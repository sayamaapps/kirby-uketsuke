<?php

namespace Sayamaapps\Uketsuke;

use DateTimeImmutable;
use DateTimeZone;
use Kirby\Cms\App;

/**
 * Fires `uketsuke.payment:after` again for paid purchases, with `retry` set to true.
 *
 * Used by the retry route after a delivery failed. The notification passed to the hook is the
 * original on_payment notification of the order, or a synthetic one with event `retry`.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-uketsuke
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final class Retry
{
	/**
	 * Retries every paid order that has a delivery in the channel with the given status.
	 *
	 * @return list<string> The order ids that were retried.
	 */
	public static function channel(App $kirby, Ledger $ledger, string $channel, string $status): array
	{
		$triggered = [];

		foreach ($ledger->ordersWithDelivery($channel, $status) as $orderId) {
			$triggered = [...$triggered, ...self::order($kirby, $ledger, $orderId)];
		}

		return $triggered;
	}

	/**
	 * Fires the payment hook for the purchase with retry set to true.
	 */
	private static function fire(App $kirby, Ledger $ledger, Purchase $purchase): void
	{
		$notification = $ledger->originalPayment($purchase->orderId)
			?? new Notification(
				event: 'retry',
				orderId: $purchase->orderId,
				transactionId: '',
				productId: $purchase->productId,
				receivedAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
				payload: [],
				duplicate: false,
			);

		Hooks::fire($kirby, 'uketsuke.payment:after', [
			'purchase' => $purchase,
			'notification' => $notification,
			'retry' => true,
		]);
	}

	/**
	 * Retries one order if it is paid. Returns the order id if the hook was fired, [] otherwise.
	 *
	 * @return list<string>
	 */
	public static function order(App $kirby, Ledger $ledger, string $orderId): array
	{
		$purchase = $ledger->purchase($orderId);

		if ($purchase === null || $purchase->status !== 'paid') {
			return [];
		}

		self::fire($kirby, $ledger, $purchase);

		return [$orderId];
	}
}
