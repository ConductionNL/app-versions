<script setup lang="ts">
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import { computed, onMounted, ref, watch } from 'vue'
import AppInfoPanel from './components/AppInfoPanel.vue'
import AppPickerPanel from './components/AppPickerPanel.vue'
import AppSettingsPanel from './components/AppSettingsPanel.vue'
import DowngradeConfirmDialog from './components/DowngradeConfirmDialog.vue'
import InstallResultPanel from './components/InstallResultPanel.vue'
import type { AppOption, AppVersion, InstallDebugEntry, InstallResult } from './types'
import { compareVersions, getVersionRangeSummary, versionRangeText } from './utils/versioning'

const isAdmin = ref(false)
const isLoading = ref(true)
const apps = ref<AppOption[]>([])
const appFilter = ref('')
const showFilters = ref(false)
const coreAppsVisibility = ref<'show' | 'hide'>('show')
const updateChannel = ref('')
const selectedApp = ref('')
const versions = ref<AppVersion[]>([])
const versionFilter = ref('')
const hasCheckedVersions = ref(false)
const isCheckingVersions = ref(false)
const isInstallingVersion = ref(false)
const installedVersion = ref('')
const availableSource = ref('')
const errorMessage = ref('')
const selectedVersion = ref('')
const safeModeEnabled = ref(true)
const debugModeEnabled = ref(false)
const isDowngradeConfirmOpen = ref(false)
const downgradeConfirmFromVersion = ref('')
const downgradeConfirmToVersion = ref('')
const downgradeConfirmApp = ref('')
let downgradeResolve: ((value: boolean) => void) | null = null
const safeModeStorageKey = 'app_versions_safe_mode'
const debugModeStorageKey = 'app_versions_debug_mode'
const lastInstallDebug = ref<InstallDebugEntry[]>([])
const lastInstallResult = ref<InstallResult | null>(null)
const hasInstallResult = ref(false)
const installRequestFromVersion = ref('')
const installRequestToVersion = ref('')

const changeActionLabel = computed(() => {
	if (!selectedApp.value || !selectedVersion.value) {
		return ''
	}

	if (!installedVersion.value) {
		return 'Install'
	}

	const comparison = compareVersions(selectedVersion.value, installedVersion.value)
	if (comparison > 0) {
		return 'Update'
	}

	if (comparison < 0) {
		return 'Degrade'
	}

	return ''
})

const hasSidebarSelect = computed(() => isAdmin.value)
const sidebarLabel = computed(() => hasSidebarSelect.value ? 'Select an app from store' : 'Loading…')
const hasSplitLayout = computed(() => Boolean(selectedApp.value || installedVersion.value || hasInstallResult.value))
const isSafeMode = computed(() => safeModeEnabled.value)
const includeDebug = computed(() => debugModeEnabled.value)

const apiUrl = (path: string): string => {
	const oc = window.OC as unknown as {
		webroot?: string
	}
	const webroot = (typeof oc?.webroot === 'string' ? oc.webroot : '').replace(/\/$/, '')
	return `${window.location.origin}${webroot}${path}`
}

const ocsHeaders: HeadersInit = { 'OCS-APIRequest': 'true' }

const withOcsJson = (path: string, query: Record<string, string | number | boolean> = {}): string => {
	const separator = path.includes('?') ? '&' : '?'
	const params = new URLSearchParams()
	Object.entries(query).forEach(([key, value]) => {
		params.set(key, String(value))
	})
	params.set('format', 'json')

	return `${path}${separator}${params.toString()}`
}

const unwrapOcsResponse = async <T>(response: Response): Promise<T> => {
	const raw = await response.json()
	if (typeof raw !== 'object' || raw === null) {
		throw new Error('Unexpected response format')
	}

	const meta = (raw as { ocs?: { meta?: { status?: string, statuscode?: number, message?: string } } }).ocs?.meta
	if (meta && (meta.status === 'failure' || (typeof meta.statuscode === 'number' && meta.statuscode >= 400))) {
		throw new Error(meta.message || 'OCS request failed')
	}

	return (raw.ocs?.data ?? raw) as T
}

type OcsWrapped<T> = {
	ocs?: {
		meta?: {
			status?: string
			statuscode?: number
			message?: string
		}
		data?: T
	}
	data?: T
}

