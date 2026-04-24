<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<script setup lang="ts">
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import type { InstallDebugEntry, InstallResult } from '../types'

const props = defineProps<{
	result: InstallResult
	statusTone: 'success' | 'warning' | 'error' | 'info'
	statusLabel: string
	debugModeEnabled: boolean
	debug: InstallDebugEntry[]
}>()

const debugValueToString = (value: unknown): string => {
	if (value === null) return 'null'
	if (typeof value === 'string') return value
	if (typeof value === 'number' || typeof value === 'boolean') return String(value)
	if (typeof value === 'bigint') return value.toString()
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
		const obj = value as Record<string, unknown>
		const keys = Object.keys(obj)
		if (keys.length === 0) {
			lines.push(`${indent}{}`)
			return lines
		}
		for (const key of keys) {
			const nested = obj[key]
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
	if (value === null || value === undefined) return false
	if (typeof value === 'string') return value.trim() !== ''
	return true
}

const debugToTextLines = (value: unknown): string[] => {
	const lines = formatDebugLines(value)
	return lines.length === 0 ? ['—'] : lines
}

const toneClass = (tone: typeof props.statusTone) => {
	// Map tone → module-generated class name at runtime.
	return ({
		success: 'statusSuccess',
		warning: 'statusWarning',
		error: 'statusError',
		info: 'statusInfo',
	} as const)[tone]
}
</script>

<template>
	<div :class="$style.panel">
		<p :class="$style.title">{{ t('app_versions', 'Install result') }}</p>
		<p :class="[$style.status, $style[toneClass(statusTone)]]">
			{{ statusLabel }}
		</p>
		<p :class="$style.message">{{ result.message }}</p>
		<div :class="$style.grid">
			<div>
				<span>{{ t('app_versions', 'App') }}</span>
				<strong>{{ result.appId || '-' }}</strong>
			</div>
			<div>
				<span>{{ t('app_versions', 'Transition') }}</span>
				<strong>{{ result.fromVersion || t('app_versions', 'N/A') }} → {{ result.toVersion }}</strong>
			</div>
			<div>
				<span>{{ t('app_versions', 'Mode') }}</span>
				<strong>{{ result.installStatus === 'dry-run' ? t('app_versions', 'Dry-run (no write)') : (result.dryRun ? t('app_versions', 'Dry-run') : t('app_versions', 'Live install')) }}</strong>
			</div>
			<div>
				<span>{{ t('app_versions', 'Result') }}</span>
				<strong>{{ result.installedVersion || result.toVersion }}</strong>
			</div>
		</div>
		<div
			v-if="debugModeEnabled && debug.length > 0"
			:class="$style.debug"
		>
			<p :class="$style.debugSubtitle">
				{{ n('app_versions', 'Install debug (%n step)', 'Install debug (%n steps)', debug.length) }}
			</p>
			<div :class="$style.debugTimeline">
				<article
					v-for="(entry, entryIndex) in debug"
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
</template>

<style module>
.panel {
	padding: 1rem;
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
}

.title {
	margin: 0 0 0.5rem 0;
	font-weight: bold;
}

.status {
	margin: 0 0 0.5rem 0;
	padding: 0.25rem 0.5rem;
	border-radius: var(--border-radius);
	display: inline-block;
	font-weight: bold;
}

.statusSuccess {
	background: var(--color-success, #46ba61);
	color: var(--color-primary-element-text);
}

.statusWarning {
	background: var(--color-warning, #eca700);
	color: var(--color-primary-element-text);
}

.statusError {
	background: var(--color-error, #d73a4a);
	color: var(--color-primary-element-text);
}

.statusInfo {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.message {
	margin: 0 0 0.75rem 0;
}

.grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 0.5rem 1rem;
	margin-bottom: 1rem;
}

.grid span {
	display: block;
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.grid strong {
	display: block;
	overflow-wrap: anywhere;
}

.debug {
	margin-top: 1rem;
	padding-top: 0.75rem;
	border-top: 1px solid var(--color-border);
}

.debugSubtitle {
	margin: 0 0 0.5rem 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.debugTimeline {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.debugStep {
	padding: 0.5rem;
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.debugStepHeader {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin: 0 0 0.25rem 0;
	font-weight: bold;
}

.debugStepIndex {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 1.5rem;
	height: 1.5rem;
	border-radius: 50%;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-size: 0.75rem;
}

.debugStepStage {
	font-family: var(--font-face-monospace);
}

.debugNoData {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.debugStepDetails {
	margin-top: 0.25rem;
}

.debugStepSummary {
	cursor: pointer;
	color: var(--color-primary-element);
}

.debugOutput {
	margin: 0.5rem 0 0 0;
	padding: 0.5rem;
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	font-family: var(--font-face-monospace);
	font-size: 0.8rem;
	list-style: none;
	max-height: 300px;
	overflow: auto;
}

.debugOutputLine {
	white-space: pre;
}
</style>
