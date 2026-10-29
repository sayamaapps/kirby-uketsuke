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
 * A notification remembers when its hooks ran to the end. If the request died inside
 * a hook (fatal error, time limit), Digistore24 gets no `OK` and sends the notification
 * again; that repetition fires the hooks once more instead of counting as a duplicate.
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
	 * Seconds after which hooks that never finished count as stopped. Digistore24 waits
	 * 30 seconds for an answer and retries after 3 minutes at the earliest.
	 */
	private const STALLED_AFTER = 120;

	/**
	 * Creates the receiver for one request.
	 */
	public function __construct(
		private readonly App $kirby
	) {
	}

	/**
	 * Fires the hooks of a notification and records that they ran to the end.
	 *
	 * @param array{id: int, notification: Notification, purchase: Purchase|null, hook: string|null} $filing
	 */
	private function announce(Ledger $ledger, array $filing): void
	{
		$notification = $filing['notification'];
		$purchase = $filing['purchase'];
		$error = null;

		if ($filing['hook'] !== null && $purchase !== null) {
			$error = Hooks::fire($this->kirby, $filing['hook'], [
				'purchase' => $purchase,
				'notification' => $notification,
				'retry' => false,
			]);
		}

		$lastError = Hooks::fire($this->kirby, 'uketsuke.notification:after', [
			'notification' => $notification,
			'purchase' => $purchase,
		]);
		$error ??= $lastError;

		try {
			$ledger->notifications()->markAnnounced($filing['id'], $error);
		} catch (Throwable $e) {
			error_log('Uketsuke: could not mark the notification as announced (' . $e::class . ')');
		}
	}

	/**
	 * Writes the purchase change for the event and returns the hook name to fire, null if none.
	 */
	private function book(Ledger $ledger, Payload $payload, DateTimeImmutable $now): string|null
	{
		if ($payload->orderId() === '') {
			return null;
		}

		if ($payload->event() === 'on_payment') {
			if ($ledger->insertPurchase($payload->purchaseRow($now, 'paid', null))) {
				return 'uketsuke.payment:after';
			}

			return 'uketsuke.payment:repeat';
		}

		if ($payload->event() === 'on_refund') {
			return $this->settle($ledger, $payload, 'refunded', $now) ? 'uketsuke.refund:after' : null;
		}

		if ($payload->event() === 'on_chargeback') {
			return $this->settle($ledger, $payload, 'chargeback', $now) ? 'uketsuke.chargeback:after' : null;
		}

		return null;
	}

	/**
	 * Stores the notification and books the purchase. Runs inside the write transaction.
	 *
	 * Returns what to announce after the commit, null if nothing: duplicates, silent events.
	 *
	 * @return array{id: int, notification: Notification, purchase: Purchase|null, hook: string|null}|null
	 */
	private function file(Ledger $ledger, Payload $payload, DateTimeImmutable $now): array|null
	{
		$notifications = $ledger->notifications();
		$original = $notifications->original(
			$payload->event(),
			$payload->orderId(),
			$payload->productId(),
			$payload->transactionId()
		);

		if ($original !== null) {
			$notifications->add($this->notification($payload, $now, true));
			$stalledBefore = $now->modify('-' . self::STALLED_AFTER . ' seconds');

			if ($original['announced'] || $original['notification']->receivedAt > $stalledBefore) {
				return null;
			}

			error_log('Uketsuke: hooks of order ' . self::label($payload->orderId()) . ' never finished, firing them again');

			return [
				'id' => $original['id'],
				'notification' => $original['notification'],
				'purchase' => $this->purchase($ledger, $original['notification']),
				'hook' => $original['hook'],
			];
		}

		$hook = $this->book($ledger, $payload, $now);
		$notification = $this->notification($payload, $now, false);
		$silent = in_array($notification->event, self::SILENT_EVENTS, true);
		$id = $notifications->add($notification, $hook, $silent);

		if ($silent) {
			return null;
		}

		return [
			'id' => $id,
			'notification' => $notification,
			'purchase' => $this->purchase($ledger, $notification),
			'hook' => $hook,
		];
	}

	/**
	 * Processes the form body and returns the response Digistore24 receives.
	 *
	 * @param array<string, mixed> $data The form body of the request, without the query string.
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
			$filing = $ledger->transaction(fn (): array|null => $this->file($ledger, $payload, $now));
		} catch (Throwable $e) {
			return Reply::storageError('STORAGE_ERROR', $e);
		}

		if ($filing !== null) {
			$this->announce($ledger, $filing);
		}

		try {
			$ledger->notifications()->purge($now->modify('-' . $options->retentionDays() . ' days'));
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
	 * Returns the purchase a notification refers to, null for events without a purchase.
	 */
	private function purchase(Ledger $ledger, Notification $notification): Purchase|null
	{
		if ($notification->orderId === '' || in_array($notification->event, self::PURCHASE_EVENTS, true) === false) {
			return null;
		}

		return $ledger->purchase($notification->orderId, $notification->productId === '' ? null : $notification->productId);
	}

	/**
	 * Sets a refund or chargeback status and returns true when it changed something.
	 *
	 * Creates the purchase first if it is unknown. Without a product id, every product of a
	 * known order gets the status.
	 */
	private function settle(Ledger $ledger, Payload $payload, string $status, DateTimeImmutable $now): bool
	{
		$orderId = $payload->orderId();
		$productId = $payload->productId();
		$refundedAt = $status === 'refunded' ? $now : null;

		if ($productId === '' && $ledger->purchase($orderId) !== null) {
			return $ledger->setStatus($orderId, $status, $refundedAt) > 0;
		}

		if ($ledger->insertPurchase($payload->purchaseRow($now, $status, $refundedAt))) {
			return true;
		}

		return $ledger->setStatus($orderId, $status, $refundedAt, $productId) > 0;
	}
}
