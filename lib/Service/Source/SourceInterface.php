<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Service\Source;

/**
 * Read-only driver that knows how to list and resolve releases for a given
 * source binding. Drivers do not perform any installation themselves —
 * installation is delegated to either `SelectedReleaseInstallerService`
 * (for the App Store path with full code-signing) or
 * `ExternalReleaseInstallerService` (for unsigned / external sources).
 */
interface SourceInterface {
	public const INSTALLER_SIGNED = 'signed';
	public const INSTALLER_EXTERNAL = 'external';

	public function getKind(): string;

	public function getInstallerKind(): string;

	/**
	 * Lists available versions for the given app under this source binding.
	 *
	 * Implementations MUST NOT throw on transient errors (rate limits,
	 * 404s, network failures); they MUST return an empty list with a
	 * populated `error` field in the result envelope so the caller can
	 * surface the message to the admin.
	 *
	 * @return array{versions: list<array{version: string}>, error: ?string}
	 */
	public function listVersions(string $appId, SourceBinding $binding): array;

	/**
	 * Resolves a single release into a payload usable by the installer.
	 *
	 * The shape varies by source kind:
	 *   - App Store releases include `download`, `signature`, `certificate`, `version`
	 *   - GitHub releases include `download`, `version`, optional `sha256Url`
	 *
	 * @return array<string, mixed>|null
	 */
	public function resolveRelease(string $appId, string $version, SourceBinding $binding): ?array;
}
