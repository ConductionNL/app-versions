<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\AppVersions\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Stored Personal Access Token row.
 *
 * @psalm-api
 *
 * The inherited OCP\AppFramework\Db\Entity::$id is declared but not initialised
 * by the base constructor; it is populated by the mapper on insert/load.
 * @psalm-suppress PropertyNotSetInConstructor
 *
 * @method string getOwnerUid()
 * @method void setOwnerUid(string $value)
 * @method string getLabel()
 * @method void setLabel(string $value)
 * @method string getTargetPattern()
 * @method void setTargetPattern(string $value)
 * @method string getKind()
 * @method void setKind(string $value)
 * @method string getEncryptedToken()
 * @method void setEncryptedToken(string $value)
 * @method string getTokenHint()
 * @method void setTokenHint(string $value)
 * @method bool getSharedWithAdmins()
 * @method void setSharedWithAdmins(bool $value)
 * @method ?string getLastValidatedScopes()
 * @method void setLastValidatedScopes(?string $value)
 * @method ?string getExpiresAt()
 * @method void setExpiresAt(?string $value)
 * @method ?string getLastUsedAt()
 * @method void setLastUsedAt(?string $value)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $value)
 * @method string getForge()
 * @method void setForge(string $value)
 * @method string getWarnedThresholds()
 * @method void setWarnedThresholds(string $value)
 */
class Pat extends Entity {
	public const KIND_CLASSIC = 'classic';
	public const KIND_FINE_GRAINED = 'fine-grained';
	/** Opaque forge access token (e.g. Codeberg/Forgejo) — no holder-visible scopes. */
	public const KIND_FORGE_TOKEN = 'forge-token';

	protected string $ownerUid = '';
	protected string $label = '';
	protected string $targetPattern = '';
	protected string $kind = '';
	protected string $forge = 'github';
	protected string $encryptedToken = '';
	protected string $tokenHint = '';
	protected bool $sharedWithAdmins = false;
	protected ?string $lastValidatedScopes = null;
	protected ?string $expiresAt = null;
	protected ?string $lastUsedAt = null;
	protected string $createdAt = '';
	/** JSON array of expiry-warning thresholds already notified, e.g. `["14d","3d"]`. */
	protected string $warnedThresholds = '[]';

	public function __construct() {
		$this->addType('ownerUid', 'string');
		$this->addType('label', 'string');
		$this->addType('targetPattern', 'string');
		$this->addType('kind', 'string');
		$this->addType('forge', 'string');
		$this->addType('encryptedToken', 'string');
		$this->addType('tokenHint', 'string');
		$this->addType('sharedWithAdmins', 'boolean');
		$this->addType('lastValidatedScopes', 'string');
		$this->addType('expiresAt', 'string');
		$this->addType('lastUsedAt', 'string');
		$this->addType('createdAt', 'string');
		$this->addType('warnedThresholds', 'string');
	}

	/**
	 * Decodes the persisted threshold ledger; see "PAT expiry warnings".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 * @return list<string>
	 */
	public function getWarnedThresholdsList(): array {
		$raw = $this->warnedThresholds;
		if (!is_string($raw) || $raw === '') {
			return [];
		}
		/** @var mixed $decoded */
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}

		return array_values(array_filter($decoded, static fn (mixed $v): bool => is_string($v)));
	}

	/**
	 * Whether a given threshold has already been notified for this token; see "PAT expiry warnings".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	public function hasWarnedThreshold(string $threshold): bool {
		return in_array($threshold, $this->getWarnedThresholdsList(), true);
	}

	/**
	 * Records a threshold as notified (idempotent); see "PAT expiry warnings".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	public function addWarnedThreshold(string $threshold): void {
		$thresholds = $this->getWarnedThresholdsList();
		if (in_array($threshold, $thresholds, true)) {
			return;
		}
		$thresholds[] = $threshold;
		$this->setWarnedThresholds(json_encode(array_values($thresholds), JSON_THROW_ON_ERROR));
	}

	/**
	 * Resets the threshold ledger, e.g. when a token is renewed with a new expiry; see "PAT expiry warnings".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
	public function clearWarnedThresholds(): void {
		$this->setWarnedThresholds('[]');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toRedacted(): array {
		$validated = null;
		$lastValidated = $this->lastValidatedScopes;
		if (is_string($lastValidated) && $lastValidated !== '') {
			/** @var mixed $decoded */
			$decoded = json_decode($lastValidated, true);
			if (is_array($decoded)) {
				$validated = $decoded;
			}
		}

		return [
			'id' => $this->getId(),
			'ownerUid' => $this->ownerUid,
			'label' => $this->label,
			'targetPattern' => $this->targetPattern,
			'kind' => $this->kind,
			'forge' => $this->forge,
			'tokenHint' => $this->tokenHint,
			'sharedWithAdmins' => $this->sharedWithAdmins,
			'lastValidatedScopes' => $validated,
			'expiresAt' => $this->expiresAt,
			'lastUsedAt' => $this->lastUsedAt,
			'createdAt' => $this->createdAt,
		];
	}
}
