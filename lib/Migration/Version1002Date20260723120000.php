<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the `app_versions_audit` table backing the audit trail — one immutable
 * row per version operation (install, bind_source, …) with who/what/when.
 *
 * The table name is FROZEN on the pre-rename `app_versions` prefix — see
 * {@see \OCA\Versioniq\Db\AuditEntryMapper::TABLE_NAME}, and the note on
 * {@see \OCA\Versioniq\Migration\Version1000Date20260502120000} about why a
 * shipped migration cannot be edited after the fact.
 *
 * @spec openspec/specs/audit-trail/spec.md
 * @psalm-suppress UnusedClass
 */
class Version1002Date20260723120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable('app_versions_audit')) {
			return null;
		}

		$table = $schema->createTable('app_versions_audit');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
		]);
		$table->addColumn('actor_uid', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('app_id', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		$table->addColumn('operation', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('from_version', Types::STRING, [
			'notnull' => false,
			'length' => 64,
		]);
		$table->addColumn('to_version', Types::STRING, [
			'notnull' => false,
			'length' => 64,
		]);
		$table->addColumn('source_id', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		$table->addColumn('status', Types::STRING, [
			'notnull' => true,
			'length' => 16,
		]);
		$table->addColumn('message', Types::TEXT, [
			'notnull' => false,
		]);
		$table->addColumn('created_at', Types::DATETIME, [
			'notnull' => true,
		]);
		$table->setPrimaryKey(['id']);
		// Nextcloud caps index names at 30 chars (incl. `oc_` prefix on MySQL).
		$table->addIndex(['app_id', 'created_at'], 'av_audit_app_created_idx');
		$table->addIndex(['created_at'], 'av_audit_created_idx');

		return $schema;
	}
}
