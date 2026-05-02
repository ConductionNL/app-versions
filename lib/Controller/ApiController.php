<?php

declare(strict_types=1);

namespace OCA\AppVersions\Controller;

use InvalidArgumentException;
use OCA\AppVersions\Service\InstallerService;
use OCA\AppVersions\Service\Source\SourceBinding;
use OCA\AppVersions\Service\Source\SourceRegistry;
use OCA\AppVersions\Service\Source\TrustedSourceList;
use OCA\AppVersions\Service\Source\UntrustedSourceException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\ServerVersion;

/**
 * @psalm-suppress UnusedClass
 */
class ApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private InstallerService $installerService,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private ServerVersion $serverVersion,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/admin-check')]
	public function adminCheck(): DataResponse {
		return new DataResponse(['isAdmin' => $this->isAdmin()], Http::STATUS_OK);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/apps')]
	public function apps(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse(['apps' => $this->installerService->getInstalledApps()]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/update-channel')]
	public function updateChannel(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse([
			'updateChannel' => $this->serverVersion->getChannel(),
		]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/sources')]
	public function sources(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse([
			'sources' => $this->installerService->getSourceRegistry()->listAvailable(),
			'trustedPatterns' => $this->installerService->getTrustedSources()->getPatterns(),
		]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/source/{appId}/binding')]
	public function getBinding(string $appId): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$binding = $this->installerService->getBinding($appId);

		return new DataResponse([
			'appId' => $appId,
			'binding' => $binding?->toArray(),
			'sourceId' => $binding?->getId() ?? 'appstore',
		]);
	}

	#[NoAdminRequired]
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'POST', url: '/api/source/{appId}/bind')]
	public function bindSource(string $appId): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$kind = $this->stringParam('kind', '');
		try {
			$binding = match ($kind) {
				SourceBinding::KIND_APPSTORE => SourceBinding::appStore(),
				SourceBinding::KIND_GITHUB_RELEASE => SourceBinding::github(
					$this->stringParam('owner', ''),
					$this->stringParam('repo', ''),
					$this->stringParam('assetPattern', '*.tar.gz'),
				),
				default => throw new InvalidArgumentException('Unknown source kind: ' . $kind),
			};
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->installerService->bindSource($appId, $binding);
		} catch (UntrustedSourceException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse([
			'appId' => $appId,
			'sourceId' => $binding->getId(),
			'binding' => $binding->toArray(),
		]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/app/{appId}/versions')]
	public function appVersions(string $appId): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$source = $this->request->getParam('source');
		$sourceOverride = is_string($source) && trim($source) !== '' ? trim($source) : null;

		$result = $this->installerService->getAppVersions($appId, $sourceOverride);
		$statusCode = $result['statusCode'] ?? Http::STATUS_OK;
		unset($result['statusCode'], $result['hasError']);

		return new DataResponse($result, $statusCode);
	}

	#[NoAdminRequired]
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'POST', url: '/api/app/{appId}/versions/{version}/install')]
	public function installVersion(string $appId, string $version): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$requestedVersion = $this->stringParam('targetVersion', '');
		if ($requestedVersion === '') {
			$requestedVersion = $this->stringParam('version', '');
		}
		if ($requestedVersion === '') {
			$requestedVersion = $version;
		}

		$source = $this->request->getParam('source');
		$sourceOverride = is_string($source) && trim($source) !== '' ? trim($source) : null;

		$includeDebug = $this->readBinaryBool($this->request->getParam('debug', '0'), false);

		$result = $this->installerService->installAppVersion(
			$appId,
			$requestedVersion,
			$includeDebug,
			$sourceOverride,
		);
		$result['payload']['requestedVersion'] = $requestedVersion;
		$result['payload']['routeVersion'] = $version;

		return new DataResponse(
			$result['payload'] ?? [],
			$result['statusCode'] ?? Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}

	private function stringParam(string $name, string $default): string {
		$value = $this->request->getParam($name, $default);

		return is_string($value) ? trim($value) : $default;
	}

	private function readBinaryBool(mixed $value, bool $default): bool {
		if (is_bool($value)) {
			return $value;
		}
		if (is_int($value) || is_float($value)) {
			return (string)(int)$value === '1';
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

	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}
}
