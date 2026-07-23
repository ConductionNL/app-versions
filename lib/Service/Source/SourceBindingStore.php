<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\AppVersions\Service\Source;

use OCA\AppVersions\AppInfo\Application;
use OCA\AppVersions\Service\Audit\AuditLogger;
use OCP\IAppConfig;
use OCP\IUserSession;

/**
 * Reads and writes the per-app source binding stored under app config key
 * `source.{appId}`. Bindings are JSON; absent or invalid values are treated
 * as unbound and the App Store is used as the fallback source.
 *
 * `set()` is the single write path for a source binding — it is called both
 * by the explicit `POST /api/source/{appId}/bind` endpoint and by the
 * implicit binding an install performs on success — so recording the
 * `bind_source` audit entry here covers both call sites with one hook and
 * guarantees exactly one entry per logical binding write; see "Source
 * binding changes are recorded".
 *
 * @psalm-api
 */
class SourceBindingStore {
	public function __construct(
		private IAppConfig $config,
		private AuditLogger $auditLogger,
		private IUserSession $userSession,
	) {
	}

	/**
	 * Reads the persisted source binding for an app (null if unbound/invalid); see "Source binding".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function get(string $appId): ?SourceBinding {
		$raw = $this->config->getValueString(Application::APP_ID, $this->key($appId), '');
		if ($raw === '') {
			return null;
		}

		try {
			$decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}

		if (!is_array($decoded)) {
			return null;
		}

		try {
			return SourceBinding::fromArray($decoded);
		} catch (\InvalidArgumentException) {
			return null;
		}
	}

	/**
	 * Persists a source binding under `source.{appId}`; see "Source binding"
	 * and "Source binding changes are recorded".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 * @spec openspec/specs/audit-trail/spec.md
	 */
	public function set(string $appId, SourceBinding $binding): void {
		$previous = $this->get($appId);

		$this->config->setValueString(
			Application::APP_ID,
			$this->key($appId),
			json_encode($binding->toArray(), JSON_THROW_ON_ERROR)
		);

		$actorUid = $this->userSession->getUser()?->getUID() ?? 'system';
		$message = ($previous !== null && $previous->getId() !== $binding->getId())
			? sprintf('Rebound from %s', $previous->getId())
			: null;

		$this->auditLogger->record(
			$actorUid,
			$appId,
			AuditLogger::OPERATION_BIND_SOURCE,
			null,
			null,
			$binding->getId(),
			AuditLogger::STATUS_SUCCESS,
			$message,
		);
	}

	/**
	 * Removes an app's source binding; see "Source binding".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function clear(string $appId): void {
		$this->config->deleteKey(Application::APP_ID, $this->key($appId));
	}

	private function key(string $appId): string {
		return 'source.' . $appId;
	}
}
