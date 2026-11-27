import js from "@eslint/js";
import globals from "globals";

export default [
    js.configs.recommended,
    {
        languageOptions: {
            ecmaVersion: "latest",
            sourceType: "module",
            globals: {
                ...globals.browser,
                // Injected by templates/shop/hosted_fields/index.html.twig and templates/shop/integrated/index.html.twig
                payplug_hosted_fields_params: "readonly",
                payplug_integrated_payment_params: "readonly",
            },
        },
        rules: {
            "no-undef": "error",
            "no-unused-vars": "warn",
            "no-irregular-whitespace": "warn",
        },
    },
    {
        ignores: [
            "vendor/**",
            "node_modules/**",
            "assets/shop/dist/**",
            "public/**",
            "tests/**",
        ],
    },
];
