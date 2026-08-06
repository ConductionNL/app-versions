<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\AppVersions\Service\Installer;

/**
 * Compares the database migration step files (`lib/Migration/Version*.php`)
 * shipped by an installed app copy against a target app copy, naming the
 * steps present in the installed copy but absent from the target — the
 * concrete schema risk a downgrade carries; see "Migration diff on
 * downgrade".
 *
 * The installed set is read from the filesystem (not the `oc_migrations` DB
 * table): a file diff is symmetric with the target (also a filesystem read
 * of an extracted archive) and does not pull in noise from steps shipped by
 * even older versions the DB table would still list — see design.md
 * "Rejected alternatives".
 *
 * @spec openspec/specs/migration-safety/spec.md
 * @psalm-api
 */
class MigrationDiffer {
	private const MIGRATION_GLOB = '/lib/Migration/Version*.php';

	/**
	 * Returns the migration step basenames present in `$installedAppPath`
	 * but absent from `$targetAppPath`, sorted; `null` when either side
	 * could not be read (unreadable archive layout) — a diff failure must
	 * degrade to a generic warning, never block an acknowledged downgrade.
	 *
	 * @spec openspec/specs/migration-safety/spec.md
	 * @return list<string>|null
	 */
	public function diff(?string $installedAppPath, ?string $targetAppPath): ?array {
		if ($installedAppPath === null || $installedAppPath === '' || $targetAppPath === null || $targetAppPath === '') {
			return null;
		}

		$installed = $this->migrationBasenames($installedAppPath);
		$target = $this->migrationBasenames($targetAppPath);
		if ($installed === null || $target === null) {
			return null;
		}

		$diff = array_values(array_diff($installed, $target));
		sort($diff);

		return $diff;
	}

	/**
	 * @return list<string>|null null when the migration directory exists but could not be enumerated
	 */
	private function migrationBasenames(string $appPath): ?array {
		try {
			$dir = rtrim($appPath, '/') . '/lib/Migration';
			if (!is_dir($dir)) {
				// No migrations directory is a legitimate empty set, not a failure.
				return [];
			}

			$files = glob(rtrim($appPath, '/') . self::MIGRATION_GLOB);
			if ($files === false) {
				return null;
			}

			return array_map(
				static fn (string $file): string => basename($file, '.php'),
				$files
			);
		} catch (\Throwable) {
			return null;
		}
	}
}
