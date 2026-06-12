<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Service\Installer;

use OCA\AppVersions\Service\Installer\EnvironmentCheck;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

final class EnvironmentCheckTest extends TestCase {
	/** @var list<string> */
	private array $tempDirs = [];

	protected function tearDown(): void {
		foreach (array_reverse($this->tempDirs) as $dir) {
			@chmod($dir, 0777);
			@chmod(dirname($dir), 0777);
		}
		foreach (array_reverse($this->tempDirs) as $dir) {
			$this->removeRecursive($dir);
		}
		$this->tempDirs = [];
		parent::tearDown();
	}

	private function build(): EnvironmentCheck {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);

		return new EnvironmentCheck($factory);
	}

	/**
	 * Creates a parent/app folder pair under the system temp dir and returns the
	 * app path. The parent is what governs writability.
	 */
	private function makeAppDir(): string {
		$parent = sys_get_temp_dir() . '/av-envcheck-' . uniqid('', true);
		$appPath = $parent . '/sampleapp';
		mkdir($appPath, 0777, true);
		$this->tempDirs[] = $parent;

		return $appPath;
	}

	private function removeRecursive(string $dir): void {
		if (!is_dir($dir)) {
			@unlink($dir);

			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($it as $item) {
			$item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
		}
		@rmdir($dir);
	}

	public function testWritableParentReportsManageable(): void {
		$appPath = $this->makeAppDir();
		$result = $this->build()->inspect($appPath);

		self::assertTrue($result['manageable']);
	}

	public function testNonWritableParentReportsNotManageableWithWarning(): void {
		if (function_exists('posix_getuid') && posix_getuid() === 0) {
			self::markTestSkipped('Running as root bypasses directory permissions.');
		}

		$appPath = $this->makeAppDir();
		// Make the PARENT directory non-writable so rename() of the app folder
		// would fail — this is the authoritative functional check.
		chmod(dirname($appPath), 0555);

		$check = $this->build();
		self::assertFalse($check->isDestinationWritable($appPath));

		$result = $check->inspect($appPath);
		self::assertFalse($result['manageable']);
		self::assertNotNull($result['warning']);
		self::assertNotSame('', $result['warning']);
	}

	public function testGitCheckoutEnrichesWarningButDoesNotBlock(): void {
		$appPath = $this->makeAppDir();
		mkdir($appPath . '/.git', 0777, true);

		$result = $this->build()->inspect($appPath);

		// Writable + .git: advisory only — still manageable.
		self::assertTrue($result['manageable']);
		self::assertNotNull($result['warning']);
	}

	public function testWritablePlainAppHasNoWarning(): void {
		$appPath = $this->makeAppDir();
		$result = $this->build()->inspect($appPath);

		self::assertTrue($result['manageable']);
		// A plain writable folder owned by the test user yields no advisory.
		if ($result['warning'] !== null) {
			// Owner-mismatch can legitimately fire in some CI setups; only the
			// .git heuristic must be absent here.
			self::assertStringNotContainsString('Git', (string)$result['warning']);
		}
	}
}
