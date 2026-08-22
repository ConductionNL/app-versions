import { execFile } from 'node:child_process'
import { existsSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { promisify } from 'node:util'
import { expect, type Page } from '@playwright/test'

const execFileAsync = promisify(execFile)

export const SETTINGS_URL = '/index.php/settings/admin/versioniq'

/** Tab labels as rendered in the Versioniq settings tablist. */
export type TabName =
	| 'Apps'
	| 'History'
	| 'Sources'
	| 'Tokens'
	| 'Trusted sources'
	| 'Discover'
	| 'Artifact cache'

/**
 * Opens the admin settings page and waits for the app shell to be interactive.
 *
 * `waitUntil: 'domcontentloaded'` is load-bearing. The default is `'load'`,
 * which waits for every sub-resource — and a Nextcloud settings page keeps
 * requests in flight, so `load` does not fire. The navigation then sat until
 * the 60s test timeout killed it, and Playwright reported the kill as
 *
 *   page.goto: net::ERR_ABORTED; maybe frame was detached?
 *
 * which reads like the PAGE broke. It did not: nothing was ever waiting for
 * the page, only for an event the platform does not emit (the same defect
 * ADR-074 rule 4 names for `networkidle`).
 *
 * Nothing is lost by not waiting for `load`: the two assertions below are the
 * real readiness signal, and they are what the callers actually depend on.
 */
export async function openSettings(page: Page): Promise<void> {
	await page.goto(SETTINGS_URL, { waitUntil: 'domcontentloaded' })
	await expect(page.getByRole('heading', { name: 'Versioniq', level: 2 })).toBeVisible()
	await expect(page.getByRole('tablist', { name: 'Versioniq sections' })).toBeVisible()
}

/** Switches to a top-level tab and returns its panel locator. */
export async function openTab(page: Page, tab: TabName) {
	await page.getByRole('tablist', { name: 'Versioniq sections' })
		.getByRole('tab', { name: tab, exact: true })
		.click()
	const panel = page.getByRole('tabpanel', { name: tab })
	await expect(panel).toBeVisible()
	return panel
}

/**
 * Opens the version picker for an app by its appId, from the Apps tab.
 * Returns once the app-detail header shows the selected app.
 */
export async function chooseApp(page: Page, appId: string): Promise<void> {
	await openTab(page, 'Apps')
	const card = page.locator('article').filter({ has: page.getByText(appId, { exact: true }) }).first()
	await card.getByRole('button', { name: 'Choose app' }).click()
	await expect(page.getByText('Selected app')).toBeVisible()
	await expect(page.getByRole('button', { name: 'Choose another app' })).toBeVisible()
}

/**
 * Waits for the version list of the selected app to finish loading.
 * Returns true when versions rendered, false when the source reported none
 * (offline / not on the App Store) so a spec can skip network-dependent asserts.
 *
 * 🔑 A WAIT LONGER THAN ITS TEST'S TIMEOUT CAN NEVER ELAPSE. This waited
 * 240_000ms inside a test bounded at 60_000 (playwright.config.ts), so it could
 * only ever use a quarter of its stated patience — and when loading did stall,
 * the failure read `Test timeout of 60000ms exceeded` rather than naming the
 * thing that never finished. Same arithmetic-opposition defect as a bounded
 * apt retry inside a shorter job cap (ConductionNL/.github#510).
 *
 * 240s was calibrated for the real App Store (~12.4 MB from garm3). The fixture
 * now serves that catalogue locally, so the budget belongs inside the test
 * bound, where it can actually be spent. A spec that legitimately needs longer
 * should raise its own timeout with `test.setTimeout()` — deliberately, and
 * visibly, rather than inheriting a number that silently cannot apply.
 */
export async function versionsLoaded(page: Page): Promise<boolean> {
	const loading = page.getByText('Fetching available versions from the source')
	await expect(
		loading,
		'the version list never finished loading — the source did not answer',
	).toBeHidden({ timeout: 45_000 })
	const rows = page.getByTestId('changelog-toggle')
	return (await rows.count()) > 0
}

/** Reads an app config value straight from the server, to assert persistence. */
export async function appConfigValue(page: Page, key: string): Promise<string | null> {
	const res = await page.request.get(
		`/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/versioniq/${key}?format=json`,
		{ headers: { 'OCS-APIRequest': 'true' } },
	)
	if (!res.ok()) {
		return null
	}
	const body = await res.json()
	const data = body?.ocs?.data?.data
	return typeof data === 'string' && data !== '' ? data : null
}

// --- Forge fixture ---------------------------------------------------------
// The fixture forge (tests/e2e/fixtures/forge) must be bootstrapped before the
// forge specs run (see docs/e2e.md and fixtures/forge/bootstrap.sh). These
// helpers drive its control plane and the app's install API.

/** Base URL of the fixture forge's control plane, as reachable from the host. */
export const FIXTURE_URL = process.env.FORGE_FIXTURE_URL ?? 'http://localhost:9099'

/** The app installed from the fixture forge, and the source it is bound to. */
export const FIXTURE_APP = 'fixtureapp'
export const FIXTURE_SOURCE = 'codeberg:fixtureowner/fixtureapp'

/** Whether the fixture forge is reachable — forge specs skip when it is not. */
export async function fixtureAvailable(page: Page): Promise<boolean> {
	try {
		const res = await page.request.get(`${FIXTURE_URL}/health`, { timeout: 5_000 })
		return res.ok()
	} catch {
		return false
	}
}

/** Resets the fixture forge to its default release set and clears overrides. */
export async function resetFixture(page: Page): Promise<void> {
	await page.request.post(`${FIXTURE_URL}/control/reset`)
}

/** Posts a control command to the fixture forge. */
export async function fixtureControl(page: Page, path: string, body: unknown): Promise<void> {
	const res = await page.request.post(`${FIXTURE_URL}/control/${path}`, { data: body as object })
	if (!res.ok()) {
		throw new Error(`fixture control ${path} failed: ${res.status()}`)
	}
}

/**
 * How to reach the instance: a Docker container locally, or the runner's own
 * filesystem in CI.
 *
 * ⚠️ EVERY `occ` AND `sql` HELPER USED TO SHELL OUT TO `docker exec av-e2e`,
 * and both swallowed the failure into an empty string. In CI there is no such
 * container — the shared quality.yml runs the PHP built-in server on the runner
 * — so every one of those calls failed silently and the specs that depend on
 * them failed on opaque assertions (`Expected: 0, Received: 1`) that named
 * neither Docker nor the missing container.
 *
 * The mode is DETECTED rather than configured, so neither the developer setup
 * nor the shared workflow needs a new environment variable: Playwright runs
 * with cwd inside the app, which in CI sits at `<server>/apps/versioniq`, so
 * walking up for an `occ` file finds the server root. A developer checkout is
 * not inside a Nextcloud tree, finds nothing, and keeps the Docker behaviour.
 * An explicit `NC_CONTAINER` or `NC_SERVER_ROOT` overrides the detection.
 */
type Instance =
	| { mode: 'docker'; container: string }
	| { mode: 'local'; root: string }

function resolveInstance(): Instance {
	if (process.env.NC_CONTAINER) {
		return { mode: 'docker', container: process.env.NC_CONTAINER }
	}
	if (process.env.NC_SERVER_ROOT) {
		return { mode: 'local', root: process.env.NC_SERVER_ROOT }
	}

	let dir = process.cwd()
	for (let i = 0; i < 6; i++) {
		if (existsSync(join(dir, 'occ'))) {
			return { mode: 'local', root: dir }
		}
		const up = dirname(dir)
		if (up === dir) break
		dir = up
	}

	return { mode: 'docker', container: 'av-e2e' }
}

export const INSTANCE = resolveInstance()

/**
 * Runs a command against the instance, wherever it lives.
 *
 * Returns the exit code alongside the output instead of throwing, because
 * several specs assert on a NON-ZERO exit (a refused downgrade, an integrity
 * failure) — that is the behaviour under test, not an error. `stderr` is
 * returned too: the previous helpers discarded it, which is how "docker: not
 * found" became an empty string and then a confusing assertion.
 */
export async function execInInstance(
	argv: string[],
	opts: { asRoot?: boolean; env?: Record<string, string> } = {},
): Promise<{ code: number; stdout: string; stderr: string }> {
	let cmd: string
	let args: string[]
	let spawnOpts: Record<string, unknown>

	if (INSTANCE.mode === 'docker') {
		const envArgs = Object.entries(opts.env ?? {}).flatMap(([k, v]) => ['-e', `${k}=${v}`])
		cmd = 'docker'
		args = ['exec', ...envArgs, '-u', opts.asRoot ? 'root' : 'www-data', INSTANCE.container, ...argv]
		spawnOpts = {}
	} else {
		// On a runner the tests own the tree, so `asRoot` has nothing to grant
		// and is deliberately a no-op rather than a sudo escalation.
		cmd = argv[0]
		args = argv.slice(1)
		spawnOpts = { cwd: INSTANCE.root, env: { ...process.env, ...(opts.env ?? {}) } }
	}

	try {
		const { stdout, stderr } = await execFileAsync(cmd, args, {
			maxBuffer: 8 * 1024 * 1024,
			...spawnOpts,
		})
		return { code: 0, stdout, stderr }
	} catch (err) {
		const e = err as { code?: number; stdout?: string; stderr?: string; message?: string }
		return {
			code: typeof e.code === 'number' ? e.code : 1,
			stdout: e.stdout ?? '',
			stderr: e.stderr ?? e.message ?? '',
		}
	}
}

/**
 * A `php -r` snippet that opens the instance's OWN database.
 *
 * The previous helpers hard-coded `sqlite:/var/www/html/data/nc.db.db`, which
 * is right for the Docker image and wrong everywhere else — CI runs pgsql, so
 * every query would have failed even once the container problem was fixed.
 * Reading `config/config.php` means the helper follows the instance rather than
 * a remembered layout.
 */
function dbPrelude(): string {
	return [
		'$CONFIG=[];require "config/config.php";$c=$CONFIG;',
		'$t=$c["dbtype"]??"sqlite3";',
		// `dbhost` IS NOT A HOSTNAME. Nextcloud stores host, host:port, or
		// :/path/to/socket in that one key, and CI's config holds
		// "127.0.0.1:5432" — pasted straight into `host=`, PDO reads the whole
		// string as a name and fails with
		//   SQLSTATE[08006] could not translate host name "127.0.0.1:5432"
		// which every caller then saw as an empty result, i.e. "zero rows".
		'$hraw=(string)($c["dbhost"]??"localhost");$hp="";$hs="";',
		'if(str_starts_with($hraw,":")){$hs=substr($hraw,1);$hraw="localhost";}',
		'elseif(str_contains($hraw,":")){[$hraw,$tail]=explode(":",$hraw,2);if(ctype_digit($tail)){$hp=$tail;}else{$hs=$tail;}}',
		'if($t==="sqlite3"){$dsn="sqlite:".($c["datadirectory"]??"data")."/".($c["dbname"]??"owncloud").".db";$u=null;$w=null;}',
		'elseif($t==="pgsql"){$dsn="pgsql:host=".$hraw.($hp!==""?";port=".$hp:"").";dbname=".$c["dbname"];$u=$c["dbuser"];$w=$c["dbpassword"];}',
		'else{$dsn="mysql:".($hs!==""?"unix_socket=".$hs:"host=".$hraw.($hp!==""?";port=".$hp:"")).";dbname=".$c["dbname"];$u=$c["dbuser"];$w=$c["dbpassword"];}',
		'$p=new PDO($dsn,$u,$w,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);',
	].join('')
}

/**
 * Installs a version of the fixture app and returns the structured outcome.
 *
 * Installs are driven through `occ versioniq:install` rather than the HTTP
 * API. Both call the same `InstallerService::installAppVersion`, so the forge
 * integration under test is identical — but the install swaps app files and
 * calls `opcache_reset()`, which under the test image's mod_php poisons the
 * shared web opcache and 503s the instance. `occ` runs with opcache disabled
 * (`opcache.enable_cli=Off`) and is the reproducible-provisioning path these
 * commands exist for, so it exercises the engine without the harness artifact.
 * The single HTTP-path install is covered separately in version-management.
 */
export async function installFixture(
	page: Page,
	version: string,
	opts: { allowDowngrade?: boolean; acceptNewSha?: boolean } = {},
): Promise<{ status: number; body: any }> {
	void page
	const args = ['php', 'occ', 'versioniq:install',
		FIXTURE_APP, version, `--source=${FIXTURE_SOURCE}`, '--json']
	if (opts.allowDowngrade) args.push('--allow-downgrade')
	if (opts.acceptNewSha) args.push('--accept-new-sha')

	// A non-zero exit (guard refused, integrity failure, …) still emits the
	// structured outcome on stdout — surface it with the exit code.
	const { code, stdout } = await execInInstance(args)
	return { status: code, body: parseLastJson(stdout) }
}

/** Extracts the last JSON object printed by an occ command (ignores warnings). */
function parseLastJson(out: string): any {
	const match = out.match(/\{[\s\S]*\}\s*$/)
	if (!match) return {}
	try { return JSON.parse(match[0]) } catch { return {} }
}

/**
 * A `YYYY-MM-DD HH:MM:SS` timestamp offset from now.
 *
 * The specs used SQLite's `datetime('now','-1 day')` and
 * `strftime('%Y-%m-%d %H:%M:%S','now')` inline. Those are not SQL — they are
 * SQLite builtins, and on the pgsql instance CI runs they are simply unknown
 * functions, so every statement carrying one fails. Computing the literal here
 * keeps the statements portable across sqlite, pgsql and mysql alike.
 *
 * @param days Offset in days; negative for the past.
 */
export function tsOffset(days = 0): string {
	return new Date(Date.now() + (days * 86_400_000)).toISOString().slice(0, 19).replace('T', ' ')
}

/**
 * What the discover API actually said, as an assertion message.
 *
 * The discover endpoint returns `{ results, providers, errors }`, and the specs
 * read only `results`. So when a provider fails — the App Store unreachable, a
 * forge refusing — the test reports `element(s) not found` or
 * `expect(0).toBeGreaterThan(0)`, which describes the SYMPTOM and hides the one
 * field that names the cause. The API is already telling us; nothing was
 * listening.
 *
 * Passed as the assertion's message so a failure carries the provider errors
 * beside it. It runs the same query the UI runs, so a search that works here
 * and not in the browser is itself the finding.
 *
 * @param page The Playwright page (used for its request context).
 * @param query The search term.
 */
export async function discoverDiagnostics(page: Page, query: string): Promise<string> {
	try {
		const res = await page.request.get(
			`/ocs/v2.php/apps/versioniq/api/discover?q=${encodeURIComponent(query)}&format=json`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		const data = (await res.json())?.ocs?.data ?? {}
		const errors = data.errors ?? []
		const providers = (data.providers ?? []).map((p: { id: string, enabled: boolean }) => `${p.id}=${p.enabled ? 'on' : 'off'}`)
		return `discover(${query}) -> ${(data.results ?? []).length} result(s); `
			+ `providers: ${providers.join(', ') || 'none'}; `
			+ `errors: ${errors.length > 0 ? JSON.stringify(errors) : 'none'}`
	} catch (err) {
		return `discover(${query}) -> the diagnostic request itself failed: ${String(err)}`
	}
}

/** Runs an occ command against the instance, returning stdout. */
export async function occ(...args: string[]): Promise<string> {
	const { stdout } = await execInInstance(['php', 'occ', ...args])
	return stdout
}

/** Runs a query against the instance's own database, returning stdout rows. */
export async function sql(query: string): Promise<string> {
	const { code, stdout, stderr } = await execInInstance([
		'php', '-r',
		`${dbPrelude()}$s=$p->query(${JSON.stringify(query)});foreach($s->fetchAll(PDO::FETCH_NUM) as $r){echo implode("\\t",array_map(fn($v)=>$v??"",$r)),"\\n";}`,
	])
	reportDbFailure('sql', query, code, stderr)
	return stdout.trim()
}

/** Runs a mutating SQL statement against the instance's own database. */
export async function sqlExec(stmt: string): Promise<void> {
	const { code, stderr } = await execInInstance([
		'php', '-r',
		`${dbPrelude()}$p->exec(${JSON.stringify(stmt)});`,
	])
	reportDbFailure('sqlExec', stmt, code, stderr)
}

/**
 * Surfaces a failed database call instead of letting it become an empty string.
 *
 * ⚠️ AN EMPTY RESULT AND A FAILED QUERY LOOK IDENTICAL TO THE CALLER, and the
 * callers all read the empty string as "zero rows". Measured 2026-08-19: a
 * dozen specs failed with `expect(received).toBeGreaterThan(0) / Received: 0`
 * — a message about the app's data, produced by a query that never ran.
 *
 * This deliberately does NOT throw. Several specs legitimately expect zero rows
 * (a swept PAT, a pruned audit row), so turning every failure into an exception
 * would replace one wrong answer with another. It writes the real cause to the
 * Playwright output, where the next reader can see it next to the assertion it
 * explains.
 *
 * @param helper Which helper failed.
 * @param statement The SQL that was attempted.
 * @param code The exit code from the instance.
 * @param stderr Whatever PHP said about it.
 */
function reportDbFailure(helper: string, statement: string, code: number, stderr: string): void {
	if (code === 0) {
		return
	}

	console.error(
		`[e2e] ${helper}() exited ${code} — the result below is NOT "zero rows", it is a failed query.\n`
		+ `      statement: ${statement}\n`
		+ `      stderr:    ${stderr.trim() || '(none)'}`,
	)
}

/** Force-executes an app background job by class-name substring, once. */
export async function runJob(classSubstring: string): Promise<void> {
	const id = (await sql(`SELECT id FROM oc_jobs WHERE class LIKE '%${classSubstring}%' LIMIT 1`)).split('\t')[0].trim()
	if (id) {
		await occ('background-job:execute', id, '--force-execute')
	}
}

/** The fixture app's clean source binding, with no recorded digests. */
const CLEAN_FIXTURE_BINDING = JSON.stringify({
	kind: 'github-release',
	forge: 'codeberg',
	owner: 'fixtureowner',
	repo: 'fixtureapp',
	assetPattern: '*.tar.gz',
})

/** Resets the fixture app to its 1.0.0 baseline via the real install path. */
export async function resetFixtureApp(page: Page): Promise<void> {
	// Restore the fixture forge's default release set + clear asset overrides
	// first, so the baseline install below can always fetch a genuine 1.0.0.
	await resetFixture(page)
	// Clear any pin and — crucially — the recorded SHA-256 map, which lives in
	// the binding config and would otherwise leak across tests (a test that
	// records a rewritten digest would make the next test's tamper "match").
	await page.request.delete(`/ocs/v2.php/apps/versioniq/api/app/${FIXTURE_APP}/pin?format=json`, {
		headers: { 'OCS-APIRequest': 'true' },
	}).catch(() => undefined)
	await occ('config:app:set', 'versioniq', `source.${FIXTURE_APP}`, '--value', CLEAN_FIXTURE_BINDING)
	// Clear the artifact cache too: a genuine copy cached by a prior test would
	// otherwise be served as a download fallback and mask a tampered forge.
	await page.request.delete('/ocs/v2.php/apps/versioniq/api/cache?format=json', {
		headers: { 'OCS-APIRequest': 'true' },
	}).catch(() => undefined)

	// Install the baseline, retrying once: rapid sequential installs each toggle
	// maintenance mode, and an occasional overlap can make one attempt a no-op.
	for (let attempt = 0; attempt < 2; attempt++) {
		const { body } = await installFixture(page, '1.0.0', { allowDowngrade: true })
		await occ('maintenance:mode', '--off')
		if (body.installedVersion === '1.0.0' || body.updateType === 'none') break
	}
}
