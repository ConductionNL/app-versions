<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


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

	/**
	 * Resolves a binding to its concrete source driver; see "Source abstraction".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function get(SourceBinding $binding): SourceInterface {
		return match ($binding->kind) {
			SourceBinding::KIND_APPSTORE => $this->appStore,
			SourceBinding::KIND_GITHUB_RELEASE => $this->github,
			default => throw new InvalidArgumentException('Unsupported source kind: ' . $binding->kind),
		};
	}

	/**
	 * Lists the registered source kinds for the UI; see "Source management API".
	 *
	 * @spec openspec/specs/external-sources/spec.md
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

	/**
	 * Parses a source-id string (`appstore` / `github:owner/repo`) into a binding; see "Explicit source override".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
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
