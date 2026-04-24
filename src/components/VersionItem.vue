<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'

const props = defineProps<{
	version: string
	selected: boolean
	isInstallingVersion: boolean
	/** One of 'Install' | 'Update' | 'Degrade' | '' — used both for variant styling and button label key. */
	changeActionKey: 'Install' | 'Update' | 'Degrade' | ''
	/** Already-translated button label corresponding to changeActionKey. */
	changeActionLabel: string
}>()

const emit = defineEmits<{
	(e: 'select', version: string): void
	(e: 'deselect'): void
	(e: 'install'): void
}>()

const showActions = computed(() => props.selected)
const showDegradeWarning = computed(() => props.selected && props.changeActionKey === 'Degrade')
</script>

<template>
	<li :class="$style.item">
		<div :class="$style.main">
			<span>{{ version }}</span>
			<button
				v-if="!selected"
				type="button"
				:class="$style.selectButton"
				:disabled="isInstallingVersion"
				@click="emit('select', version)"
			>
				{{ t('app_versions', 'Select') }}
			</button>
			<span v-else :class="$style.selectedFlag">
				{{ t('app_versions', 'Selected') }}
			</span>
		</div>
		<div v-if="showActions" :class="$style.actionGroup">
			<p v-if="showDegradeWarning" :class="$style.degradeWarning">
				{{ t('app_versions', 'Warning! Downgrading can result in breaking the database if earlier updates or migrations added database columns. Only do this when you can fix the database or are sure no migrations have been executed since the version you downgrade to!') }}
			</p>
			<div :class="$style.actions">
				<button
					v-if="changeActionKey"
					type="button"
					:class="[
						$style.actionButton,
						changeActionKey === 'Update' ? $style.actionButtonUpdate : '',
						changeActionKey === 'Degrade' ? $style.actionButtonDegrade : '',
					]"
					:aria-busy="isInstallingVersion"
					:disabled="isInstallingVersion"
					@click="emit('install')"
				>
					<span v-if="isInstallingVersion" :class="$style.spinner" aria-hidden="true" />
					{{ isInstallingVersion ? t('app_versions', 'Installing…') : changeActionLabel }}
				</button>
				<button
					type="button"
					:class="$style.deselectButton"
					:disabled="isInstallingVersion"
					@click="emit('deselect')"
				>
					{{ t('app_versions', 'Pick other') }}
				</button>
			</div>
		</div>
	</li>
</template>

<style module>
.item {
	padding: 0.5rem 0.75rem;
	border-bottom: 1px solid var(--color-border-dark);
}

.main {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 0.5rem;
}

.selectButton {
	padding: 0.25rem 0.75rem;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	background: transparent;
	cursor: pointer;
}

.selectButton:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.selectedFlag {
	padding: 0.25rem 0.75rem;
	border-radius: var(--border-radius);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text-dark, var(--color-main-text));
	font-weight: bold;
}

.actionGroup {
	margin-top: 0.5rem;
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.degradeWarning {
	margin: 0;
	padding: 0.5rem;
	border-radius: var(--border-radius);
	background: var(--color-warning-pale, var(--color-background-hover));
	color: var(--color-warning-text, var(--color-main-text));
	font-size: 0.85rem;
}

.actions {
	display: flex;
	gap: 0.5rem;
}

.actionButton {
	padding: 0.35rem 0.75rem;
	border-radius: var(--border-radius);
	border: 1px solid transparent;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	cursor: pointer;
	font-weight: bold;
	display: inline-flex;
	align-items: center;
	gap: 0.4rem;
}

.actionButtonUpdate {
	background: var(--color-success, var(--color-primary-element));
}

.actionButtonDegrade {
	background: var(--color-error, #d73a4a);
}

.actionButton:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.deselectButton {
	padding: 0.35rem 0.75rem;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	background: transparent;
	cursor: pointer;
}

.deselectButton:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.spinner {
	display: inline-block;
	width: 0.85rem;
	height: 0.85rem;
	border: 2px solid currentColor;
	border-right-color: transparent;
	border-radius: 50%;
	animation: spin 0.75s linear infinite;
}

@keyframes spin {
	to { transform: rotate(360deg); }
}
</style>
