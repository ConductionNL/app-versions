<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\AppVersions\Service\Installer;

use OCA\AppVersions\AppInfo\Application;
use OCP\IL10N;
use OCP\L10N\IFactory;

/**
 * Detects environment conditions that prevent a successful install.
 *
 * The authoritative, blocking check is whether the PARENT directory of an
 * app's folder is writable — `rename()` of the existing folder (the backup
 * step) needs write permission on the parent, not on the folder itself.
 * Dev-checkout heuristics (a `.git` directory, owner ≠ web-server uid) only
 * enrich the human warning and never block on their own.
 *
 * @spec openspec/specs/version-management/spec.md
 * @psalm-api
 */
class EnvironmentCheck {
	private ?IL10N $l = null;

	public function __construct(
		private IFactory $l10nFactory,
	) {
	}

	private function l10n(): IL10N {
		if ($this->l === null) {
			$this->l = $this->l10nFactory->get(Application::APP_ID);
		}

		return $this->l;
	}

	/**
	 * Authoritative functional check used by the fail-fast guard: can the
	 * web-server user rename the app folder (i.e. is its parent writable)?
	 */
	public function isDestinationWritable(string $appPath): bool {
		return is_writable(dirname($appPath));
	}

	/**
	 * Inspects an installed app's folder and returns a card descriptor.
	 *
	 * @return array{manageable: bool, warning: ?string}
	 */
	public function inspect(string $appPath): array {
		$writable = $this->isDestinationWritable($appPath);

		if (!$writable) {
			return [
				'manageable' => false,
				'warning' => $this->l10n()->t('This app\'s folder is not writable by the web-server user, so version changes will fail. It is likely a bind-mounted dev checkout — fix the folder ownership/permissions to manage it here.'),
			];
		}

		// Writable: manageable. Advisory dev-checkout signals enrich the warning
		// but never flip `manageable` to false.
		$advisory = $this->devCheckoutWarning($appPath);

		return [
			'manageable' => true,
			'warning' => $advisory,
		];
	}

	/**
	 * Advisory-only warning for a writable folder that still looks like a dev
	 * checkout (contains `.git`, or is owned by a different uid than the
	 * web-server process). Returns null when no heuristic matches.
	 */
	private function devCheckoutWarning(string $appPath): ?string {
		$signals = [];

		if (is_dir($appPath . '/.git')) {
			$signals[] = 'git';
		}

		if (function_exists('posix_getuid')) {
			$owner = @fileowner($appPath);
			if ($owner !== false && $owner !== posix_getuid()) {
				$signals[] = 'owner';
			}
		}

		if ($signals === []) {
			return null;
		}

		return $this->l10n()->t('This app looks like a development checkout (e.g. a Git working copy or a bind mount owned by another user). Version changes here will overwrite your working copy.');
	}
}
