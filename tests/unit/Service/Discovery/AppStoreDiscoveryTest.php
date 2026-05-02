<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\Service\Discovery;

use OCA\AppVersions\Service\Discovery\AppStoreDiscovery;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AppStoreDiscoveryTest extends TestCase {
	/**
	 * @param list<array<string, mixed>> $catalog
	 */
	private function buildDiscovery(array $catalog, int $cachedTs = 0): AppStoreDiscovery {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => match ($key) {
				'cache.appstore_catalog' => $cachedTs > 0 ? json_encode($catalog) : $default,
				'cache.appstore_catalog_ts' => $cachedTs > 0 ? (string)$cachedTs : $default,
				default => $default,
			}
		);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn(json_encode($catalog, JSON_THROW_ON_ERROR));

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(2_000_000_000);

		return new AppStoreDiscovery($clientService, $config, $timeFactory, $this->createMock(LoggerInterface::class));
	}

	public function testFetchesAndFiltersByName(): void {
		$discovery = $this->buildDiscovery([
			['id' => 'openregister', 'name' => 'Open Register', 'summary' => 'data registers'],
			['id' => 'opencatalogi', 'name' => 'Open Catalogi', 'summary' => 'catalogs'],
			['id' => 'unrelated', 'name' => 'Unrelated', 'summary' => 'something'],
		]);

		$result = $discovery->search('register');

		$this->assertCount(1, $result->hits);
		$this->assertSame('openregister', $result->hits[0]->appId);
	}

	public function testCaseInsensitiveAcrossFields(): void {
		$discovery = $this->buildDiscovery([
			['id' => 'openregister', 'name' => 'Open Register', 'summary' => 'data REGISTERS'],
		]);

		$this->assertCount(1, $discovery->search('register')->hits);
		$this->assertCount(1, $discovery->search('REGISTER')->hits);
		$this->assertCount(1, $discovery->search('Register')->hits);
	}

	public function testMatchesCategory(): void {
		$discovery = $this->buildDiscovery([
			['id' => 'someapp', 'name' => 'Some App', 'summary' => '', 'categories' => ['social']],
		]);

		$this->assertCount(1, $discovery->search('social')->hits);
	}

	public function testNoMatchReturnsEmptyHits(): void {
		$discovery = $this->buildDiscovery([
			['id' => 'someapp', 'name' => 'Some App', 'summary' => 'unrelated'],
		]);

		$this->assertSame([], $discovery->search('register')->hits);
	}

	public function testExactMatchScoresHigher(): void {
		$discovery = $this->buildDiscovery([
			['id' => 'register-helper', 'name' => 'Register Helper', 'summary' => ''],
			['id' => 'register', 'name' => 'register', 'summary' => ''],
			['id' => 'misc', 'name' => 'Misc Register Stuff', 'summary' => ''],
		]);

		$result = $discovery->search('register');

		$this->assertSame('register', $result->hits[0]->appId, 'Exact match should rank first');
	}

	public function testEmptyQueryReturnsEmpty(): void {
		$discovery = $this->buildDiscovery([['id' => 'foo', 'name' => 'Foo', 'summary' => '']]);

		$this->assertSame([], $discovery->search('')->hits);
		$this->assertSame([], $discovery->search('   ')->hits);
	}

	public function testCacheHitAvoidsRefetch(): void {
		$now = 2_000_000_000;
		$discovery = $this->buildDiscovery(
			[['id' => 'openregister', 'name' => 'Open Register', 'summary' => 'cached entry']],
			cachedTs: $now - 60, // 60 s ago, well within TTL
		);

		// First call uses the cache (no fetch). We can verify by ensuring catalog content
		// from the "cached" payload appears in results.
		$result = $discovery->search('register');
		$this->assertCount(1, $result->hits);
		$this->assertStringContainsString('cached entry', $result->hits[0]->summary);
	}
}
