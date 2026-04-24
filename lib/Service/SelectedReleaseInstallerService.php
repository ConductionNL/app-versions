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
use OC\AppFramework\Bootstrap\Coordinator;
use OC\Archive\TAR;
use OC\DB\Connection;
use OC\DB\MigrationService;
use OC\Files\FilenameValidator;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\Files;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\L10N\IFactory;
use OCP\Migration\IOutput;
use phpseclib\File\X509;
use Psr\Log\LoggerInterface;

/**
 * Ports the shape of \OC\Installer to a single-release install flow. Every
 * collaborator is constructor-injected so the behaviour can be exercised in
 * tests without the live Nextcloud container — the legacy `Server::get()`
 * lookups that used to live in per-service accessor methods were replaced
 * with standard DI.
 *
 * The service intentionally keeps a handful of direct touchpoints on
 * Nextcloud internals — `\OC::$SERVERROOT`, `\OC::$APPSROOTS`, `\OC_App`
 * static helpers, `OC\Archive\TAR`, `OC\DB\MigrationService` — because OCP
 * does not expose equivalents for the filesystem / migration / bootstrap
 * workflow this class needs. Each site is labelled inline.
 */
class SelectedReleaseInstallerService
{
	/** @var array<int, mixed> */
	private array $debug = [];

