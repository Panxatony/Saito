/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

/**
 * ESLint configuration, in the flat format ESLint 9 expects.
 *
 * Replaces `.eslintrc.json`. The rules are the same four the project had; what
 * changed is how they are declared, and one rule that no longer exists:
 * `@typescript-eslint/quotes` was among the formatting rules typescript-eslint
 * dropped in v8, on the grounds that a formatter does that job better than a
 * linter. The core `quotes` rule takes over — it is what the TypeScript variant
 * wrapped anyway, and nothing in this codebase uses the syntax the wrapper
 * existed for.
 */

import js from '@eslint/js';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default tseslint.config(
    // Declaration files are hand-written shims for packages that ship no types
    // of their own; linting them reports on other people's API shapes.
    { ignores: ['**/*.d.ts'] },

    js.configs.recommended,
    ...tseslint.configs.recommended,

    {
        files: ['frontend/src/**/*.ts'],
        languageOptions: {
            ecmaVersion: 2019,
            sourceType: 'module',
            globals: globals.browser,
        },
        rules: {
            quotes: ['error', 'single', { avoidEscape: true }],
            'max-classes-per-file': ['error', 5],
            // Leading underscore means "deliberately unused" — a parameter kept
            // for its position in a signature, most often.
            '@typescript-eslint/no-unused-vars': [
                'warn',
                { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
            ],
        },
    },
);
