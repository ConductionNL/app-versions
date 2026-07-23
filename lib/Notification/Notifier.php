<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\AppVersions\Notification;

use OCA\AppVersions\AppInfo\Application;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders App Versions notifications (the `pinned_to_vulnerable` advisory
 * notice, and the `pat_expiring` / `pat_expired` token-expiry notices) into
 * localized subject/message text for the notifications app. Read-only
 * presentation — it never changes a version or a token.
 *
 * @psalm-api
 */
class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return 'App Versions';
	}

	/**
	 * Renders the localized subject/message for a notification; see
	 * "PAT expiry warnings" for the `pat_expiring` / `pat_expired` subjects.
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);

		if ($notification->getSubject() === 'pinned_to_vulnerable') {
			$parameters = $notification->getSubjectParameters();
			$app = is_string($parameters['app'] ?? null) ? $parameters['app'] : '';
			$version = is_string($parameters['version'] ?? null) ? $parameters['version'] : '';
			$advisory = is_string($parameters['advisory'] ?? null) ? $parameters['advisory'] : '';

			$notification
				->setParsedSubject(
					$l->t('Security advisory affects a pinned app version')
				)
				->setParsedMessage(
					$l->t('%1$s is on version %2$s, which is affected by advisory %3$s. Review and update to a safe version.', [$app, $version, $advisory])
				);

			return $notification;
		}

		if ($notification->getSubject() === 'pat_expiring') {
			$parameters = $notification->getSubjectParameters();
			$label = is_string($parameters['label'] ?? null) ? $parameters['label'] : '';
			$forge = is_string($parameters['forge'] ?? null) ? $parameters['forge'] : '';
			$days = is_int($parameters['daysRemaining'] ?? null) ? $parameters['daysRemaining'] : 0;

			$notification
				->setParsedSubject(
					$l->t('Access token expires soon')
				)
				->setParsedMessage(
					$l->n(
						'"%1$s" (%2$s) expires in %3$d day. Renew it before it lapses.',
						'"%1$s" (%2$s) expires in %3$d days. Renew it before it lapses.',
						$days,
						[$label, $forge, $days]
					)
				);

			return $notification;
		}

		if ($notification->getSubject() === 'pat_expired') {
			$parameters = $notification->getSubjectParameters();
			$label = is_string($parameters['label'] ?? null) ? $parameters['label'] : '';
			$forge = is_string($parameters['forge'] ?? null) ? $parameters['forge'] : '';

			$notification
				->setParsedSubject(
					$l->t('Access token expired')
				)
				->setParsedMessage(
					$l->t('"%1$s" (%2$s) has expired. Create a new token to keep private installs and discovery working.', [$label, $forge])
				);

			return $notification;
		}

		throw new UnknownNotificationException();
	}
}
