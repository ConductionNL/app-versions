<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import AppCard from './AppCard.vue'
import type { AppOption } from '../types'

const props = defineProps<{
	filteredApps: AppOption[]
	appsLength: number
	selectedApp: string
	appFilter: string
	showFilters: boolean
	coreAppsVisibility: 'show' | 'hide'
	hasSidebarSelect: boolean
	hasSplitLayout: boolean
	isLoading: boolean
	isCheckingVersions: boolean
	isInstallingVersion: boolean
	sidebarLabel: string
	fallbackIconFor: (app: AppOption) => string
	descriptionFor: (app: AppOption) => string
}>()

const emit = defineEmits<{
	(e: 'update:appFilter', value: string): void
	(e: 'update:showFilters', value: boolean): void
	(e: 'update:coreAppsVisibility', value: 'show' | 'hide'): void
	(e: 'pickApp', appId: string): void
}>()

const onToggleFilters = () => emit('update:showFilters', !props.showFilters)
</script>

<template>
	<div :class="$style.section">
		<label :class="$style.label" for="app-filter">{{ t('app_versions', 'Pick an installed App') }}</label>
		<div :class="$style.toolbar">
			<button
				type="button"
				:class="$style.toggleButton"
				@click="onToggleFilters"
			>
				{{ showFilters ? t('app_versions', 'Hide filters') : t('app_versions', 'Show filters') }}
			</button>
		</div>
		<div v-if="showFilters" :class="$style.filterPanel">
			<label :class="$style.field">
				<span :class="$style.fieldLabel">{{ t('app_versions', 'Core apps') }}</span>
				<select
					:value="coreAppsVisibility"
					:class="$style.select"
					@change="$emit('update:coreAppsVisibility', ($event.target as HTMLSelectElement).value as 'show' | 'hide')"
				>
					<option value="show">{{ t('app_versions', 'Show core apps') }}</option>
					<option value="hide">{{ t('app_versions', 'Hide core apps') }}</option>
				</select>
			</label>
		</div>
		<input
			id="app-filter"
			:value="appFilter"
			type="text"
			:placeholder="t('app_versions', 'Search apps')"
			:class="$style.input"
			:disabled="!hasSidebarSelect || isLoading || appsLength === 0 || isCheckingVersions || isInstallingVersion"
			:aria-label="sidebarLabel"
			@input="$emit('update:appFilter', ($event.target as HTMLInputElement).value)"
		>
		<div
			v-if="!selectedApp"
			:class="[$style.cardList, { [$style.cardListSplit]: hasSplitLayout }]"
		>
			<AppCard
				v-for="app in filteredApps"
				:key="app.id"
				:app="app"
				:selected="selectedApp === app.id"
				:disabled="isCheckingVersions || isInstallingVersion"
				:loading="selectedApp === app.id && isCheckingVersions"
				:fallback-icon="fallbackIconFor(app)"
				:description="descriptionFor(app)"
				@pick="(id) => emit('pickApp', id)"
			/>
		</div>
		<p v-if="!selectedApp && filteredApps.length === 0" :class="$style.empty">
			{{ t('app_versions', 'No apps match your filter.') }}
		</p>
	</div>
</template>

<style module>
.section {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-top: 12px;
}

.label {
	font-weight: 600;
}

.toolbar {
	display: flex;
	align-items: center;
	justify-content: flex-start;
}

.toggleButton {
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

.field {
	display: flex;
	flex-direction: column;
	gap: 6px;
	max-width: 260px;
}

.fieldLabel {
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.select {
	width: 100%;
	box-sizing: border-box;
	border: 1px solid var(--color-border-dark);
	border-radius: 6px;
	padding: 8px 10px;
	background: var(--color-main-background);
}

.input {
	width: 100%;
	box-sizing: border-box;
	border: 1px solid var(--color-border-dark);
	border-radius: 6px;
	padding: 8px 10px;
}

.cardList {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(min(100%, 240px), 1fr));
	gap: 16px;
	overflow-y: visible;
	overflow-x: hidden;
	padding-inline-end: 4px;
	align-content: start;
}

.cardListSplit {
	max-height: 360px;
	overflow-y: auto;
}

.empty {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
</style>
