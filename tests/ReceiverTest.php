<?php

use Kirby\Filesystem\Dir;
use Kirby\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Uketsuke\Booking;
use Sayamaapps\Uketsuke\Ledger;
use Sayamaapps\Uketsuke\Notification;
use Sayamaapps\Uketsuke\Payload;
use Sayamaapps\Uketsuke\Purchase;
use Sayamaapps\Uketsuke\Receiver;
use Sayamaapps\Uketsuke\Retry;
use Sayamaapps\Uketsuke\Routes;

/**
 * The IPN flow through the real route: signature, notification row, purchase, hooks, answer.
 */
#[CoversClass(Receiver::class)]
#[CoversClass(Routes::class)]
#[CoversClass(Payload::class)]
#[CoversClass(Booking::class)]
#[RunTestsInSeparateProcesses]
final class ReceiverTest extends TestCase
{
	/** @var array<int, array<int, mixed>> */
	private array $calls = [];
	private SiteFixture $site;

	private function active(array $extra = []): array
	{
		return [...['active' => true, 'passphrase' => SiteFixture::PASSPHRASE], ...$extra];
	}

	private function ledger(): Ledger
	{
		return new Ledger($this->site->storage());
	}

	private function notificationRows(): array
	{
		$pdo = new PDO('sqlite:' . $this->site->storage() . '/uketsuke.sqlite');
		$rows = $pdo->query('SELECT * FROM notifications ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
		$pdo = null;

		return $rows;
	}

	private function payment(
		string $orderId = 'ORD-1',
		string $transactionId = 'TX-1',
		string $event = 'on_payment',
		string $productId = '111111',
		array $extra = []
	): array {
		return SiteFixture::signed(array_merge([
			'event' => $event,
			'order_id' => $orderId,
			'transaction_id' => $transactionId,
			'product_id' => $productId,
			'product_name' => 'Gasthaus Muster Kurs',
			'api_mode' => 'live',
			'email' => 'erika@example.com',
			'address_first_name' => 'Erika',
			'address_last_name' => 'Muster',
			'order_date' => '2026-10-29',
			'order_time' => '12:30:00',
			'transaction_amount' => '19.90',
			'transaction_currency' => 'EUR',
			'ipn_version' => '1.1',
		], $extra));
	}

	private function post(array $module, array $fields, array $hooks = []): Response|null
	{
		$app = $this->site->app($module, $hooks, 'POST', $fields);

		return $app->call('uketsuke/digistore24', 'POST');
	}

	/**
	 * Hooks that record the product id and the status of every purchase they fire for.
	 *
	 * @param array<int, array<int, mixed>> $calls
	 * @return array<string, callable>
	 */
	private function productHooks(array &$calls): array
	{
		$record = function (string $name) use (&$calls): Closure {
			return function (Purchase $purchase) use (&$calls, $name): void {
				$calls[] = [$name, $purchase->productId, $purchase->status];
			};
		};

		return [
			'uketsuke.payment:after' => $record('payment:after'),
			'uketsuke.payment:repeat' => $record('payment:repeat'),
			'uketsuke.refund:after' => $record('refund:after'),
			'uketsuke.chargeback:after' => $record('chargeback:after'),
			'uketsuke.notification:after' => function (Notification $notification) use (&$calls): void {
				$calls[] = ['notification:after', $notification->event];
			},
		];
	}

	protected function setUp(): void
	{
		$this->site = new SiteFixture();
	}

	/**
	 * Returns product id and status of every stored row of an order in row order, a placeholder included.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private function storedRows(string $orderId): array
	{
		$pdo = new PDO('sqlite:' . $this->site->storage() . '/uketsuke.sqlite');
		$statement = $pdo->prepare('SELECT product_id, status FROM purchases WHERE order_id = :order_id ORDER BY rowid');
		$statement->execute([':order_id' => $orderId]);
		$rows = array_map(
			static fn (array $row): array => [(string)$row['product_id'], (string)$row['status']],
			$statement->fetchAll(PDO::FETCH_ASSOC)
		);
		$pdo = null;

		return $rows;
	}

	protected function tearDown(): void
	{
		$this->site->destroy();
	}

	public function testActiveWithoutPassphraseAnswers503NotConfigured(): void
	{
		$response = $this->post(['active' => true], $this->payment());

		$this->assertSame(503, $response->code());
		$this->assertSame('NOT_CONFIGURED', $response->body());
		$this->assertStringContainsString('NOT_CONFIGURED', SiteFixture::log());
	}

	public function testBadSignatureIsRejectedAndNothingIsStored(): void
	{
		$fields = $this->payment('ORD-1');
		$fields['amount_brutto'] = '0.01';

		$response = $this->post($this->active(), $fields, HookRecorder::hooks($this->calls));

		$this->assertSame(403, $response->code());
		$this->assertSame('INVALID_SIGNATURE', $response->body());
		$this->assertNull($this->ledger()->purchase('ORD-1'));
		$this->assertSame([], $this->notificationRows());
		$this->assertSame([], $this->calls);
		$this->assertStringContainsString('signature rejected for order ORD-1', SiteFixture::log());
	}

	public function testBodyThatIsNotAFormIsRefusedBeforeItIsRead(): void
	{
		$json = $this->site->app($this->active(), [], 'POST', $this->payment(), [], null, 'application/json');
		$this->assertSame(415, $json->call('uketsuke/digistore24', 'POST')->code());

		$_SERVER['CONTENT_LENGTH'] = '1000000';
		$large = $this->site->app($this->active(), [], 'POST', $this->payment());
		$this->assertSame(413, $large->call('uketsuke/digistore24', 'POST')->code());

		$this->assertDirectoryDoesNotExist($this->site->storage());
	}

	public function testChargebackSetsTheStatusAndFiresItsHook(): void
	{
		$this->post($this->active(), $this->payment(), HookRecorder::hooks($this->calls));
		$this->calls = [];

		$this->post($this->active(), $this->payment('ORD-1', 'TX-C', 'on_chargeback'), HookRecorder::hooks($this->calls));

		$this->assertSame('chargeback', $this->ledger()->purchase('ORD-1')->status);
		$this->assertSame([
			['chargeback:after', 'ORD-1', 'chargeback'],
			['notification:after', 'on_chargeback', 'ORD-1'],
		], $this->calls);
	}

	public function testChargebackWithoutProductIdFiresForEveryProduct(): void
	{
		$this->post($this->active(), $this->payment('ORD-1', 'TX-1', productId: '111111'), $this->productHooks($this->calls));
		$this->post($this->active(), $this->payment('ORD-1', 'TX-1', productId: '222222'), $this->productHooks($this->calls));
		$this->calls = [];

		$this->post($this->active(), $this->payment('ORD-1', 'TX-C', 'on_chargeback', ''), $this->productHooks($this->calls));

		$this->assertSame([
			['chargeback:after', '111111', 'chargeback'],
			['chargeback:after', '222222', 'chargeback'],
			['notification:after', 'on_chargeback'],
		], $this->calls);
		$this->assertSame(['chargeback', 'chargeback'], array_map(
			static fn (Purchase $purchase): string => $purchase->status,
			$this->ledger()->order('ORD-1')
		));
	}

	public function testConnectionTestIsAnsweredOkWithOnlyTheNotificationHook(): void
	{
		$response = $this->post($this->active(), SiteFixture::signed(['event' => 'connection_test', 'product_id' => '111111']), HookRecorder::hooks($this->calls));

		$this->assertSame(200, $response->code());
		$this->assertSame([['notification:after', 'connection_test', null]], $this->calls);
		$this->assertNull($this->ledger()->purchase(''));
	}

	public function testFailingHookIsRecordedAndStillAnsweredOk(): void
	{
		$hooks = [
			'uketsuke.payment:after' => function (): void {
				throw new RuntimeException('Erika Muster erika@example.com');
			},
		];

		$response = $this->post($this->active(), $this->payment(), $hooks);

		$this->assertSame(200, $response->code());
		$this->assertSame('RuntimeException', $this->notificationRows()[0]['hook_error']);
		$this->assertSame('paid', $this->ledger()->purchase('ORD-1')->status);
		$this->assertStringContainsString('hook uketsuke.payment:after failed with RuntimeException', SiteFixture::log());
		$this->assertStringNotContainsString('erika@example.com', SiteFixture::log());
	}

	public function testTrackingKeyOfThePaymentIsStoredWithThePurchase(): void
	{
		$response = $this->post($this->active(), $this->payment(extra: ['tracking_param' => 'newsletter-2026-12']), HookRecorder::hooks($this->calls));

		$this->assertSame(200, $response->code());
		$this->assertSame('newsletter-2026-12', $this->ledger()->purchase('ORD-1')->trackingKey);
	}

	public function testPurchaseRetentionDeletesOldOrdersWhenANotificationArrives(): void
	{
		$this->post($this->active(), $this->payment('ORD-OLD', 'TX-0', extra: ['order_date' => '2024-01-10']));
		$this->assertNotNull($this->ledger()->purchase('ORD-OLD'));

		$this->post($this->active(['purchaseRetention' => 396]), $this->payment());

		$this->assertNull($this->ledger()->purchase('ORD-OLD'));
		$this->assertNotNull($this->ledger()->purchase('ORD-1'));
	}

	public function testWithoutPurchaseRetentionOldOrdersAreKept(): void
	{
		$this->post($this->active(), $this->payment('ORD-OLD', 'TX-0', extra: ['order_date' => '2024-01-10']));
		$this->post($this->active(), $this->payment());

		$this->assertNotNull($this->ledger()->purchase('ORD-OLD'));
	}

	public function testProductOutsideTheProductsOptionIsAnsweredOkAndNotStored(): void
	{
		$response = $this->post(
			$this->active(['products' => [222222]]),
			$this->payment(),
			HookRecorder::hooks($this->calls)
		);

		$this->assertSame(200, $response->code());
		$this->assertSame('OK', $response->body());
		$this->assertNull($this->ledger()->purchase('ORD-1'));
		$this->assertSame([], $this->notificationRows());
		$this->assertSame([], $this->calls);
	}

	public function testProductInTheProductsOptionIsStored(): void
	{
		$response = $this->post(
			$this->active(['products' => [222222, '111111']]),
			$this->payment(),
			HookRecorder::hooks($this->calls)
		);

		$this->assertSame('OK', $response->body());
		$this->assertSame('erika@example.com', $this->ledger()->purchase('ORD-1', '111111')->email);
		$this->assertCount(2, $this->calls);
	}

	public function testNotificationWithoutProductIdPassesTheProductsOption(): void
	{
		$this->post($this->active(['products' => ['111111']]), $this->payment());
		$response = $this->post(
			$this->active(['products' => ['111111']]),
			$this->payment(transactionId: 'TX-2', event: 'on_refund', productId: '')
		);

		$this->assertSame('OK', $response->body());
		$this->assertSame('refunded', $this->ledger()->purchase('ORD-1', '111111')->status);
	}

	public function testBadSignatureIsRejectedEvenForAnIgnoredProduct(): void
	{
		$fields = [...$this->payment(), 'sha_sign' => 'wrong'];
		$response = $this->post($this->active(['products' => ['222222']]), $fields);

		$this->assertSame(403, $response->code());
	}

	public function testFirstPaymentCreatesThePurchaseAndFiresTheHookOnce(): void
	{
		$response = $this->post($this->active(), $this->payment(), HookRecorder::hooks($this->calls));

		$this->assertSame(200, $response->code());
		$this->assertSame('OK', $response->body());

		$purchase = $this->ledger()->purchase('ORD-1');
		$this->assertSame('paid', $purchase->status);
		$this->assertSame('erika@example.com', $purchase->email);
		$this->assertSame(19.9, $purchase->amount);
		$this->assertSame('2026-10-29T11:30:00+00:00', $purchase->paidAt->format(DATE_ATOM));

		$this->assertSame([
			['payment:after', 'ORD-1', 'on_payment', false],
			['notification:after', 'on_payment', 'ORD-1'],
		], $this->calls);
	}

	public function testGetAnswersOkWhileActive(): void
	{
		$app = $this->site->app($this->active(), [], 'GET');

		$response = $app->call('uketsuke/digistore24', 'GET');

		$this->assertSame(200, $response->code());
		$this->assertSame('OK', $response->body());
	}

	public function testHooksAreClaimedSoTheNextRepetitionDoesNotFireThemAgain(): void
	{
		$this->post($this->active(), $this->payment(), $this->productHooks($this->calls));
		$this->updateNotifications("announced_at = NULL, received_at = datetime('now', '-10 minutes')");
		$this->calls = [];

		$this->post($this->active(), $this->payment(), $this->productHooks($this->calls));

		$this->assertSame([['payment:after', '111111', 'paid'], ['notification:after', 'on_payment']], $this->calls);
		$this->assertNotNull($this->notificationRows()[0]['attempted_at']);

		// The repetition died inside a hook again: the claim is fresh, the hooks are still open.
		$this->updateNotifications('announced_at = NULL');
		$this->calls = [];

		$this->post($this->active(), $this->payment(), $this->productHooks($this->calls));

		$this->assertSame([], $this->calls);
		$this->assertNull($this->notificationRows()[0]['announced_at']);
	}

	public function testHooksFireAgainWhenTheClaimIsOlderThanTwoMinutes(): void
	{
		$this->post($this->active(), $this->payment(), $this->productHooks($this->calls));
		$this->updateNotifications("announced_at = NULL, received_at = datetime('now', '-10 minutes'), attempted_at = datetime('now', '-3 minutes')");
		$this->calls = [];

		$this->post($this->active(), $this->payment(), $this->productHooks($this->calls));

		$this->assertSame([['payment:after', '111111', 'paid'], ['notification:after', 'on_payment']], $this->calls);
		$this->assertNotNull($this->notificationRows()[0]['announced_at']);
	}

	public function testHooksOfAStalledOrderWideRefundFireForEveryProductAgain(): void
	{
		$this->post($this->active(), $this->payment('ORD-1', 'TX-1', productId: '111111'), $this->productHooks($this->calls));
		$this->post($this->active(), $this->payment('ORD-1', 'TX-1', productId: '222222'), $this->productHooks($this->calls));
		$this->post($this->active(), $this->payment('ORD-1', 'TX-R', 'on_refund', ''), $this->productHooks($this->calls));
		$this->updateNotifications("announced_at = NULL, received_at = datetime('now', '-10 minutes') WHERE event = 'on_refund'");
		$this->calls = [];

		$this->post($this->active(), $this->payment('ORD-1', 'TX-R', 'on_refund', ''), $this->productHooks($this->calls));

		$this->assertSame([
			['refund:after', '111111', 'refunded'],
			['refund:after', '222222', 'refunded'],
			['notification:after', 'on_refund'],
		], $this->calls);
	}

	public function testHooksStillRunningAreNotFiredTwice(): void
	{
		$this->post($this->active(), $this->payment(), HookRecorder::hooks($this->calls));
		$pdo = new PDO('sqlite:' . $this->site->storage() . '/uketsuke.sqlite');
		$pdo->exec('UPDATE notifications SET announced_at = NULL');
		$pdo = null;
		$this->calls = [];

		$this->post($this->active(), $this->payment(), HookRecorder::hooks($this->calls));

		$this->assertSame([], $this->calls);
	}

	public function testHooksThatNeverFinishedFireAgainOnTheNextDelivery(): void
	{
		$this->post($this->active(), $this->payment(), HookRecorder::hooks($this->calls));
		$pdo = new PDO('sqlite:' . $this->site->storage() . '/uketsuke.sqlite');
		$pdo->exec("UPDATE notifications SET announced_at = NULL, received_at = datetime('now', '-10 minutes')");
		$pdo = null;
		$this->calls = [];

		$this->post($this->active(), $this->payment(), HookRecorder::hooks($this->calls));
		$this->post($this->active(), $this->payment(), HookRecorder::hooks($this->calls));

		$this->assertSame([
			['payment:after', 'ORD-1', 'on_payment', false],
			['notification:after', 'on_payment', 'ORD-1'],
		], $this->calls);
		$this->assertNotNull($this->notificationRows()[0]['announced_at']);
		$this->assertStringContainsString('hooks of order ORD-1 never finished', SiteFixture::log());
	}

	public function testInactiveModuleAnswersNotFoundOnEveryRoute(): void
	{
		$app = $this->site->app(['active' => false, 'passphrase' => 'x'], [], 'POST', $this->payment());

		$this->assertNull($app->call('uketsuke/digistore24', 'POST'));
		$this->assertNull($app->call('uketsuke/digistore24', 'GET'));
		$this->assertFalse(is_dir($this->site->storage()));
	}

	public function testInvalidRouteMethodsAreNotHandled(): void
	{
		$app = $this->site->app($this->active(), [], 'PUT');

		$this->assertNull($app->call('uketsuke/digistore24', 'PUT'));
	}

	public function testLatePaymentAfterAChargebackOfOneProductOnlyStaysPaid(): void
	{
		$this->post($this->active(), $this->payment('ORD-9', 'TX-1', productId: '111111'));
		$this->post($this->active(), $this->payment('ORD-9', 'TX-C', 'on_chargeback', '111111'));

		$this->post($this->active(), $this->payment('ORD-9', 'TX-1', productId: '222222'), $this->productHooks($this->calls));

		$this->assertSame([
			['payment:after', '222222', 'paid'],
			['notification:after', 'on_payment'],
		], $this->calls);
		$this->assertSame([['111111', 'chargeback'], ['222222', 'paid']], $this->storedRows('ORD-9'));
	}

	public function testLatePaymentAfterAnOrderWideChargebackIsStoredAsChargeback(): void
	{
		$this->post($this->active(), $this->payment('ORD-9', 'TX-C', 'on_chargeback', ''), $this->productHooks($this->calls));
		$this->calls = [];

		$this->post($this->active(), $this->payment('ORD-9', 'TX-1', productId: '111111'), $this->productHooks($this->calls));

		$this->assertSame([
			['chargeback:after', '111111', 'chargeback'],
			['notification:after', 'on_payment'],
		], $this->calls);
		$this->assertSame('chargeback', $this->ledger()->purchase('ORD-9', '111111')->status);
	}

	public function testLatePaymentAfterAnOrderWideChargebackOfAKnownOrderIsStoredAsChargeback(): void
	{
		$this->post($this->active(), $this->payment('ORD-9', 'TX-1', productId: '111111'), $this->productHooks($this->calls));
		$this->calls = [];

		$this->post($this->active(), $this->payment('ORD-9', 'TX-C', 'on_chargeback', ''), $this->productHooks($this->calls));

		$this->assertSame([
			['chargeback:after', '111111', 'chargeback'],
			['notification:after', 'on_chargeback'],
		], $this->calls);
		$this->assertSame('111111', $this->ledger()->purchase('ORD-9')->productId);
		$this->calls = [];

		$this->post($this->active(), $this->payment('ORD-9', 'TX-1', productId: '222222'), $this->productHooks($this->calls));

		$this->assertSame([
			['chargeback:after', '222222', 'chargeback'],
			['notification:after', 'on_payment'],
		], $this->calls);
		$this->assertSame('chargeback', $this->ledger()->purchase('ORD-9', '222222')->status);
		$this->assertSame('111111', $this->ledger()->purchase('ORD-9')->productId);
		$this->assertSame(['111111', '222222'], array_map(
			static fn (Purchase $purchase): string => $purchase->productId,
			$this->ledger()->order('ORD-9')
		));

		$retry = Retry::order($this->site->app($this->active()), $this->ledger(), 'ORD-9');
		$this->assertSame(['triggered' => [], 'failed' => [], 'next' => null], $retry);
		$this->calls = [];

		$this->post($this->active(), $this->payment('ORD-9', 'TX-2', productId: '222222'), $this->productHooks($this->calls));

		$this->assertSame([
			['payment:repeat', '222222', 'chargeback'],
			['notification:after', 'on_payment'],
		], $this->calls);
		$this->assertSame(
			[['111111', 'chargeback'], ['', 'chargeback'], ['222222', 'chargeback']],
			$this->storedRows('ORD-9')
		);
	}

	public function testLatePaymentAfterAnOrderWideRefundIsStoredAsRefunded(): void
	{
		$this->post($this->active(), $this->payment('ORD-9', 'TX-R', 'on_refund', ''), $this->productHooks($this->calls));
		$this->calls = [];

		$this->post($this->active(), $this->payment('ORD-9', 'TX-1', productId: '111111'), $this->productHooks($this->calls));

		$this->assertSame([
			['refund:after', '111111', 'refunded'],
			['notification:after', 'on_payment'],
		], $this->calls);
		$purchase = $this->ledger()->purchase('ORD-9', '111111');
		$this->assertSame('refunded', $purchase->status);
		$this->assertNotNull($purchase->refundedAt);
		$this->calls = [];

		$this->post($this->active(), $this->payment('ORD-9', 'TX-1', productId: '222222'), $this->productHooks($this->calls));

		$this->assertSame([
			['refund:after', '222222', 'refunded'],
			['notification:after', 'on_payment'],
		], $this->calls);
		$this->assertSame('refunded', $this->ledger()->purchase('ORD-9', '222222')->status);

		$retry = Retry::order($this->site->app($this->active()), $this->ledger(), 'ORD-9');
		$this->assertSame(['triggered' => [], 'failed' => [], 'next' => null], $retry);
	}

	public function testLatePaymentAfterAnOrderWideRefundOfAKnownOrderIsStoredAsRefunded(): void
	{
		$this->post($this->active(), $this->payment('ORD-9', 'TX-1', productId: '111111'), $this->productHooks($this->calls));
		$this->calls = [];

		$this->post($this->active(), $this->payment('ORD-9', 'TX-R', 'on_refund', ''), $this->productHooks($this->calls));

		$this->assertSame([
			['refund:after', '111111', 'refunded'],
			['notification:after', 'on_refund'],
		], $this->calls);
		$this->assertSame('111111', $this->ledger()->purchase('ORD-9')->productId);
		$this->calls = [];

		$this->post($this->active(), $this->payment('ORD-9', 'TX-1', productId: '222222'), $this->productHooks($this->calls));

		$this->assertSame([
			['refund:after', '222222', 'refunded'],
			['notification:after', 'on_payment'],
		], $this->calls);
		$purchase = $this->ledger()->purchase('ORD-9', '222222');
		$this->assertSame('refunded', $purchase->status);
		$this->assertNotNull($purchase->refundedAt);
		$this->assertSame('111111', $this->ledger()->purchase('ORD-9')->productId);
		$this->assertSame(['111111', '222222'], array_map(
			static fn (Purchase $purchase): string => $purchase->productId,
			$this->ledger()->order('ORD-9')
		));

		$retry = Retry::order($this->site->app($this->active()), $this->ledger(), 'ORD-9');
		$this->assertSame(['triggered' => [], 'failed' => [], 'next' => null], $retry);
		$this->calls = [];

		$this->post($this->active(), $this->payment('ORD-9', 'TX-2', productId: '222222'), $this->productHooks($this->calls));

		$this->assertSame([
			['payment:repeat', '222222', 'refunded'],
			['notification:after', 'on_payment'],
		], $this->calls);
		$this->assertSame(
			[['111111', 'refunded'], ['', 'refunded'], ['222222', 'refunded']],
			$this->storedRows('ORD-9')
		);
	}

	public function testLatePaymentAfterARefundAndAChargebackOfAKnownOrderIsStoredAsChargeback(): void
	{
		$this->post($this->active(), $this->payment('ORD-9', 'TX-1', productId: '111111'));
		$this->post($this->active(), $this->payment('ORD-9', 'TX-R', 'on_refund', ''));
		$this->post($this->active(), $this->payment('ORD-9', 'TX-C', 'on_chargeback', ''));

		$this->post($this->active(), $this->payment('ORD-9', 'TX-1', productId: '222222'), $this->productHooks($this->calls));

		$this->assertSame([
			['chargeback:after', '222222', 'chargeback'],
			['notification:after', 'on_payment'],
		], $this->calls);
		$this->assertSame(
			[['111111', 'chargeback'], ['', 'chargeback'], ['222222', 'chargeback']],
			$this->storedRows('ORD-9')
		);
	}

	public function testLatePaymentAfterARefundOfOneProductOnlyStaysPaid(): void
	{
		$this->post($this->active(), $this->payment('ORD-9', 'TX-1', productId: '111111'));
		$this->post($this->active(), $this->payment('ORD-9', 'TX-R', 'on_refund', '111111'));

		$this->post($this->active(), $this->payment('ORD-9', 'TX-1', productId: '222222'), $this->productHooks($this->calls));

		$this->assertSame([
			['payment:after', '222222', 'paid'],
			['notification:after', 'on_payment'],
		], $this->calls);
		$this->assertSame([['111111', 'refunded'], ['222222', 'paid']], $this->storedRows('ORD-9'));
	}

	public function testLatePaymentForAProductOfASettledOrderFiresTheRepeatHook(): void
	{
		$this->post($this->active(), $this->payment('ORD-9', 'TX-R', 'on_refund', ''), $this->productHooks($this->calls));
		$this->post($this->active(), $this->payment('ORD-9', 'TX-1', productId: '111111'), $this->productHooks($this->calls));
		$this->calls = [];

		$this->post($this->active(), $this->payment('ORD-9', 'TX-2', productId: '111111'), $this->productHooks($this->calls));

		$this->assertSame([
			['payment:repeat', '111111', 'refunded'],
			['notification:after', 'on_payment'],
		], $this->calls);
	}

	public function testLatePaymentForARefundedProductFiresTheRepeatHook(): void
	{
		$this->post($this->active(), $this->payment('ORD-1', 'TX-1'), $this->productHooks($this->calls));
		$this->post($this->active(), $this->payment('ORD-1', 'TX-R', 'on_refund', '111111'), $this->productHooks($this->calls));
		$this->calls = [];

		$this->post($this->active(), $this->payment('ORD-1', 'TX-2'), $this->productHooks($this->calls));

		$this->assertSame([
			['payment:repeat', '111111', 'refunded'],
			['notification:after', 'on_payment'],
		], $this->calls);
		$this->assertSame('refunded', $this->ledger()->purchase('ORD-1', '111111')->status);
	}

	public function testNotificationStoresOnlyAllowListedFields(): void
	{
		$this->post($this->active(), $this->payment(), HookRecorder::hooks($this->calls));

		$rows = $this->notificationRows();
		$this->assertCount(1, $rows);
		$this->assertSame('on_payment', $rows[0]['event']);
		$this->assertSame(0, (int)$rows[0]['duplicate']);
		$this->assertStringNotContainsString('erika@example.com', $rows[0]['payload']);
		$this->assertStringNotContainsString('Erika', $rows[0]['payload']);
		$this->assertStringNotContainsString('erika@example.com', (string)file_get_contents($this->site->storage() . '/uketsuke.sqlite'));
		$this->assertStringNotContainsString('erika@example.com', SiteFixture::log());
	}

	public function testOrderIdAloneFindsTheFirstProductWhenTheOrderWideRefundCameFirst(): void
	{
		$this->post($this->active(), $this->payment('ORD-9', 'TX-R', 'on_refund', ''), $this->productHooks($this->calls));
		$this->post($this->active(), $this->payment('ORD-9', 'TX-1', productId: '111111'), $this->productHooks($this->calls));
		$this->post($this->active(), $this->payment('ORD-9', 'TX-2', productId: '222222'), $this->productHooks($this->calls));

		$this->assertSame([['', 'refunded'], ['111111', 'refunded'], ['222222', 'refunded']], $this->storedRows('ORD-9'));
		$this->assertSame('111111', $this->ledger()->purchase('ORD-9')->productId);

		$refund = new Notification('on_refund', 'ORD-9', 'TX-R', '', new DateTimeImmutable('2026-10-29 12:00:00'), [], false);
		$booking = new Booking($this->ledger());
		$this->assertSame('111111', $booking->purchase($refund)?->productId);
		$this->assertSame(['111111', '222222'], array_map(
			static fn (Purchase $purchase): string => $purchase->productId,
			$booking->purchases($refund)
		));
	}

	public function testOrderWideRefundFiresOnlyForTheProductsThatChanged(): void
	{
		$this->post($this->active(), $this->payment('ORD-1', 'TX-1', productId: '111111'));
		$this->post($this->active(), $this->payment('ORD-1', 'TX-1', productId: '222222'));
		$this->post($this->active(), $this->payment('ORD-1', 'TX-R', 'on_refund', '111111'));

		$this->post($this->active(), $this->payment('ORD-1', 'TX-R2', 'on_refund', ''), $this->productHooks($this->calls));

		$this->assertSame([
			['refund:after', '222222', 'refunded'],
			['notification:after', 'on_refund'],
		], $this->calls);
	}

	public function testOrderWideRefundFiresTheHookForEveryProduct(): void
	{
		$this->post($this->active(), $this->payment('ORD-1', 'TX-1', productId: '111111'), $this->productHooks($this->calls));
		$this->post($this->active(), $this->payment('ORD-1', 'TX-1', productId: '222222'), $this->productHooks($this->calls));
		$this->calls = [];

		$response = $this->post($this->active(), $this->payment('ORD-1', 'TX-R', 'on_refund', ''), $this->productHooks($this->calls));

		$this->assertSame('OK', $response->body());
		$this->assertSame([
			['refund:after', '111111', 'refunded'],
			['refund:after', '222222', 'refunded'],
			['notification:after', 'on_refund'],
		], $this->calls);

		foreach ($this->ledger()->order('ORD-1') as $purchase) {
			$this->assertSame('refunded', $purchase->status);
			$this->assertNotNull($purchase->refundedAt);
		}
	}

	public function testOrderWideRefundGivesTheNotificationHookTheFirstChangedPurchase(): void
	{
		$calls = &$this->calls;
		$hooks = [
			'uketsuke.notification:after' => function (Notification $notification, Purchase|null $purchase = null) use (&$calls): void {
				$calls[] = [$notification->event, $purchase?->productId];
			},
		];
		$this->post($this->active(), $this->payment('ORD-1', 'TX-1', productId: '111111'));
		$this->post($this->active(), $this->payment('ORD-1', 'TX-1', productId: '222222'));
		$this->post($this->active(), $this->payment('ORD-1', 'TX-R', 'on_refund', '111111'));

		$this->post($this->active(), $this->payment('ORD-1', 'TX-R2', 'on_refund', ''), $hooks);

		$this->assertSame([['on_refund', '222222']], $this->calls);
	}

	public function testOrderWideRefundThatChangesNothingFiresOnlyTheNotificationHook(): void
	{
		$this->post($this->active(), $this->payment('ORD-1', 'TX-1', productId: '111111'));
		$this->post($this->active(), $this->payment('ORD-1', 'TX-1', productId: '222222'));
		$this->post($this->active(), $this->payment('ORD-1', 'TX-R', 'on_refund', ''));

		$this->post($this->active(), $this->payment('ORD-1', 'TX-R2', 'on_refund', ''), HookRecorder::hooks($this->calls));

		$this->assertSame([['notification:after', 'on_refund', 'ORD-1']], $this->calls);
	}

	public function testPaymentWithoutProductIdDoesNotSettleTheOrder(): void
	{
		$this->post($this->active(), $this->payment('ORD-1', 'TX-1', productId: ''), $this->productHooks($this->calls));
		$this->calls = [];

		$this->post($this->active(), $this->payment('ORD-1', 'TX-1', productId: '222222'), $this->productHooks($this->calls));

		$this->assertSame([
			['payment:after', '222222', 'paid'],
			['notification:after', 'on_payment'],
		], $this->calls);
		$this->assertSame(['paid', 'paid'], array_map(
			static fn (Purchase $purchase): string => $purchase->status,
			$this->ledger()->order('ORD-1')
		));
	}

	public function testProductSpecificRefundsFireOneHookEach(): void
	{
		$this->post($this->active(), $this->payment('ORD-1', 'TX-1', productId: '111111'));
		$this->post($this->active(), $this->payment('ORD-1', 'TX-1', productId: '222222'));

		$this->post($this->active(), $this->payment('ORD-1', 'TX-R1', 'on_refund', '111111'), $this->productHooks($this->calls));
		$this->post($this->active(), $this->payment('ORD-1', 'TX-R2', 'on_refund', '222222'), $this->productHooks($this->calls));

		$this->assertSame([
			['refund:after', '111111', 'refunded'],
			['notification:after', 'on_refund'],
			['refund:after', '222222', 'refunded'],
			['notification:after', 'on_refund'],
		], $this->calls);
	}

	public function testQueryStringIsNotPartOfTheNotification(): void
	{
		$fields = $this->payment();
		$app = $this->site->app($this->active(), HookRecorder::hooks($this->calls), 'POST', $fields, ['order_id' => 'ORD-EVIL']);

		$response = $app->call('uketsuke/digistore24', 'POST');

		$this->assertSame('OK', $response->body());
		$this->assertNotNull($this->ledger()->purchase('ORD-1'));
		$this->assertNull($this->ledger()->purchase('ORD-EVIL'));
	}

	public function testRefundForAnUnknownOrderCreatesThePurchase(): void
	{
		$this->post($this->active(), $this->payment('ORD-9', 'TX-R', 'on_refund'), HookRecorder::hooks($this->calls));

		$this->assertSame('refunded', $this->ledger()->purchase('ORD-9')->status);
	}

	public function testRefundSetsTheStatusAndFiresTheRefundHook(): void
	{
		$this->post($this->active(), $this->payment(), HookRecorder::hooks($this->calls));
		$this->calls = [];

		$response = $this->post($this->active(), $this->payment('ORD-1', 'TX-R', 'on_refund'), HookRecorder::hooks($this->calls));

		$this->assertSame(200, $response->code());
		$purchase = $this->ledger()->purchase('ORD-1');
		$this->assertSame('refunded', $purchase->status);
		$this->assertNotNull($purchase->refundedAt);
		$this->assertSame([
			['refund:after', 'ORD-1', 'refunded'],
			['notification:after', 'on_refund', 'ORD-1'],
		], $this->calls);
	}

	public function testRepeatedNotificationIsAnsweredOkWithoutHooks(): void
	{
		$this->post($this->active(), $this->payment(), HookRecorder::hooks($this->calls));
		$this->calls = [];

		$response = $this->post($this->active(), $this->payment(), HookRecorder::hooks($this->calls));

		$this->assertSame(200, $response->code());
		$this->assertSame([], $this->calls);
		$rows = $this->notificationRows();
		$this->assertCount(2, $rows);
		$this->assertSame(1, (int)$rows[1]['duplicate']);
	}

	public function testReplayedRefundAfterThePurgeDoesNotFireTheHookAgain(): void
	{
		$this->post($this->active(), $this->payment(), HookRecorder::hooks($this->calls));
		$this->post($this->active(), $this->payment('ORD-1', 'TX-R', 'on_refund'), HookRecorder::hooks($this->calls));
		$this->ledger()->notifications()->purge(new DateTimeImmutable('+1 day'));
		$this->calls = [];

		$this->post($this->active(), $this->payment('ORD-1', 'TX-R', 'on_refund'), HookRecorder::hooks($this->calls));

		$this->assertSame([['notification:after', 'on_refund', 'ORD-1']], $this->calls);
	}

	public function testSecondPaymentOfTheOrderFiresTheRepeatHook(): void
	{
		$this->post($this->active(), $this->payment('ORD-1', 'TX-1'), HookRecorder::hooks($this->calls));
		$this->calls = [];

		$response = $this->post($this->active(), $this->payment('ORD-1', 'TX-2'), HookRecorder::hooks($this->calls));

		$this->assertSame(200, $response->code());
		$this->assertSame([
			['payment:repeat', 'ORD-1'],
			['notification:after', 'on_payment', 'ORD-1'],
		], $this->calls);
		$this->assertSame('paid', $this->ledger()->purchase('ORD-1')->status);
	}

	public function testSecondProductOfAnOrderIsItsOwnPurchase(): void
	{
		$this->post($this->active(), $this->payment('ORD-1', 'TX-1', productId: '111111'), HookRecorder::hooks($this->calls));
		$this->post($this->active(), $this->payment('ORD-1', 'TX-1', productId: '222222'), HookRecorder::hooks($this->calls));
		$this->post($this->active(), $this->payment('ORD-1', 'TX-R', 'on_refund', '222222'), HookRecorder::hooks($this->calls));

		$this->assertSame([
			['payment:after', 'ORD-1', 'on_payment', false],
			['notification:after', 'on_payment', 'ORD-1'],
			['payment:after', 'ORD-1', 'on_payment', false],
			['notification:after', 'on_payment', 'ORD-1'],
			['refund:after', 'ORD-1', 'refunded'],
			['notification:after', 'on_refund', 'ORD-1'],
		], $this->calls);
		$this->assertSame(['paid', 'refunded'], array_map(
			static fn (Purchase $purchase): string => $purchase->status,
			$this->ledger()->order('ORD-1')
		));
	}

	public function testSilentAndUnknownEventsAreAnsweredOk(): void
	{
		$silent = $this->post($this->active(), SiteFixture::signed(['event' => 'on_affiliation', 'affiliate_id' => '7']), HookRecorder::hooks($this->calls));
		$missed = $this->post($this->active(), SiteFixture::signed(['event' => 'on_payment_missed', 'order_id' => 'ORD-3']), HookRecorder::hooks($this->calls));
		$unknown = $this->post($this->active(), SiteFixture::signed(['event' => 'something_new']), HookRecorder::hooks($this->calls));

		$this->assertSame(['OK', 'OK', 'OK'], [$silent->body(), $missed->body(), $unknown->body()]);
		$this->assertSame([['notification:after', 'something_new', null]], $this->calls);
		$this->assertNull($this->ledger()->purchase('ORD-3'));
		$this->assertCount(3, $this->notificationRows());
	}

	public function testStorageConfigurationErrorsAreLoggedWithTheirReason(): void
	{
		$response = $this->post($this->active(['storage' => $this->site->root . '/public/uketsuke']), $this->payment());

		$this->assertSame(503, $response->code());
		$this->assertStringContainsString('lies inside the web root', SiteFixture::log());
	}

	public function testStorageFailureAnswers503StorageErrorSoDigistoreRetries(): void
	{
		file_put_contents($this->site->root . '/storage/blocked', 'a file, not a directory');

		$response = $this->post($this->active(['storage' => $this->site->root . '/storage/blocked/uketsuke']), $this->payment());

		$this->assertSame(503, $response->code());
		$this->assertSame('STORAGE_ERROR', $response->body());
		$this->assertStringContainsString('STORAGE_ERROR', SiteFixture::log());
	}

	public function testSymbolicLinkAsLedgerFileAnswers503AndLeavesTheTargetAlone(): void
	{
		$elsewhere = sys_get_temp_dir() . '/uketsuke-elsewhere-' . bin2hex(random_bytes(8));
		mkdir($elsewhere, 0700, true);
		file_put_contents($elsewhere . '/target', 'untouched');
		mkdir($this->site->storage(), 0700, true);
		symlink($elsewhere . '/target', $this->site->storage() . '/uketsuke.sqlite');

		try {
			$response = $this->post($this->active(), $this->payment(), HookRecorder::hooks($this->calls));

			$this->assertSame(503, $response->code());
			$this->assertSame('STORAGE_ERROR', $response->body());
			$this->assertSame('untouched', file_get_contents($elsewhere . '/target'));
			$this->assertSame([], $this->calls);
			$this->assertStringContainsString('uketsuke.sqlite is a symbolic link', SiteFixture::log());
		} finally {
			unlink($this->site->storage() . '/uketsuke.sqlite');
			Dir::remove($elsewhere);
		}
	}

	/**
	 * Changes the notification rows directly, as a request that died inside a hook would leave them.
	 */
	private function updateNotifications(string $set): void
	{
		$pdo = new PDO('sqlite:' . $this->site->storage() . '/uketsuke.sqlite');
		$pdo->exec('UPDATE notifications SET ' . $set);
		$pdo = null;
	}
}
