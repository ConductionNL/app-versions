<script setup lang="ts">
import type { InstallDebugEntry, InstallResult } from '../types'
import { debugHasData, debugToTextLines } from '../utils/debug'

defineProps<{
	result: InstallResult
	tone: 'success' | 'warning' | 'error' | 'info'
	label: string
	debugModeEnabled: boolean
	debugEntries: InstallDebugEntry[]
}>()
</script>

<template>
	<div :class="$style.resultPanel">
		<p :class="$style.versionSummary">Install result</p>
		<p :class="[$style.resultStatus, $style[`resultStatus${tone.charAt(0).toUpperCase() + tone.slice(1)}`]]">
			{{ label }}
		</p>
		<p :class="$style.resultMessage">{{ result.message }}</p>
		<div :class="$style.resultGrid">
			<div>
				<span>App</span>
				<strong>{{ result.appId || '-' }}</strong>
			</div>
			<div>
				<span>Transition</span>
				<strong>{{ result.fromVersion || 'N/A' }} → {{ result.toVersion }}</strong>
			</div>
			<div>
				<span>Mode</span>
				<strong>{{ result.installStatus === 'dry-run' ? 'Dry-run (no write)' : (result.dryRun ? 'Dry-run' : 'Live install') }}</strong>
			</div>
			<div>
				<span>Result</span>
				<strong>{{ result.installedVersion || result.toVersion }}</strong>
			</div>
		</div>
		<div
			v-if="debugModeEnabled && debugEntries.length > 0"
			:class="$style.debugPanel"
		>
			<p :class="$style.debugSubtitle">Install debug ({{ debugEntries.length }} step(s))</p>
			<div :class="$style.debugTimeline">
				<article
					v-for="(entry, entryIndex) in debugEntries"
					:key="`${entry.stage}-${entryIndex}`"
					:class="$style.debugStep"
				>
					<p :class="$style.debugStepHeader">
						<span :class="$style.debugStepIndex">{{ entryIndex + 1 }}</span>
						<span :class="$style.debugStepStage">{{ entry.stage }}</span>
					</p>
					<p v-if="!debugHasData(entry.data)" :class="$style.debugNoData">No details</p>
					<details v-else :class="$style.debugStepDetails" :open="entryIndex === 0">
						<summary :class="$style.debugStepSummary">View details</summary>
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

<style module src="../styles/InstallResultPanel.module.css"></style>
