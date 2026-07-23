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

use Exception;

/**
 * Thrown by {@see \OCA\AppVersions\Service\ExternalReleaseInstallerService}
 * when the SHA-256 of a freshly downloaded artifact does not match the digest
 * recorded on the binding at a previous successful install of the same
 * (appId, version, source) triple. Thrown before extraction/backup — no
 * filesystem change has happened when this is raised. The HTTP layer maps
 * this to the machine-readable `sha_mismatch` error code; see "Recorded
 * SHA-256 enforced on reinstall".
 *
 * @spec openspec/specs/external-sources/spec.md
 * @psalm-api
 */
final class ShaMismatchException extends Exception {
	public function __construct(
		public readonly string $appId,
		public readonly string $version,
		public readonly string $expectedSha,
		public readonly string $actualSha,
	) {
		parent::__construct(sprintf(
			'Artifact for %s@%s does not match the checksum recorded at first install (expected %s, got %s). The release may have been rewritten upstream.',
			$appId,
			$version,
			$expectedSha,
			$actualSha,
		));
	}
}
