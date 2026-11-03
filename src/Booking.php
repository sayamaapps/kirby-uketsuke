<?php

namespace Sayamaapps\Uketsuke;

use DateTimeImmutable;

/**
 * Writes the purchase change of a notification and reports which purchases it changed.
 *
 * A payment creates its product. A refund or chargeback with a product id changes that
 * product, which is created if it is unknown. Without a product id it settles the whole order:
 * it changes every product of a known order and, for a known and an unknown order alike,
 * leaves a placeholder purchase without product id (unless the order has one already). The
 * placeholder marks the whole order as settled, so a payment that arrives later is stored
 * with that status instead of as paid. Next to real products the placeholder is no purchase:
 * it is not reported as changed, and the Ledger listings leave it out.
 *
 * @internal Used by the receiver.
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-uketsuke
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final class Booking
{
	/** The hook each purchase status fires when a notification sets it. */
	private const EVENT_HOOKS = [
		'paid' => 'uketsuke.payment:after',
		'refunded' => 'uketsuke.refund:after',
		'chargeback' => 'uketsuke.chargeback:after',
	];

	/** Events that create or change a purchase. */
	private const PURCHASE_EVENTS = ['on_payment', 'on_refund', 'on_chargeback'];

	/** Statuses that settle a whole order. */
	private const SETTLED_STATUSES = ['refunded', 'chargeback'];

	/**
	 * Creates the booking for one request.
	 */
	public function __construct(
		private readonly Ledger $ledger
	) {
	}

	/**
	 * Writes the purchase change for the event and returns the hook to fire and the purchases it fires for.
	 *
	 * A purchase whose status the event did not change is not returned; when nothing changed,
	 * the hook is null, so a replayed notification fires nothing, now or when it is repeated.
	 *
	 * @return array{hook: string|null, purchases: list<Purchase>}
	 */
	public function book(Payload $payload, DateTimeImmutable $now): array
	{
		if ($payload->orderId() === '') {
			return ['hook' => null, 'purchases' => []];
		}

		if ($payload->event() === 'on_payment') {
			return $this->bookPayment($payload, $now);
		}

		$status = match ($payload->event()) {
			'on_refund' => 'refunded',
			'on_chargeback' => 'chargeback',
			default => null,
		};

		if ($status === null) {
			return ['hook' => null, 'purchases' => []];
		}

		$purchases = $this->settle($payload, $status, $now);

		return ['hook' => $purchases === [] ? null : self::EVENT_HOOKS[$status], 'purchases' => $purchases];
	}

	/**
	 * Stores the product of an on_payment and returns the hook to fire.
	 *
	 * A refund or chargeback without product id that arrived before the payment left a
	 * placeholder with the status of the whole order, whether the order was unknown then or
	 * already had other products. The product is then stored with that status, and the hook
	 * of that status fires instead of the payment hook. A product that is already stored
	 * fires `uketsuke.payment:repeat`, whatever its status.
	 *
	 * @return array{hook: string|null, purchases: list<Purchase>}
	 */
	private function bookPayment(Payload $payload, DateTimeImmutable $now): array
	{
		$orderId = $payload->orderId();
		$placeholder = $this->ledger->purchase($orderId, '');
		$settled = $placeholder !== null && in_array($placeholder->status, self::SETTLED_STATUSES, true);
		$status = $settled ? $placeholder->status : 'paid';
		$refundedAt = $settled ? $placeholder->refundedAt : null;
		$inserted = $this->ledger->insertPurchase($payload->purchaseRow($now, $status, $refundedAt));

		return [
			'hook' => $inserted ? self::EVENT_HOOKS[$status] : 'uketsuke.payment:repeat',
			'purchases' => $this->find($orderId, $payload->productId()),
		];
	}

	/**
	 * Returns true when the notification is an event that creates or changes a purchase and has an order id.
	 */
	private function concerns(Notification $notification): bool
	{
		return $notification->orderId !== '' && in_array($notification->event, self::PURCHASE_EVENTS, true);
	}

	/**
	 * Returns the purchase of an order and product as a list of zero or one. An empty product id means the first product.
	 *
	 * @return list<Purchase>
	 */
	private function find(string $orderId, string $productId): array
	{
		$purchase = $this->ledger->purchase($orderId, $productId === '' ? null : $productId);

		return $purchase === null ? [] : [$purchase];
	}

	/**
	 * Sets the status of every product of a known order and returns the purchases that changed, as stored now.
	 *
	 * A placeholder without product id is updated too, but only counts as a changed purchase
	 * while the order has no other product. It is not created here, see settle().
	 *
	 * @return list<Purchase>
	 */
	private function order(string $orderId, string $status, DateTimeImmutable|null $refundedAt): array
	{
		$products = $this->ledger->order($orderId);
		$real = array_filter($products, static fn (Purchase $purchase): bool => $purchase->productId !== '');
		$changed = [];

		foreach ($real === [] ? $products : $real as $purchase) {
			if ($purchase->status !== $status) {
				$changed[] = $purchase->productId;
			}
		}

		$this->ledger->setStatus($orderId, $status, $refundedAt);

		return array_values(array_filter(
			$this->ledger->order($orderId),
			static fn (Purchase $purchase): bool => in_array($purchase->productId, $changed, true)
		));
	}

	/**
	 * Returns the purchase a notification refers to, null for events without a purchase.
	 */
	public function purchase(Notification $notification): Purchase|null
	{
		if ($this->concerns($notification) === false) {
			return null;
		}

		return $this->find($notification->orderId, $notification->productId)[0] ?? null;
	}

	/**
	 * Returns the purchases a stored notification fired its hook for, to fire it again.
	 *
	 * A refund or chargeback without product id fired for every product of the order that
	 * has the status now.
	 *
	 * @return list<Purchase>
	 */
	public function purchases(Notification $notification): array
	{
		if ($this->concerns($notification) === false) {
			return [];
		}

		if ($notification->productId !== '' || $notification->event === 'on_payment') {
			return $this->find($notification->orderId, $notification->productId);
		}

		$status = $notification->event === 'on_refund' ? 'refunded' : 'chargeback';

		return array_values(array_filter(
			$this->ledger->order($notification->orderId),
			static fn (Purchase $purchase): bool => $purchase->status === $status
		));
	}

	/**
	 * Sets a refund or chargeback status and returns the purchases whose status changed.
	 *
	 * Without a product id the whole order is settled. A known order changes every product (see
	 * order()), and the status is also stored in the placeholder purchase without product id,
	 * which is created if the order has none yet, so that a payment of another product that
	 * arrives later is stored with this status. The placeholder is written after the products
	 * and has a later row id, so it is never the first product of the order, and it is not part
	 * of the returned purchases while the order has other products. An unknown order gets the
	 * placeholder only, and it is returned as the changed purchase.
	 *
	 * @param $status `refunded` or `chargeback`.
	 * @return list<Purchase>
	 */
	private function settle(Payload $payload, string $status, DateTimeImmutable $now): array
	{
		$orderId = $payload->orderId();
		$productId = $payload->productId();
		$refundedAt = $status === 'refunded' ? $now : null;

		if ($productId === '' && $this->ledger->purchase($orderId) !== null) {
			$changed = $this->order($orderId, $status, $refundedAt);
			$this->ledger->insertPurchase($payload->purchaseRow($now, $status, $refundedAt));

			return $changed;
		}

		if ($this->ledger->insertPurchase($payload->purchaseRow($now, $status, $refundedAt))) {
			return $this->find($orderId, $productId);
		}

		return $this->ledger->setStatus($orderId, $status, $refundedAt, $productId) > 0
			? $this->find($orderId, $productId)
			: [];
	}
}
