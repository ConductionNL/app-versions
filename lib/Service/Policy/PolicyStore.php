<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\AppVersions\Service\Policy;

use OCA\AppVersions\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes per-app auto-update policies stored under app config key
 * `policy.{appId}`. Policies are JSON; a missing or malformed value is
 * treated as "no policy" (level `none`), logged, never fatal — mirrors
 * {@see \OCA\AppVersions\Service\Pin\PinStore} and
 * {@see \OCA\AppVersions\Service\Source\SourceBindingStore}.
 *
 * @psalm-api
 */
class PolicyStore {
	private const KEY_PREFIX = 'policy.';

	public function __construct(
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reads the persisted policy for an app (null if unset/invalid); see "Per-app update policy".
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function get(string $appId): ?Policy {
		$raw = $this->config->getValueString(Application::APP_ID, $this->key($appId), '');
		if ($raw === '') {
			return null;
		}

		return $this->decode($appId, $raw);
	}

	/**
	 * The effective policy level for an app — `none` when no policy is stored
	 * or the stored value is malformed; see "Per-app update policy" ("Absent
	 * policy means none").
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function levelFor(string $appId): string {
		return $this->get($appId)?->level ?? Policy::LEVEL_NONE;
	}

	/**
	 * Returns every persisted policy, keyed by app id.
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 * @return array<string, Policy>
	 */
	public function all(): array {
		$values = $this->config->getAllValues(Application::APP_ID, self::KEY_PREFIX);
		$policies = [];
		foreach ($values as $key => $value) {
			if (!str_starts_with($key, self::KEY_PREFIX)) {
				continue;
			}
			if (!is_string($value) || $value === '') {
				continue;
			}
			$appId = substr($key, strlen(self::KEY_PREFIX));
			if ($appId === '') {
				continue;
			}
			$policy = $this->decode($appId, $value);
			if ($policy !== null) {
				$policies[$appId] = $policy;
			}
		}

		return $policies;
	}

	/**
	 * Persists a policy; see "Per-app update policy" ("Set a patch policy").
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function set(string $appId, Policy $policy): void {
		$this->config->setValueString(
			Application::APP_ID,
			$this->key($appId),
			json_encode($policy->toArray(), JSON_THROW_ON_ERROR)
		);
	}

	/**
	 * Removes an app's policy (a no-op when none exists); see "Per-app update policy".
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public function clear(string $appId): void {
		$this->config->deleteKey(Application::APP_ID, $this->key($appId));
	}

	private function decode(string $appId, string $raw): ?Policy {
		try {
			$decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException $error) {
			$this->logger->warning('PolicyStore: malformed policy JSON, treating as none', [
				'appId' => $appId,
				'message' => $error->getMessage(),
			]);

			return null;
		}

		if (!is_array($decoded)) {
			return null;
		}

		try {
			return Policy::fromArray($decoded);
		} catch (\InvalidArgumentException $error) {
			$this->logger->warning('PolicyStore: invalid policy payload, treating as none', [
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
