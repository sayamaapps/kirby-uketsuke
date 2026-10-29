<?php

namespace Sayamaapps\Uketsuke;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Reads a Digistore24 IPN request and maps it to ledger fields.
 *
 * Every field is read defensively, with the fallbacks of Digistore24's parameter
 * table. Test mode: api_mode is sent as 'live' or 'test'; a present value other than
 * 'live' counts as test, and so does pay_method 'test'. A missing api_mode counts as
 * live, unlike Digistore24's reference receiver: a real buyer must not lose a delivery
 * because a field is absent. Order times are local German time (Europe/Berlin) and
 * are stored as UTC.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-uketsuke
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final class Payload
{
	/**
	 * Fields that may be stored in notifications.payload. Everything else is dropped.
	 * No e-mail address, name or address is on this list.
	 */
	private const NOTIFICATION_FIELDS = [
		'event',
		'order_id',
		'transaction_id',
		'product_id',
		'product_name',
		'product_name_intern',
		'api_mode',
		'pay_method',
		'billing_type',
		'billing_status',
		'transaction_type',
		'pay_sequence_no',
		'quantity',
		'amount',
		'currency',
		'language',
		'campaignkey',
		'affiliate_name',
		'order_date',
		'order_time',
		'ipn_version',
		'merchant_id',
	];

	/**
	 * @param array<string, mixed> $data The form body of the request.
	 */
	public function __construct(
		private readonly array $data
	) {
	}

	/**
	 * Returns the purchase amount as float, null when missing or not numeric.
	 */
	private function amount(): float|null
	{
		$value = $this->first('transaction_amount', 'amount_brutto', 'amount');

		return $value !== null && is_numeric($value) ? (float)$value : null;
	}

	/**
	 * Returns the event name, '' when missing.
	 */
	public function event(): string
	{
		return $this->first('event') ?? '';
	}

	/**
	 * Returns the first non-empty string value of the given keys, null if none.
	 */
	private function first(string ...$keys): string|null
	{
		foreach ($keys as $key) {
			$value = $this->data[$key] ?? null;

			if (is_string($value) && $value !== '') {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Returns true for a test purchase: api_mode present and not 'live', or pay_method 'test'.
	 */
	public function isTest(): bool
	{
		$mode = $this->first('api_mode');

		if ($mode !== null && strtolower($mode) !== 'live') {
			return true;
		}

		return strtolower($this->first('pay_method', 'transaction_pay_method') ?? '') === 'test';
	}

	/**
	 * Returns the notification fields that may be stored, allow-listed and non-empty only.
	 *
	 * @return array<string, string>
	 */
	public function notificationPayload(): array
	{
		$payload = [];

		foreach (self::NOTIFICATION_FIELDS as $key) {
			$value = $this->first($key);

			if ($value !== null) {
				$payload[$key] = $value;
			}
		}

		return $payload;
	}

	/**
	 * Returns the order id, '' when missing.
	 */
	public function orderId(): string
	{
		return $this->first('order_id') ?? '';
	}

	/**
	 * Returns the purchase time in UTC: order_date + order_time, else order_date_time, else now.
	 */
	private function paidAt(DateTimeImmutable $now): DateTimeImmutable
	{
		$local = null;
		$day = $this->first('order_date');

		if ($day !== null) {
			$local = $day . ' ' . ($this->first('order_time') ?? '00:00:00');
		}

		$local ??= $this->first('order_date_time');

		if ($local === null) {
			return $now;
		}

		try {
			$parsed = new DateTimeImmutable($local, new DateTimeZone('Europe/Berlin'));
		} catch (Throwable) {
			return $now;
		}

		return $parsed->setTimezone(new DateTimeZone('UTC'));
	}

	/**
	 * Returns the product id, '' when missing.
	 */
	public function productId(): string
	{
		return $this->first('product_id') ?? '';
	}

	/**
	 * Returns the column values of a new purchases row.
	 *
	 * @return array<string, mixed>
	 */
	public function purchaseRow(DateTimeImmutable $now, string $status, DateTimeImmutable|null $refundedAt): array
	{
		$stamp = $now->format('Y-m-d H:i:s');

		return [
			'order_id' => $this->orderId(),
			'product_id' => $this->productId(),
			'product_name' => $this->first('product_name'),
			'product_name_intern' => $this->first('product_name_intern'),
			'email' => $this->first('email'),
			'first_name' => $this->first('address_first_name', 'first_name'),
			'last_name' => $this->first('address_last_name', 'last_name'),
			'status' => $status,
			'paid_at' => $this->paidAt($now)->format('Y-m-d H:i:s'),
			'refunded_at' => $refundedAt?->format('Y-m-d H:i:s'),
			'test' => $this->isTest() ? 1 : 0,
			'language' => $this->first('language'),
			'country' => $this->first('address_country', 'country'),
			'affiliate' => $this->first('affiliate_name', 'affiliate'),
			'campaignkey' => $this->first('campaignkey'),
			'amount' => $this->amount(),
			'currency' => $this->first('transaction_currency', 'currency'),
			'custom' => $this->first('custom'),
			'buyer_id' => $this->first('buyer_id'),
			'source' => 'ipn',
			'created_at' => $stamp,
			'updated_at' => $stamp,
		];
	}

	/**
	 * Returns the transaction id, '' when missing.
	 */
	public function transactionId(): string
	{
		return $this->first('transaction_id') ?? '';
	}
}
