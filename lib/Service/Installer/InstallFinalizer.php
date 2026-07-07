<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\AppVersions\Service\Installer;

use Exception;
use OC\AppFramework\Bootstrap\Coordinator;
use OC\DB\Connection;
use OC\DB\MigrationService;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Finalizes the post-extract phase of an app install: migrations, repair steps,
 * background-job registration, remote/public route registration, and the
 * `installed_version` / `enabled` config writes.
 *
 * Used by both `SelectedReleaseInstallerService` (signed App Store path) and
 * `ExternalReleaseInstallerService` (unsigned GitHub-release path) so the two
 * installers cannot drift on the migration semantics that determine whether an
 * upgrade actually completes.
 *
 * @psalm-api
 */
class InstallFinalizer {
	public function __construct(
		private IAppConfig $appConfig,
		private IAppManager $appManager,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Runs migrations, repair steps, job + route registration, and version/enabled writes after extraction;
	 * see "Install Specific Version" ("any database migrations for the new version MUST be triggered").
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @param array<string, mixed> $info Parsed `appinfo/info.xml` for the just-extracted version.
	 * @throws Exception
	 */
	public function finalize(string $appPath, array $info, string $enabled, ?IOutput $output = null): string {
		$appId = (string)($info['id'] ?? '');

		// Lazy registration must run before autoload + migrations so app-registered
		// event listeners are wired up when migrations dispatch events.
		$coordinator = Server::get(Coordinator::class);
		$coordinator->runLazyRegistration($appId);

		\OC_App::registerAutoloading($appId, $appPath);

		$previousVersion = $this->appConfig->getValueString($appId, 'installed_version', '');
		$migrationService = new MigrationService($appId, Server::get(Connection::class));
		if ($output instanceof IOutput) {
			$migrationService->setOutput($output);
		}

		$repairSteps = (array)($info['repair-steps'] ?? []);

		$preMigration = (array)($repairSteps['pre-migration'] ?? []);
		if ($previousVersion !== '' && $preMigration !== []) {
			\OC_App::executeRepairSteps($appId, $preMigration);
		}

		$migrationService->migrate('latest', $previousVersion === '');

		$postMigration = (array)($repairSteps['post-migration'] ?? []);
		if ($previousVersion !== '' && $postMigration !== []) {
			\OC_App::executeRepairSteps($appId, $postMigration);
		}

		/** @var list<string> $backgroundJobs */
		$backgroundJobs = (array)($info['background-jobs'] ?? []);
		foreach ($backgroundJobs as $job) {
			/** @var class-string<IJob> $job */
			$this->jobList->add($job);
		}

		$appInstallScriptPath = $appPath . '/appinfo/install.php';
		if (file_exists($appInstallScriptPath)) {
			$this->logger->warning('Using an appinfo/install.php file is deprecated. Application "{app}" still uses one.', [
				'app' => $appId,
			]);
			self::includeAppScript($appInstallScriptPath);
		}

		$installStep = (array)($repairSteps['install'] ?? []);
		if ($installStep !== []) {
			\OC_App::executeRepairSteps($appId, $installStep);
		}

		$infoVersion = (string)($info['version'] ?? '');
		$installedVersion = $infoVersion !== ''
			? $infoVersion
			: $this->appManager->getAppVersion($appId, false);
		$this->appConfig->setValueString($appId, 'installed_version', $installedVersion);
		$this->appConfig->setValueString($appId, 'enabled', $enabled);

		/** @var array<string, string> $remote */
		$remote = (array)($info['remote'] ?? []);
		foreach ($remote as $name => $path) {
			$this->appConfig->setValueString('core', 'remote_' . $name, $appId . '/' . $path);
		}
		/** @var array<string, string> $public */
		$public = (array)($info['public'] ?? []);
		foreach ($public as $name => $path) {
			$this->appConfig->setValueString('core', 'public_' . $name, $appId . '/' . $path);
		}

		\OC_App::setAppTypes($appId);
		$this->appManager->clearAppsCache();

		return $appId;
	}

	private static function includeAppScript(string $script): void {
		if (file_exists($script)) {
			include $script;
		}
	}
}
