<?php

namespace Sayamaapps\Uketsuke;

use DateTimeImmutable;
use DateTimeZone;

/**
 * One received Digistore24 notification as stored in the ledger.
 *
 * The payload holds only the allow-listed, non-personal fields (see Payload).
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-uketsuke
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final readonly class Notification
{
	/**
	 * @param array<string, string> $payload Allow-listed fields of the notification.
	 */
	public function __construct(
		public string $event,
		public string $orderId,
		public string $transactionId,
		public string $productId,
		public DateTimeImmutable $receivedAt,
		public array $payload,
		public bool $duplicate
	) {
	}

	/**
	 * Rebuilds a notification from a row of the notifications table.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row): self
	{
		$payload = json_decode((string)$row['payload'], true);

		return new self(
			event: (string)$row['event'],
			orderId: (string)$row['order_id'],
			transactionId: (string)$row['transaction_id'],
			productId: (string)$row['product_id'],
			receivedAt: new DateTimeImmutable((string)$row['received_at'], new DateTimeZone('UTC')),
			payload: is_array($payload) ? $payload : [],
			duplicate: (string)$row['duplicate'] === '1',
		);
	}
}
