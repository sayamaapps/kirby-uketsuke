<?php

namespace Sayamaapps\Uketsuke;

use Kirby\Database\Database;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Exception\PermissionException;
use Throwable;

/**
 * Opens the ledger database: directory, connection, WAL and busy timeout, schema.
 *
 * The schema is created with SQL that SQLite 3.7.17 understands (no UPSERT, no RETURNING),
 * because shared hosters still ship that version. PRAGMA user_version tracks the migrations.
 *
 * Version 2 keys purchases and deliveries by order and product: Digistore24 sends one
 * on_payment per product of an order (order bumps, upsells), all with the same order id.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-uketsuke
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final class Schema
{
	public const FILE = 'uketsuke.sqlite';
	public const VERSION = 2;

	/**
	 * Creates the tables and indexes of the current version.
	 */
	private static function create(Database $database): void
	{
		$database->execute(
			'CREATE TABLE IF NOT EXISTS notifications (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				received_at TEXT NOT NULL,
				event TEXT NOT NULL,
				order_id TEXT NOT NULL DEFAULT \'\',
				transaction_id TEXT NOT NULL DEFAULT \'\',
				product_id TEXT NOT NULL DEFAULT \'\',
				duplicate INTEGER NOT NULL DEFAULT 0,
				hook TEXT,
				announced_at TEXT,
				hook_error TEXT,
				payload TEXT NOT NULL
			)'
		);
		self::indexNotifications($database);
		self::createPurchases($database, 'purchases');
		$database->execute('CREATE INDEX IF NOT EXISTS purchases_paid ON purchases (paid_at)');
		self::createDeliveries($database, 'deliveries');
	}

	/**
	 * Creates the deliveries table under the given name.
	 */
	private static function createDeliveries(Database $database, string $table): void
	{
		$database->execute(
			'CREATE TABLE IF NOT EXISTS ' . $table . ' (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				order_id TEXT NOT NULL,
				product_id TEXT NOT NULL DEFAULT \'\',
				channel TEXT NOT NULL,
				status TEXT NOT NULL,
				attempts INTEGER NOT NULL DEFAULT 0,
				error TEXT,
				sent_at TEXT,
				meta TEXT,
				created_at TEXT NOT NULL,
				updated_at TEXT NOT NULL,
				UNIQUE (order_id, product_id, channel)
			)'
		);
	}

	/**
	 * Creates the purchases table under the given name.
	 */
	private static function createPurchases(Database $database, string $table): void
	{
		$database->execute(
			'CREATE TABLE IF NOT EXISTS ' . $table . ' (
				order_id TEXT NOT NULL,
				product_id TEXT NOT NULL DEFAULT \'\',
				product_name TEXT,
				product_name_intern TEXT,
				email TEXT,
				first_name TEXT,
				last_name TEXT,
				status TEXT NOT NULL,
				paid_at TEXT NOT NULL,
				refunded_at TEXT,
				test INTEGER NOT NULL DEFAULT 0,
				language TEXT,
				country TEXT,
				affiliate TEXT,
				campaignkey TEXT,
				amount REAL,
				currency TEXT,
				custom TEXT,
				buyer_id TEXT,
				source TEXT NOT NULL DEFAULT \'ipn\',
				created_at TEXT NOT NULL,
				updated_at TEXT NOT NULL,
				PRIMARY KEY (order_id, product_id)
			)'
		);
	}

	/**
	 * Creates the indexes of the notifications table.
	 */
	private static function indexNotifications(Database $database): void
	{
		$database->execute(
			'CREATE INDEX IF NOT EXISTS notifications_position ON notifications (event, order_id, product_id, transaction_id)'
		);
		$database->execute('CREATE INDEX IF NOT EXISTS notifications_received ON notifications (received_at)');
	}

	/**
	 * Creates the storage directory with mode 0700. Fails before mkdir() when an ancestor is a file.
	 */
	private static function makeDirectory(string $directory): void
	{
		$ancestor = $directory;

		while (is_dir($ancestor) === false) {
			if (file_exists($ancestor)) {
				throw new InvalidArgumentException(
					message: 'Uketsuke: the storage directory cannot be created, a file is in the way'
				);
			}

			$parent = dirname($ancestor);

			if ($parent === $ancestor) {
				break;
			}

			$ancestor = $parent;
		}

		if (is_dir($directory) === false && mkdir($directory, 0700, true) === false) {
			throw new PermissionException(
				message: 'Uketsuke: the storage directory cannot be created'
			);
		}
	}

	/**
	 * Creates or migrates the schema inside one write transaction, so two requests cannot migrate at once.
	 */
	private static function migrate(Database $database): void
	{
		$database->execute('BEGIN IMMEDIATE');

		try {
			$version = self::version($database);

			if ($version === 0) {
				self::create($database);
			} elseif ($version === 1) {
				self::migrateFromOne($database);
			}

			$database->execute('PRAGMA user_version = ' . self::VERSION);
		} catch (Throwable $e) {
			$database->execute('ROLLBACK');
			throw $e;
		}

		$database->execute('COMMIT');
	}

	/**
	 * Migrates a version 1 ledger: order and product as key, hook state on notifications.
	 *
	 * Version 1 had one purchase per order, so each delivery takes the product of its order.
	 * Existing notifications count as announced.
	 */
	private static function migrateFromOne(Database $database): void
	{
		$database->execute('ALTER TABLE notifications ADD COLUMN hook TEXT');
		$database->execute('ALTER TABLE notifications ADD COLUMN announced_at TEXT');
		$database->execute('UPDATE notifications SET announced_at = received_at');
		$database->execute('DROP INDEX IF EXISTS notifications_key');
		self::indexNotifications($database);

		self::createPurchases($database, 'purchases_v2');
		$database->execute('INSERT INTO purchases_v2 SELECT * FROM purchases');
		$database->execute('DROP TABLE purchases');
		$database->execute('ALTER TABLE purchases_v2 RENAME TO purchases');
		$database->execute('CREATE INDEX IF NOT EXISTS purchases_paid ON purchases (paid_at)');

		self::createDeliveries($database, 'deliveries_v2');
		$database->execute(
			'INSERT INTO deliveries_v2
				(id, order_id, product_id, channel, status, attempts, error, sent_at, meta, created_at, updated_at)
			SELECT d.id, d.order_id,
				COALESCE((SELECT p.product_id FROM purchases p WHERE p.order_id = d.order_id), \'\'),
				d.channel, d.status, d.attempts, d.error, d.sent_at, d.meta, d.created_at, d.updated_at
			FROM deliveries d'
		);
		$database->execute('DROP TABLE deliveries');
		$database->execute('ALTER TABLE deliveries_v2 RENAME TO deliveries');
	}

	/**
	 * Creates the directory if needed and returns the connected, migrated database.
	 */
	public static function open(string $directory): Database
	{
		self::makeDirectory($directory);

		$database = new Database([
			'type' => 'sqlite',
			'database' => $directory . '/' . self::FILE,
		]);
		$database->fail();
		$database->execute('PRAGMA busy_timeout = 5000');
		$database->execute('PRAGMA journal_mode = WAL');

		if (self::version($database) < self::VERSION) {
			self::migrate($database);
		}

		return $database;
	}

	/**
	 * Returns the schema version stored in the database file, 0 for a new file.
	 */
	private static function version(Database $database): int
	{
		$rows = $database->query('PRAGMA user_version', [], ['fetch' => 'array', 'iterator' => 'array']);

		return (int)($rows[0]['user_version'] ?? 0);
	}
}
