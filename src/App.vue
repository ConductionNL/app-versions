<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<script setup lang="ts">
import axios from '@nextcloud/axios'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { NcAppContent, NcContent, NcDialog } from '@conduction/nextcloud-vue'
import { computed, onMounted, ref, watch } from 'vue'

const APP_ID = 'app_versions'

type AppOption = {
	id: string
	label: string
	description: string
	summary: string
	preview: string
	isCore: boolean
}

type AppVersion = {
	version: string
}

type InstallDebugEntry = {
	stage: string
	data?: unknown
}

type InstallResult = {
	appId: string
	fromVersion?: string | null
	toVersion: string
	installedVersion?: string | null
	updateType?: string
	message: string
	dryRun: boolean
	installStatus: string
	debug?: InstallDebugEntry[]
}

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

type VersionRangeInfo = {
	major: number
	minor: number
	patch: number
	direction: 'upgrade' | 'degrade'
	from: string
	to: string
}

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

const debugValueToString = (value: unknown): string => {
	if (value === null) {
		return 'null'
	}

	if (typeof value === 'string') {
		return value
	}

	if (typeof value === 'number' || typeof value === 'boolean') {
		return String(value)
	}

	if (typeof value === 'bigint') {
		return value.toString()
	}

	return JSON.stringify(value)
}

const formatDebugLines = (value: unknown, depth = 0): string[] => {
	const indent = ' '.repeat(depth * 2)
	const lines: string[] = []

	if (value === null || value === undefined) {
		lines.push(`${indent}—`)
		return lines
	}

	if (Array.isArray(value)) {
		if (value.length === 0) {
			lines.push(`${indent}[]`)
			return lines
		}

		value.forEach((entry, index) => {
			if (entry === null || entry === undefined) {
				lines.push(`${indent}[${index}]: —`)
				return
			}

			if (typeof entry === 'object') {
				lines.push(`${indent}[${index}]:`)
				lines.push(...formatDebugLines(entry, depth + 1))
			} else {
				lines.push(`${indent}[${index}]: ${debugValueToString(entry)}`)
			}
		})

		return lines
	}

	if (typeof value === 'object') {
		const objectValue = value as Record<string, unknown>
		const keys = Object.keys(objectValue)
		if (keys.length === 0) {
			lines.push(`${indent}{}`)
			return lines
		}

		for (const key of keys) {
			const nested = objectValue[key]
			if (nested === null || nested === undefined) {
				lines.push(`${indent}${key}: —`)
				continue
			}

			if (typeof nested === 'object') {
				lines.push(`${indent}${key}:`)
				lines.push(...formatDebugLines(nested, depth + 1))
				continue
			}

			lines.push(`${indent}${key}: ${debugValueToString(nested)}`)
		}
		return lines
	}

	lines.push(`${indent}${debugValueToString(value)}`)
	return lines
}

const debugHasData = (value: unknown): boolean => {
	if (value === null || value === undefined) {
		return false
	}

	if (typeof value === 'string') {
		return value.trim() !== ''
	}

	return true
}

