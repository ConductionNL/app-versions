<script setup lang="ts">
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet, ocsWrite } from '../ocs'

const SOURCES = '/ocs/v2.php/apps/app_versions/api/sources'
const TRUSTED = '/ocs/v2.php/apps/app_versions/api/trusted-sources'

const patterns = ref<string[]>([])
const forge = ref('github')
const owner = ref('')
const repo = ref('')
const confirmTrust = ref(false)
const loading = ref(false)
const error = ref('')
const notice = ref('')

const loadPatterns = async (): Promise<void> => {
	error.value = ''
	try {
		const { payload } = await ocsGet<{ trustedPatterns?: string[] }>(SOURCES)
		patterns.value = Array.isArray(payload.trustedPatterns) ? payload.trustedPatterns : []
	} catch (e) {
		error.value = e instanceof Error ? e.message : 'Could not load trusted sources.'
	}
}

const addPattern = async (): Promise<void> => {
	error.value = ''
	notice.value = ''
	if (!owner.value.trim()) {
		error.value = 'An owner/organisation is required.'
		return
	}
	if (!confirmTrust.value) {
		error.value = 'Please confirm you trust this source before adding it.'
		return
	}
	loading.value = true
	try {
		const body: Record<string, unknown> = { forge: forge.value, owner: owner.value.trim() }
		if (repo.value.trim()) {
			body.repo = repo.value.trim()
		}
		const { payload, error: apiError } = await ocsWrite<{ trustedPatterns?: string[] }>('POST', TRUSTED, body)
		if (apiError) {
			error.value = apiError
			return
		}
		patterns.value = Array.isArray(payload.trustedPatterns) ? payload.trustedPatterns : patterns.value
		notice.value = `Trusted ${forge.value}:${owner.value.trim()}/${repo.value.trim() || '*'}`
		owner.value = ''
		repo.value = ''
		confirmTrust.value = false
	} catch (e) {
		error.value = e instanceof Error ? e.message : 'Could not add the trusted source.'
	} finally {
		loading.value = false
	}
}

const removePattern = async (pattern: string): Promise<void> => {
	error.value = ''
	notice.value = ''
	loading.value = true
	try {
		const { payload, error: apiError } = await ocsWrite<{ trustedPatterns?: string[] }>(
			'DELETE',
			`${TRUSTED}?pattern=${encodeURIComponent(pattern)}`,
		)
		if (apiError) {
			error.value = apiError
			return
		}
		patterns.value = Array.isArray(payload.trustedPatterns) ? payload.trustedPatterns : patterns.value
	} catch (e) {
		error.value = e instanceof Error ? e.message : 'Could not remove the trusted source.'
	} finally {
		loading.value = false
	}
}

onMounted(loadPatterns)
</script>

<template>
	<div :class="$style.panel">
		<h3>Trusted sources</h3>
		<p :class="$style.hint">
			Only repositories matching these forge-qualified patterns may be installed from external
			sources. Widening this list extends trust — add concrete owners only.
		</p>

		<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
		<NcNoteCard v-if="notice" type="success">{{ notice }}</NcNoteCard>

		<ul :class="$style.list">
			<li v-for="pattern in patterns" :key="pattern" :class="$style.row">
				<code>{{ pattern }}</code>
				<NcButton type="tertiary" :disabled="loading" @click="removePattern(pattern)">Remove</NcButton>
			</li>
			<li v-if="patterns.length === 0" :class="$style.empty">No trusted sources configured.</li>
		</ul>

		<form :class="$style.form" @submit.prevent="addPattern">
			<label :class="$style.field">
				<span>Forge</span>
				<select v-model="forge">
					<option value="github">GitHub</option>
					<option value="codeberg">Codeberg</option>
				</select>
			</label>
			<label :class="$style.field">
				<span>Owner / organisation</span>
				<input v-model="owner" type="text" placeholder="ConductionNL" />
			</label>
			<label :class="$style.field">
				<span>Repository (optional — blank trusts the whole owner)</span>
				<input v-model="repo" type="text" placeholder="openregister" />
			</label>
			<label :class="$style.checkbox">
				<input v-model="confirmTrust" type="checkbox" />
				<span>I trust this source and understand apps will be installed from it.</span>
			</label>
			<NcButton native-type="submit" type="primary" :disabled="loading">Add trusted source</NcButton>
		</form>
	</div>
</template>

<style module>
.panel { display: flex; flex-direction: column; gap: 12px; }
.hint { color: var(--color-text-maxcontrast); font-size: 13px; margin: 0; }
.list { display: flex; flex-direction: column; gap: 4px; margin: 0; padding: 0; list-style: none; }
.row { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 4px 0; border-bottom: 1px solid var(--color-border); }
.empty { color: var(--color-text-maxcontrast); font-style: italic; }
.form { display: flex; flex-direction: column; gap: 8px; max-width: 480px; margin-top: 8px; }
.field { display: flex; flex-direction: column; gap: 2px; font-size: 13px; }
.field input, .field select { padding: 6px 8px; }
.checkbox { display: flex; align-items: flex-start; gap: 8px; font-size: 13px; }
</style>
