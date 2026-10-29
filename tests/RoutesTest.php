<?php

use Kirby\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Uketsuke\Auth;
use Sayamaapps\Uketsuke\Ledger;
use Sayamaapps\Uketsuke\Payload;
use Sayamaapps\Uketsuke\Protocol;
use Sayamaapps\Uketsuke\Retry;
use Sayamaapps\Uketsuke\Routes;

/**
 * The protocol and retry routes, and the token check in front of them.
 */
#[CoversClass(Routes::class)]
#[CoversClass(Auth::class)]
#[CoversClass(Protocol::class)]
#[CoversClass(Retry::class)]
#[RunTestsInSeparateProcesses]
final class RoutesTest extends TestCase
{
	/** @var array<int, array<int, mixed>> */
	private array $calls = [];
	private SiteFixture $site;

	private function bearer(): string
	{
		return 'Bearer ' . SiteFixture::TOKEN;
	}

	private function get(array $module, array $query = [], string|null $authorization = null): Response|null
	{
		return $this->site->app($module, [], 'GET', [], $query, $authorization)->call('uketsuke/digistore24/protokoll', 'GET');
	}

	private function ledger(): Ledger
	{
		return new Ledger($this->site->storage());
	}

	private function module(array $extra = []): array
	{
		return [...['active' => true, 'passphrase' => SiteFixture::PASSPHRASE, 'token' => SiteFixture::TOKEN], ...$extra];
	}

	private function post(array $module, string $path, array $body = [], string|null $authorization = null): Response|null
	{
		return $this->site->app($module, HookRecorder::hooks($this->calls), 'POST', $body, [], $authorization)->call($path, 'POST');
	}

	/**
	 * Stores a paid purchase through the same mapping the receiver uses.
	 */
	private function seed(string $orderId, string $paidAt, string $email = 'erika@example.com'): void
	{
		$payload = new Payload([
			'order_id' => $orderId,
			'product_id' => '111111',
			'product_name' => 'Kurs Beispiel',
			'email' => $email,
			'address_first_name' => 'Erika',
			'address_last_name' => 'Muster',
			'order_date_time' => $paidAt,
		]);
		$this->ledger()->insertPurchase($payload->purchaseRow(new DateTimeImmutable('now', new DateTimeZone('UTC')), 'paid', null));
	}

	protected function setUp(): void
	{
		$this->site = new SiteFixture();
	}

	protected function tearDown(): void
	{
		$this->site->destroy();
	}

	public function testInactiveModuleHidesTheProtocolAndTheRetryRoute(): void
	{
		$this->seed('ORD-1', '2026-10-10 10:00:00');

		$this->assertNull($this->get($this->module(['active' => false]), [], $this->bearer()));
		$this->assertNull($this->post($this->module(['active' => false]), 'uketsuke/digistore24/wiederholen', ['order_id' => 'ORD-1'], $this->bearer()));
	}

	public function testMalformedDatesAreBadRequests(): void
	{
		$this->assertSame(400, $this->get($this->module(), ['since' => '2026-02-30'], $this->bearer())->code());
		$this->assertSame(400, $this->get($this->module(), ['since' => 'yesterday'], $this->bearer())->code());
		$this->assertSame(400, $this->get($this->module(), ['until' => '31.10.2026'], $this->bearer())->code());
	}

	public function testProtocolDefaultsToTheLastThirtyDays(): void
	{
		$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
		$this->seed('ORD-NEW', $now->format('Y-m-d H:i:s'));
		$this->seed('ORD-OLD', $now->modify('-40 days')->format('Y-m-d H:i:s'));

		$data = json_decode((string)$this->get($this->module(), [], $this->bearer())->body(), true);

		$this->assertSame(['ORD-NEW'], array_column($data['purchases'], 'order_id'));
	}

	public function testProtocolFiltersByDateAndTreatsUntilAsInclusive(): void
	{
		$this->seed('ORD-A', '2026-10-10 10:00:00');
		$this->seed('ORD-B', '2026-10-20 10:00:00');

		$since = json_decode((string)$this->get($this->module(), ['since' => '2026-10-15'], $this->bearer())->body(), true);
		$until = json_decode((string)$this->get($this->module(), ['since' => '2026-10-01', 'until' => '2026-10-10'], $this->bearer())->body(), true);

		$this->assertSame(['ORD-B'], array_column($since['purchases'], 'order_id'));
		$this->assertSame(['ORD-A'], array_column($until['purchases'], 'order_id'));
	}

	public function testProtocolIsNotFoundWithoutTokenOption(): void
	{
		$this->seed('ORD-1', '2026-10-10 10:00:00');

		$this->assertNull($this->get($this->module(['token' => null]), [], $this->bearer()));
	}

