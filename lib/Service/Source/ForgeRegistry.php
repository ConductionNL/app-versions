<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Service\Source;

use InvalidArgumentException;

/**
 * Holds the known git forges. Adding a forge is a config entry here, not a new
 * class — the driver and validator read {@see Forge} fields.
 *
 * @spec openspec/specs/external-sources/spec.md
 * @psalm-api
 */
class ForgeRegistry {
	public const FORGE_GITHUB = 'github';
	public const FORGE_CODEBERG = 'codeberg';

	/** @var array<string, Forge> */
	private array $forges;

	public function __construct() {
		$this->forges = [
			self::FORGE_GITHUB => new Forge(
				self::FORGE_GITHUB,
				'https://api.github.com',
				'https://github.com',
				Forge::SCHEME_BEARER,
				true,
				'https://github.com/settings/tokens',
			),
			self::FORGE_CODEBERG => new Forge(
				self::FORGE_CODEBERG,
				'https://codeberg.org/api/v1',
				'https://codeberg.org',
				Forge::SCHEME_TOKEN,
				false,
				'https://codeberg.org/user/settings/applications',
			),
		];
	}

	public function has(string $forgeId): bool {
		return isset($this->forges[$forgeId]);
	}

	/**
	 * @throws InvalidArgumentException when the forge is unknown
	 */
	public function get(string $forgeId): Forge {
		if (!isset($this->forges[$forgeId])) {
			throw new InvalidArgumentException('Unknown forge: ' . $forgeId);
		}

		return $this->forges[$forgeId];
	}

	/**
	 * @return list<string>
	 */
	public function ids(): array {
		return array_keys($this->forges);
	}
}
