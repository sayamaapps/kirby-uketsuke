<?php

/**
 * Plugin registration for Uketsuke.
 *
 * Kirby loads this file. It loads the class map, the configuration and
 * registers the plugin. The logic lives in src/.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-uketsuke
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */

use Kirby\Filesystem\F;

F::loadClasses(require __DIR__ . '/autoload.php', __DIR__);

Kirby::plugin(
	name: 'sayamaapps/uketsuke',
	extends: [
		'options' => require __DIR__ . '/config/options.php',
		'routes'  => require __DIR__ . '/config/routes.php',
	],
	root: __DIR__
);
