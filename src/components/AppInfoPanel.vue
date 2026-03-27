<script setup lang="ts">
import type { AppOption, AppVersion, VersionRangeInfo } from '../types'

const props = defineProps<{
	selectedApp: string
	selectedAppOption: AppOption | null
	installedVersion: string
	selectedVersion: string
	selectedVersionRange: VersionRangeInfo | null
	versionRangeText: (summary: VersionRangeInfo | null) => string
	versions: AppVersion[]
	filteredVersions: AppVersion[]
	visibleVersions: AppVersion[]
	versionFilter: string
	changeActionLabel: string
	availableSource: string
	hasCheckedVersions: boolean
	errorMessage: string
	isCheckingVersions: boolean
	isInstallingVersion: boolean
}>()

const emit = defineEmits<{
	'update:versionFilter': [value: string]
	'clear-selected-app': []
	'select-version': [version: string]
	'deselect-version': []
	'perform-install': []
}>()
</script>

<template>
	<div :class="[$style.infoPanel, { [$style.infoPanelOpen]: props.selectedApp || props.installedVersion || props.versions.length > 0 || props.availableSource || props.errorMessage || props.hasCheckedVersions }]">
		<div v-if="props.selectedApp || props.installedVersion" :class="$style.installed">
			<div v-if="props.selectedApp" :class="$style.selectedApp">
				<span :class="$style.installedLabel">Selected app</span>
				<span :class="$style.installedValue">{{ props.selectedAppOption?.label || props.selectedApp }}</span>
				<span v-if="props.selectedAppOption?.label && props.selectedAppOption.id !== props.selectedAppOption.label" :class="$style.installedSubvalue">{{ props.selectedApp }}</span>
				<button
					type="button"
					:class="$style.changeAppButton"
					:disabled="props.isCheckingVersions || props.isInstallingVersion"
					@click="emit('clear-selected-app')"
				>
					Choose another app
				</button>
			</div>
			<div v-if="props.installedVersion" :class="$style.installedCurrent">
				<span :class="$style.installedLabel">Current installed</span>
				<span :class="$style.installedValue">{{ props.installedVersion }}</span>
			</div>
			<div v-if="props.selectedVersion" :class="$style.selectedVersion">
				<span :class="$style.installedLabel">Selected version</span>
				<span :class="$style.versionTransition">
					<span :class="$style.versionChip">{{ props.installedVersion || '—' }}</span>
					<span :class="$style.versionArrow">→</span>
					<span :class="$style.versionChip">{{ props.selectedVersion }}</span>
				</span>
			</div>
			<p v-if="props.selectedVersionRange" :class="$style.versionSummary">
				{{ props.versionRangeText(props.selectedVersionRange) }}
			</p>
			<p v-if="props.selectedVersionRange?.direction === 'degrade'" :class="$style.versionDegradeSummary">
				Downgrade path detected.
			</p>
		</div>

		<div v-if="props.versions.length > 0" :class="$style.versionListContainer">
			<input
				v-if="!props.selectedVersion"
				:value="props.versionFilter"
				type="text"
				placeholder="Filter versions"
				:class="$style.versionFilterInput"
				:disabled="props.isInstallingVersion"
				@input="emit('update:versionFilter', ($event.target as HTMLInputElement).value)"
			/>
			<div :class="$style.versionListWrapper">
				<transition-group
					name="versionFade"
					tag="ul"
					:class="$style.versionList"
				>
					<li v-for="version in props.visibleVersions" :key="version.version" :class="$style.versionItem">
						<div :class="$style.versionItemMain">
							<span>{{ version.version }}</span>
							<button
								v-if="props.selectedVersion !== version.version"
								type="button"
								:class="$style.versionSelectButton"
								:disabled="props.isInstallingVersion"
								@click="emit('select-version', version.version)"
							>
								Select
							</button>
							<span v-else :class="$style.selectedVersionFlag">
								Selected
							</span>
						</div>
						<div
							v-if="props.selectedVersion === version.version && props.selectedVersion !== ''"
							:class="$style.versionActionGroup"
						>
							<p
								v-if="props.changeActionLabel === 'Degrade'"
								:class="$style.versionDegradeWarning"
							>
								Warning! Downgrading can result in breaking the database if earlier updates or migrations added database columns. Only do this when u can fix the database or are sure no migrations have been executed since the version u downgrade to!
							</p>
							<div :class="$style.versionItemActions">
								<button
									v-if="props.changeActionLabel"
									type="button"
									:class="[$style.versionActionButton, props.changeActionLabel === 'Update' ? $style.versionActionUpdateButton : (props.changeActionLabel === 'Degrade' ? $style.versionActionDegradeButton : '')]"
									:aria-busy="props.isInstallingVersion"
									:disabled="props.isInstallingVersion"
									@click="emit('perform-install')"
								>
									<span v-if="props.isInstallingVersion" :class="$style.spinner" aria-hidden="true" />
									{{ props.isInstallingVersion ? 'Installing…' : props.changeActionLabel }}
								</button>
								<button
									type="button"
									:class="$style.versionDeselectButton"
									:disabled="props.isInstallingVersion"
									@click="emit('deselect-version')"
								>
									Pick other
								</button>
							</div>
						</div>
					</li>
				</transition-group>
				<p v-if="props.filteredVersions.length === 0" :class="$style.noFilterResult">
					No versions match your filter.
				</p>
			</div>
		</div>

		<p v-if="props.availableSource" :class="$style.note">
			Versions source: {{ props.availableSource }}
		</p>
		<p v-else-if="props.hasCheckedVersions" :class="$style.note">
			No versions available for this app.
		</p>
		<p v-if="props.errorMessage" :class="$style.error">{{ props.errorMessage }}</p>
	</div>
</template>

<style module src="../styles/AppInfoPanel.module.css"></style>
