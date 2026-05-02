<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Pat>
 */
class PatMapper extends QBMapper {
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

	/**
	 * @return list<Pat>
	 */
	public function findOwnedBy(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_STR)));

		return $this->findEntities($qb);
	}

	public function deleteByOwner(string $uid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_STR)));

		return $qb->executeStatement();
	}
}
