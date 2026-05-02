<?php

declare(strict_types=1);

namespace OCA\AppVersions\Service\Discovery;

/**
 * One result from a discovery provider.
 *
 * `appId` may be a best-effort guess (e.g. derived from the GitHub repo name)
 * for sources that don't explicitly publish an app id; downstream code should
 * not rely on uniqueness of `appId` across providers, only within a provider's
 * own response.
 */
final class DiscoveryHit {
	/**
	 * @param array<string, mixed> $sourceBinding
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $name,
		public readonly string $summary,
		public readonly ?string $iconUrl,
		public readonly string $sourceProviderId,
		public readonly array $sourceBinding,
		public readonly bool $installable,
		public readonly ?string $installableReason,
		public readonly ?string $homepageUrl,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'appId' => $this->appId,
			'name' => $this->name,
			'summary' => $this->summary,
			'iconUrl' => $this->iconUrl,
			'sourceProviderId' => $this->sourceProviderId,
			'sourceBinding' => $this->sourceBinding,
			'installable' => $this->installable,
			'installableReason' => $this->installableReason,
			'homepageUrl' => $this->homepageUrl,
		];
	}
}
