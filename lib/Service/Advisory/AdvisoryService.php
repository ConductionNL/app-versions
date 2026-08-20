<?php

declare(strict_types=1);
/**
 * @license EUPL-1.2
 * @copyright Copyright (c) 2025, Conduction B.V. <info@conduction.nl>
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */


namespace OCA\AppVersions\Service\Advisory;

use OCA\AppVersions\Service\Source\SourceBinding;
use OCA\AppVersions\Service\Source\SourceBindingStore;
use OCA\AppVersions\Service\Source\SourceRegistry;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Correlates the currently-installed/pinned version of each app against the
 * published security advisories resolved from the app's bound source (the
 * App Store security feed for store-sourced apps; the forge advisory endpoint
 * for external-sourced apps).
 *
 * The correlation is read-only: it never installs, unpins, or otherwise mutates
 * an app's version — App Versions surfaces the advisory and the recommended
 * safe version, and the administrator decides. External fetches are delegated
 * to the source drivers (which reuse the existing PAT/credential path); this
 * service adds no HTTP client of its own.
 *
 * States (per app):
 *   - `pinned-to-vulnerable`: the installed/pinned version is itself within an
 *     advisory's affected range. This is the failure mode this tool can
 *     uniquely create (deliberate pinning to an older version) and is therefore
 *     the prominent, must-own state.
 *   - `advisory-available`: the installed version is NOT affected, but the
 *     source reports advisories for the app affecting other (older) versions —
 *     i.e. the app has a security history and the installed version is at or
 *     above the fix. Informational.
 *   - `none`: the source reports no advisories for the app.
 *
 * @psalm-api
 */
class AdvisoryService {
	public const STATE_NONE = 'none';

	/**
	 * Wall-clock ceiling for a full correlateAll() sweep, in seconds.
	 *
	 * Chosen against the frontend that consumes it: App.vue aborts its
	 * background fetches at 8s, so a server budget above that would be spent
	 * producing a response nobody is still waiting for. 5s leaves room for the
	 * response to be serialised and delivered inside that window — a bound must
	 * fit inside the bound that contains it.
	 *
	 * @var float
	 */
	private const CORRELATE_ALL_BUDGET_SECONDS = 5.0;
	public const STATE_AVAILABLE = 'advisory-available';
	public const STATE_VULNERABLE = 'pinned-to-vulnerable';

