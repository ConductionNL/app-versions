<?php

declare(strict_types=1);

namespace OCA\AppVersions\Controller;

use InvalidArgumentException;
use OCA\AppVersions\Db\Pat;
use OCA\AppVersions\Db\PatMapper;
use OCA\AppVersions\Service\InstallerService;
use OCA\AppVersions\Service\Pat\PatDeeplinkBuilder;
use OCA\AppVersions\Service\Pat\PatManager;
use OCA\AppVersions\Service\Pat\PatValidator;
use OCA\AppVersions\Service\Source\SourceBinding;
use OCA\AppVersions\Service\Source\SourceRegistry;
use OCA\AppVersions\Service\Source\TrustedSourceList;
use OCA\AppVersions\Service\Source\UntrustedSourceException;
use OCP\AppFramework\Db\DoesNotExistException;
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
		private PatMapper $patMapper,
		private PatManager $patManager,
		private PatValidator $patValidator,
		private PatDeeplinkBuilder $deeplinkBuilder,
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

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/pats')]
	public function listPats(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$pats = $this->patMapper->findVisibleTo($user->getUID());
		$payload = array_map(
			static fn (Pat $pat): array => $pat->toRedacted(),
			$pats
		);

		return new DataResponse(['pats' => $payload]);
	}

	#[NoAdminRequired]
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'POST', url: '/api/pats')]
	public function createPat(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$label = $this->stringParam('label', '');
		$targetPattern = $this->stringParam('targetPattern', '');
		$token = $this->stringParam('token', '');
		if ($label === '' || $targetPattern === '' || $token === '') {
			return new DataResponse(
				['message' => 'label, targetPattern and token are required.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$result = $this->patValidator->validate($token);
		if (!$result->ok) {
			return new DataResponse(['message' => $result->error ?? 'PAT validation failed.'], Http::STATUS_BAD_REQUEST);
		}

		$pat = $this->patManager->create(
			$user->getUID(),
			$label,
			$this->patValidator->detectKind($token),
			$targetPattern,
			$token,
			$result->scopes,
			$result->warnings,
			$result->expiresAt,
		);

		return new DataResponse(['pat' => $pat->toRedacted(), 'warnings' => $result->warnings]);
	}

	#[NoAdminRequired]
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'PATCH', url: '/api/pats/{id}')]
	public function patchPat(int $id): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$pat = $this->patMapper->findById($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'PAT not found.'], Http::STATUS_NOT_FOUND);
		}

		if ($pat->getOwnerUid() !== $user->getUID()) {
			return new DataResponse(['message' => 'Only the PAT owner can update it.'], Http::STATUS_FORBIDDEN);
		}

		$label = $this->request->getParam('label');
		$shared = $this->request->getParam('sharedWithAdmins');
		if (is_string($label) && trim($label) !== '') {
			$pat->setLabel(trim($label));
		}
		if (is_bool($shared)) {
			$pat->setSharedWithAdmins($shared);
		} elseif (is_string($shared)) {
			$pat->setSharedWithAdmins($this->readBinaryBool($shared, $pat->getSharedWithAdmins()));
		}

		return new DataResponse(['pat' => $this->patManager->update($pat)->toRedacted()]);
	}

	#[NoAdminRequired]
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'DELETE', url: '/api/pats/{id}')]
	public function deletePat(int $id): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$pat = $this->patMapper->findById($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'PAT not found.'], Http::STATUS_NOT_FOUND);
		}

		if ($pat->getOwnerUid() !== $user->getUID()) {
			return new DataResponse(['message' => 'Only the PAT owner can delete it.'], Http::STATUS_FORBIDDEN);
		}

		$this->patManager->delete($pat);

		return new DataResponse(['deleted' => $id]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/pats/deeplink')]
	public function patDeeplink(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$kind = $this->stringParam('kind', Pat::KIND_FINE_GRAINED);
		try {
			return new DataResponse($this->deeplinkBuilder->build($kind));
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}
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
