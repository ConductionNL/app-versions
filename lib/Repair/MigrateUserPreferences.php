<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2026, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Repair;

use OCA\Versioniq\AppInfo\Application;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Carries this app's per-user preferences across the `app_versions` ->
 * `versioniq` app-id rename.
 *
 * WHY THIS EXISTS SEPARATELY FROM MigrateAppConfigKeys. `IAppConfig` and
 * `IConfig`'s user values are different stores: the former is `oc_appconfig`,
 * the latter `oc_preferences`. Both are namespaced by app id, so both are cut
 * off by the rename, but copying one does nothing for the other.
 *
 * WHY IT IS HERE EVEN THOUGH TODAY'S CODE WRITES NO USER VALUE. Grepping this
 * app at the time of the rename finds no `setUserValue()` call: the per-user
 * state it does keep — PATs — lives in the `app_versions_pats` table, which
 * the rename does not touch, and PAT ownership is a `uid` column rather than a
 * preference. That is an argument for the step being CHEAP, not for it being
 * absent:
 *   - `oc_preferences` is written by more than this app's own code. Nextcloud
 *     itself stores per-app UI state there, and a release between this app's
 *     first version and this rename may have written a key the current code no
 *     longer reads.
 *   - The failure mode if a preference does exist and is missed is SILENT.
 *     Every read of a user value carries a default, so a lost value is not an
 *     error — it is the user's explicit choice quietly replaced by the shipped
 *     one, with nothing logged and no test red.
 *   - The step is a no-op on an install with nothing stored (it reports
 *     "nothing to do"), so the cost of keeping it is a single user walk on
 *     install, and the cost of omitting it is unbounded.
 *
 * WHY IT ENUMERATES BY USER RATHER THAN BY VALUE. `IConfig` has no "list every
 * key for every user", and `getUsersForUserValue(app, key, value)` needs both
 * the key and the VALUE up front. That is exhaustive only for a closed value
 * set — a boolean opt-out — and this app has no such guarantee: anything it
 * might store is keyed by a managed app's id or holds a free-form string. Used
 * against an open-valued key it migrates NOTHING while reporting success.
 * Walking `IUserManager::callForSeenUsers()` and asking
 * `IConfig::getUserKeys()` what that user actually stored is exhaustive by
 * construction, for open and closed value sets alike, and — like
 * MigrateAppConfigKeys' use of `getKeys()` — cannot drift when a future
 * release adds a preference.
 *
 * `callForSeenUsers()` rather than `callForAllUsers()`: a stored preference is
 * written from a logged-in session, so a user with a row in `oc_preferences`
 * for this app has necessarily been seen. The seen-user walk reads the same
 * table and avoids a full backend enumeration (LDAP included) on every
 * install.
 *
 * SAFETY. Idempotent and non-destructive, matching MigrateAppConfigKeys:
 *   - a value is copied only when the user has nothing stored under the new
 *     app id, so a preference changed after the rename is never clobbered and
 *     a second run is a no-op;
 *   - the old `app_versions` rows are never deleted, so a rollback still finds
 *     them;
 *   - every failure is logged and the loop continues, reads included. This
 *     step runs under `<install>`, where a throw means the app never enables.
 *
 * NO TOKEN MATERIAL PASSES THROUGH HERE. Personal access tokens are stored
 * encrypted in the `app_versions_pats` table, not in `oc_preferences`, and the
 * rename does not touch that table.
 *
 * Registered under BOTH `<install>` and `<post-migration>` in
 * `appinfo/info.xml` alongside MigrateAppConfigKeys — see the ordering comment
 * there.
 *
 * @psalm-api
 */
class MigrateUserPreferences implements IRepairStep {
	/**
	 * The preferences namespace this app used before the rename.
	 *
	 * Deliberately the OLD app id — see MigrateAppConfigKeys::OLD_APP_ID.
	 *
	 * @var string
	 */
	private const OLD_APP_ID = 'app_versions';

	/**
	 * Number of preferences copied during this run.
	 *
	 * Held as state rather than passed around because the walk happens inside
	 * a closure handed to IUserManager::callForSeenUsers(), which returns
	 * nothing and cannot thread a running total back out.
	 *
	 * @var int
	 */
	private int $migrated = 0;

	/**
	 * Number of preferences already present under the new app id.
	 *
	 * @var int
	 */
	private int $alreadyPresent = 0;

	public function __construct(
		private IConfig $config,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Human-readable name shown by `occ upgrade` / `occ maintenance:repair`.
	 *
	 * @spec exclude One-off app_versions->versioniq rename plumbing; the
	 *       IRepairStep contract requires a name and it carries no behaviour.
	 */
	public function getName(): string {
		return 'Copy Versioniq per-user preferences from the app_versions app id';
	}

	/**
	 * Copy every stored per-user preference from the old app id to the new one.
	 *
	 * @spec exclude One-off app_versions->versioniq app-id rename plumbing: it
	 *       moves oc_preferences rows between namespaces and adds no behaviour
	 *       of its own. This app's per-user state that the rename DOES have to
	 *       preserve is PAT ownership, which lives in the frozen
	 *       app_versions_pats table and is specified in
	 *       openspec/specs/pat-management/spec.md.
	 */
	public function run(IOutput $output): void {
		$this->migrated = 0;
		$this->alreadyPresent = 0;

		try {
			// The callback returns null rather than void: IUserManager treats a
			// `false` return as "stop iterating", so the contract is
			// Closure(IUser): (bool|null) and null means "keep going".
			$this->userManager->callForSeenUsers(
				function (IUser $user): ?bool {
					$this->migrateUser($user->getUID());
					return null;
				},
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Versioniq: could not enumerate users; per-user preferences were not migrated',
				['exception' => $e->getMessage()],
			);
			$output->warning('MigrateUserPreferences: user enumeration failed; preferences left under the app_versions app id.');
			return;
		}

		if ($this->migrated === 0 && $this->alreadyPresent === 0) {
			$output->info('MigrateUserPreferences: no stored app_versions user preferences on this install; nothing to do.');
			return;
		}

		$output->info(
			'MigrateUserPreferences: migrated ' . $this->migrated . ' preference(s); '
			. $this->alreadyPresent . ' already set under versioniq.',
		);
	}

	/**
	 * Copy one user's stored preferences from the old app id to the new one.
	 *
	 * @param string $userId The Nextcloud user ID.
	 */
	private function migrateUser(string $userId): void {
		foreach ($this->oldKeysFor($userId) as $key) {
			try {
				$old = $this->config->getUserValue($userId, self::OLD_APP_ID, $key, '');
				if ($old === '') {
					continue;
				}

				$existing = $this->config->getUserValue($userId, Application::APP_ID, $key, '');
				if ($existing !== '') {
					$this->alreadyPresent++;
					continue;
				}

				$this->config->setUserValue($userId, Application::APP_ID, $key, $old);
				$this->migrated++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'Versioniq: could not migrate one user preference; leaving it under the old app id',
					['key' => $key, 'exception' => $e->getMessage()],
				);
			}
		}
	}

	/**
	 * Every preference key this user has stored under the old app id.
	 *
	 * @param string $userId The Nextcloud user ID.
	 *
	 * @return array<int, string> The stored key names, empty when unreadable.
	 */
	private function oldKeysFor(string $userId): array {
		try {
			return $this->config->getUserKeys($userId, self::OLD_APP_ID);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Versioniq: could not enumerate app_versions preference keys for a user; skipping that user',
				['exception' => $e->getMessage()],
			);
			return [];
		}
	}
}
