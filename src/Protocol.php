<?php

namespace Sayamaapps\Uketsuke;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Builds the protocol of paid purchases for the protocol route.
 *
 * The output has no buyer data: no e-mail, no name, no address. Delivery errors are
 * stored with e-mail addresses removed (see Ledger::recordDelivery()), because mail servers quote the recipient in them.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-uketsuke
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final class Protocol
{
	/**
	 * Builds the protocol for purchases paid on the days from $since to $until (both inclusive).
	 *
	 * @return array<string, mixed>
	 */
	public static function build(Ledger $ledger, DateTimeImmutable $since, DateTimeImmutable|null $until): array
	{
		$purchases = [];

		foreach ($ledger->purchases($since, $until?->modify('+1 day')) as $purchase) {
			$purchases[] = [
				'order_id' => $purchase->orderId,
				'product_id' => $purchase->productId,
				'product_name' => $purchase->productName,
				'status' => $purchase->status,
				'paid_at' => $purchase->paidAt->format(DATE_ATOM),
				'test' => $purchase->test,
				'source' => 'ipn',
				'hooks_pending' => $ledger->notifications()->unannounced($purchase),
				'deliveries' => self::deliveries($ledger->deliveries($purchase)),
			];
		}

		return [
			'since' => $since->format('Y-m-d'),
			'until' => $until?->format('Y-m-d'),
			'purchases' => $purchases,
		];
	}

	/**
	 * Parses a YYYY-MM-DD date in Europe/Berlin, null if it is not a valid date.
	 */
	public static function date(string $value): DateTimeImmutable|null
	{
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Europe/Berlin'));

		return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
	}

	/**
	 * Converts deliveries to their public fields.
	 *
	 * @param list<array{channel: string, status: string, attempts: int, error: string|null, sent_at: DateTimeImmutable|null, meta: array<string, mixed>}> $deliveries
	 * @return list<array<string, mixed>>
	 */
	private static function deliveries(array $deliveries): array
	{
		$public = [];

		foreach ($deliveries as $delivery) {
			$public[] = [
				'channel' => $delivery['channel'],
				'status' => $delivery['status'],
				'attempts' => $delivery['attempts'],
				'sent_at' => $delivery['sent_at']?->format(DATE_ATOM),
				'error' => $delivery['error'],
			];
		}

		return $public;
	}
}
