<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\AppVersions\Service\AutoUpdate;

use OCA\AppVersions\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads and writes the two global auto-update settings: `auto_update_enabled`
 * (the kill switch, default `false`) and `auto_update_window` (default
 * {@see AutoUpdateWindow::DEFAULT_WINDOW}). Plain app-config values — see
 * design.md "Storage" ("Globals").
 *
 * @psalm-api
 */
class AutoUpdateSettingsStore {
	public const CONFIG_ENABLED = 'auto_update_enabled';
	public const CONFIG_WINDOW = 'auto_update_window';

	public function __construct(
		private IAppConfig $config,
	) {
	}

	/**
	 * Whether the global auto-update kill switch is on; see "Global kill
	 * switch and window".
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function isEnabled(): bool {
		return $this->config->getValueBool(Application::APP_ID, self::CONFIG_ENABLED, false);
	}

	/**
	 * The configured maintenance window, falling back to the default when
	 * unset or empty; see "Global kill switch and window".
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function getWindow(): string {
		$window = $this->config->getValueString(Application::APP_ID, self::CONFIG_WINDOW, AutoUpdateWindow::DEFAULT_WINDOW);

		return $window === '' ? AutoUpdateWindow::DEFAULT_WINDOW : $window;
	}

	/**
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function setEnabled(bool $enabled): void {
		$this->config->setValueBool(Application::APP_ID, self::CONFIG_ENABLED, $enabled);
	}

	/**
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function setWindow(string $window): void {
		$this->config->setValueString(Application::APP_ID, self::CONFIG_WINDOW, $window);
	}
}
