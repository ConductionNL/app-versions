<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Service\Pat;

use OCA\AppVersions\Db\Pat;
use OCA\AppVersions\Db\PatMapper;
use OCA\AppVersions\Service\Pat\PatManager;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

final class PatManagerTest extends TestCase {
	public function testBuildHintShortToken(): void {
		$this->assertSame('****', PatManager::buildHint('abc'));
		$this->assertSame('********', PatManager::buildHint('eightchr'));
	}

	public function testBuildHintFullToken(): void {
		$token = 'ghp_abcdefghijklmnopqrstuvwxyz1234567890';

		$this->assertSame('ghp_...7890', PatManager::buildHint($token));
	}

	public function testCreateEncryptsTokenAndCapturesHint(): void {
		$mapper = $this->createMock(PatMapper::class);
		$mapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$crypto = $this->createMock(ICrypto::class);
		$crypto->expects($this->once())
			->method('encrypt')
			->with('ghp_secrettoken1234567890abcdef1234567890')
			->willReturn('encrypted-blob');

		$manager = new PatManager($mapper, $crypto);

		$pat = $manager->create(
			'admin',
			'My PAT',
			Pat::KIND_CLASSIC,
			'ConductionNL/*',
			'ghp_secrettoken1234567890abcdef1234567890',
			['repo'],
			[],
			null,
		);

		$this->assertSame('encrypted-blob', $pat->getEncryptedToken());
		$this->assertSame('ghp_...7890', $pat->getTokenHint());
		$this->assertSame('admin', $pat->getOwnerUid());
		$this->assertSame('ConductionNL/*', $pat->getTargetPattern());
		$this->assertFalse($pat->getSharedWithAdmins());

		$validated = json_decode($pat->getLastValidatedScopes() ?? '{}', true);
		$this->assertIsArray($validated);
		$this->assertSame(['repo'], $validated['scopes']);
	}

	public function testUseTokenDecryptsOnlyInsideCallback(): void {
		$pat = new Pat();
		$pat->setEncryptedToken('encrypted-blob');

		$mapper = $this->createMock(PatMapper::class);
		$mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$crypto = $this->createMock(ICrypto::class);
		$crypto->expects($this->once())
			->method('decrypt')
			->with('encrypted-blob')
			->willReturn('plaintext-token');

		$manager = new PatManager($mapper, $crypto);

		$captured = null;
		$result = $manager->useToken($pat, function (string $plaintext) use (&$captured): string {
			$captured = $plaintext;

			return 'callback-result';
		});

		$this->assertSame('plaintext-token', $captured);
		$this->assertSame('callback-result', $result);
		$this->assertNotNull($pat->getLastUsedAt());
	}

	public function testRefreshValidationPersistsScopesAndExpiry(): void {
		$pat = new Pat();
		$mapper = $this->createMock(PatMapper::class);
		$mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$crypto = $this->createMock(ICrypto::class);
		$manager = new PatManager($mapper, $crypto);

		$validation = \OCA\AppVersions\Service\Pat\ValidationResult::accepted(
			['repo'],
			[],
			'2026-08-15 12:00:00'
		);

		$updated = $manager->refreshValidation($pat, $validation);

		$this->assertSame('2026-08-15 12:00:00', $updated->getExpiresAt());
		$validated = json_decode($updated->getLastValidatedScopes() ?? '{}', true);
		$this->assertIsArray($validated);
		$this->assertSame(['repo'], $validated['scopes']);
	}
}
