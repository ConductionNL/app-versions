<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Service\Source;

use InvalidArgumentException;
use OCA\AppVersions\Service\Source\SourceBinding;
use OCA\AppVersions\Service\Source\SourceRegistry;
use PHPUnit\Framework\TestCase;

final class SourceRegistryTest extends TestCase {
	public function testParseAppstore(): void {
		$binding = SourceRegistry::parseSourceId('appstore');

		$this->assertSame(SourceBinding::KIND_APPSTORE, $binding->kind);
	}

	public function testParseEmptyDefaultsToAppstore(): void {
		$binding = SourceRegistry::parseSourceId('');

		$this->assertSame(SourceBinding::KIND_APPSTORE, $binding->kind);
	}

	public function testParseGithubProducesBinding(): void {
		$binding = SourceRegistry::parseSourceId('github:ConductionNL/openregister');

		$this->assertSame(SourceBinding::KIND_GITHUB_RELEASE, $binding->kind);
		$this->assertSame('ConductionNL/openregister', $binding->getOwnerRepo());
	}

	public function testParseGithubMissingRepoRejected(): void {
		$this->expectException(InvalidArgumentException::class);

		SourceRegistry::parseSourceId('github:ConductionNL');
	}

	public function testParseGithubEmptyOwnerOrRepoRejected(): void {
		$this->expectException(InvalidArgumentException::class);

		SourceRegistry::parseSourceId('github:/openregister');
	}

	public function testParseUnknownPrefixRejected(): void {
		$this->expectException(InvalidArgumentException::class);

		SourceRegistry::parseSourceId('gitlab:ConductionNL/openregister');
	}
}
