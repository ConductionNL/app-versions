<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Service\Discovery;

/**
 * Envelope returned by every discovery provider.
 *
 * `error` is set when the provider failed transiently (rate-limited, network
 * error, malformed payload) without crashing the whole search. The aggregator
 * surfaces these in the final response so the admin can see "App Store
 * worked, GitHub timed out" rather than a single fatal error.
 */
final class DiscoveryResult {
	/**
	 * @param list<DiscoveryHit> $hits
	 */
	public function __construct(
		public readonly array $hits,
		public readonly ?string $error = null,
	) {
	}

	public static function empty(): self {
		return new self([], null);
	}

	public static function failed(string $error): self {
		return new self([], $error);
	}
}
