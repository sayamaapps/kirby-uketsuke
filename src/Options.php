<?php

namespace Sayamaapps\Uketsuke;

use Kirby\Cms\App;
use Kirby\Exception\InvalidArgumentException;

/**
 * The plugin options, read once per request.
 *
 * Also decides where the ledger lives: the storage directory must lie outside the
 * web root, because the database must not be served as a file.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-uketsuke
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
final class Options
{
	/**
	 * Creates the option set; tests pass values directly, the site uses fromKirby().
	 */
	public function __construct(
		private readonly bool $active,
		private readonly string|null $passphrase,
		private readonly string|null $token,
		private readonly string $path,
		private readonly int $retention,
		private readonly string|null $storage
	) {
	}

	/**
	 * Returns true when the module answers requests at all.
	 */
	public function active(): bool
	{
		return $this->active;
	}

	/**
	 * Reads `sayamaapps.uketsuke.*` from the running site.
	 */
	public static function fromKirby(App $kirby): self
	{
		$plugin = $kirby->plugin('sayamaapps/uketsuke');
		$path = trim(self::text($plugin?->option('path')) ?? '', '/');

		return new self(
			active: $plugin?->option('active') === true,
			passphrase: self::text($plugin?->option('passphrase')),
			token: self::text($plugin?->option('token')),
			path: $path === '' ? 'uketsuke/digistore24' : $path,
			retention: max(1, (int)($plugin?->option('retention') ?? 90)),
			storage: self::text($plugin?->option('storage')),
		);
	}

	/**
	 * Returns the IPN passphrase, null when it is not set.
	 */
	public function passphrase(): string|null
	{
		return $this->passphrase;
	}

	/**
	 * Returns the route prefix without slashes at either end. An empty prefix (`'/'`) falls back
	 * to the default, because it would put the receiver on the home page.
	 */
	public function path(): string
	{
		return $this->path;
	}

	/**
	 * Resolves symlinks of the longest existing part of the path, so a link into the web root is seen.
	 */
	private static function resolve(string $path): string
	{
		$current = rtrim($path, '/');
		$tail = '';

		while (realpath($current) === false && $current !== dirname($current)) {
			$tail = '/' . basename($current) . $tail;
			$current = dirname($current);
		}

		$resolved = realpath($current);

		return ($resolved === false ? $current : $resolved) . $tail;
	}

	/**
	 * Returns the number of days notifications are kept (at least 1).
	 */
	public function retentionDays(): int
	{
		return $this->retention;
	}

	/**
	 * Returns the storage directory of the ledger.
	 *
	 * Defaults to `<storage root>/uketsuke`. Kirby 5 defines no `storage` root by itself:
	 * without the `storage` option the site must set that root, for example in index.php.
	 * Throws when neither is set or when the directory lies inside the web root (index root),
	 * including through a symlink.
	 */
	public function storageDirectory(App $kirby): string
	{
		$directory = $this->storage;

		if ($directory === null) {
			$root = $kirby->root('storage');

			if ($root === null || trim($root) === '') {
				throw new InvalidArgumentException(
					message: 'Uketsuke: Kirby root "storage" is not set. Set sayamaapps.uketsuke.storage to a directory outside the web root.'
				);
			}

			$directory = rtrim($root, '/') . '/uketsuke';
		}

		if (self::within($directory, $kirby->root('index'))) {
			throw new InvalidArgumentException(
				message: 'Uketsuke: storage directory ' . $directory . ' lies inside the web root. Move it outside, a web server would serve the database as a file.'
			);
		}

		return $directory;
	}

	/**
	 * Returns the value as a non-empty string, null otherwise.
	 */
	private static function text(mixed $value): string|null
	{
		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * Returns the bearer token of the protocol and retry routes, null disables them.
	 */
	public function token(): string|null
	{
		return $this->token;
	}

	/**
	 * Returns true when the path lies inside the root directory (equal counts as inside).
	 */
	private static function within(string $path, string|null $root): bool
	{
		if ($root === null || trim($root) === '') {
			return false;
		}

		return str_starts_with(self::resolve($path) . '/', self::resolve($root) . '/');
	}
}
