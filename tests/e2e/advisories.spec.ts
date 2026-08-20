import { expect, test } from '@playwright/test'
import { occ, openSettings, openTab, versionsLoaded } from './helpers'

/**
 * The advisory surface, driven through the UI.
 *
 * WHAT THIS EXISTS TO PROTECT. `/api/advisories` used to correlate inline:
 * two external calls per app, ~176 sequential calls on an 88-app instance.
 * Measured on a live instance it did not answer within 120s, twice, and while
 * it held the PHP session lock the sibling `/api/pins` request never ran, so
 * pin badges silently never rendered (issue #160). Correlation now happens in
 * AdvisoryRefreshJob and the endpoint serves the stored snapshot.
 *
 * The load-bearing assertion is the one about the freshness line. A snapshot
 * that is empty and a snapshot that was never taken render identically as
 * "no advisory badges" — an absence that reads as reassurance. The line is
 * what makes those two states distinguishable to the admin, so it is tested
 * as a contract rather than as decoration.
 *
 * @spec openspec/specs/security-advisory-correlation/spec.md
 */
test.describe('security advisories', () => {
	test('the advisory surface states how old its answer is', async ({ page }) => {
		await openSettings(page)
		await openTab(page, 'Apps')
		await versionsLoaded(page)

		const freshness = page.getByTestId('advisory-freshness')
		await expect(
			freshness,
			'the advisory freshness line must always render — an empty advisory map and a sweep that never ran look identical without it',
		).toBeVisible()

		// One of the three legitimate states, and never blank. Which one depends
		// on whether cron has run on this instance, so the assertion covers the
		// set rather than pinning one and going flaky.
		await expect(freshness).toHaveText(/Advisories (checked|not checked yet|status unavailable)/)
	})

	test('the endpoint answers promptly because it reads a snapshot rather than correlating', async ({ page }) => {
		await openSettings(page)

		// The regression this guards is a request that never returns. A budget
		// well under the old 120s non-answer is enough to catch a reversion to
		// inline correlation, without being tight enough to flake on a loaded
		// CI box.
		const started = Date.now()
		const response = await page.request.get('/ocs/v2.php/apps/app_versions/api/advisories?format=json', {
			headers: { 'OCS-APIRequest': 'true' },
		})
		const elapsedMs = Date.now() - started

		expect(response.ok(), 'GET /api/advisories should answer 200').toBeTruthy()
		expect(
			elapsedMs,
			`GET /api/advisories took ${elapsedMs}ms — this endpoint reads a stored snapshot, so anything near the old inline-correlation timings means it is sweeping on the request path again`,
		).toBeLessThan(15_000)

		// `checkedAt` is part of the contract: null means "no sweep yet", a
		// number is the unix time of the last completed sweep. Its ABSENCE
		// would leave the client unable to tell those apart, so assert the key
		// exists rather than that it is truthy.
		const body = await response.json()
		const payload = body?.ocs?.data
		expect(payload, 'the OCS envelope should carry a data object').toBeTruthy()
		expect(
			Object.keys(payload),
			'the response must carry checkedAt so the UI can state the age of the answer',
		).toContain('checkedAt')
	})

	test('the refresh job is registered, so a snapshot will actually be produced', async ({ page }) => {
		// Without this the endpoint is honest but permanently empty: it would
		// report "not checked yet" forever and nothing would ever say why.
		// Reading the job list proves the sweep is wired to cron, which no
		// amount of UI assertion can.
		const jobs = await occ('background-job:list', '--output=json')
		expect(
			jobs,
			'AdvisoryRefreshJob must be registered as a background job — the advisory snapshot has no other writer',
		).toContain('AdvisoryRefreshJob')
	})
})
