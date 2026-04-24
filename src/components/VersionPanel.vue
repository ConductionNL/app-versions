<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import VersionItem from './VersionItem.vue'
import type { AppOption, AppVersion, VersionRangeInfo } from '../types'

defineProps<{
	open: boolean
	selectedApp: string
	selectedAppOption: AppOption | undefined
	installedVersion: string
	selectedVersion: string
	versions: AppVersion[]
	visibleVersions: AppVersion[]
	filteredVersions: AppVersion[]
	versionFilter: string
	selectedVersionRange: VersionRangeInfo | null
	rangeText: string
	availableSource: string
	hasCheckedVersions: boolean
	errorMessage: string
	isCheckingVersions: boolean
	isInstallingVersion: boolean
	changeActionKey: 'Install' | 'Update' | 'Degrade' | ''
	changeActionLabel: string
}>()

defineEmits<{
	(e: 'update:versionFilter', value: string): void
	(e: 'clearSelectedApp'): void
	(e: 'selectVersion', version: string): void
	(e: 'deselectVersion'): void
	(e: 'install'): void
}>()
</script>

<template>
	<div :class="[$style.panel, { [$style.open]: open }]">
		<div v-if="selectedApp || installedVersion" :class="$style.installed">
			<div v-if="selectedApp" :class="$style.block">
				<span :class="$style.label">{{ t('app_versions', 'Selected app') }}</span>
				<span :class="$style.value">{{ selectedAppOption?.label || selectedApp }}</span>
				<span
					v-if="selectedAppOption?.label && selectedAppOption.id !== selectedAppOption.label"
					:class="$style.subvalue"
				>
					{{ selectedApp }}
				</span>
				<button
					type="button"
					:class="$style.changeButton"
					:disabled="isCheckingVersions || isInstallingVersion"
					@click="$emit('clearSelectedApp')"
				>
					{{ t('app_versions', 'Choose another app') }}
				</button>
			</div>
			<div v-if="installedVersion" :class="$style.block">
				<span :class="$style.label">{{ t('app_versions', 'Current installed') }}</span>
				<span :class="$style.value">{{ installedVersion }}</span>
			</div>
			<div v-if="selectedVersion" :class="$style.transitionBlock">
				<span :class="$style.label">{{ t('app_versions', 'Selected version') }}</span>
				<span :class="$style.transitionRow">
					<span :class="$style.chip">{{ installedVersion || '—' }}</span>
					<span :class="$style.arrow">→</span>
					<span :class="$style.chip">{{ selectedVersion }}</span>
				</span>
			</div>
			<p v-if="selectedVersionRange" :class="$style.summary">
				{{ rangeText }}
			</p>
			<p v-if="selectedVersionRange?.direction === 'degrade'" :class="$style.degradeSummary">
				{{ t('app_versions', 'Downgrade path detected.') }}
			</p>
		</div>
		<div v-if="versions.length > 0" :class="$style.listContainer">
			<input
				v-if="!selectedVersion"
				:value="versionFilter"
				type="text"
				:placeholder="t('app_versions', 'Filter versions')"
				:class="$style.filterInput"
				:disabled="isInstallingVersion"
				@input="$emit('update:versionFilter', ($event.target as HTMLInputElement).value)"
			>
			<div :class="$style.listWrapper">
				<transition-group
					name="versionFade"
					tag="ul"
					:class="$style.list"
				>
					<VersionItem
						v-for="version in visibleVersions"
						:key="version.version"
						:version="version.version"
						:selected="selectedVersion === version.version && selectedVersion !== ''"
						:is-installing-version="isInstallingVersion"
						:change-action-key="changeActionKey"
						:change-action-label="changeActionLabel"
						@select="(v) => $emit('selectVersion', v)"
						@deselect="$emit('deselectVersion')"
						@install="$emit('install')"
					/>
				</transition-group>
				<p v-if="filteredVersions.length === 0" :class="$style.empty">
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
</template>

<style module>
.panel {
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

.open {
	opacity: 1;
	transform: scaleX(1);
	max-height: calc(100vh - 160px);
	pointer-events: auto;
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

.block {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-right: 6px;
}

.value {
	font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
	font-weight: 600;
	font-size: 14px;
}

.subvalue {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

.changeButton {
	align-self: flex-start;
	margin-top: 8px;
}

.transitionBlock {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.transitionRow {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 14px;
}

.chip {
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

.arrow {
	font-weight: 700;
	color: var(--color-text-light);
}

.summary {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-light);
}

.degradeSummary {
	margin: 2px 0 0;
	color: #7c2d12;
	font-size: 12px;
	font-weight: 600;
}

.listContainer {
	max-height: calc(100vh - 420px);
	min-height: 120px;
	overflow: hidden;
	overflow-x: hidden;
	width: 100%;
	display: flex;
	flex-direction: column;
	padding-inline-end: 4px;
}

.filterInput {
	width: 100%;
	box-sizing: border-box;
	border: 1px solid var(--color-border-dark);
	border-radius: 6px;
	padding: 6px 8px;
	margin-bottom: 8px;
}

.listWrapper {
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

.listWrapper::-webkit-scrollbar {
	width: 8px;
}

.listWrapper::-webkit-scrollbar-track {
	background: var(--color-background-dark);
	border-radius: 4px;
}

.listWrapper::-webkit-scrollbar-thumb {
	background: var(--color-text-maxcontrast);
	border-radius: 4px;
}

.listWrapper::-webkit-scrollbar-thumb:hover {
	background: var(--color-text-light);
}

.list {
	padding-inline-start: 20px;
	margin: 8px 0 0;
}

.empty {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
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

:global(.versionFade-move) {
	transition: transform 0.2s ease;
}
</style>
