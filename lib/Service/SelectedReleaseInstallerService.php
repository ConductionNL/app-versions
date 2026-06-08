<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Service;

use Exception;
use OC\Archive\TAR;
use OC\Files\FilenameValidator;
use OCA\AppVersions\Service\Installer\FailureClassifier;
use OCA\AppVersions\Service\Installer\InstallFailure;
use OCA\AppVersions\Service\Installer\InstallFinalizer;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\L10N\IFactory;
use OCP\Server;
use phpseclib\File\X509;

/**
 * @psalm-api
 */
class SelectedReleaseInstallerService {
	/** @var array<int, mixed> */
	private array $debug = [];

	public function __construct(
		private InstallFinalizer $finalizer,
	) {
	}

	/**
	 * Returns internal debug logs for the last operation.
	 *
	 * @return array<int, mixed>
	 */
	public function getDebugLog(): array {
		return $this->debug;
	}

	/**
	 * Clears operation debug log.
	 */
	private function resetDebug(): void {
		$this->debug = [];
	}

	/**
	 * Adds one debug stage entry.
	 *
	 * @param string $stage
	 * @param mixed $data
	 */
	private function addDebug(string $stage, mixed $data = null): void {
		$this->debug[] = [
			'stage' => $stage,
			'data' => $data,
		];
	}

	/**
	 * Splits certificate bundles into individual certificate PEM blocks.
	 *
	 * @param string $cert
	 * @return array<int, string>
	 */
	private function splitCerts(string $cert): array {
		preg_match_all('([\-]{3,}[\S\ ]+?[\-]{3,}[\S\s]+?[\-]{3,}[\S\ ]+?[\-]{3,})', $cert, $matches);

		return $matches[0];
	}

	/**
	 * Verifies app certificate against Nextcloud signing chain and CRL.
	 *
	 * @param string $appId
	 * @param string $certificate
	 * @throws Exception
	 */
	private function verifyCertificate(string $appId, string $certificate): void {
		$rootCrt = file_get_contents(\OC::$SERVERROOT . '/resources/codesigning/root.crt');
		$rootCrl = file_get_contents(\OC::$SERVERROOT . '/resources/codesigning/root.crl');
		if ($rootCrt === false) {
			throw new Exception('Unable to load Nextcloud root certificate chain.');
		}

		if ($rootCrl === false) {
			throw new Exception('Unable to load Nextcloud certificate revocation list.');
		}

		$x509 = new X509();
		$rootCrtList = $this->splitCerts($rootCrt);
		foreach ($rootCrtList as $rootCertificate) {
			$x509->loadCA($rootCertificate);
		}

		$loadedCertificate = $x509->loadX509($certificate);
		if ($loadedCertificate === false) {
			throw new Exception('Could not parse app certificate.');
		}

		$crl = new X509();
		foreach ($rootCrtList as $rootCertificate) {
			$crl->loadCA($rootCertificate);
		}

		$crl->loadCRL($rootCrl);
		if ($crl->validateSignature() !== true) {
			throw new Exception('Could not validate CRL signature');
		}

		$tbsCertificate = (array)($loadedCertificate['tbsCertificate'] ?? []);
		$serialNumber = $tbsCertificate['serialNumber'] ?? null;
		if (!is_object($serialNumber) || !method_exists($serialNumber, 'toString')) {
			throw new Exception('Could not read certificate serial number.');
		}
		// External-API mismatch: phpseclib X509::loadX509() returns untyped arrays, so the
		// serialNumber BigInteger's ->toString() is not statically known despite the method_exists guard above.
		/** @psalm-suppress MixedMethodCall */
		$serial = (string)$serialNumber->toString();
		$revoked = $crl->getRevoked($serial);
		if ($revoked !== false) {
			throw new Exception(sprintf('Certificate "%s" has been revoked', $serial));
		}

		if ($x509->validateSignature() !== true) {
			throw new Exception(sprintf('App with id %s has a certificate not issued by a trusted Code Signing Authority', $appId));
		}

		$certInfo = openssl_x509_parse($certificate);
		$subject = is_array($certInfo) && is_array($certInfo['subject'] ?? null) ? $certInfo['subject'] : [];
		if (!isset($subject['CN'])) {
			throw new Exception(sprintf('App with id %s has a cert with no CN', $appId));
		}

		$commonName = (string)$subject['CN'];
		if ($commonName !== $appId) {
			throw new Exception(sprintf('App with id %s has a cert issued to %s', $appId, $commonName));
		}

		$this->addDebug('certificate-validated', ['appId' => $appId, 'serial' => $serial]);
	}

