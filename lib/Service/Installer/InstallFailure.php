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

	/**
	 * @param FailureClassifier::RESTORE_* $restoreState
	 */
	public function __construct(
		string $message,
		private string $stage,
		private string $outcome,
		private string $restoreState,
		?Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	/**
	 * Pre-finalize failure whose previous files were restored from backup —
	 * fully safe, previous version intact.
	 */
	public static function reverted(string $message, string $stage, ?Throwable $previous = null): self {
		return new self($message, $stage, self::OUTCOME_REVERTED, FailureClassifier::RESTORE_CLEAN, $previous);
	}

	/**
	 * Finalize-phase failure. `$restoreState` records whether the previous files
	 * could be swapped back ({@see FailureClassifier::RESTORE_CLEAN}), the restore
	 * failed ({@see FailureClassifier::RESTORE_FAILED}), or there was no prior
	 * version to restore ({@see FailureClassifier::RESTORE_NONE}); either way the
	 * database state may be uncertain.
	 *
	 * @param FailureClassifier::RESTORE_* $restoreState
	 */
	public static function finalizeFailed(string $message, string $restoreState, ?Throwable $previous = null): self {
		return new self($message, FailureClassifier::STAGE_FINALIZE, self::OUTCOME_INSTALLED_BUT_BROKEN, $restoreState, $previous);
	}

	public function getStage(): string {
		return $this->stage;
	}

	public function getOutcome(): string {
		return $this->outcome;
	}

	/**
	 * @return FailureClassifier::RESTORE_*
	 */
	public function getRestoreState(): string {
		return $this->restoreState;
	}
}
