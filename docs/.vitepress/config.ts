import { defineConfig } from 'vitepress'

export default defineConfig({
    title: 'Stripe Toolkit · Webhooks',
    description: 'Bulletproof Stripe webhook handling for Laravel.',
    cleanUrls: true,
    base: '/stripe-toolkit-webhooks/',

    head: [
        ['meta', { name: 'theme-color', content: '#38bdf8' }],
    ],

    themeConfig: {
        nav: [
            { text: 'Guide', link: '/installation' },
            { text: 'GitHub', link: 'https://github.com/transistorized-cmd/stripe-toolkit-webhooks' },
        ],

        sidebar: [
            {
                text: 'Getting started',
                items: [
                    { text: 'Installation', link: '/installation' },
                    { text: 'Writing handlers', link: '/handlers' },
                ],
            },
            {
                text: 'Advanced',
                items: [
                    { text: 'Multi-secret · Connect', link: '/multi-secret-connect' },
                    { text: 'Thin events', link: '/thin-events' },
                    { text: 'Debug inspector', link: '/debug-inspector' },
                ],
            },
            {
                text: 'Operations',
                items: [
                    { text: 'Migrating from Spatie', link: '/migrating-from-spatie' },
                    { text: 'Troubleshooting', link: '/troubleshooting' },
                    { text: 'FAQ', link: '/faq' },
                ],
            },
        ],

        socialLinks: [
            { icon: 'github', link: 'https://github.com/transistorized-cmd/stripe-toolkit-webhooks' },
        ],

        footer: {
            message: 'First module of The Complete Stripe Toolkit for Laravel.',
            copyright: 'MIT-licensed.',
        },

        search: { provider: 'local' },
    },
})
