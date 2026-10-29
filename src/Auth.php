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
	 */
	public static function granted(Request $request, string $token): bool
	{
		$header = $request->header('Authorization');

		if (is_string($header) === false || str_starts_with($header, 'Bearer ') === false) {
			return false;
		}

		return hash_equals($token, trim(substr($header, 7)));
	}
}
