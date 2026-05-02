<?php

declare(strict_types=1);

namespace OCA\AppVersions\Service\Installer;

use Exception;
use OC\AppFramework\Bootstrap\Coordinator;
use OC\DB\Connection;
use OC\DB\MigrationService;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
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
 */
class InstallFinalizer {
	public function __construct(
		private IConfig $config,
		private IAppManager $appManager,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string, mixed> $info Parsed `appinfo/info.xml` for the just-extracted version.
	 * @throws Exception
	 */
	public function finalize(string $appPath, array $info, string $enabled, ?IOutput $output = null): string {
		// Lazy registration must run before autoload + migrations so app-registered
		// event listeners are wired up when migrations dispatch events.
		$coordinator = Server::get(Coordinator::class);
		$coordinator->runLazyRegistration($info['id']);

		\OC_App::registerAutoloading($info['id'], $appPath);

		$previousVersion = $this->config->getAppValue($info['id'], 'installed_version', '');
		$migrationService = new MigrationService($info['id'], Server::get(Connection::class));
		if ($output instanceof IOutput) {
			$migrationService->setOutput($output);
		}

		if ($previousVersion !== '' && isset($info['repair-steps']['pre-migration'])) {
			\OC_App::executeRepairSteps($info['id'], $info['repair-steps']['pre-migration']);
		}

		$migrationService->migrate('latest', $previousVersion === '');

		if ($previousVersion !== '' && isset($info['repair-steps']['post-migration'])) {
			\OC_App::executeRepairSteps($info['id'], $info['repair-steps']['post-migration']);
		}

		foreach (($info['background-jobs'] ?? []) as $job) {
			$this->jobList->add($job);
		}

		$appInstallScriptPath = $appPath . '/appinfo/install.php';
		if (file_exists($appInstallScriptPath)) {
			$this->logger->warning('Using an appinfo/install.php file is deprecated. Application "{app}" still uses one.', [
				'app' => $info['id'],
			]);
			self::includeAppScript($appInstallScriptPath);
		}

		if (isset($info['repair-steps']['install'])) {
			\OC_App::executeRepairSteps($info['id'], $info['repair-steps']['install']);
		}

		$installedVersion = is_string($info['version'] ?? null) && $info['version'] !== ''
			? $info['version']
			: $this->appManager->getAppVersion($info['id'], false);
		$this->config->setAppValue($info['id'], 'installed_version', $installedVersion);
		$this->config->setAppValue($info['id'], 'enabled', $enabled);

		foreach (($info['remote'] ?? []) as $name => $path) {
			$this->config->setAppValue('core', 'remote_' . $name, $info['id'] . '/' . $path);
		}
		foreach (($info['public'] ?? []) as $name => $path) {
			$this->config->setAppValue('core', 'public_' . $name, $info['id'] . '/' . $path);
		}

		\OC_App::setAppTypes($info['id']);
		$this->appManager->clearAppsCache();

		return $info['id'];
	}

	private static function includeAppScript(string $script): void {
		if (file_exists($script)) {
			include $script;
		}
	}
}
