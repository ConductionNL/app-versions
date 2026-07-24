import { test as setup, expect } from '@playwright/test'
import { AUTH_FILE, NC_ADMIN_PASS, NC_ADMIN_USER } from '../../playwright.config'

/**
 * Logs in as the admin once and stores the session for every other spec.
 * Nextcloud's login form is server-rendered, so this is a plain form post.
 */
setup('authenticate as admin', async ({ page }) => {
	// This step also warms the App Store caches below, which on a cold instance
	// is a multi-minute download — well past the suite's per-test timeout.
	setup.setTimeout(900_000)

	await page.goto('/login')
	await page.locator('#user').fill(NC_ADMIN_USER)
	await page.locator('#password').fill(NC_ADMIN_PASS)
	await page.locator('button[type=submit]').click()

	// Landing anywhere authenticated is enough; go straight to our settings page
	// so a broken login fails here rather than in every functional spec.
	await page.goto('/index.php/settings/admin/app_versions')

	// Nextcloud's first-run wizard is a modal that covers the page and swallows
	// clicks and focus. Instances used for e2e should have `firstrunwizard`
	// disabled (see docs/e2e.md), but dismiss it defensively so the suite also
	// works on an instance where it is enabled.
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape')
		await expect(wizard).toBeHidden()
	}

	await expect(page.getByRole('heading', { name: 'App Versions', level: 2 })).toBeVisible()

	await page.context().storageState({ path: AUTH_FILE })

	// Warm the App Store caches once.
	//
	// The catalogue endpoint answers with the whole store (~30 MB) and its
	// results are cached for an hour; a spec that happens to run just after the
	// cache expires would otherwise wait out a full cold download and look like
	// a product failure. Paying it here, deliberately and visibly, keeps the
	// functional specs fast and deterministic. Failures are ignored: an offline
	// instance should surface in the specs that actually assert on the data.
	for (const url of [
		'/ocs/v2.php/apps/app_versions/api/app/notes/versions?format=json',
		'/ocs/v2.php/apps/app_versions/api/discover?q=calendar&format=json',
	]) {
		await page.request.get(url, {
			headers: { 'OCS-APIRequest': 'true' },
			timeout: 240_000,
		}).catch(() => undefined)
	}
})
