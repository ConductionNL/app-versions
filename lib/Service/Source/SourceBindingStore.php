<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Service\Source;

use OCA\AppVersions\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads and writes the per-app source binding stored under app config key
 * `source.{appId}`. Bindings are JSON; absent or invalid values are treated
 * as unbound and the App Store is used as the fallback source.
 *
 * @psalm-api
 */
class SourceBindingStore {
	public function __construct(
		private IAppConfig $config,
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
	 * Persists a source binding under `source.{appId}`; see "Source binding".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	public function set(string $appId, SourceBinding $binding): void {
		$this->config->setValueString(
			Application::APP_ID,
			$this->key($appId),
			json_encode($binding->toArray(), JSON_THROW_ON_ERROR)
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
