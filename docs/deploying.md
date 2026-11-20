# How a deploy works

Merged means live: a merge to `main` that passes every automated check goes live at https://help.firstmilemanager.co.uk straight away (spec #30, user stories 129 to 133; ADR-0003 and ADR-0004). Nobody changes the live site by hand.

## What starts a deploy

Every push to `main` (in practice, every merged pull request) starts the **Deploy** workflow, `.github/workflows/deploy.yml`. It runs the checks once, and only if every one of them passes does it ask Forge to deploy that commit, through Forge's deploy hook. If any check fails, nothing is deployed and the live site stays as it was.

Deploys go one at a time and never go backwards. If another merge reaches `main` while a commit is still being checked, the older commit isn't deployed: the newer one's run deploys it, with the older change in it, once its own checks pass. That run shows the notice "Not deployed: main has moved on". If the newer commit fails its checks, neither goes live until a fix is merged.

## The checks

The Deploy workflow runs the same two workflows a pull request runs:

- **CI** (`ci.yml`): code style (Pint), static analysis (PHPStan), the content rebuild over `content/`, and the tests (Pest, on Postgres and Meilisearch).
- **Launch Checks** (`launch-checks.yml`): every automated Launch Check over `content/`, against the main project at `main`, including the page checks in headless Chromium. On `main` there is no pull request to comment on, so the findings go to the run's summary instead.

On `main`, these two run only inside the Deploy workflow, so a merge is checked once, not twice. The picture tool's own tests (`picture-tool.yml`) still run apart, only when the tool changes; they test the tool that takes Pictures in CI, which the help centre's server never runs.

## What the deploy runs

The hook starts a deploy on the help centre's Forge server, which runs the deploy script kept in this repository at `deploy/forge-deploy.sh`. The workflow doesn't wait for it to finish. The script:

1. Clones the commit that passed its checks into a new release folder (Forge's zero-downtime deployments). Readers keep using the old release.
2. Installs the PHP packages and the diagram tool (`tools/diagrams`).
3. Caches the configuration, routes, views and the files' fingerprints (`php artisan optimize`).
4. Migrates the database.
5. Runs the content rebuild (`php artisan help:rebuild`): the database copy of every Article, Glossary Entry and Release Note, the rendered pages, the Pictures and diagrams, and the search index with its synonyms. It does all of this or none of it.
6. Switches readers to the new release.

Forge doesn't read `deploy/forge-deploy.sh` itself: the script is pasted into the site's deploy script box in Forge. When a pull request changes the file, AJ pastes the new version into Forge before merging it.

## When a deploy fails

If any step fails, the script stops before step 6, so the old release keeps serving readers. Forge emails AJ about the failed deployment. The help centre never shows readers a half-deployed site.

To try again after fixing something on the server (a full disk, say), open that commit's Deploy run in GitHub Actions and re-run its "Deploy to help.firstmilemanager.co.uk" job on its own; the checks aren't run again, and it deploys the same commit if it is still `main`'s newest. Forge's own "Deploy now" button deploys whatever `main` holds, checked or not, so use it only when `main`'s newest commit has passed its checks.

If the Deploy workflow's own deploy job fails (for example, the hook secret is missing or Forge didn't answer), nothing was deployed. The job's error says why.

## Taking a change back

Merge a revert. On GitHub, open the merged pull request and choose **Revert**, then merge the pull request that makes. It goes through the same checks and deploys like any other change. Never edit files, the database or the search index on the live server: the next deploy would overwrite the change, and the repository would no longer say what is live.

## Pages and Cloudflare's cache

Cloudflare keeps styles, fonts, logos, the script and Pictures for a year (`Cache-Control: public, max-age=31536000, immutable`). Their addresses carry a fingerprint, so a deploy that changes one gives it a new address. Every other response, pages, redirects, pages not found and retired notices included, says `Cache-Control: no-cache, private` and `CDN-Cache-Control: no-store` (`App\Site\Http\Middleware\PagesNotCached`), so Cloudflare never keeps a page. That keeps Page View Counts true, because every view reaches the server, and makes a deploy visible at once. Don't add a Cloudflare cache rule that caches pages.

## Chromium and Playwright

The content rebuild draws diagrams in headless Chromium through Playwright, pinned in `tools/diagrams/package.json` (Playwright 1.64.1 today). On the server, Chromium is installed once for the `forge` user, at that same version:

```sh
npx -y playwright@1.64.1 install chromium
```

(and `npx -y playwright@1.64.1 install-deps chromium` as root). When a pull request changes the Playwright version in `tools/diagrams/package.json`, install the matching Chromium on the server the same way before merging it, or the deploy's content rebuild fails and the old release keeps serving.

## Going public

Each deploy keeps the help centre behind Cloudflare Access and marked "noindex" until going public is switched on in `config/help.php` (`help.going_public`). Going public is its own reviewed change, not part of a deploy.

Before it, the run for the Launch Check issue checks main at the real address: in GitHub, Actions › Launch Checks › Run workflow, on main. It runs every Launch Check with `--at-launch`, times each page template at `https://help.firstmilemanager.co.uk` through Cloudflare Access, checks the address's `llms.txt`, sitemap, redirects and page not found, and retakes every Picture. Its summary is the evidence the Launch Check issue links.

## The scheduler

The jobs in `routes/console.php` (forgetting rate-limit keys, sending "doesn't match" votes, the nightly pruning, backup and uptime reading, the monthly Help Gaps Report) run only while Forge's scheduler runs `php artisan schedule:run` every minute as the `forge` user. A deploy doesn't change it, and it must stay on. `docs/rebuilding-the-server.md` lists the jobs.

## If the server is lost

`docs/rebuilding-the-server.md` says how to build a new server from this repository within a working day. The deploy brings back every Article, and `php artisan help:restore-records` brings back the records from the nightly backup.

## Secrets

Names only; the values are kept in GitHub and Forge, never in the repository, issues or logs.

- GitHub repository secrets: `HELP_DEPLOY_HOOK_URL` (Forge's deploy hook, used only by the Deploy workflow) `FMM_READ_TOKEN` (the checks read the main project, the marketing site and Stockist's `GLOSSARY.md` with it), `STOCKIST_READ_TOKEN` (the picture tool, until help#3), and `CF_ACCESS_CLIENT_ID` and `CF_ACCESS_CLIENT_SECRET` (the Access service token `help-centre-ci`, used only by the run for the Launch Check issue).
- The server's environment in Forge: `MEILISEARCH_KEY`, `HELP_GITHUB_ISSUES_TOKEN`, the backup store's `HELP_BACKUP_ENDPOINT`, `HELP_BACKUP_BUCKET`, `HELP_BACKUP_KEY` and `HELP_BACKUP_SECRET`, and Better Stack's `HELP_UPTIME_API_TOKEN` and `HELP_UPTIME_MONITOR_IDS`, with the rest of the site's settings.
