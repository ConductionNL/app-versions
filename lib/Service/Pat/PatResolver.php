<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Service\Pat;

use OCA\AppVersions\Db\Pat;
use OCA\AppVersions\Db\PatMapper;

/**
 * Looks up the highest-priority non-expired PAT visible to the current uid
 * that matches the binding's `owner/repo`. Used by `GithubReleaseSource` to
 * decide whether to authenticate a request.
 *
 * @psalm-api
 */
class PatResolver {
	public function __construct(
		private PatMapper $mapper,
	) {
	}

	/**
	 * Finds the highest-priority non-expired PAT for the given forge matching owner/repo; see "Authenticated GitHub fetches" ("Expired PAT skipped").
	 *
	 * Only tokens whose `forge` equals the requested forge are considered, so a
	 * Codeberg binding never authenticates with a GitHub token and vice-versa.
	 * Legacy PAT rows default to forge `github`, so they keep serving GitHub.
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	public function findFor(string $forge, string $ownerRepo, string $currentUid): ?Pat {
		$now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
		$candidates = array_values(array_filter(
			$this->mapper->findVisibleTo($currentUid),
			static fn (Pat $pat): bool => $pat->getForge() === $forge,
		));

		// Prefer owner-owned PATs over shared ones; within each tier, prefer most-specific pattern.
		usort($candidates, function (Pat $a, Pat $b) use ($currentUid): int {
			$aOwn = $a->getOwnerUid() === $currentUid;
			$bOwn = $b->getOwnerUid() === $currentUid;
			if ($aOwn !== $bOwn) {
				return $aOwn ? -1 : 1;
			}

			return strlen($b->getTargetPattern()) <=> strlen($a->getTargetPattern());
		});

		foreach ($candidates as $pat) {
			if ($pat->getExpiresAt() !== null && $pat->getExpiresAt() <= $now) {
				continue;
			}
			if (fnmatch($pat->getTargetPattern(), $ownerRepo, FNM_NOESCAPE)) {
				return $pat;
			}
		}

		return null;
	}
}
