<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\AppVersions\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the `warned_thresholds` column to `app_versions_pats` — a JSON array
 * (e.g. `["14d","3d","expired"]`) recording which expiry-warning thresholds
 * have already fired for a token, so `PatExpiryWarningJob` notifies at most
 * once per threshold. Existing rows default to `[]`.
 *
 * @spec openspec/specs/pat-management/spec.md
 * @psalm-suppress UnusedClass
 */
class Version1002Date20260723120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('app_versions_pats')) {
			return null;
		}

		$table = $schema->getTable('app_versions_pats');
		// Doctrine\DBAL\Schema\Table::hasColumn exists at runtime; the bundled
		// DBAL stubs psalm sees don't declare it.
		/** @psalm-suppress UndefinedMethod */
		if ($table->hasColumn('warned_thresholds')) {
			return null;
		}

		$table->addColumn('warned_thresholds', Types::TEXT, [
			'notnull' => true,
			'default' => '[]',
		]);

		return $schema;
	}
}
