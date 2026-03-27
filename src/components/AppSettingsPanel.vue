<script setup lang="ts">
const props = defineProps<{
	updateChannel: string
	safeModeEnabled: boolean
	debugModeEnabled: boolean
	disabled: boolean
}>()

const emit = defineEmits<{
	'update:safeModeEnabled': [value: boolean]
	'update:debugModeEnabled': [value: boolean]
}>()
</script>

<template>
	<div :class="$style.settingsPanel">
		<p v-if="props.updateChannel" :class="$style.updateChannel">
			Update channel: <strong>{{ props.updateChannel }}</strong>
		</p>
		<div :class="$style.settingsToggles">
			<label :class="$style.safeMode">
				<input
					type="checkbox"
					:checked="props.safeModeEnabled"
					:class="$style.safeModeCheckbox"
					:disabled="props.disabled"
					@change="emit('update:safeModeEnabled', ($event.target as HTMLInputElement).checked)"
				/>
				<span>Safe mode (block downgrades and respects update channel)</span>
			</label>
			<label :class="$style.safeMode">
				<input
					type="checkbox"
					:checked="props.debugModeEnabled"
					:class="$style.safeModeCheckbox"
					:disabled="props.disabled"
					@change="emit('update:debugModeEnabled', ($event.target as HTMLInputElement).checked)"
				/>
				<span>Enable install dry-run (show debug output)</span>
			</label>
		</div>
	</div>
</template>

<style module src="../styles/AppSettingsPanel.module.css"></style>
