# Putting the help centre live

These are the steps that put First Mile Manager's help centre live at help.firstmilemanager.co.uk (help#2). AJ does them, because they need the Forge account, Cloudflare, GitHub settings and secrets. Follow them again to set the site up anywhere else. `docs/deploying.md` explains how each deploy works, and `docs/rebuilding-the-server.md` explains how to rebuild after the server is lost.

The help centre runs on the same London Forge server as Stockist's help centre, but as a **separate site** with its own database and its own Meilisearch index (ADR-0006, fmm#56). It shares nothing else with Stockist's help, and nothing at all with First Mile Manager or Stockist themselves. It is public and indexed from the start: `help.going_public` is already on in `config/help.php`, so you don't need Cloudflare Access.

Do the steps in this order. Each takes minutes. The longest wait is the first deploy's content rebuild.

## 1. GitHub: the read-only key

The checks read First Mile Manager, its marketing site and Stockist's `GLOSSARY.md` from GitHub.

1. In GitHub, go to Settings › Developer settings › Fine-grained tokens and generate a new token named `fmm-help-read`, owned by `gpl-ajackson`.
2. Under repository access, choose only `gpl-ajackson/firstmilemanager`, `gpl-ajackson/firstmilemanager-marketing` and `gpl-ajackson/stockist`. Give it read access to Contents, Issues and Pull requests, and nothing else.
3. In this repository, go to Settings › Secrets and variables › Actions. Add the token as **`FMM_READ_TOKEN`**, and add the same token again as **`STOCKIST_READ_TOKEN`** (the picture tool reads it under that name until help#3).

The next pull request's Launch Checks then run. Launch Check 9 stays red until marketing#2 adds `src/data/site.ts` with the same facts as `content/facts.yaml`.

## 2. GitHub: the issue desk key

"Doesn't match" votes reach Article Owners as issues in this repository.

1. Generate another fine-grained token named `fmm-help-issue-desk`, for `gpl-ajackson/firstmilemanager-help` only, with read and write access to Issues and nothing else.
2. Keep it for step 5 (`HELP_GITHUB_ISSUES_TOKEN`). It goes on the server, not in GitHub's secrets.

## 3. Cloudflare: the address

1. In Cloudflare, open the `firstmilemanager.co.uk` zone. Add an **A** record named `help` that points to the Stockist help server's IP address, with the proxy on (orange cloud).
2. Under SSL/TLS, set the mode to **Full (strict)**. Then create an origin certificate for `help.firstmilemanager.co.uk` (SSL/TLS › Origin Server) and keep its certificate and key for step 4. The server's firewall already accepts web traffic only from Cloudflare (`config/trustedproxy.php`), so this site is covered too.

## 4. Forge: the site

1. On the Stockist help server in Forge, select **New site**. Use domain `help.firstmilemanager.co.uk`, project type Laravel, PHP 8.4, with **zero-downtime deployments** on.
2. Connect it to `gpl-ajackson/firstmilemanager-help` at branch `main`. Leave "Install Composer dependencies" off, because the deploy script installs them.
3. Under the site's Database, create a database **`fmm_help`** with its own user. Don't reuse Stockist's help database or user.
4. Under SSL, install the Cloudflare origin certificate and key from step 3.
5. Paste the whole of `deploy/forge-deploy.sh` into the site's deploy script box (Site › Deployments › Deploy script).
6. Check that Chromium for the `forge` user is at the version `tools/diagrams/package.json` pins (Playwright 1.64.1 today). Stockist's help may have installed a different one. If it doesn't match, run `npx -y playwright@1.64.1 install chromium` as `forge`, as `docs/deploying.md` says under "Chromium and Playwright".

## 5. Forge: the environment

In the site's Environment, start from `.env.example` and set:

- `APP_NAME="First Mile Manager Help"`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://help.firstmilemanager.co.uk`. Generate a new `APP_KEY`: never copy Stockist's.
- `DB_DATABASE=fmm_help`, and the user and password from step 4.
- `MEILISEARCH_HOST=http://127.0.0.1:7700` and `MEILISEARCH_INDEX=fmm_help`. Set `MEILISEARCH_KEY` to the server's Meilisearch key. The help centre touches only indexes named after `fmm_help`, so it never changes Stockist's index. Check that Stockist's help uses a different index name before the first deploy.
- `HELP_GITHUB_ISSUES_TOKEN`, the token from step 2.
- The nightly records backup: create a Cloudflare R2 bucket **`fmm-help-backups`** (EU jurisdiction) with a lifecycle rule that deletes objects after 14 days. Create an R2 token scoped to that bucket only. Then set `HELP_BACKUP_ENDPOINT` (`https://<account>.eu.r2.cloudflarestorage.com`), `HELP_BACKUP_BUCKET=fmm-help-backups`, `HELP_BACKUP_KEY` and `HELP_BACKUP_SECRET`.
- Uptime: in Better Stack, add a monitor for `https://help.firstmilemanager.co.uk/`. Set `HELP_UPTIME_API_TOKEN` and `HELP_UPTIME_MONITOR_IDS` to its id.

## 6. Forge: the scheduler

Under the server's Scheduler, add a job that runs every minute as `forge`:

```sh
php /home/forge/help.firstmilemanager.co.uk/current/artisan schedule:run
```

This is a second job beside Stockist's help, which has its own. Without it, records are never pruned or backed up (`docs/rebuilding-the-server.md` lists the jobs).

## 7. The first deploy

1. Copy the site's deploy hook URL (Site › Deployments) and add it to this repository's secrets as **`HELP_DEPLOY_HOOK_URL`**.
2. In GitHub, go to Actions › Deploy, open the newest run on `main`, and re-run its deploy job. Pushing to `main` deploys from now on, once its checks pass.
3. Watch the deployment in Forge. The script migrates the empty database and runs `help:rebuild`, which builds every Article, Glossary Entry and the search index from `content/`.

## 8. Check it

- `https://help.firstmilemanager.co.uk` shows the First Mile Manager logo and the early access banner. There are no slice 0 Articles yet: they wait until "Staff sign-in and roles" is on the demo (`content/features.yaml`).
- `https://help.firstmilemanager.co.uk/robots.txt` allows everything, and `/sitemap.xml` lists the home page, the Glossary and the Role Paths.
- A page's source has no `noindex` (search results and retired notices aside).
- Search for "sign-in" on the help home: the Sign-in Glossary Entry answers.

## Optional: Help Previews

Each help pull request can get a preview on Cloudflare Pages. To turn it on, create a Pages project (such as `fmm-help-previews`) and an API token that can edit it. Then add `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_ACCOUNT_ID` and `HELP_PREVIEW_PAGES_PROJECT` to this repository's secrets. Without them, the Help Preview job skips.
