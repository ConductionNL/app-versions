<?php

declare(strict_types=1);

namespace OCA\AppVersions\Service\Pat;

/**
 * Outcome of probing a PAT against `GET https://api.github.com/user`.
 *
 * - `ok = true` means the PAT is accepted; warnings may still be present
 *   (e.g. fine-grained PATs always carry an `unverifiable_scope` warning
 *   because GitHub does not expose configured permissions).
 * - `ok = false` means the PAT is rejected and MUST NOT be persisted; the
 *   `error` field carries an admin-readable message.
 */
final class ValidationResult {
	/**
	 * @param list<string> $scopes
	 * @param list<string> $warnings
	 */
	public function __construct(
		public readonly bool $ok,
		public readonly array $scopes,
		public readonly array $warnings,
		public readonly ?string $expiresAt,
		public readonly ?string $error,
	) {
	}

	public static function rejected(string $error): self {
		return new self(false, [], [], null, $error);
	}

	/**
	 * @param list<string> $scopes
	 * @param list<string> $warnings
	 */
	public static function accepted(array $scopes, array $warnings, ?string $expiresAt): self {
		return new self(true, $scopes, $warnings, $expiresAt, null);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'ok' => $this->ok,
			'scopes' => $this->scopes,
			'warnings' => $this->warnings,
			'expiresAt' => $this->expiresAt,
			'error' => $this->error,
		];
	}
}
