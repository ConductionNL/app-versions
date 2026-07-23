<!--
SPDX-License-Identifier: EUPL-1.2
Shown when the install endpoint refuses to overwrite a pinned app (HTTP 409).
Offers Re-pin (move the pin to the new version and install), Unpin (drop the
pin and install), or Cancel. The actual retry-with-override install call is
performed by the caller (App.vue already owns the full install flow); this
dialog only decides which override to send.
@spec openspec/specs/version-pinning/spec.md
-->
<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'

defineProps<{
	open: boolean
	appId: string
	pinnedVersion: string
	targetVersion: string
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	resolve: [choice: 'repin' | 'unpin' | 'cancel']
}>()

const choose = (choice: 'repin' | 'unpin' | 'cancel'): void => {
	emit('update:open', false)
	emit('resolve', choice)
}

const buttons = [
	{
		label: t('app_versions', 'Cancel'),
		type: 'tertiary' as const,
		callback: () => choose('cancel'),
	},
	{
		label: t('app_versions', 'Unpin and install'),
		type: 'secondary' as const,
		callback: () => choose('unpin'),
	},
	{
		label: t('app_versions', 'Move pin and install'),
		type: 'primary' as const,
		callback: () => choose('repin'),
	},
]
</script>

<template>
	<NcDialog
		:open="open"
		:name="t('app_versions', '{appId} is pinned', { appId })"
		:buttons="buttons"
		@update:open="(value: boolean) => { if (!value) { choose('cancel') } }">
		<p :class="$style.text">
			{{ t('app_versions', '{appId} is pinned to version {pinnedVersion}. App Versions will not overwrite a pin without an explicit choice.', { appId, pinnedVersion }) }}
		</p>
		<p :class="$style.text">
			{{ t('app_versions', 'Move pin and install will install {targetVersion} and move the pin there. Unpin and install will remove the pin entirely before installing.', { targetVersion }) }}
		</p>
	</NcDialog>
</template>

<style module>
.text {
	font-size: 13px;
	line-height: 1.4;
}
</style>
