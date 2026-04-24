<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<script setup lang="ts">
import axios from '@nextcloud/axios'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcAppContent, NcContent } from '@conduction/nextcloud-vue'
import { computed, onMounted, ref, watch } from 'vue'
import AppPicker from './components/AppPicker.vue'
import DowngradeConfirmDialog from './components/DowngradeConfirmDialog.vue'
import InstallResultPanel from './components/InstallResultPanel.vue'
import SettingsPanel from './components/SettingsPanel.vue'
import VersionPanel from './components/VersionPanel.vue'
import type { AppOption, AppVersion, InstallDebugEntry, InstallResult, VersionRangeInfo } from './types'

const APP_ID = 'app_versions'

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

const parseVersionCore = (version: string): { major: number, minor: number, patch: number } => {
	const [core] = version.split('-', 2)
	const [rawMajor, rawMinor, rawPatch] = core.split('.')

	return {
		major: Number.parseInt(rawMajor || '0', 10) || 0,
		minor: Number.parseInt(rawMinor || '0', 10) || 0,
		patch: Number.parseInt(rawPatch || '0', 10) || 0,
	}
}

const compareVersions = (left: string, right: string): number => {
	const [leftCore, leftPre = ''] = left.split('-', 2)
	const [rightCore, rightPre = ''] = right.split('-', 2)
	const leftParts = leftCore.split('.').map((part) => Number(part || '0'))
	const rightParts = rightCore.split('.').map((part) => Number(part || '0'))

	for (let index = 0; index < Math.max(leftParts.length, rightParts.length); index++) {
		const leftPart = leftParts[index] ?? 0
		const rightPart = rightParts[index] ?? 0

		if (leftPart > rightPart) {
			return 1
		}
		if (leftPart < rightPart) {
			return -1
		}
	}

	if (leftPre === rightPre) {
		return 0
	}
	if (!leftPre) {
		return 1
	}
	if (!rightPre) {
		return -1
	}

	const leftPreParts = leftPre.split('.')
	const rightPreParts = rightPre.split('.')
	for (let index = 0; index < Math.max(leftPreParts.length, rightPreParts.length); index++) {
		const leftPart = leftPreParts[index]
		const rightPart = rightPreParts[index]

		if (leftPart === undefined) {
			return -1
		}
		if (rightPart === undefined) {
			return 1
		}

		const leftNumeric = /^\d+$/.test(leftPart)
		const rightNumeric = /^\d+$/.test(rightPart)

		if (leftNumeric && rightNumeric) {
			const leftNum = Number(leftPart)
			const rightNum = Number(rightPart)
			if (leftNum > rightNum) {
				return 1
			}
			if (leftNum < rightNum) {
				return -1
			}
			continue
		}

		if (leftNumeric) {
			return -1
		}
		if (rightNumeric) {
			return 1
		}

		if (leftPart > rightPart) {
			return 1
		}
		if (leftPart < rightPart) {
			return -1
		}
	}

	return 0
}

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

const changeActionLabelText = computed(() => {
	const key = changeActionLabel.value
	if (key === 'Install') return t(APP_ID, 'Install')
	if (key === 'Update') return t(APP_ID, 'Update')
	if (key === 'Degrade') return t(APP_ID, 'Degrade')
	return ''
})

const hasSidebarSelect = computed(() => isAdmin.value)
const sidebarLabel = computed(() => hasSidebarSelect.value ? t(APP_ID, 'Select an app from store') : t(APP_ID, 'Loading…'))
const hasInfoPanel = computed(() => selectedApp.value || installedVersion.value || versions.value.length > 0 || availableSource.value || errorMessage.value || hasCheckedVersions.value)
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

