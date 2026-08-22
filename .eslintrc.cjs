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
		// `@spec openspec/...` is a first-class tag in this fleet, not a typo:
		// hydra gate-16 (spec-coverage) REQUIRES it in the docblock immediately
		// above every changed method, and fails the build without it. Left
		// undeclared, jsdoc/check-tag-names warns on the very tag CI demands,
		// which pushes authors to move it out of the docblock — where gate-16
		// then stops seeing it. Declaring it keeps the two tools agreeing.
		'jsdoc/check-tag-names': ['warn', { definedTags: ['spec'] }],
		'vue/first-attribute-linebreak': 'off',
		// `void asyncFn()` is this codebase's established idiom for an
		// intentionally-not-awaited call (see App.vue / *Panel.vue) — it is
		// exactly what the `no-floating-promises`-style convention asks for,
		// so the generic `no-void` rule (which forbids the operator outright)
		// would fight every existing call site rather than catch a real bug.
		'no-void': 'off',
	},
	overrides: [
		{
			// Test files legitimately import devDependencies (vitest, @vue/test-utils).
			files: ['**/*.spec.ts'],
			rules: {
				'n/no-unpublished-import': 'off',
			},
		},
	],
}