	public function __construct(
		private SourceRegistry $sourceRegistry,
		private SourceBindingStore $bindingStore,
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Correlates a single app: resolves its binding + source, fetches advisories
	 * and available versions, and evaluates the state against the installed
	 * version. Returns a `none` result (no error) for apps whose source cannot
	 * answer advisories, so callers can treat the map uniformly.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @return array{appId: string, installedVersion: ?string, state: string, advisories: list<array{id: string, severity: string, summary: string}>, recommendedVersion: ?string, error: ?string}
	 */
	public function correlate(string $appId): array {
		$installedVersion = $this->installedVersion($appId);

		$binding = $this->bindingStore->get($appId) ?? SourceBinding::appStore();
		$source = $this->sourceRegistry->get($binding);

		if (!$source instanceof AdvisorySourceInterface) {
			return $this->emptyResult($appId, $installedVersion);
		}

		if ($installedVersion === null) {
			return $this->emptyResult($appId, null);
		}

		$advisoryResult = $source->listAdvisories($appId, $binding);
		$advisories = $advisoryResult['advisories'];
		$error = $advisoryResult['error'];

		$available = [];
		$versionResult = $source->listVersions($appId, $binding);
		foreach ($versionResult['versions'] as $entry) {
			$available[] = $entry['version'];
		}

		$evaluated = $this->evaluate($installedVersion, $advisories, $available);
		$evaluated['appId'] = $appId;
		$evaluated['error'] = $error;

		return $evaluated;
	}

	/**
	 * Correlates every enabled app, keyed by app id. Individual app failures are
	 * logged and surfaced as an `error` on that app's entry, never thrown, so a
	 * single unreachable source does not abort the whole sweep.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @return array<string, array{appId: string, installedVersion: ?string, state: string, advisories: list<array{id: string, severity: string, summary: string}>, recommendedVersion: ?string, error: ?string}>
	 */
	public function correlateAll(): array {
		$results = [];
		$deadline = microtime(true) + self::CORRELATE_ALL_BUDGET_SECONDS;

		foreach ($this->appManager->getEnabledApps() as $appId) {
			// BUDGET, BECAUSE THIS ENDPOINT COULD NOT PREVIOUSLY RETURN AT ALL.
			//
			// correlate() makes TWO source calls per app (listAdvisories and
			// listVersions), so an instance with 88 enabled apps issues 176
			// sequential external calls on a page-load path. Measured on a live
			// instance: /api/advisories did not answer within 120s, twice.
			//
			// It could not even warm its own per-app payload cache, because the
			// cache is written on completion and the request never completed —
			// so a second call was exactly as slow as the first.
			//
			// The knock-on was worse than a slow badge: this is dispatched
			// first of the three background loaders, and while it held the PHP
			// session lock /api/pins never ran, so pin badges never rendered
			// and nothing anywhere reported why (issue #160).
			//
			// Apps not reached report `error` rather than being dropped: the
			// caller already treats that field as "could not answer", so
			// coverage is unchanged in shape — what changes is that a slow
			// source degrades to a stated gap instead of an endpoint that hangs.
			if (microtime(true) >= $deadline) {
				$results[$appId] = $this->emptyResult(
					$appId,
					$this->installedVersion($appId),
					'Advisory correlation budget exceeded before this app was reached; its sources were not queried.'
				);
				continue;
			}

			try {
				$results[$appId] = $this->correlate($appId);
			} catch (\Throwable $error) {
				$this->logger->warning('AdvisoryService: correlation failed for app', [
					'app' => $appId,
					'message' => $error->getMessage(),
				]);
				$results[$appId] = $this->emptyResult($appId, $this->installedVersion($appId), $error->getMessage());
			}
		}

		return $results;
	}

	/**
	 * Pure correlation: given the installed version, the advisories from the
	 * source, and the versions available from the source, computes the advisory
	 * state and the nearest resolving version. No I/O — this is the unit-tested
	 * core of the feature.
	 *
	 * @spec openspec/specs/security-advisory-correlation/spec.md
	 * @param list<array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string}> $advisories
	 * @param list<string> $availableVersions
	 * @return array{appId: string, installedVersion: ?string, state: string, advisories: list<array{id: string, severity: string, summary: string}>, recommendedVersion: ?string, error: ?string}
	 */
	public function evaluate(string $installedVersion, array $advisories, array $availableVersions): array {
		if ($advisories === []) {
			return [
				'appId' => '',
				'installedVersion' => $installedVersion,
				'state' => self::STATE_NONE,
				'advisories' => [],
				'recommendedVersion' => null,
				'error' => null,
			];
		}

		$active = array_values(array_filter(
			$advisories,
			fn (array $advisory): bool => $this->isAffected($installedVersion, $advisory['affected']),
		));

		if ($active === []) {
			// The app has advisories, but none affect the installed version:
			// the installed version is already safe.
			return [
				'appId' => '',
				'installedVersion' => $installedVersion,
				'state' => self::STATE_AVAILABLE,
				'advisories' => $this->summarise($advisories),
				'recommendedVersion' => null,
				'error' => null,
			];
		}

		return [
			'appId' => '',
			'installedVersion' => $installedVersion,
			'state' => self::STATE_VULNERABLE,
			'advisories' => $this->summarise($active),
			'recommendedVersion' => $this->nearestResolving($installedVersion, $active, $availableVersions),
			'error' => null,
		];
	}

	/**
	 * Reduces advisory records to the id/severity/summary triple surfaced to
	 * the admin (drops the internal affected-range / patch fields).
	 *
	 * @param list<array{id: string, severity: string, summary: string, affected?: list<string>, firstPatchedVersion?: ?string}> $advisories
	 * @return list<array{id: string, severity: string, summary: string}>
	 */
	private function summarise(array $advisories): array {
		return array_values(array_map(
			static fn (array $a): array => [
				'id' => $a['id'],
				'severity' => $a['severity'],
				'summary' => $a['summary'],
			],
			$advisories,
		));
	}

	/**
	 * A version is affected by an advisory when EVERY affected clause holds
	 * (AND semantics — a range such as ">= 1.0.0, < 1.2.3" is two clauses). An
	 * empty clause list means "all versions affected".
	 *
	 * @param list<string> $affected
	 */
	private function isAffected(string $version, array $affected): bool {
		if ($affected === []) {
			return true;
		}
		foreach ($affected as $clause) {
			if (!$this->satisfiesClause($version, $clause)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Evaluates a single `op version` clause (e.g. `< 1.2.3`, `>= 1.0.0`,
	 * `= 1.0.0`) against a version using PHP's version_compare. A bare version
	 * with no operator is treated as equality. Unparseable clauses are treated
	 * as non-matching (fail-safe: they do not spuriously mark a version
	 * affected).
	 */
	private function satisfiesClause(string $version, string $clause): bool {
		$clause = trim($clause);
		if ($clause === '') {
			return true;
		}

		if (preg_match('/^(<=|>=|<|>|=)?\s*(.+)$/', $clause, $matches) !== 1) {
			return false;
		}
		$operator = $matches[1] !== '' ? $matches[1] : '=';
		$bound = trim($matches[2]);
		if ($bound === '') {
			return false;
		}

		return version_compare($version, $bound, $operator);
	}

	/**
	 * The nearest version, strictly newer than the installed one, that is not
	 * affected by any of the active advisories — the recommended safe upgrade.
	 * Prefers the published `firstPatchedVersion` when it is offered by the
	 * source; otherwise scans the available versions ascending. Returns null
	 * when the source offers no resolving version (stuck-on-vulnerable).
	 *
	 * @param list<array{id: string, severity: string, summary: string, affected: list<string>, firstPatchedVersion: ?string}> $active
	 * @param list<string> $availableVersions
	 */
	private function nearestResolving(string $installedVersion, array $active, array $availableVersions): ?string {
		$candidates = [];
		foreach ($availableVersions as $candidate) {
			if (version_compare($candidate, $installedVersion, '>')) {
				$candidates[] = $candidate;
			}
		}
		// Fold in any published first-patched versions the source named, even
		// if they were not in the available-versions list.
		foreach ($active as $advisory) {
			$patched = $advisory['firstPatchedVersion'];
			if (is_string($patched) && $patched !== '' && version_compare($patched, $installedVersion, '>')) {
				$candidates[] = $patched;
			}
		}
		$candidates = array_values(array_unique($candidates));
		usort($candidates, static fn (string $a, string $b): int => version_compare($a, $b));

		foreach ($candidates as $candidate) {
			$stillVulnerable = false;
			foreach ($active as $advisory) {
				if ($this->isAffected($candidate, $advisory['affected'])) {
					$stillVulnerable = true;
					break;
				}
			}
			if (!$stillVulnerable) {
				return $candidate;
			}
		}

		return null;
	}

	private function installedVersion(string $appId): ?string {
		try {
			$installed = $this->appManager->getAppVersion($appId);
		} catch (\Throwable) {
			return null;
		}

		return $installed !== '' ? $installed : null;
	}

	/**
	 * @return array{appId: string, installedVersion: ?string, state: string, advisories: list<array{id: string, severity: string, summary: string}>, recommendedVersion: ?string, error: ?string}
	 */
	private function emptyResult(string $appId, ?string $installedVersion, ?string $error = null): array {
		return [
			'appId' => $appId,
			'installedVersion' => $installedVersion,
			'state' => self::STATE_NONE,
			'advisories' => [],
			'recommendedVersion' => null,
			'error' => $error,
		];
	}
}
