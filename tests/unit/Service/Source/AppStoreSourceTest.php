<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Service\Source;

use OCA\AppVersions\Service\Source\AppStoreSource;
use OCA\AppVersions\Service\Source\SourceBinding;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/specs/changelog-visibility/spec.md
 */
final class AppStoreSourceTest extends TestCase {
	/**
	 * @param array<string, mixed> $appPayload
	 */
	private function buildSource(array $appPayload, string $language = 'en'): AppStoreSource {
		$body = json_encode(['data' => [$appPayload]], JSON_THROW_ON_ERROR);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn($body);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('28.0.0');

		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('findLanguage')->willReturn($language);

		return new AppStoreSource($clientService, $config, $l10nFactory);
	}

	private function binding(): SourceBinding {
		return SourceBinding::appStore();
	}

	public function testMapsRequestedLanguageChangelog(): void {
		$source = $this->buildSource([
			'id' => 'openregister',
			'releases' => [
				[
					'version' => '2.3.0',
					'translations' => [
						'en' => ['changelog' => 'English notes'],
						'nl' => ['changelog' => 'Nederlandse notities'],
					],
				],
			],
		], 'nl');

		$result = $source->listVersions('openregister', $this->binding());

		$this->assertNull($result['error']);
		$this->assertSame('Nederlandse notities', $result['versions'][0]['changelog']);
	}

	public function testFallsBackToEnglishWhenRequestedLanguageMissing(): void {
		$source = $this->buildSource([
			'id' => 'openregister',
			'releases' => [
				[
					'version' => '2.3.0',
					'translations' => [
						'en' => ['changelog' => 'English notes'],
					],
				],
			],
		], 'de');

		$result = $source->listVersions('openregister', $this->binding());

		$this->assertSame('English notes', $result['versions'][0]['changelog']);
	}

	public function testMissingTranslationsYieldsNullChangelogAndListingSucceeds(): void {
		$source = $this->buildSource([
			'id' => 'openregister',
			'releases' => [
				['version' => '2.3.0'],
			],
		]);

		$result = $source->listVersions('openregister', $this->binding());

		$this->assertNull($result['error']);
		$this->assertCount(1, $result['versions']);
		$this->assertNull($result['versions'][0]['changelog']);
	}

	public function testMalformedTranslationsShapeIsFailSoftNull(): void {
		$source = $this->buildSource([
			'id' => 'openregister',
			'releases' => [
				[
					'version' => '2.3.0',
					// Malformed: translations should be a map, not a string.
					'translations' => 'not-an-array',
				],
			],
		]);

		$result = $source->listVersions('openregister', $this->binding());

		// The throwing mapper must not fail the whole listing.
		$this->assertNull($result['error']);
		$this->assertCount(1, $result['versions']);
		$this->assertSame('2.3.0', $result['versions'][0]['version']);
		$this->assertNull($result['versions'][0]['changelog']);
	}

	public function testBlankChangelogNormalizesToNull(): void {
		$source = $this->buildSource([
			'id' => 'openregister',
			'releases' => [
				[
					'version' => '2.3.0',
					'translations' => [
						'en' => ['changelog' => "   \n  "],
					],
				],
			],
		]);

		$result = $source->listVersions('openregister', $this->binding());

		$this->assertNull($result['versions'][0]['changelog']);
	}
}
