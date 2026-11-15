# The Next Release folder

Change Summaries wait here for the next FMM Release's Release Note (ADR-0003). This file is the folder's guide, not a Change Summary.

## Adding a Change Summary

If your change is news to a reader, your Help Proposal adds one file here: one or two plain sentences on what a reader can now do or will notice. Name the file after the change, such as `fmm-61-operators-and-sign-up.md`. A change with "No help changes", an Article edit that isn't news, and a help-only fix have none.

```markdown
---
product_area: People
team_roles: [Admin, Transport Manager]
affects_clients: false
articles: [invite-people-to-first-mile-manager]
---

You can now invite a Driver by their mobile number. They get a text message with a link to sign in.
```

- `product_area`: the Product Area, named exactly as the app's menu names it.
- `team_roles`: the Team Roles it affects.
- `affects_clients`: `true` when it changes what clients see. The Release Note marks it "Affects your clients", so Account Managers know what to tell them. Say what clients will notice in the sentences.
- `articles` (optional): the Articles it changed, by Article Name. The Release Note links them.

Write "You can now…" or what the reader will notice. Use `GLOSSARY.md` terms, and leave out ticket numbers, internal terms and code words. Mention a fix only when a reader would have noticed the problem. You may link other help by name (`[words](article:<name>)`, `term:<term>`).

The rebuild checks every Change Summary, so yours is checked on your pull request, but readers see it only once it is in a Release Note.

## At an FMM Release

Before promoting the main project to `demo`, run the Release Check workflow (Actions › Release Check › Run workflow, with the version being released), or locally:

```sh
php artisan help:release-check --main-project=<a firstmilemanager checkout at the version being released, with origin/demo> --date=<the release's date>
```

It lists every pull request merged since the last FMM Release with its help status, shows the Release Note this folder would make, and runs the Launch Checks with every Picture retaken against that version. Release only when it says help is complete for this release.

Then the Content Owner runs:

```sh
php artisan help:release-note --date=<the release's date, YYYY-MM-DD>
```

It writes `content/release-notes/<date>.md`, with the Change Summaries grouped by Product Area, and removes them from this folder. Reword the Release Note's summary if you like, then commit the Release Note and the removals together, so nothing is announced twice. With no Change Summaries waiting, it says there is nothing to release and writes nothing.
