<?php

declare(strict_types=1);

namespace OCA\AppVersions\Service\Pat;

use OCA\AppVersions\Db\Pat;
use OCA\AppVersions\Db\PatMapper;

/**
 * Looks up the highest-priority non-expired PAT visible to the current uid
 * that matches the binding's `owner/repo`. Used by `GithubReleaseSource` to
 * decide whether to authenticate a request.
 */
class PatResolver {
	public function __construct(
		private PatMapper $mapper,
	) {
	}

	public function findFor(string $ownerRepo, string $currentUid): ?Pat {
		$now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
		$candidates = $this->mapper->findVisibleTo($currentUid);

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
