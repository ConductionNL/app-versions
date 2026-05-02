<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Service\Pat;

use OCA\AppVersions\Db\Pat;
use OCA\AppVersions\Db\PatMapper;
use OCA\AppVersions\Service\Pat\PatResolver;
use PHPUnit\Framework\TestCase;

final class PatResolverTest extends TestCase {
	private function makePat(int $id, string $owner, string $pattern, ?string $expiresAt = null): Pat {
		$pat = new Pat();
		$pat->setId($id);
		$pat->setOwnerUid($owner);
		$pat->setTargetPattern($pattern);
		$pat->setExpiresAt($expiresAt);

		return $pat;
	}

	private function buildResolver(array $patsVisibleTo): PatResolver {
		$mapper = $this->createMock(PatMapper::class);
		$mapper->method('findVisibleTo')->willReturnCallback(
			static fn (string $uid): array => $patsVisibleTo[$uid] ?? []
		);

		return new PatResolver($mapper);
	}

	public function testReturnsNullWhenNoPatsVisible(): void {
		$resolver = $this->buildResolver(['admin' => []]);

		$this->assertNull($resolver->findFor('ConductionNL/openregister', 'admin'));
	}

	public function testMatchesGlobPattern(): void {
		$pat = $this->makePat(1, 'admin', 'ConductionNL/*');
		$resolver = $this->buildResolver(['admin' => [$pat]]);

		$found = $resolver->findFor('ConductionNL/openregister', 'admin');

		$this->assertNotNull($found);
		$this->assertSame(1, $found->getId());
	}

	public function testNonMatchingPatternReturnsNull(): void {
		$pat = $this->makePat(1, 'admin', 'OtherOrg/*');
		$resolver = $this->buildResolver(['admin' => [$pat]]);

		$this->assertNull($resolver->findFor('ConductionNL/openregister', 'admin'));
	}

	public function testExpiredPatSkipped(): void {
		$expired = $this->makePat(1, 'admin', 'ConductionNL/*', '2020-01-01 00:00:00');
		$valid = $this->makePat(2, 'admin', 'ConductionNL/*', '2099-01-01 00:00:00');

		$resolver = $this->buildResolver(['admin' => [$expired, $valid]]);

		$found = $resolver->findFor('ConductionNL/openregister', 'admin');

		$this->assertNotNull($found);
		$this->assertSame(2, $found->getId());
	}

	public function testOwnerOwnedPreferredOverShared(): void {
		$shared = $this->makePat(1, 'other', 'ConductionNL/*');
		$shared->setSharedWithAdmins(true);
		$ownerOwned = $this->makePat(2, 'admin', 'ConductionNL/*');

		$resolver = $this->buildResolver(['admin' => [$shared, $ownerOwned]]);

		$found = $resolver->findFor('ConductionNL/openregister', 'admin');

		$this->assertNotNull($found);
		$this->assertSame(2, $found->getId(), 'PAT owned by current user should win over shared one');
	}

	public function testMoreSpecificPatternPreferred(): void {
		$broad = $this->makePat(1, 'admin', 'ConductionNL/*');
		$specific = $this->makePat(2, 'admin', 'ConductionNL/openregister');

		$resolver = $this->buildResolver(['admin' => [$broad, $specific]]);

		$found = $resolver->findFor('ConductionNL/openregister', 'admin');

		$this->assertNotNull($found);
		$this->assertSame(2, $found->getId());
	}
}