const unwrapOcsResponseWithMeta = async <T>(response: Response): Promise<{ payload: T, metaMessage?: string }> => {
	const raw = (await response.json()) as OcsWrapped<T>
	if (typeof raw !== 'object' || raw === null) {
		throw new Error('Unexpected response format')
	}

	const data = (raw.ocs?.data ?? raw.data ?? raw) as T
	const meta = raw.ocs?.meta
	if (meta && (meta.status === 'failure' || (typeof meta.statuscode === 'number' && meta.statuscode >= 400))) {
		return {
			payload: data,
			metaMessage: meta.message || 'OCS request failed',
		}
	}

	return { payload: data }
}

const normalizeInstallResult = (payload: {
	appId?: string
	fromVersion?: string | null
	toVersion?: string
	installedVersion?: string | null
	updateType?: string
	message?: string
	dryRun?: boolean
	installStatus?: string
	debug?: unknown
}): InstallResult => {
	const normalizedUpdateType = payload.updateType ?? 'none'
	const normalizedFrom = payload.fromVersion ?? null
	const normalizedTo = payload.toVersion || ''
	const resolvedMessage = payload.message || 'Install completed.'
	const shouldForceDowngradeMessage = normalizedUpdateType === 'downgrade'
		|| (normalizedFrom !== null && normalizedTo !== '' && compareVersions(normalizedTo, normalizedFrom) < 0)
	const finalMessage = shouldForceDowngradeMessage
		? (resolvedMessage === 'App updated.'
			? 'App downgraded.'
			: resolvedMessage)
		: resolvedMessage

	return {
		appId: payload.appId || '',
		fromVersion: normalizedFrom,
		toVersion: normalizedTo,
		installedVersion: payload.installedVersion ?? null,
		updateType: normalizedUpdateType,
		message: finalMessage,
		dryRun: Boolean(payload.dryRun),
		installStatus: payload.installStatus || 'failed',
		debug: Array.isArray(payload.debug) ? payload.debug as InstallDebugEntry[] : [],
	}
}

const installStatusTone = computed<'success' | 'warning' | 'error' | 'info'>(() => {
	const result = lastInstallResult.value
	if (!result) {
		return 'info'
	}

	if (result.installStatus === 'dry-run') {
		return 'warning'
	}

	if (result.installStatus === 'failed' || result.installStatus === 'error') {
		return 'error'
	}

	return 'success'
})

const installStatusLabel = computed(() => {
	switch (installStatusTone.value) {
	case 'warning':
		return 'Dry run'
	case 'error':
		return 'Failed'
	default:
		return 'Done'
	}
})

const checkAdmin = async (): Promise<void> => {
	errorMessage.value = ''
	try {
		const response = await fetch(apiUrl(withOcsJson('/ocs/v2.php/apps/app_versions/api/admin-check')), { headers: { ...ocsHeaders, Accept: 'application/json' } })
		const payload = await unwrapOcsResponse<{ isAdmin: boolean }>(response)
		isAdmin.value = Boolean(payload.isAdmin)
	} catch {
		isAdmin.value = false
		errorMessage.value = 'Could not verify admin permissions.'
	} finally {
		isLoading.value = false
	}
}

const checkUpdateChannel = async (): Promise<void> => {
	if (!isAdmin.value) {
		updateChannel.value = ''
		return
	}

	try {
		const response = await fetch(apiUrl(withOcsJson('/ocs/v2.php/apps/app_versions/api/update-channel')), { headers: { ...ocsHeaders, Accept: 'application/json' } })
		const payload = await unwrapOcsResponse<{ updateChannel: string }>(response)
		updateChannel.value = payload.updateChannel || ''
	} catch {
		updateChannel.value = ''
	}
}

const loadApps = async (): Promise<void> => {
	if (!isAdmin.value) {
		return
	}

	try {
		const response = await fetch(apiUrl(withOcsJson('/ocs/v2.php/apps/app_versions/api/apps')), { headers: { ...ocsHeaders, Accept: 'application/json' } })
		const payload = await unwrapOcsResponse<{ apps: AppOption[] }>(response)
		apps.value = payload.apps || []
	} catch (error) {
		errorMessage.value = error instanceof Error ? error.message : 'Could not fetch app list.'
	}
}

const resetSelectedAppState = (): void => {
	versions.value = []
	selectedVersion.value = ''
	versionFilter.value = ''
	hasCheckedVersions.value = false
	installedVersion.value = ''
	availableSource.value = ''
	lastInstallDebug.value = []
	lastInstallResult.value = null
	hasInstallResult.value = false
}

