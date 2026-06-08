<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Service\Installer;

use Exception;
use Throwable;

/**
 * Thrown by the installers after they have already handled the filesystem
 * recovery for a failure, so the orchestrator can report an honest outcome
 * (`reverted` or `installed-but-broken`) instead of a generic 500.
 *
 * All other (pre-recovery) failures continue to throw plain exceptions and are
 * classified by {@see FailureClassifier} from their message + last stage.
 *
 * @spec openspec/specs/version-management/spec.md
 * @psalm-api
 */
class InstallFailure extends Exception {
	public const OUTCOME_REVERTED = 'reverted';
	public const OUTCOME_INSTALLED_BUT_BROKEN = 'installed-but-broken';

	public function __construct(
		string $message,
		private string $stage,
		private string $outcome,
		private bool $restoredCleanly,
		?Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	/**
	 * Pre-finalize failure whose previous files were restored from backup —
	 * fully safe, previous version intact.
	 */
	public static function reverted(string $message, string $stage, ?Throwable $previous = null): self {
		return new self($message, $stage, self::OUTCOME_REVERTED, true, $previous);
	}

	/**
	 * Finalize-phase failure. `restoredCleanly` records whether the previous
	 * files could be swapped back; either way database state may be uncertain.
	 */
	public static function finalizeFailed(string $message, bool $restoredCleanly, ?Throwable $previous = null): self {
		return new self($message, FailureClassifier::STAGE_FINALIZE, self::OUTCOME_INSTALLED_BUT_BROKEN, $restoredCleanly, $previous);
	}

	public function getStage(): string {
		return $this->stage;
	}

	public function getOutcome(): string {
		return $this->outcome;
	}

	public function wasRestoredCleanly(): bool {
		return $this->restoredCleanly;
	}
}
