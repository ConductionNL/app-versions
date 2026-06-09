<script setup lang="ts">
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet, ocsWrite } from '../ocs'

type Pat = {
	id: number
	label: string
	targetPattern: string
	forge?: string
	kind?: string
	tokenHint?: string
	sharedWithAdmins?: boolean
}

const PATS = '/ocs/v2.php/apps/app_versions/api/pats'

const pats = ref<Pat[]>([])
const forge = ref('github')
const label = ref('')
const owner = ref('')
const repo = ref('')
const token = ref('')
const loading = ref(false)
const error = ref('')
const notice = ref('')
const deeplink = ref<{ url: string, instructions: string[] } | null>(null)

const loadPats = async (): Promise<void> => {
	try {
		const { payload } = await ocsGet<{ pats?: Pat[] }>(PATS)
		pats.value = Array.isArray(payload.pats) ? payload.pats : []
	} catch (e) {
		error.value = e instanceof Error ? e.message : 'Could not load tokens.'
	}
}

const derivedTargetPattern = (): string => {
	const o = owner.value.trim()
	const r = repo.value.trim()
	return r ? `${o}/${r}` : `${o}/*`
}

const addToken = async (): Promise<void> => {
	error.value = ''
	notice.value = ''
	if (!label.value.trim() || !owner.value.trim() || !token.value.trim()) {
		error.value = 'Label, owner and token are required.'
		return
	}
	loading.value = true
	try {
		const { error: apiError } = await ocsWrite<{ pat?: Pat }>('POST', PATS, {
			forge: forge.value,
			label: label.value.trim(),
			targetPattern: derivedTargetPattern(),
			token: token.value.trim(),
		})
		if (apiError) {
			error.value = apiError
			return
		}
		notice.value = 'Token added.'
		label.value = ''
		owner.value = ''
		repo.value = ''
		token.value = ''
		await loadPats()
	} catch (e) {
		error.value = e instanceof Error ? e.message : 'Could not add the token.'
	} finally {
		loading.value = false
	}
}

const toggleShare = async (pat: Pat): Promise<void> => {
	error.value = ''
	loading.value = true
	try {
		const { error: apiError } = await ocsWrite<{ pat?: Pat }>('PATCH', `${PATS}/${pat.id}`, {
			sharedWithAdmins: !pat.sharedWithAdmins,
		})
		if (apiError) {
			error.value = apiError
			return
		}
		await loadPats()
	} catch (e) {
		error.value = e instanceof Error ? e.message : 'Could not update the token.'
	} finally {
		loading.value = false
	}
}

const deleteToken = async (pat: Pat): Promise<void> => {
	error.value = ''
	loading.value = true
	try {
		const { error: apiError } = await ocsWrite('DELETE', `${PATS}/${pat.id}`)
		if (apiError) {
			error.value = apiError
			return
		}
		await loadPats()
	} catch (e) {
		error.value = e instanceof Error ? e.message : 'Could not delete the token.'
	} finally {
		loading.value = false
	}
}

const fetchDeeplink = async (): Promise<void> => {
	error.value = ''
	deeplink.value = null
	try {
		const { payload } = await ocsGet<{ url?: string, instructions?: string[] }>(
			'/ocs/v2.php/apps/app_versions/api/pats/deeplink',
			{ forge: forge.value },
		)
		if (payload.url) {
			deeplink.value = { url: payload.url, instructions: payload.instructions ?? [] }
		}
	} catch (e) {
		error.value = e instanceof Error ? e.message : 'Could not build the token-creation link.'
	}
}

onMounted(loadPats)
</script>

<template>
	<div :class="$style.panel">
		<h3>Access tokens</h3>
		<p :class="$style.hint">
			Personal access tokens let App Versions read private repositories. Tokens are encrypted at
			rest and never shown again after creation.
		</p>

		<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
		<NcNoteCard v-if="notice" type="success">{{ notice }}</NcNoteCard>

		<ul :class="$style.list">
			<li v-for="pat in pats" :key="pat.id" :class="$style.row">
				<span>
					<strong>{{ pat.label }}</strong>
					<code>{{ pat.forge || 'github' }}:{{ pat.targetPattern }}</code>
					<span v-if="pat.tokenHint" :class="$style.hint">…{{ pat.tokenHint }}</span>
				</span>
				<span :class="$style.actions">
					<NcButton type="tertiary" :disabled="loading" @click="toggleShare(pat)">
						{{ pat.sharedWithAdmins ? 'Unshare' : 'Share with admins' }}
					</NcButton>
					<NcButton type="tertiary" :disabled="loading" @click="deleteToken(pat)">Delete</NcButton>
				</span>
			</li>
			<li v-if="pats.length === 0" :class="$style.empty">No tokens configured.</li>
		</ul>

		<form :class="$style.form" @submit.prevent="addToken">
			<label :class="$style.field">
				<span>Forge</span>
				<select v-model="forge">
					<option value="github">GitHub</option>
					<option value="codeberg">Codeberg</option>
				</select>
			</label>
			<NcButton type="secondary" :disabled="loading" @click="fetchDeeplink">Create a token on {{ forge }}…</NcButton>
			<NcNoteCard v-if="deeplink" type="info">
				<a :href="deeplink.url" target="_blank" rel="noopener noreferrer">{{ deeplink.url }}</a>
				<ul>
					<li v-for="(line, i) in deeplink.instructions" :key="i">{{ line }}</li>
				</ul>
			</NcNoteCard>
			<label :class="$style.field">
				<span>Label</span>
				<input v-model="label" type="text" placeholder="Conduction private repos" />
			</label>
			<label :class="$style.field">
				<span>Owner</span>
				<input v-model="owner" type="text" placeholder="ConductionNL" />
			</label>
			<label :class="$style.field">
				<span>Repository (optional — blank covers the whole owner)</span>
				<input v-model="repo" type="text" placeholder="openregister" />
			</label>
			<label :class="$style.field">
				<span>Token</span>
				<input v-model="token" type="password" autocomplete="off" />
			</label>
			<NcButton native-type="submit" type="primary" :disabled="loading">Add token</NcButton>
		</form>
	</div>
</template>

<style module>
.panel { display: flex; flex-direction: column; gap: 12px; }
.hint { color: var(--color-text-maxcontrast); font-size: 13px; margin: 0; }
.list { display: flex; flex-direction: column; gap: 4px; margin: 0; padding: 0; list-style: none; }
.row { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 4px 0; border-bottom: 1px solid var(--color-border); }
.actions { display: flex; gap: 4px; }
.empty { color: var(--color-text-maxcontrast); font-style: italic; }
.form { display: flex; flex-direction: column; gap: 8px; max-width: 480px; margin-top: 8px; }
.field { display: flex; flex-direction: column; gap: 2px; font-size: 13px; }
.field input, .field select { padding: 6px 8px; }
</style>
