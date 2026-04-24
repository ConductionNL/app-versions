<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import type { AppOption } from '../types'

const props = defineProps<{
	app: AppOption
	selected: boolean
	disabled: boolean
	loading: boolean
	fallbackIcon: string
	description: string
}>()

defineEmits<{
	(e: 'pick', appId: string): void
}>()
</script>

<template>
	<article
		:class="[$style.card, { [$style.selected]: selected, [$style.core]: app.isCore }]"
	>
		<div :class="$style.body">
			<div :class="$style.header">
				<div :class="$style.titleBlock">
					<div :class="$style.titleRow">
						<p :class="$style.title">{{ app.label }}</p>
						<span v-if="app.isCore" :class="$style.coreFlag">{{ t('app_versions', 'CORE') }}</span>
					</div>
					<p :class="$style.meta">{{ app.id }}</p>
				</div>
				<div :class="$style.media">
					<img
						v-if="app.preview"
						:src="app.preview"
						:alt="`${app.label} icon`"
						:class="$style.icon"
					>
					<div v-else :class="$style.fallback" aria-hidden="true">
						{{ fallbackIcon }}
					</div>
				</div>
			</div>
			<p :class="$style.description">{{ description }}</p>
		</div>
		<button
			v-if="!app.isCore"
			type="button"
			:class="$style.button"
			:disabled="disabled"
			@click="$emit('pick', app.id)"
		>
			{{ selected && loading ? t('app_versions', 'Loading…') : t('app_versions', 'Choose app') }}
		</button>
	</article>
</template>

<style module>
.card {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	padding: 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	transition: border-color 0.15s ease;
}

.card:hover {
	border-color: var(--color-primary-element);
}

.selected {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light);
}

.core {
	opacity: 0.85;
}

.body {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.header {
	display: flex;
	align-items: flex-start;
	gap: 0.75rem;
}

.titleBlock {
	flex: 1;
	min-width: 0;
}

.titleRow {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.title {
	margin: 0;
	font-weight: bold;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.meta {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	font-family: var(--font-face-monospace);
}

.coreFlag {
	padding: 1px 6px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 0.7rem;
	letter-spacing: 0.05em;
}

.media {
	flex-shrink: 0;
	width: 3rem;
	height: 3rem;
	display: flex;
	align-items: center;
	justify-content: center;
}

.icon {
	width: 100%;
	height: 100%;
	object-fit: contain;
}

.fallback {
	width: 100%;
	height: 100%;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-weight: bold;
	font-size: 1.25rem;
}

.description {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	line-height: 1.4;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

.button {
	align-self: flex-end;
	padding: 0.35rem 0.75rem;
	border-radius: var(--border-radius);
	border: 1px solid transparent;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	cursor: pointer;
	font-weight: bold;
}

.button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}
</style>
