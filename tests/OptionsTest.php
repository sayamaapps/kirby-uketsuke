<?php

use Kirby\Cms\App;
use Kirby\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Uketsuke\Options;
use Sayamaapps\Uketsuke\Uketsuke;

#[CoversClass(Options::class)]
#[CoversClass(Uketsuke::class)]
#[RunTestsInSeparateProcesses]
final class OptionsTest extends TestCase
{
	private SiteFixture $site;

	protected function setUp(): void
	{
		$this->site = new SiteFixture();
	}

	protected function tearDown(): void
	{
		$this->site->destroy();
	}

	public function testConfiguredValuesAreRead(): void
	{
		$app = $this->site->app([
			'active' => true,
			'passphrase' => 'pass',
			'token' => 'tok',
			'path' => '/shop/ipn/',
			'retention' => 0,
			'storage' => $this->site->root . '/elsewhere',
		]);
		$options = Options::fromKirby($app);

		$this->assertTrue($options->active());
		$this->assertSame('pass', $options->passphrase());
		$this->assertSame('tok', $options->token());
		$this->assertSame('shop/ipn', $options->path());
		$this->assertSame(1, $options->retentionDays());
		$this->assertSame($this->site->root . '/elsewhere', $options->storageDirectory($app));
		$this->assertSame($this->site->root . '/elsewhere', Uketsuke::options()->storageDirectory($app));
	}

	public function testDefaultsAreInactiveAndKeepTheStorageOutsideTheWebRoot(): void
	{
		$app = $this->site->app();
		$options = Options::fromKirby($app);

		$this->assertFalse($options->active());
		$this->assertNull($options->passphrase());
		$this->assertNull($options->token());
		$this->assertSame('uketsuke/digistore24', $options->path());
		$this->assertSame(90, $options->retentionDays());
		$this->assertSame($this->site->root . '/storage/uketsuke', $options->storageDirectory($app));
	}

	public function testStorageDirectoryNeedsAStorageRootOrAnExplicitPath(): void
	{
		$options = new Options(true, 'pass', null, 'uketsuke/digistore24', 90, null);
		$noRoot = new App(['roots' => ['index' => $this->site->root . '/public', 'site' => $this->site->root . '/site']]);

		$this->expectException(InvalidArgumentException::class);
		$options->storageDirectory($noRoot);
	}

	public function testStorageInsideTheWebRootIsRefused(): void
	{
		$app = $this->site->app(['storage' => $this->site->root . '/public/ledger']);

		$this->expectException(InvalidArgumentException::class);
		Options::fromKirby($app)->storageDirectory($app);
	}

	public function testSymlinkIntoTheWebRootIsRefused(): void
	{
		symlink($this->site->root . '/public', $this->site->root . '/storage/uketsuke');
		$app = $this->site->app();

		$this->expectException(InvalidArgumentException::class);
		Options::fromKirby($app)->storageDirectory($app);
	}
}
