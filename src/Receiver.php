<?php

namespace Sayamaapps\Uketsuke;

use DateTimeImmutable;
use DateTimeZone;
use Kirby\Cms\App;
use Kirby\Http\Response;
use Throwable;

/**
 * Handles one IPN request: signature, notification row, purchase, hooks.
 *
 * Answers `OK` for everything that was received and stored, including duplicates,
 * connection tests and events without a purchase. Digistore24 counts a notification
 * as delivered only when it receives the text `OK`; any other answer makes it retry.
 * Hooks run after the write, and a failing hook never changes the answer.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-uketsuke
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final class Receiver
{
	/** Events that create or change a purchase. */
	private const PURCHASE_EVENTS = ['on_payment', 'on_refund', 'on_chargeback'];

	/** Events that are stored and answered `OK` without any hook. */
	private const SILENT_EVENTS = [
		'on_payment_missed',
		'on_rebill_cancelled',
		'on_rebill_resumed',
		'last_paid_day',
		'customform',
		'eticket',
		'on_affiliation',
	];

	/**
	 * Creates the receiver for one request.
	 */
	public function __construct(
		private readonly App $kirby
	) {
	}

	/**
	 * Fires the hooks of a stored, non-duplicate notification and records hook errors.
	 *
	 * @param array{id: int, duplicate: bool, notification: Notification, purchase: Purchase|null, hook: string|null} $filing
	 */
	private function announce(Ledger $ledger, array $filing): void
	{
		$notification = $filing['notification'];

		if (in_array($notification->event, self::SILENT_EVENTS, true)) {
			return;
		}

		$purchase = $filing['purchase'];

		if ($filing['hook'] !== null && $purchase !== null) {
			$error = Hooks::fire($this->kirby, $filing['hook'], [
				'purchase' => $purchase,
				'notification' => $notification,
				'retry' => false,
			]);
			$this->noteError($ledger, $filing['id'], $error);
		}

		$error = Hooks::fire($this->kirby, 'uketsuke.notification:after', [
			'notification' => $notification,
			'purchase' => $purchase,
		]);
		$this->noteError($ledger, $filing['id'], $error);
	}

	/**
	 * Writes the purchase change for the event and returns the hook name to fire, null if none.
	 */
	private function book(Ledger $ledger, Payload $payload, DateTimeImmutable $now): string|null
	{
		$orderId = $payload->orderId();

		if ($orderId === '') {
			return null;
		}

		if ($payload->event() === 'on_payment') {
			if ($ledger->insertPurchase($payload->purchaseRow($now, 'paid', null))) {
				return 'uketsuke.payment:after';
			}

			return 'uketsuke.payment:repeat';
		}

		if ($payload->event() === 'on_refund') {
			$this->settle($ledger, $payload, 'refunded', $now);
			return 'uketsuke.refund:after';
		}

		if ($payload->event() === 'on_chargeback') {
			$this->settle($ledger, $payload, 'chargeback', $now);
			return 'uketsuke.chargeback:after';
		}

		return null;
	}

	/**
	 * Stores the notification and books the purchase. Runs inside the write transaction.
	 *
	 * @return array{id: int, duplicate: bool, notification: Notification, purchase: Purchase|null, hook: string|null}
	 */
	private function file(Ledger $ledger, Payload $payload, DateTimeImmutable $now): array
	{
		$event = $payload->event();
		$orderId = $payload->orderId();
		$transactionId = $payload->transactionId();

		if ($ledger->notificationSeen($event, $orderId, $transactionId)) {
			$notification = $this->notification($payload, $now, true);

			return [
				'id' => $ledger->addNotification($notification),
				'duplicate' => true,
				'notification' => $notification,
				'purchase' => null,
				'hook' => null,
			];
		}

		$notification = $this->notification($payload, $now, false);
		$id = $ledger->addNotification($notification);
		$hook = $this->book($ledger, $payload, $now);

		return [
			'id' => $id,
			'duplicate' => false,
			'notification' => $notification,
			'purchase' => $orderId !== '' && in_array($event, self::PURCHASE_EVENTS, true)
				? $ledger->purchase($orderId)
				: null,
			'hook' => $hook,
		];
	}

	/**
	 * Processes the request data and returns the response Digistore24 receives.
	 *
	 * @param array<string, mixed> $data Form body merged with the query string.
	 */
	public function handle(array $data): Response
	{
		$options = Options::fromKirby($this->kirby);
		$passphrase = $options->passphrase();

		if ($passphrase === null) {
			error_log('Uketsuke: NOT_CONFIGURED, no IPN passphrase is set');
			return Reply::text(503, 'NOT_CONFIGURED');
		}

		$payload = new Payload($data);

		if (Signature::verify($passphrase, $data) === false) {
			error_log('Uketsuke: signature rejected for order ' . self::label($payload->orderId()));
			return Reply::text(403, 'INVALID_SIGNATURE');
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		try {
			$ledger = new Ledger($options->storageDirectory($this->kirby));
			$filing = $ledger->transaction(fn (): array => $this->file($ledger, $payload, $now));
		} catch (Throwable $e) {
			error_log('Uketsuke: STORAGE_ERROR (' . $e::class . ')');
			return Reply::text(503, 'STORAGE_ERROR');
		}

		if ($filing['duplicate'] === false) {
			$this->announce($ledger, $filing);
		}

		try {
			$ledger->purgeNotifications($now->modify('-' . $options->retentionDays() . ' days'));
		} catch (Throwable $e) {
			error_log('Uketsuke: retention purge failed (' . $e::class . ')');
		}

		return Reply::text(200, 'OK');
	}

	/**
	 * Keeps only letters, digits, dash and underscore of a value that goes into the log.
	 */
	private static function label(string $value): string
	{
		return substr((string)preg_replace('/[^A-Za-z0-9_-]/', '?', $value), 0, 32);
	}

	/**
	 * Stores a hook error on the notification row; a failure to store it is only logged.
	 */
	private function noteError(Ledger $ledger, int $id, string|null $error): void
	{
		if ($error === null) {
			return;
		}

		try {
			$ledger->markHookError($id, $error);
		} catch (Throwable $e) {
			error_log('Uketsuke: could not store hook error (' . $e::class . ')');
		}
	}

	/**
	 * Builds the notification object for storage and for the hooks.
	 */
	private function notification(Payload $payload, DateTimeImmutable $now, bool $duplicate): Notification
	{
		return new Notification(
			event: $payload->event(),
			orderId: $payload->orderId(),
			transactionId: $payload->transactionId(),
			productId: $payload->productId(),
			receivedAt: $now,
			payload: $payload->notificationPayload(),
			duplicate: $duplicate,
		);
	}

	/**
	 * Sets a refund or chargeback status, creating the purchase first if the order is unknown.
	 */
	private function settle(Ledger $ledger, Payload $payload, string $status, DateTimeImmutable $now): void
	{
		$refundedAt = $status === 'refunded' ? $now : null;

		if ($ledger->insertPurchase($payload->purchaseRow($now, $status, $refundedAt)) === false) {
			$ledger->setStatus($payload->orderId(), $status, $refundedAt);
		}
	}
}
