<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Service\Source;

use OCA\AppVersions\AppInfo\Application;
use OCA\AppVersions\Service\Audit\AuditLogger;
use OCA\AppVersions\Service\Source\SourceBinding;
use OCA\AppVersions\Service\Source\SourceBindingStore;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class SourceBindingStoreTest extends TestCase {
	private function auditLogger(): AuditLogger {
		return $this->createMock(AuditLogger::class);
	}

	private function userSession(string $uid = 'alice'): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}

	public function testGetReturnsNullWhenUnset(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$store = new SourceBindingStore($config, $this->auditLogger(), $this->userSession());

		$this->assertNull($store->get('openregister'));
	}

	public function testGetReturnsBindingForValidJson(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode([
			'kind' => SourceBinding::KIND_GITHUB_RELEASE,
			'owner' => 'ConductionNL',
			'repo' => 'openregister',
			'assetPattern' => '*.tar.gz',
		], JSON_THROW_ON_ERROR));

		$store = new SourceBindingStore($config, $this->auditLogger(), $this->userSession());
		$binding = $store->get('openregister');

		$this->assertNotNull($binding);
		$this->assertSame('github:ConductionNL/openregister', $binding->getId());
	}

	public function testGetReturnsNullOnMalformedJson(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('{not valid json');

		$store = new SourceBindingStore($config, $this->auditLogger(), $this->userSession());

		$this->assertNull($store->get('openregister'));
	}

	public function testGetReturnsNullOnInvalidBinding(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode([
			'kind' => SourceBinding::KIND_GITHUB_RELEASE,
			// missing owner/repo
		], JSON_THROW_ON_ERROR));

		$store = new SourceBindingStore($config, $this->auditLogger(), $this->userSession());

		$this->assertNull($store->get('openregister'));
	}

	public function testSetWritesJson(): void {
		$captured = null;
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$config->expects($this->once())
			->method('setValueString')
			->with(
				Application::APP_ID,
				'source.openregister',
				$this->callback(function (string $value) use (&$captured): bool {
					$captured = $value;

					return true;
				})
			);

		$store = new SourceBindingStore($config, $this->auditLogger(), $this->userSession());
		$store->set('openregister', SourceBinding::github('ConductionNL', 'openregister'));

		$decoded = json_decode((string)$captured, true);
		$this->assertIsArray($decoded);
		$this->assertSame('github-release', $decoded['kind']);
		$this->assertSame('ConductionNL', $decoded['owner']);
		$this->assertSame('openregister', $decoded['repo']);
	}

	public function testSetRecordsABindSourceAuditEntryWithoutAPreviousMessage(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$auditLogger = $this->createMock(AuditLogger::class);
		$auditLogger->expects($this->once())
			->method('record')
			->with(
				'alice',
				'openregister',
				AuditLogger::OPERATION_BIND_SOURCE,
				null,
				null,
				'github:ConductionNL/openregister',
				AuditLogger::STATUS_SUCCESS,
				null,
			);

		$store = new SourceBindingStore($config, $auditLogger, $this->userSession());
		$store->set('openregister', SourceBinding::github('ConductionNL', 'openregister'));
	}

	public function testSetOnRebindNamesThePreviousSourceIdInTheMessage(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode([
			'kind' => SourceBinding::KIND_GITHUB_RELEASE,
			'owner' => 'ConductionNL',
			'repo' => 'openregister',
		], JSON_THROW_ON_ERROR));

		$auditLogger = $this->createMock(AuditLogger::class);
		$auditLogger->expects($this->once())
			->method('record')
			->with(
				'alice',
				'openregister',
				AuditLogger::OPERATION_BIND_SOURCE,
				null,
				null,
				'appstore',
				AuditLogger::STATUS_SUCCESS,
				$this->callback(fn (?string $message): bool => $message !== null && str_contains($message, 'github:ConductionNL/openregister')),
			);

		$store = new SourceBindingStore($config, $auditLogger, $this->userSession());
		$store->set('openregister', SourceBinding::appStore());
	}

	public function testClearDeletesValue(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())
			->method('deleteKey')
			->with(Application::APP_ID, 'source.openregister');

		$store = new SourceBindingStore($config, $this->auditLogger(), $this->userSession());
		$store->clear('openregister');
	}
}
