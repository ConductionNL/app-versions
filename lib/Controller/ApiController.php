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
 *
 * @spec openspec/changes/retrofit-frontend-context-endpoints-2026-04-24/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\AppVersions\Controller;

use OCA\AppVersions\Service\InstallerService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\ServerVersion;

/**
 * @psalm-suppress UnusedClass
 */
class ApiController extends OCSController {

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param InstallerService $installerService
	 * @param IGroupManager $groupManager
	 * @param IUserSession $userSession
	 * @param ServerVersion $serverVersion
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly InstallerService $installerService,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
		private readonly ServerVersion $serverVersion,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Checks whether the currently signed in user is an admin.
	 *
	 * @return DataResponse
	 *
	 * @spec openspec/changes/retrofit-frontend-context-endpoints-2026-04-24/tasks.md#task-1
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
	 *
	 * @spec openspec/specs/version-management/spec.md#requirement-list-installed-apps
	 */
	#[NoAdminRequired]
	public function apps(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([
				'message' => 'Forbidden',
			], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse([
			'apps' => $this->installerService->getInstalledApps(),
		]);
	}

	/**
	 * Returns the currently configured Nextcloud update channel.
	 *
	 * @return DataResponse
	 *
	 * @spec openspec/changes/retrofit-frontend-context-endpoints-2026-04-24/tasks.md#task-1
	 */
	#[NoAdminRequired]
	public function updateChannel(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([
				'message' => 'Forbidden',
			], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse([
			'updateChannel' => $this->serverVersion->getChannel(),
		]);
	}

	/**
	 * Returns available versions for a given app id.
	 *
	 * @param string $appId
	 * @return DataResponse
	 *
	 * @spec openspec/specs/version-management/spec.md#requirement-fetch-available-versions
	 */
	#[NoAdminRequired]
	public function appVersions(string $appId): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([
				'message' => 'Forbidden',
			], Http::STATUS_FORBIDDEN);
		}

		$result = $this->installerService->getAppVersions($appId);
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
	 *
	 * @spec openspec/specs/version-management/spec.md#requirement-install-specific-version
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired(strict: false)]
	public function installVersion(string $appId, string $version): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse([
				'message' => 'Forbidden',
			], Http::STATUS_FORBIDDEN);
		}

		$requestedVersion = $this->resolveRequestedVersion($version);
		$includeDebug = $this->readBinaryBool($this->request->getParam('debug', '0'), false);

		$result = $this->installerService->installAppVersion($appId, $requestedVersion, $includeDebug);
		$result['payload']['requestedVersion'] = $requestedVersion;
		$result['payload']['routeVersion'] = $version;

		return new DataResponse(
			$result['payload'] ?? [],
			$result['statusCode'] ?? Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}

	/**
	 * Resolves the version to install from the request body, falling back to
	 * the URL parameter when none is provided.
	 *
	 * Preference order: `targetVersion` body param → `version` body param →
	 * `$routeVersion` from the URL. Empty / non-string values at any tier
	 * fall through to the next.
	 *
	 * @param string $routeVersion Version extracted from the URL path.
	 * @return string Non-empty version string (may equal $routeVersion).
	 */
	private function resolveRequestedVersion(string $routeVersion): string {
		foreach (['targetVersion', 'version'] as $field) {
			$value = $this->request->getParam($field);
			if (is_string($value) && trim($value) !== '') {
				return trim($value);
			}
		}

		return $routeVersion;
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
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}
}
