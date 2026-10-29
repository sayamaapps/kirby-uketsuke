<?php

namespace Sayamaapps\Uketsuke;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Kirby\Database\Database;

/**
 * Small helpers shared by the ledger tables: queries as arrays, UTC timestamps.
 *
 * @internal
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-uketsuke
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final class Sql
{
	/**
	 * Runs a query and returns the rows as arrays.
	 *
	 * @param array<string, mixed> $bindings
	 * @return list<array<string, mixed>>
	 */
	public static function rows(Database $database, string $sql, array $bindings = []): array
	{
		$rows = $database->query($sql, $bindings, ['fetch' => 'array', 'iterator' => 'array']);

		return is_array($rows) ? array_values($rows) : [];
	}

	/**
	 * Formats an instant as the UTC timestamp the ledger stores ('Y-m-d H:i:s').
	 */
	public static function stamp(DateTimeInterface $moment): string
	{
		return DateTimeImmutable::createFromInterface($moment)
			->setTimezone(new DateTimeZone('UTC'))
			->format('Y-m-d H:i:s');
	}
}
