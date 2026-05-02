<?php

declare(strict_types=1);

namespace OCA\AppVersions\Service\Pat;

use Exception;
use OCA\AppVersions\Db\Pat;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Probes a PAT against `GET https://api.github.com/user` to:
 *   - confirm the token is valid (non-401)
 *   - read scope from the `X-OAuth-Scopes` response header (classic only)
 *   - read expiry from the `github-authentication-token-expiration` header
 *
 * Classic PAT (`ghp_*`) scope check is hard — any scope outside
 * `repo` / `public_repo` rejects the upload. Fine-grained PATs
 * (`github_pat_*`) do not expose configured permissions to the holder, so
 * we accept them with an explicit `unverifiable_scope` warning.
 */
class PatValidator {
	private const USER_ENDPOINT = 'https://api.github.com/user';
	private const USER_AGENT = 'Nextcloud-AppVersions';

	/** @var list<string> */
	public const ALLOWED_CLASSIC_SCOPES = ['repo', 'public_repo'];

	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	public function detectKind(string $token): string {
		if (str_starts_with($token, 'ghp_')) {
			return Pat::KIND_CLASSIC;
		}
		if (str_starts_with($token, 'github_pat_')) {
			return Pat::KIND_FINE_GRAINED;
		}

		return Pat::KIND_FINE_GRAINED; // Conservative default; pure user-supplied strings get the safer code path.
	}

	public function validate(string $token): ValidationResult {
		$kind = $this->detectKind($token);
		$client = $this->clientService->newClient();

		try {
			$response = $client->get(self::USER_ENDPOINT, [
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Accept' => 'application/vnd.github+json',
					'User-Agent' => self::USER_AGENT,
					'X-GitHub-Api-Version' => '2022-11-28',
				],
				'timeout' => 30,
				// IClient (Guzzle) throws on 4xx by default; we want to inspect
				// the status ourselves so we can produce a useful error message.
				'http_errors' => false,
				'nextcloud' => ['allow_local_address' => false],
			]);
		} catch (Exception $error) {
			$this->logger->warning('PatValidator: probe failed', ['errorMessage' => $error->getMessage()]);

			return ValidationResult::rejected('Could not reach api.github.com — check network connectivity.');
		}

		$status = $response->getStatusCode();
		if ($status === 401) {
			return ValidationResult::rejected('Token is invalid or revoked.');
		}
		if ($status === 403) {
			return ValidationResult::rejected('GitHub rate limit exceeded — try again later.');
		}
		if ($status !== 200) {
			return ValidationResult::rejected(sprintf('GitHub returned HTTP %d.', $status));
		}

		$headers = $response->getHeaders();
		$scopesHeader = $this->headerValue($headers, 'X-OAuth-Scopes');
		$expiresAt = $this->parseExpires($this->headerValue($headers, 'github-authentication-token-expiration'));

		if ($kind === Pat::KIND_CLASSIC) {
			$scopes = $this->parseScopes($scopesHeader);
			$disallowed = array_values(array_filter(
				$scopes,
				static fn (string $scope): bool => !in_array($scope, self::ALLOWED_CLASSIC_SCOPES, true)
			));
			if ($disallowed !== []) {
				return ValidationResult::rejected(sprintf(
					'PAT has scopes beyond what App Versions needs (%s). Recreate with %s only.',
					implode(', ', $disallowed),
					implode(' or ', self::ALLOWED_CLASSIC_SCOPES)
				));
			}

			return ValidationResult::accepted($scopes, [], $expiresAt);
		}

		// Fine-grained PAT
		return ValidationResult::accepted(
			[],
			['unverifiable_scope: GitHub did not expose configured permissions; please verify they are read-only (Contents: Read-only, Metadata: Read-only).'],
			$expiresAt
		);
	}

	/**
	 * @param array<string, list<string>|string> $headers
	 */
	private function headerValue(array $headers, string $name): ?string {
		foreach ($headers as $key => $values) {
			if (strcasecmp((string)$key, $name) !== 0) {
				continue;
			}
			if (is_array($values)) {
				return $values[0] ?? null;
			}
			if (is_string($values)) {
				return $values;
			}
		}

		return null;
	}

	/**
	 * @return list<string>
	 */
	private function parseScopes(?string $headerValue): array {
		if ($headerValue === null || trim($headerValue) === '') {
			return [];
		}

		$out = [];
		foreach (explode(',', $headerValue) as $scope) {
			$trimmed = trim($scope);
			if ($trimmed !== '') {
				$out[] = $trimmed;
			}
		}

		return $out;
	}

	private function parseExpires(?string $value): ?string {
		if ($value === null || trim($value) === '') {
			return null;
		}

		try {
			return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
		} catch (Exception) {
			return null;
		}
	}
}