const unwrapOcsResponse = <T>(raw: unknown): T => {
	if (typeof raw !== 'object' || raw === null) {
		throw new Error('Unexpected response format')
	}

	const meta = (raw as { ocs?: { meta?: { status?: string, statuscode?: number, message?: string } } }).ocs?.meta
	if (meta && (meta.status === 'failure' || (typeof meta.statuscode === 'number' && meta.statuscode >= 400))) {
		throw new Error(meta.message || 'OCS request failed')
	}

	return ((raw as { ocs?: { data?: T } }).ocs?.data ?? raw) as T
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

const unwrapOcsResponseWithMeta = <T>(raw: unknown): { payload: T, metaMessage?: string } => {
	if (typeof raw !== 'object' || raw === null) {
		throw new Error('Unexpected response format')
	}

	const typed = raw as OcsWrapped<T>
	const data = (typed.ocs?.data ?? typed.data ?? typed) as T
	const meta = typed.ocs?.meta
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
	// Server-side messages (`App updated.`, etc.) arrive in English and are
	// compared verbatim below; only the client-synthesised defaults go through
	// t(). The server-message → localised-message mapping is follow-up work.
	const resolvedMessage = payload.message || t(APP_ID, 'Install completed.')
	const shouldForceDowngradeMessage = normalizedUpdateType === 'downgrade'
		|| (normalizedFrom !== null && normalizedTo !== '' && compareVersions(normalizedTo, normalizedFrom) < 0)
	const finalMessage = shouldForceDowngradeMessage
		? (resolvedMessage === 'App updated.'
			? t(APP_ID, 'App downgraded.')
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
		return t(APP_ID, 'Dry run')
	case 'error':
		return t(APP_ID, 'Failed')
	default:
		return t(APP_ID, 'Done')
	}
})

const checkAdmin = async (): Promise<void> => {
	errorMessage.value = ''
	try {
		const response = await axios.get(apiUrl(withOcsJson('/ocs/v2.php/apps/app_versions/api/admin-check')), {
			headers: { ...ocsHeaders, Accept: 'application/json' },
			validateStatus: () => true,
		})
		const payload = unwrapOcsResponse<{ isAdmin: boolean }>(response.data)
		isAdmin.value = Boolean(payload.isAdmin)
	} catch {
		isAdmin.value = false
		errorMessage.value = t(APP_ID, 'Could not verify admin permissions.')
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
		const response = await axios.get(apiUrl(withOcsJson('/ocs/v2.php/apps/app_versions/api/update-channel')), {
			headers: { ...ocsHeaders, Accept: 'application/json' },
			validateStatus: () => true,
		})
		const payload = unwrapOcsResponse<{ updateChannel: string }>(response.data)
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
		const response = await axios.get(apiUrl(withOcsJson('/ocs/v2.php/apps/app_versions/api/apps')), {
			headers: { ...ocsHeaders, Accept: 'application/json' },
			validateStatus: () => true,
		})
		const payload = unwrapOcsResponse<{ apps: AppOption[] }>(response.data)
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
			const response = await axios.get(apiUrl(url), {
				headers: { ...ocsHeaders, Accept: 'application/json' },
				validateStatus: () => true,
			})
		const payload = unwrapOcsResponse<{
			availableVersions?: AppVersion[]
			versions?: AppVersion[]
			installedVersion: string | null
			source?: string
			error?: string
		}>(response.data)
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

	return visibleApps.filter((app) => {
		return app.label.toLowerCase().includes(filter) || app.id.toLowerCase().includes(filter)
	})
})

const selectedAppOption = computed(() => {
	return apps.value.find((app) => app.id === selectedApp.value) ?? null
})

const appCardDescription = (app: AppOption): string => {
	return app.summary || app.description || 'No description available.'
}

const appCardFallback = (app: AppOption): string => {
	const source = (app.label || app.id).trim()
	if (source === '') {
		return '?'
	}

	return source.charAt(0).toUpperCase()
}

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

