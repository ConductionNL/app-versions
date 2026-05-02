<?php

declare(strict_types=1);

namespace OCA\AppVersions\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the `app_versions_pats` table for PAT storage.
 *
 * @psalm-suppress UnusedClass
 */
class Version1000Date20260502120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('app_versions_pats')) {
			return null;
		}

		$table = $schema->createTable('app_versions_pats');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
		]);
		$table->addColumn('owner_uid', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('label', Types::STRING, [
			'notnull' => true,
			'length' => 128,
		]);
		$table->addColumn('target_pattern', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		$table->addColumn('kind', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('encrypted_token', Types::TEXT, [
			'notnull' => true,
		]);
		$table->addColumn('token_hint', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		// Note: Nextcloud's MigrationService validation rejects a notnull bool
		// column with `default => false`. We omit the default and let the
		// entity assign false on construction; inserts always go through the
		// entity layer, so an unset default doesn't matter at runtime.
		$table->addColumn('shared_with_admins', Types::BOOLEAN, [
			'notnull' => false,
		]);
		$table->addColumn('last_validated_scopes', Types::TEXT, [
			'notnull' => false,
		]);
		$table->addColumn('expires_at', Types::DATETIME, [
			'notnull' => false,
		]);
		$table->addColumn('last_used_at', Types::DATETIME, [
			'notnull' => false,
		]);
		$table->addColumn('created_at', Types::DATETIME, [
			'notnull' => true,
		]);
		$table->setPrimaryKey(['id']);
		// Nextcloud caps index names at 30 chars (incl. `oc_` prefix on MySQL).
		$table->addIndex(['owner_uid'], 'av_pats_owner_idx');
		$table->addIndex(['target_pattern'], 'av_pats_target_idx');

		return $schema;
	}
}
