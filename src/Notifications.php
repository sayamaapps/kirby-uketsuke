<?php

namespace Sayamaapps\Uketsuke;

use Closure;
use DateTimeImmutable;
use Kirby\Database\Database;

/**
 * The notifications table of the ledger: every received IPN, its hook and whether the hooks ran.
 *
 * A notification is announced once its hooks ran to the end, even if one threw. A request
 * that dies inside a hook leaves it unannounced, and the receiver fires the hooks again
 * when Digistore24 repeats the notification. A run claims the notification first
 * (`attempted_at`), so overlapping repetitions do not fire the hooks twice.
 *
 * @internal Used by the receiver, the retry and the protocol; reach it through Ledger::notifications().
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-uketsuke
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final class Notifications
{
	/**
	 * @param Closure(): Database $database Opens the ledger database on first use.
	 */
	public function __construct(
		private readonly Closure $database
	) {
	}

	/**
	 * Stores a notification and returns its row id.
	 *
	 * @param $hook The hook the notification fires, null if none.
	 * @param $announced True when nothing is left to fire (duplicates, silent events).
	 */
	public function add(Notification $notification, string|null $hook = null, bool $announced = true): int
	{
		$encodedPayload = json_encode($notification->payload);
		$received = Sql::stamp($notification->receivedAt);

		$this->database()->execute(
			'INSERT INTO notifications
				(received_at, event, order_id, transaction_id, product_id, duplicate, hook, announced_at, hook_error, payload)
			VALUES
				(:received_at, :event, :order_id, :transaction_id, :product_id, :duplicate, :hook, :announced_at, NULL, :payload)',
			[
				':received_at' => $received,
				':event' => $notification->event,
				':order_id' => $notification->orderId,
				':transaction_id' => $notification->transactionId,
				':product_id' => $notification->productId,
				':duplicate' => $notification->duplicate ? 1 : 0,
				':hook' => $hook,
				':announced_at' => $announced ? $received : null,
				':payload' => $encodedPayload === false ? '{}' : $encodedPayload,
			]
		);

		return (int)$this->database()->lastId();
	}

	/**
	 * Claims an unannounced notification for another run of its hooks. Returns true when the caller got it.
	 *
	 * Succeeds only when the hooks never ran to the end and the last start (the first delivery,
	 * or the previous claim) lies at or before `$stalledBefore`. Call it inside the write
	 * transaction: a second repetition that arrives meanwhile finds the claim and gets false.
	 */
	public function claim(int $id, DateTimeImmutable $now, DateTimeImmutable $stalledBefore): bool
	{
		$this->database()->execute(
			'UPDATE notifications SET attempted_at = :now
			WHERE id = :id AND announced_at IS NULL AND COALESCE(attempted_at, received_at) <= :stalled',
			[
				':now' => Sql::stamp($now),
				':id' => $id,
				':stalled' => Sql::stamp($stalledBefore),
			]
		);

		return (int)$this->database()->affected() === 1;
	}

	/**
	 * Returns the database connection of the ledger.
	 */
	private function database(): Database
	{
		return ($this->database)();
	}

	/**
	 * Records that the hooks of a notification ran to the end, with the exception class a hook threw.
	 */
	public function markAnnounced(int $id, string|null $hookError = null): void
	{
		$this->database()->execute(
			'UPDATE notifications SET announced_at = :now, hook_error = :error WHERE id = :id',
			[
				':now' => Sql::stamp(new DateTimeImmutable('now')),
				':error' => $hookError === null ? null : substr($hookError, 0, 500),
				':id' => $id,
			]
		);
	}

	/**
	 * Returns the first, non-duplicate notification with this key, null if there is none.
	 *
	 * @return array{id: int, hook: string|null, announced: bool, notification: Notification}|null
	 */
	public function original(string $event, string $orderId, string $productId, string $transactionId): array|null
	{
		$rows = Sql::rows(
			$this->database(),
			'SELECT * FROM notifications
			WHERE event = :event AND order_id = :order_id AND product_id = :product_id
				AND transaction_id = :transaction_id AND duplicate = 0
			ORDER BY id LIMIT 1',
			[
				':event' => $event,
				':order_id' => $orderId,
				':product_id' => $productId,
				':transaction_id' => $transactionId,
			]
		);

		if (isset($rows[0]) === false) {
			return null;
		}

		return [
			'id' => (int)$rows[0]['id'],
			'hook' => isset($rows[0]['hook']) ? (string)$rows[0]['hook'] : null,
			'announced' => isset($rows[0]['announced_at']),
			'notification' => Notification::fromRow($rows[0]),
		];
	}

	/**
	 * Returns the first (non-duplicate) on_payment notification of a purchase, null if none.
	 */
	public function originalPayment(Purchase $purchase): Notification|null
	{
		$rows = Sql::rows(
			$this->database(),
			'SELECT * FROM notifications
			WHERE order_id = :order_id AND product_id = :product_id AND event = :event AND duplicate = 0
			ORDER BY id LIMIT 1',
			[':order_id' => $purchase->orderId, ':product_id' => $purchase->productId, ':event' => 'on_payment']
		);

		return isset($rows[0]) ? Notification::fromRow($rows[0]) : null;
	}

	/**
	 * Deletes notifications received before the given instant and returns how many were removed.
	 */
	public function purge(DateTimeImmutable $before): int
	{
		$this->database()->execute(
			'DELETE FROM notifications WHERE received_at < :before',
			[':before' => Sql::stamp($before)]
		);

		return (int)$this->database()->affected();
	}

	/**
	 * Returns true when the first on_payment of a purchase was stored but its hooks never finished.
	 *
	 * False once the notification is purged (see the `retention` option).
	 */
	public function unannounced(Purchase $purchase): bool
	{
		$rows = Sql::rows(
			$this->database(),
			'SELECT COUNT(*) AS open FROM notifications
			WHERE order_id = :order_id AND product_id = :product_id AND event = :event
				AND duplicate = 0 AND announced_at IS NULL',
			[':order_id' => $purchase->orderId, ':product_id' => $purchase->productId, ':event' => 'on_payment']
		);

		return (int)($rows[0]['open'] ?? 0) > 0;
	}
}
