<?php

namespace Sayamaapps\Uketsuke;

use Kirby\Database\Database;
use Kirby\Exception\Exception;

/**
 * Opens the ledger database: directory, connection, WAL and busy timeout, schema.
 *
 * The schema is created with SQL that SQLite 3.7.17 understands (no UPSERT, no RETURNING),
 * because shared hosters still ship that version. PRAGMA user_version tracks future migrations.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-uketsuke
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final class Schema
{
	public const FILE = 'uketsuke.sqlite';
	public const VERSION = 1;

	/**
	 * Creates the tables and indexes, then sets the schema version.
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
				hook_error TEXT,
				payload TEXT NOT NULL
			)'
		);
		$database->execute('CREATE INDEX IF NOT EXISTS notifications_key ON notifications (event, order_id, transaction_id)');
		$database->execute('CREATE INDEX IF NOT EXISTS notifications_received ON notifications (received_at)');
		$database->execute(
			'CREATE TABLE IF NOT EXISTS purchases (
				order_id TEXT PRIMARY KEY,
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
				updated_at TEXT NOT NULL
			)'
		);
		$database->execute('CREATE INDEX IF NOT EXISTS purchases_paid ON purchases (paid_at)');
		$database->execute(
			'CREATE TABLE IF NOT EXISTS deliveries (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				order_id TEXT NOT NULL,
				channel TEXT NOT NULL,
				status TEXT NOT NULL,
				attempts INTEGER NOT NULL DEFAULT 0,
				error TEXT,
				sent_at TEXT,
				meta TEXT,
				created_at TEXT NOT NULL,
				updated_at TEXT NOT NULL,
				UNIQUE (order_id, channel)
			)'
		);
		$database->execute('PRAGMA user_version = ' . self::VERSION);
	}

	/**
	 * Creates the storage directory with mode 0700. Fails before mkdir() when an ancestor is a file.
	 */
	private static function makeDirectory(string $directory): void
	{
		$ancestor = $directory;

		while (is_dir($ancestor) === false) {
			if (file_exists($ancestor)) {
				throw new Exception(
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
			throw new Exception(
				message: 'Uketsuke: the storage directory cannot be created'
			);
		}
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

		$rows = $database->query('PRAGMA user_version', [], ['fetch' => 'array', 'iterator' => 'array']);
		if ((int)($rows[0]['user_version'] ?? 0) < self::VERSION) {
			self::create($database);
		}

		return $database;
	}
}
