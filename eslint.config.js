import js from '@eslint/js';
import prettier from 'eslint-config-prettier';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import typescript from 'typescript-eslint';

/** @type {import('eslint').Linter.Config[]} */
export default [
    js.configs.recommended,
    ...typescript.configs.recommended,
    {
        ...react.configs.flat.recommended,
        ...react.configs.flat['jsx-runtime'], // Required for React 17+
        languageOptions: {
            globals: {
                ...globals.browser,
            },
        },
        rules: {
            'react/react-in-jsx-scope': 'off',
            'react/prop-types': 'off',
            'react/no-unescaped-entities': 'off',
        },
        settings: {
            react: {
                version: 'detect',
            },
        },
    },
    {
        plugins: {
            'react-hooks': reactHooks,
        },
        rules: {
            'react-hooks/rules-of-hooks': 'error',
            'react-hooks/exhaustive-deps': 'warn',
        },
    },
    {
        // MCP Apps widgets (App\\Mcp\\Apps\\Widget): plain browser scripts concatenated into one inline
        // <script> per widget, after laravel/mcp's SDK (createMcpApp) and our core.js (FW).
        files: ['resources/mcp-apps/**/*.js'],
        languageOptions: {
            sourceType: 'script',
            // recipe-scale.js is inlined before recipe.js (Widget::LIBS).
            globals: { ...globals.browser, FW: 'readonly', factorFor: 'readonly', scaleIngredient: 'readonly', formatQty: 'readonly' },
        },
    },
    {
        // `design/` is the vendored Claude Design handoff bundle (generated JS/prompts), not our
        // source; `modules/*/resources/dist` are pre-built module bundles. Neither should be linted.
        // resources/js/lib/homer.js is a verbatim copy of familywell-site's mascot renderer (kept compact so it diffs
        // cleanly against the source): synced, not edited here, so not linted or reformatted.
        ignores: ['vendor', 'node_modules', 'public', 'bootstrap/ssr', 'tailwind.config.js', 'design', 'modules/*/resources/dist', 'resources/js/lib/homer.js'],
    },
    prettier, // Turn off all rules that might conflict with Prettier
];
