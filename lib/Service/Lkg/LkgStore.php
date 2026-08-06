<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\AppVersions\Service\Lkg;

use OCA\AppVersions\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes the per-app last-known-good version record stored under
 * app config key `lkg.{appId}`. JSON; a missing or malformed value is
 * treated as "no record yet" (logged, never fatal) — mirrors
 * {@see \OCA\AppVersions\Service\Pin\PinStore}.
 *
 * `set()` is called exactly once per successful finalize, from
 * {@see \OCA\AppVersions\Service\Installer\InstallFinalizer::finalize()} —
 * the single choke point shared by both installers — so a failed or
 * reverted install never touches the record; see "Last-known-good version
 * record".
 *
 * @spec openspec/specs/migration-safety/spec.md
 * @psalm-api
 */
class LkgStore {
	private const KEY_PREFIX = 'lkg.';

	public function __construct(
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reads the persisted last-known-good record for an app (null if none/invalid); see
	 * "Last-known-good version record".
	 *
	 * @spec openspec/specs/migration-safety/spec.md
	 */
	public function get(string $appId): ?Lkg {
		$raw = $this->config->getValueString(Application::APP_ID, $this->key($appId), '');
		if ($raw === '') {
			return null;
		}

		return $this->decode($appId, $raw);
	}

	/**
	 * Persists the last-known-good record after a successful finalize; see
	 * "Last-known-good version record".
	 *
	 * @spec openspec/specs/migration-safety/spec.md
	 */
	public function set(string $appId, Lkg $lkg): void {
		$this->config->setValueString(
			Application::APP_ID,
			$this->key($appId),
			json_encode($lkg->toArray(), JSON_THROW_ON_ERROR)
		);
	}

	private function decode(string $appId, string $raw): ?Lkg {
		try {
			$decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException $error) {
			$this->logger->warning('LkgStore: malformed last-known-good JSON, treating as absent', [
				'appId' => $appId,
				'message' => $error->getMessage(),
			]);

			return null;
		}

		if (!is_array($decoded)) {
			return null;
		}

		try {
			return Lkg::fromArray($decoded);
		} catch (\InvalidArgumentException $error) {
			$this->logger->warning('LkgStore: invalid last-known-good payload, treating as absent', [
				'appId' => $appId,
				'message' => $error->getMessage(),
			]);

			return null;
		}
	}

	private function key(string $appId): string {
		return self::KEY_PREFIX . $appId;
	}
}
