<script setup lang="ts">
import NcDialog from '@nextcloud/vue/components/NcDialog'
import type { VersionRangeInfo } from '../types'

defineProps<{
	open: boolean
	buttons: Array<Record<string, unknown>>
	appId: string
	fromVersion: string
	toVersion: string
	range: VersionRangeInfo | null
	versionRangeText: (summary: VersionRangeInfo | null) => string
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
}>()
</script>

<template>
	<NcDialog
		:open="open"
		name="Confirm downgrade"
		:buttons="buttons"
		@update:open="emit('update:open', $event)"
	>
		<p :class="$style.downgradeConfirmText">
			<strong>{{ appId }}</strong>
		</p>
		<p :class="$style.versionTransitionRow">
			<span :class="$style.versionChip">{{ fromVersion || '—' }}</span>
			<span :class="$style.versionArrow">→</span>
			<span :class="$style.versionChip">{{ toVersion }}</span>
		</p>
		<p v-if="range" :class="$style.versionRangeSummary">
			<strong>Downgrade info:</strong> {{ versionRangeText(range) }}
		</p>
		<p :class="$style.versionItemDegradeMessage">
			Downgrading can break database schema assumptions if migrations were already applied in newer versions. Continue only if you are sure no incompatible schema changes are involved.
		</p>
	</NcDialog>
</template>

<style module src="../styles/DowngradeConfirmDialog.module.css"></style>
