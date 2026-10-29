<?php

use Kirby\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Uketsuke\Ledger;
use Sayamaapps\Uketsuke\Payload;
use Sayamaapps\Uketsuke\Purchase;
use Sayamaapps\Uketsuke\Receiver;
use Sayamaapps\Uketsuke\Routes;

/**
 * The IPN flow through the real route: signature, notification row, purchase, hooks, answer.
 */
#[CoversClass(Receiver::class)]
#[CoversClass(Routes::class)]
#[CoversClass(Payload::class)]
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

	private function payment(string $orderId = 'ORD-1', string $transactionId = 'TX-1', string $event = 'on_payment'): array
	{
		return SiteFixture::signed([
			'event' => $event,
			'order_id' => $orderId,
			'transaction_id' => $transactionId,
			'product_id' => '111111',
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
		]);
	}

	private function post(array $module, array $fields, array $hooks = []): Response|null
	{
		$app = $this->site->app($module, $hooks, 'POST', $fields);

		return $app->call('uketsuke/digistore24', 'POST');
	}

	protected function setUp(): void
	{
		$this->site = new SiteFixture();
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
		$this->assertSame(Routes::class, Routes::class);
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

	public function testStorageFailureAnswers503StorageErrorSoDigistoreRetries(): void
	{
		file_put_contents($this->site->root . '/storage/blocked', 'a file, not a directory');

		$response = $this->post($this->active(['storage' => $this->site->root . '/storage/blocked/uketsuke']), $this->payment());

		$this->assertSame(503, $response->code());
		$this->assertSame('STORAGE_ERROR', $response->body());
		$this->assertStringContainsString('STORAGE_ERROR', SiteFixture::log());
	}
}
