<?php

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;

// Requirements the tool cannot see in the code: the PHP version and the installer
// that places the plugin in site/plugins. The PDO extensions are used through
// Kirby\Database, which the tool does not see as a symbol.
return static function (Configuration $configuration): Configuration {
	return $configuration
		->addNamedFilter(NamedFilter::fromString('php'))
		->addNamedFilter(NamedFilter::fromString('getkirby/composer-installer'))
		->addNamedFilter(NamedFilter::fromString('ext-json'))
		->addNamedFilter(NamedFilter::fromString('ext-pdo'))
		->addNamedFilter(NamedFilter::fromString('ext-pdo_sqlite'));
};
