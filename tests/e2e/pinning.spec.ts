import { expect, test } from '@playwright/test'
import { appConfigValue, chooseApp, openSettings, openTab, versionsLoaded } from './helpers'

/**
 * Version pinning and its audit-trail integration, driven through the UI.
 * Each test cleans up the pin it creates so the instance is left as found.
 *
 * @spec openspec/specs/version-pinning/spec.md
 * @spec openspec/specs/audit-trail/spec.md
 */
const APP = 'dashboard'

test.describe('version pinning', () => {
	test.afterEach(async ({ page }) => {
		// Best-effort cleanup: remove any pin this spec may have left behind.
		await page.request.delete(`/ocs/v2.php/apps/app_versions/api/app/${APP}/pin?format=json`, {
			headers: { 'OCS-APIRequest': 'true' },
		}).catch(() => undefined)
	})

	test('pin the installed version, then unpin — both audited', async ({ page }) => {
		await openSettings(page)
		await chooseApp(page, APP)
		await versionsLoaded(page)

		// --- Pin -------------------------------------------------------------
		await page.getByRole('button', { name: 'Pin this version' }).click()

		const dialog = page.getByRole('dialog')
		await expect(dialog).toBeVisible()
		// The pin dialog must be honest that a pin is monitored, not enforced
		// against Nextcloud's own updater.
		await expect(dialog).toContainText('Nextcloud')
		await dialog.getByRole('textbox', { name: 'Reason (optional)' }).fill('e2e pin check')
		await dialog.getByRole('button', { name: 'Pin', exact: true }).click()
		await expect(dialog).toBeHidden()

		// Persisted server-side with attribution.
		const stored = await appConfigValue(page, `pin.${APP}`)
		expect(stored, 'pin should be persisted in app config').toBeTruthy()
		const pin = JSON.parse(stored as string)
		expect(pin.version).toBeTruthy()
		expect(pin.pinnedBy).toBe('admin')
		expect(pin.pinnedAt).toMatch(/^\d{4}-\d{2}-\d{2}T/)
		expect(pin.reason).toBe('e2e pin check')

		// Surfaced as a badge with an unpin affordance.
		await expect(page.getByTestId('pin-badge').or(page.getByTestId('pin-badge-detail')).first()).toBeVisible()

		// --- Audited ---------------------------------------------------------
		await openTab(page, 'History')
		const pinRow = page.getByTestId('history-row').filter({ has: page.locator('[data-operation="pin"]') })
			.or(page.locator('[data-testid=history-row][data-operation=pin]'))
		await expect(pinRow.first()).toBeVisible()
		await expect(pinRow.first()).toContainText('admin')
		await expect(pinRow.first()).toContainText(APP)

		// --- Unpin -----------------------------------------------------------
		await openTab(page, 'Apps')
		await page.getByRole('button', { name: 'Unpin' }).first().click()
		await expect.poll(async () => appConfigValue(page, `pin.${APP}`), {
			message: 'pin should be cleared after unpin',
			timeout: 20_000,
		}).toBeNull()

		await openTab(page, 'History')
		await expect(page.locator('[data-testid=history-row][data-operation=unpin]').first()).toBeVisible()
	})

	test('a pinned app shows who pinned it and when', async ({ page }) => {
		// Seed a pin through the API, then assert the UI presents it.
		const put = await page.request.put(`/ocs/v2.php/apps/app_versions/api/app/${APP}/pin?format=json`, {
			headers: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data: { reason: 'seeded by e2e' },
		})
		expect(put.ok(), 'seeding a pin via API should succeed').toBeTruthy()

		await openSettings(page)
		await openTab(page, 'Apps')

		const badge = page.getByTestId('pin-badge').first()
		await expect(badge).toBeVisible()
		await expect(badge).toContainText('Pinned')
		// Attribution is carried in the title so hovering explains the badge.
		await expect(badge).toHaveAttribute('title', /admin/)
	})
})
