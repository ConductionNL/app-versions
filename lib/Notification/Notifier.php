<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\AppVersions\Notification;

use OCA\AppVersions\AppInfo\Application;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders App Versions notifications (currently only the
 * `pinned_to_vulnerable` advisory notice) into localized subject/message text
 * for the notifications app. Read-only presentation — it never changes a
 * version.
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

		throw new UnknownNotificationException();
	}
}
