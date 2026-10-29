<?php

use Kirby\Exception\InvalidArgumentException;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Uketsuke\Ledger;
use Sayamaapps\Uketsuke\Notification;
use Sayamaapps\Uketsuke\Payload;
use Sayamaapps\Uketsuke\Purchase;

#[CoversClass(Ledger::class)]
#[CoversClass(Purchase::class)]
#[CoversClass(Notification::class)]
final class LedgerTest extends TestCase
{
	private string $directory;

	private function notification(string $event, string $orderId, string $transactionId, DateTimeImmutable $at, bool $duplicate = false): Notification
	{
		return new Notification($event, $orderId, $transactionId, '111111', $at, ['event' => $event], $duplicate);
	}

	private function payment(string $orderId, string $paidAt = '2026-10-29 10:00:00'): array
	{
		return (new Payload(['order_id' => $orderId, 'product_id' => '111111', 'email' => 'a@example.com', 'order_date_time' => $paidAt]))
			->purchaseRow(self::utc('2026-10-29 12:00:00'), 'paid', null);
	}

	protected function setUp(): void
	{
		$this->directory = sys_get_temp_dir() . '/uketsuke-ledger-' . bin2hex(random_bytes(8));
	}

	protected function tearDown(): void
	{
		Dir::remove($this->directory);
	}

	public function testDeliveriesCountAttemptsAndKeepTheLastStatus(): void
	{
		$ledger = new Ledger($this->directory);

		$this->assertNull($ledger->delivery('ORD-1', 'mail'));

		$ledger->recordDelivery('ORD-1', 'mail', 'failed', 'SMTP timeout');
		$ledger->recordDelivery('ORD-1', 'mail', 'sent', null, ['message_id' => 'abc']);

		$delivery = $ledger->delivery('ORD-1', 'mail');
		$this->assertSame('sent', $delivery['status']);
		$this->assertSame(2, $delivery['attempts']);
		$this->assertNull($delivery['error']);
		$this->assertInstanceOf(DateTimeImmutable::class, $delivery['sent_at']);
		$this->assertSame(['message_id' => 'abc'], $delivery['meta']);
		$this->assertCount(1, $ledger->deliveries('ORD-1'));
	}

	public function testDeliveryStatusIsValidated(): void
	{
		$ledger = new Ledger($this->directory);

		$this->expectException(InvalidArgumentException::class);
		$ledger->recordDelivery('ORD-1', 'mail', 'delivered');
	}

	public function testInsertPurchaseOnceAndReadItBack(): void
	{
		$ledger = new Ledger($this->directory);

		$this->assertTrue($ledger->insertPurchase($this->payment('ORD-1')));
		$this->assertFalse($ledger->insertPurchase($this->payment('ORD-1')));

		$purchase = $ledger->purchase('ORD-1');
		$this->assertInstanceOf(Purchase::class, $purchase);
		$this->assertSame('111111', $purchase->productId);
		$this->assertSame('paid', $purchase->status);
		$this->assertSame('a@example.com', $purchase->email);
		$this->assertFalse($purchase->test);
		$this->assertNull($purchase->refundedAt);
		$this->assertSame('2026-10-29T09:00:00+00:00', $purchase->paidAt->format(DATE_ATOM));
		$this->assertArrayHasKey('campaignKey', $purchase->toArray());
	}

	public function testNothingIsWrittenBeforeTheFirstUse(): void
	{
		new Ledger($this->directory);

		$this->assertDirectoryDoesNotExist($this->directory);
	}

	public function testNotificationKeyAndHookError(): void
	{
		$ledger = new Ledger($this->directory);
		$at = self::utc('2026-10-29 10:00:00');

		$this->assertFalse($ledger->notificationSeen('on_payment', 'ORD-1', 'TX-1'));
		$id = $ledger->addNotification($this->notification('on_payment', 'ORD-1', 'TX-1', $at));
		$this->assertTrue($ledger->notificationSeen('on_payment', 'ORD-1', 'TX-1'));
		$this->assertFalse($ledger->notificationSeen('on_payment', 'ORD-1', 'TX-2'));

		$ledger->markHookError($id, 'RuntimeException');
		$ledger->addNotification($this->notification('on_payment', 'ORD-1', 'TX-1', $at, true));

		$original = $ledger->originalPayment('ORD-1');
		$this->assertSame('TX-1', $original->transactionId);
		$this->assertFalse($original->duplicate);
		$this->assertNull($ledger->originalPayment('ORD-404'));
	}

