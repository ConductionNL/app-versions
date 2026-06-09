<script setup lang="ts">
import { ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet, ocsWrite } from '../ocs'

type AppOption = { id: string, label: string }

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
		error.value = e instanceof Error ? e.message : 'Could not load the current binding.'
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
		error.value = 'Select an app first.'
		return
	}
	if (!owner.value.trim() || !repo.value.trim()) {
		error.value = 'Owner and repository are required.'
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
		notice.value = `Bound to ${payload.sourceId}`
		emit('bound', selectedAppId.value)
	} catch (e) {
		error.value = e instanceof Error ? e.message : 'Could not bind the source.'
	} finally {
		loading.value = false
	}
}
</script>

<template>
	<div :class="$style.panel">
		<h3>App sources</h3>
		<p :class="$style.hint">
			Bind an installed app to a GitHub or Codeberg repository so its versions are pulled from
			that forge instead of the App Store. The repository must be on the trusted-sources list.
		</p>

		<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
		<NcNoteCard v-if="notice" type="success">{{ notice }}</NcNoteCard>

		<form :class="$style.form" @submit.prevent="bind">
			<label :class="$style.field">
				<span>App</span>
				<select v-model="selectedAppId">
					<option value="">— choose an app —</option>
					<option v-for="app in props.apps" :key="app.id" :value="app.id">{{ app.label }} ({{ app.id }})</option>
				</select>
			</label>

			<p v-if="currentBinding && currentBinding.sourceId" :class="$style.hint">
				Current source: <code>{{ currentBinding.sourceId }}</code>
			</p>

			<label :class="$style.field">
				<span>Forge</span>
				<select v-model="forge">
					<option value="github">GitHub</option>
					<option value="codeberg">Codeberg</option>
				</select>
			</label>
			<label :class="$style.field">
				<span>Owner</span>
				<input v-model="owner" type="text" placeholder="ConductionNL" />
			</label>
			<label :class="$style.field">
				<span>Repository</span>
				<input v-model="repo" type="text" placeholder="openregister" />
			</label>
			<label :class="$style.field">
				<span>Asset pattern</span>
				<input v-model="assetPattern" type="text" placeholder="*.tar.gz" />
			</label>
			<NcButton native-type="submit" type="primary" :disabled="loading">Bind source</NcButton>
		</form>
	</div>
</template>

<style module>
.panel { display: flex; flex-direction: column; gap: 12px; }
.hint { color: var(--color-text-maxcontrast); font-size: 13px; margin: 0; }
.form { display: flex; flex-direction: column; gap: 8px; max-width: 480px; }
.field { display: flex; flex-direction: column; gap: 2px; font-size: 13px; }
.field input, .field select { padding: 6px 8px; }
</style>
