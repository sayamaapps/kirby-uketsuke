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
 * A purchase is one product of an order: Digistore24 sends one on_payment per product,
 * all with the same order id. Deliveries belong to a purchase. Methods that take an
 * order id alone accept it as long as the order has one product; pass the Purchase
 * when it may have several.
 *
 * The database is opened on first use. All values are bound parameters.
 * Timestamps are stored as UTC 'Y-m-d H:i:s'. Methods marked @internal serve the
 * receiver and the routes and may change in any version; the notifications table
 * is in Notifications.
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
	private Notifications|null $notifications = null;

	/**
	 * Creates a ledger in the given directory. Nothing is opened yet.
	 */
	public function __construct(
		private readonly string $directory
	) {
	}

	/**
	 * Opens the database on first use: busy timeout, WAL, schema.
	 */
	private function database(): Database
	{
		return $this->database ??= Schema::open($this->directory);
	}

	/**
	 * Returns all deliveries of a purchase, ordered by channel.
	 *
	 * @param $purchase The purchase, or the order id of a single-product order.
	 * @return list<array{channel: string, status: string, attempts: int, error: string|null, sent_at: DateTimeImmutable|null, meta: array<string, mixed>}>
	 */
	public function deliveries(Purchase|string $purchase): array
	{
		[$orderId, $productId] = $this->position($purchase);
		$deliveries = [];
		$rows = $this->rows(
			'SELECT * FROM deliveries WHERE order_id = :order_id AND product_id = :product_id ORDER BY channel',
			[':order_id' => $orderId, ':product_id' => $productId]
		);

		foreach ($rows as $row) {
			$deliveries[] = self::deliveryRow($row);
		}

		return $deliveries;
	}

	/**
	 * Returns one delivery of a purchase, null if the channel has none.
	 *
	 * @param $purchase The purchase, or the order id of a single-product order.
	 * @return array{channel: string, status: string, attempts: int, error: string|null, sent_at: DateTimeImmutable|null, meta: array<string, mixed>}|null
	 */
	public function delivery(Purchase|string $purchase, string $channel): array|null
	{
		[$orderId, $productId] = $this->position($purchase);
		$rows = $this->rows(
			'SELECT * FROM deliveries WHERE order_id = :order_id AND product_id = :product_id AND channel = :channel',
			[':order_id' => $orderId, ':product_id' => $productId, ':channel' => $channel]
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
	 * Inserts a purchase unless this product of the order exists. Returns true when a new row was written.
	 *
	 * @internal
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
			'INSERT OR IGNORE INTO purchases (' . implode(', ', $columns) . ')
			VALUES (' . implode(', ', array_keys($bindings)) . ')',
			$bindings
		);

		return (int)$this->database()->affected() === 1;
	}

	/**
	 * Returns the notifications table, which shares this ledger's connection.
	 *
	 * @internal
	 */
	public function notifications(): Notifications
	{
		return $this->notifications ??= new Notifications(fn (): Database => $this->database());
	}

	/**
	 * Returns all purchases (products) of an order, in the order they were received.
	 *
	 * @return list<Purchase>
	 */
	public function order(string $orderId): array
	{
		$purchases = [];

		foreach ($this->rows('SELECT * FROM purchases WHERE order_id = :order_id ORDER BY rowid', [':order_id' => $orderId]) as $row) {
			$purchases[] = Purchase::fromRow($row);
		}

		return $purchases;
	}

	/**
	 * Returns paid purchases for the retry route, a page at a time, ordered by their row id.
	 *
	 * With a status, the purchases whose delivery in the channel has that status. With null,
	 * the purchases that have no delivery in the channel at all, for example because a hook
	 * stopped before it recorded one.
	 *
	 * @internal
	 * @return list<array{key: int, purchase: Purchase}>
	 */
	public function paidWithDelivery(string $channel, string|null $status, int $after, int $limit): array
	{
		$sql = 'SELECT p.rowid AS ledger_key, p.* FROM purchases p
			LEFT JOIN deliveries d
				ON d.order_id = p.order_id AND d.product_id = p.product_id AND d.channel = :channel
			WHERE p.status = :paid AND p.rowid > :after AND '
			. ($status === null ? 'd.id IS NULL' : 'd.status = :status')
			. ' ORDER BY p.rowid LIMIT ' . max(1, $limit);
		$bindings = [':channel' => $channel, ':paid' => 'paid', ':after' => $after];

		if ($status !== null) {
			$bindings[':status'] = $status;
		}

		$page = [];

		foreach ($this->rows($sql, $bindings) as $row) {
			$page[] = ['key' => (int)$row['ledger_key'], 'purchase' => Purchase::fromRow($row)];
		}

		return $page;
	}

	/**
	 * Resolves a purchase or an order id to [order id, product id].
	 *
	 * An order id resolves to its only product, or to '' while the order is unknown.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function position(Purchase|string $purchase): array
	{
		if ($purchase instanceof Purchase) {
			return [$purchase->orderId, $purchase->productId];
		}

		$rows = $this->rows('SELECT product_id FROM purchases WHERE order_id = :order_id', [':order_id' => $purchase]);

		if (count($rows) > 1) {
			throw new InvalidArgumentException(
				message: 'Uketsuke: order ' . $purchase . ' has several products, pass the Purchase instead of the order id'
			);
		}

		return [$purchase, isset($rows[0]) ? (string)$rows[0]['product_id'] : ''];
	}

	/**
	 * Returns a purchase of an order, null if there is none.
	 *
	 * Without a product id, the first product the order received (the main product).
	 */
	public function purchase(string $orderId, string|null $productId = null): Purchase|null
	{
		$rows = $this->rows(
			'SELECT * FROM purchases
			WHERE order_id = :order_id AND (:product_id IS NULL OR product_id = :product_id)
			ORDER BY rowid LIMIT 1',
			[':order_id' => $orderId, ':product_id' => $productId]
		);

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
		$bindings = [':since' => Sql::stamp($since)];

		if ($until !== null) {
			$sql .= ' AND paid_at < :until';
			$bindings[':until'] = Sql::stamp($until);
		}

		$purchases = [];
		foreach ($this->rows($sql . ' ORDER BY paid_at, order_id, rowid', $bindings) as $row) {
			$purchases[] = Purchase::fromRow($row);
		}

		return $purchases;
	}

	/**
	 * Records one delivery attempt of a purchase in a channel. Increments `attempts`.
	 *
	 * E-mail addresses in the error and in string values of the meta data are replaced
	 * by `[address]`, because mail servers quote the recipient in their answers.
	 *
	 * @param $purchase The purchase, or the order id of a single-product order.
	 * @param array<string, mixed> $meta Free-form, non-personal details (for example a message id).
	 */
	public function recordDelivery(
		Purchase|string $purchase,
		string $channel,
		string $status,
		string|null $error = null,
		array $meta = []
	): void {
		if (in_array($status, self::DELIVERY_STATUSES, true) === false) {
			throw new InvalidArgumentException(
				message: 'Uketsuke: unknown delivery status ' . $status
			);
		}

		[$orderId, $productId] = $this->position($purchase);
		$now = Sql::stamp(new DateTimeImmutable('now'));
		$encodedMeta = json_encode(self::scrubMeta($meta));
		$key = [':order_id' => $orderId, ':product_id' => $productId, ':channel' => $channel];

		$this->run(
			'INSERT OR IGNORE INTO deliveries (order_id, product_id, channel, status, attempts, created_at, updated_at)
			VALUES (:order_id, :product_id, :channel, :status, 0, :now, :now)',
			[...$key, ':status' => $status, ':now' => $now]
		);
		$this->run(
			'UPDATE deliveries
			SET status = :status, attempts = attempts + 1, error = :error, sent_at = :sent_at, meta = :meta, updated_at = :now
			WHERE order_id = :order_id AND product_id = :product_id AND channel = :channel',
			[
				...$key,
				':status' => $status,
				':error' => $error === null ? null : substr(self::scrub($error), 0, 500),
				':sent_at' => $status === 'sent' ? $now : null,
				':meta' => $encodedMeta === false ? '{}' : $encodedMeta,
				':now' => $now,
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
		return Sql::rows($this->database(), $sql, $bindings);
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
	 * Replaces e-mail addresses, because mail servers quote the recipient in their answers.
	 */
	private static function scrub(string $text): string
	{
		return (string)preg_replace('/[^\s<>]+@[^\s<>]+/', '[address]', $text);
	}

	/**
	 * Replaces e-mail addresses in the string values of the meta data, nested arrays included.
	 *
	 * @param array<array-key, mixed> $meta
	 * @return array<array-key, mixed>
	 */
	private static function scrubMeta(array $meta): array
	{
		foreach ($meta as $key => $value) {
			if (is_string($value)) {
				$meta[$key] = self::scrub($value);
			} elseif (is_array($value)) {
				$meta[$key] = self::scrubMeta($value);
			}
		}

		return $meta;
	}

	/**
	 * Sets the status of existing purchases and returns how many changed.
	 *
	 * Without a product id, every product of the order. A purchase that already has the
	 * status does not count, so a replayed refund does not fire its hook twice. A refund
	 * date is stored only for refunds.
	 *
	 * @internal
	 */
	public function setStatus(
		string $orderId,
		string $status,
		DateTimeImmutable|null $refundedAt,
		string|null $productId = null
	): int {
		$this->run(
			'UPDATE purchases
			SET status = :status, refunded_at = COALESCE(:refunded_at, refunded_at), updated_at = :now
			WHERE order_id = :order_id AND (:product_id IS NULL OR product_id = :product_id) AND status != :status',
			[
				':status' => $status,
				':refunded_at' => $refundedAt === null ? null : Sql::stamp($refundedAt),
				':now' => Sql::stamp(new DateTimeImmutable('now')),
				':order_id' => $orderId,
				':product_id' => $productId,
			]
		);

		return (int)$this->database()->affected();
	}

	/**
	 * Runs the work inside one write transaction. The write lock is taken at the start (BEGIN IMMEDIATE),
	 * so a check and the write that follows it cannot interleave with another request.
	 *
	 * @internal
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
