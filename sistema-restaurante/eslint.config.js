import js from '@eslint/js';
import globals from 'globals';

/**
 * Configuración de ESLint (formato «flat», el único que admite ESLint 9+).
 *
 * Solo se revisa `resources/js`, que es el JavaScript que escribimos. El resto
 * —`vendor/`, `node_modules/`, lo que compila Vite en `public/build`— no es
 * nuestro y no tiene sentido señalarlo.
 */
export default [
    {
        ignores: [
            'node_modules/**',
            'vendor/**',
            'public/build/**',
            'public/storage/**',
            'storage/**',
            'bootstrap/cache/**',
        ],
    },

    js.configs.recommended,

    {
        files: ['resources/js/**/*.js'],
        languageOptions: {
            ecmaVersion: 2024,
            sourceType: 'module',
            globals: {
                ...globals.browser,
                // Alpine se carga como global desde app.js y se usa en las
                // plantillas Blade; axios se cuelga de window en bootstrap.js.
                Alpine: 'readonly',
                axios: 'readonly',
            },
        },
        rules: {
            // Un `console.log` olvidado no debe romper el lint, pero sí avisar.
            'no-console': ['warn', { allow: ['warn', 'error'] }],
            'no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
            eqeqeq: ['error', 'smart'],
            'prefer-const': 'error',
            'no-var': 'error',
        },
    },

    {
        // Ficheros de configuración: corren en Node, no en el navegador.
        files: ['*.config.js', 'vite.config.js', 'eslint.config.js'],
        languageOptions: {
            sourceType: 'module',
            globals: globals.node,
        },
    },
];
