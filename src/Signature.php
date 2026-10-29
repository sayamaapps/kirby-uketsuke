<?php

namespace Sayamaapps\Uketsuke;

/**
 * Digistore24 IPN signature (sha_sign).
 *
 * The algorithm follows Digistore24's reference receiver, see
 * https://www.digistore24.com/download/ipn/examples/ipn/ipn_receiver.php
 * The passphrase is appended after every key=value pair, not once at the end.
 * An empty passphrase never verifies.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-uketsuke
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final class Signature
{
	/**
	 * Computes the signature over all parameters, without the signature fields themselves.
	 *
	 * Keys are sorted case-sensitively. Empty values (null, '', false) are skipped; '0' is not.
	 * Nested arrays are flattened the way http_build_query() writes them (`custom[a]=b`).
	 *
	 * @param array<string, mixed> $params
	 */
	public static function compute(string $passphrase, array $params): string
	{
		unset($params['sha_sign'], $params['SHASIGN']);

		$keys = array_keys($params);
		sort($keys, SORT_STRING);

		$string = '';
		foreach ($keys as $key) {
			$value = $params[$key];

			if ($value === null || $value === '' || $value === false) {
				continue;
			}

			if (is_array($value)) {
				$query = http_build_query([$key => $value], '', '&', PHP_QUERY_RFC3986);
				foreach (explode('&', $query) as $part) {
					if ($part === '') {
						continue;
					}
					$string .= rawurldecode($part) . $passphrase;
				}
				continue;
			}

			$string .= $key . '=' . (string)$value . $passphrase;
		}

		return strtoupper(hash('sha512', $string));
	}

	/**
	 * Returns true when the received sha_sign (or SHASIGN) matches the computed signature.
	 *
	 * @param array<string, mixed> $params The complete request data, including the signature.
	 */
	public static function verify(string $passphrase, array $params): bool
	{
		if ($passphrase === '') {
			return false;
		}

		$received = $params['sha_sign'] ?? $params['SHASIGN'] ?? null;

		if (is_string($received) === false || $received === '') {
			return false;
		}

		return hash_equals(self::compute($passphrase, $params), strtoupper($received));
	}
}
