<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Service\Source;

use InvalidArgumentException;
use OCA\AppVersions\Service\Source\SourceBinding;
use PHPUnit\Framework\TestCase;

final class SourceBindingTest extends TestCase {
	public function testAppStoreFactoryProducesAppstoreId(): void {
		$binding = SourceBinding::appStore();

		$this->assertSame(SourceBinding::KIND_APPSTORE, $binding->kind);
		$this->assertSame('appstore', $binding->getId());
		$this->assertNull($binding->getOwnerRepo());
	}

	public function testGithubFactoryProducesNamespacedId(): void {
		$binding = SourceBinding::github('ConductionNL', 'openregister');

		$this->assertSame(SourceBinding::KIND_GITHUB_RELEASE, $binding->kind);
		$this->assertSame('github:ConductionNL/openregister', $binding->getId());
		$this->assertSame('ConductionNL/openregister', $binding->getOwnerRepo());
		$this->assertSame('*.tar.gz', $binding->getAssetPattern());
		$this->assertNotNull($binding->boundAt);
	}

	public function testGithubFactoryAcceptsCustomAssetPattern(): void {
		$binding = SourceBinding::github('ConductionNL', 'openregister', 'openregister-*.zip');

		$this->assertSame('openregister-*.zip', $binding->getAssetPattern());
	}

	public function testRoundtripThroughArray(): void {
		$original = SourceBinding::github('ConductionNL', 'openregister');
		$restored = SourceBinding::fromArray($original->toArray());

		$this->assertSame($original->getId(), $restored->getId());
		$this->assertSame($original->getAssetPattern(), $restored->getAssetPattern());
		$this->assertSame($original->boundAt, $restored->boundAt);
	}

	public function testFromArrayRejectsMissingKind(): void {
		$this->expectException(InvalidArgumentException::class);

		SourceBinding::fromArray(['owner' => 'foo', 'repo' => 'bar']);
	}

	public function testGithubBindingRequiresOwnerAndRepo(): void {
		$this->expectException(InvalidArgumentException::class);

		new SourceBinding(SourceBinding::KIND_GITHUB_RELEASE, ['owner' => 'foo']);
	}

	public function testUnknownKindRejected(): void {
		$this->expectException(InvalidArgumentException::class);

		new SourceBinding('mystery-source');
	}

	public function testAssetPatternFallsBackOnInvalidConfig(): void {
		$binding = new SourceBinding(
			SourceBinding::KIND_GITHUB_RELEASE,
			['owner' => 'a', 'repo' => 'b', 'assetPattern' => '']
		);

		$this->assertSame('*.tar.gz', $binding->getAssetPattern());
	}
}