const getVersionRangeSummary = (from: string, to: string): VersionRangeInfo | null => {
	if (!from || !to || from === to) {
		return null
	}

	const direction = compareVersions(to, from)
	const comparison = direction === 0 ? 0 : (direction > 0 ? 1 : -1)
	const low = comparison <= 0 ? to : from
	const high = comparison <= 0 ? from : to

	const inRange = versions.value.filter((entry) => {
		return compareVersions(entry.version, low) >= 0 && compareVersions(entry.version, high) <= 0
	})

	const majors = new Set<number>()
	const minors = new Set<string>()
	for (const entry of inRange) {
		const parsed = parseVersionCore(entry.version)
		majors.add(parsed.major)
		minors.add(`${parsed.major}.${parsed.minor}`)
	}

	const fromParsed = parseVersionCore(low)
	const toParsed = parseVersionCore(high)
	const patch = fromParsed.major === toParsed.major && fromParsed.minor === toParsed.minor
		? Math.abs(toParsed.patch - fromParsed.patch)
		: 0

	if (majors.size === 0) {
		const major = Math.abs(toParsed.major - fromParsed.major)
		const minor = comparison === 0
			? 0
			: (toParsed.major === fromParsed.major ? Math.abs(toParsed.minor - fromParsed.minor) : Math.abs(toParsed.minor - fromParsed.minor))

		return {
			major,
			minor,
			patch,
			direction: comparison > 0 ? 'upgrade' : 'degrade',
			from,
			to,
		}
	}

	return {
		major: Math.max(0, majors.size - 1),
		minor: Math.max(0, minors.size - 1),
		patch,
		direction: comparison > 0 ? 'upgrade' : 'degrade',
		from,
		to,
	}
}

const selectedVersionRange = computed(() => {
	if (!installedVersion.value || !selectedVersion.value || installedVersion.value === selectedVersion.value) {
		return null
	}

	return getVersionRangeSummary(installedVersion.value, selectedVersion.value)
})

const downgradeVersionRange = computed(() => getVersionRangeSummary(downgradeConfirmFromVersion.value, downgradeConfirmToVersion.value))

const versionRangeText = (summary: VersionRangeInfo | null): string => {
	if (!summary) {
		return ''
	}

	const isUpgrade = summary.direction === 'upgrade'

	if (summary.major === 0 && summary.minor === 0 && summary.patch > 0) {
		return isUpgrade
			? n(
				APP_ID,
				'Upgrade stays within major/minor and changes %n patch version step.',
				'Upgrade stays within major/minor and changes %n patch version steps.',
				summary.patch,
			)
			: n(
				APP_ID,
				'Downgrade stays within major/minor and changes %n patch version step.',
				'Downgrade stays within major/minor and changes %n patch version steps.',
				summary.patch,
			)
	}

	// Two counts (major + minor) in one sentence. translatePlural only picks
	// a plural form for one count — the trailing "minor version step(s)" —
	// so the major count is passed as a {major} substitution. Dutch uses the
	// same sentence shape, so this keeps the full sentence in one string.
	return isUpgrade
		? n(
			APP_ID,
			'Upgrade crosses {major} major and %n minor version step.',
			'Upgrade crosses {major} major and %n minor version steps.',
			summary.minor,
			{ major: summary.major },
		)
		: n(
			APP_ID,
			'Downgrade crosses {major} major and %n minor version step.',
			'Downgrade crosses {major} major and %n minor version steps.',
			summary.minor,
			{ major: summary.major },
		)
}

const resolveDowngrade = (value: boolean): void => {
	isDowngradeConfirmOpen.value = false
	downgradeResolve?.(value)
	downgradeResolve = null
}

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

const onDowngradeDialogOpenChange = (open: boolean) => {
	if (open) {
		return
	}

	// Treat ESC / outside-click as cancel.
	resolveDowngrade(false)
}

