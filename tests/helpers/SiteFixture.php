<?php

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use Sayamaapps\Uketsuke\Signature;

/**
 * A disposable site: temporary roots, and a symlink to the plugin under test in its plugins folder.
 *
 * The storage root sits outside the index (web) root, as the module requires.
 */
final class SiteFixture
{
	public const PASSPHRASE = 'test-passphrase';
	public const TOKEN = 'test-token';

	public readonly string $root;

	/**
	 * Creates the directory tree in the system temp directory.
	 */
	public function __construct()
	{
		$this->root = sys_get_temp_dir() . '/uketsuke-test-' . bin2hex(random_bytes(8));

		foreach (['public', 'storage', 'content', 'site/accounts', 'site/config', 'site/plugins', 'site/templates'] as $dir) {
			mkdir($this->root . '/' . $dir, 0700, true);
		}

		symlink(dirname(__DIR__, 2), $this->root . '/site/plugins/uketsuke');
	}

	/**
	 * Boots a Kirby app with the given module options and request.
	 *
	 * @param array<string, mixed> $options Module options without the `sayamaapps.uketsuke.` prefix.
	 * @param array<string, callable> $hooks Hooks of the site, keyed by name.
	 * @param array<string, mixed> $body Form body of the request.
	 * @param array<string, mixed> $query Query string of the request.
	 */
	public function app(
		array $options = [],
		array $hooks = [],
		string $method = 'GET',
		array $body = [],
		array $query = [],
		string|null $authorization = null
	): App {
		$prefixed = ['hooks' => $hooks];

		foreach ($options as $key => $value) {
			$prefixed['sayamaapps.uketsuke.' . $key] = $value;
		}

		if ($authorization === null) {
			unset($_SERVER['HTTP_AUTHORIZATION']);
		} else {
			$_SERVER['HTTP_AUTHORIZATION'] = $authorization;
		}

		return new App([
			'roots' => [
				'index' => $this->root . '/public',
				'site' => $this->root . '/site',
				'content' => $this->root . '/content',
				'storage' => $this->root . '/storage',
			],
			'options' => $prefixed,
			'request' => [
				'method' => $method,
				'body' => $body,
				'query' => $query,
			],
		]);
	}

	/**
	 * Destroys the app and removes the temporary site. The plugin symlink goes first, so no plugin file is touched.
	 */
	public function destroy(): void
	{
		App::destroy();
		unset($_SERVER['HTTP_AUTHORIZATION']);
		unlink($this->root . '/site/plugins/uketsuke');
		Dir::remove($this->root);
	}

	/**
	 * Returns the error log of the test process.
	 */
	public static function log(): string
	{
		$path = (string)ini_get('error_log');

		return is_file($path) ? (string)file_get_contents($path) : '';
	}

	/**
	 * Returns the fields with a valid sha_sign for the test passphrase.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	public static function signed(array $fields): array
	{
		$fields['sha_sign'] = Signature::compute(self::PASSPHRASE, $fields);

		return $fields;
	}

	/**
	 * Returns the ledger directory of this site.
	 */
	public function storage(): string
	{
		return $this->root . '/storage/uketsuke';
	}
}
