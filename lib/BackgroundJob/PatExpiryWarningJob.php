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

use OCA\AppVersions\Db\Pat;
use OCA\AppVersions\Db\PatMapper;
use OCA\AppVersions\Service\Pat\PatExpiryEvaluator;
use OCA\AppVersions\Service\Pat\PatExpiryNotifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily sweep of every stored PAT: for each token with a known `expiresAt`,
 * notifies the owner once per crossed threshold (14 days, 3 days, expiry).
 * Tokens without a known expiry are left alone — never probed, never
 * notified. Each token is processed independently so one bad row (decode
 * failure, missing owner, forge lookup error) never blocks the rest of the
 * sweep.
 *
 * @psalm-api
 */
class PatExpiryWarningJob extends TimedJob {
	/** Sweep once every 24 hours. */
	private const INTERVAL_SECONDS = 24 * 60 * 60;

	public function __construct(
		ITimeFactory $time,
		private PatMapper $patMapper,
		private PatExpiryEvaluator $evaluator,
		private PatExpiryNotifier $notifier,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL_SECONDS);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		foreach ($this->patMapper->findAll() as $pat) {
			try {
				$this->processPat($pat);
			} catch (\Throwable $error) {
				$this->logger->warning('PatExpiryWarningJob: failed to process a token', [
					'patId' => $pat->getId(),
					'message' => $error->getMessage(),
				]);
			}
		}
	}

	/**
	 * Evaluates and, if needed, notifies for a single token; see "PAT expiry warnings".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	private function processPat(Pat $pat): void {
		$expiresAt = $pat->getExpiresAt();
		if ($expiresAt === null) {
			// Unknown expiry (fine-grained/Codeberg tokens the API didn't
			// disclose) MUST NOT be probed or notified.
			return;
		}

		$threshold = $this->evaluator->highestCrossedThreshold($expiresAt);
		if ($threshold === null) {
			return;
		}
		if ($pat->hasWarnedThreshold($threshold)) {
			return;
		}

		$daysRemaining = $this->evaluator->daysRemaining($expiresAt);
		$notified = $this->notifier->notify($pat, $threshold, $daysRemaining);
		if (!$notified) {
			// Notification failed — leave the ledger untouched so the next
			// run retries this threshold instead of silently skipping it.
			return;
		}

		$pat->addWarnedThreshold($threshold);
		$this->patMapper->update($pat);
	}
}
