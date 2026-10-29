<?php

namespace Sayamaapps\Uketsuke;

use Kirby\Cms\App;
use Throwable;

/**
 * Fires the Uketsuke hooks and keeps a failing hook from failing the request.
 *
 * A hook that throws is logged with its exception class only: the message may
 * contain buyer data that the site's own code put there. The class name is
 * returned so the caller can store it in notifications.hook_error.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-uketsuke
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final class Hooks
{
	/**
	 * Triggers the hook and returns the exception class name if it failed, null otherwise.
	 *
	 * @param array<string, mixed> $arguments Named arguments, matched by the hook's parameter names.
	 */
	public static function fire(App $kirby, string $name, array $arguments): string|null
	{
		try {
			$kirby->trigger($name, $arguments);
			return null;
		} catch (Throwable $e) {
			error_log('Uketsuke: hook ' . $name . ' failed with ' . $e::class);
			return $e::class;
		}
	}
}
