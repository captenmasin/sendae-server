module.exports = {
    ignorePatterns: [
        'node_modules',
        'vendor',
        'dist',
        'build',
        'coverage',
        'public',
        '.github',
        'resources/js/ziggy.js',
        'resources/js/routes.js'
    ],
    env: {
        browser: true,
        es2021: true
    },
    extends: [
        'standard',
        'plugin:vue/vue3-recommended',
        'plugin:sonarjs/recommended-legacy'
    ],
    parserOptions: {
        ecmaVersion: 12,
        sourceType: 'module',
        parser: '@typescript-eslint/parser'
    },
    plugins: [
        'vue', 'pretty-imports', 'sonarjs', 'optimize-regex', '@typescript-eslint'
    ],
    rules: {
        'vue/component-name-in-template-casing': ['error', 'PascalCase'],
        indent: ['error', 4]
    },
    overrides: [{
        files: ['*.js', '*.vue'],
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
    }]
}
