<?php

namespace Sayamaapps\Uketsuke;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Kirby\Database\Database;
use Kirby\Exception\InvalidArgumentException;
use Throwable;

/**
 * The purchase ledger: notifications, purchases and deliveries in one SQLite file.
 *
 * The database is opened on first use. Schema creation is idempotent and runs
 * once per connection; PRAGMA user_version tracks future migrations. All values
 * are bound parameters. Timestamps are stored as UTC 'Y-m-d H:i:s'.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-uketsuke
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final class Ledger
{
	private const DELIVERY_STATUSES = ['pending', 'sent', 'failed', 'skipped', 'simulated'];

	private Database|null $database = null;

	/**
	 * Creates a ledger in the given directory. Nothing is opened yet.
	 */
	public function __construct(
		private readonly string $directory
	) {
	}

	/**
	 * Stores a notification and returns its row id.
	 */
	public function addNotification(Notification $notification): int
	{
		$encodedPayload = json_encode($notification->payload);
		$payload = $encodedPayload === false ? '{}' : $encodedPayload;

		$this->run(
			'INSERT INTO notifications (received_at, event, order_id, transaction_id, product_id, duplicate, hook_error, payload) VALUES (:received_at, :event, :order_id, :transaction_id, :product_id, :duplicate, NULL, :payload)',
			[
				':received_at' => $this->stamp($notification->receivedAt),
				':event' => $notification->event,
				':order_id' => $notification->orderId,
				':transaction_id' => $notification->transactionId,
				':product_id' => $notification->productId,
				':duplicate' => $notification->duplicate ? 1 : 0,
				':payload' => $payload,
			]
		);

		return (int)$this->database()->lastId();
	}

	/**
	 * Opens the database on first use: busy timeout, WAL, schema.
	 */
	/**
	 * Opens the database on first use.
	 */
	private function database(): Database
	{
		return $this->database ??= Schema::open($this->directory);
	}

	/**
	 * Returns all deliveries of an order, ordered by channel.
	 *
	 * @return list<array{channel: string, status: string, attempts: int, error: string|null, sent_at: DateTimeImmutable|null, meta: array<string, mixed>}>
	 */
	public function deliveries(string $orderId): array
	{
		$deliveries = [];
		$rows = $this->rows(
			'SELECT * FROM deliveries WHERE order_id = :order_id ORDER BY channel',
			[':order_id' => $orderId]
		);

		foreach ($rows as $row) {
			$deliveries[] = self::deliveryRow($row);
		}

		return $deliveries;
	}

	/**
	 * Returns one delivery of an order, null if the channel has none.
	 *
	 * @return array{channel: string, status: string, attempts: int, error: string|null, sent_at: DateTimeImmutable|null, meta: array<string, mixed>}|null
	 */
	public function delivery(string $orderId, string $channel): array|null
	{
		$rows = $this->rows(
			'SELECT * FROM deliveries WHERE order_id = :order_id AND channel = :channel',
			[':order_id' => $orderId, ':channel' => $channel]
		);

		return isset($rows[0]) ? self::deliveryRow($rows[0]) : null;
	}

	/**
	 * Converts a row of the deliveries table.
	 *
	 * @param array<string, mixed> $row
	 * @return array{channel: string, status: string, attempts: int, error: string|null, sent_at: DateTimeImmutable|null, meta: array<string, mixed>}
	 */
	private static function deliveryRow(array $row): array
	{
		$meta = json_decode((string)($row['meta'] ?? '{}'), true);
		$sentAt = isset($row['sent_at']) ? (string)$row['sent_at'] : null;

		return [
			'channel' => (string)$row['channel'],
			'status' => (string)$row['status'],
			'attempts' => (int)$row['attempts'],
			'error' => isset($row['error']) ? (string)$row['error'] : null,
			'sent_at' => $sentAt === null ? null : new DateTimeImmutable($sentAt, new DateTimeZone('UTC')),
			'meta' => is_array($meta) ? $meta : [],
		];
	}

	/**
	 * Inserts a purchase unless the order exists. Returns true when a new row was written.
	 *
	 * @param array<string, mixed> $row Column values, as built by Payload::purchaseRow().
	 */
	public function insertPurchase(array $row): bool
	{
		$columns = array_keys($row);
		$bindings = [];

		foreach ($row as $column => $value) {
			$bindings[':' . $column] = $value;
		}

		$this->run(
			'INSERT OR IGNORE INTO purchases (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_keys($bindings)) . ')',
			$bindings
		);

		return (int)$this->database()->affected() === 1;
	}

	/**
	 * Stores the name of the exception that a hook threw, for the notification row.
	 */
	public function markHookError(int $notificationId, string $error): void
	{
		$this->run(
			'UPDATE notifications SET hook_error = :error WHERE id = :id',
			[':error' => substr($error, 0, 500), ':id' => $notificationId]
		);
	}

	/**
	 * Returns true when a notification with this key was already stored and not as a duplicate.
	 */
	public function notificationSeen(string $event, string $orderId, string $transactionId): bool
	{
		$rows = $this->rows(
			'SELECT COUNT(*) AS seen FROM notifications WHERE event = :event AND order_id = :order_id AND transaction_id = :transaction_id AND duplicate = 0',
			[':event' => $event, ':order_id' => $orderId, ':transaction_id' => $transactionId]
		);

		return (int)($rows[0]['seen'] ?? 0) > 0;
	}

	/**
	 * Returns the order ids that have a delivery in the given channel with the given status.
	 *
	 * @return list<string>
	 */
	public function ordersWithDelivery(string $channel, string $status): array
	{
		$orders = [];
		$rows = $this->rows(
			'SELECT order_id FROM deliveries WHERE channel = :channel AND status = :status ORDER BY order_id',
			[':channel' => $channel, ':status' => $status]
		);

		foreach ($rows as $row) {
			$orders[] = (string)$row['order_id'];
		}

		return $orders;
	}

	/**
	 * Returns the first (non-duplicate) on_payment notification of an order, null if none.
	 */
	public function originalPayment(string $orderId): Notification|null
	{
		$rows = $this->rows(
			'SELECT * FROM notifications WHERE order_id = :order_id AND event = :event AND duplicate = 0 ORDER BY id LIMIT 1',
			[':order_id' => $orderId, ':event' => 'on_payment']
		);

		return isset($rows[0]) ? Notification::fromRow($rows[0]) : null;
	}

	/**
	 * Returns the purchase of an order, null if there is none.
	 */
	public function purchase(string $orderId): Purchase|null
	{
		$rows = $this->rows('SELECT * FROM purchases WHERE order_id = :order_id', [':order_id' => $orderId]);

		return isset($rows[0]) ? Purchase::fromRow($rows[0]) : null;
	}

	/**
	 * Returns the purchases paid within [since, until), ordered by payment time.
	 *
	 * Both bounds are compared as instants; `until` is exclusive.
	 *
	 * @return list<Purchase>
	 */
	public function purchases(DateTimeInterface $since, DateTimeInterface|null $until = null): array
	{
		$sql = 'SELECT * FROM purchases WHERE paid_at >= :since';
		$bindings = [':since' => $this->stamp($since)];

		if ($until !== null) {
			$sql .= ' AND paid_at < :until';
			$bindings[':until'] = $this->stamp($until);
		}

		$purchases = [];
		foreach ($this->rows($sql . ' ORDER BY paid_at, order_id', $bindings) as $row) {
			$purchases[] = Purchase::fromRow($row);
		}

		return $purchases;
	}

	/**
	 * Deletes notifications received before the given instant and returns how many were removed.
	 *
	 * Purchases and deliveries are never purged.
	 */
	public function purgeNotifications(DateTimeImmutable $before): int
	{
		$this->run('DELETE FROM notifications WHERE received_at < :before', [':before' => $this->stamp($before)]);

		return (int)$this->database()->affected();
	}

	/**
	 * Records one delivery attempt of an order in a channel. Increments `attempts`.
	 *
	 * @param array<string, mixed> $meta Free-form, non-personal details (for example a message id).
	 */
	public function recordDelivery(string $orderId, string $channel, string $status, string|null $error = null, array $meta = []): void
	{
		if (in_array($status, self::DELIVERY_STATUSES, true) === false) {
			throw new InvalidArgumentException(
				message: 'Uketsuke: unknown delivery status ' . $status
			);
		}

		$now = $this->stamp(new DateTimeImmutable('now'));
		$sentAt = $status === 'sent' ? $now : null;
		$error = $error === null
			? null
			: substr((string)preg_replace('/[^\s<>]+@[^\s<>]+/', '[address]', $error), 0, 500);
		$encodedMeta = json_encode($meta);
		$metaJson = $encodedMeta === false ? '{}' : $encodedMeta;

		$this->run(
			'INSERT OR IGNORE INTO deliveries (order_id, channel, status, attempts, created_at, updated_at) VALUES (:order_id, :channel, :status, 0, :now, :now)',
			[':order_id' => $orderId, ':channel' => $channel, ':status' => $status, ':now' => $now]
		);
		$this->run(
			'UPDATE deliveries SET status = :status, attempts = attempts + 1, error = :error, sent_at = :sent_at, meta = :meta, updated_at = :now WHERE order_id = :order_id AND channel = :channel',
			[
				':status' => $status,
				':error' => $error,
				':sent_at' => $sentAt,
				':meta' => $metaJson,
				':now' => $now,
				':order_id' => $orderId,
				':channel' => $channel,
			]
		);
	}

	/**
	 * Runs a query and returns the rows as arrays.
	 *
	 * @param array<string, mixed> $bindings
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $sql, array $bindings = []): array
	{
		$rows = $this->database()->query($sql, $bindings, ['fetch' => 'array', 'iterator' => 'array']);

		return is_array($rows) ? array_values($rows) : [];
	}

	/**
	 * Runs a statement without result rows.
	 *
	 * @param array<string, mixed> $bindings
	 */
	private function run(string $sql, array $bindings = []): bool
	{
		return $this->database()->execute($sql, $bindings);
	}

	/**
	 * Sets the status of an existing purchase. A refund date is stored only for refunds.
	 */
	public function setStatus(string $orderId, string $status, DateTimeImmutable|null $refundedAt): void
	{
		$this->run(
			'UPDATE purchases SET status = :status, refunded_at = COALESCE(:refunded_at, refunded_at), updated_at = :now WHERE order_id = :order_id',
			[
				':status' => $status,
				':refunded_at' => $refundedAt === null ? null : $this->stamp($refundedAt),
				':now' => $this->stamp(new DateTimeImmutable('now')),
				':order_id' => $orderId,
			]
		);
	}

	/**
	 * Formats an instant as the UTC timestamp the ledger stores.
	 */
	private function stamp(DateTimeInterface $moment): string
	{
		return DateTimeImmutable::createFromInterface($moment)
			->setTimezone(new DateTimeZone('UTC'))
			->format('Y-m-d H:i:s');
	}

	/**
	 * Runs the work inside one write transaction. The write lock is taken at the start (BEGIN IMMEDIATE),
	 * so a check and the write that follows it cannot interleave with another request.
	 *
	 * @template T
	 * @param Closure(): T $work
	 * @return T
	 */
	public function transaction(Closure $work): mixed
	{
		$this->run('BEGIN IMMEDIATE');

		try {
			$result = $work();
		} catch (Throwable $e) {
			$this->run('ROLLBACK');
			throw $e;
		}

		$this->run('COMMIT');

		return $result;
	}
}
