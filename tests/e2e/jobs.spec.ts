import { expect, test } from '@playwright/test'
import {
	FIXTURE_APP,
	FIXTURE_SOURCE,
	appConfigValue,
	fixtureAvailable,
	fixtureControl,
	installFixture,
	occ,
	resetFixtureApp,
	runJob,
	sql,
	sqlExec,
} from './helpers'

/**
 * Background jobs, driven with `occ background-job:execute --force-execute` so
 * the nightly TimedJobs run on demand. Covers auto-update execution, PAT expiry
 * warnings, audit retention pruning, and pin-drift reconciliation.
 *
 * @spec openspec/specs/auto-update-policies/spec.md
 * @spec openspec/specs/pat-management/spec.md
 * @spec openspec/specs/audit-trail/spec.md
 * @spec openspec/specs/version-pinning/spec.md
 */
test.describe('background jobs', () => {
	test.beforeEach(async ({ page }) => {
		test.skip(!(await fixtureAvailable(page)), 'forge fixture not running')
		await resetFixtureApp(page)
	})

	async function setPolicy(page: import('@playwright/test').Page, level: string) {
		await page.request.put(`/ocs/v2.php/apps/app_versions/api/app/${FIXTURE_APP}/policy?format=json`, {
			headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data: { level },
		})
	}
	const installed = async () => (await occ('config:app:get', FIXTURE_APP, 'installed_version')).trim()

	test.afterEach(async ({ page }) => {
		await occ('config:app:delete', 'app_versions', 'auto_update_enabled')
		await occ('config:app:delete', 'app_versions', 'auto_update_window')
		await occ('config:app:delete', 'app_versions', `auto_attempt.${FIXTURE_APP}`)
		await page.request.delete(`/ocs/v2.php/apps/app_versions/api/app/${FIXTURE_APP}/policy?format=json`, {
			headers: { 'OCS-APIRequest': 'true' },
		}).catch(() => undefined)
		await page.request.delete(`/ocs/v2.php/apps/app_versions/api/app/${FIXTURE_APP}/pin?format=json`, {
			headers: { 'OCS-APIRequest': 'true' },
		}).catch(() => undefined)
	})

	// --- auto-update job ---------------------------------------------------
	test('a patch policy applies the highest same-minor release, and notifies', async ({ page }) => {
		expect(await installed()).toBe('1.0.0')
		await sqlExec("DELETE FROM oc_notifications WHERE app='app_versions'")
		await setPolicy(page, 'patch')
		await occ('config:app:set', 'app_versions', 'auto_update_enabled', '--value', '1')
		await occ('config:app:set', 'app_versions', 'auto_update_window', '--value', '00:00-23:59')

		await runJob('AutoUpdateJob')
		// patch of 1.0.0 -> 1.0.1 (1.1.0/1.2.0 are minor and must be skipped).
		expect(await installed()).toBe('1.0.1')
		// The outcome is reported to admins.
		const subj = await sql("SELECT subject FROM oc_notifications WHERE app='app_versions'")
		expect(subj).toContain('auto_update_success')
	})

	test('a pinned app is skipped by the auto-update job', async ({ page }) => {
		await setPolicy(page, 'all')
		await page.request.put(`/ocs/v2.php/apps/app_versions/api/app/${FIXTURE_APP}/pin?format=json`, {
			headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }, data: {},
		})
		await occ('config:app:set', 'app_versions', 'auto_update_enabled', '--value', '1')
		await occ('config:app:set', 'app_versions', 'auto_update_window', '--value', '00:00-23:59')

		await runJob('AutoUpdateJob')
		expect(await installed(), 'pinned app not updated').toBe('1.0.0')
	})

	test('the job is a no-op while the kill switch is off', async ({ page }) => {
		await setPolicy(page, 'all')
		await occ('config:app:set', 'app_versions', 'auto_update_enabled', '--value', '0')

		await runJob('AutoUpdateJob')
		expect(await installed(), 'disabled -> no update').toBe('1.0.0')
	})

	test('a failed auto-update attempt is not retried', async ({ page }) => {
		await setPolicy(page, 'all')
		await occ('config:app:set', 'app_versions', 'auto_update_enabled', '--value', '1')
		await occ('config:app:set', 'app_versions', 'auto_update_window', '--value', '00:00-23:59')
		// Make the highest candidate (1.2.0) fail by serving a mismatched archive.
		await fixtureControl(page, 'repo', {
			repo: 'fixtureowner/fixtureapp',
			releases: [{ tag: 'v1.2.0', asset: 'fixtureapp-wrongversion.tar.gz', sha: true }],
		})
		await sqlExec("DELETE FROM oc_notifications WHERE app='app_versions'")
		await runJob('AutoUpdateJob')
		expect(await installed(), 'failed install left the version alone').toBe('1.0.0')
		const ledger = await appConfigValue(page, `auto_attempt.${FIXTURE_APP}`)
		expect(ledger, 'the failed attempt was recorded').toBeTruthy()
		expect(ledger).toContain('1.2.0')
		// The failure is reported to admins with its classification.
		expect(await sql("SELECT subject FROM oc_notifications WHERE app='app_versions'")).toContain('auto_update_failure')
	})

	// --- PAT expiry warning job -------------------------------------------
	test('the expiry job warns once for a token nearing expiry', async ({ page }) => {
		// Create a codeberg PAT, then age its expiry to 10 days out (crosses 14d).
		await page.request.post('/ocs/v2.php/apps/app_versions/api/pats?format=json', {
			headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data: { forge: 'codeberg', label: 'expiring', targetPattern: 'fixtureowner/*', token: 'codeberg-expiry-token' },
		})
		const id = (await sql("SELECT id FROM oc_app_versions_pats WHERE label='expiring' LIMIT 1")).split('\t')[0]
		await sqlExec(`UPDATE oc_app_versions_pats SET expires_at = datetime('now','+10 days'), warned_thresholds='[]' WHERE id=${id}`)

		await runJob('PatExpiryWarningJob')
		const warned = (await sql(`SELECT warned_thresholds FROM oc_app_versions_pats WHERE id=${id}`)).trim()
		expect(warned, 'a threshold was recorded as warned').toMatch(/14d|3d|expired/)

		// Second run must not add another threshold entry (warn at most once).
		const before = warned
		await runJob('PatExpiryWarningJob')
		expect((await sql(`SELECT warned_thresholds FROM oc_app_versions_pats WHERE id=${id}`)).trim()).toBe(before)

		// cleanup
		await page.request.delete(`/ocs/v2.php/apps/app_versions/api/pats/${id}?format=json`, {
			headers: { 'OCS-APIRequest': 'true' },
		}).catch(() => undefined)
	})

	test('the expiry job leaves a token with no known expiry alone', async ({ page }) => {
		await page.request.post('/ocs/v2.php/apps/app_versions/api/pats?format=json', {
			headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data: { forge: 'codeberg', label: 'noexpiry', targetPattern: 'fixtureowner/*', token: 'codeberg-noexp-token' },
		})
		const id = (await sql("SELECT id FROM oc_app_versions_pats WHERE label='noexpiry' LIMIT 1")).split('\t')[0]
		await sqlExec(`UPDATE oc_app_versions_pats SET expires_at=NULL, warned_thresholds='[]' WHERE id=${id}`)

		await runJob('PatExpiryWarningJob')
		expect((await sql(`SELECT warned_thresholds FROM oc_app_versions_pats WHERE id=${id}`)).trim()).toBe('[]')

		await page.request.delete(`/ocs/v2.php/apps/app_versions/api/pats/${id}?format=json`, {
			headers: { 'OCS-APIRequest': 'true' },
		}).catch(() => undefined)
	})

	// --- audit retention prune job ----------------------------------------
	test('the prune job removes entries older than the retention window', async ({ page }) => {
		// Seed one clearly-old audit row and one recent row.
		await sqlExec("INSERT INTO oc_app_versions_audit (actor_uid, app_id, operation, status, created_at) VALUES ('system','prunetest','install','success', strftime('%Y-%m-%d %H:%M:%S','now','-400 days'))")
		await sqlExec("INSERT INTO oc_app_versions_audit (actor_uid, app_id, operation, status, created_at) VALUES ('system','prunetest','install','success', strftime('%Y-%m-%d %H:%M:%S','now'))")
		await occ('config:app:set', 'app_versions', 'audit_retention_days', '--value', '365')

		const oldBefore = await sql("SELECT count(*) FROM oc_app_versions_audit WHERE app_id='prunetest' AND created_at < datetime('now','-365 days')")
		expect(Number(oldBefore)).toBeGreaterThan(0)

		await runJob('PruneAuditJob')
		expect(Number(await sql("SELECT count(*) FROM oc_app_versions_audit WHERE app_id='prunetest' AND created_at < datetime('now','-365 days')"))).toBe(0)
		// The recent row survives.
		expect(Number(await sql("SELECT count(*) FROM oc_app_versions_audit WHERE app_id='prunetest'"))).toBeGreaterThan(0)

		await sqlExec("DELETE FROM oc_app_versions_audit WHERE app_id='prunetest'")
		await occ('config:app:delete', 'app_versions', 'audit_retention_days')
	})

	// --- pin drift reconciliation job -------------------------------------
	test('the reconcile job flags drift when the installed version leaves the pin', async ({ page }) => {
		// Install 1.0.1 for real (files + version match), then seed a pin at an
		// earlier version — as if the app had been pinned at 1.0.0 and something
		// else later moved it to 1.0.1. Reconcile must notice installed != pinned.
		// (Done via a stale pin record rather than an out-of-band version change,
		// which would put the instance into NC's upgrade-required 503.)
		await installFixture(page, '1.0.1')
		await occ('config:app:set', 'app_versions', `pin.${FIXTURE_APP}`, '--value',
			JSON.stringify({ version: '1.0.0', pinnedBy: 'admin', pinnedAt: '2026-01-01T00:00:00+00:00' }))

		await runJob('PinReconcileJob')
		const pin = JSON.parse((await appConfigValue(page, `pin.${FIXTURE_APP}`)) ?? '{}')
		expect(pin.driftedTo ?? pin.driftDetected ?? pin.drifted ?? null, 'drift recorded on the pin').toBeTruthy()
	})

	test('the reconcile job records no drift while the installed version matches the pin', async ({ page }) => {
		// Installed 1.0.0 and pinned at 1.0.0 — reconcile must leave the pin clean.
		await occ('config:app:set', 'app_versions', `pin.${FIXTURE_APP}`, '--value',
			JSON.stringify({ version: '1.0.0', pinnedBy: 'admin', pinnedAt: '2026-01-01T00:00:00+00:00' }))

		await runJob('PinReconcileJob')
		const pin = JSON.parse((await appConfigValue(page, `pin.${FIXTURE_APP}`)) ?? '{}')
		expect(pin.driftedTo ?? pin.driftDetected ?? pin.drifted ?? null, 'no drift flag when versions match').toBeFalsy()
	})
})
