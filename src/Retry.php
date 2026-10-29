<?php

namespace Sayamaapps\Uketsuke;

use DateTimeImmutable;
use DateTimeZone;
use Kirby\Cms\App;

/**
 * Fires `uketsuke.payment:after` again for paid purchases, with `retry` set to true.
 *
 * Used by the retry route after a delivery failed. The notification passed to the hook is the
 * original on_payment notification of the purchase, or a synthetic one with event `retry`.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-uketsuke
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final class Retry
{
	/** Purchases per request when the caller gives no limit. */
	public const LIMIT = 20;

	/** Upper bound of the limit: each hook may send mail, and the request has a time limit. */
	public const MAX_LIMIT = 100;

	/**
	 * Retries a page of paid purchases with a delivery in the channel with the given status.
	 * A null status means: without any delivery in the channel.
	 *
	 * `next` is the cursor for the following page (pass it as `after`), null on the last page.
	 *
	 * @return array{triggered: list<string>, failed: list<array{order_id: string, product_id: string, error: string}>, next: int|null}
	 */
	public static function channel(
		App $kirby,
		Ledger $ledger,
		string $channel,
		string|null $status,
		int $after = 0,
		int $limit = self::LIMIT
	): array {
		$limit = min(max(1, $limit), self::MAX_LIMIT);
		$page = $ledger->paidWithDelivery($channel, $status, $after, $limit + 1);
		$next = null;

		if (count($page) > $limit) {
			$page = array_slice($page, 0, $limit);
			$next = $page[$limit - 1]['key'];
		}

		$result = self::fire($kirby, $ledger, array_column($page, 'purchase'));
		$result['next'] = $next;

		return $result;
	}

	/**
	 * Fires the payment hook for each purchase and collects the order ids and the failures.
	 *
	 * @param list<Purchase> $purchases
	 * @return array{triggered: list<string>, failed: list<array{order_id: string, product_id: string, error: string}>}
	 */
	private static function fire(App $kirby, Ledger $ledger, array $purchases): array
	{
		$triggered = [];
		$failed = [];

		foreach ($purchases as $purchase) {
			$notification = $ledger->notifications()->originalPayment($purchase)
				?? new Notification(
					event: 'retry',
					orderId: $purchase->orderId,
					transactionId: '',
					productId: $purchase->productId,
					receivedAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
					payload: [],
					duplicate: false,
				);

			$error = Hooks::fire($kirby, 'uketsuke.payment:after', [
				'purchase' => $purchase,
				'notification' => $notification,
				'retry' => true,
			]);

			$triggered[] = $purchase->orderId;

			if ($error !== null) {
				$failed[] = ['order_id' => $purchase->orderId, 'product_id' => $purchase->productId, 'error' => $error];
			}
		}

		return ['triggered' => $triggered, 'failed' => $failed];
	}

	/**
	 * Retries every paid product of one order.
	 *
	 * @return array{triggered: list<string>, failed: list<array{order_id: string, product_id: string, error: string}>, next: null}
	 */
	public static function order(App $kirby, Ledger $ledger, string $orderId): array
	{
		$paid = array_values(array_filter(
			$ledger->order($orderId),
			fn (Purchase $purchase): bool => $purchase->status === 'paid'
		));

		$result = self::fire($kirby, $ledger, $paid);
		$result['next'] = null;

		return $result;
	}
}
