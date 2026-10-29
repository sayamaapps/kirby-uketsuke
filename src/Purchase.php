<?php

namespace Sayamaapps\Uketsuke;

use DateTimeImmutable;
use DateTimeZone;

/**
 * One purchase (order) as stored in the ledger.
 *
 * Contains buyer data (e-mail, name). Only the site's own code reads it through
 * Uketsuke::ledger(); the protocol route never outputs it.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-uketsuke
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class Purchase
{
	public string|null $affiliate;
	public float|null $amount;
	public string|null $buyerId;
	public string|null $campaignKey;
	public string|null $country;
	public string|null $currency;
	public string|null $custom;
	public string|null $email;
	public string|null $firstName;
	public string|null $language;
	public string|null $lastName;
	public string $orderId;
	public DateTimeImmutable $paidAt;
	public string $productId;
	public string|null $productName;
	public string|null $productNameIntern;
	public DateTimeImmutable|null $refundedAt;
	public string $status;
	public bool $test;

	/**
	 * @param array<string, mixed> $row
	 */
	private function __construct(array $row)
	{
		$this->orderId = (string)$row['order_id'];
		$this->productId = (string)$row['product_id'];
		$this->productName = self::text($row, 'product_name');
		$this->productNameIntern = self::text($row, 'product_name_intern');
		$this->email = self::text($row, 'email');
		$this->firstName = self::text($row, 'first_name');
		$this->lastName = self::text($row, 'last_name');
		$this->status = (string)$row['status'];
		$this->paidAt = self::moment((string)$row['paid_at']);
		$this->refundedAt = self::text($row, 'refunded_at') === null ? null : self::moment((string)$row['refunded_at']);
		$this->test = (string)$row['test'] === '1';
		$this->language = self::text($row, 'language');
		$this->country = self::text($row, 'country');
		$this->affiliate = self::text($row, 'affiliate');
		$this->campaignKey = self::text($row, 'campaignkey');
		$this->amount = self::text($row, 'amount') === null ? null : (float)$row['amount'];
		$this->currency = self::text($row, 'currency');
		$this->custom = self::text($row, 'custom');
		$this->buyerId = self::text($row, 'buyer_id');
	}

	/**
	 * Builds the purchase from a row of the purchases table.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row): self
	{
		return new self($row);
	}

	/**
	 * Parses a UTC timestamp of the ledger.
	 */
	private static function moment(string $value): DateTimeImmutable
	{
		return new DateTimeImmutable($value, new DateTimeZone('UTC'));
	}

	/**
	 * Returns the column value as a string, null for SQL NULL.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function text(array $row, string $key): string|null
	{
		return isset($row[$key]) ? (string)$row[$key] : null;
	}

	/**
	 * Returns the purchase as an array with dates as ATOM strings.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		$data = get_object_vars($this);
		$data['paidAt'] = $this->paidAt->format(DATE_ATOM);
		$data['refundedAt'] = $this->refundedAt?->format(DATE_ATOM);

		return $data;
	}
}
