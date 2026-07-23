<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\AppVersions\Service\Pin;

use OCA\AppVersions\AppInfo\Application;
use OCA\AppVersions\Service\Audit\AuditLogger;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes per-app pins stored under app config key `pin.{appId}`.
 * Pins are JSON; a missing or malformed value is treated as "not pinned"
 * (logged, never fatal) — mirrors
 * {@see \OCA\AppVersions\Service\Source\SourceBindingStore}.
 *
 * `set()`/`clear()`/`markDrift()` are the only write paths and each records
 * exactly one audit entry (`pin` / `unpin` / `pin_drift`), so every caller —
 * the pin API, the install-path guard's override handling, and the drift
 * handler — gets audit coverage for free.
 *
 * @psalm-api
 */
class PinStore {
	private const KEY_PREFIX = 'pin.';

	public function __construct(
		private IAppConfig $config,
		private AuditLogger $auditLogger,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reads the persisted pin for an app (null if unpinned/invalid); see "Pin an installed app to its current version".
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	public function get(string $appId): ?Pin {
		$raw = $this->config->getValueString(Application::APP_ID, $this->key($appId), '');
		if ($raw === '') {
			return null;
		}

		return $this->decode($appId, $raw);
	}

	/**
	 * Returns every persisted pin, keyed by app id; see "Honest pin presentation".
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 * @return array<string, Pin>
	 */
	public function all(): array {
		$values = $this->config->getAllValues(Application::APP_ID, self::KEY_PREFIX);
		$pins = [];
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
			$pin = $this->decode($appId, $value);
			if ($pin !== null) {
				$pins[$appId] = $pin;
			}
		}

		return $pins;
	}

	/**
	 * Persists a fresh pin (drift markers cleared) and records a `pin` audit
	 * entry; see "Pin an installed app to its current version" and "Pin
	 * lifecycle operations are audited".
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 * @spec openspec/specs/audit-trail/spec.md
	 */
	public function set(string $appId, Pin $pin): void {
		$this->write($appId, $pin->withoutDrift());

		$this->auditLogger->record(
			$pin->pinnedBy,
			$appId,
			AuditLogger::OPERATION_PIN,
			null,
			$pin->version,
			null,
			AuditLogger::STATUS_SUCCESS,
			$pin->reason,
		);
	}

	/**
	 * Removes an app's pin and records an `unpin` audit entry (a no-op, no
	 * audit entry, when no pin exists); see "Unpin" and "Pin lifecycle
	 * operations are audited".
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 * @spec openspec/specs/audit-trail/spec.md
	 */
	public function clear(string $appId, string $actorUid): void {
		$previous = $this->get($appId);
		if ($previous === null) {
			return;
		}

		$this->config->deleteKey(Application::APP_ID, $this->key($appId));

		$this->auditLogger->record(
			$actorUid,
			$appId,
			AuditLogger::OPERATION_UNPIN,
			$previous->version,
			null,
			null,
			AuditLogger::STATUS_SUCCESS,
			null,
		);
	}

	/**
	 * Records newly-detected drift on an existing pin (idempotent per
	 * drifted version — a repeated call with the same `$driftedTo` is a
	 * no-op) and, on a genuine new drift, records a `pin_drift` audit entry
	 * with `actor_uid=system`; see "Drift detection" and "Drift handled once
	 * per version". Returns true when this call newly recorded drift.
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 * @spec openspec/specs/audit-trail/spec.md
	 */
	public function markDrift(string $appId, string $driftedTo, string $driftedAt): bool {
		$pin = $this->get($appId);
		if ($pin === null) {
			return false;
		}
		if ($pin->driftedTo === $driftedTo) {
			// Already recorded for this exact drifted version — idempotent no-op.
			return false;
		}

		$this->write($appId, $pin->withDrift($driftedTo, $driftedAt));

		$this->auditLogger->record(
			'system',
			$appId,
			AuditLogger::OPERATION_PIN_DRIFT,
			$pin->version,
			$driftedTo,
			null,
			AuditLogger::STATUS_SUCCESS,
			null,
		);

		return true;
	}

	private function write(string $appId, Pin $pin): void {
		$this->config->setValueString(
			Application::APP_ID,
			$this->key($appId),
			json_encode($pin->toArray(), JSON_THROW_ON_ERROR)
		);
	}

	private function decode(string $appId, string $raw): ?Pin {
		try {
			$decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException $error) {
			$this->logger->warning('PinStore: malformed pin JSON, treating as unpinned', [
				'appId' => $appId,
				'message' => $error->getMessage(),
			]);

			return null;
		}

		if (!is_array($decoded)) {
			return null;
		}

		try {
			return Pin::fromArray($decoded);
		} catch (\InvalidArgumentException $error) {
			$this->logger->warning('PinStore: invalid pin payload, treating as unpinned', [
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
