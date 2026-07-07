<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\AppVersions\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the `forge` column to `app_versions_pats` so a stored access token is
 * attributed to a forge (GitHub or Codeberg/Forgejo). Existing rows default to
 * `github`, preserving backward compatibility with pre-forge PATs.
 *
 * @spec openspec/specs/pat-management/spec.md
 * @psalm-suppress UnusedClass
 */
class Version1001Date20260609120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if (!$schema->hasTable('app_versions_pats')) {
			return null;
		}

		$table = $schema->getTable('app_versions_pats');
		// Doctrine\DBAL\Schema\Table::hasColumn exists at runtime; the bundled
		// DBAL stubs psalm sees don't declare it.
		/** @psalm-suppress UndefinedMethod */
		if ($table->hasColumn('forge')) {
			return null;
		}

		$table->addColumn('forge', Types::STRING, [
			'notnull' => true,
			'length' => 16,
			'default' => 'github',
		]);

		return $schema;
	}
}
