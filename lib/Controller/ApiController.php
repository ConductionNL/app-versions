<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\AppVersions\Controller;

use InvalidArgumentException;
use OCA\AppVersions\Db\AuditEntryMapper;
use OCA\AppVersions\Db\Pat;
use OCA\AppVersions\Db\PatMapper;
use OCA\AppVersions\Service\Advisory\AdvisoryService;
use OCA\AppVersions\Service\AutoUpdate\AutoUpdateSettingsStore;
use OCA\AppVersions\Service\Cache\ArtifactCache;
use OCA\AppVersions\Service\AutoUpdate\AutoUpdateWindow;
use OCA\AppVersions\Service\Discovery\DiscoveryAggregator;
use OCA\AppVersions\Service\InstallerService;
use OCA\AppVersions\Service\Pat\PatDeeplinkBuilder;
use OCA\AppVersions\Service\Pat\PatExpiryEvaluator;
use OCA\AppVersions\Service\Pat\PatManager;
use OCA\AppVersions\Service\Pat\PatValidator;
use OCA\AppVersions\Service\Pin\Pin;
use OCA\AppVersions\Service\Pin\PinStore;
use OCA\AppVersions\Service\Policy\Policy;
use OCA\AppVersions\Service\Policy\PolicyStore;
use OCA\AppVersions\Service\Source\SourceBinding;
use OCA\AppVersions\Service\Source\UntrustedSourceException;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Utility\ITimeFactory;
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
		private PatExpiryEvaluator $patExpiryEvaluator,
		private DiscoveryAggregator $discoveryAggregator,
		private AdvisoryService $advisoryService,
		private AuditEntryMapper $auditEntryMapper,
		private PinStore $pinStore,
		private IAppManager $appManager,
		private ITimeFactory $timeFactory,
		private PolicyStore $policyStore,
		private AutoUpdateSettingsStore $autoUpdateSettingsStore,
		private ArtifactCache $artifactCache,
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
	 * Correlates each installed app's version against known security advisories
	 * (admin-only, read-only). Returns a per-app map of advisory state
	 * (`none` | `advisory-available` | `pinned-to-vulnerable`), the matching
	 * advisories, and the recommended safe version. Never changes a version.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/advisories')]
	public function advisories(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse(['advisories' => $this->advisoryService->correlateAll()]);
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
	 * Returns the active source binding for an app, including any recorded
	 * SHA-256 digests (not secrets); see "Source binding" and "Recorded
	 * digests are binding-scoped and surfaced".
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
		$forge = $this->stringParam('forge', SourceBinding::FORGE_GITHUB);
		try {
			$binding = match ($kind) {
				SourceBinding::KIND_APPSTORE => SourceBinding::appStore(),
				SourceBinding::KIND_GITHUB_RELEASE => $forge === SourceBinding::FORGE_CODEBERG
					? SourceBinding::codeberg(
						$this->stringParam('owner', ''),
						$this->stringParam('repo', ''),
						$this->stringParam('assetPattern', '*.tar.gz'),
					)
					: SourceBinding::github(
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

		// Re-read the persisted binding: rebinding to the same source id
		// preserves any previously recorded SHA-256 digests, so the response
		// should reflect what was actually written, not the pre-write value —
		// see "Recorded digests are binding-scoped and surfaced".
		$persisted = $this->installerService->getBinding($appId);

		return new DataResponse([
			'appId' => $appId,
			'sourceId' => $binding->getId(),
			'binding' => ($persisted ?? $binding)->toArray(),
		]);
	}

	/**
	 * Curated add of a forge-qualified trusted-source pattern; see "Source management API".
	 *
	 * Admin access is enforced by the runtime isAdmin() guard below (covered by
	 * both the 403 and 200 controller tests). The declarative
	 * #[AuthorizedAdminSetting] attribute is intentionally not used here: it
	 * requires a class-string<IDelegatedSettings>, whereas Settings\Admin is a
	 * plain ISettings — adopting it would also opt this endpoint into admin
	 * delegation semantics, a product change to make deliberately.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'POST', url: '/api/trusted-sources')]
	public function addTrustedSource(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$forge = $this->stringParam('forge', '');
		$owner = $this->stringParam('owner', '');
		/** @var mixed $repoRaw */
		$repoRaw = $this->request->getParam('repo');
		$repo = is_string($repoRaw) && trim($repoRaw) !== '' ? trim($repoRaw) : null;

		try {
			$patterns = $this->installerService->addTrustedPattern($forge, $owner, $repo);
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new DataResponse(['trustedPatterns' => $patterns]);
	}

	/**
	 * Removes a trusted-source pattern. The pattern is passed as a `pattern`
	 * query parameter (not a path segment) because patterns contain `/`, and
	 * Apache rejects encoded slashes in the path (`AllowEncodedSlashes Off`) with
	 * a 404 before the request reaches Nextcloud; query strings carry `%2F` fine.
	 *
	 * @spec openspec/specs/external-sources/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'DELETE', url: '/api/trusted-sources')]
	public function removeTrustedSource(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$pattern = $this->stringParam('pattern', '');
		if ($pattern === '') {
			return new DataResponse(['message' => 'A pattern is required.'], Http::STATUS_BAD_REQUEST);
		}

		$patterns = $this->installerService->removeTrustedPattern($pattern);

		return new DataResponse(['trustedPatterns' => $patterns]);
	}

	/**
	 * Fetches available versions from the bound (or overridden) source; see "Fetch Available Versions"
	 * and "Version listings carry release notes".
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @spec openspec/specs/changelog-visibility/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/app/{appId}/versions')]
	public function appVersions(string $appId): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		/** @var mixed $source */
		$source = $this->request->getParam('source');
		$sourceOverride = is_string($source) && trim($source) !== '' ? trim($source) : null;

		$result = $this->installerService->getAppVersions($appId, $sourceOverride);
		$statusCode = $result['statusCode'] ?? Http::STATUS_OK;
		unset($result['statusCode'], $result['hasError']);

		return new DataResponse($result, $this->toHttpStatus($statusCode, Http::STATUS_OK));
	}

	/**
	 * Installs a specific version (password-confirmed); see "Install Specific
	 * Version" and, when the app is pinned, "Pins are enforced on App
	 * Versions' own install path" (`overridePin=repin|unpin`, `pin=1`). For an
	 * external source with a recorded SHA-256 mismatch, `acceptNewSha=1`
	 * bypasses the check once and replaces the recorded digest on success; see
	 * "Recorded SHA-256 enforced on reinstall". A downgrade (target version
	 * older than installed) is refused with a 409 unless `allowDowngrade=1`;
	 * see "Server-side downgrade guard".
	 *
	 * `dryRun` is an independent boolean, decoupled from `debug`; see
	 * MODIFIED "Debug Mode". When `dryRun` is not supplied at all, `debug=1`
	 * still implies a dry run (deprecated back-compat) and the response
	 * carries a `deprecationNotice`.
	 *
	 * @spec openspec/specs/version-management/spec.md
	 * @spec openspec/specs/version-pinning/spec.md
	 * @spec openspec/specs/external-sources/spec.md
	 * @spec openspec/specs/migration-safety/spec.md
	 * @spec openspec/specs/cli-commands/spec.md
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

		/** @var mixed $source */
		$source = $this->request->getParam('source');
		$sourceOverride = is_string($source) && trim($source) !== '' ? trim($source) : null;

		$includeDebug = $this->readBinaryBool($this->request->getParam('debug', '0'), false);

		// `dryRun` is independent of `debug` — see MODIFIED "Debug Mode".
		// `getParam()` without a default returns null when the caller omitted
		// the parameter entirely, which is how we distinguish "not supplied"
		// (legacy `debug`-implies-dry-run fallback still applies) from an
		// explicit `dryRun=0` (a real install regardless of `debug`).
		/** @var mixed $dryRunParam */
		$dryRunParam = $this->request->getParam('dryRun');
		$dryRunSupplied = $dryRunParam !== null;
		$dryRun = $dryRunSupplied ? $this->readBinaryBool($dryRunParam, false) : null;

		$overridePinRaw = $this->stringParam('overridePin', '');
		$overridePin = $overridePinRaw === '' ? null : $overridePinRaw;
		$pinRequested = $this->readBinaryBool($this->request->getParam('pin', '0'), false);
		$acceptNewSha = $this->readBinaryBool($this->request->getParam('acceptNewSha', '0'), false);
		$allowDowngrade = $this->readBinaryBool($this->request->getParam('allowDowngrade', '0'), false);

		$result = $this->installerService->installAppVersion(
			$appId,
			$requestedVersion,
			$includeDebug,
			$sourceOverride,
			$overridePin,
			$pinRequested,
			$acceptNewSha,
			$allowDowngrade,
			$dryRun,
		);
		$result['payload']['requestedVersion'] = $requestedVersion;
		$result['payload']['routeVersion'] = $version;
		if (!$dryRunSupplied && $includeDebug) {
			// Legacy back-compat path only — see MODIFIED "Debug Mode",
			// Scenario "Legacy behavior preserved".
			$result['payload']['deprecationNotice'] = 'debug=1 implying a dry run is deprecated; pass dryRun=1 explicitly instead.';
		}

		return new DataResponse(
			$result['payload'] ?? [],
			$this->toHttpStatus($result['statusCode'] ?? Http::STATUS_INTERNAL_SERVER_ERROR, Http::STATUS_INTERNAL_SERVER_ERROR)
		);
	}

	/**
	 * Lists all pins joined with the live installed version and current
	 * drift status; see "Honest pin presentation".
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/pins')]
	public function pins(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$pins = [];
		foreach ($this->pinStore->all() as $appId => $pin) {
			try {
				$installed = $this->appManager->getAppVersion($appId, false);
				$installedVersion = $installed !== '' ? $installed : null;
			} catch (\Exception) {
				$installedVersion = null;
			}

			$pins[] = $pin->toArray() + [
				'appId' => $appId,
				'installedVersion' => $installedVersion,
			];
		}

		return new DataResponse(['pins' => $pins]);
	}

	/**
	 * Pins an app to its currently installed version (password-confirmed);
	 * rejects a `version` other than the installed one; see "Pin an
	 * installed app to its current version".
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'PUT', url: '/api/app/{appId}/pin')]
	public function pinApp(string $appId): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$installedVersion = $this->appManager->getAppVersion($appId, false);
		} catch (\Exception) {
			$installedVersion = '';
		}
		if ($installedVersion === '') {
			return new DataResponse(['message' => 'App is not installed.'], Http::STATUS_BAD_REQUEST);
		}

		$requestedVersion = $this->stringParam('version', '');
		if ($requestedVersion !== '' && $requestedVersion !== $installedVersion) {
			return new DataResponse(
				['message' => 'Only the installed version can be pinned.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$reason = $this->stringParam('reason', '');

		try {
			$pin = new Pin(
				$installedVersion,
				$user->getUID(),
				$this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
				$reason === '' ? null : $reason,
			);
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		$this->pinStore->set($appId, $pin);

		return new DataResponse([
			'appId' => $appId,
			'pin' => $pin->toArray(),
		]);
	}

	/**
	 * Removes an app's pin (password-confirmed); the installed version is
	 * unaffected; see "Unpin".
	 *
	 * @spec openspec/specs/version-pinning/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'DELETE', url: '/api/app/{appId}/pin')]
	public function unpinApp(string $appId): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$this->pinStore->clear($appId, $user->getUID());

		return new DataResponse(['appId' => $appId, 'unpinned' => true]);
	}

	/**
	 * Lists every persisted per-app auto-update policy plus the global
	 * kill switch / window; see "Per-app update policy" and "Global kill
	 * switch and window".
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/policies')]
	public function policies(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$policies = [];
		foreach ($this->policyStore->all() as $appId => $policy) {
			$policies[] = $policy->toArray() + ['appId' => $appId];
		}

		return new DataResponse([
			'policies' => $policies,
			'autoUpdateEnabled' => $this->autoUpdateSettingsStore->isEnabled(),
			'autoUpdateWindow' => $this->autoUpdateSettingsStore->getWindow(),
		]);
	}

	/**
	 * Sets an app's auto-update policy level (password-confirmed); rejects an
	 * unknown level with 400; see "Per-app update policy".
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'PUT', url: '/api/app/{appId}/policy')]
	public function setPolicy(string $appId): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$level = $this->stringParam('level', '');
		if (!Policy::isValidLevel($level)) {
			return new DataResponse(
				['message' => 'level must be one of: ' . implode(', ', Policy::VALID_LEVELS) . '.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$policy = new Policy(
			$level,
			$user->getUID(),
			$this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
		);
		$this->policyStore->set($appId, $policy);

		return new DataResponse(['appId' => $appId, 'policy' => $policy->toArray()]);
	}

	/**
	 * Clears an app's auto-update policy (password-confirmed); a no-op when
	 * none exists; see "Per-app update policy".
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'DELETE', url: '/api/app/{appId}/policy')]
	public function clearPolicy(string $appId): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$this->policyStore->clear($appId);

		return new DataResponse(['appId' => $appId, 'cleared' => true]);
	}

	/**
	 * Updates the global auto-update kill switch and/or maintenance window
	 * (password-confirmed); rejects a malformed window with 400; see "Global
	 * kill switch and window".
	 *
	 * @spec openspec/specs/auto-update-policies/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'PUT', url: '/api/auto-update/settings')]
	public function updateAutoUpdateSettings(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		/** @var mixed $enabledParam */
		$enabledParam = $this->request->getParam('enabled');
		$windowParam = $this->stringParam('window', '');

		if ($windowParam !== '' && !AutoUpdateWindow::isValid($windowParam)) {
			return new DataResponse(
				['message' => 'window must be in HH:MM-HH:MM format.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($enabledParam !== null) {
			$this->autoUpdateSettingsStore->setEnabled($this->readBinaryBool($enabledParam, $this->autoUpdateSettingsStore->isEnabled()));
		}
		if ($windowParam !== '') {
			$this->autoUpdateSettingsStore->setWindow($windowParam);
		}

		return new DataResponse([
			'autoUpdateEnabled' => $this->autoUpdateSettingsStore->isEnabled(),
			'autoUpdateWindow' => $this->autoUpdateSettingsStore->getWindow(),
		]);
	}

	/**
	 * Lists PATs visible to the current admin, redacted, with derived
	 * `expiryState`/`daysRemaining`; see "PAT management API" and
	 * "Expiry state in the PAT API and UI".
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
			fn (Pat $pat): array => $this->serializePat($pat),
			$pats
		);

		return new DataResponse(['pats' => $payload]);
	}

	/**
	 * Redacts a PAT and merges in its derived `expiryState`/`daysRemaining`;
	 * see "Expiry state in the PAT API and UI".
	 *
	 * @spec openspec/specs/pat-management/spec.md
	 * @return array<string, mixed>
	 */
	private function serializePat(Pat $pat): array {
		$expiry = $this->patExpiryEvaluator->evaluate($pat->getExpiresAt());

		return $pat->toRedacted() + [
			'expiryState' => $expiry['state'],
			'daysRemaining' => $expiry['daysRemaining'],
		];
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
		$forge = $this->stringParam('forge', SourceBinding::FORGE_GITHUB);
		if ($label === '' || $targetPattern === '' || $token === '') {
			return new DataResponse(
				['message' => 'label, targetPattern and token are required.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$result = $this->patValidator->validate($token, $forge);
		if (!$result->ok) {
			return new DataResponse(['message' => $result->error ?? 'PAT validation failed.'], Http::STATUS_BAD_REQUEST);
		}

		// Codeberg/Forgejo tokens are opaque; GitHub tokens are classified by prefix.
		$kind = $forge === SourceBinding::FORGE_CODEBERG
			? Pat::KIND_FORGE_TOKEN
			: $this->patValidator->detectKind($token);

		$pat = $this->patManager->create(
			$user->getUID(),
			$label,
			$kind,
			$targetPattern,
			$token,
			$result->scopes,
			$result->warnings,
			$result->expiresAt,
			$forge,
		);

		return new DataResponse(['pat' => $this->serializePat($pat), 'warnings' => $result->warnings]);
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

		/** @var mixed $label */
		$label = $this->request->getParam('label');
		/** @var mixed $shared */
		$shared = $this->request->getParam('sharedWithAdmins');
		if (is_string($label) && trim($label) !== '') {
			$pat->setLabel(trim($label));
		}
		if (is_bool($shared)) {
			$pat->setSharedWithAdmins($shared);
		} elseif (is_string($shared)) {
			$pat->setSharedWithAdmins($this->readBinaryBool($shared, $pat->getSharedWithAdmins()));
		}

		return new DataResponse(['pat' => $this->serializePat($this->patManager->update($pat))]);
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

		// A codeberg forge maps to the opaque forge-token deeplink; otherwise the
		// caller selects a GitHub kind (classic / fine-grained).
		$forge = $this->stringParam('forge', SourceBinding::FORGE_GITHUB);
		$kind = $forge === SourceBinding::FORGE_CODEBERG
			? Pat::KIND_FORGE_TOKEN
			: $this->stringParam('kind', Pat::KIND_FINE_GRAINED);
		try {
			return new DataResponse($this->deeplinkBuilder->build($kind));
		} catch (InvalidArgumentException $error) {
			return new DataResponse(['message' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Lists audit entries, newest-first, admin-only, paginated and optionally
	 * filtered by app id; see "Audit entries are immutable and admin-readable".
	 * No mutation endpoint exists for this resource — the retention prune job
	 * is the only deletion path.
	 *
	 * @spec openspec/specs/audit-trail/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/audit')]
	public function auditLog(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$appId = $this->stringParam('appId', '');
		$limit = $this->intParam('limit', 50);
		$limit = max(1, min($limit, 200));
		$offset = max(0, $this->intParam('offset', 0));

		$entries = $this->auditEntryMapper->findPage($appId !== '' ? $appId : null, $limit, $offset);

		return new DataResponse([
			'entries' => $entries,
			'limit' => $limit,
			'offset' => $offset,
		]);
	}

	/**
	 * Cache summary: per-app cached versions + size, and the total cache size,
	 * admin-only; see "Cache visibility and management".
	 *
	 * @spec openspec/specs/artifact-cache/spec.md
	 */
	#[ApiRoute(verb: 'GET', url: '/api/cache')]
	public function cacheSummary(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse($this->artifactCache->summary());
	}

	/**
	 * Clears cached release artifacts — all apps, or a single app when
	 * `appId` is supplied — password-confirmed; see "Cache visibility and
	 * management" ("Clear cache").
	 *
	 * @spec openspec/specs/artifact-cache/spec.md
	 */
	#[PasswordConfirmationRequired(strict: false)]
	#[ApiRoute(verb: 'DELETE', url: '/api/cache')]
	public function clearCache(): DataResponse {
		if (!$this->isAdmin()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$appId = $this->stringParam('appId', '');
		$this->artifactCache->clear($appId !== '' ? $appId : null);

		return new DataResponse($this->artifactCache->summary());
	}

	private function intParam(string $name, int $default): int {
		/** @var mixed $value */
		$value = $this->request->getParam($name, (string)$default);
		if (is_int($value)) {
			return $value;
		}
		if (is_string($value) && trim($value) !== '' && preg_match('/^-?\d+$/', trim($value))) {
			return (int)trim($value);
		}

		return $default;
	}

	private function stringParam(string $name, string $default): string {
		/** @var mixed $value */
		$value = $this->request->getParam($name, $default);

		return is_string($value) ? trim($value) : $default;
	}

	/**
	 * Coerces a service-provided integer status code into a valid HTTP status,
	 * falling back to $fallback when the value is outside the known set.
	 *
	 * @param int $status
	 * @param 100|101|102|200|201|202|203|204|205|206|207|208|226|300|301|302|303|304|305|306|307|400|401|402|403|404|405|406|407|408|409|410|411|412|413|414|415|416|417|418|422|423|424|426|428|429|431|500|501|502|503|504|505|506|507|508|509|510|511 $fallback
	 * @return 100|101|102|200|201|202|203|204|205|206|207|208|226|300|301|302|303|304|305|306|307|400|401|402|403|404|405|406|407|408|409|410|411|412|413|414|415|416|417|418|422|423|424|426|428|429|431|500|501|502|503|504|505|506|507|508|509|510|511
	 */
	private function toHttpStatus(int $status, int $fallback): int {
		// Install failures are now classified to category-appropriate statuses by
		// FailureClassifier: 409 (preflight_permission), 422 (incompatible /
		// version/appId/checksum mismatch) and 502 (download). These are present
		// in the whitelist below intentionally — do not remove them.
		$known = [
			100, 101, 102, 200, 201, 202, 203, 204, 205, 206, 207, 208, 226,
			300, 301, 302, 303, 304, 305, 306, 307,
			400, 401, 402, 403, 404, 405, 406, 407, 408, 409, 410, 411, 412,
			413, 414, 415, 416, 417, 418, 422, 423, 424, 426, 428, 429, 431,
			500, 501, 502, 503, 504, 505, 506, 507, 508, 509, 510, 511,
		];

		return in_array($status, $known, true) ? $status : $fallback;
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
