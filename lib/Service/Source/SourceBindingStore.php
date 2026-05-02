<?php

declare(strict_types=1);

namespace OCA\AppVersions\Service\Source;

use OCA\AppVersions\AppInfo\Application;
use OCP\IConfig;

/**
 * Reads and writes the per-app source binding stored under app config key
 * `source.{appId}`. Bindings are JSON; absent or invalid values are treated
 * as unbound and the App Store is used as the fallback source.
 */
class SourceBindingStore {
	public function __construct(private IConfig $config) {
	}

	public function get(string $appId): ?SourceBinding {
		$raw = $this->config->getAppValue(Application::APP_ID, $this->key($appId), '');
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

	public function set(string $appId, SourceBinding $binding): void {
		$this->config->setAppValue(
			Application::APP_ID,
			$this->key($appId),
			json_encode($binding->toArray(), JSON_THROW_ON_ERROR)
		);
	}

	public function clear(string $appId): void {
		$this->config->deleteAppValue(Application::APP_ID, $this->key($appId));
	}

	private function key(string $appId): string {
		return 'source.' . $appId;
	}
}
