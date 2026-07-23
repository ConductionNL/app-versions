// This app is Vue 3 + TypeScript throughout (`<script setup lang="ts">`), so
// it must extend the `vue3` preset — the plain `@nextcloud` preset uses the
// JS-only Vue parser and cannot parse TS-only constructs (e.g. `type X = {}`)
// inside a `.vue` file's script block.
module.exports = {
	extends: [
		'@nextcloud/eslint-config/vue3',
	],
	rules: {
		'jsdoc/require-jsdoc': 'off',
		'vue/first-attribute-linebreak': 'off',
		// `void asyncFn()` is this codebase's established idiom for an
		// intentionally-not-awaited call (see App.vue / *Panel.vue) — it is
		// exactly what the `no-floating-promises`-style convention asks for,
		// so the generic `no-void` rule (which forbids the operator outright)
		// would fight every existing call site rather than catch a real bug.
		'no-void': 'off',
	},
}
