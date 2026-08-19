import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import { expect, type Page } from '@playwright/test'

const execFileAsync = promisify(execFile)

export const SETTINGS_URL = '/index.php/settings/admin/app_versions'

/** Tab labels as rendered in the App Versions settings tablist. */
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
	await expect(page.getByRole('heading', { name: 'App Versions', level: 2 })).toBeVisible()
	await expect(page.getByRole('tablist', { name: 'App Versions sections' })).toBeVisible()
}

/** Switches to a top-level tab and returns its panel locator. */
export async function openTab(page: Page, tab: TabName) {
	await page.getByRole('tablist', { name: 'App Versions sections' })
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
 */
export async function versionsLoaded(page: Page): Promise<boolean> {
	const loading = page.getByText('Fetching available versions from the source')
	await expect(loading).toBeHidden({ timeout: 240_000 })
	const rows = page.getByTestId('changelog-toggle')
	return (await rows.count()) > 0
}

/** Reads an app config value straight from the server, to assert persistence. */
export async function appConfigValue(page: Page, key: string): Promise<string | null> {
	const res = await page.request.get(
		`/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/app_versions/${key}?format=json`,
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

/** The Nextcloud container the fixture app is installed into. */
const NC_CONTAINER = process.env.NC_CONTAINER ?? 'av-e2e'

/**
 * Installs a version of the fixture app and returns the structured outcome.
 *
 * Installs are driven through `occ app_versions:install` rather than the HTTP
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
	const args = ['exec', '-u', 'www-data', NC_CONTAINER, 'php', 'occ', 'app_versions:install',
		FIXTURE_APP, version, `--source=${FIXTURE_SOURCE}`, '--json']
	if (opts.allowDowngrade) args.push('--allow-downgrade')
	if (opts.acceptNewSha) args.push('--accept-new-sha')
	try {
		const { stdout } = await execFileAsync('docker', args, { maxBuffer: 8 * 1024 * 1024 })
		return { status: 0, body: parseLastJson(stdout) }
	} catch (err) {
		// A non-zero exit (guard refused, integrity failure, …) still emits the
		// structured outcome on stdout — surface it with the exit code.
		const e = err as { code?: number; stdout?: string }
		return { status: e.code ?? 1, body: parseLastJson(e.stdout ?? '') }
	}
}

/** Extracts the last JSON object printed by an occ command (ignores warnings). */
function parseLastJson(out: string): any {
	const match = out.match(/\{[\s\S]*\}\s*$/)
	if (!match) return {}
	try { return JSON.parse(match[0]) } catch { return {} }
}

/** Runs an occ command in the Nextcloud container, returning stdout. */
export async function occ(...args: string[]): Promise<string> {
	const { stdout } = await execFileAsync(
		'docker', ['exec', '-u', 'www-data', NC_CONTAINER, 'php', 'occ', ...args],
		{ maxBuffer: 8 * 1024 * 1024 },
	).catch((e) => ({ stdout: (e as { stdout?: string }).stdout ?? '' }))
	return stdout
}

/** Runs a query against the instance's SQLite DB, returning stdout rows. */
export async function sql(query: string): Promise<string> {
	const { stdout } = await execFileAsync('docker', [
		'exec', NC_CONTAINER, 'php', '-r',
		`$p=new PDO("sqlite:/var/www/html/data/nc.db.db");$s=$p->query(${JSON.stringify(query)});foreach($s->fetchAll(PDO::FETCH_NUM) as $r){echo implode("\\t",array_map(fn($v)=>$v??"",$r)),"\\n";}`,
	], { maxBuffer: 8 * 1024 * 1024 }).catch((e) => ({ stdout: (e as { stdout?: string }).stdout ?? '' }))
	return stdout.trim()
}

/** Runs a mutating SQL statement against the instance's SQLite DB. */
export async function sqlExec(stmt: string): Promise<void> {
	await execFileAsync('docker', [
		'exec', NC_CONTAINER, 'php', '-r',
		`$p=new PDO("sqlite:/var/www/html/data/nc.db.db");$p->exec(${JSON.stringify(stmt)});`,
	]).catch(() => undefined)
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
	await page.request.delete(`/ocs/v2.php/apps/app_versions/api/app/${FIXTURE_APP}/pin?format=json`, {
		headers: { 'OCS-APIRequest': 'true' },
	}).catch(() => undefined)
	await occ('config:app:set', 'app_versions', `source.${FIXTURE_APP}`, '--value', CLEAN_FIXTURE_BINDING)
	// Clear the artifact cache too: a genuine copy cached by a prior test would
	// otherwise be served as a download fallback and mask a tampered forge.
	await page.request.delete('/ocs/v2.php/apps/app_versions/api/cache?format=json', {
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
