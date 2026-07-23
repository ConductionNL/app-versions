<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\AppVersions\Service\Pat;

use OCA\AppVersions\AppInfo\Application;
use OCA\AppVersions\Db\Pat;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Raises the owner-only `pat_expiring` / `pat_expired` notification for a
 * single crossed threshold. Read-only — it never mutates the PAT row; the
 * caller ({@see \OCA\AppVersions\BackgroundJob\PatExpiryWarningJob}) is
 * responsible for persisting the ledger once notification succeeds.
 *
 * @psalm-api
 */
class PatExpiryNotifier {
	public function __construct(
		private IManager $notificationManager,
		private PatDeeplinkBuilder $deeplinkBuilder,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Notifies a PAT's owner about a crossed expiry threshold, linking the
	 * per-forge renewal deeplink; see "PAT expiry warnings".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 * @param PatExpiryEvaluator::THRESHOLD_* $threshold one of {@see PatExpiryEvaluator}'s THRESHOLD_* constants
	 * @return bool true if the notification was raised
	 */
	public function notify(Pat $pat, string $threshold, ?int $daysRemaining): bool {
		try {
			$deeplink = $this->deeplinkBuilder->build($pat->getKind());

			$notification = $this->notificationManager->createNotification();
			$notification
				->setApp(Application::APP_ID)
				->setUser($pat->getOwnerUid())
				->setDateTime($this->timeFactory->getDateTime())
				->setObject('pat', (string)$pat->getId())
				->setLink($deeplink['url']);

			if ($threshold === PatExpiryEvaluator::THRESHOLD_EXPIRED) {
				$notification->setSubject('pat_expired', [
					'label' => $pat->getLabel(),
					'forge' => $pat->getForge(),
				]);
			} else {
				$notification->setSubject('pat_expiring', [
					'label' => $pat->getLabel(),
					'forge' => $pat->getForge(),
					'daysRemaining' => $daysRemaining ?? 0,
				]);
			}

			$this->notificationManager->notify($notification);

			return true;
		} catch (\Throwable $error) {
			$this->logger->warning('PatExpiryNotifier: failed to raise notification', [
				'patId' => $pat->getId(),
				'threshold' => $threshold,
				'message' => $error->getMessage(),
			]);

			return false;
		}
	}
}
