<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\Source;

use RuntimeException;

/**
 * Thrown when an install or version-list request targets a source not in the
 * configured trusted-source allowlist. The HTTP layer maps this to 403.
 *
 * @psalm-api
 */
final class UntrustedSourceException extends RuntimeException {
	public function __construct(
		public readonly string $sourceId,
		?string $reason = null,
	) {
		parent::__construct(
			$reason !== null
				? sprintf('Source "%s" is not in the trusted-source allowlist: %s', $sourceId, $reason)
				: sprintf('Source "%s" is not in the trusted-source allowlist.', $sourceId)
		);
	}
}
