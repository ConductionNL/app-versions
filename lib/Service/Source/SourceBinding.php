<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Service\Source;

use InvalidArgumentException;

/**
 * Immutable representation of which source an installed app is bound to.
 *
 * Persisted as a JSON blob under app config key `source.{appId}`.
 */
final class SourceBinding {
	public const KIND_APPSTORE = 'appstore';
	public const KIND_GITHUB_RELEASE = 'github-release';

	/**
	 * Forges a `github-release` binding may target. The `kind` stays
	 * `github-release` for backward compatibility (legacy rows have no forge);
	 * the `forge` config field discriminates GitHub from Codeberg/Forgejo.
	 */
	public const FORGE_GITHUB = 'github';
	public const FORGE_CODEBERG = 'codeberg';
	private const ALLOWED_FORGES = [self::FORGE_GITHUB, self::FORGE_CODEBERG];

	/**
	 * @param array<string, mixed> $config
	 */
	public function __construct(
		public readonly string $kind,
		public readonly array $config = [],
		public readonly ?string $boundAt = null,
	) {
		if ($kind !== self::KIND_APPSTORE && $kind !== self::KIND_GITHUB_RELEASE) {
			throw new InvalidArgumentException('Unknown source kind: ' . $kind);
		}

		if ($kind === self::KIND_GITHUB_RELEASE) {
			if (!isset($config['owner']) || !is_string($config['owner']) || $config['owner'] === '') {
				throw new InvalidArgumentException('github-release binding requires non-empty owner');
			}
			if (!isset($config['repo']) || !is_string($config['repo']) || $config['repo'] === '') {
				throw new InvalidArgumentException('github-release binding requires non-empty repo');
			}
			// Reject path-traversal characters in owner/repo. fnmatch in
			// TrustedSourceList lets `*` match `/`, so `ConductionNL/../../../x`
			// would otherwise pass the allowlist. GitHub's own owner/repo
			// charset is the same as below. CWE-22 / OWASP A01:2021.
			if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $config['owner'])) {
				throw new InvalidArgumentException('github-release owner contains invalid characters');
			}
			if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $config['repo'])) {
				throw new InvalidArgumentException('github-release repo contains invalid characters');
			}
			if (isset($config['forge']) && (!is_string($config['forge']) || !in_array($config['forge'], self::ALLOWED_FORGES, true))) {
				throw new InvalidArgumentException('github-release binding has an unknown forge');
			}
		}
	}

	/**
	 * Returns the forge a `github-release` binding targets (`github`|`codeberg`),
	 * defaulting to `github` when absent (legacy rows). Empty string for
	 * non-release bindings.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function getForge(): string {
		if ($this->kind !== self::KIND_GITHUB_RELEASE) {
			return '';
		}
		/** @var mixed $forge */
		$forge = $this->config['forge'] ?? null;

		return is_string($forge) && $forge !== '' ? $forge : self::FORGE_GITHUB;
	}

	/**
	 * Returns the canonical source id (`appstore` or `github:owner/repo`); see "Source binding".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function getId(): string {
		if ($this->kind === self::KIND_APPSTORE) {
			return 'appstore';
		}

		// Forge-qualified id. For github this is byte-identical to the legacy
		// `github:owner/repo` form (getForge() defaults to github).
		return $this->getForge() . ':' . $this->configString('owner') . '/' . $this->configString('repo');
	}

	/**
	 * Returns the `owner/repo` for github bindings, null otherwise; see "GitHub releases as a source".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function getOwnerRepo(): ?string {
		if ($this->kind !== self::KIND_GITHUB_RELEASE) {
			return null;
		}

		return $this->configString('owner') . '/' . $this->configString('repo');
	}

	public function getAssetPattern(): string {
		/** @var mixed $pattern */
		$pattern = $this->config['assetPattern'] ?? '*.tar.gz';

		return is_string($pattern) && $pattern !== '' ? $pattern : '*.tar.gz';
	}

	/**
	 * Returns a config value as a string, or '' when absent/non-string.
	 */
	private function configString(string $key): string {
		/** @var mixed $value */
		$value = $this->config[$key] ?? null;

		return is_string($value) ? $value : '';
	}

	/**
	 * Serializes the binding to its persisted JSON shape; see "Source binding".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$payload = array_merge(['kind' => $this->kind], $this->config);
		if ($this->boundAt !== null) {
			$payload['boundAt'] = $this->boundAt;
		}

		return $payload;
	}

	/**
	 * Reconstructs a binding from its persisted payload; see "Source binding".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @param array<array-key, mixed> $payload
	 */
	public static function fromArray(array $payload): self {
		$kind = $payload['kind'] ?? null;
		if (!is_string($kind)) {
			throw new InvalidArgumentException('Source binding payload missing "kind"');
		}

		$config = array_filter(
			$payload,
			static fn (int|string $key): bool => is_string($key) && $key !== 'kind' && $key !== 'boundAt',
			ARRAY_FILTER_USE_KEY,
		);
		/** @var array<string, mixed> $config */

		$boundAt = isset($payload['boundAt']) && is_string($payload['boundAt']) ? $payload['boundAt'] : null;

		return new self($kind, $config, $boundAt);
	}

	/**
	 * Builds an App Store binding; see "Source abstraction".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public static function appStore(): self {
		return new self(self::KIND_APPSTORE);
	}

	/**
	 * Builds a validated github-release binding with a boundAt timestamp; see "Source binding".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public static function github(string $owner, string $repo, string $assetPattern = '*.tar.gz'): self {
		return self::forgeRelease(self::FORGE_GITHUB, $owner, $repo, $assetPattern);
	}

	/**
	 * Builds a validated codeberg (Forgejo) release binding; mirrors ::github().
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public static function codeberg(string $owner, string $repo, string $assetPattern = '*.tar.gz'): self {
		return self::forgeRelease(self::FORGE_CODEBERG, $owner, $repo, $assetPattern);
	}

	/**
	 * Shared factory for forge-release bindings with a boundAt timestamp.
	 */
	private static function forgeRelease(string $forge, string $owner, string $repo, string $assetPattern): self {
		return new self(
			self::KIND_GITHUB_RELEASE,
			[
				'forge' => $forge,
				'owner' => $owner,
				'repo' => $repo,
				'assetPattern' => $assetPattern,
			],
			(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
		);
	}
}
