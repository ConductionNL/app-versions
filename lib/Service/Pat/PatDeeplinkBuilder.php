<?php

declare(strict_types=1);

namespace OCA\AppVersions\Service\Pat;

use InvalidArgumentException;
use OCA\AppVersions\Db\Pat;
use OCP\IRequest;

/**
 * Builds prefilled GitHub URLs for PAT creation. Classic PATs accept full
 * scope+description prefill; fine-grained PATs only accept the page link, so
 * we return a structured `instructions` array for the UI to render.
 */
class PatDeeplinkBuilder {
	private const CLASSIC_BASE = 'https://github.com/settings/tokens/new';
	private const FINE_GRAINED_BASE = 'https://github.com/settings/personal-access-tokens/new';

	public function __construct(private IRequest $request) {
	}

	/**
	 * @return array{kind:string, url:string, instructions:list<string>}
	 */
	public function build(string $kind): array {
		return match ($kind) {
			Pat::KIND_CLASSIC => $this->buildClassic(),
			Pat::KIND_FINE_GRAINED => $this->buildFineGrained(),
			default => throw new InvalidArgumentException('Unknown PAT kind: ' . $kind),
		};
	}

	/**
	 * @return array{kind:string, url:string, instructions:list<string>}
	 */
	private function buildClassic(): array {
		$description = 'Nextcloud App Versions - ' . $this->describeHost();
		$query = http_build_query([
			'scopes' => 'repo',
			'description' => $description,
		]);

		return [
			'kind' => Pat::KIND_CLASSIC,
			'url' => self::CLASSIC_BASE . '?' . $query,
			'instructions' => [
				'Click the link to open GitHub with the recommended scope (`repo`) and description prefilled.',
				'Set an expiration of 90 days or less.',
				'Click "Generate token" and paste the resulting `ghp_...` value back into App Versions.',
			],
		];
	}

	/**
	 * @return array{kind:string, url:string, instructions:list<string>}
	 */
	private function buildFineGrained(): array {
		return [
			'kind' => Pat::KIND_FINE_GRAINED,
			'url' => self::FINE_GRAINED_BASE,
			'instructions' => [
				'Repository access: choose "Only select repositories" and pick the ones App Versions should install from.',
				'Permissions → Repository permissions → Contents: Read-only.',
				'Metadata: Read-only (this is auto-included; do not change).',
				'Set an expiration of 90 days or less.',
				'Click "Generate token" and paste the resulting `github_pat_...` value back into App Versions.',
			],
		];
	}

	private function describeHost(): string {
		$host = $this->request->getServerHost();

		return $host !== '' ? $host : 'Nextcloud';
	}
}
