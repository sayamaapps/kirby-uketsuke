<?php

use Sayamaapps\Uketsuke\Notification;
use Sayamaapps\Uketsuke\Purchase;

/**
 * Hooks for the tests: every call is appended to a list, as [name, order id, details...].
 */
final class HookRecorder
{
	/**
	 * @param array<int, array<int, mixed>> $calls
	 * @return array<string, callable>
	 */
	public static function hooks(array &$calls): array
	{
		return [
			'uketsuke.payment:after' => function (Purchase $purchase, Notification $notification, bool $retry) use (&$calls): void {
				$calls[] = ['payment:after', $purchase->orderId, $notification->event, $retry];
			},
			'uketsuke.payment:repeat' => function (Purchase $purchase) use (&$calls): void {
				$calls[] = ['payment:repeat', $purchase->orderId];
			},
			'uketsuke.refund:after' => function (Purchase $purchase) use (&$calls): void {
				$calls[] = ['refund:after', $purchase->orderId, $purchase->status];
			},
			'uketsuke.chargeback:after' => function (Purchase $purchase) use (&$calls): void {
				$calls[] = ['chargeback:after', $purchase->orderId, $purchase->status];
			},
			'uketsuke.notification:after' => function (Notification $notification, Purchase|null $purchase = null) use (&$calls): void {
				$calls[] = ['notification:after', $notification->event, $purchase?->orderId];
			},
		];
	}
}
