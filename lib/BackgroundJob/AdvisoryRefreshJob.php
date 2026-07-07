<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\AppVersions\BackgroundJob;

use OCA\AppVersions\Service\Advisory\AdvisoryNotifier;
use OCA\AppVersions\Service\Advisory\AdvisoryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Scheduled refresh that re-resolves security advisories for every installed
 * app and raises an admin notification for any advisory that newly affects a
 * pinned/installed version. It performs NO version change — it only reads
 * (via {@see AdvisoryService}) and notifies (via {@see AdvisoryNotifier}),
 * neither of which can install or unpin; the administrator stays in control.
 *
 * @psalm-api
 */
class AdvisoryRefreshJob extends TimedJob {
	/** Re-resolve advisories every 6 hours. */
	private const INTERVAL_SECONDS = 6 * 60 * 60;

	public function __construct(
		ITimeFactory $time,
		private AdvisoryService $advisoryService,
		private AdvisoryNotifier $advisoryNotifier,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL_SECONDS);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		try {
			$correlations = $this->advisoryService->correlateAll();
			$fired = $this->advisoryNotifier->notifyNewAdvisories($correlations);
			if ($fired > 0) {
				$this->logger->info('AdvisoryRefreshJob: raised advisory notifications', ['count' => $fired]);
			}
		} catch (\Throwable $error) {
			$this->logger->error('AdvisoryRefreshJob: refresh failed', ['message' => $error->getMessage()]);
		}
	}
}