const checkVersions = async (preserveInstallResult = false): Promise<void> => {
	const appId = selectedApp.value.trim()
	versions.value = []
	selectedVersion.value = ''
	versionFilter.value = ''
	hasCheckedVersions.value = false
	if (!preserveInstallResult) {
		lastInstallDebug.value = []
		lastInstallResult.value = null
		hasInstallResult.value = false
	}

	if (!appId) {
		return
	}

	isCheckingVersions.value = true
	errorMessage.value = ''

	try {
		const url = withOcsJson(`/ocs/v2.php/apps/app_versions/api/app/${encodeURIComponent(appId)}/versions`)
		const response = await fetch(apiUrl(url), { headers: { ...ocsHeaders, Accept: 'application/json' } })
		const payload = await unwrapOcsResponse<{
			availableVersions?: AppVersion[]
			versions?: AppVersion[]
			installedVersion: string | null
			source?: string
			error?: string
		}>(response)
		versions.value = payload.availableVersions || payload.versions || []
		installedVersion.value = payload.installedVersion || ''
		availableSource.value = payload.source || ''
		errorMessage.value = payload.error ?? ''
		hasCheckedVersions.value = true
	} catch (error) {
		errorMessage.value = error instanceof Error ? error.message : 'Could not fetch app versions.'
		availableSource.value = ''
	} finally {
		isCheckingVersions.value = false
	}
}

const isDowngradeBlockedBySafeMode = (version: string): boolean => {
	if (!isSafeMode.value || !installedVersion.value || !version) {
		return false
	}
	return compareVersions(version, installedVersion.value) < 0
}

const ensurePasswordConfirmation = async (): Promise<void> => {
	const windowOC = window as Window & {
		OC?: {
			PasswordConfirmation?: {
				requiresPasswordConfirmation?: () => boolean
				requirePasswordConfirmation?: (callback: () => void, options?: unknown, rejectCallback?: (error: Error) => void) => void
			}
		}
	}

	const passwordConfirmation = windowOC.OC?.PasswordConfirmation
	if (!passwordConfirmation?.requirePasswordConfirmation) {
		return
	}

	if (passwordConfirmation.requiresPasswordConfirmation && !passwordConfirmation.requiresPasswordConfirmation()) {
		return
	}

	await new Promise<void>((resolve, reject) => {
		passwordConfirmation.requirePasswordConfirmation(
			() => resolve(),
			undefined,
			() => reject(new Error('Password confirmation was cancelled'))
		)
	})
}

const onSelectApp = (appId: string) => {
	selectedApp.value = appId
	resetSelectedAppState()
}

const onPickApp = async (appId: string) => {
	if (!appId || isCheckingVersions.value || isInstallingVersion.value) {
		return
	}

	onSelectApp(appId)
	await checkVersions()
}

const clearSelectedApp = () => {
	selectedApp.value = ''
	errorMessage.value = ''
	resetSelectedAppState()
}

const filteredApps = computed(() => {
	const filter = appFilter.value.trim().toLowerCase()
	const visibleApps = coreAppsVisibility.value === 'hide'
		? apps.value.filter((app) => !app.isCore)
		: apps.value

	if (filter === '') {
		return visibleApps
	}

	return visibleApps.filter((app) => app.label.toLowerCase().includes(filter) || app.id.toLowerCase().includes(filter))
})

const selectedAppOption = computed(() => {
	return apps.value.find((app) => app.id === selectedApp.value) ?? null
})

const filteredVersions = computed(() => {
	const filter = versionFilter.value.trim().toLowerCase()
	const list = isSafeMode.value && installedVersion.value
		? versions.value.filter((version) => !isDowngradeBlockedBySafeMode(version.version))
		: versions.value

	if (filter === '') {
		return list
	}

	return list.filter((version) => version.version.toLowerCase().includes(filter))
})

const visibleVersions = computed(() => {
	if (selectedVersion.value) {
		const selected = versions.value.find((version) => version.version === selectedVersion.value)
		return selected ? [selected] : []
	}

	return filteredVersions.value
})

const selectedVersionRange = computed(() => {
	if (!installedVersion.value || !selectedVersion.value || installedVersion.value === selectedVersion.value) {
		return null
	}

	return getVersionRangeSummary(installedVersion.value, selectedVersion.value, versions.value)
})

const downgradeVersionRange = computed(() => getVersionRangeSummary(downgradeConfirmFromVersion.value, downgradeConfirmToVersion.value, versions.value))

