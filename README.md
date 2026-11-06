# Agent Publishing demo (Symfony 8.1)

A small, runnable site showing [`mattleads/agent-publishing-bundle`](https://github.com/mattleads/agent-publishing-bundle): an AI agent drafts an article through the API, lints it until the report is green, submits it, a human approves it through the API, the site renders it, and the bundle counts who reads it.

The demo app owns only what a site always owns: a controller that renders articles, two templates, and configuration. Everything else comes from the bundle.

## Run it

```bash
composer install
echo "APP_SECRET=$(openssl rand -hex 16)" > .env.local   # API keys are hashed with it; the bundle refuses an empty secret
php bin/console doctrine:schema:create
php bin/console agent-publishing:api-key:create seo-agent --scope=articles:read --scope=articles:draft --section=blog
php bin/console agent-publishing:api-key:create editor --scope=articles:read --scope=articles:approve --scope=articles:publish --scope=stats:read
php -S localhost:8766 -t public public/index.php
```

Then, with the two secrets the commands printed:

```bash
php scripts/agent.php http://localhost:8766 apb_AGENT_SECRET apb_EDITOR_SECRET
```

The script walks through the whole loop and prints what happens at each step: the first lint report with its hints, the rewritten draft, the forbidden publish, the rejection and resubmission, the approval, a scheduled German translation, seven page views from different origins, and the stats the bundle collected. Open **http://localhost:8766** afterwards to see the article, and view the page source for the JSON-LD.

## What to look at

| File | Shows |
|---|---|
| `config/packages/mattleads_agent_publishing.yaml` | sections, locales, per-section lint expectations, SEO identity |
| `config/packages/security.yaml` | the stateless API firewall with the bundle's authenticator and entry point |
| `src/Controller/BlogController.php` | the one route default that makes a page countable: `_agent_publishing_section` |
| `templates/blog/show.html.twig` | `agent_publishing_jsonld(article)` and `agent_publishing_url(article)` |
| `scripts/agent.php` | the agent loop against the REST API, in 150 lines of plain PHP |

Public outputs: `/sitemap-publisher.xml`, `/llms.txt`, `/api/publisher/v1/openapi.json`.

## Notes

- Storage is the bundle's Doctrine mapping on SQLite. Switch `DATABASE_URL` in `.env.local` for anything else.
- Messenger is installed, so each view is dispatched as an `ArticleViewed` message. It is handled synchronously here; route it to an async transport in `config/packages/messenger.yaml` for production.
- Scheduled articles are published by `php bin/console agent-publishing:publish-scheduled`, which you would run from cron.