	public function testOrdersWithDeliveryByChannelAndStatus(): void
	{
		$ledger = new Ledger($this->directory);
		$ledger->recordDelivery('ORD-2', 'mail', 'failed');
		$ledger->recordDelivery('ORD-1', 'mail', 'failed');
		$ledger->recordDelivery('ORD-3', 'mail', 'sent');

		$this->assertSame(['ORD-1', 'ORD-2'], $ledger->ordersWithDelivery('mail', 'failed'));
	}

	public function testPurchasesFilterByPaymentTimeWithExclusiveUntil(): void
	{
		$ledger = new Ledger($this->directory);
		$ledger->insertPurchase($this->payment('ORD-OLD', '2026-10-01 10:00:00'));
		$ledger->insertPurchase($this->payment('ORD-A', '2026-10-10 10:00:00'));
		$ledger->insertPurchase($this->payment('ORD-B', '2026-10-20 10:00:00'));

		$orders = array_map(static fn (Purchase $p): string => $p->orderId, $ledger->purchases(self::utc('2026-10-05 00:00:00'), self::utc('2026-10-20 08:00:00')));

		$this->assertSame(['ORD-A'], $orders);
		$this->assertCount(3, $ledger->purchases(self::utc('2026-01-01 00:00:00')));
	}

	public function testPurgeRemovesOldNotificationsOnly(): void
	{
		$ledger = new Ledger($this->directory);
		$ledger->insertPurchase($this->payment('ORD-1'));
		$ledger->addNotification($this->notification('on_payment', 'ORD-1', 'TX-1', self::utc('2026-01-01 00:00:00')));
		$ledger->addNotification($this->notification('on_payment', 'ORD-1', 'TX-2', self::utc('2026-10-28 00:00:00')));

		$this->assertSame(1, $ledger->purgeNotifications(self::utc('2026-10-01 00:00:00')));
		$this->assertFalse($ledger->notificationSeen('on_payment', 'ORD-1', 'TX-1'));
		$this->assertTrue($ledger->notificationSeen('on_payment', 'ORD-1', 'TX-2'));
		$this->assertNotNull($ledger->purchase('ORD-1'));
	}

	public function testSchemaIsCreatedWithWalAndUserVersion(): void
	{
		$ledger = new Ledger($this->directory);
		$this->assertNull($ledger->purchase('ORD-1'));

		$pdo = new PDO('sqlite:' . $this->directory . '/uketsuke.sqlite');
		$this->assertSame(1, (int)$pdo->query('PRAGMA user_version')->fetchColumn());
		$this->assertSame('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
		$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
		$this->assertSame(['deliveries', 'notifications', 'purchases'], array_values(array_diff($tables, ['sqlite_sequence'])));
		$pdo = null;
	}

	public function testSetStatusMarksRefundAndChargeback(): void
	{
		$ledger = new Ledger($this->directory);
		$ledger->insertPurchase($this->payment('ORD-1'));

		$ledger->setStatus('ORD-1', 'refunded', self::utc('2026-10-30 09:00:00'));
		$refunded = $ledger->purchase('ORD-1');
		$this->assertSame('refunded', $refunded->status);
		$this->assertSame('2026-10-30T09:00:00+00:00', $refunded->refundedAt?->format(DATE_ATOM));

		$ledger->setStatus('ORD-1', 'chargeback', null);
		$charged = $ledger->purchase('ORD-1');
		$this->assertSame('chargeback', $charged->status);
		$this->assertSame('2026-10-30T09:00:00+00:00', $charged->refundedAt?->format(DATE_ATOM));
	}

	public function testStorageDirectoryIsCreatedPrivately(): void
	{
		$ledger = new Ledger($this->directory);
		$ledger->purchase('ORD-1');

		$this->assertSame('0700', substr(sprintf('%o', fileperms($this->directory)), -4));
	}

	public function testTransactionRollsBackOnError(): void
	{
		$ledger = new Ledger($this->directory);

		try {
			$ledger->transaction(function () use ($ledger): void {
				$ledger->insertPurchase($this->payment('ORD-1'));
				throw new RuntimeException('boom');
			});
			$this->fail('the exception must pass through');
		} catch (RuntimeException) {
		}

		$this->assertNull($ledger->purchase('ORD-1'));
		$this->assertSame(42, $ledger->transaction(static fn (): int => 42));
	}

	private static function utc(string $value): DateTimeImmutable
	{
		return new DateTimeImmutable($value, new DateTimeZone('UTC'));
	}
}