	/**
	 * Constructor.
	 *
	 * @param IFactory         $l10nFactory        Provides localisable strings during install errors.
	 * @param IAppManager      $appManager         Reads app metadata + clears the installed-app cache.
	 * @param IConfig          $config             Reads maintenance / per-app config values.
	 * @param ITempManager     $tempManager        Allocates temp files and folders for the archive.
	 * @param IClientService   $clientService      Builds the HTTP client used to download releases.
	 * @param IJobList         $jobList            Registers background jobs declared in info.xml.
	 * @param LoggerInterface  $logger             Emits deprecation warnings (legacy install.php).
	 * @param Coordinator      $bootstrapCoordinator Runs lazy IBootstrap registration on the new app version.
	 * @param Connection       $dbConnection       Migration-service dependency.
	 * @param FilenameValidator $filenameValidator Rejects forbidden files during recursive copy.
	 */
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IAppManager $appManager,
		private readonly IConfig $config,
		private readonly ITempManager $tempManager,
		private readonly IClientService $clientService,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
		private readonly Coordinator $bootstrapCoordinator,
		private readonly Connection $dbConnection,
		private readonly FilenameValidator $filenameValidator,
	) {
	}//end __construct()

	/**
	 * Returns internal debug logs for the last operation.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/specs/version-management/spec.md#requirement-debug-mode
	 */
	public function getDebugLog(): array
	{
		return $this->debug;
	}//end getDebugLog()

	/**
	 * Clears operation debug log.
	 *
	 * @return void
	 */
	private function resetDebug(): void
	{
		$this->debug = [];
	}//end resetDebug()

	/**
	 * Adds one debug stage entry.
	 *
	 * @param string $stage Stage identifier.
	 * @param mixed  $data  Optional stage payload.
	 *
	 * @return void
	 */
	private function addDebug(string $stage, mixed $data = null): void
	{
		$this->debug[] = [
			'stage' => $stage,
			'data' => $data,
		];
	}//end addDebug()

	/**
	 * Splits certificate bundles into individual certificate PEM blocks.
	 *
	 * @param string $cert Concatenated PEM cert bundle.
	 *
	 * @return array<int, string>
	 */
	private function splitCerts(string $cert): array
	{
		preg_match_all('([\-]{3,}[\S\ ]+?[\-]{3,}[\S\s]+?[\-]{3,}[\S\ ]+?[\-]{3,})', $cert, $matches);

		return $matches[0];
	}//end splitCerts()

	/**
	 * Verifies app certificate against Nextcloud signing chain and CRL.
	 *
	 * @param string $appId       App id being verified.
	 * @param string $certificate PEM-encoded certificate from the release metadata.
	 *
	 * @return void
	 * @throws Exception
	 */
	private function verifyCertificate(string $appId, string $certificate): void
	{
		// `\OC::$SERVERROOT` is a legacy constant used to locate the Nextcloud
		// code-signing chain shipped inside the server's `resources/` dir.
		// OCP has no replacement for this path.
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

		$serial = $loadedCertificate['tbsCertificate']['serialNumber']->toString();
		$revoked = $crl->getRevoked($serial);
		if ($revoked !== false) {
			throw new Exception(sprintf('Certificate "%s" has been revoked', $serial));
		}

		if ($x509->validateSignature() !== true) {
			throw new Exception(sprintf('App with id %s has a certificate not issued by a trusted Code Signing Authority', $appId));
		}

		$certInfo = openssl_x509_parse($certificate);
		if (isset($certInfo['subject']['CN']) === false) {
			throw new Exception(sprintf('App with id %s has a cert with no CN', $appId));
		}

		if ($certInfo['subject']['CN'] !== $appId) {
			throw new Exception(sprintf('App with id %s has a cert issued to %s', $appId, $certInfo['subject']['CN']));
		}

		$this->addDebug('certificate-validated', ['appId' => $appId, 'serial' => $serial]);
	}//end verifyCertificate()

	/**
	 * Resolves writable apps root path.
	 *
	 * `\OC::$APPSROOTS` is the multi-root apps-directory list that Nextcloud
	 * supports for custom deployments; OCP does not expose it.
	 *
	 * @return string
	 * @throws Exception
	 */
	private function getInstallPath(): string
	{
		foreach (\OC::$APPSROOTS as $dir) {
			if (isset($dir['writable']) === true && $dir['writable'] === true) {
				if (is_writable($dir['path']) === false || is_readable($dir['path']) === false) {
					throw new Exception('Cannot write into "apps" directory.');
				}

				return $dir['path'];
			}
		}

		throw new Exception('No writable apps directory found.');
	}//end getInstallPath()

	/**
	 * Returns timeout for downloads (unbounded in CLI contexts, 120s in HTTP).
	 *
	 * @return int
	 */
	private function getDownloadTimeout(): int
	{
		return PHP_SAPI === 'cli' ? 0 : 120;
	}//end getDownloadTimeout()

	/**
	 * Installs one selected release.
	 *
	 * @param string                                                                            $appId   App id to install.
	 * @param array{download?: mixed, signature?: mixed, certificate?: mixed, version?: mixed} $release Release metadata from the store.
	 * @param bool                                                                              $dryRun  When true, skips the post-copy install steps.
	 *
	 * @return array<string, mixed>
	 * @throws Exception
	 *
	 * @spec openspec/specs/version-management/spec.md#requirement-install-specific-version
	 */
	public function installFromSelectedRelease(string $appId, array $release, bool $dryRun = false): array
	{
		$this->resetDebug();
		$this->addDebug('requested-install', [
			'appId' => $appId,
			'dryRun' => $dryRun,
		]);

		if (preg_match('/^[a-z][a-z0-9_\-]*$/', $appId) === 0) {
			throw new Exception('Invalid app id.');
		}

		$installedVersion = '';
		try {
			$installedVersion = $this->appManager->getAppVersion($appId);
		} catch (Exception) {
			$installedVersion = '';
		}

		$previousEnabled = $this->config->getAppValue($appId, 'enabled', 'no');
		$installedApp = null;

		$this->replaceWithSelectedRelease($appId, $release, $dryRun);

		if ($dryRun === false) {
			$appPath = $this->appManager->getAppPath($appId, true);
			$l = $this->l10nFactory->get('core');
			$info = $this->appManager->getAppInfoByPath($appPath . '/appinfo/info.xml', $l->getLanguageCode());
			if (is_array($info) === false || $info['id'] !== $appId) {
				throw new Exception(
					$l->t(
						'App "%s" cannot be installed because appinfo file cannot be read.',
						[$appId]
					)
				);
			}

			$ignoreMaxApps = $this->config->getSystemValue('app_install_overwrite', []);
			$ignoreMax = in_array($appId, $ignoreMaxApps, true);
			$serverVersion = implode('.', \OCP\Util::getVersion());
			if ($this->appManager->isAppCompatible($serverVersion, $info, $ignoreMax) === false) {
				throw new Exception(
					$l->t(
						'App "%s" cannot be installed because it is not compatible with this version of the server.',
						[$info['name']]
					)
				);
			}

			// `\OC_App::checkAppDependencies` has no OCP replacement — it
			// compares the requested app's info.xml dependencies against the
			// current server version and installed-apps list.
			\OC_App::checkAppDependencies($this->config, $l, $info, $ignoreMax);

			$this->bootstrapCoordinator->runLazyRegistration($appId);

			$enabled = ($installedVersion === '') ? 'no' : $previousEnabled;
			$this->addDebug('last-steps', [
				'appPath' => $appPath,
				'enabled' => $enabled,
			]);
			$installedApp = $this->installAppLastSteps($appPath, $info, null, $enabled);
			$this->addDebug('post-install-state', [
				'appPath' => $appPath,
				'installedVersionConfig' => $this->config->getAppValue($appId, 'installed_version', ''),
				'installedApp' => $installedApp,
			]);
			$this->addDebug('installed', ['appId' => $installedApp]);
		}

		if ($dryRun === true) {
			$this->addDebug('result', [
				'status' => 'dry-run',
				'message' => 'Skipping installAppLastSteps and post-install writes.',
			]);

			return [
				'status' => 'dry-run',
				'installedVersionBefore' => ($installedVersion === '') ? null : $installedVersion,
				'dryRun' => true,
				'debug' => $this->debug,
			];
		}

		return [
			'status' => 'installed',
			'installedVersionBefore' => ($installedVersion === '') ? null : $installedVersion,
			'installedApp' => $installedApp,
			'dryRun' => false,
			'debug' => $this->debug,
		];
	}//end installFromSelectedRelease()

	/**
	 * Replaces existing app with selected release and validates download contents.
	 *
	 * @param string               $appId   App id being replaced.
	 * @param array<string, mixed> $release Release metadata from the store.
	 * @param bool                 $dryRun  When true, skips the filesystem swap.
	 *
	 * @return void
	 * @throws Exception
	 *
	 * @spec openspec/specs/version-management/spec.md#requirement-install-specific-version
	 */
	public function replaceWithSelectedRelease(string $appId, array $release, bool $dryRun): void
	{
		$downloadUrl = ($release['download'] ?? '');
		$signature = ($release['signature'] ?? '');
		$certificate = ($release['certificate'] ?? '');
		$expectedVersion = ($release['version'] ?? null);

		if (is_string($downloadUrl) === false || $downloadUrl === '') {
			throw new Exception('No download URL found for the selected release.');
		}

		if (is_string($signature) === false || $signature === '') {
			throw new Exception('No signature found for the selected release.');
		}

		if (is_string($certificate) === false || $certificate === '') {
			throw new Exception('No app certificate found for the selected app.');
		}

		if (is_string($expectedVersion) === false || $expectedVersion === '') {
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

		$tempFile = $this->tempManager->getTemporaryFile('.tar.gz');
		$tempFolder = $this->tempManager->getTemporaryFolder('app-version');

		try {
			$client = $this->clientService->newClient();
			$client->get($downloadUrl, [
				'sink' => $tempFile,
				'timeout' => $this->getDownloadTimeout(),
			]);
		} catch (Exception $error) {
			throw new Exception('Could not download selected release: ' . $error->getMessage());
		}

		$this->addDebug('downloaded', ['tempFile' => $tempFile]);

		// `OC\Archive\TAR` is instantiated directly — OCP does not expose an
		// archive extractor. This mirrors how `\OC\Installer` does it.
		$archive = new TAR($tempFile);
		if ($archive->extract($tempFolder) === false) {
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
		if (is_string($infoContents) === false) {
			throw new Exception('Could not read appinfo/info.xml from selected release.');
		}

		$info = simplexml_load_string($infoContents);
		if (($info instanceof \SimpleXMLElement) === false) {
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
		if (is_string($downloadedContent) === false) {
			throw new Exception('Could not read downloaded archive for signature verification.');
		}

		$signatureData = base64_decode($signature, true);
		if (is_string($signatureData) === false) {
			throw new Exception('Could not decode release signature.');
		}

		if (openssl_verify($downloadedContent, $signatureData, $publicKey, OPENSSL_ALGO_SHA512) !== 1) {
			throw new Exception('Release signature verification failed.');
		}

		$this->addDebug('signature-verified', ['result' => 'ok']);

		$previousPath = null;
		try {
			$previousPath = $this->appManager->getAppPath($appId);
		} catch (AppPathNotFoundException) {
			$previousPath = null;
		}

		$destination = ($previousPath !== null)
			? $previousPath
			: $this->getInstallPath() . '/' . $appId;

		if (is_dir(dirname($destination)) === false) {
			throw new Exception('Could not resolve app install folder.');
		}

		$this->addDebug('destination', ['destination' => $destination]);

		$backupDestination = null;
		if (is_dir($destination) === true) {
			$backupDestination = $destination . '.appversion-backup';
			if (rename($destination, $backupDestination) === false) {
				throw new Exception('Could not backup existing app folder before replacement.');
			}
		}

		if ($dryRun === true) {
			$this->addDebug('dry-run-skip-filesystem', [
				'message' => 'Skipping backup, copy/replace, and cleanup.',
				'hasExistingAppPath' => $previousPath !== null,
			]);
			if ($backupDestination !== null && is_dir($backupDestination) === true) {
				rename($backupDestination, $destination);
			}

			return;
		}

		try {
			if (mkdir($destination, 0777, true) === false && is_dir($destination) === false) {
				throw new Exception('Could not create app destination folder.');
			}

			$this->copyRecursive($extractedRoot, $destination);
		} catch (Exception $error) {
			if ($backupDestination !== null && is_dir($backupDestination) === true) {
				if (is_dir($destination) === true) {
					Files::rmdirr($destination);
				}

				rename($backupDestination, $destination);
			}

			throw $error;
		}

		if ($backupDestination !== null && is_dir($backupDestination) === true) {
			Files::rmdirr($backupDestination);
		}

		if (function_exists('opcache_reset') === true) {
			opcache_reset();
		}

		$this->addDebug('filesystem-updated', ['destination' => $destination]);
	}//end replaceWithSelectedRelease()

	/**
	 * Finalizes app install/upgrade steps (autoloading, migrations, hooks, cache/config updates).
	 *
	 * @param string               $appPath The on-disk path of the newly-extracted app.
	 * @param array<string, mixed> $info    Parsed info.xml contents.
	 * @param IOutput|null         $output  Optional output target for migration progress.
	 * @param string               $enabled Enabled state to persist after install.
	 *
	 * @return string
	 * @throws Exception
	 */
	private function installAppLastSteps(string $appPath, array $info, ?IOutput $output = null, string $enabled = 'no'): string
	{
		// `\OC_App` static helpers cover operations OCP does not expose:
		// autoloader registration, repair steps, type classification.
		\OC_App::registerAutoloading($info['id'], $appPath);

		$previousVersion = $this->config->getAppValue($info['id'], 'installed_version', '');
		// `OC\DB\MigrationService` is the engine that actually runs the
		// doctrine migrations declared in `lib/Migration/*`. It's instantiated
		// directly the same way `\OC\Installer` does.
		$ms = new MigrationService($info['id'], $this->dbConnection);
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

		foreach ($info['background-jobs'] as $job) {
			$this->jobList->add($job);
		}

		$appInstallScriptPath = $appPath . '/appinfo/install.php';
		if (file_exists($appInstallScriptPath) === true) {
			$this->logger->warning('Using an appinfo/install.php file is deprecated. Application "{app}" still uses one.', [
				'app' => $info['id'],
			]);
			self::includeAppScript($appInstallScriptPath);
		}

		\OC_App::executeRepairSteps($info['id'], $info['repair-steps']['install']);

		$installedVersion = (is_array($info) === true && is_string($info['version'] ?? null) === true)
			? $info['version']
			: $this->appManager->getAppVersion($info['id'], false);
		$this->config->setAppValue($info['id'], 'installed_version', $installedVersion);
		$this->config->setAppValue($info['id'], 'enabled', $enabled);

		foreach ($info['remote'] as $name => $path) {
			$this->config->setAppValue('core', 'remote_' . $name, $info['id'] . '/' . $path);
		}

		foreach ($info['public'] as $name => $path) {
			$this->config->setAppValue('core', 'public_' . $name, $info['id'] . '/' . $path);
		}

		\OC_App::setAppTypes($info['id']);
		$this->appManager->clearAppsCache();

		return $info['id'];
	}//end installAppLastSteps()

	/**
	 * Includes legacy install script when present.
	 *
	 * @param string $script Path to a legacy appinfo/install.php.
	 *
	 * @return void
	 */
	private static function includeAppScript(string $script): void
	{
		if (file_exists($script) === true) {
			include $script;
		}
	}//end includeAppScript()

	/**
	 * Finds the single top-level folder inside a TAR extraction path.
	 *
	 * @param string $path Temp folder holding the extracted archive.
	 *
	 * @return string|null
	 */
	private function findSingleDirectory(string $path): ?string
	{
		$entries = scandir($path);
		if (is_array($entries) === false) {
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
	}//end findSingleDirectory()

	/**
	 * Recursively copies extracted app files to destination.
	 *
	 * @param string $source      Extracted source folder.
	 * @param string $destination Target on-disk folder.
	 *
	 * @return void
	 * @throws Exception
	 */
	private function copyRecursive(string $source, string $destination): void
	{
		if (is_dir($source) === false) {
			throw new Exception('Invalid extracted app source folder.');
		}

		if (mkdir($destination, 0777, true) === false && is_dir($destination) === false) {
			throw new Exception('Could not create destination folder.');
		}

		$items = scandir($source);
		if (is_array($items) === false) {
			throw new Exception('Could not read extracted folder contents.');
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$sourceItem = $source . '/' . $item;
			$destinationItem = $destination . '/' . $item;
			if (is_dir($sourceItem) === true) {
				$this->copyRecursive($sourceItem, $destinationItem);
			} elseif (is_file($sourceItem) === true) {
				if ($this->filenameValidator->isForbidden($sourceItem) === false) {
					if (copy($sourceItem, $destinationItem) === false) {
						throw new Exception('Could not copy app file "' . $item . '".');
					}
				}
			}
		}
	}//end copyRecursive()
}//end class
