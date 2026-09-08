import type { Page } from "@playwright/test";

import { expect, test } from "@playwright/test";
import { ocsData, ocsRequest, openSettings, openTab } from "./helpers.ts";

/**
 * Per-app auto-update policies and the global kill switch / window.
 * @spec openspec/specs/auto-update-policies/spec.md
 */
const APP = "dashboard";

const DEFAULT_WINDOW = "01:00-05:00";

/**
 * The stored global settings and per-app policies, read back from the app.
 *
 * 🔑 PROVISIONING_API IS NOT A SOUND ORACLE FOR THESE TWO KEYS, AND THESE THREE
 * TESTS REPORTED A WORKING FEATURE AS BROKEN FOR TWO DAYS BECAUSE OF IT.
 *
 * In run 34214407296 the settings PUT answered `autoUpdateEnabled: true`, and a
 * later fresh page load of this settings section answered `autoUpdateEnabled:
 * true`, `autoUpdateWindow: "23:00-03:00"` and `policies: [{level: "patch",
 * appId: "dashboard"}]`. Both are ordinary reads from the database in their own
 * HTTP requests, so the values were persisted. For the same twenty seconds,
 *
 *     GET .../provisioning_api/api/v1/config/apps/versioniq/auto_update_enabled
 *     GET .../provisioning_api/api/v1/config/apps/versioniq/policy.dashboard
 *
 * answered 200 with `{"data": ""}`. Twenty-one requests, every one of them,
 * `Cache-Control: no-store` on each, while `pin.dashboard` and
 * `source.fixtureapp` read back correctly through the same helper in the same
 * run. So it is not the helper, the session, or the app id.
 *
 * This app stores both values through IAppConfig and reads them through
 * getValueBool, getValueString and getAllValues. provisioning_api is the only
 * reader that goes through getValueMixed with a null lazy flag, which returns
 * the caller's default when its isLazy() probe cannot classify the key. What
 * makes that probe fail here is a question about Nextcloud, not about this
 * repository, and it is not answerable from the CI artifacts.
 *
 * What is answerable is where to assert. This endpoint is the one the admin
 * section itself loads, so a value that survives to here is a value that
 * survives a reload, which is what "persists server-side" means for a setting.
 * {@see appConfigValue} stays in use where it demonstrably works.
 */
async function stored(page: Page): Promise<any> {
	return await ocsData(
		page,
		"get",
		"/ocs/v2.php/apps/versioniq/api/policies?format=json",
	);
}

/** Sets the global kill switch and window straight through the API. */
async function setGlobals(
	page: Page,
	enabled: "0" | "1",
	window: string,
): Promise<void> {
	await ocsRequest(
		page,
		"put",
		"/ocs/v2.php/apps/versioniq/api/auto-update/settings" +
			`?enabled=${enabled}&window=${encodeURIComponent(window)}&format=json`,
	);
}

test.describe("auto-update policies", () => {
	/**
	 * Puts the instance back the way the suite found it.
	 *
	 * 🔑 THE RESTORE HAS TO LIVE HERE, NOT AT THE END OF A TEST BODY. It used to
	 * sit at the end of "enabling automation with a valid window persists
	 * server-side". When that test failed on its assertion the restore never
	 * ran, the kill switch stayed on, and "policies show as inert while the kill
	 * switch is off" then failed too, hunting a hint that correctly does not
	 * render while automation is enabled. One broken oracle, two red tests, and
	 * the second one named the wrong thing.
	 */
	test.afterEach(async ({ page }) => {
		await ocsRequest(
			page,
			"delete",
			`/ocs/v2.php/apps/versioniq/api/app/${APP}/policy?format=json`,
		).catch(() => undefined);
		await setGlobals(page, "0", DEFAULT_WINDOW).catch(() => undefined);
	});

	test("automation is off by default and says so", async ({ page }) => {
		await openSettings(page);
		await openTab(page, "Apps");

		const settings = page.getByTestId("auto-update-settings");
		await expect(settings).toBeVisible();
		await expect(
			page.getByTestId("auto-update-kill-switch"),
		).not.toBeChecked();
		// Default window is advertised.
		await expect(page.getByTestId("auto-update-window")).toHaveValue(
			/01:00-05:00/,
		);
	});

	test("an invalid maintenance window is rejected before saving", async ({
		page,
	}) => {
		await openSettings(page);
		await openTab(page, "Apps");

		await page.getByTestId("auto-update-window").fill("nonsense");
		await expect(
			page.getByTestId("auto-update-window-error"),
		).toBeVisible();
		await expect(
			page.getByTestId("auto-update-settings-save"),
		).toBeDisabled();
	});

	test("enabling automation with a valid window persists server-side", async ({
		page,
	}) => {
		await openSettings(page);
		await openTab(page, "Apps");

		await page.getByTestId("auto-update-kill-switch").check();
		await page.getByTestId("auto-update-window").fill("23:00-03:00");
		await page.getByTestId("auto-update-settings-save").click();

		await expect
			.poll(async () => (await stored(page)).autoUpdateEnabled, {
				message: "kill switch should persist",
				timeout: 20_000,
			})
			.toBe(true);
		expect((await stored(page)).autoUpdateWindow).toBe("23:00-03:00");

		// Turning it back off is part of the behaviour, not only cleanup: an
		// omitted `enabled` must leave the switch alone, and `0` must clear it.
		await page.getByTestId("auto-update-kill-switch").uncheck();
		await page.getByTestId("auto-update-window").fill(DEFAULT_WINDOW);
		await page.getByTestId("auto-update-settings-save").click();
		await expect
			.poll(async () => (await stored(page)).autoUpdateEnabled, {
				message: "kill switch should clear",
				timeout: 20_000,
			})
			.toBe(false);
	});

	test("a per-app policy is persisted and badged", async ({ page }) => {
		await openSettings(page);
		await openTab(page, "Apps");

		const card = page
			.locator("article")
			.filter({ has: page.getByText(APP, { exact: true }) })
			.first();
		const selector = card.getByTestId("policy-select");
		await expect(selector).toBeVisible();

		// NcSelect renders a combobox; pick the "Patch" option.
		await selector.click();
		await page.getByRole("option", { name: /patch/i }).first().click();

		await expect
			.poll(
				async () =>
					((await stored(page)).policies ?? []).find(
						(p: any) => p.appId === APP,
					)?.level,
				{
					message: "policy should persist",
					timeout: 20_000,
				},
			)
			.toBe("patch");

		await expect(card.getByTestId("policy-active-badge")).toBeVisible();
	});

	test("policies show as inert while the kill switch is off", async ({
		page,
	}) => {
		// The precondition is the whole point of the test, so state it rather
		// than inheriting whatever the previous test left behind.
		await setGlobals(page, "0", DEFAULT_WINDOW);
		// Seed a policy directly, then confirm the UI explains it will not run.
		await ocsRequest(
			page,
			"put",
			`/ocs/v2.php/apps/versioniq/api/app/${APP}/policy?format=json`,
			{ data: { level: "patch" } },
		);

		await openSettings(page);
		await openTab(page, "Apps");

		const card = page
			.locator("article")
			.filter({ has: page.getByText(APP, { exact: true }) })
			.first();
		await expect(card.getByTestId("policy-disabled-hint")).toBeVisible();
	});
});
