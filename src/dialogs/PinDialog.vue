<!--
SPDX-License-Identifier: EUPL-1.2
Pin (or re-pin, or "Accept -> move pin") an app to a specific version, with
an optional reason. Owns its own write call so App.vue only needs to open it
and react to the `pinned` event; see "Pin an installed app to its current
version" and "Honest pin presentation".
@spec openspec/specs/version-pinning/spec.md
-->
<script setup lang="ts">
import { ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ocsWrite } from '../ocs'

export type PinRecord = {
	appId: string
	version: string
	pinnedBy: string
	pinnedAt: string
	reason?: string | null
	driftedTo?: string | null
	driftedAt?: string | null
}

const props = defineProps<{
	open: boolean
	appId: string
	version: string
	initialReason?: string | null
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	pinned: [pin: PinRecord]
}>()

const reason = ref('')
const saving = ref(false)
const error = ref('')

watch(() => props.open, (isOpen) => {
	if (isOpen) {
		reason.value = props.initialReason ?? ''
		error.value = ''
	}
})

const confirmPin = async (): Promise<void> => {
	saving.value = true
	error.value = ''
	try {
		const body: Record<string, unknown> = { version: props.version }
		if (reason.value.trim()) {
			body.reason = reason.value.trim()
		}
		const { payload, error: apiError } = await ocsWrite<{ appId: string, pin: PinRecord }>(
			'PUT',
			`/ocs/v2.php/apps/app_versions/api/app/${encodeURIComponent(props.appId)}/pin`,
			body,
		)
		if (apiError) {
			error.value = apiError
			return
		}
		emit('pinned', { ...payload.pin, appId: props.appId })
		emit('update:open', false)
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('app_versions', 'Could not pin this app.')
	} finally {
		saving.value = false
	}
}

const buttons = [
	{
		label: t('app_versions', 'Cancel'),
		type: 'tertiary' as const,
		callback: () => emit('update:open', false),
	},
	{
		label: t('app_versions', 'Pin'),
		type: 'primary' as const,
		callback: confirmPin,
	},
]
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('app_versions', 'Pin {appId} at {version}', { appId, version })"
		:buttons="buttons"
		@update:open="(value: boolean) => emit('update:open', value)">
		<div :class="$style.body">
			<NcNoteCard type="info">
				{{ t('app_versions', 'Pins are enforced inside App Versions and monitored elsewhere — Nextcloud\'s own updater can still update this app. If that happens you will be notified and offered a one-click re-pin.') }}
			</NcNoteCard>
			<NcTextField
				:model-value="reason"
				:label="t('app_versions', 'Reason (optional)')"
				:placeholder="t('app_versions', 'e.g. 2.5.0 breaks LDAP sync')"
				:disabled="saving"
				@update:model-value="(value: string) => (reason = value)" />
			<p v-if="error" :class="$style.error">
				{{ error }}
			</p>
		</div>
	</NcDialog>
</template>

<style module>
.body {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.error {
	margin: 0;
	color: var(--color-error);
	font-size: 13px;
}
</style>
