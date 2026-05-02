<?php

declare(strict_types=1);

namespace OCA\AppVersions\Service\Source;

use RuntimeException;

/**
 * Thrown when an install or version-list request targets a source not in the
 * configured trusted-source allowlist. The HTTP layer maps this to 403.
 */
final class UntrustedSourceException extends RuntimeException {
	public function __construct(public readonly string $sourceId, ?string $reason = null) {
		parent::__construct(
			$reason !== null
				? sprintf('Source "%s" is not in the trusted-source allowlist: %s', $sourceId, $reason)
				: sprintf('Source "%s" is not in the trusted-source allowlist.', $sourceId)
		);
	}
}
