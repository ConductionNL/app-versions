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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Repairs `app_versions_pats.shared_with_admins`.
 *
 * The column was created `notnull => false` with no default, and the mapper
 * omits the field on insert when it equals the entity default (false), so every
 * stored PAT ended up with a NULL `shared_with_admins`. Reading such a row then
 * fatals — `Pat::$sharedWithAdmins` is a non-nullable `bool` — which 500s the
 * PAT list and PAT resolution the moment any token exists. This backfills the
 * existing NULLs and gives the column a `false` default so an omitted insert
 * stores `false` rather than NULL.
 *
 * The column stays nullable on purpose. Adding NOT NULL as well looked like
 * belt and braces but made the app impossible to install: Nextcloud rejects
 * the schema outright with
 *
 *   Column "oc_app_versions_pats"."shared_with_admins" is type Bool and also
 *   NotNull, so it can not store "false"
 *
 * which is an Oracle-compatibility rule in core, not a quirk of one setup. It
 * fires during `occ app:enable`, so the app could not be enabled at all on a
 * clean Nextcloud 31. The backfill above plus the default below already
 * achieve what the constraint was for: existing NULLs become false, and an
 * insert that omits the field gets false rather than NULL.
 *
 * @spec openspec/specs/pat-management/spec.md
 * @psalm-suppress UnusedClass
 */
class Version1004Date20260725120000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		if (!$schema->hasTable('app_versions_pats')) {
			return;
		}
		// Backfill the NULLs the old insert path left behind, so every row
		// reads as a real boolean once the default is in place.
		$qb = $this->db->getQueryBuilder();
		$qb->update('app_versions_pats')
			->set('shared_with_admins', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->isNull('shared_with_admins'));
		$qb->executeStatement();
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('app_versions_pats')) {
			return null;
		}

		$column = $schema->getTable('app_versions_pats')->getColumn('shared_with_admins');
		$column->setDefault(false);

		return $schema;
	}
}