	/**
	 * Resolves writable apps root path.
	 *
	 * @return string
	 * @throws Exception
	 */
	private function getInstallPath(): string {
		foreach (\OC::$APPSROOTS as $dir) {
			if (isset($dir['writable']) && $dir['writable'] === true) {
				if (!is_writable($dir['path']) || !is_readable($dir['path'])) {
					throw new Exception('Cannot write into "apps" directory.');
				}
				return $dir['path'];
			}
		}

		throw new Exception('No writable apps directory found.');
	}

	/**
	 * Returns localization factory.
	 *
	 * @return IFactory
	 */
	private function getL10n(): IFactory {
		return Server::get(IFactory::class);
	}

	/**
	 * Returns app manager.
	 *
	 * @return IAppManager
	 */
	private function getAppManager(): IAppManager {
		return Server::get(IAppManager::class);
	}

	/**
	 * Returns config service.
	 *
	 * @return IConfig
	 */
	private function getConfig(): IConfig {
		return Server::get(IConfig::class);
	}

	/**
	 * Returns app config service.
	 *
	 * @return IAppConfig
	 */
	private function getAppConfig(): IAppConfig {
		return Server::get(IAppConfig::class);
	}

	/**
	 * Returns temp manager.
	 *
	 * @return ITempManager
	 */
	private function getTempManager(): ITempManager {
		return Server::get(ITempManager::class);
	}

	/**
	 * Returns timeout for downloads.
	 *
	 * @return int
	 */
	private function getDownloadTimeout(): int {
		return PHP_SAPI === 'cli' ? 0 : 120;
	}

	/**
	 * Returns HTTP client service.
	 *
	 * @return IClientService
	 */
	private function getClientService(): IClientService {
		return Server::get(IClientService::class);
	}

