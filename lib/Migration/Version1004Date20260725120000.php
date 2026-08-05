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
 * It deliberately does NOT set the column NOT NULL. Nextcloud's
 * MigrationService rejects a NOT NULL boolean outright — a `bool` is an
 * integer of length 1 there and the platform cannot store `false` in it:
 *
 *     Column "oc_app_versions_pats"."shared_with_admins" is type Bool
 *     and also NotNull, so it can not store "false".
 *
 * Version1000Date20260502120000 already documents that rule at the point it
 * creates the column; this step broke it, and the exception aborts the whole
 * `occ app:enable` — i.e. the app could not be installed on a fresh
 * Nextcloud 31/32/33 at all. It went unnoticed because no CI job had ever
 * successfully reached `app:enable` (see .github/workflows/code-quality.yml).
 * The default plus the backfill above achieve what the NOT NULL was reaching
 * for: no existing row is NULL, and an insert that omits the field stores
 * `false`.
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
		// Backfill before tightening the column, so setting NOT NULL cannot fail.
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
