<?php

namespace Sayamaapps\Uketsuke;

use Kirby\Http\Response;

/**
 * Builds the plain-text and JSON responses of the module.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-uketsuke
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final class Reply
{
	/**
	 * Creates an application/json response.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function json(array $data, int $code = 200): Response
	{
		return Response::json($data, $code);
	}

	/**
	 * Creates a text/plain response with the given status code and body.
	 */
	public static function text(int $code, string $body): Response
	{
		return new Response(['body' => $body, 'type' => 'text/plain', 'code' => $code]);
	}
}
