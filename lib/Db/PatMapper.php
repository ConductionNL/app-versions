<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\Versioniq\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @psalm-api
 *
 * @extends QBMapper<Pat>
 */
class PatMapper extends QBMapper {
	/**
	 * FROZEN ON THE OLD APP ID — deliberately still `app_versions_pats`.
	 *
	 * Database table names are NOT keyed by the Nextcloud app id: the
	 * `app_versions` -> `versioniq` rename does not touch `oc_app_versions_pats`
	 * and every stored PAT is still in it. Renaming the constant would make
	 * this mapper query a table that does not exist — a hard error on read,
	 * and a silent loss of every encrypted token if someone "finished the job"
	 * by adding a CREATE TABLE migration for the new name instead.
	 *
	 * Moving it would need a real, reversible data migration (create, copy,
	 * verify, drop) for zero user-visible benefit: no admin ever sees a table
	 * name. If that migration is ever written, this comment and the matching
	 * literals in lib/Migration/Version10*.php move together — they are the
	 * same identifier on both sides.
	 *
	 * @var string
	 */
	public const TABLE_NAME = 'app_versions_pats';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE_NAME, Pat::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findById(int $id): Pat {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Returns every stored PAT regardless of owner; see "PAT expiry warnings"
	 * (the daily job must sweep all tokens, not just those visible to a uid).
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 * @return list<Pat>
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName);

		return $this->findEntities($qb);
	}

	/**
	 * @return list<Pat>
	 */
	public function findVisibleTo(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->orX(
				$qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('shared_with_admins', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			))
			->orderBy('created_at', 'DESC');

		return $this->findEntities($qb);
	}

	public function deleteByOwner(string $uid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_STR)));

		return $qb->executeStatement();
	}
}