	public function testProtocolListsPurchasesWithoutBuyerData(): void
	{
		$this->seed('ORD-1', '2026-10-10 10:00:00');
		$this->ledger()->recordDelivery('ORD-1', 'mail', 'failed', 'Recipient erika@example.com rejected');

		$response = $this->get($this->module(), ['since' => '2026-10-01', 'until' => '2026-10-31'], $this->bearer());

		$this->assertSame(200, $response->code());
		$this->assertSame('application/json', $response->type());
		$body = $response->body();
		$data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
		$this->assertSame('2026-10-01', $data['since']);
		$this->assertSame('2026-10-31', $data['until']);
		$this->assertCount(1, $data['purchases']);
		$purchase = $data['purchases'][0];
		$this->assertSame(['order_id', 'product_id', 'product_name', 'status', 'paid_at', 'test', 'source', 'deliveries'], array_keys($purchase));
		$this->assertSame('mail', $purchase['deliveries'][0]['channel']);
		$this->assertSame('Recipient [address] rejected', $purchase['deliveries'][0]['error']);
		$this->assertStringNotContainsString('erika', $body);
		$this->assertStringNotContainsString('Muster', $body);
	}

	public function testRetryByChannelAndStatusRetriesEveryPaidOrderOfThatDelivery(): void
	{
		$this->seed('ORD-1', '2026-10-10 10:00:00');
		$this->seed('ORD-2', '2026-10-11 10:00:00');
		$this->seed('ORD-3', '2026-10-12 10:00:00');
		$this->ledger()->recordDelivery('ORD-1', 'mail', 'failed');
		$this->ledger()->recordDelivery('ORD-2', 'mail', 'failed');
		$this->ledger()->recordDelivery('ORD-3', 'mail', 'sent');

		$response = $this->post($this->module(), 'uketsuke/digistore24/wiederholen', ['channel' => 'mail', 'status' => 'failed'], $this->bearer());

		$this->assertSame(['triggered' => ['ORD-1', 'ORD-2']], json_decode($response->body(), true));
		$this->assertSame([['payment:after', 'ORD-1', 'retry', true], ['payment:after', 'ORD-2', 'retry', true]], $this->calls);
	}

	public function testRetryByOrderFiresThePaymentHookAgainForPaidOrders(): void
	{
		$this->seed('ORD-1', '2026-10-10 10:00:00');

		$response = $this->post($this->module(), 'uketsuke/digistore24/wiederholen', ['order_id' => 'ORD-1'], $this->bearer());

		$this->assertSame(200, $response->code());
		$this->assertSame(['triggered' => ['ORD-1']], json_decode($response->body(), true));
		$this->assertSame([['payment:after', 'ORD-1', 'retry', true]], $this->calls);
	}

	public function testRetryIgnoresUnpaidOrdersAndUnknownOnes(): void
	{
		$this->seed('ORD-1', '2026-10-10 10:00:00');
		$this->ledger()->setStatus('ORD-1', 'refunded', new DateTimeImmutable('now', new DateTimeZone('UTC')));

		$refunded = json_decode((string)$this->post($this->module(), 'uketsuke/digistore24/wiederholen', ['order_id' => 'ORD-1'], $this->bearer())->body(), true);
		$unknown = json_decode((string)$this->post($this->module(), 'uketsuke/digistore24/wiederholen', ['order_id' => 'ORD-404'], $this->bearer())->body(), true);

		$this->assertSame(['triggered' => []], $refunded);
		$this->assertSame(['triggered' => []], $unknown);
		$this->assertSame([], $this->calls);
	}

	public function testRetryIsNotFoundWithoutTokenOption(): void
	{
		$this->assertNull($this->post($this->module(['token' => null]), 'uketsuke/digistore24/wiederholen', ['order_id' => 'ORD-1'], $this->bearer()));
	}

	public function testRetryNeedsAnOrderOrAChannelAndTheToken(): void
	{
		$this->assertSame(400, $this->post($this->module(), 'uketsuke/digistore24/wiederholen', [], $this->bearer())->code());
		$this->assertSame(401, $this->post($this->module(), 'uketsuke/digistore24/wiederholen', ['order_id' => 'ORD-1'])->code());
	}

	public function testRetryUsesTheOriginalPaymentNotification(): void
	{
		$payload = ['event' => 'on_payment', 'order_id' => 'ORD-1', 'transaction_id' => 'TX-1', 'product_id' => '111111', 'api_mode' => 'live'];
		$this->ledger()->addNotification(new Sayamaapps\Uketsuke\Notification('on_payment', 'ORD-1', 'TX-1', '111111', new DateTimeImmutable('now', new DateTimeZone('UTC')), $payload, false));
		$this->seed('ORD-1', '2026-10-10 10:00:00');

		$this->post($this->module(), 'uketsuke/digistore24/wiederholen', ['order_id' => 'ORD-1'], $this->bearer());

		$this->assertSame([['payment:after', 'ORD-1', 'on_payment', true]], $this->calls);
	}

	public function testTheBearerSchemeIsMatchedExactly(): void
	{
		$this->assertSame(401, $this->get($this->module(), [], 'bearer ' . SiteFixture::TOKEN)->code());
		$this->assertSame(200, $this->get($this->module(), [], $this->bearer())->code());
	}

	public function testWrongOrMissingTokenIsUnauthorized(): void
	{
		$wrong = $this->get($this->module(), [], 'Bearer nope');
		$missing = $this->get($this->module(), []);
		$basic = $this->get($this->module(), [], 'Basic ' . base64_encode('a:b'));

		$this->assertSame([401, 401, 401], [$wrong->code(), $missing->code(), $basic->code()]);
		$this->assertSame('UNAUTHORIZED', $wrong->body());
	}
}
