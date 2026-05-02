<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Service\Source;

use Exception;
use OCA\AppVersions\Service\Source\GithubReleaseSource;
use OCA\AppVersions\Service\Source\SourceBinding;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GithubReleaseSourceTest extends TestCase {
	private function buildSource(IClient $client): GithubReleaseSource {
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$logger = $this->createMock(LoggerInterface::class);

		return new GithubReleaseSource($clientService, $logger);
	}

	private function mockResponse(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		return $response;
	}

	public function testListVersionsReturnsSortedTags(): void {
		$body = json_encode([
			['tag_name' => 'v2.5.0'],
			['tag_name' => 'v2.4.0'],
			['tag_name' => '2.6.0'],
			['tag_name' => 'v2.5.0'], // duplicate
		], JSON_THROW_ON_ERROR);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(200, $body));

		$result = $this->buildSource($client)->listVersions(
			'openregister',
			SourceBinding::github('ConductionNL', 'openregister')
		);

		$this->assertNull($result['error']);
		$this->assertCount(3, $result['versions']);
		$this->assertSame('2.6.0', $result['versions'][0]['version']);
		$this->assertSame('2.5.0', $result['versions'][1]['version']);
		$this->assertSame('2.4.0', $result['versions'][2]['version']);
	}

	public function testListVersionsHandles404Gracefully(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(404, ''));

		$result = $this->buildSource($client)->listVersions(
			'openregister',
			SourceBinding::github('ConductionNL', 'openregister')
		);

		$this->assertSame([], $result['versions']);
		$this->assertSame('GitHub repository not found.', $result['error']);
	}

	public function testListVersionsHandlesRateLimit(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(403, ''));

		$result = $this->buildSource($client)->listVersions(
			'openregister',
			SourceBinding::github('ConductionNL', 'openregister')
		);

		$this->assertSame([], $result['versions']);
		$this->assertStringContainsString('rate limit', $result['error']);
	}

	public function testListVersionsHandlesNetworkException(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new Exception('Could not resolve host'));

		$result = $this->buildSource($client)->listVersions(
			'openregister',
			SourceBinding::github('ConductionNL', 'openregister')
		);

		$this->assertSame([], $result['versions']);
		$this->assertStringContainsString('Could not reach', $result['error']);
	}

	public function testListVersionsHandlesMalformedJson(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(200, 'not json'));

		$result = $this->buildSource($client)->listVersions(
			'openregister',
			SourceBinding::github('ConductionNL', 'openregister')
		);

		$this->assertSame([], $result['versions']);
		$this->assertStringContainsString('malformed JSON', $result['error']);
	}

	public function testResolveReleaseFindsMatchingTagAndAsset(): void {
		$body = json_encode([
			[
				'tag_name' => 'v2.5.0',
				'assets' => [
					[
						'name' => 'openregister-2.5.0.tar.gz',
						'browser_download_url' => 'https://example.invalid/openregister-2.5.0.tar.gz',
					],
					[
						'name' => 'openregister-2.5.0.tar.gz.sha256',
						'browser_download_url' => 'https://example.invalid/openregister-2.5.0.tar.gz.sha256',
					],
				],
			],
		], JSON_THROW_ON_ERROR);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(200, $body));

		$release = $this->buildSource($client)->resolveRelease(
			'openregister',
			'2.5.0',
			SourceBinding::github('ConductionNL', 'openregister')
		);

		$this->assertNotNull($release);
		$this->assertSame('2.5.0', $release['version']);
		$this->assertSame('https://example.invalid/openregister-2.5.0.tar.gz', $release['download']);
		$this->assertSame('https://example.invalid/openregister-2.5.0.tar.gz.sha256', $release['sha256Url']);
	}

	public function testResolveReleaseFailsWhenMultipleMatchingAssets(): void {
		$body = json_encode([
			[
				'tag_name' => 'v2.5.0',
				'assets' => [
					[
						'name' => 'openregister-2.5.0.tar.gz',
						'browser_download_url' => 'https://example.invalid/a.tar.gz',
					],
					[
						'name' => 'openregister-2.5.0-debug.tar.gz',
						'browser_download_url' => 'https://example.invalid/b.tar.gz',
					],
				],
			],
		], JSON_THROW_ON_ERROR);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(200, $body));

		$release = $this->buildSource($client)->resolveRelease(
			'openregister',
			'2.5.0',
			SourceBinding::github('ConductionNL', 'openregister')
		);

		$this->assertNotNull($release);
		$this->assertArrayHasKey('error', $release);
		$this->assertStringContainsString('Multiple matching assets', $release['error']);
	}

	public function testResolveReleaseReturnsNullForUnknownVersion(): void {
		$body = json_encode([['tag_name' => 'v2.4.0', 'assets' => []]], JSON_THROW_ON_ERROR);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(200, $body));

		$release = $this->buildSource($client)->resolveRelease(
			'openregister',
			'2.5.0',
			SourceBinding::github('ConductionNL', 'openregister')
		);

		$this->assertNull($release);
	}
}
