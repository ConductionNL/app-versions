<script setup lang="ts">
import type { AppOption } from '../types'

const props = defineProps<{
	apps: AppOption[]
	appFilter: string
	showFilters: boolean
	coreAppsVisibility: 'show' | 'hide'
	selectedApp: string
	hasSplitLayout: boolean
	hasSidebarSelect: boolean
	isLoading: boolean
	isCheckingVersions: boolean
	isInstallingVersion: boolean
	sidebarLabel: string
}>()

const emit = defineEmits<{
	'update:appFilter': [value: string]
	'update:showFilters': [value: boolean]
	'update:coreAppsVisibility': [value: 'show' | 'hide']
	'pick-app': [appId: string]
}>()

const appCardDescription = (app: AppOption): string => app.summary || app.description || 'No description available.'

const appCardFallback = (app: AppOption): string => {
	const source = (app.label || app.id).trim()
	return source === '' ? '?' : source.charAt(0).toUpperCase()
}
</script>

<template>
	<div :class="$style.selectSection">
		<label :class="$style.label" for="app-filter">Pick an installed App</label>
		<div :class="$style.filterToolbar">
			<button
				type="button"
				:class="$style.filterToggleButton"
				@click="emit('update:showFilters', !props.showFilters)"
			>
				{{ props.showFilters ? 'Hide filters' : 'Show filters' }}
			</button>
		</div>
		<div v-if="props.showFilters" :class="$style.filterPanel">
			<label :class="$style.filterField">
				<span :class="$style.filterFieldLabel">Core apps</span>
				<select
					:value="props.coreAppsVisibility"
					:class="$style.filterSelect"
					@change="emit('update:coreAppsVisibility', ($event.target as HTMLSelectElement).value as 'show' | 'hide')"
				>
					<option value="show">Show core apps</option>
					<option value="hide">Hide core apps</option>
				</select>
			</label>
		</div>
		<input
			id="app-filter"
			:value="props.appFilter"
			type="text"
			placeholder="Search apps"
			:class="$style.appFilterInput"
			:disabled="!props.hasSidebarSelect || props.isLoading || props.apps.length === 0 || props.isCheckingVersions || props.isInstallingVersion"
			:aria-label="props.sidebarLabel"
			@input="emit('update:appFilter', ($event.target as HTMLInputElement).value)"
		/>
		<div
			v-if="!props.selectedApp"
			:class="[$style.appCardList, { [$style.appCardListSplit]: props.hasSplitLayout }]"
		>
			<article
				v-for="app in props.apps"
				:key="app.id"
				:class="[$style.appCard, { [$style.appCardSelected]: props.selectedApp === app.id, [$style.appCardCore]: app.isCore }]"
			>
				<div :class="$style.appCardBody">
					<div :class="$style.appCardHeader">
						<div :class="$style.appCardTitleBlock">
							<div :class="$style.appCardTitleRow">
								<p :class="$style.appCardTitle">{{ app.label }}</p>
								<span v-if="app.isCore" :class="$style.appCardCoreFlag">CORE</span>
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
					:disabled="props.isCheckingVersions || props.isInstallingVersion"
					@click="emit('pick-app', app.id)"
				>
					{{ props.selectedApp === app.id && props.isCheckingVersions ? 'Loading…' : 'Choose app' }}
				</button>
			</article>
		</div>
		<p v-if="!props.selectedApp && props.apps.length === 0" :class="$style.noFilterResult">
			No apps match your filter.
		</p>
	</div>
</template>

<style module src="../styles/AppPickerPanel.module.css"></style>