const downgradeConfirmButtons = computed(() => [
	{
		label: 'Cancel',
		type: 'tertiary',
		disabled: isInstallingVersion.value,
		callback: () => {
			isDowngradeConfirmOpen.value = false
			downgradeResolve?.(false)
			downgradeResolve = null
		},
	},
	{
		label: 'Downgrade',
		variant: 'error',
		disabled: isInstallingVersion.value,
		callback: () => {
			isDowngradeConfirmOpen.value = false
			downgradeResolve?.(true)
			downgradeResolve = null
		},
	},
])

const confirmDowngrade = async (appId: string, fromVersion: string, toVersion: string): Promise<boolean> => {
	if (downgradeResolve) {
		downgradeResolve(false)
		downgradeResolve = null
	}

	downgradeConfirmApp.value = appId
	downgradeConfirmFromVersion.value = fromVersion
	downgradeConfirmToVersion.value = toVersion
	return new Promise<boolean>((resolve) => {
		downgradeResolve = resolve
		isDowngradeConfirmOpen.value = true
	})
}

const onDowngradeDialogClose = (open: boolean) => {
	if (open) {
		return
	}

	if (downgradeResolve) {
		downgradeResolve(false)
		downgradeResolve = null
	}
}

const onSelectVersion = (version: string): void => {
	if (isDowngradeBlockedBySafeMode(version)) {
		errorMessage.value = 'Safe mode is enabled. Disable it to downgrade.'
		return
	}

	selectedVersion.value = version
	errorMessage.value = ''
}

const performInstall = async (): Promise<void> => {
	if (!selectedApp.value || !selectedVersion.value || isInstallingVersion.value) {
		return
	}

	const selectedAppValue = selectedApp.value
	const selectedVersionValue = selectedVersion.value
	const requestedFromVersion = installedVersion.value
	const requestedToVersion = selectedVersionValue
	const isDowngrade = installedVersion.value !== '' && compareVersions(selectedVersionValue, installedVersion.value) < 0

	if (isDowngrade) {
		const proceed = await confirmDowngrade(selectedAppValue, installedVersion.value, selectedVersionValue)
		if (!proceed) {
			return
		}
	}

	isInstallingVersion.value = true
	errorMessage.value = ''
	hasInstallResult.value = false
	lastInstallResult.value = null
	lastInstallDebug.value = []
	installRequestFromVersion.value = requestedFromVersion
	installRequestToVersion.value = requestedToVersion

	try {
		await ensurePasswordConfirmation()

		const endpoint = withOcsJson(
			`/ocs/v2.php/apps/app_versions/api/app/${encodeURIComponent(selectedAppValue)}/versions/${encodeURIComponent(selectedVersionValue)}/install`,
			{
				debug: includeDebug.value ? '1' : '0',
				targetVersion: selectedVersionValue,
			}
		)
		const response = await fetch(apiUrl(endpoint), {
			method: 'POST',
			headers: {
				...ocsHeaders,
				Accept: 'application/json',
				'Content-Type': 'application/json',
			},
			body: JSON.stringify({
				version: selectedVersionValue,
			}),
		})
		const { payload, metaMessage } = await unwrapOcsResponseWithMeta<{
			appId: string
			toVersion: string
			fromVersion?: string
			installedVersion?: string
			updateType?: string
			message?: string
			dryRun?: boolean
			installStatus?: string
			debug?: unknown
		}>(response)
		const result = normalizeInstallResult(payload)
		const requestedFrom = installRequestFromVersion.value
		const requestedTo = installRequestToVersion.value
		lastInstallResult.value = {
			...result,
			fromVersion: result.fromVersion ?? requestedFrom,
			toVersion: result.toVersion && requestedTo && result.toVersion === requestedFrom && requestedTo !== requestedFrom
				? requestedTo
				: result.toVersion || requestedTo,
			installedVersion: result.installedVersion ?? requestedFrom,
		}
		lastInstallDebug.value = result.debug ?? []
		hasInstallResult.value = true

		if (metaMessage) {
			errorMessage.value = metaMessage
			if (lastInstallResult.value) {
				lastInstallResult.value = {
					...lastInstallResult.value,
					message: metaMessage,
					installStatus: 'failed',
				}
			}
		} else {
			selectedApp.value = ''
			installedVersion.value = ''
			availableSource.value = ''
			selectedVersion.value = ''
			await checkVersions(true)
		}
	} catch (error) {
		errorMessage.value = error instanceof Error ? error.message : 'Could not install selected version.'
		hasInstallResult.value = true
		lastInstallResult.value = {
			appId: selectedAppValue,
			fromVersion: requestedFromVersion || null,
			toVersion: requestedToVersion,
			message: errorMessage.value,
			dryRun: false,
			installStatus: 'failed',
			updateType: 'none',
		}
	} finally {
		isInstallingVersion.value = false
	}
}

