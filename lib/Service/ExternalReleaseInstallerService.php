<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Service;

use Exception;
use OC\Archive\TAR;
use OC\Archive\ZIP;
use OC\Files\FilenameValidator;
use OCA\AppVersions\Service\Installer\InstallFinalizer;
use OCA\AppVersions\Service\Pat\PatManager;
use OCA\AppVersions\Service\Pat\PatResolver;
use OCA\AppVersions\Service\Source\SourceBinding;
use OCA\AppVersions\Service\Source\TrustedSourceList;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\Files;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Installs an app from an external release source (e.g. GitHub).
 *
 * Differs from `SelectedReleaseInstallerService` in that there is no
 * Nextcloud-issued code-signing certificate to verify and no per-release
 * signature to check. To compensate we apply:
 *   1. Trusted-source allowlist gate (no download until passed)
 *   2. Optional SHA-256 verification when the release publishes a sibling .sha256 asset
 *   3. Mandatory appId match against the extracted `appinfo/info.xml`
 *   4. Mandatory version match against the extracted `appinfo/info.xml`
 *
 * The post-extract finalization (migrations, repair steps, config writes) is
 * delegated to `InstallFinalizer` so signed and external installs cannot drift
 * on upgrade semantics.
 */
class ExternalReleaseInstallerService {
	/** @var list<array{stage: string, data: mixed}> */
	private array $debug = [];

	public function __construct(
		private IClientService $clientService,
		private ITempManager $tempManager,
		private IAppManager $appManager,
		private IConfig $config,
		private InstallFinalizer $finalizer,
		private TrustedSourceList $trustedSources,
		private LoggerInterface $logger,
		private PatResolver $patResolver,
		private PatManager $patManager,
		private IUserSession $userSession,
	) {
	}

	/**
	 * @return list<array{stage: string, data: mixed}>
	 */
	public function getDebugLog(): array {
		return $this->debug;
	}

