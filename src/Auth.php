<?php

namespace Sayamaapps\Uketsuke;

use Kirby\Http\Request;

/**
 * Checks the bearer token of the protocol and retry routes.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-uketsuke
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final class Auth
{
	/**
	 * Returns true when the request carries `Authorization: Bearer <token>` with the configured token.
	 * The scheme name is case-insensitive (RFC 7235).
	 */
	public static function granted(Request $request, string $token): bool
	{
		$header = $request->header('Authorization');

		if (is_string($header) === false || preg_match('/^Bearer\s+(\S+)\s*$/i', $header, $match) !== 1) {
			return false;
		}

		return hash_equals($token, $match[1]);
	}
}
