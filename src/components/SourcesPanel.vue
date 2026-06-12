<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ocsGet, ocsWrite } from '../ocs'

type AppOption = { id: string, label: string }
type SelectOption = { id: string, label: string }

const props = defineProps<{ apps: AppOption[] }>()
const emit = defineEmits<{ (e: 'bound', appId: string): void }>()

const selectedAppId = ref('')
const currentBinding = ref<{ sourceId?: string } | null>(null)
const forge = ref('github')
const owner = ref('')
const repo = ref('')
const assetPattern = ref('*.tar.gz')
const loading = ref(false)
const error = ref('')
const notice = ref('')

const appOptions = computed<SelectOption[]>(() => props.apps.map((app) => ({ id: app.id, label: `${app.label} (${app.id})` })))
const forgeOptions: SelectOption[] = [
	{ id: 'github', label: 'GitHub' },
	{ id: 'codeberg', label: 'Codeberg' },
]

const loadBinding = async (appId: string): Promise<void> => {
	currentBinding.value = null
	if (!appId) {
		return
	}
	try {
		const { payload } = await ocsGet<{ sourceId?: string, binding?: unknown }>(
			`/ocs/v2.php/apps/app_versions/api/source/${encodeURIComponent(appId)}/binding`,
		)
		currentBinding.value = { sourceId: payload.sourceId }
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('app_versions', 'Could not load the current binding.')
	}
}

watch(selectedAppId, (appId) => {
	error.value = ''
	notice.value = ''
	void loadBinding(appId)
})

const bind = async (): Promise<void> => {
	error.value = ''
	notice.value = ''
	if (!selectedAppId.value) {
		error.value = t('app_versions', 'Select an app first.')
		return
	}
	if (!owner.value.trim() || !repo.value.trim()) {
		error.value = t('app_versions', 'Owner and repository are required.')
		return
	}
	loading.value = true
	try {
		const { payload, error: apiError } = await ocsWrite<{ sourceId?: string }>(
			'POST',
			`/ocs/v2.php/apps/app_versions/api/source/${encodeURIComponent(selectedAppId.value)}/bind`,
			{
				kind: 'github-release',
				forge: forge.value,
				owner: owner.value.trim(),
				repo: repo.value.trim(),
				assetPattern: assetPattern.value.trim() || '*.tar.gz',
			},
		)
		if (apiError) {
			error.value = apiError
			return
		}
		currentBinding.value = { sourceId: payload.sourceId }
		notice.value = t('app_versions', 'Bound to {source}', { source: payload.sourceId ?? '' })
		emit('bound', selectedAppId.value)
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('app_versions', 'Could not bind the source.')
	} finally {
		loading.value = false
	}
}
</script>

<template>
	<div :class="$style.panel">
		<h3>{{ t('app_versions', 'App sources') }}</h3>
		<p :class="$style.hint">
			{{ t('app_versions', 'Bind an installed app to a GitHub or Codeberg repository so its versions are pulled from that forge instead of the App Store. The repository must be on the trusted-sources list.') }}
		</p>

		<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
		<NcNoteCard v-if="notice" type="success">{{ notice }}</NcNoteCard>

		<form :class="$style.form" @submit.prevent="bind">
			<NcSelect
				v-model="selectedAppId"
				:input-label="t('app_versions', 'App')"
				:options="appOptions"
				:reduce="(option) => option.id"
				:placeholder="t('app_versions', 'Choose an app')"
				label="label" />

			<p v-if="currentBinding && currentBinding.sourceId" :class="$style.hint">
				{{ t('app_versions', 'Current source:') }} <code>{{ currentBinding.sourceId }}</code>
			</p>

			<NcSelect
				v-model="forge"
				:input-label="t('app_versions', 'Forge')"
				:options="forgeOptions"
				:reduce="(option) => option.id"
				:clearable="false"
				label="label" />
			<NcTextField v-model="owner" :label="t('app_versions', 'Owner')" placeholder="ConductionNL" />
			<NcTextField v-model="repo" :label="t('app_versions', 'Repository')" placeholder="openregister" />
			<NcTextField v-model="assetPattern" :label="t('app_versions', 'Asset pattern')" placeholder="*.tar.gz" />
			<NcButton native-type="submit" type="primary" :disabled="loading">{{ t('app_versions', 'Bind source') }}</NcButton>
		</form>
	</div>
</template>

<style module>
.panel { display: flex; flex-direction: column; gap: 12px; }
.hint { color: var(--color-text-maxcontrast); font-size: 13px; margin: 0; }
.form { display: flex; flex-direction: column; gap: 8px; max-width: 480px; }
</style>