	/**
	 * @param array<string, mixed> $release
	 * @return array{status: string, installedVersionBefore: ?string, installedApp?: string, integrityWarning?: ?string, dryRun: bool, debug: list<array{stage: string, data: mixed}>}
	 * @throws Exception
	 */
	public function installFromExternalRelease(
		string $appId,
		string $version,
		array $release,
		SourceBinding $binding,
		bool $dryRun = false,
	): array {
		$this->resetDebug();
		$this->addDebug('requested-install', [
			'appId' => $appId,
			'version' => $version,
			'sourceId' => $binding->getId(),
			'dryRun' => $dryRun,
		]);

		if (!preg_match('/^[a-z][a-z0-9_\-]*$/', $appId)) {
			throw new Exception('Invalid app id.');
		}

		$this->trustedSources->assertBindingAllowed($binding);

		$downloadUrl = $release['download'] ?? '';
		$rawShaUrl = $release['sha256Url'] ?? null;
		$shaUrl = is_string($rawShaUrl) && $rawShaUrl !== '' ? $rawShaUrl : null;
		if (!is_string($downloadUrl) || $downloadUrl === '') {
			throw new Exception('No download URL found for the selected release.');
		}

		try {
			$installedVersion = (string)$this->appManager->getAppVersion($appId);
		} catch (Exception) {
			$installedVersion = '';
		}
		$previousEnabled = (string)$this->config->getAppValue($appId, 'enabled', 'no');

		$tempFile = $this->tempManager->getTemporaryFile('.tar.gz');
		$tempFolder = $this->tempManager->getTemporaryFolder('app-version-external');
		if (!is_string($tempFile) || !is_string($tempFolder)) {
			throw new Exception('Could not allocate temporary download paths.');
		}

		$authResolution = $this->resolveAuth($binding);
		$this->addDebug('auth-resolution', ['hasPat' => $authResolution !== null]);

		try {
			$this->authenticatedDownload($downloadUrl, $tempFile, $authResolution);
		} catch (Exception $error) {
			throw new Exception('Could not download selected release: ' . $error->getMessage());
		}
		$this->addDebug('downloaded', ['tempFile' => $tempFile, 'sourceUrl' => $downloadUrl]);

		$integrityWarning = $this->verifyChecksum($tempFile, $shaUrl, $authResolution);
		$this->addDebug('checksum', ['shaUrl' => $shaUrl, 'integrityWarning' => $integrityWarning]);

		$archivePath = $this->extractArchive($tempFile, $tempFolder);
		$this->addDebug('archive-extracted', ['extractedRoot' => $archivePath]);

		$info = $this->parseAndValidateInfoXml($archivePath, $appId, $version);
		$this->addDebug('info-validated', [
			'appId' => $info['id'],
			'archiveVersion' => $info['version'],
		]);

		$previousPath = null;
		try {
			$previousPath = $this->appManager->getAppPath($appId);
		} catch (AppPathNotFoundException) {
			$previousPath = null;
		}

		$destination = $previousPath !== null ? $previousPath : $this->getInstallPath() . '/' . $appId;

		if (!is_dir(dirname($destination))) {
			throw new Exception('Could not resolve app install folder.');
		}

		if ($dryRun) {
			$this->addDebug('dry-run-skip-filesystem', ['destination' => $destination]);

			return [
				'status' => 'dry-run',
				'installedVersionBefore' => $installedVersion === '' ? null : $installedVersion,
				'integrityWarning' => $integrityWarning,
				'dryRun' => true,
				'debug' => $this->debug,
			];
		}

		$backupDestination = null;
		if (is_dir($destination)) {
			$backupDestination = $destination . '.appversion-backup';
			if (is_dir($backupDestination)) {
				Files::rmdirr($backupDestination);
			}
			if (!rename($destination, $backupDestination)) {
				throw new Exception('Could not backup existing app folder before replacement.');
			}
		}

		try {
			if (!mkdir($destination, 0777, true) && !is_dir($destination)) {
				throw new Exception('Could not create app destination folder.');
			}
			$this->copyRecursive($archivePath, $destination);
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

		$enabled = $installedVersion === '' ? 'no' : $previousEnabled;
		$installedApp = $this->finalizer->finalize($destination, $info, $enabled);
		$this->addDebug('finalized', ['appId' => $installedApp, 'enabled' => $enabled]);

		return [
			'status' => 'installed',
			'installedVersionBefore' => $installedVersion === '' ? null : $installedVersion,
			'installedApp' => $installedApp,
			'integrityWarning' => $integrityWarning,
			'dryRun' => false,
			'debug' => $this->debug,
		];
	}

	private function resolveAuth(SourceBinding $binding): ?\OCA\AppVersions\Db\Pat {
		$ownerRepo = $binding->getOwnerRepo();
		if ($ownerRepo === null) {
			return null;
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $this->patResolver->findFor($ownerRepo, $user->getUID());
	}

	private function authenticatedDownload(string $url, string $sinkPath, ?\OCA\AppVersions\Db\Pat $pat): void {
		$options = [
			'sink' => $sinkPath,
			'timeout' => $this->getDownloadTimeout(),
			'headers' => ['User-Agent' => 'Nextcloud-AppVersions'],
			// SSRF defence-in-depth: block fetches to internal addresses even
			// though $url originates from a trusted-source GitHub release JSON.
			// Mirrors PatValidator. See OWASP A10:2021.
			'nextcloud' => ['allow_local_address' => false],
		];

		if ($pat === null) {
			$this->clientService->newClient()->get($url, $options);

			return;
		}

		$this->patManager->useToken($pat, function (string $token) use ($url, $options): void {
			$options['headers']['Authorization'] = 'Bearer ' . $token;
			$this->clientService->newClient()->get($url, $options);
		});
	}

	private function verifyChecksum(string $tempFile, ?string $shaUrl, ?\OCA\AppVersions\Db\Pat $pat): ?string {
		if ($shaUrl === null) {
			return 'No SHA-256 checksum available for this artifact.';
		}

		$options = [
			'timeout' => 30,
			'headers' => ['User-Agent' => 'Nextcloud-AppVersions'],
			// SSRF defence-in-depth: same rationale as authenticatedDownload.
			'nextcloud' => ['allow_local_address' => false],
		];

		try {
			if ($pat === null) {
				$response = $this->clientService->newClient()->get($shaUrl, $options);
			} else {
				$response = $this->patManager->useToken($pat, function (string $token) use ($shaUrl, $options) {
					$options['headers']['Authorization'] = 'Bearer ' . $token;

					return $this->clientService->newClient()->get($shaUrl, $options);
				});
			}
		} catch (Exception $error) {
			$this->logger->warning('External installer: could not fetch .sha256', [
				'shaUrl' => $shaUrl,
				'message' => $error->getMessage(),
			]);

			return 'Failed to fetch advertised SHA-256; install proceeded without verification.';
		}

		if ($response->getStatusCode() !== 200) {
			return 'Failed to fetch advertised SHA-256; install proceeded without verification.';
		}

		$body = trim((string)$response->getBody());
		// Accept both raw hash and `<hash>  <filename>` forms.
		$expected = preg_split('/\s+/', $body)[0] ?? '';
		if (!preg_match('/^[a-f0-9]{64}$/i', $expected)) {
			return 'SHA-256 file format unrecognized; install proceeded without verification.';
		}

		$actual = hash_file('sha256', $tempFile);
		if ($actual === false) {
			return 'Could not compute SHA-256 of downloaded archive.';
		}

		if (!hash_equals(strtolower($expected), strtolower($actual))) {
			throw new Exception(sprintf(
				'SHA-256 mismatch — expected %s, got %s.',
				strtolower($expected),
				strtolower($actual)
			));
		}

		return null;
	}

	private function extractArchive(string $archiveFile, string $destFolder): string {
		// Try TAR first (most Nextcloud apps publish .tar.gz), fall back to ZIP.
		$archive = new TAR($archiveFile);
		$extracted = $archive->extract($destFolder);
		if (!$extracted) {
			$archive = new ZIP($archiveFile);
			$extracted = $archive->extract($destFolder);
			if (!$extracted) {
				$err = $archive->getError();
				$msg = 'Could not extract release archive (tried TAR and ZIP).';
				if ($err instanceof \PEAR_Error) {
					$msg .= ' ' . $err->getMessage();
				}
				throw new Exception($msg);
			}
		}

		$root = $this->findSingleDirectory($destFolder);
		if ($root === null) {
			throw new Exception('Could not determine extracted app folder.');
		}

		return $root;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function parseAndValidateInfoXml(string $extractedRoot, string $expectedAppId, string $expectedVersion): array {
		$infoXml = $extractedRoot . '/appinfo/info.xml';
		$infoContents = @file_get_contents($infoXml);
		if (!is_string($infoContents)) {
			throw new Exception('Downloaded archive is missing appinfo/info.xml.');
		}

		$xml = simplexml_load_string($infoContents);
		if (!$xml instanceof \SimpleXMLElement) {
			throw new Exception('Could not parse appinfo/info.xml from downloaded archive.');
		}

		$archiveAppId = (string)$xml->id;
		$archiveVersion = (string)$xml->version;

		if ($archiveAppId !== $expectedAppId) {
			throw new Exception(sprintf(
				"Downloaded archive declares appId '%s', expected '%s'.",
				$archiveAppId,
				$expectedAppId
			));
		}
		if ($archiveVersion !== $expectedVersion) {
			throw new Exception(sprintf(
				"Downloaded archive declares version '%s', expected '%s'.",
				$archiveVersion,
				$expectedVersion
			));
		}

		$l = Server::get(IFactory::class)->get('core');
		$info = $this->appManager->getAppInfoByPath($infoXml, $l->getLanguageCode());
		if (!is_array($info) || $info['id'] !== $expectedAppId) {
			throw new Exception('appinfo/info.xml could not be loaded by app manager.');
		}

		$ignoreMaxApps = $this->config->getSystemValue('app_install_overwrite', []);
		$ignoreMax = in_array($expectedAppId, $ignoreMaxApps, true);
		$serverVersion = implode('.', \OCP\Util::getVersion());
		if (!$this->appManager->isAppCompatible($serverVersion, $info, $ignoreMax)) {
			throw new Exception(sprintf(
				'App "%s" is not compatible with this Nextcloud version.',
				$info['name'] ?? $expectedAppId
			));
		}

		\OC_App::checkAppDependencies($this->config, $l, $info, $ignoreMax);

		return $info;
	}

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

	private function getDownloadTimeout(): int {
		return PHP_SAPI === 'cli' ? 0 : 120;
	}

	private function resetDebug(): void {
		$this->debug = [];
	}

	private function addDebug(string $stage, mixed $data = null): void {
		$this->debug[] = ['stage' => $stage, 'data' => $data];
	}
}
