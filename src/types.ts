// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

export type AppOption = {
	id: string
	label: string
	description: string
	summary: string
	preview: string
	isCore: boolean
}

export type AppVersion = {
	version: string
}

export type InstallDebugEntry = {
	stage: string
	data?: unknown
}

export type InstallResult = {
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

export type VersionRangeInfo = {
	major: number
	minor: number
	patch: number
	direction: 'upgrade' | 'degrade'
	from: string
	to: string
}
