<?php

use Kirby\Cms\App;
use Sayamaapps\Uketsuke\Routes;

// The routes themselves are in src/Routes.php. Routes are a closure, not an
// array, so the path can depend on the options at resolution time.
return fn () => Routes::all(App::instance());