	/**
	 * Installs one selected App Store release through the signed (code-signing) path; see "Install Specific Version".
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @param string $appId
	 * @param array<string, mixed> $release
	 * @param bool $dryRun
	 * @return array<string, mixed>
	 * @throws Exception
	 */
	public function installFromSelectedRelease(string $appId, array $release, bool $dryRun = false): array {
		$this->resetDebug();
		$this->addDebug('requested-install', [
			'appId' => $appId,
			'dryRun' => $dryRun,
		]);

		if (!preg_match('/^[a-z][a-z0-9_\-]*$/', $appId)) {
			throw new Exception('Invalid app id.');
		}

		$appManager = $this->getAppManager();
		$config = $this->getConfig();
		$appConfig = $this->getAppConfig();

		try {
			$installedVersion = $appManager->getAppVersion($appId);
		} catch (Exception) {
			$installedVersion = '';
		}
		$previousEnabled = $appConfig->getValueString($appId, 'enabled', 'no');
		$installedApp = null;

		$backupDestination = $this->replaceWithSelectedRelease($appId, $release, $dryRun);

		if (!$dryRun) {
			$appPath = $appManager->getAppPath($appId, true);
			$l = $this->getL10n()->get('core');

			// Pre-finalize validation (appinfo readable, compatible, deps met).
			// On failure the files are restored from the retained backup and the
			// outcome is a clean revert — the previous version is intact.
			try {
				$info = $appManager->getAppInfoByPath($appPath . '/appinfo/info.xml', $l->getLanguageCode());
				if (!is_array($info) || ($info['id'] ?? null) !== $appId) {
					throw new Exception(
						$l->t('App "%s" cannot be installed because appinfo file cannot be read.',
							[$appId]
						)
					);
				}
				/** @var array<string, mixed> $info */

				$ignoreMaxApps = (array)$config->getSystemValue('app_install_overwrite', []);
				$ignoreMax = in_array($appId, $ignoreMaxApps, true);
				$serverVersion = Server::get(\OCP\ServerVersion::class)->getVersionString();
				if (!$appManager->isAppCompatible($serverVersion, $info, $ignoreMax)) {
					$appName = isset($info['name']) && is_string($info['name']) ? $info['name'] : $appId;
					throw new Exception(
						$l->t('App "%s" cannot be installed because it is not compatible with this version of the server.',
							[$appName]
						)
					);
				}

				\OC_App::checkAppDependencies($config, $l, $info, $ignoreMax);
			} catch (Exception $validationError) {
				$this->restoreFromBackup($appPath, $backupDestination);
				throw InstallFailure::reverted($validationError->getMessage(), FailureClassifier::STAGE_INFO_VALIDATED, $validationError);
			}

			$enabled = $installedVersion === '' ? 'no' : $previousEnabled;
			$this->addDebug('last-steps', [
				'appPath' => $appPath,
				'enabled' => $enabled,
			]);

			// Finalize (migrations + repair steps) is the last, unrecoverable
			// phase. Keep the backup until it succeeds; on failure restore the
			// previous files and report installed-but-broken.
			try {
				$installedApp = $this->finalizer->finalize($appPath, $info, $enabled);
			} catch (Exception $finalizeError) {
				$restoredCleanly = $this->restoreFromBackup($appPath, $backupDestination);
				throw InstallFailure::finalizeFailed($finalizeError->getMessage(), $restoredCleanly, $finalizeError);
			}

			// Finalize succeeded — now it is safe to drop the backup.
			if ($backupDestination !== null && is_dir($backupDestination)) {
				$this->rmdirr($backupDestination);
			}
			$this->addDebug('post-install-state', [
				'appPath' => $appPath,
				'installedVersionConfig' => $appConfig->getValueString($appId, 'installed_version', ''),
				'installedApp' => $installedApp,
			]);
			$this->addDebug('installed', ['appId' => $installedApp]);
		}

		if ($dryRun) {
			$this->addDebug('result', [
				'status' => 'dry-run',
				'message' => 'Skipping installAppLastSteps and post-install writes.',
			]);

			return [
				'status' => 'dry-run',
				'installedVersionBefore' => $installedVersion === '' ? null : $installedVersion,
				'dryRun' => true,
				'debug' => $this->debug,
			];
		}

		return [
			'status' => 'installed',
			'installedVersionBefore' => $installedVersion === '' ? null : $installedVersion,
			'installedApp' => $installedApp,
			'dryRun' => false,
			'debug' => $this->debug,
		];
	}

