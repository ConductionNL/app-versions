<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ocsGet, ocsWrite } from '../ocs'

type SelectOption = { id: string, label: string }

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

const forgeOptions: SelectOption[] = [
	{ id: 'github', label: 'GitHub' },
	{ id: 'codeberg', label: 'Codeberg' },
]

const loadPatterns = async (): Promise<void> => {
	error.value = ''
	try {
		const { payload } = await ocsGet<{ trustedPatterns?: string[] }>(SOURCES)
		patterns.value = Array.isArray(payload.trustedPatterns) ? payload.trustedPatterns : []
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('app_versions', 'Could not load trusted sources.')
	}
}

const addPattern = async (): Promise<void> => {
	error.value = ''
	notice.value = ''
	if (!owner.value.trim()) {
		error.value = t('app_versions', 'An owner/organisation is required.')
		return
	}
	if (!confirmTrust.value) {
		error.value = t('app_versions', 'Please confirm you trust this source before adding it.')
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
		notice.value = t('app_versions', 'Trusted {pattern}', { pattern: `${forge.value}:${owner.value.trim()}/${repo.value.trim() || '*'}` })
		owner.value = ''
		repo.value = ''
		confirmTrust.value = false
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('app_versions', 'Could not add the trusted source.')
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
		error.value = e instanceof Error ? e.message : t('app_versions', 'Could not remove the trusted source.')
	} finally {
		loading.value = false
	}
}

onMounted(loadPatterns)
</script>

<template>
	<div :class="$style.panel">
		<h3>{{ t('app_versions', 'Trusted sources') }}</h3>
		<p :class="$style.hint">
			{{ t('app_versions', 'Only repositories matching these forge-qualified patterns may be installed from external sources. Widening this list extends trust — add concrete owners only.') }}
		</p>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-if="notice" type="success">
			{{ notice }}
		</NcNoteCard>

		<ul :class="$style.list">
			<li v-for="pattern in patterns" :key="pattern" :class="$style.row">
				<code>{{ pattern }}</code>
				<NcButton type="tertiary" :disabled="loading" @click="removePattern(pattern)">
					{{ t('app_versions', 'Remove') }}
				</NcButton>
			</li>
			<li v-if="patterns.length === 0" :class="$style.empty">
				{{ t('app_versions', 'No trusted sources configured.') }}
			</li>
		</ul>

		<form :class="$style.form" @submit.prevent="addPattern">
			<NcSelect
				v-model="forge"
				:input-label="t('app_versions', 'Forge')"
				:options="forgeOptions"
				:reduce="(option) => option.id"
				:clearable="false"
				label="label" />
			<NcTextField v-model="owner" :label="t('app_versions', 'Owner / organisation')" placeholder="ConductionNL" />
			<NcTextField v-model="repo" :label="t('app_versions', 'Repository (optional — blank trusts the whole owner)')" placeholder="openregister" />
			<NcCheckboxRadioSwitch v-model="confirmTrust">
				{{ t('app_versions', 'I trust this source and understand apps will be installed from it.') }}
			</NcCheckboxRadioSwitch>
			<NcButton variant="primary" type="submit" :disabled="loading">
				{{ t('app_versions', 'Add trusted source') }}
			</NcButton>
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
</style>
