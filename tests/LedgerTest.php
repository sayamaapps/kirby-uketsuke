<?php

use Kirby\Exception\InvalidArgumentException;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Uketsuke\Ledger;
use Sayamaapps\Uketsuke\Notification;
use Sayamaapps\Uketsuke\Notifications;
use Sayamaapps\Uketsuke\Payload;
use Sayamaapps\Uketsuke\Purchase;
use Sayamaapps\Uketsuke\Sql;

#[CoversClass(Ledger::class)]
#[CoversClass(Purchase::class)]
#[CoversClass(Notification::class)]
#[CoversClass(Notifications::class)]
#[CoversClass(Sql::class)]
final class LedgerTest extends TestCase
{
	private string $directory;

	private function notification(string $event, string $orderId, string $transactionId, DateTimeImmutable $at, bool $duplicate = false): Notification
	{
		return new Notification($event, $orderId, $transactionId, '111111', $at, ['event' => $event], $duplicate);
	}

	private function payment(string $orderId, string $paidAt = '2026-10-29 10:00:00', string $productId = '111111'): array
	{
		return (new Payload(['order_id' => $orderId, 'product_id' => $productId, 'email' => 'a@example.com', 'order_date_time' => $paidAt]))
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

	public function testDeliveryMetaAndErrorLoseAddresses(): void
	{
		$ledger = new Ledger($this->directory);
		$ledger->recordDelivery('ORD-1', 'mail', 'failed', '550 erika@example.com unknown', [
			'to' => 'erika@example.com',
			'nested' => ['cc' => 'max@example.org'],
			'count' => 2,
		]);

		$delivery = $ledger->delivery('ORD-1', 'mail');
		$this->assertSame('550 [address] unknown', $delivery['error']);
		$this->assertSame(['to' => '[address]', 'nested' => ['cc' => '[address]'], 'count' => 2], $delivery['meta']);
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

	public function testNotificationKeyHookAndAnnouncement(): void
	{
		$ledger = new Ledger($this->directory);
		$at = self::utc('2026-10-29 10:00:00');

		$this->assertNull($ledger->notifications()->original('on_payment', 'ORD-1', '111111', 'TX-1'));
		$id = $ledger->notifications()->add($this->notification('on_payment', 'ORD-1', 'TX-1', $at), 'uketsuke.payment:after', false);
		$this->assertNull($ledger->notifications()->original('on_payment', 'ORD-1', '111111', 'TX-2'));
		$this->assertNull($ledger->notifications()->original('on_payment', 'ORD-1', '222222', 'TX-1'));

		$original = $ledger->notifications()->original('on_payment', 'ORD-1', '111111', 'TX-1');
		$this->assertSame($id, $original['id']);
		$this->assertSame('uketsuke.payment:after', $original['hook']);
		$this->assertFalse($original['announced']);

		$ledger->notifications()->markAnnounced($id, 'RuntimeException');
		$this->assertTrue($ledger->notifications()->original('on_payment', 'ORD-1', '111111', 'TX-1')['announced']);
		$ledger->notifications()->add($this->notification('on_payment', 'ORD-1', 'TX-1', $at, true));

		$ledger->insertPurchase($this->payment('ORD-1'));
		$payment = $ledger->notifications()->originalPayment($ledger->purchase('ORD-1'));
		$this->assertSame('TX-1', $payment->transactionId);
		$this->assertFalse($payment->duplicate);
		$ledger->insertPurchase($this->payment('ORD-404'));
		$this->assertNull($ledger->notifications()->originalPayment($ledger->purchase('ORD-404')));
	}

	public function testOrderIdAloneResolvesToTheOnlyProduct(): void
	{
		$ledger = new Ledger($this->directory);
		$ledger->insertPurchase($this->payment('ORD-1', productId: '111111'));

		$ledger->recordDelivery('ORD-1', 'mail', 'sent');

		$this->assertSame('sent', $ledger->delivery($ledger->purchase('ORD-1'), 'mail')['status']);
	}

	public function testOrderWithSeveralProductsKeepsOnePurchaseAndDeliveryEach(): void
	{
		$ledger = new Ledger($this->directory);

		$this->assertTrue($ledger->insertPurchase($this->payment('ORD-1', productId: '111111')));
		$this->assertTrue($ledger->insertPurchase($this->payment('ORD-1', productId: '222222')));
		$this->assertFalse($ledger->insertPurchase($this->payment('ORD-1', productId: '222222')));

		[$main, $bump] = $ledger->order('ORD-1');
		$this->assertSame('111111', $main->productId);
		$this->assertSame('222222', $bump->productId);
		$this->assertSame('111111', $ledger->purchase('ORD-1')->productId);
		$this->assertSame('222222', $ledger->purchase('ORD-1', '222222')->productId);
		$this->assertNull($ledger->purchase('ORD-1', '333333'));

		$ledger->recordDelivery($main, 'mail', 'sent');
		$ledger->recordDelivery($bump, 'mail', 'failed');
		$this->assertSame('sent', $ledger->delivery($main, 'mail')['status']);
		$this->assertSame('failed', $ledger->delivery($bump, 'mail')['status']);
		$this->assertCount(1, $ledger->deliveries($bump));

		$this->assertSame(1, $ledger->setStatus('ORD-1', 'refunded', null, '222222'));
		$this->assertSame('paid', $ledger->purchase('ORD-1')->status);
		$this->assertSame(0, $ledger->setStatus('ORD-1', 'refunded', null, '222222'));
		$this->assertSame(1, $ledger->setStatus('ORD-1', 'refunded', null));

		$this->expectException(InvalidArgumentException::class);
		$ledger->delivery('ORD-1', 'mail');
	}

	public function testPaidWithDeliveryByStatusOrWithoutDeliveryInPages(): void
	{
		$ledger = new Ledger($this->directory);

		foreach (['ORD-1', 'ORD-2', 'ORD-3', 'ORD-4'] as $orderId) {
			$ledger->insertPurchase($this->payment($orderId));
		}

		$ledger->recordDelivery('ORD-2', 'mail', 'failed');
		$ledger->recordDelivery('ORD-1', 'mail', 'failed');
		$ledger->recordDelivery('ORD-3', 'mail', 'sent');
		$ledger->setStatus('ORD-4', 'refunded', null);

		$orders = static fn (array $page): array => array_map(static fn (array $row): string => $row['purchase']->orderId, $page);

		$this->assertSame(['ORD-1', 'ORD-2'], $orders($ledger->paidWithDelivery('mail', 'failed', 0, 10)));
		$this->assertSame([], $orders($ledger->paidWithDelivery('mail', null, 0, 10)));

		$first = $ledger->paidWithDelivery('mail', 'failed', 0, 1);
		$this->assertSame(['ORD-1'], $orders($first));
		$this->assertSame(['ORD-2'], $orders($ledger->paidWithDelivery('mail', 'failed', $first[0]['key'], 1)));

		$ledger->insertPurchase($this->payment('ORD-5'));
		$this->assertSame(['ORD-5'], $orders($ledger->paidWithDelivery('mail', null, 0, 10)));
		$this->assertSame(['ORD-1', 'ORD-2', 'ORD-3', 'ORD-5'], $orders($ledger->paidWithDelivery('sms', null, 0, 10)));
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
		$ledger->notifications()->add($this->notification('on_payment', 'ORD-1', 'TX-1', self::utc('2026-01-01 00:00:00')));
		$ledger->notifications()->add($this->notification('on_payment', 'ORD-1', 'TX-2', self::utc('2026-10-28 00:00:00')));

		$this->assertSame(1, $ledger->notifications()->purge(self::utc('2026-10-01 00:00:00')));
		$this->assertNull($ledger->notifications()->original('on_payment', 'ORD-1', '111111', 'TX-1'));
		$this->assertNotNull($ledger->notifications()->original('on_payment', 'ORD-1', '111111', 'TX-2'));
		$this->assertNotNull($ledger->purchase('ORD-1'));
	}

	public function testSchemaIsCreatedWithWalAndUserVersion(): void
	{
		$ledger = new Ledger($this->directory);
		$this->assertNull($ledger->purchase('ORD-1'));

		$pdo = new PDO('sqlite:' . $this->directory . '/uketsuke.sqlite');
		$this->assertSame(2, (int)$pdo->query('PRAGMA user_version')->fetchColumn());
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

	public function testUnannouncedPaymentIsReported(): void
	{
		$ledger = new Ledger($this->directory);
		$ledger->insertPurchase($this->payment('ORD-1'));
		$purchase = $ledger->purchase('ORD-1');

		$this->assertFalse($ledger->notifications()->unannounced($purchase));

		$id = $ledger->notifications()->add(
			$this->notification('on_payment', 'ORD-1', 'TX-1', self::utc('2026-10-29 10:00:00')),
			'uketsuke.payment:after',
			false
		);
		$this->assertTrue($ledger->notifications()->unannounced($purchase));

		$ledger->notifications()->markAnnounced($id);
		$this->assertFalse($ledger->notifications()->unannounced($purchase));
	}

	public function testVersionOneLedgerIsMigrated(): void
	{
		mkdir($this->directory, 0700, true);
		$pdo = new PDO('sqlite:' . $this->directory . '/uketsuke.sqlite');
		$pdo->exec("CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, received_at TEXT NOT NULL,
			event TEXT NOT NULL, order_id TEXT NOT NULL DEFAULT '', transaction_id TEXT NOT NULL DEFAULT '',
			product_id TEXT NOT NULL DEFAULT '', duplicate INTEGER NOT NULL DEFAULT 0, hook_error TEXT, payload TEXT NOT NULL)");
		$pdo->exec('CREATE INDEX notifications_key ON notifications (event, order_id, transaction_id)');
		$pdo->exec("CREATE TABLE purchases (order_id TEXT PRIMARY KEY, product_id TEXT NOT NULL DEFAULT '', product_name TEXT,
			product_name_intern TEXT, email TEXT, first_name TEXT, last_name TEXT, status TEXT NOT NULL, paid_at TEXT NOT NULL,
			refunded_at TEXT, test INTEGER NOT NULL DEFAULT 0, language TEXT, country TEXT, affiliate TEXT, campaignkey TEXT,
			amount REAL, currency TEXT, custom TEXT, buyer_id TEXT, source TEXT NOT NULL DEFAULT 'ipn',
			created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
		$pdo->exec('CREATE TABLE deliveries (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id TEXT NOT NULL, channel TEXT NOT NULL,
			status TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, error TEXT, sent_at TEXT, meta TEXT,
			created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (order_id, channel))');
		$pdo->exec("INSERT INTO notifications (received_at, event, order_id, transaction_id, product_id, payload)
			VALUES ('2026-10-29 10:00:00', 'on_payment', 'ORD-1', 'TX-1', '111111', '{}')");
		$pdo->exec("INSERT INTO purchases (order_id, product_id, email, status, paid_at, created_at, updated_at)
			VALUES ('ORD-1', '111111', 'a@example.com', 'paid', '2026-10-29 10:00:00', '2026-10-29 10:00:00', '2026-10-29 10:00:00')");
		$pdo->exec("INSERT INTO deliveries (order_id, channel, status, attempts, created_at, updated_at)
			VALUES ('ORD-1', 'mail', 'sent', 1, '2026-10-29 10:00:00', '2026-10-29 10:00:00')");
		$pdo->exec('PRAGMA user_version = 1');
		$pdo = null;

		$ledger = new Ledger($this->directory);
		$purchase = $ledger->purchase('ORD-1');

		$this->assertSame('a@example.com', $purchase->email);
		$this->assertSame('sent', $ledger->delivery($purchase, 'mail')['status']);
		$this->assertTrue($ledger->notifications()->original('on_payment', 'ORD-1', '111111', 'TX-1')['announced']);
		$this->assertFalse($ledger->notifications()->unannounced($purchase));
		$this->assertTrue($ledger->insertPurchase($this->payment('ORD-1', productId: '222222')));

		$pdo = new PDO('sqlite:' . $this->directory . '/uketsuke.sqlite');
		$this->assertSame(2, (int)$pdo->query('PRAGMA user_version')->fetchColumn());
		$pdo = null;
	}

	private static function utc(string $value): DateTimeImmutable
	{
		return new DateTimeImmutable($value, new DateTimeZone('UTC'));
	}
}
