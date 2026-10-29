<?php

use Kirby\Filesystem\F;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// The plugin's classes come from the class map, as in production.
F::loadClasses(require dirname(__DIR__) . '/autoload.php', dirname(__DIR__));

require_once __DIR__ . '/helpers/SiteFixture.php';
require_once __DIR__ . '/helpers/HookRecorder.php';

// error_log() goes to a file: the tests read it, and nothing reaches the test output.
ini_set('error_log', sys_get_temp_dir() . '/uketsuke-tests-' . getmypid() . '.log');

// Kirby's Whoops handler would stay registered after every App is created and
// flag each test as risky. PHPUnit reports errors itself.
Kirby\Cms\App::$enableWhoops = false;
