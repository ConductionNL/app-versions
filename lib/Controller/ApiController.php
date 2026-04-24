<?php

/**
 * AppVersions API Controller
 *
 * Exposes the OCS endpoints consumed by the admin UI: discovery of installed
 * apps, the current update channel, available versions per app, and version
 * installation.
 *
 * @category Controller
 * @package  OCA\AppVersions\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\AppVersions\Controller;

use OCA\AppVersions\Service\InstallerService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\OCSController;
use OCP\ServerVersion;
use OCP\IGroupManager;

/**
 * @psalm-suppress UnusedClass
 */
class ApiController extends OCSController {

	/**
	 * Checks whether the currently signed in user is an admin.
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function adminCheck(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([
				'isAdmin' => false,
			], Http::STATUS_OK);
		}

		return new DataResponse([
			'isAdmin' => true,
		], Http::STATUS_OK);
	}

	/**
	 * Returns installed apps for the select dropdown.
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function apps(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([
				'message' => 'Forbidden',
			], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse([
			'apps' => $this->getInstallerService()->getInstalledApps(),
		]);
	}

	/**
	 * Returns the currently configured Nextcloud update channel.
	 *
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function updateChannel(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([
				'message' => 'Forbidden',
			], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse([
			'updateChannel' => \OC::$server->get(ServerVersion::class)->getChannel(),
		]);
	}

	/**
	 * Returns available versions for a given app id.
	 *
	 * @param string $appId
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function appVersions(string $appId): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([
				'message' => 'Forbidden',
			], Http::STATUS_FORBIDDEN);
		}

		$result = $this->getInstallerService()->getAppVersions($appId);
		$statusCode = $result['statusCode'] ?? Http::STATUS_OK;
		unset($result['statusCode'], $result['hasError']);

		return new DataResponse($result, $statusCode);
	}

	/**
	 * Installs a selected app version.
	 *
	 * @param string $appId
	 * @param string $version
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired(strict: false)]
	public function installVersion(string $appId, string $version): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([
				'message' => 'Forbidden',
			], Http::STATUS_FORBIDDEN);
		}

		$requestedVersion = $this->request->getParam('targetVersion');
		if (!is_string($requestedVersion)) {
			$requestedVersion = '';
		}

		$requestedVersion = trim($requestedVersion);
		if ($requestedVersion === '') {
			$requestedVersion = $this->request->getParam('version');
			if (!is_string($requestedVersion)) {
				$requestedVersion = '';
			}

			$requestedVersion = trim($requestedVersion);
			if ($requestedVersion === '') {
				$requestedVersion = $version;
			}
		}

		$rawDebug = $this->request->getParam('debug', '0');
		$includeDebug = $this->readBinaryBool($rawDebug, false);

		$result = $this->getInstallerService()->installAppVersion(
			$appId,
			$requestedVersion,
			$includeDebug
		);
		$result['payload']['requestedVersion'] = $requestedVersion;
		$result['payload']['routeVersion'] = $version;

		return new DataResponse(
			$result['payload'] ?? [],
			$result['statusCode'] ?? Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}

	/**
	 * Reads a request value into bool with safe defaults.
	 *
	 * Accepts "1", "0", "true", "false", integers and floats.
	 *
	 * @param mixed $value
	 * @param bool $default
	 * @return bool
	 */
	private function readBinaryBool(mixed $value, bool $default): bool {
		if (is_bool($value)) {
			return $value;
		}

		if (is_int($value) || is_float($value)) {
			return (string) (int) $value === '1';
		}

		if (is_string($value)) {
			$normalized = strtolower(trim($value));
			if (in_array($normalized, ['1', 'true'], true)) {
				return true;
			}
			if (in_array($normalized, ['0', 'false'], true)) {
				return false;
			}
		}

		return $default;
	}

	/**
	 * Checks if the current user is an admin user.
	 *
	 * @return bool
	 */
	private function isAdmin(): bool {
		$user = \OC::$server->getUserSession()->getUser();
		if ($user === null) {
			return false;
		}

		return \OC::$server->get(IGroupManager::class)->isAdmin($user->getUID());
	}

	/**
	 * Resolves installer service from container.
	 *
	 * @return InstallerService
	 */
	private function getInstallerService(): InstallerService {
		return \OC::$server->get(InstallerService::class);
	}
}
