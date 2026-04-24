<?php

/**
 * AppVersions Selected-Release Installer Service
 *
 * Low-level installer that downloads a specific release archive, verifies its
 * signature, extracts it into the apps directory and runs the app's migration
 * and repair steps.
 *
 * @category Service
 * @package  OCA\AppVersions\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\AppVersions\Service;

use Exception;
use OC\Archive\TAR;
use OC\AppFramework\Bootstrap\Coordinator;
use OC\DB\Connection;
use OC\DB\MigrationService;
use OC\Files\FilenameValidator;
use phpseclib\File\X509;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\Files;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\L10N\IFactory;
use OCP\Migration\IOutput;
use OCP\Server;
use Psr\Log\LoggerInterface;

class SelectedReleaseInstallerService {
	/** @var array<int, mixed> */
	private array $debug = [];

	/**
	 * Returns internal debug logs for the last operation.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/specs/version-management/spec.md#requirement-debug-mode
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
		if (!$loadedCertificate) {
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

		$serial = $loadedCertificate['tbsCertificate']['serialNumber']->toString();
		$revoked = $crl->getRevoked($serial);
		if ($revoked !== false) {
			throw new Exception(sprintf('Certificate "%s" has been revoked', $serial));
		}

		if ($x509->validateSignature() !== true) {
			throw new Exception(sprintf('App with id %s has a certificate not issued by a trusted Code Signing Authority', $appId));
		}

		$certInfo = openssl_x509_parse($certificate);
		if (!isset($certInfo['subject']['CN'])) {
			throw new Exception(sprintf('App with id %s has a cert with no CN', $appId));
		}

		if ($certInfo['subject']['CN'] !== $appId) {
			throw new Exception(sprintf('App with id %s has a cert issued to %s', $appId, $certInfo['subject']['CN']));
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
		return php_sapi_name() === 'cli' ? 0 : 120;
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
	 * Returns background job list.
	 *
	 * @return IJobList
	 */
	private function getJobList(): IJobList {
		return Server::get(IJobList::class);
	}

	/**
	 * Returns logger service.
	 *
	 * @return LoggerInterface
	 */
	private function getLogger(): LoggerInterface {
		return Server::get(LoggerInterface::class);
	}

	/**
	 * Installs one selected release.
	 *
	 * @param string $appId
	 * @param array{download?: mixed, signature?: mixed, certificate?: mixed, version?: mixed} $release
	 * @param bool $dryRun
	 * @return array<string, mixed>
	 * @throws Exception
	 *
	 * @spec openspec/specs/version-management/spec.md#requirement-install-specific-version
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

		$installedVersion = '';
		try {
			$installedVersion = $appManager->getAppVersion($appId);
		} catch (Exception) {
			$installedVersion = '';
		}
		$previousEnabled = $config->getAppValue($appId, 'enabled', 'no');
		$installedApp = null;

		$this->replaceWithSelectedRelease($appId, $release, $dryRun);

		if (!$dryRun) {
			$appPath = $appManager->getAppPath($appId, true);
			$l = $this->getL10n()->get('core');
			$info = $appManager->getAppInfoByPath($appPath . '/appinfo/info.xml', $l->getLanguageCode());
			if (!is_array($info) || $info['id'] !== $appId) {
				throw new Exception(
					$l->t('App "%s" cannot be installed because appinfo file cannot be read.',
						[$appId]
					)
				);
			}

			$ignoreMaxApps = $config->getSystemValue('app_install_overwrite', []);
			$ignoreMax = in_array($appId, $ignoreMaxApps, true);
			$serverVersion = implode('.', \OCP\Util::getVersion());
			if (!$appManager->isAppCompatible($serverVersion, $info, $ignoreMax)) {
				throw new Exception(
					$l->t('App "%s" cannot be installed because it is not compatible with this version of the server.',
						[$info['name']]
					)
				);
			}

			\OC_App::checkAppDependencies($config, $l, $info, $ignoreMax);

			$coordinator = Server::get(Coordinator::class);
			$coordinator->runLazyRegistration($appId);

			$enabled = $installedVersion === '' ? 'no' : $previousEnabled;
			$this->addDebug('last-steps', [
				'appPath' => $appPath,
				'enabled' => $enabled,
			]);
			$installedApp = $this->installAppLastSteps($appPath, $info, null, $enabled);
			$this->addDebug('post-install-state', [
				'appPath' => $appPath,
				'installedVersionConfig' => $config->getAppValue($appId, 'installed_version', ''),
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
	 * Replaces existing app with selected release and validates download contents.
	 *
	 * @param string $appId
	 * @param array<string, mixed> $release
	 * @param bool $dryRun
	 * @return void
	 * @throws Exception
	 *
	 * @spec openspec/specs/version-management/spec.md#requirement-install-specific-version
	 */
	public function replaceWithSelectedRelease(string $appId, array $release, bool $dryRun): void {
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
			'hasSignature' => $signature !== '',
			'hasCertificate' => $certificate !== '',
			'dryRun' => $dryRun,
		]);

		$this->verifyCertificate($appId, $certificate);

		$tempManager = $this->getTempManager();
		$tempFile = $tempManager->getTemporaryFile('.tar.gz');
		$tempFolder = $tempManager->getTemporaryFolder('app-version');
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

		if ((string) $info->id !== $appId) {
			throw new Exception('Downloaded app id does not match requested app.');
		}

		$archiveVersion = (string) $info->version;
		if ($expectedVersion !== $archiveVersion) {
			throw new Exception('Downloaded app version does not match requested version.');
		}
		$this->addDebug('info-xml', [
			'appId' => (string) $info->id,
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

		$previousPath = null;
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
			return;
		}

		try {
			if (!mkdir($destination, 0777, true) && !is_dir($destination)) {
				throw new Exception('Could not create app destination folder.');
			}
			$this->copyRecursive($extractedRoot, $destination);
		} catch (Exception $error) {
			if ($backupDestination !== null && is_dir($backupDestination)) {
				if (is_dir($destination)) {
					Files::rmdirr($destination);
				}
				rename($backupDestination, $destination);
			}
			throw $error;
		}

		if ($backupDestination !== null && is_dir($backupDestination)) {
			Files::rmdirr($backupDestination);
		}
		if (function_exists('opcache_reset')) {
			opcache_reset();
		}
		$this->addDebug('filesystem-updated', ['destination' => $destination]);
	}

	/**
	 * Finalizes app install/upgrade steps (autoloading, migrations, hooks, cache/config updates).
	 *
	 * @param string $appPath
	 * @param array<string, mixed> $info
	 * @param IOutput|null $output
	 * @param string $enabled
	 * @return string
	 * @throws Exception
	 */
	private function installAppLastSteps(string $appPath, array $info, ?IOutput $output = null, string $enabled = 'no'): string {
		\OC_App::registerAutoloading($info['id'], $appPath);

		$config = $this->getConfig();
		$appManager = $this->getAppManager();
		$previousVersion = $config->getAppValue($info['id'], 'installed_version', '');
		$ms = new MigrationService($info['id'], Server::get(Connection::class));
		if ($output instanceof IOutput) {
			$ms->setOutput($output);
		}
		if ($previousVersion !== '') {
			\OC_App::executeRepairSteps($info['id'], $info['repair-steps']['pre-migration']);
		}

		$ms->migrate('latest', $previousVersion === '');

		if ($previousVersion !== '') {
			\OC_App::executeRepairSteps($info['id'], $info['repair-steps']['post-migration']);
		}

		if ($output instanceof IOutput) {
			$output->debug('Registering tasks of ' . $info['id']);
		}

		$queue = $this->getJobList();
		foreach ($info['background-jobs'] as $job) {
			$queue->add($job);
		}

		$appInstallScriptPath = $appPath . '/appinfo/install.php';
		if (file_exists($appInstallScriptPath)) {
			$this->getLogger()->warning('Using an appinfo/install.php file is deprecated. Application "{app}" still uses one.', [
				'app' => $info['id'],
			]);
			self::includeAppScript($appInstallScriptPath);
		}

		\OC_App::executeRepairSteps($info['id'], $info['repair-steps']['install']);

		$installedVersion = is_array($info) && is_string($info['version'] ?? null)
			? $info['version']
			: $appManager->getAppVersion($info['id'], false);
		$config->setAppValue($info['id'], 'installed_version', $installedVersion);
		$config->setAppValue($info['id'], 'enabled', $enabled);

		foreach ($info['remote'] as $name => $path) {
			$config->setAppValue('core', 'remote_' . $name, $info['id'] . '/' . $path);
		}
		foreach ($info['public'] as $name => $path) {
			$config->setAppValue('core', 'public_' . $name, $info['id'] . '/' . $path);
		}

		\OC_App::setAppTypes($info['id']);
		$appManager->clearAppsCache();
		return $info['id'];
	}

	/**
	 * Includes legacy install script when present.
	 *
	 * @param string $script
	 */
	private static function includeAppScript(string $script): void {
		if (file_exists($script)) {
			include $script;
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
			static fn(string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir($path . '/' . $entry)
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
}
