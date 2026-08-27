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

/**
 * Pure `HH:MM-HH:MM` maintenance-window parsing and containment logic for
 * {@see \OCA\Versioniq\BackgroundJob\AutoUpdateJob}. Supports windows that
 * cross midnight (e.g. `23:00-03:00`); see "Global kill switch and window"
 * ("Midnight-crossing window").
 *
 * @psalm-api
 */
final class AutoUpdateWindow {
	public const DEFAULT_WINDOW = '01:00-05:00';

	private const PATTERN = '/^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]):([0-5]\d)$/';

	/**
	 * Whether `$window` is a syntactically valid `HH:MM-HH:MM` window.
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public static function isValid(string $window): bool {
		return preg_match(self::PATTERN, trim($window)) === 1;
	}

	/**
	 * Whether `$now` falls inside `$window`, handling windows that cross
	 * midnight; a malformed or zero-width window is treated as never
	 * inside (fail-safe — never silently "always on"); see "Global kill
	 * switch and window".
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	public static function isWithin(string $window, \DateTimeInterface $now): bool {
		if (preg_match(self::PATTERN, trim($window), $matches) !== 1) {
			return false;
		}

		$start = ((int)$matches[1]) * 60 + (int)$matches[2];
		$end = ((int)$matches[3]) * 60 + (int)$matches[4];
		$current = ((int)$now->format('H')) * 60 + (int)$now->format('i');

		if ($start === $end) {
			return false;
		}

		if ($start < $end) {
			return $current >= $start && $current < $end;
		}

		// Crosses midnight (e.g. 23:00-03:00).
		return $current >= $start || $current < $end;
	}
}
