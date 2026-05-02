<?php

declare(strict_types=1);

namespace OCA\AppVersions\Service\Pat;

use Exception;
use OCA\AppVersions\Db\Pat;
use OCA\AppVersions\Db\PatMapper;
use OCP\Security\ICrypto;

/**
 * Encapsulates PAT plaintext handling so the rest of the app never sees it.
 *
 * Plaintext appears only in three places:
 *   1. The HTTP request body that uploads the token
 *   2. The argument to `ICrypto::encrypt()` during `create()`
 *   3. The `$plaintext` parameter passed to the `useToken()` callback
 *
 * No method on this class returns plaintext, no plaintext is stored on a
 * property, and `useToken()` discards its decrypted variable in `finally{}`
 * before returning.
 */
class PatManager {
	public function __construct(
		private PatMapper $mapper,
		private ICrypto $crypto,
	) {
	}

	/**
	 * @param list<string> $scopes
	 * @param list<string> $warnings
	 */
	public function create(
		string $ownerUid,
		string $label,
		string $kind,
		string $targetPattern,
		string $plaintextToken,
		array $scopes,
		array $warnings,
		?string $expiresAt,
	): Pat {
		$pat = new Pat();
		$pat->setOwnerUid($ownerUid);
		$pat->setLabel($label);
		$pat->setKind($kind);
		$pat->setTargetPattern($targetPattern);
		$pat->setEncryptedToken($this->crypto->encrypt($plaintextToken));
		$pat->setTokenHint(self::buildHint($plaintextToken));
		$pat->setSharedWithAdmins(false);
		$pat->setLastValidatedScopes(json_encode([
			'scopes' => $scopes,
			'warnings' => $warnings,
			'validatedAt' => $this->nowString(),
		], JSON_THROW_ON_ERROR));
		$pat->setExpiresAt($expiresAt);
		$pat->setCreatedAt($this->nowString());

		return $this->mapper->insert($pat);
	}

	/**
	 * @template T
	 * @param callable(string): T $callback
	 * @return T
	 */
	public function useToken(Pat $pat, callable $callback): mixed {
		$plaintext = $this->crypto->decrypt($pat->getEncryptedToken());
		try {
			$result = $callback($plaintext);
		} finally {
			$plaintext = null;
			unset($plaintext);
		}

		$pat->setLastUsedAt($this->nowString());
		try {
			$this->mapper->update($pat);
		} catch (Exception) {
			// Best-effort — failing to update last-used must not break the install flow.
		}

		return $result;
	}

	public function delete(Pat $pat): Pat {
		return $this->mapper->delete($pat);
	}

	public function update(Pat $pat): Pat {
		return $this->mapper->update($pat);
	}

	public function refreshValidation(Pat $pat, ValidationResult $result): Pat {
		$pat->setLastValidatedScopes(json_encode([
			'scopes' => $result->scopes,
			'warnings' => $result->warnings,
			'validatedAt' => $this->nowString(),
		], JSON_THROW_ON_ERROR));
		if ($result->expiresAt !== null) {
			$pat->setExpiresAt($result->expiresAt);
		}

		return $this->mapper->update($pat);
	}

	public static function buildHint(string $token): string {
		if (strlen($token) <= 8) {
			return str_repeat('*', max(strlen($token), 4));
		}

		return substr($token, 0, 4) . '...' . substr($token, -4);
	}

	private function nowString(): string {
		return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
	}
}
