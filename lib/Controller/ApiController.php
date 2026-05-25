<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Controller;

use InvalidArgumentException;
use OCA\AppVersions\Db\Pat;
use OCA\AppVersions\Db\PatMapper;
use OCA\AppVersions\Service\Discovery\DiscoveryAggregator;
use OCA\AppVersions\Service\InstallerService;
use OCA\AppVersions\Service\Pat\PatDeeplinkBuilder;
use OCA\AppVersions\Service\Pat\PatManager;
use OCA\AppVersions\Service\Pat\PatValidator;
use OCA\AppVersions\Service\Source\SourceBinding;
use OCA\AppVersions\Service\Source\UntrustedSourceException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
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
		private DiscoveryAggregator $discoveryAggregator,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Reports whether the current user is an admin so the frontend can gate the UI.
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/admin-check')]
	public function adminCheck(): DataResponse {
		return new DataResponse(['isAdmin' => $this->isAdmin()], Http::STATUS_OK);
	}

	/**
	 * Lists installed apps (admin-only); see "List Installed Apps".
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/apps')]
	public function apps(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse(['apps' => $this->installerService->getInstalledApps()]);
	}

	/**
	 * Returns the server update channel so versions can be filtered; see "Respect update channel".
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/update-channel')]
	public function updateChannel(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse([
			'updateChannel' => $this->serverVersion->getChannel(),
		]);
	}

	/**
	 * Lists registered sources and trusted-source globs; see "Source management API".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
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

	/**
	 * Returns the active source binding for an app; see "Source binding".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
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

	/**
	 * Binds a source to an app after allowlist validation; see "Source management API".
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
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

	/**
	 * Fetches available versions from the bound (or overridden) source; see "Fetch Available Versions".
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
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

	/**
	 * Installs a specific version (password-confirmed); see "Install Specific Version".
	 *
	 * @spec openspec/specs/version-management/spec.md
	 */
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

	/**
	 * Lists PATs visible to the current admin, redacted; see "PAT management API".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
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

	/**
	 * Validates and creates an encrypted PAT; see "PAT validation on upload" and "PAT storage".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
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

	/**
	 * Updates a PAT's label / share flag, owner-only; see "PAT management API" and "PAT storage".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
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

	/**
	 * Deletes a PAT, restricted to its owner; see "PAT management API" ("Delete restricted to owner").
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
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

	/**
	 * Multi-source app search with query-length + filter handling; see "Discovery API".
	 *
	 * @spec openspec/specs/app-discovery/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/discover')]
	public function discover(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$query = $this->stringParam('q', '');
		if (mb_strlen($query) < 2) {
			return new DataResponse(
				['message' => 'Query must be at least 2 characters.'],
				Http::STATUS_BAD_REQUEST
			);
		}
		if (mb_strlen($query) > 100) {
			return new DataResponse(
				['message' => 'Query must be at most 100 characters.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$sourcesParam = $this->stringParam('sources', '');
		$sourceIds = null;
		if ($sourcesParam !== '') {
			$sourceIds = array_values(array_filter(array_map('trim', explode(',', $sourcesParam))));
		}

		$installedOnly = $this->readBinaryBool($this->request->getParam('installedOnly', '0'), false);

		$result = $this->discoveryAggregator->search($query, $sourceIds, $installedOnly);

		return new DataResponse($result);
	}

	/**
	 * Returns a prefilled GitHub PAT-creation deeplink; see "PAT management API" (deeplink scenarios).
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 */
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
