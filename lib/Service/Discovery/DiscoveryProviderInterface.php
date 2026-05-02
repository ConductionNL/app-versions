<?php

declare(strict_types=1);

namespace OCA\AppVersions\Service\Discovery;

/**
 * Read-only search driver for one source of installable apps. Used by
 * `DiscoveryAggregator` to assemble the multi-source `/api/discover` response.
 */
interface DiscoveryProviderInterface {
	public function getId(): string;

	public function getLabel(): string;

	/**
	 * Whether this provider can produce results in the current request context.
	 * Disabled providers are skipped silently and are reported with `enabled: false`
	 * in the aggregator's `providers` list so the UI can render an inactive chip.
	 */
	public function isEnabled(): bool;

	public function search(string $query): DiscoveryResult;
}
