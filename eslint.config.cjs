const {
    defineConfig,
    globalIgnores
} = require('eslint/config')

const globals = require('globals')
const js = require('@eslint/js')
const { FlatCompat } = require('@eslint/eslintrc')
const vue = require('eslint-plugin-vue')
const importPlugin = require('eslint-plugin-import')
const prettyImports = require('eslint-plugin-pretty-imports')
const sonarjs = require('eslint-plugin-sonarjs')
const optimizeRegex = require('eslint-plugin-optimize-regex')
const typescriptEslint = require('@typescript-eslint/eslint-plugin')
const tsParser = require('@typescript-eslint/parser')

const compat = new FlatCompat({
    baseDirectory: __dirname,
    recommendedConfig: js.configs.recommended,
    allConfig: js.configs.all
})

const standardConfig = compat.extends('standard').map((config) => ({
    ...config,
    files: config.files ?? ['**/*.{js,mjs,cjs}']
}))

module.exports = defineConfig([
    globalIgnores([
        '**/node_modules',
        '**/vendor',
        '**/dist',
        '**/build',
        '**/coverage',
        '**/public',
        '**/.github',
        'resources/js/ziggy.js',
        'resources/js/routes.js'
    ]),
    ...standardConfig,
    ...vue.configs['flat/recommended'],
    ...compat.extends('plugin:sonarjs/recommended-legacy'),
    {
        languageOptions: {
            globals: {
                ...globals.browser
            },
            ecmaVersion: 12,
            sourceType: 'module'
        },
        plugins: {
            import: importPlugin,
            'pretty-imports': prettyImports,
            sonarjs,
            'optimize-regex': optimizeRegex,
            '@typescript-eslint': typescriptEslint
        },
        rules: {
            'vue/component-name-in-template-casing': ['error', 'PascalCase'],
            indent: ['error', 4]
        }
    },
    {
        files: ['**/*.vue'],
        languageOptions: {
            parserOptions: {
                parser: tsParser
            }
        }
    },
    {
        files: ['**/*.js', '**/*.vue'],
        rules: {
            'no-return-assign': 'off',
            'vue/no-v-html': 'off',
            'vue/prop-name-casing': 'off',
            'import/no-duplicates': ['error', { considerQueryString: true }],
            'vue/require-prop-type-constructor': 'off',
            'vue/no-v-text-v-html-on-component': 'off',
            'prefer-promise-reject-errors': 'off',
            'sonarjs/no-duplicate-string': 'off',
            'sonarjs/no-unused-vars': 'off',
            'sonarjs/no-nested-conditional': 'off',
            'sonarjs/no-redundant-optional': 'off',
            'sonarjs/super-linear-regex': 'off',
            'sonarjs/use-type-alias': 'off',
            'sonarjs/pseudo-random': 'off',
            'sonarjs/redundant-type-aliases': 'off',
            'vue/multi-word-component-names': 'off',
            'vue/no-setup-props-destructure': 'off',
            'pretty-imports/sorted': 'warn',
            'no-duplicate-imports': 'error',
            'vue/require-default-prop': 'off',
            'vue/no-use-v-if-with-v-for': 'error',
            'vue/script-indent': ['error', 4],
            'vue/html-indent': ['error', 4],
            indent: ['error', 4],
            'no-tabs': 'off',
            'vue/html-closing-bracket-newline': 'off',
            'no-mixed-spaces-and-tabs': 'error'
        }
    }
])
