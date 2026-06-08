<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Service\Installer;

use OCA\AppVersions\AppInfo\Application;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Throwable;

/**
 * Classifies an install failure into a machine-readable category and maps that
 * category to an actionable, translatable hint and an appropriate HTTP status.
 *
 * The category is derived from (a) lightweight inspection of the exception
 * message and (b) the last installer breadcrumb stage, without retrofitting a
 * typed-exception hierarchy onto the installers. See
 * `openspec/changes/improve-install-failure-diagnostics/design.md` (D1).
 *
 * @spec openspec/specs/version-management/spec.md
 * @psalm-api
 */
class FailureClassifier {
	public const CATEGORY_PREFLIGHT_PERMISSION = 'preflight_permission';
	public const CATEGORY_DOWNLOAD = 'download';
	public const CATEGORY_CHECKSUM_MISMATCH = 'checksum_mismatch';
	public const CATEGORY_EXTRACT = 'extract';
	public const CATEGORY_APPID_MISMATCH = 'appid_mismatch';
	public const CATEGORY_VERSION_MISMATCH = 'version_mismatch';
	public const CATEGORY_INCOMPATIBLE = 'incompatible';
	public const CATEGORY_FINALIZE = 'finalize';
	public const CATEGORY_UNKNOWN = 'unknown';

	/**
	 * Canonical breadcrumb stage names emitted by the installers. Documented
	 * here so the orchestrator and tests share one vocabulary.
	 */
	public const STAGE_REQUESTED = 'requested-install';
	public const STAGE_DOWNLOADED = 'downloaded';
	public const STAGE_CHECKSUM = 'checksum';
	public const STAGE_EXTRACTED = 'archive-extracted';
	public const STAGE_INFO_VALIDATED = 'info-validated';
	public const STAGE_FILESYSTEM_UPDATED = 'filesystem-updated';
	public const STAGE_FINALIZE = 'finalize';

	private ?IL10N $l = null;

	public function __construct(
		private IFactory $l10nFactory,
	) {
	}

	private function l10n(): IL10N {
		if ($this->l === null) {
			$this->l = $this->l10nFactory->get(Application::APP_ID);
		}

		return $this->l;
	}

	/**
	 * Maps a category to its HTTP status. Classified failures never collapse to
	 * a blanket 500; 500 is reserved for `extract`/`finalize`/`unknown`.
	 */
	public function httpStatusFor(string $category): int {
		return match ($category) {
			self::CATEGORY_PREFLIGHT_PERMISSION => Http::STATUS_CONFLICT,
			self::CATEGORY_INCOMPATIBLE,
			self::CATEGORY_VERSION_MISMATCH,
			self::CATEGORY_APPID_MISMATCH,
			self::CATEGORY_CHECKSUM_MISMATCH => Http::STATUS_UNPROCESSABLE_ENTITY,
			self::CATEGORY_DOWNLOAD => Http::STATUS_BAD_GATEWAY,
			default => Http::STATUS_INTERNAL_SERVER_ERROR,
		};
	}

	/**
	 * Derives a category from the exception message and last breadcrumb stage.
	 *
	 * Message inspection is primary because the installers throw consistent,
	 * descriptive messages; the breadcrumb stage is a secondary tie-breaker.
	 */
	public function categoryFor(Throwable $error, ?string $lastStage = null): string {
		$message = strtolower($error->getMessage());

		if (str_contains($message, 'not compatible')) {
			return self::CATEGORY_INCOMPATIBLE;
		}
		if (str_contains($message, 'app id') || str_contains($message, 'appid')) {
			return self::CATEGORY_APPID_MISMATCH;
		}
		if (str_contains($message, 'version does not match') || str_contains($message, 'declares version') || str_contains($message, 'version is missing')) {
			return self::CATEGORY_VERSION_MISMATCH;
		}
		if (str_contains($message, 'sha-256') || str_contains($message, 'checksum')) {
			return self::CATEGORY_CHECKSUM_MISMATCH;
		}
		if (str_contains($message, 'download')) {
			return self::CATEGORY_DOWNLOAD;
		}
		if (str_contains($message, 'extract')) {
			return self::CATEGORY_EXTRACT;
		}
		if (str_contains($message, 'permission') || str_contains($message, 'not writable') || str_contains($message, 'cannot write') || str_contains($message, 'backup existing app folder')) {
			return self::CATEGORY_PREFLIGHT_PERMISSION;
		}

		// Fall back to the last reached stage when the message is opaque.
		return match ($lastStage) {
			self::STAGE_REQUESTED => self::CATEGORY_DOWNLOAD,
			self::STAGE_DOWNLOADED => self::CATEGORY_CHECKSUM_MISMATCH,
			self::STAGE_CHECKSUM => self::CATEGORY_EXTRACT,
			self::STAGE_FILESYSTEM_UPDATED, self::STAGE_FINALIZE => self::CATEGORY_FINALIZE,
			default => self::CATEGORY_UNKNOWN,
		};
	}