	/**
	 * Verifies signature/certificate, downloads, validates appId+version, and replaces app files (with backup/restore);
	 * see "Install Specific Version" ("Installation fails" — no partial installs).
	 *
	 * Returns the retained backup path (or null when there was no previous
	 * install / on dry run); the caller deletes it after `finalize()` succeeds
	 * or restores from it on a finalize-phase failure.
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @param string $appId
	 * @param array<string, mixed> $release
	 * @param bool $dryRun
	 * @return ?string
	 * @throws Exception
	 */
	public function replaceWithSelectedRelease(string $appId, array $release, bool $dryRun): ?string {
		$downloadUrl = $release['download'] ?? '';
		$signature = $release['signature'] ?? '';
		$certificate = $release['certificate'] ?? '';
		$expectedVersion = $release['version'] ?? null;

		if (!is_string($downloadUrl) || $downloadUrl === '') {
			throw new Exception('No download URL found for the selected release.');
		}
		if (!is_string($signature) || $signature === '') {
			throw new Exception('No signature found for the selected release.');
		}
		if (!is_string($certificate) || $certificate === '') {
			throw new Exception('No app certificate found for the selected app.');
		}
		if (!is_string($expectedVersion) || $expectedVersion === '') {
			throw new Exception('Selected release version is missing.');
		}

		$this->addDebug('release-metadata', [
			'downloadUrl' => $downloadUrl,
			'expectedVersion' => $expectedVersion,
			'hasSignature' => true,
			'hasCertificate' => true,
			'dryRun' => $dryRun,
		]);

		$this->verifyCertificate($appId, $certificate);

		$tempManager = $this->getTempManager();
		$tempFile = $tempManager->getTemporaryFile('.tar.gz');
		$tempFolder = $tempManager->getTemporaryFolder('app-version');
		if (!is_string($tempFile) || !is_string($tempFolder)) {
			throw new Exception('Could not allocate temporary download paths.');
		}
		$appManager = $this->getAppManager();

		try {
			$client = $this->getClientService()->newClient();
			$client->get($downloadUrl, [
				'sink' => $tempFile,
				'timeout' => $this->getDownloadTimeout(),
			]);
		} catch (Exception $error) {
			throw new Exception('Could not download selected release: ' . $error->getMessage());
		}
		$this->addDebug('downloaded', ['tempFile' => $tempFile]);

		$archive = new TAR($tempFile);
		if (!$archive->extract($tempFolder)) {
			$errorMessage = 'Could not extract selected app archive';
			$archiveError = $archive->getError();
			if ($archiveError instanceof \PEAR_Error) {
				$errorMessage .= ': ' . $archiveError->getMessage();
			}

			throw new Exception($errorMessage);
		}
		$this->addDebug('archive-extracted', ['tempFolder' => $tempFolder]);

		$extractedRoot = $this->findSingleDirectory($tempFolder);
		if ($extractedRoot === null) {
			throw new Exception('Could not determine extracted app folder.');
		}
		$this->addDebug('extracted-root', ['path' => $extractedRoot]);

		$infoXml = $extractedRoot . '/appinfo/info.xml';
		$infoContents = file_get_contents($infoXml);
		if (!is_string($infoContents)) {
			throw new Exception('Could not read appinfo/info.xml from selected release.');
		}

		$info = simplexml_load_string($infoContents);
		if (!$info instanceof \SimpleXMLElement) {
			throw new Exception('Could not parse appinfo/info.xml from selected release.');
		}

		if ((string)$info->id !== $appId) {
			throw new Exception('Downloaded app id does not match requested app.');
		}

		$archiveVersion = (string)$info->version;
		if ($expectedVersion !== $archiveVersion) {
			throw new Exception('Downloaded app version does not match requested version.');
		}
		$this->addDebug('info-xml', [
			'appId' => (string)$info->id,
			'archiveVersion' => $archiveVersion,
		]);

		$publicKey = openssl_get_publickey($certificate);
		if ($publicKey === false) {
			throw new Exception('Could not read appstore certificate.');
		}

		$downloadedContent = file_get_contents($tempFile);
		if (!is_string($downloadedContent)) {
			throw new Exception('Could not read downloaded archive for signature verification.');
		}

		$signatureData = base64_decode($signature, true);
		if (!is_string($signatureData)) {
			throw new Exception('Could not decode release signature.');
		}

		if (openssl_verify($downloadedContent, $signatureData, $publicKey, OPENSSL_ALGO_SHA512) !== 1) {
			throw new Exception('Release signature verification failed.');
		}
		$this->addDebug('signature-verified', ['result' => 'ok']);

		try {
			$previousPath = $appManager->getAppPath($appId);
		} catch (AppPathNotFoundException) {
			$previousPath = null;
		}

		$destination = $previousPath !== null
			? $previousPath
			: $this->getInstallPath() . '/' . $appId;

		if (!is_dir(dirname($destination))) {
			throw new Exception('Could not resolve app install folder.');
		}
		$this->addDebug('destination', ['destination' => $destination]);

		$backupDestination = null;
		if (is_dir($destination)) {
			$backupDestination = $destination . '.appversion-backup';
			if (!rename($destination, $backupDestination)) {
				throw new Exception('Could not backup existing app folder before replacement.');
			}
		}

		if ($dryRun) {
			$this->addDebug('dry-run-skip-filesystem', [
				'message' => 'Skipping backup, copy/replace, and cleanup.',
				'hasExistingAppPath' => $previousPath !== null,
			]);
			if ($backupDestination !== null && is_dir($backupDestination)) {
				rename($backupDestination, $destination);
			}
			return null;
		}

		try {
			if (!mkdir($destination, 0777, true) && !is_dir($destination)) {
				throw new Exception('Could not create app destination folder.');
			}
			$this->copyRecursive($extractedRoot, $destination);
		} catch (Exception $error) {
			// Pre-finalize failure: restore the previous files and report a clean
			// revert (the previously installed version is intact).
			$this->restoreFromBackup($destination, $backupDestination);
			throw InstallFailure::reverted($error->getMessage(), 'copy', $error);
		}

		if (function_exists('opcache_reset')) {
			opcache_reset();
		}
		$this->addDebug('filesystem-updated', ['destination' => $destination]);

		// Backup is intentionally retained until finalize() succeeds; the caller
		// owns its deletion (success) or restore (finalize-phase failure).
		return $backupDestination;
	}

