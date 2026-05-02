<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Service\Source;

use OCA\AppVersions\AppInfo\Application;
use OCP\IConfig;

/**
 * Reads and enforces the trusted-source allowlist. Bindings whose owner/repo
 * does not match any configured glob are rejected before any HTTP fetch or
 * filesystem write happens.
 */
class TrustedSourceList {
	private const CONFIG_KEY = 'trusted_sources';

	/** @var list<string> */
	private const DEFAULT_PATTERNS = ['ConductionNL/*'];

	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * @return list<string>
	 */
	public function getPatterns(): array {
		$raw = $this->config->getAppValue(Application::APP_ID, self::CONFIG_KEY, '');
		if ($raw === '') {
			return self::DEFAULT_PATTERNS;
		}

		try {
			$decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return self::DEFAULT_PATTERNS;
		}

		if (!is_array($decoded)) {
			return self::DEFAULT_PATTERNS;
		}

		$patterns = [];
		foreach ($decoded as $entry) {
			if (is_string($entry) && trim($entry) !== '') {
				$patterns[] = trim($entry);
			}
		}

		return $patterns === [] ? self::DEFAULT_PATTERNS : $patterns;
	}

	/**
	 * @param list<string> $patterns
	 */
	public function setPatterns(array $patterns): void {
		$cleaned = [];
		foreach ($patterns as $entry) {
			if (is_string($entry) && trim($entry) !== '') {
				$cleaned[] = trim($entry);
			}
		}

		$this->config->setAppValue(
			Application::APP_ID,
			self::CONFIG_KEY,
			json_encode($cleaned, JSON_THROW_ON_ERROR)
		);
	}

	public function isAllowed(string $sourceId): bool {
		$ownerRepo = $this->extractOwnerRepo($sourceId);
		if ($ownerRepo === null) {
			return $sourceId === 'appstore';
		}

		foreach ($this->getPatterns() as $pattern) {
			if (fnmatch($pattern, $ownerRepo, FNM_NOESCAPE)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @throws UntrustedSourceException
	 */
	public function assertAllowed(string $sourceId): void {
		if (!$this->isAllowed($sourceId)) {
			throw new UntrustedSourceException(
				$sourceId,
				sprintf(
					'allowlist patterns: %s',
					implode(', ', $this->getPatterns())
				)
			);
		}
	}

	public function assertBindingAllowed(SourceBinding $binding): void {
		$this->assertAllowed($binding->getId());
	}

	private function extractOwnerRepo(string $sourceId): ?string {
		if (!str_starts_with($sourceId, 'github:')) {
			return null;
		}

		$ownerRepo = substr($sourceId, strlen('github:'));
		if (!str_contains($ownerRepo, '/')) {
			return null;
		}

		[$owner, $repo] = explode('/', $ownerRepo, 2);
		if ($owner === '' || $repo === '') {
			return null;
		}

		return $owner . '/' . $repo;
	}
}
