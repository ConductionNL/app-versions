<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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

	/**
	 * Builds an empty (no-hit, no-error) provider result; see "Provider interface".
	 *
	 * @spec openspec/specs/app-discovery/spec.md
	 */
	public static function empty(): self {
		return new self([], null);
	}

	/**
	 * Builds a failed provider result carrying a surfaced error; see "Provider interface".
	 *
	 * @spec openspec/specs/app-discovery/spec.md
	 */
	public static function failed(string $error): self {
		return new self([], $error);
	}
}
