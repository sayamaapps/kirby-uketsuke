<?php

namespace Sayamaapps\Uketsuke;

use Kirby\Cms\App;

/**
 * Entry point for site code: the purchase ledger and the options.
 *
 * Site code (hooks, templates, controllers) calls these methods. They are the only
 * place in src/ that reads the global App instance, the way a hook callback does.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-uketsuke
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final class Uketsuke
{
	/**
	 * Returns the purchase ledger of the running site.
	 *
	 * The database is opened on first use, so this does not touch the disk.
	 */
	public static function ledger(): Ledger
	{
		$kirby = App::instance();

		return new Ledger(Options::fromKirby($kirby)->storageDirectory($kirby));
	}

	/**
	 * Returns the module options of the running site.
	 */
	public static function options(): Options
	{
		return Options::fromKirby(App::instance());
	}
}
