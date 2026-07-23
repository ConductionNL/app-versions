<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ocsGet, ocsWrite } from '../ocs'

type ExpiryState = 'ok' | 'expiring' | 'expired' | 'unknown'

type Pat = {
	id: number
	label: string
	targetPattern: string
	forge?: string
	kind?: string
	tokenHint?: string
	sharedWithAdmins?: boolean
	expiryState?: ExpiryState
	daysRemaining?: number | null
}
type SelectOption = { id: string, label: string }

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

const forgeOptions: SelectOption[] = [
	{ id: 'github', label: 'GitHub' },
	{ id: 'codeberg', label: 'Codeberg' },
]

const loadPats = async (): Promise<void> => {
	try {
		const { payload } = await ocsGet<{ pats?: Pat[] }>(PATS)
		pats.value = Array.isArray(payload.pats) ? payload.pats : []
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('app_versions', 'Could not load tokens.')
	}
}

/**
 * Badge label for a token's derived expiry state; see "Expiry state in the
 * PAT API and UI" ("Badges reflect state").
 * @param pat
 * @spec openspec/specs/pat-management/spec.md
 */
const expiryBadgeLabel = (pat: Pat): string => {
	if (pat.expiryState === 'expiring') {
		const days = pat.daysRemaining ?? 0
		return t('app_versions', 'Expires in {days} days', { days })
	}
	if (pat.expiryState === 'expired') {
		return t('app_versions', 'Expired')
	}
	if (pat.expiryState === 'unknown') {
		return t('app_versions', 'Expiry unknown')
	}
	return ''
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
		error.value = t('app_versions', 'Label, owner and token are required.')
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
		notice.value = t('app_versions', 'Token added.')
		label.value = ''
		owner.value = ''
		repo.value = ''
		token.value = ''
		await loadPats()
	} catch (e) {
		error.value = e instanceof Error ? e.message : t('app_versions', 'Could not add the token.')
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
		error.value = e instanceof Error ? e.message : t('app_versions', 'Could not update the token.')
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
		error.value = e instanceof Error ? e.message : t('app_versions', 'Could not delete the token.')
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
		error.value = e instanceof Error ? e.message : t('app_versions', 'Could not build the token-creation link.')
	}
}

onMounted(loadPats)
</script>

<template>
	<div :class="$style.panel">
		<h3>{{ t('app_versions', 'Access tokens') }}</h3>
		<p :class="$style.hint">
			{{ t('app_versions', 'Personal access tokens let App Versions read private repositories. Tokens are encrypted at rest and never shown again after creation.') }}
		</p>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-if="notice" type="success">
			{{ notice }}
		</NcNoteCard>

		<ul :class="$style.list">
			<li v-for="pat in pats" :key="pat.id" :class="$style.row">
				<span>
					<strong>{{ pat.label }}</strong>
					<code>{{ pat.forge || 'github' }}:{{ pat.targetPattern }}</code>
					<span
						v-if="pat.expiryState && pat.expiryState !== 'ok'"
						data-testid="expiry-badge"
						:data-expiry-state="pat.expiryState"
						:class="[$style.expiryBadge, {
							[$style.expiryBadgeError]: pat.expiryState === 'expired',
							[$style.expiryBadgeNeutral]: pat.expiryState === 'unknown',
						}]">
						{{ expiryBadgeLabel(pat) }}
					</span>
					<span v-if="pat.tokenHint" :class="$style.hint">…{{ pat.tokenHint }}</span>
				</span>
				<span :class="$style.actions">
					<NcButton type="tertiary" :disabled="loading" @click="toggleShare(pat)">
						{{ pat.sharedWithAdmins ? t('app_versions', 'Unshare') : t('app_versions', 'Share with admins') }}
					</NcButton>
					<NcButton type="tertiary" :disabled="loading" @click="deleteToken(pat)">{{ t('app_versions', 'Delete') }}</NcButton>
				</span>
			</li>
			<li v-if="pats.length === 0" :class="$style.empty">
				{{ t('app_versions', 'No tokens configured.') }}
			</li>
		</ul>

		<form :class="$style.form" @submit.prevent="addToken">
			<NcSelect
				v-model="forge"
				:input-label="t('app_versions', 'Forge')"
				:options="forgeOptions"
				:reduce="(option) => option.id"
				:clearable="false"
				label="label" />
			<NcButton type="secondary" :disabled="loading" @click="fetchDeeplink">
				{{ t('app_versions', 'Create a token on {forge}…', { forge }) }}
			</NcButton>
			<NcNoteCard v-if="deeplink" type="info">
				<a :href="deeplink.url" target="_blank" rel="noopener noreferrer">{{ deeplink.url }}</a>
				<ul>
					<li v-for="(line, i) in deeplink.instructions" :key="i">
						{{ line }}
					</li>
				</ul>
			</NcNoteCard>
			<NcTextField v-model="label" :label="t('app_versions', 'Label')" placeholder="Conduction private repos" />
			<NcTextField v-model="owner" :label="t('app_versions', 'Owner')" placeholder="ConductionNL" />
			<NcTextField v-model="repo" :label="t('app_versions', 'Repository (optional — blank covers the whole owner)')" placeholder="openregister" />
			<NcTextField v-model="token"
				type="password"
				:label="t('app_versions', 'Token')"
				autocomplete="off" />
			<NcButton native-type="submit" type="primary" :disabled="loading">
				{{ t('app_versions', 'Add token') }}
			</NcButton>
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
.expiryBadge {
	display: inline-flex;
	align-items: center;
	padding: 2px 8px;
	border-radius: 9999px;
	background: var(--color-warning, #f0a020);
	color: var(--color-primary-text, #000);
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.02em;
	margin-left: 4px;
}
.expiryBadgeError {
	background: var(--color-error, #d32f2f);
	color: var(--color-primary-text, #fff);
}
.expiryBadgeNeutral {
	background: var(--color-background-darker, #ededed);
	color: var(--color-text-maxcontrast);
}
</style>
