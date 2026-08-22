<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Service\AutoUpdate;

use OCA\Versioniq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Bounds {@see \OCA\Versioniq\BackgroundJob\AutoUpdateJob}'s never-retry
 * rule without a new table: records one attempt per (appId, version) under
 * app config key `auto_attempt.{appId}` as a JSON map
 * `version => {at, outcome}`, pruned to the most recent
 * {@see self::MAX_ENTRIES} entries per app so the config value never grows
 * unbounded.
 *
 * @psalm-api
 */
class AttemptLedger {
	private const KEY_PREFIX = 'auto_attempt.';
	private const MAX_ENTRIES = 10;

	public const OUTCOME_SUCCESS = 'success';
	public const OUTCOME_FAILURE = 'failure';

	public function __construct(
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether `$version` was already attempted for `$appId`; see "Nightly
	 * policy execution through the standard installer" ("Failed attempt is
	 * not retried").
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function hasAttempted(string $appId, string $version): bool {
		return array_key_exists($version, $this->all($appId));
	}

	/**
	 * Records the outcome of an attempted (appId, version) install, pruning
	 * to the most recent {@see self::MAX_ENTRIES} entries; see "Nightly
	 * policy execution through the standard installer".
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 * @param self::OUTCOME_* $outcome
	 */
	public function record(string $appId, string $version, string $outcome, string $at): void {
		$entries = $this->all($appId);
		$entries[$version] = ['at' => $at, 'outcome' => $outcome];

		if (count($entries) > self::MAX_ENTRIES) {
			uasort($entries, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
			$entries = array_slice($entries, -self::MAX_ENTRIES, null, true);
		}

		$this->config->setValueString(
			Application::APP_ID,
			self::KEY_PREFIX . $appId,
			json_encode($entries, JSON_THROW_ON_ERROR)
		);
	}

	/**
	 * @return array<string, array{at:string, outcome:string}>
	 */
	private function all(string $appId): array {
		$raw = $this->config->getValueString(Application::APP_ID, self::KEY_PREFIX . $appId, '');
		if ($raw === '') {
			return [];
		}

		try {
			$decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException $error) {
			$this->logger->warning('AttemptLedger: malformed ledger JSON, treating as empty', [
				'appId' => $appId,
				'message' => $error->getMessage(),
			]);

			return [];
		}

		if (!is_array($decoded)) {
			return [];
		}

		$entries = [];
		foreach ($decoded as $version => $entry) {
			if (
				is_string($version) && $version !== ''
				&& is_array($entry)
				&& isset($entry['at'], $entry['outcome'])
				&& is_string($entry['at']) && is_string($entry['outcome'])
			) {
				$entries[$version] = ['at' => $entry['at'], 'outcome' => $entry['outcome']];
			}
		}

		return $entries;
	}
}
