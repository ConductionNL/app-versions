import { expect, test } from "@playwright/test";
import { autoUpdateState, openSettings, openTab } from "./helpers.ts";

/**
 * Per-app auto-update policies and the global kill switch / window.
 * @spec openspec/specs/auto-update-policies/spec.md
 */
const APP = "dashboard";

test.describe("auto-update policies", () => {
	test.afterEach(async ({ page }) => {
		await page.request
			.delete(
				`/ocs/v2.php/apps/versioniq/api/app/${APP}/policy?format=json`,
				{
					headers: { "OCS-APIRequest": "true" },
				},
			)
			.catch(() => undefined);
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
			.poll(async () => (await autoUpdateState(page)).autoUpdateEnabled, {
				message: "kill switch should persist",
				timeout: 20_000,
			})
			.toBe(true);
		expect((await autoUpdateState(page)).autoUpdateWindow).toBe(
			"23:00-03:00",
		);

		// Put the instance back the way we found it.
		await page.getByTestId("auto-update-kill-switch").uncheck();
		await page.getByTestId("auto-update-window").fill("01:00-05:00");
		await page.getByTestId("auto-update-settings-save").click();
		await expect
			.poll(async () => (await autoUpdateState(page)).autoUpdateEnabled, {
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

		// The badge is driven by the parent's `policies` map, which
		// onPolicyChange only writes after the PUT comes back, so seeing it
		// means the server accepted the change.
		await expect(card.getByTestId("policy-active-badge")).toBeVisible();

		// Then prove it SURVIVES, by reloading and looking again. This is the
		// property the test name claims and the one a user would notice.
		//
		// Deliberately not read through page.request here. A policy written by
		// the page and read back through the request context comes back empty
		// on CI, while the same read succeeds for a policy that context wrote
		// itself — see the kill-switch test below, which passes. Reloading
		// keeps the write and the read in one context and still fails if the
		// value never reached the database.
		await page.reload();
		await openTab(page, "Apps");

		const reloaded = page
			.locator("article")
			.filter({ has: page.getByText(APP, { exact: true }) })
			.first();
		await expect(reloaded.getByTestId("policy-active-badge")).toBeVisible();
		await expect(reloaded.getByTestId("policy-select")).toContainText(
			/patch/i,
		);
	});

	test("policies show as inert while the kill switch is off", async ({
		page,
	}) => {
		// Seed a policy directly, then confirm the UI explains it will not run.
		//
		// The seed is asserted. Without this the request could fail and the
		// only symptom would be the hint below never appearing, which reads as
		// a broken component rather than a fixture that never ran.
		const seeded = await page.request.put(
			`/ocs/v2.php/apps/versioniq/api/app/${APP}/policy?format=json`,
			{
				headers: {
					"OCS-APIRequest": "true",
					"Content-Type": "application/json",
				},
				data: { level: "patch" },
			},
		);
		expect(
			seeded.ok(),
			`seeding the ${APP} policy failed with HTTP ${seeded.status()}; the hint below cannot appear without it`,
		).toBe(true);

		// And assert the app itself now reports it, so a write that returns 200
		// without persisting is caught here rather than blamed on the UI.
		await expect
			.poll(
				async () =>
					(await autoUpdateState(page)).policies.find(
						(p) => p.appId === APP,
					)?.level,
				{
					message: "the seeded policy should be readable back",
					timeout: 20_000,
				},
			)
			.toBe("patch");

		await openSettings(page);
		await openTab(page, "Apps");

		const card = page
			.locator("article")
			.filter({ has: page.getByText(APP, { exact: true }) })
			.first();
		await expect(card.getByTestId("policy-disabled-hint")).toBeVisible();
	});
});
