# Rebuilding the server

If the help centre's server is lost, a new one is built from this repository within one working day, and at most a day of records is lost (spec #30, user stories 161 and 162; the plan's "Keeping it running"; ADR-0004). Only the records are restored from a backup. Articles need no backup: the deploy makes them again from `content/`.

## What is kept where

- **Content** (Articles, Glossary Entries, Release Notes, Pictures, redirects, Product Areas and facts) is in this repository. Each deploy's content rebuild (`php artisan help:rebuild`) makes the database copy, the rendered pages and diagrams and the search index from it. None of it is backed up, and none of it is restored.
- **Records** are Search Records, Article Feedback, Page View Counts, Still Stuck Counts, the monthly totals and monthly uptime (`App\Records\RecordsBackup::TABLES`). They live only in the server's database. Every night at 04:00 UK time, after the 03:30 pruning, `help:back-up-records` backs them up to the Cloudflare R2 bucket `stockist-help-backups` (EU jurisdiction) as one file, `records/<day>-<hhmm>.jsonl.gz`. It deletes each backup 14 days after it was made, and so does the bucket's own lifecycle rule. A record pruned under rule 11 is therefore gone from every backup within 14 days.
- **Settings** are in Forge, in the server's environment, and in this repository's GitHub secrets. Their names are below and in `docs/deploying.md`, never their values.
- **Uptime history** is also kept by Better Stack. `help:record-uptime` reads last month and this month again every night at 04:30, so the current month's figure comes back the night after a rebuild.

## The scheduler must be on

Every nightly, five-minute and monthly job runs from Laravel's scheduler, which needs Forge's scheduler to run `php artisan schedule:run` every minute, as the `forge` user, in the site's current release folder. Without it, nothing in `routes/console.php` runs: rate-limit keys aren't forgotten, "doesn't match" votes never reach their Article Owners, records aren't pruned and old "doesn't match" issues aren't deleted (which breaks rule 11), there are no backups and no monthly uptime, and no Help Gaps Report is raised. `php artisan schedule:list` shows the jobs and when each runs next:

| Job | When (UK time) |
| --- | --- |
| `help:forget-rate-limits` | every five minutes |
| `help:doesnt-match-issues` | every five minutes |
| `help:prune-records` | 03:30 every night |
| `help:delete-old-doesnt-match-issues` | 03:45 every night |
| `help:back-up-records` | 04:00 every night |
| `help:record-uptime` | 04:30 every night |
| `help:gaps-report --wait-until=16:00` | on the hour from 08:00 to 16:00 on the first working day of the month, raising the month's issue once |

## Rebuilding, step by step

Each step takes minutes of work. The longest waits are while Forge makes the server and while the first deploy runs the content rebuild.

1. **Make the server.** In Forge, make a Laravel VPS in London (about 2 GB) with PHP 8.4 and Postgres. It is the same server as Stockist's help centre (ADR-0006), but never the Stockist or First Mile Manager dev and demo server, and the help centre shares nothing with either app (rule 21, ADR-0004). `docs/putting-it-live.md` adds this site to it.
2. **Install Meilisearch** v1.52.4 on the server with a new master key. It listens only on the server itself, and port 7700 is never open to the internet.
3. **Close the firewall** to everything but Cloudflare. Web traffic is accepted only from the ranges in `config/trustedproxy.php`.
4. **Make the site** `help.firstmilemanager.co.uk` in Forge, from `gpl-ajackson/firstmilemanager-help` at `main`, with zero-downtime deployments. Paste `deploy/forge-deploy.sh` into its deploy script box, and install Chromium for the `forge` user at the version `tools/diagrams/package.json` pins (`docs/deploying.md`, "Chromium and Playwright").
5. **Fill in the environment** from `.env.example` with production values: `APP_ENV=production`, `APP_DEBUG=false`, a new `APP_KEY`, the database Forge made (`DB_*`), `MEILISEARCH_KEY` (the new master key), `HELP_GITHUB_ISSUES_TOKEN`, `HELP_BACKUP_ENDPOINT`, `HELP_BACKUP_BUCKET`, `HELP_BACKUP_KEY`, `HELP_BACKUP_SECRET`, `HELP_UPTIME_API_TOKEN` and `HELP_UPTIME_MONITOR_IDS`. A token that can't be found can be made again in GitHub, Cloudflare R2 or Better Stack. A new `APP_KEY` loses nothing, because the help centre keeps nothing encrypted.
6. **Deploy.** Save the new site's deploy hook as the repository secret `HELP_DEPLOY_HOOK_URL`, then re-run the "Deploy to help.firstmilemanager.co.uk" job of `main`'s newest Deploy run (`docs/deploying.md`, "When a deploy fails"). The deploy migrates the empty database and runs `help:rebuild`, so every Article, Glossary Entry and Release Note is back, and so is the search index.
7. **Restore the records** before any reader can reach the new server, and before the scheduler is on. In Forge's command runner, in the current release folder, run:

   ```sh
   php artisan help:restore-records
   ```

   This restores the newest backup and lists each table's rows. Until the restore is done, keep the scheduler off. Otherwise the 04:00 backup of the empty database becomes the newest backup, and the 04:30 uptime run puts records in the database, which the restore then refuses. To restore an older backup, name it by its UK day and time: `php artisan help:restore-records 2026-11-03-0400`. The command restores all of a backup or none of it. It refuses a damaged backup (restore the one before it) and a database that already holds records, so it can never double them or mix them up. Never run it on a server that readers are using.
8. **Turn on the scheduler.** In Forge, schedule `php artisan schedule:run` every minute as `forge`, as described above.
9. **Point Cloudflare at the new server.** Change the proxied `help.firstmilemanager.co.uk` record in the Cloudflare zone to the new server's address. The Access policy belongs to the address, so it carries on as before.
10. **Check it.**
    - `php artisan schedule:list` lists the five jobs above.
    - `php artisan help:back-up-records` puts a backup in the bucket.
    - `php artisan help:record-uptime` reads uptime from Better Stack.
    - The home page and an Article open through Cloudflare.
    - The Better Stack monitors are up.
    - The server's own address, and port 7700, are refused from outside.

What is lost is everything recorded after the last backup: at most a day of anonymous figures, which the plan accepts.