const debugToTextLines = (value: unknown): string[] => {
	const lines = formatDebugLines(value)
	if (lines.length === 0) {
		return ['—']
	}

	return lines
}

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

	const downgradeConfirmButtons = computed(() => [
	{
		label: t(APP_ID, 'Cancel'),
		type: 'tertiary',
		disabled: isInstallingVersion.value,
		callback: () => {
			isDowngradeConfirmOpen.value = false
			downgradeResolve?.(false)
			downgradeResolve = null
		},
	},
	{
		label: t(APP_ID, 'Downgrade'),
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
			<NcDialog
				:open="isDowngradeConfirmOpen"
				:name="t('app_versions', 'Confirm downgrade')"
				:buttons="downgradeConfirmButtons"
				@update:open="onDowngradeDialogClose"
			>
				<p class="$style.downgradeConfirmText">
					<strong>{{ downgradeConfirmApp }}</strong>
				</p>
				<p class="$style.versionTransitionRow">
					<span :class="$style.versionChip">{{ downgradeConfirmFromVersion || '—' }}</span>
					<span :class="$style.versionArrow">→</span>
					<span :class="$style.versionChip">{{ downgradeConfirmToVersion }}</span>
				</p>
				<p v-if="downgradeVersionRange" :class="$style.versionRangeSummary">
					<strong>{{ t('app_versions', 'Downgrade info:') }}</strong> {{ versionRangeText(downgradeVersionRange) }}
				</p>
				<p :class="$style.versionItemDegradeMessage">
					{{ t('app_versions', 'Downgrading can break database schema assumptions if migrations were already applied in newer versions. Continue only if you are sure no incompatible schema changes are involved.') }}
				</p>
				</NcDialog>
				<div :class="$style.layout">
					<main :class="$style.mainContent">
						<h2>{{ t('app_versions', 'App Versions') }}</h2>
						<div :class="$style.settingsPanel">
							<p v-if="updateChannel" :class="$style.updateChannel">
								{{ t('app_versions', 'Update channel:') }} <strong>{{ updateChannel }}</strong>
							</p>
							<div :class="$style.settingsToggles">
								<label :class="$style.safeMode">
									<input
										type="checkbox"
										v-model="safeModeEnabled"
										:class="$style.safeModeCheckbox"
										:disabled="isInstallingVersion"
									/>
									<span>{{ t('app_versions', 'Safe mode (block downgrades and respects update channel)') }}</span>
								</label>
								<label :class="$style.safeMode">
									<input
										type="checkbox"
										v-model="debugModeEnabled"
										:class="$style.safeModeCheckbox"
										:disabled="isInstallingVersion"
									/>
									<span>{{ t('app_versions', 'Enable install dry-run (show debug output)') }}</span>
								</label>
							</div>
						</div>
						<div :class="[$style.contentRow, { [$style.contentRowSplit]: hasSplitLayout }]">
							<div :class="[$style.leftColumn, { [$style.leftColumnFull]: !hasSplitLayout }]">
								<div :class="$style.selectSection">
									<label :class="$style.label" for="app-filter">{{ t('app_versions', 'Pick an installed App') }}</label>
									<div :class="$style.filterToolbar">
										<button
											type="button"
											:class="$style.filterToggleButton"
											@click="showFilters = !showFilters"
										>
											{{ showFilters ? t('app_versions', 'Hide filters') : t('app_versions', 'Show filters') }}
										</button>
									</div>
									<div v-if="showFilters" :class="$style.filterPanel">
										<label :class="$style.filterField">
											<span :class="$style.filterFieldLabel">{{ t('app_versions', 'Core apps') }}</span>
											<select v-model="coreAppsVisibility" :class="$style.filterSelect">
												<option value="show">{{ t('app_versions', 'Show core apps') }}</option>
												<option value="hide">{{ t('app_versions', 'Hide core apps') }}</option>
											</select>
										</label>
									</div>
									<input
										id="app-filter"
									v-model="appFilter"
									type="text"
									:placeholder="t('app_versions', 'Search apps')"
									:class="$style.appFilterInput"
										:disabled="!hasSidebarSelect || isLoading || apps.length === 0 || isCheckingVersions || isInstallingVersion"
										:aria-label="sidebarLabel"
									/>
									<div
										v-if="!selectedApp"
										:class="[$style.appCardList, { [$style.appCardListSplit]: hasSplitLayout }]"
									>
										<article
											v-for="app in filteredApps"
											:key="app.id"
										:class="[$style.appCard, { [$style.appCardSelected]: selectedApp === app.id, [$style.appCardCore]: app.isCore }]"
										>
											<div :class="$style.appCardBody">
												<div :class="$style.appCardHeader">
													<div :class="$style.appCardTitleBlock">
														<div :class="$style.appCardTitleRow">
															<p :class="$style.appCardTitle">{{ app.label }}</p>
															<span v-if="app.isCore" :class="$style.appCardCoreFlag">{{ t('app_versions', 'CORE') }}</span>
														</div>
														<p :class="$style.appCardMeta">{{ app.id }}</p>
													</div>
													<div :class="$style.appCardMedia">
														<img
															v-if="app.preview"
															:src="app.preview"
															:alt="`${app.label} icon`"
															:class="$style.appCardIcon"
														/>
														<div v-else :class="$style.appCardFallbackIcon" aria-hidden="true">
															{{ appCardFallback(app) }}
														</div>
													</div>
												</div>
												<p :class="$style.appCardDescription">{{ appCardDescription(app) }}</p>
											</div>
											<button
											v-if="!app.isCore"
											type="button"
											:class="$style.appCardButton"
											:disabled="isCheckingVersions || isInstallingVersion"
											@click="onPickApp(app.id)"
										>
											{{ selectedApp === app.id && isCheckingVersions ? t('app_versions', 'Loading…') : t('app_versions', 'Choose app') }}
										</button>
										</article>
									</div>
									<p v-if="!selectedApp && filteredApps.length === 0" :class="$style.noFilterResult">
										{{ t('app_versions', 'No apps match your filter.') }}
									</p>
								</div>
							<div
								:class="[$style.infoPanel, { [$style.infoPanelOpen]: hasInfoPanel }]"
							>
								<div v-if="selectedApp || installedVersion" :class="$style.installed">
									<div v-if="selectedApp" :class="$style.selectedApp">
										<span :class="$style.installedLabel">{{ t('app_versions', 'Selected app') }}</span>
										<span :class="$style.installedValue">{{ selectedAppOption?.label || selectedApp }}</span>
										<span v-if="selectedAppOption?.label && selectedAppOption.id !== selectedAppOption.label" :class="$style.installedSubvalue">{{ selectedApp }}</span>
										<button
											type="button"
											:class="$style.changeAppButton"
											:disabled="isCheckingVersions || isInstallingVersion"
											@click="clearSelectedApp"
										>
											{{ t('app_versions', 'Choose another app') }}
										</button>
									</div>
									<div v-if="installedVersion" :class="$style.installedCurrent">
										<span :class="$style.installedLabel">{{ t('app_versions', 'Current installed') }}</span>
										<span :class="$style.installedValue">{{ installedVersion }}</span>
									</div>
									<div v-if="selectedVersion" :class="$style.selectedVersion">
										<span :class="$style.installedLabel">{{ t('app_versions', 'Selected version') }}</span>
										<span :class="$style.versionTransition">
											<span :class="$style.versionChip">{{ installedVersion || '—' }}</span>
											<span :class="$style.versionArrow">→</span>
											<span :class="$style.versionChip">{{ selectedVersion }}</span>
										</span>
									</div>
									<p
										v-if="selectedVersionRange"
										:class="$style.versionSummary"
									>
										{{ versionRangeText(selectedVersionRange) }}
									</p>
									<p
										v-if="selectedVersionRange?.direction === 'degrade'"
										:class="$style.versionDegradeSummary"
									>
										{{ t('app_versions', 'Downgrade path detected.') }}
									</p>
								</div>
								<div v-if="versions.length > 0" :class="$style.versionListContainer">
									<input
										v-if="!selectedVersion"
										v-model="versionFilter"
										type="text"
										:placeholder="t('app_versions', 'Filter versions')"
										:class="$style.versionFilterInput"
										:disabled="isInstallingVersion"
									/>
									<div :class="$style.versionListWrapper">
										<transition-group
											name="versionFade"
											tag="ul"
											:class="$style.versionList"
										>
											<li v-for="version in visibleVersions" :key="version.version" :class="$style.versionItem">
												<div :class="$style.versionItemMain">
													<span>{{ version.version }}</span>
													<button
														v-if="selectedVersion !== version.version"
														type="button"
														:class="$style.versionSelectButton"
														:disabled="isInstallingVersion"
														@click="onSelectVersion(version.version)"
													>
														{{ t('app_versions', 'Select') }}
													</button>
													<span
														v-else
														:class="$style.selectedVersionFlag"
													>
														{{ t('app_versions', 'Selected') }}
													</span>
												</div>
												<div
													v-if="selectedVersion === version.version && selectedVersion !== ''"
													:class="$style.versionActionGroup"
												>
													<p
														v-if="changeActionLabel === 'Degrade'"
														:class="$style.versionDegradeWarning"
													>
														{{ t('app_versions', 'Warning! Downgrading can result in breaking the database if earlier updates or migrations added database columns. Only do this when you can fix the database or are sure no migrations have been executed since the version you downgrade to!') }}
													</p>
													<div :class="$style.versionItemActions">
														<button
															v-if="changeActionLabel"
															type="button"
															:class="[$style.versionActionButton, changeActionLabel === 'Update' ? $style.versionActionUpdateButton : (changeActionLabel === 'Degrade' ? $style.versionActionDegradeButton : '')]"
															:aria-busy="isInstallingVersion"
															:disabled="isInstallingVersion"
															@click="performInstall"
														>
															<span v-if="isInstallingVersion" :class="$style.spinner" aria-hidden="true" />
															{{ isInstallingVersion ? t('app_versions', 'Installing…') : changeActionLabelText }}
														</button>
														<button
															type="button"
															:class="$style.versionDeselectButton"
															:disabled="isInstallingVersion"
															@click="selectedVersion = ''"
														>
															{{ t('app_versions', 'Pick other') }}
														</button>
													</div>
												</div>
											</li>
										</transition-group>
										<p v-if="filteredVersions.length === 0" :class="$style.noFilterResult">
											{{ t('app_versions', 'No versions match your filter.') }}
										</p>
									</div>
								</div>
								<p v-if="availableSource" :class="$style.note">
									{{ t('app_versions', 'Versions source:') }} {{ availableSource }}
								</p>
								<p v-else-if="hasCheckedVersions" :class="$style.note">
									{{ t('app_versions', 'No versions available for this app.') }}
								</p>
								<p v-if="errorMessage" :class="$style.error">{{ errorMessage }}</p>
							</div>
						</div>
							<div v-if="hasSplitLayout" :class="$style.rightColumn">
							<div v-if="hasInstallResult && lastInstallResult" :class="$style.resultPanel">
								<p :class="$style.versionSummary">{{ t('app_versions', 'Install result') }}</p>
								<p :class="[$style.resultStatus, $style[`resultStatus${installStatusTone.charAt(0).toUpperCase() + installStatusTone.slice(1)}`]]">
									{{ installStatusLabel }}
								</p>
								<p :class="$style.resultMessage">{{ lastInstallResult.message }}</p>
								<div :class="$style.resultGrid">
									<div>
										<span>{{ t('app_versions', 'App') }}</span>
										<strong>{{ lastInstallResult.appId || '-' }}</strong>
									</div>
									<div>
										<span>{{ t('app_versions', 'Transition') }}</span>
										<strong>{{ lastInstallResult.fromVersion || t('app_versions', 'N/A') }} → {{ lastInstallResult.toVersion }}</strong>
									</div>
									<div>
										<span>{{ t('app_versions', 'Mode') }}</span>
										<strong>{{ lastInstallResult.installStatus === 'dry-run' ? t('app_versions', 'Dry-run (no write)') : (lastInstallResult.dryRun ? t('app_versions', 'Dry-run') : t('app_versions', 'Live install')) }}</strong>
									</div>
									<div>
										<span>{{ t('app_versions', 'Result') }}</span>
										<strong>{{ lastInstallResult.installedVersion || lastInstallResult.toVersion }}</strong>
									</div>
								</div>
								<div
									v-if="debugModeEnabled && lastInstallDebug.length > 0"
									:class="$style.debugPanel"
								>
									<p :class="$style.debugSubtitle">{{ n('app_versions', 'Install debug (%n step)', 'Install debug (%n steps)', lastInstallDebug.length) }}</p>
									<div :class="$style.debugTimeline">
										<article
											v-for="(entry, entryIndex) in lastInstallDebug"
											:key="`${entry.stage}-${entryIndex}`"
											:class="$style.debugStep"
										>
											<p :class="$style.debugStepHeader">
												<span :class="$style.debugStepIndex">{{ entryIndex + 1 }}</span>
												<span :class="$style.debugStepStage">{{ entry.stage }}</span>
											</p>
											<p v-if="!debugHasData(entry.data)" :class="$style.debugNoData">{{ t('app_versions', 'No details') }}</p>
											<details v-else :class="$style.debugStepDetails" :open="entryIndex === 0">
												<summary :class="$style.debugStepSummary">{{ t('app_versions', 'View details') }}</summary>
												<ul :class="$style.debugOutput">
													<li
														v-for="(line, lineIndex) in debugToTextLines(entry.data)"
														:key="`${entry.stage}-line-${lineIndex}`"
														:class="$style.debugOutputLine"
													>
														{{ line }}
													</li>
												</ul>
											</details>
										</article>
									</div>
								</div>
							</div>
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

.updateChannel {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.mainContent {
	width: 100%;
	padding-left: 16px;
	padding-right: 16px;
	box-sizing: border-box;
}

.settingsPanel {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-top: 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-main-background);
}

.settingsToggles {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 12px;
}

.selectSection {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-top: 12px;
}

.filterToolbar {
	display: flex;
	align-items: center;
	justify-content: flex-start;
}

.filterToggleButton {
	align-self: flex-start;
}

.filterPanel {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 10px 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: 8px;
	background: var(--color-main-background);
}

.filterField {
	display: flex;
	flex-direction: column;
	gap: 6px;
	max-width: 260px;
}

.filterFieldLabel {
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.filterSelect {
	width: 100%;
	box-sizing: border-box;
	border: 1px solid var(--color-border-dark);
	border-radius: 6px;
	padding: 8px 10px;
	background: var(--color-main-background);
}

.appFilterInput {
	width: 100%;
	box-sizing: border-box;
	border: 1px solid var(--color-border-dark);
	border-radius: 6px;
	padding: 8px 10px;
}

.appCardList {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(min(100%, 240px), 1fr));
	gap: 16px;
	overflow-y: visible;
	overflow-x: hidden;
	padding-inline-end: 4px;
	align-content: start;
}

.appCardListSplit {
	max-height: 360px;
	overflow-y: auto;
}

.appCard {
	display: flex;
	flex-direction: column;
	justify-content: space-between;
	gap: 12px;
	padding: 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: 8px;
	background: var(--color-main-background);
	min-height: 124px;
	min-width: 0;
	box-shadow: 0 6px 18px rgba(15, 23, 42, 0.1);
}

.appCardMedia {
	display: flex;
	align-items: center;
	margin-left: auto;
	flex-shrink: 0;
}

.appCardSelected {
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 1px color-mix(in srgb, var(--color-primary-element) 30%, transparent);
}

.appCardCore {
	border-color: #ef4444;
	box-shadow: 0 6px 18px rgba(127, 29, 29, 0.12);
}

.appCardIcon,
.appCardFallbackIcon {
	width: 48px;
	height: 48px;
	border-radius: 10px;
	border: 1px solid var(--color-border-dark);
	background: color-mix(in srgb, var(--color-main-background) 92%, var(--color-primary-element) 8%);
}

.appCardIcon {
	display: block;
	object-fit: contain;
	padding: 6px;
	box-sizing: border-box;
}

.appCardFallbackIcon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	font-weight: 700;
	font-size: 18px;
	color: var(--color-primary-element);
}

.appCardBody {
	display: flex;
	flex-direction: column;
	gap: 6px;
	min-width: 0;
}

.appCardHeader {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	min-width: 0;
	justify-content: space-between;
}

.appCardTitleBlock {
	display: flex;
	flex-direction: column;
	gap: 6px;
	min-width: 0;
}

.appCardTitleRow {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}

.appCardTitle,
.appCardMeta {
	margin: 0;
}

.appCardTitle {
	font-weight: 700;
	color: var(--color-main-text);
	word-break: break-word;
}

.appCardMeta {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
	word-break: break-all;
}

.appCardCoreFlag {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	padding: 2px 8px;
	border-radius: 9999px;
	background: #fee2e2;
	border: 1px solid #ef4444;
	color: #991b1b;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.04em;
	flex-shrink: 0;
}

.appCardDescription {
	margin: 0;
	font-size: 13px;
	line-height: 1.35;
	color: var(--color-text-maxcontrast);
}

.appCardButton {
	align-self: flex-start;
}

.installedSubvalue {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

.changeAppButton {
	align-self: flex-start;
	margin-top: 8px;
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

.rowInline {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 10px;
}

.installed {
	border-left: 4px solid var(--color-border-dark);
	padding: 8px 10px;
	width: 100%;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.infoPanel {
	margin-top: 8px;
	width: 100%;
	overflow: visible;
	max-height: 0;
	opacity: 0;
	transform: scaleX(0);
	transform-origin: right center;
	pointer-events: none;
	background: var(--color-main-background);
	border: 1px solid var(--color-border-dark);
	border-left-width: 4px;
	border-radius: 6px;
	padding: 10px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	box-sizing: border-box;
	transition:
		max-height 0.28s ease,
		opacity 0.2s ease,
		transform 0.28s ease;
}

.infoPanelOpen {
	opacity: 1;
	transform: scaleX(1);
	max-height: calc(100vh - 160px);
	pointer-events: auto;
}

.selectedApp,
.installedCurrent {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.installedLabel {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-right: 6px;
}

.installedValue {
	font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
	font-weight: 600;
	font-size: 14px;
}

.versionList {
	padding-inline-start: 20px;
	margin: 8px 0 0;
}

.versionItem {
	width: 100%;
	display: flex;
	flex-direction: column;
	align-items: stretch;
	gap: 6px;
	justify-content: flex-start;
	padding: 6px 0;
	transition:
		opacity 0.18s ease,
		transform 0.18s ease,
		max-height 0.18s ease;
	overflow: visible;
}

.versionItemMain {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 10px;
	width: 100%;
}

.versionActionGroup {
	flex-direction: column;
	gap: 8px;
	display: flex;
	width: 100%;
}

.versionDegradeWarning {
	margin: 0;
	padding: 8px 10px;
	border: 1px solid #fdba74;
	background: #ffedd5;
	color: #7c2d12;
	border-radius: 6px;
	font-size: 12px;
	line-height: 1.3;
}

.downgradeConfirmText {
	font-size: 14px;
	line-height: 1.4;
}

.versionItemDegradeMessage {
	margin: 8px 0 0;
	padding: 8px 10px;
	border: 1px solid #fdba74;
	background: #ffedd5;
	color: #7c2d12;
	border-radius: 6px;
	font-size: 12px;
	line-height: 1.3;
}

.versionItemActions {
	margin-top: 8px;
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 10px;
	width: 100%;
}

.versionSelectButton {
	line-height: 1.1;
	margin: 2px 0;
	visibility: hidden;
	opacity: 0;
	transition: opacity 0.12s ease;
	padding: 3px 10px;
	border: 1px solid var(--color-primary-element);
	color: var(--color-primary-element);
	border-radius: 6px;
	background: var(--color-main-background);
	font-size: 12px;
}

.versionItem:hover .versionSelectButton,
.versionSelectButton:focus-visible {
	visibility: visible;
	opacity: 1;
}

.versionSelectButton:hover {
	filter: brightness(1.05);
}

.selectedVersionFlag {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	font-size: 12px;
	font-weight: 600;
	color: #1f2937;
	background: #e0f2fe;
	border: 1px solid #38bdf8;
	border-radius: 9999px;
	padding: 2px 10px;
	line-height: 1.3;
	margin-left: auto;
}

.versionActionButton {
	appearance: none;
	-webkit-appearance: none;
	border-radius: 6px;
	padding: 3px 10px;
	font-size: 12px;
	line-height: 1.1;
	cursor: pointer;
	box-sizing: border-box;
	flex: 1 1 0;
	width: calc(50% - 5px);
}

.versionActionUpdateButton {
	color: #166534 !important;
	border: 1px solid #22c55e !important;
	background: #dcfce7 !important;
}

.versionActionUpdateButton:hover {
	background: #bbf7d0 !important;
}

.versionActionDegradeButton {
	color: #991b1b !important;
	border: 1px solid #ef4444 !important;
	background: #fee2e2 !important;
}

.versionActionDegradeButton:hover {
	background: #fecaca !important;
}

:global(.versionFade-move),
:global(.versionFade-enter-active),
:global(.versionFade-leave-active) {
	transition: all 0.2s ease;
}

:global(.versionFade-enter-from),
:global(.versionFade-leave-to) {
	opacity: 0;
	transform: translateY(-4px);
}

:global(.versionFade-leave-active) {
	position: absolute;
}

.versionListContainer {
	max-height: calc(100vh - 420px);
	min-height: 120px;
	overflow: hidden;
	overflow-x: hidden;
	width: 100%;
	display: flex;
	flex-direction: column;
	padding-inline-end: 4px;
}

.versionListWrapper {
	width: 100%;
	max-height: calc(100vh - 460px);
	min-height: 80px;
	flex: 1;
	overflow-y: scroll;
	overflow-x: hidden;
	scrollbar-gutter: stable;
	scrollbar-width: thin;
	scrollbar-color: var(--color-text-maxcontrast) var(--color-background-dark);
}

.versionFilterInput {
	width: 100%;
	box-sizing: border-box;
	border: 1px solid var(--color-border-dark);
	border-radius: 6px;
	padding: 6px 8px;
	margin-bottom: 8px;
}

.noFilterResult {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.versionListWrapper::-webkit-scrollbar {
	width: 8px;
}

.versionListWrapper::-webkit-scrollbar-track {
	background: var(--color-background-dark);
	border-radius: 4px;
}

.versionListWrapper::-webkit-scrollbar-thumb {
	background: var(--color-text-maxcontrast);
	border-radius: 4px;
}

.versionListWrapper::-webkit-scrollbar-thumb:hover {
	background: var(--color-text-light);
}

:global(.versionFade-move) {
	transition: transform 0.2s ease;
}

.label {
	font-weight: 600;
}

.safeMode {
	display: inline-flex;
	gap: 8px;
	align-items: center;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	width: 100%;
}

.safeModeCheckbox {
	accent-color: var(--color-primary-element);
}

.versionDeselectButton {
	box-sizing: border-box;
	flex: 1 1 0;
	width: calc(50% - 5px);
}

.spinner {
	display: inline-block;
	width: 0.95em;
	height: 0.95em;
	border: 2px solid rgba(255, 255, 255, 0.35);
	border-top-color: currentColor;
	border-radius: 50%;
	margin-right: 7px;
	vertical-align: -1px;
	animation: spin 0.85s linear infinite;
}

@keyframes spin {
	to {
		transform: rotate(360deg);
	}
}

.selectedVersion {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.versionTransition {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 14px;
}

.versionTransitionRow {
	margin: 0;
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 14px;
}

.versionChip {
	font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
	font-weight: 600;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	padding: 2px 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: 9999px;
	background: var(--color-main-background);
}

.versionArrow {
	font-weight: 700;
	color: var(--color-text-light);
}

.versionSummary,
.versionRangeSummary {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-light);
}

.debugPanel {
	margin-top: 0;
	height: 100%;
	display: flex;
	flex-direction: column;
	gap: 6px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	padding: 8px;
	background: var(--color-main-background);
}

.resultPanel {
	border: 1px solid var(--color-border);
	border-radius: 6px;
	padding: 8px;
	background: var(--color-main-background);
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.resultStatus {
	margin: 0;
	align-self: flex-start;
	padding: 3px 10px;
	border-radius: 9999px;
	font-size: 11px;
	font-weight: 700;
	color: var(--color-main-background);
}

.resultStatusSuccess {
	background: #16a34a;
}

.resultStatusWarning {
	background: #ea580c;
}

.resultStatusError {
	background: #dc2626;
}

.resultStatusInfo {
	background: #475569;
}

.resultMessage {
	margin: 0;
	font-size: 13px;
	font-weight: 600;
}

.resultGrid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: 6px;
	padding: 8px;
}

.resultGrid div {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.resultGrid span {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.resultGrid strong {
	font-size: 12px;
	word-break: break-all;
}

.debugSubtitle {
	margin: 0;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.debugTimeline {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.debugStep {
	border: 1px solid var(--color-border-dark);
	border-radius: 6px;
	padding: 6px 8px;
	background: color-mix(in srgb, var(--color-main-background) 96%, white 4%);
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.debugStepHeader {
	margin: 0;
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 12px;
}

.debugStepIndex {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 20px;
	height: 20px;
	border-radius: 9999px;
	font-weight: 700;
	font-size: 11px;
	padding: 0 6px;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.debugStepStage {
	font-weight: 600;
}

.debugStepDetails {
	margin: 0;
}

.debugStepSummary {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}

.debugOutput {
	list-style: none;
	max-height: 260px;
	overflow: auto;
	margin: 4px 0 0;
	padding: 8px;
	background: #0f172a;
	color: #e2e8f0;
	border-radius: 4px;
	border: 1px solid #1e293b;
	font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
	font-size: 12px;
	line-height: 1.35;
}

.debugOutputLine {
	margin: 0;
	padding: 0;
	line-height: 1.35;
	white-space: pre;
	font-family: inherit;
}

.debugNoData {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.versionDegradeSummary {
	margin: 2px 0 0;
	color: #7c2d12;
	font-size: 12px;
	font-weight: 600;
}

.note {
	font-size: 12px;
	margin: 2px 0 0;
	color: var(--color-text-maxcontrast);
}

.error {
	margin: 12px 0 0;
	color: var(--color-error);
	font-size: 13px;
}

</style>
