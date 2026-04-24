<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'

defineProps<{
	updateChannel: string
	safeMode: boolean
	debugMode: boolean
	disabled: boolean
}>()

defineEmits<{
	(e: 'update:safeMode', value: boolean): void
	(e: 'update:debugMode', value: boolean): void
}>()
</script>

<template>
	<div :class="$style.panel">
		<p v-if="updateChannel" :class="$style.channel">
			{{ t('app_versions', 'Update channel:') }} <strong>{{ updateChannel }}</strong>
		</p>
		<div :class="$style.toggles">
			<label :class="$style.toggle">
				<input
					type="checkbox"
					:checked="safeMode"
					:class="$style.checkbox"
					:disabled="disabled"
					@change="$emit('update:safeMode', ($event.target as HTMLInputElement).checked)"
				>
				<span>{{ t('app_versions', 'Safe mode (block downgrades and respects update channel)') }}</span>
			</label>
			<label :class="$style.toggle">
				<input
					type="checkbox"
					:checked="debugMode"
					:class="$style.checkbox"
					:disabled="disabled"
					@change="$emit('update:debugMode', ($event.target as HTMLInputElement).checked)"
				>
				<span>{{ t('app_versions', 'Enable install dry-run (show debug output)') }}</span>
			</label>
		</div>
	</div>
</template>

<style module>
.panel {
	padding: 0.75rem 1rem;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
	margin-bottom: 1rem;
}

.channel {
	margin: 0 0 0.5rem 0;
	color: var(--color-text-maxcontrast);
}

.toggles {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.toggle {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	cursor: pointer;
}

.toggle input:disabled {
	cursor: not-allowed;
}

.checkbox {
	margin: 0;
}
</style>
