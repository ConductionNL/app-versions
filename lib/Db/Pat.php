<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Stored Personal Access Token row.
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
 */
class Pat extends Entity {
	public const KIND_CLASSIC = 'classic';
	public const KIND_FINE_GRAINED = 'fine-grained';

	protected string $ownerUid = '';
	protected string $label = '';
	protected string $targetPattern = '';
	protected string $kind = '';
	protected string $encryptedToken = '';
	protected string $tokenHint = '';
	protected bool $sharedWithAdmins = false;
	protected ?string $lastValidatedScopes = null;
	protected ?string $expiresAt = null;
	protected ?string $lastUsedAt = null;
	protected string $createdAt = '';

	public function __construct() {
		$this->addType('ownerUid', 'string');
		$this->addType('label', 'string');
		$this->addType('targetPattern', 'string');
		$this->addType('kind', 'string');
		$this->addType('encryptedToken', 'string');
		$this->addType('tokenHint', 'string');
		$this->addType('sharedWithAdmins', 'boolean');
		$this->addType('lastValidatedScopes', 'string');
		$this->addType('expiresAt', 'string');
		$this->addType('lastUsedAt', 'string');
		$this->addType('createdAt', 'string');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toRedacted(): array {
		$validated = null;
		$lastValidated = $this->lastValidatedScopes;
		if (is_string($lastValidated) && $lastValidated !== '') {
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
			'tokenHint' => $this->tokenHint,
			'sharedWithAdmins' => $this->sharedWithAdmins,
			'lastValidatedScopes' => $validated,
			'expiresAt' => $this->expiresAt,
			'lastUsedAt' => $this->lastUsedAt,
			'createdAt' => $this->createdAt,
		];
	}
}
