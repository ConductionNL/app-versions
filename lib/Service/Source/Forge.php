<?php

declare(strict_types=1);
/**
 * @license AGPL-3.0-or-later
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 */


namespace OCA\AppVersions\Service\Source;

/**
 * Immutable description of a git forge (GitHub, Codeberg/Forgejo, …).
 *
 * The only behavioural deltas between forges are data: the API base URL, the
 * HTTP auth-header scheme (`Bearer` vs Forgejo's `token`), and whether the
 * forge exposes token scopes to the holder (GitHub does via `X-OAuth-Scopes`;
 * Forgejo does not). Everything else (release JSON shape) is identical, so the
 * driver and validator read these fields rather than branching per forge.
 *
 * @spec openspec/specs/external-sources/spec.md
 * @psalm-api
 */
final class Forge {
	public const SCHEME_BEARER = 'Bearer';
	public const SCHEME_TOKEN = 'token';

	public function __construct(
		public readonly string $id,
		public readonly string $apiBaseUrl,
		public readonly string $webBaseUrl,
		public readonly string $authScheme,
		public readonly bool $exposesScopeHeader,
		public readonly string $tokenCreateUrl,
	) {
	}

	/**
	 * Builds the `Authorization` header value for this forge's auth scheme,
	 * so callers never duplicate the Bearer-vs-token decision.
	 */
	public function authHeaderValue(string $token): string {
		return $this->authScheme . ' ' . $token;
	}

	/**
	 * Releases-listing endpoint for an `owner/repo` on this forge.
	 */
	public function releasesEndpoint(string $ownerRepo): string {
		return sprintf('%s/repos/%s/releases?per_page=100', $this->apiBaseUrl, $ownerRepo);
	}

	/**
	 * The authenticated-user probe endpoint, used to validate a token.
	 */
	public function userEndpoint(): string {
		return $this->apiBaseUrl . '/user';
	}
}
