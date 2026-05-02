<?php

declare(strict_types=1);

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
		}
	}

	public function getId(): string {
		if ($this->kind === self::KIND_APPSTORE) {
			return 'appstore';
		}

		return 'github:' . $this->config['owner'] . '/' . $this->config['repo'];
	}

	public function getOwnerRepo(): ?string {
		if ($this->kind !== self::KIND_GITHUB_RELEASE) {
			return null;
		}

		return $this->config['owner'] . '/' . $this->config['repo'];
	}

	public function getAssetPattern(): string {
		$pattern = $this->config['assetPattern'] ?? '*.tar.gz';

		return is_string($pattern) && $pattern !== '' ? $pattern : '*.tar.gz';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$payload = ['kind' => $this->kind];
		foreach ($this->config as $key => $value) {
			$payload[$key] = $value;
		}
		if ($this->boundAt !== null) {
			$payload['boundAt'] = $this->boundAt;
		}

		return $payload;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function fromArray(array $payload): self {
		$kind = $payload['kind'] ?? null;
		if (!is_string($kind)) {
			throw new InvalidArgumentException('Source binding payload missing "kind"');
		}

		$config = $payload;
		unset($config['kind'], $config['boundAt']);

		$boundAt = isset($payload['boundAt']) && is_string($payload['boundAt']) ? $payload['boundAt'] : null;

		return new self($kind, $config, $boundAt);
	}

	public static function appStore(): self {
		return new self(self::KIND_APPSTORE);
	}

	public static function github(string $owner, string $repo, string $assetPattern = '*.tar.gz'): self {
		return new self(
			self::KIND_GITHUB_RELEASE,
			[
				'owner' => $owner,
				'repo' => $repo,
				'assetPattern' => $assetPattern,
			],
			(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
		);
	}
}
