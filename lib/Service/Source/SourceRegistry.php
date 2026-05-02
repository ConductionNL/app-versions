<?php

declare(strict_types=1);

namespace OCA\AppVersions\Service\Source;

use InvalidArgumentException;

/**
 * Maps a `SourceBinding` to the concrete driver that knows how to talk to
 * that origin. Drivers are stateless DI singletons; the binding carries the
 * per-app configuration (owner/repo/assetPattern) into the driver.
 */
class SourceRegistry {
	public function __construct(
		private AppStoreSource $appStore,
		private GithubReleaseSource $github,
	) {
	}

	public function get(SourceBinding $binding): SourceInterface {
		return match ($binding->kind) {
			SourceBinding::KIND_APPSTORE => $this->appStore,
			SourceBinding::KIND_GITHUB_RELEASE => $this->github,
			default => throw new InvalidArgumentException('Unsupported source kind: ' . $binding->kind),
		};
	}

	/**
	 * @return list<array{id: string, kind: string, label: string}>
	 */
	public function listAvailable(): array {
		return [
			[
				'id' => 'appstore',
				'kind' => SourceBinding::KIND_APPSTORE,
				'label' => 'Nextcloud App Store',
			],
			[
				'id' => 'github',
				'kind' => SourceBinding::KIND_GITHUB_RELEASE,
				'label' => 'GitHub Releases (public)',
			],
		];
	}

	public static function parseSourceId(string $sourceId): SourceBinding {
		$sourceId = trim($sourceId);
		if ($sourceId === '' || $sourceId === 'appstore') {
			return SourceBinding::appStore();
		}

		if (str_starts_with($sourceId, 'github:')) {
			$ownerRepo = substr($sourceId, strlen('github:'));
			if (!str_contains($ownerRepo, '/')) {
				throw new InvalidArgumentException('GitHub source id must be of the form github:owner/repo');
			}
			[$owner, $repo] = explode('/', $ownerRepo, 2);
			if ($owner === '' || $repo === '') {
				throw new InvalidArgumentException('GitHub source id has empty owner or repo');
			}

			return SourceBinding::github($owner, $repo);
		}

		throw new InvalidArgumentException('Unknown source id: ' . $sourceId);
	}
}
