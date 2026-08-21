<?php

declare(strict_types=1);

namespace OCA\AppVersions\Tests\Unit\BackgroundJob;

use OCA\AppVersions\BackgroundJob\AdvisoryRefreshJob;
use OCA\AppVersions\Service\Advisory\AdvisoryDigestNotifier;
use OCA\AppVersions\Service\Advisory\AdvisoryNotifier;
use OCA\AppVersions\Service\Advisory\AdvisorySettingsStore;
use OCA\AppVersions\Service\Advisory\AdvisoryResultStore;
use OCA\AppVersions\Service\Advisory\AdvisoryService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AdvisoryRefreshJobTest extends TestCase {
	private function correlation(string $appId, ?string $error = null): array {
		return [
			'appId' => $appId,
			'installedVersion' => '1.0.0',
			'state' => AdvisoryService::STATE_NONE,
			'advisories' => [],
			'recommendedVersion' => null,
			'error' => $error,
		];
	}

	private function runJob(
		AdvisoryService $service,
		AdvisoryResultStore $store,
		?AdvisoryNotifier $notifier = null,
		?LoggerInterface $logger = null,
		int $now = 1_700_000_000,
	): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);

		$settings = $this->createMock(AdvisorySettingsStore::class);
		$settings->method('getIntervalSeconds')->willReturn(6 * 3600);

		$job = new AdvisoryRefreshJob(
			$time,
			$service,
			$notifier ?? $this->createMock(AdvisoryNotifier::class),
			$this->createMock(AdvisoryDigestNotifier::class),
			$store,
			$settings,
			$logger ?? $this->createMock(LoggerInterface::class),
		);

		// run() is protected — this job is invoked by the cron runner, and the
		// test drives it the same way the runner does.
		$method = new \ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}

	/**
	 * The whole point of moving correlation off the request path: the sweep's
	 * result has to be PERSISTED, or the endpoint has nothing to serve and the
	 * work is thrown away every six hours.
	 */
	public function testStoresTheCorrelationSnapshotWithTheSweepTime(): void {
		$correlations = ['notes' => $this->correlation('notes')];

		$service = $this->createMock(AdvisoryService::class);
		$service->method('correlateAll')->willReturn($correlations);

		$store = $this->createMock(AdvisoryResultStore::class);
		$store->expects($this->once())
			->method('save')
			->with($correlations, 1_700_000_000);

		$this->runJob($service, $store, now: 1_700_000_000);
	}

	/**
	 * REGRESSION GUARD for the interaction that this change exists to fix.
	 *
	 * AdvisoryService's DEFAULT budget is 5s, sized for a request a user is
	 * waiting on. If the job inherited that default it would correlate only
	 * the first handful of apps and store a snapshot that looks complete —
	 * the endpoint would answer instantly and be wrong, which is worse than
	 * the hang it replaced.
	 */
	public function testSweepsWithItsOwnBudgetNotTheRequestPathDefault(): void {
		$service = $this->createMock(AdvisoryService::class);
		$service->expects($this->once())
			->method('correlateAll')
			->with($this->greaterThan(AdvisoryService::CORRELATE_ALL_BUDGET_SECONDS))
			->willReturn([]);

		$this->runJob($service, $this->createMock(AdvisoryResultStore::class));
	}

	/**
	 * Persisting comes first: notification talks to the notifications app and
	 * is the more failure-prone half. A snapshot the admin can read must
	 * survive a notifier that throws.
	 */
	public function testKeepsTheStoredSnapshotWhenNotificationFails(): void {
		$correlations = ['notes' => $this->correlation('notes')];

		$service = $this->createMock(AdvisoryService::class);
		$service->method('correlateAll')->willReturn($correlations);

		$store = $this->createMock(AdvisoryResultStore::class);
		$store->expects($this->once())->method('save')->with($correlations, 1_700_000_000);

		$notifier = $this->createMock(AdvisoryNotifier::class);
		$notifier->method('notifyNewAdvisories')->willThrowException(new \RuntimeException('notifications app is gone'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$this->runJob($service, $store, $notifier, $logger);
	}

	/**
	 * A sweep that could not reach some sources must say so. Apps whose source
	 * did not answer carry an `error`, and a UI that only renders badges for
	 * what it found cannot distinguish those from "clean".
	 */
	public function testWarnsWhenSomeAppsCouldNotBeCorrelated(): void {
		$service = $this->createMock(AdvisoryService::class);
		$service->method('correlateAll')->willReturn([
			'notes' => $this->correlation('notes'),
			'calendar' => $this->correlation('calendar', 'source did not answer'),
		]);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('could not be correlated'),
				$this->callback(static fn (array $c): bool => $c['unreached'] === 1 && $c['total'] === 2),
			);

		$this->runJob($service, $this->createMock(AdvisoryResultStore::class), null, $logger);
	}

	/**
	 * A failing sweep must not take the cron runner down with it.
	 */
	public function testSwallowsAndLogsASweepFailure(): void {
		$service = $this->createMock(AdvisoryService::class);
		$service->method('correlateAll')->willThrowException(new \RuntimeException('forge unreachable'));

		$store = $this->createMock(AdvisoryResultStore::class);
		$store->expects($this->never())->method('save');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$this->runJob($service, $store, null, $logger);
	}
}
