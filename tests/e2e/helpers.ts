import { expect, type Page } from '@playwright/test'

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

/** Opens the admin settings page and waits for the app shell to be interactive. */
export async function openSettings(page: Page): Promise<void> {
	await page.goto(SETTINGS_URL)
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
