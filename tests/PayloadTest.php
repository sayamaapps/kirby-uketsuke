<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Uketsuke\Payload;

#[CoversClass(Payload::class)]
final class PayloadTest extends TestCase
{
	private static function now(): DateTimeImmutable
	{
		return new DateTimeImmutable('2026-10-29 08:00:00', new DateTimeZone('UTC'));
	}

	public function testFieldFallbacks(): void
	{
		$row = (new Payload([
			'order_id' => 'ORD-1',
			'product_id' => '111111',
			'address_first_name' => 'Erika',
			'last_name' => 'Muster',
			'country' => 'DE',
			'amount_brutto' => '19.90',
			'amount' => '1.00',
			'transaction_currency' => 'EUR',
			'affiliate_name' => 'partner',
		]))->purchaseRow(self::now(), 'paid', null);

		$this->assertSame('Erika', $row['first_name']);
		$this->assertSame('Muster', $row['last_name']);
		$this->assertSame('DE', $row['country']);
		$this->assertSame(19.9, $row['amount']);
		$this->assertSame('EUR', $row['currency']);
		$this->assertSame('partner', $row['affiliate']);
		$this->assertNull($row['product_name_intern']);
	}

	public function testIdentifiersAreReadAndDefaultToEmpty(): void
	{
		$payload = new Payload(['event' => 'on_payment', 'order_id' => 'ORD-1', 'transaction_id' => 'TX-9', 'product_id' => '111111']);

		$this->assertSame('on_payment', $payload->event());
		$this->assertSame('ORD-1', $payload->orderId());
		$this->assertSame('TX-9', $payload->transactionId());
		$this->assertSame('111111', $payload->productId());

		$empty = new Payload([]);
		$this->assertSame('', $empty->event());
		$this->assertSame('', $empty->orderId());
	}

	public function testNotificationPayloadIsAnAllowList(): void
	{
		$stored = (new Payload([
			'event' => 'on_payment',
			'order_id' => 'ORD-1',
			'transaction_id' => 'TX-9',
			'product_id' => '111111',
			'product_name' => 'Gasthaus Muster Kurs',
			'api_mode' => 'test',
			'billing_status' => 'paid',
			'quantity' => '2',
			'email' => 'erika@example.com',
			'address_first_name' => 'Erika',
			'address_last_name' => 'Muster',
			'address_street' => 'Musterweg 1',
			'custom' => 'secret-note',
			'ipn_version' => '1.1',
		]))->notificationPayload();

		$this->assertSame(
			['event', 'order_id', 'transaction_id', 'product_id', 'product_name', 'api_mode', 'billing_status', 'quantity', 'ipn_version'],
			array_keys($stored)
		);
		$this->assertStringNotContainsString('erika@example.com', json_encode($stored, JSON_THROW_ON_ERROR));
	}

	public function testOrderDateAndTimeAreBerlinTimeStoredAsUtc(): void
	{
		// 29 Oct 2026 is after the end of summer time: Berlin is UTC+1 that day.
		$row = (new Payload(['order_id' => 'ORD-1', 'order_date' => '2026-10-29', 'order_time' => '12:30:00']))
			->purchaseRow(self::now(), 'paid', null);

		$this->assertSame('2026-10-29 11:30:00', $row['paid_at']);
	}

	public function testOrderDateTimeIsTheFallbackAndBadDatesFallBackToNow(): void
	{
		$fallback = (new Payload(['order_id' => 'ORD-1', 'order_date_time' => '2026-06-01 10:00:00']))
			->purchaseRow(self::now(), 'paid', null);
		$this->assertSame('2026-06-01 08:00:00', $fallback['paid_at']);

		$broken = (new Payload(['order_id' => 'ORD-1', 'order_date' => 'not a date']))
			->purchaseRow(self::now(), 'paid', null);
		$this->assertSame('2026-10-29 08:00:00', $broken['paid_at']);
	}

	public function testRefundStatusAndDate(): void
	{
		$row = (new Payload(['order_id' => 'ORD-1']))->purchaseRow(self::now(), 'refunded', self::now());

		$this->assertSame('refunded', $row['status']);
		$this->assertSame('2026-10-29 08:00:00', $row['refunded_at']);
		$this->assertSame('ipn', $row['source']);
	}

	public function testTestModeFollowsApiModeAndPayMethod(): void
	{
		$this->assertFalse((new Payload(['api_mode' => 'live']))->isTest());
		$this->assertTrue((new Payload(['api_mode' => 'test']))->isTest());
		$this->assertTrue((new Payload(['api_mode' => 'Sandbox']))->isTest());
		$this->assertFalse((new Payload([]))->isTest());
		$this->assertTrue((new Payload(['api_mode' => 'live', 'pay_method' => 'TEST']))->isTest());
		$this->assertTrue((new Payload(['transaction_pay_method' => 'test']))->isTest());
	}

	public function testTransactionAmountWinsOverTheOlderNames(): void
	{
		$row = (new Payload(['order_id' => 'ORD-1', 'transaction_amount' => '29.00', 'amount_brutto' => '1', 'amount' => '2']))
			->purchaseRow(self::now(), 'paid', null);

		$this->assertSame(29.0, $row['amount']);
	}
}
