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
 * @spec openspec/changes/archive/2026-04-24-retrofit-frontend-context-endpoints/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\AppVersions\Controller;

use OCA\AppVersions\Service\InstallerService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\ServerVersion;

/**
 * OCS controller for all app-versions JSON endpoints. Every public method
 * gates on an admin check inside its body — the `#[NoAdminRequired]`
 * attribute declares the posture; the body enforces it (ADR-005 contract).
 *
 * @psalm-suppress UnusedClass
 */
class ApiController extends OCSController
{
	/**
	 * Constructor.
	 *
	 * @param string           $appName          The application id (`app_versions`).
	 * @param IRequest         $request          The current HTTP request.
	 * @param InstallerService $installerService Orchestrates the install pipeline.
	 * @param IGroupManager    $groupManager     Used to verify admin membership.
	 * @param IUserSession     $userSession      Resolves the current user.
	 * @param ServerVersion    $serverVersion    Exposes the Nextcloud update channel.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly InstallerService $installerService,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
		private readonly ServerVersion $serverVersion,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Checks whether the currently signed-in user is an admin.
	 *
	 * Returns 200 in both cases — the UI uses the boolean to branch display
	 * rather than to gate access. Admin-gating happens on the mutation
	 * endpoints.
	 *
	 * @return DataResponse
	 *
	 * @spec openspec/changes/archive/2026-04-24-retrofit-frontend-context-endpoints/tasks.md#task-1
	 */
	#[NoAdminRequired]
	public function adminCheck(): DataResponse
	{
		if ($this->isAdmin() === false) {
			return new DataResponse(['isAdmin' => false], Http::STATUS_OK);
		}

		return new DataResponse(['isAdmin' => true], Http::STATUS_OK);
	}//end adminCheck()

	/**
	 * Returns installed apps for the select dropdown.
	 *
	 * @return DataResponse
	 *
	 * @spec openspec/specs/version-management/spec.md#requirement-list-installed-apps
	 */
	#[NoAdminRequired]
	public function apps(): DataResponse
	{
		if ($this->isAdmin() === false) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse([
			'apps' => $this->installerService->getInstalledApps(),
		]);
	}//end apps()

	/**
	 * Returns the currently configured Nextcloud update channel.
	 *
	 * @return DataResponse
	 *
	 * @spec openspec/changes/archive/2026-04-24-retrofit-frontend-context-endpoints/tasks.md#task-1
	 */
	#[NoAdminRequired]
	public function updateChannel(): DataResponse
	{
		if ($this->isAdmin() === false) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse([
			'updateChannel' => $this->serverVersion->getChannel(),
		]);
	}//end updateChannel()

	/**
	 * Returns available versions for a given app id.
	 *
	 * @param string $appId The Nextcloud app id to query.
	 *
	 * @return DataResponse
	 *
	 * @spec openspec/specs/version-management/spec.md#requirement-fetch-available-versions
	 */
	#[NoAdminRequired]
	public function appVersions(string $appId): DataResponse
	{
		if ($this->isAdmin() === false) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$result     = $this->installerService->getAppVersions($appId);
		$statusCode = ($result['statusCode'] ?? Http::STATUS_OK);
		unset($result['statusCode'], $result['hasError']);

		return new DataResponse($result, $statusCode);
	}//end appVersions()

	/**
	 * Installs a selected app version.
	 *
	 * @param string $appId   The Nextcloud app id to install.
	 * @param string $version Target version from the URL path (fallback).
	 *
	 * @return DataResponse
	 *
	 * @spec openspec/specs/version-management/spec.md#requirement-install-specific-version
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired(strict: false)]
	public function installVersion(string $appId, string $version): DataResponse
	{
		if ($this->isAdmin() === false) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$requestedVersion = $this->resolveRequestedVersion($version);
		$includeDebug     = $this->readBinaryBool($this->request->getParam('debug', '0'), false);

		$result = $this->installerService->installAppVersion($appId, $requestedVersion, $includeDebug);
		$result['payload']['requestedVersion'] = $requestedVersion;
		$result['payload']['routeVersion']     = $version;

		return new DataResponse(
			($result['payload'] ?? []),
			($result['statusCode'] ?? Http::STATUS_INTERNAL_SERVER_ERROR)
		);
	}//end installVersion()

	/**
	 * Resolves the version to install from the request body, falling back to
	 * the URL parameter when none is provided.
	 *
	 * Preference order: `targetVersion` body param → `version` body param →
	 * `$routeVersion` from the URL. Empty / non-string values at any tier
	 * fall through to the next.
	 *
	 * @param string $routeVersion Version extracted from the URL path.
	 *
	 * @return string Non-empty version string (may equal $routeVersion).
	 */
	private function resolveRequestedVersion(string $routeVersion): string
	{
		foreach (['targetVersion', 'version'] as $field) {
			$value = $this->request->getParam($field);
			if (is_string($value) === true && trim($value) !== '') {
				return trim($value);
			}
		}

		return $routeVersion;
	}//end resolveRequestedVersion()

	/**
	 * Reads a request value into bool with safe defaults.
	 *
	 * Accepts "1", "0", "true", "false", integers and floats.
	 *
	 * @param mixed $value   Raw value from the request.
	 * @param bool  $default Fallback when $value is neither truthy nor falsy.
	 *
	 * @return bool
	 */
	private function readBinaryBool(mixed $value, bool $default): bool
	{
		if (is_bool($value) === true) {
			return $value;
		}

		if (is_int($value) === true || is_float($value) === true) {
			return (string) (int) $value === '1';
		}

		if (is_string($value) === true) {
			$normalized = strtolower(trim($value));
			if (in_array($normalized, ['1', 'true'], true) === true) {
				return true;
			}

			if (in_array($normalized, ['0', 'false'], true) === true) {
				return false;
			}
		}

		return $default;
	}//end readBinaryBool()

	/**
	 * Checks if the current user is an admin user.
	 *
	 * @return bool
	 */
	private function isAdmin(): bool
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}//end isAdmin()
}//end class
