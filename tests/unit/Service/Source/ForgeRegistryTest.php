<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Service\Source;

use InvalidArgumentException;
use OCA\AppVersions\Service\Source\Forge;
use OCA\AppVersions\Service\Source\ForgeRegistry;
use PHPUnit\Framework\TestCase;

final class ForgeRegistryTest extends TestCase {
	public function testGithubConfig(): void {
		$forge = (new ForgeRegistry())->get(ForgeRegistry::FORGE_GITHUB);

		self::assertSame('https://api.github.com', $forge->apiBaseUrl);
		self::assertSame(Forge::SCHEME_BEARER, $forge->authScheme);
		self::assertTrue($forge->exposesScopeHeader);
		self::assertSame('Bearer abc', $forge->authHeaderValue('abc'));
		self::assertSame('https://api.github.com/repos/o/r/releases?per_page=100', $forge->releasesEndpoint('o/r'));
	}

	public function testCodebergConfig(): void {
		$forge = (new ForgeRegistry())->get(ForgeRegistry::FORGE_CODEBERG);

		self::assertSame('https://codeberg.org/api/v1', $forge->apiBaseUrl);
		self::assertSame(Forge::SCHEME_TOKEN, $forge->authScheme);
		self::assertFalse($forge->exposesScopeHeader);
		self::assertSame('token abc', $forge->authHeaderValue('abc'));
		self::assertSame('https://codeberg.org/api/v1/user', $forge->userEndpoint());
	}

	public function testHas(): void {
		$registry = new ForgeRegistry();
		self::assertTrue($registry->has(ForgeRegistry::FORGE_CODEBERG));
		self::assertFalse($registry->has('gitlab'));
	}

	public function testUnknownForgeThrows(): void {
		$this->expectException(InvalidArgumentException::class);
		(new ForgeRegistry())->get('gitlab');
	}
}
