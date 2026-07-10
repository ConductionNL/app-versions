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
		private GiteaReleaseSource $gitea,
	) {
	}

	public function get(SourceBinding $binding): SourceInterface {
		return match ($binding->kind) {
			SourceBinding::KIND_APPSTORE => $this->appStore,
			SourceBinding::KIND_GITHUB_RELEASE => $this->github,
			SourceBinding::KIND_GITEA_RELEASE => $this->gitea,
			default => throw new InvalidArgumentException('Unsupported source kind: ' . $binding->kind),
		};
	}

	/**
	 * Available source kinds, in the order the picker UI should display them.
	 *
	 * Order rationale: App Store first (implicit default for every installed
	 * app), Codeberg / Gitea second (recommended for Conduction apps — that's
	 * where the source of truth lives after the ConductionNL GitHub → Codeberg
	 * migration), GitHub third (still supported as an alternative for apps
	 * that publish releases there).
	 *
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
				'id' => 'gitea',
				'kind' => SourceBinding::KIND_GITEA_RELEASE,
				'label' => 'Codeberg / Gitea / Forgejo Releases (recommended)',
			],
			[
				'id' => 'github',
				'kind' => SourceBinding::KIND_GITHUB_RELEASE,
				'label' => 'GitHub Releases',
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

		if (str_starts_with($sourceId, 'gitea:')) {
			$rest = substr($sourceId, strlen('gitea:'));
			$parts = explode('/', $rest);
			if (count($parts) !== 3) {
				throw new InvalidArgumentException('Gitea source id must be of the form gitea:host/owner/repo');
			}
			[$host, $owner, $repo] = $parts;
			if ($host === '' || $owner === '' || $repo === '') {
				throw new InvalidArgumentException('Gitea source id has empty host, owner or repo');
			}

			return SourceBinding::gitea($host, $owner, $repo);
		}

		throw new InvalidArgumentException('Unknown source id: ' . $sourceId);
	}
}
