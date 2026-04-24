<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<script setup lang="ts">
import { NcDialog } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'

const props = defineProps<{
	open: boolean
	app: string
	fromVersion: string
	toVersion: string
	rangeText: string
	isInstallingVersion: boolean
}>()

const emit = defineEmits<{
	(e: 'update:open', value: boolean): void
	(e: 'confirm'): void
	(e: 'cancel'): void
}>()

const buttons = computed(() => [
	{
		label: t('app_versions', 'Cancel'),
		type: 'tertiary',
		disabled: props.isInstallingVersion,
		callback: () => emit('cancel'),
	},
	{
		label: t('app_versions', 'Downgrade'),
		variant: 'error',
		disabled: props.isInstallingVersion,
		callback: () => emit('confirm'),
	},
])
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('app_versions', 'Confirm downgrade')"
		:buttons="buttons"
		@update:open="(value) => emit('update:open', value)"
	>
		<p :class="$style.app">
			<strong>{{ app }}</strong>
		</p>
		<p :class="$style.transition">
			<span :class="$style.chip">{{ fromVersion || '—' }}</span>
			<span :class="$style.arrow">→</span>
			<span :class="$style.chip">{{ toVersion }}</span>
		</p>
		<p v-if="rangeText" :class="$style.rangeSummary">
			<strong>{{ t('app_versions', 'Downgrade info:') }}</strong> {{ rangeText }}
		</p>
		<p :class="$style.warning">
			{{ t('app_versions', 'Downgrading can break database schema assumptions if migrations were already applied in newer versions. Continue only if you are sure no incompatible schema changes are involved.') }}
		</p>
	</NcDialog>
</template>

<style module>
.app {
	margin-bottom: 0.5rem;
}

.transition {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin: 0.5rem 0;
}

.chip {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	font-family: var(--font-face-monospace);
}

.arrow {
	color: var(--color-text-maxcontrast);
}

.rangeSummary {
	margin: 0.75rem 0;
	color: var(--color-text-maxcontrast);
}

.warning {
	margin-top: 1rem;
	padding: 0.75rem;
	border-radius: var(--border-radius);
	background: var(--color-warning-pale, var(--color-background-hover));
	color: var(--color-warning-text, var(--color-main-text));
	font-size: 0.9rem;
}
</style>
