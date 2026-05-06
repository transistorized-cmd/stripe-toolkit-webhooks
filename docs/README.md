# Documentation site

VitePress source for the Stripe Toolkit · Webhooks docs.

## Develop locally

```bash
cd docs
npm install
npm run docs:dev
```

## Build for static hosting

```bash
npm run docs:build
# output in docs/.vitepress/dist/
```

## Deploy

The site is configured for GitHub Pages at the path
`/stripe-toolkit-webhooks/`. Deployment is intentionally not wired up
yet — set up the pipeline (GitHub Actions, Netlify, or whatever) when
the project is ready to go public.