onMounted(async () => {
	const storedSafeMode = window?.localStorage?.getItem(safeModeStorageKey)
	if (storedSafeMode !== null) {
		safeModeEnabled.value = storedSafeMode !== 'false'
	}
	const storedDebugMode = window?.localStorage?.getItem(debugModeStorageKey)
	if (storedDebugMode !== null) {
		debugModeEnabled.value = storedDebugMode === 'true'
	}

	await checkAdmin()
	await checkUpdateChannel()
	await loadApps()
})

watch([safeModeEnabled, installedVersion, selectedVersion], () => {
	if (typeof window === 'undefined') {
		return
	}

	window.localStorage?.setItem(safeModeStorageKey, safeModeEnabled.value ? 'true' : 'false')

	if (safeModeEnabled.value && isDowngradeBlockedBySafeMode(selectedVersion.value)) {
		selectedVersion.value = ''
	}
}, { deep: false })

watch(debugModeEnabled, () => {
	if (typeof window === 'undefined') {
		return
	}

	window.localStorage?.setItem(debugModeStorageKey, debugModeEnabled.value ? 'true' : 'false')
})
</script>

<template>
	<NcContent app-name="app_versions">
		<NcAppContent :class="$style.content">
			<DowngradeConfirmDialog
				:open="isDowngradeConfirmOpen"
				:buttons="downgradeConfirmButtons"
				:app-id="downgradeConfirmApp"
				:from-version="downgradeConfirmFromVersion"
				:to-version="downgradeConfirmToVersion"
				:range="downgradeVersionRange"
				:version-range-text="versionRangeText"
				@update:open="onDowngradeDialogClose"
			/>

			<div :class="$style.layout">
				<main :class="$style.mainContent">
					<h2>App Versions!</h2>

					<AppSettingsPanel
						:update-channel="updateChannel"
						:safe-mode-enabled="safeModeEnabled"
						:debug-mode-enabled="debugModeEnabled"
						:disabled="isInstallingVersion"
						@update:safe-mode-enabled="safeModeEnabled = $event"
						@update:debug-mode-enabled="debugModeEnabled = $event"
					/>

					<div :class="[$style.contentRow, { [$style.contentRowSplit]: hasSplitLayout }]">
						<div :class="[$style.leftColumn, { [$style.leftColumnFull]: !hasSplitLayout }]">
							<AppPickerPanel
								:apps="filteredApps"
								:app-filter="appFilter"
								:show-filters="showFilters"
								:core-apps-visibility="coreAppsVisibility"
								:selected-app="selectedApp"
								:has-split-layout="hasSplitLayout"
								:has-sidebar-select="hasSidebarSelect"
								:is-loading="isLoading"
								:is-checking-versions="isCheckingVersions"
								:is-installing-version="isInstallingVersion"
								:sidebar-label="sidebarLabel"
								@update:app-filter="appFilter = $event"
								@update:show-filters="showFilters = $event"
								@update:core-apps-visibility="coreAppsVisibility = $event"
								@pick-app="onPickApp"
							/>

							<AppInfoPanel
								:selected-app="selectedApp"
								:selected-app-option="selectedAppOption"
								:installed-version="installedVersion"
								:selected-version="selectedVersion"
								:selected-version-range="selectedVersionRange"
								:version-range-text="versionRangeText"
								:versions="versions"
								:filtered-versions="filteredVersions"
								:visible-versions="visibleVersions"
								:version-filter="versionFilter"
								:change-action-label="changeActionLabel"
								:available-source="availableSource"
								:has-checked-versions="hasCheckedVersions"
								:error-message="errorMessage"
								:is-checking-versions="isCheckingVersions"
								:is-installing-version="isInstallingVersion"
								@update:version-filter="versionFilter = $event"
								@clear-selected-app="clearSelectedApp"
								@select-version="onSelectVersion"
								@deselect-version="selectedVersion = ''"
								@perform-install="performInstall"
							/>
						</div>

						<div v-if="hasSplitLayout" :class="$style.rightColumn">
							<InstallResultPanel
								v-if="hasInstallResult && lastInstallResult"
								:result="lastInstallResult"
								:tone="installStatusTone"
								:label="installStatusLabel"
								:debug-mode-enabled="debugModeEnabled"
								:debug-entries="lastInstallDebug"
							/>
						</div>
					</div>
				</main>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<style module src="./styles/App.module.css"></style>
