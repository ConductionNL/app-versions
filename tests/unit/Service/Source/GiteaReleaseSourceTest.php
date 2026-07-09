<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Service\Source;

use Exception;
use OCA\AppVersions\Service\Source\GiteaReleaseSource;
use OCA\AppVersions\Service\Source\SourceBinding;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GiteaReleaseSourceTest extends TestCase {
	private function buildSource(IClient $client): GiteaReleaseSource {
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$logger = $this->createMock(LoggerInterface::class);

		return new GiteaReleaseSource($clientService, $logger);
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
			'opencatalogi',
			SourceBinding::gitea('codeberg.org', 'Conduction', 'opencatalogi')
		);

		$this->assertNull($result['error']);
		$this->assertCount(3, $result['versions']);
		$this->assertSame('2.6.0', $result['versions'][0]['version']);
		$this->assertSame('2.5.0', $result['versions'][1]['version']);
		$this->assertSame('2.4.0', $result['versions'][2]['version']);
	}

	public function testListVersionsSkipsDraftReleases(): void {
		$body = json_encode([
			['tag_name' => 'v2.5.0', 'draft' => false],
			['tag_name' => 'v2.6.0-draft', 'draft' => true],
		], JSON_THROW_ON_ERROR);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(200, $body));

		$result = $this->buildSource($client)->listVersions(
			'opencatalogi',
			SourceBinding::gitea('codeberg.org', 'Conduction', 'opencatalogi')
		);

		$this->assertCount(1, $result['versions']);
		$this->assertSame('2.5.0', $result['versions'][0]['version']);
	}

	public function testListVersionsIncludesPrereleases(): void {
		$body = json_encode([
			['tag_name' => 'v1.0.5-dev.20260708205504', 'prerelease' => true, 'draft' => false],
			['tag_name' => 'v1.0.4', 'prerelease' => false, 'draft' => false],
		], JSON_THROW_ON_ERROR);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(200, $body));

		$result = $this->buildSource($client)->listVersions(
			'opencatalogi',
			SourceBinding::gitea('codeberg.org', 'Conduction', 'opencatalogi')
		);

		$this->assertCount(2, $result['versions']);
		// Newer prerelease sorts before older stable via version_compare.
		$this->assertSame('1.0.5-dev.20260708205504', $result['versions'][0]['version']);
	}

	public function testListVersionsHandles404Gracefully(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(404, ''));

		$result = $this->buildSource($client)->listVersions(
			'opencatalogi',
			SourceBinding::gitea('codeberg.org', 'Conduction', 'opencatalogi')
		);

		$this->assertSame([], $result['versions']);
		$this->assertStringContainsString('codeberg.org', $result['error']);
		$this->assertStringContainsString('not found', $result['error']);
	}

	public function testListVersionsHandlesForbidden(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(403, ''));

		$result = $this->buildSource($client)->listVersions(
			'opencatalogi',
			SourceBinding::gitea('codeberg.org', 'Conduction', 'opencatalogi')
		);

		$this->assertSame([], $result['versions']);
		$this->assertStringContainsString('private', $result['error']);
	}

	public function testListVersionsHandlesNetworkException(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new Exception('Could not resolve host'));

		$result = $this->buildSource($client)->listVersions(
			'opencatalogi',
			SourceBinding::gitea('codeberg.org', 'Conduction', 'opencatalogi')
		);

		$this->assertSame([], $result['versions']);
		$this->assertStringContainsString('Could not reach codeberg.org', $result['error']);
	}

	public function testListVersionsHandlesMalformedJson(): void {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(200, 'not json'));

		$result = $this->buildSource($client)->listVersions(
			'opencatalogi',
			SourceBinding::gitea('codeberg.org', 'Conduction', 'opencatalogi')
		);

		$this->assertSame([], $result['versions']);
		$this->assertStringContainsString('malformed JSON', $result['error']);
	}

	public function testResolveReleaseFindsMatchingTagAndAsset(): void {
		$body = json_encode([
			[
				'tag_name' => 'v1.0.5-dev.20260708205504',
				'draft' => false,
				'assets' => [
					[
						'name' => 'opencatalogi-1.0.5-dev.20260708205504.tar.gz',
						'browser_download_url' => 'https://codeberg.org/x/y/releases/download/v1.0.5-dev.20260708205504/opencatalogi-1.0.5-dev.20260708205504.tar.gz',
					],
					[
						'name' => 'opencatalogi-1.0.5-dev.20260708205504.tar.gz.sha256',
						'browser_download_url' => 'https://codeberg.org/x/y/releases/download/v1.0.5-dev.20260708205504/opencatalogi-1.0.5-dev.20260708205504.tar.gz.sha256',
					],
				],
			],
		], JSON_THROW_ON_ERROR);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(200, $body));

		$release = $this->buildSource($client)->resolveRelease(
			'opencatalogi',
			'1.0.5-dev.20260708205504',
			SourceBinding::gitea('codeberg.org', 'Conduction', 'opencatalogi')
		);

		$this->assertNotNull($release);
		$this->assertSame('1.0.5-dev.20260708205504', $release['version']);
		$this->assertSame('gitea-release', $release['kind']);
		$this->assertStringContainsString('opencatalogi-1.0.5-dev.20260708205504.tar.gz', $release['download']);
		$this->assertStringEndsWith('.sha256', $release['sha256Url']);
	}

	public function testResolveReleaseFailsWhenMultipleMatchingAssets(): void {
		$body = json_encode([
			[
				'tag_name' => 'v2.5.0',
				'draft' => false,
				'assets' => [
					['name' => 'app-2.5.0.tar.gz', 'browser_download_url' => 'https://example.invalid/a.tar.gz'],
					['name' => 'app-2.5.0-debug.tar.gz', 'browser_download_url' => 'https://example.invalid/b.tar.gz'],
				],
			],
		], JSON_THROW_ON_ERROR);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(200, $body));

		$release = $this->buildSource($client)->resolveRelease(
			'opencatalogi',
			'2.5.0',
			SourceBinding::gitea('codeberg.org', 'Conduction', 'opencatalogi')
		);

		$this->assertNotNull($release);
		$this->assertArrayHasKey('error', $release);
		$this->assertStringContainsString('Multiple matching assets', $release['error']);
	}

	public function testResolveReleaseReturnsNullForUnknownVersion(): void {
		$body = json_encode([['tag_name' => 'v2.4.0', 'draft' => false, 'assets' => []]], JSON_THROW_ON_ERROR);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->mockResponse(200, $body));

		$release = $this->buildSource($client)->resolveRelease(
			'opencatalogi',
			'2.5.0',
			SourceBinding::gitea('codeberg.org', 'Conduction', 'opencatalogi')
		);

		$this->assertNull($release);
	}

	public function testEndpointUrlIsBuiltFromBindingHost(): void {
		$client = $this->createMock(IClient::class);
		// Locks in three properties cheaply:
		//   - the `https://` scheme (no accidental http:// downgrade)
		//   - the binding's `host` reaches the URL unmodified (no hardcoded codeberg.org)
		//   - the `/api/v1/repos/…/releases` path prefix (Gitea API contract)
		$client->expects($this->once())
			->method('get')
			->with(
				$this->stringContains('https://gitea.example.internal/api/v1/repos/myorg/myapp/releases'),
				$this->anything()
			)
			->willReturn($this->mockResponse(200, '[]'));

		$result = $this->buildSource($client)->listVersions(
			'myapp',
			SourceBinding::gitea('gitea.example.internal', 'myorg', 'myapp')
		);

		$this->assertSame([], $result['versions']);
	}
}