	/**
	 * An actionable, translatable remediation hint for a category.
	 */
	public function hintFor(string $category): string {
		$l = $this->l10n();

		return match ($category) {
			self::CATEGORY_PREFLIGHT_PERMISSION => $l->t('The app folder is not writable by the web-server user. If this is a bind-mounted dev checkout, fix the folder ownership/permissions (or install the app into a writable apps directory).'),
			self::CATEGORY_DOWNLOAD => $l->t('The release could not be downloaded from its source. Check connectivity to the source and that the release asset still exists.'),
			self::CATEGORY_CHECKSUM_MISMATCH => $l->t('The downloaded archive failed its integrity check. The release may be corrupted or tampered with; do not install it.'),
			self::CATEGORY_EXTRACT => $l->t('The release archive could not be extracted. The downloaded file may be incomplete or not a valid app archive.'),
			self::CATEGORY_APPID_MISMATCH => $l->t('The downloaded archive is for a different app than requested. Verify the source binding points at the correct repository.'),
			self::CATEGORY_VERSION_MISMATCH => $l->t('The downloaded archive declares a different version than requested. The source metadata and asset may be out of sync.'),
			self::CATEGORY_INCOMPATIBLE => $l->t('This version is not compatible with the current Nextcloud server version. Choose a compatible release.'),
			self::CATEGORY_FINALIZE => $l->t('The app files were updated but a migration or repair step failed. The previous files were restored, but database migrations may have already partially applied and cannot be rolled back automatically — verify the app and its data manually.'),
			default => $l->t('An unexpected error occurred during installation. Check the server log for details.'),
		};
	}

	/**
	 * Hint for a finalize-phase failure. Honest about the database: even when
	 * the files were restored, migrations may have partially applied.
	 */
	public function finalizeHint(bool $restoredCleanly): string {
		if ($restoredCleanly) {
			return $this->hintFor(self::CATEGORY_FINALIZE);
		}

		return $this->l10n()->t('The previous app files could not be reliably restored — the installation is in an indeterminate state and requires manual intervention. Check the app folder and its database tables.');
	}

	/**
	 * Hint for a cleanly reverted pre-finalize failure: nothing was applied.
	 */
	public function revertedHint(): string {
		return $this->l10n()->t('No changes were applied; the previously installed version remains intact.');
	}

	/**
	 * A short human description for a category, used when there is no underlying
	 * exception message to surface (e.g. the pre-flight guard).
	 */
	public function messageFor(string $category): string {
		$l = $this->l10n();

		return match ($category) {
			self::CATEGORY_PREFLIGHT_PERMISSION => $l->t('The app folder is not writable by the web-server user.'),
			default => $l->t('Installation failed.'),
		};
	}

	/**
	 * Builds the structured failure descriptor attached to every failure payload.
	 *
	 * @return array{category: string, hint: string, statusCode: int}
	 */
	public function classify(Throwable $error, ?string $lastStage = null, ?string $forcedCategory = null): array {
		$category = $forcedCategory ?? $this->categoryFor($error, $lastStage);

		return [
			'category' => $category,
			'hint' => $this->hintFor($category),
			'statusCode' => $this->httpStatusFor($category),
		];
	}
}
