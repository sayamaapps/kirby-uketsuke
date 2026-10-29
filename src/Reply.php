<?php

namespace Sayamaapps\Uketsuke;

use Kirby\Exception\Exception as KirbyException;
use Kirby\Http\Response;
use Throwable;

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
	 * Logs a failure of the ledger and returns `503 STORAGE_ERROR`.
	 *
	 * The message is logged only for Kirby exceptions, which the module throws itself
	 * (for example a storage directory that is not set). Other messages may quote data.
	 */
	public static function storageError(string $context, Throwable $e): Response
	{
		$detail = $e instanceof KirbyException ? ': ' . $e->getMessage() : '';
		error_log('Uketsuke: ' . $context . ' (' . $e::class . $detail . ')');

		return self::text(503, 'STORAGE_ERROR');
	}

	/**
	 * Creates a text/plain response with the given status code and body.
	 */
	public static function text(int $code, string $body): Response
	{
		return new Response(['body' => $body, 'type' => 'text/plain', 'code' => $code]);
	}
}