	/**
	 * Restores the previous app files from the retained backup after a post-swap
	 * failure. Returns whether the restore completed cleanly.
	 */
	private function restoreFromBackup(string $destination, ?string $backupDestination): bool {
		if ($backupDestination === null || !is_dir($backupDestination)) {
			return false;
		}
		try {
			if (is_dir($destination)) {
				$this->rmdirr($destination);
			}

			return rename($backupDestination, $destination);
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Finds the single top-level folder inside a TAR extraction path.
	 *
	 * @param string $path
	 * @return string|null
	 */
	private function findSingleDirectory(string $path): ?string {
		$entries = scandir($path);
		if (!is_array($entries)) {
			return null;
		}

		$dirs = array_values(array_filter(
			$entries,
			static fn (string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir($path . '/' . $entry)
		));

		if (count($dirs) !== 1) {
			return null;
		}

		return $path . '/' . $dirs[0];
	}

	/**
	 * Recursively copies extracted app files to destination.
	 *
	 * @param string $source
	 * @param string $destination
	 * @throws Exception
	 */
	private function copyRecursive(string $source, string $destination): void {
		if (!is_dir($source)) {
			throw new Exception('Invalid extracted app source folder.');
		}

		if (!mkdir($destination, 0777, true) && !is_dir($destination)) {
			throw new Exception('Could not create destination folder.');
		}

		$items = scandir($source);
		if (!is_array($items)) {
			throw new Exception('Could not read extracted folder contents.');
		}

		$filenameValidator = Server::get(FilenameValidator::class);
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$sourceItem = $source . '/' . $item;
			$destinationItem = $destination . '/' . $item;
			if (is_dir($sourceItem)) {
				$this->copyRecursive($sourceItem, $destinationItem);
			} elseif (is_file($sourceItem)) {
				if (!$filenameValidator->isForbidden($sourceItem)) {
					if (!copy($sourceItem, $destinationItem)) {
						throw new Exception('Could not copy app file "' . $item . '".');
					}
				}
			}
		}
	}

	/**
	 * Recursively deletes a directory on the local filesystem (temp/backup dirs),
	 * replacing the deprecated \OCP\Files::rmdirr helper.
	 *
	 * @param string $dir
	 */
	private function rmdirr(string $dir): void {
		if (!is_dir($dir)) {
			if (file_exists($dir) || is_link($dir)) {
				@unlink($dir);
			}

			return;
		}

		/** @var \Iterator<string, \SplFileInfo> $iterator */
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $item) {
			if ($item->isDir() && !$item->isLink()) {
				@rmdir($item->getPathname());
			} else {
				@unlink($item->getPathname());
			}
		}
		@rmdir($dir);
	}
}