const onSelectVersion = (version: string): void => {
	if (isDowngradeBlockedBySafeMode(version)) {
		errorMessage.value = t(APP_ID, 'Safe mode is enabled. Disable it to downgrade.')
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
		const response = await axios.post(
			apiUrl(endpoint),
			{ version: selectedVersionValue },
			{
				headers: {
					...ocsHeaders,
					Accept: 'application/json',
					'Content-Type': 'application/json',
				},
				validateStatus: () => true,
			},
		)
		const { payload, metaMessage } = unwrapOcsResponseWithMeta<{
			appId: string
			toVersion: string
			fromVersion?: string
			installedVersion?: string
			updateType?: string
			message?: string
			dryRun?: boolean
			installStatus?: string
			debug?: unknown
		}>(response.data)
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
				:app="downgradeConfirmApp"
				:from-version="downgradeConfirmFromVersion"
				:to-version="downgradeConfirmToVersion"
				:range-text="downgradeVersionRange ? versionRangeText(downgradeVersionRange) : ''"
				:is-installing-version="isInstallingVersion"
				@update:open="onDowngradeDialogOpenChange"
				@confirm="resolveDowngrade(true)"
				@cancel="resolveDowngrade(false)"
			/>
			<div :class="$style.layout">
				<main :class="$style.mainContent">
					<h2>{{ t('app_versions', 'App Versions') }}</h2>
					<SettingsPanel
						:update-channel="updateChannel"
						:safe-mode="safeModeEnabled"
						:debug-mode="debugModeEnabled"
						:disabled="isInstallingVersion"
						@update:safe-mode="safeModeEnabled = $event"
						@update:debug-mode="debugModeEnabled = $event"
					/>
					<div :class="[$style.contentRow, { [$style.contentRowSplit]: hasSplitLayout }]">
							<div :class="[$style.leftColumn, { [$style.leftColumnFull]: !hasSplitLayout }]">
								<AppPicker
									:filtered-apps="filteredApps"
									:apps-length="apps.length"
									:selected-app="selectedApp"
									:app-filter="appFilter"
									:show-filters="showFilters"
									:core-apps-visibility="coreAppsVisibility"
									:has-sidebar-select="hasSidebarSelect"
									:has-split-layout="hasSplitLayout"
									:is-loading="isLoading"
									:is-checking-versions="isCheckingVersions"
									:is-installing-version="isInstallingVersion"
									:sidebar-label="sidebarLabel"
									:fallback-icon-for="appCardFallback"
									:description-for="appCardDescription"
									@update:app-filter="appFilter = $event"
									@update:show-filters="showFilters = $event"
									@update:core-apps-visibility="coreAppsVisibility = $event"
									@pick-app="onPickApp"
								/>
							<VersionPanel
								:open="hasInfoPanel"
								:selected-app="selectedApp"
								:selected-app-option="selectedAppOption"
								:installed-version="installedVersion"
								:selected-version="selectedVersion"
								:versions="versions"
								:visible-versions="visibleVersions"
								:filtered-versions="filteredVersions"
								:version-filter="versionFilter"
								:selected-version-range="selectedVersionRange"
								:range-text="selectedVersionRange ? versionRangeText(selectedVersionRange) : ''"
								:available-source="availableSource"
								:has-checked-versions="hasCheckedVersions"
								:error-message="errorMessage"
								:is-checking-versions="isCheckingVersions"
								:is-installing-version="isInstallingVersion"
								:change-action-key="changeActionLabel as 'Install' | 'Update' | 'Degrade' | ''"
								:change-action-label="changeActionLabelText"
								@update:version-filter="versionFilter = $event"
								@clear-selected-app="clearSelectedApp"
								@select-version="onSelectVersion"
								@deselect-version="selectedVersion = ''"
								@install="performInstall"
							/>
						</div>
							<div v-if="hasSplitLayout" :class="$style.rightColumn">
							<InstallResultPanel
								v-if="hasInstallResult && lastInstallResult"
								:result="lastInstallResult"
								:status-tone="installStatusTone"
								:status-label="installStatusLabel"
								:debug-mode-enabled="debugModeEnabled"
								:debug="lastInstallDebug"
							/>
						</div>
					</div>
				</main>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<style module>
.content {
	height: 100%;
	margin: 16px;
}

.layout {
	width: 100%;
}

.mainContent {
	width: 100%;
	padding-left: 16px;
	padding-right: 16px;
	box-sizing: border-box;
}

.contentRow {
	display: block;
	margin-top: 8px;
}

.contentRowSplit {
	display: flex;
	gap: 16px;
	align-items: stretch;
}

.leftColumn,
.rightColumn {
	flex: 1 1 0;
	min-width: 0;
}

.leftColumnFull {
	width: 100%;
}


</style>
