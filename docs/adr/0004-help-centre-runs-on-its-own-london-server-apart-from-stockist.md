# The help centre runs on its own London server, apart from Stockist

> Copied from Stockist's help centre with the app (help#1). It holds here as written, with First Mile Manager in place of Stockist; ADR-0006 says where First Mile Manager's help centre differs.

The help centre is our own small Laravel app on its own small server in London, set up through Forge as Stockist's dev server is. Its database and Meilisearch run on the same server. It shares no server, database, deploy, queue or secret with Stockist (business rule 21), and it never runs on the dev and demo server. The two reach each other only over the web: today not at all, and later through SR2's user sync, SR3's in-app search and SR4's branded-centre details. The help centre reads the main repository's `GLOSSARY.md`, plans and ADRs only when its checks run. `help.firstmilemanager.co.uk` sits in the existing Cloudflare zone, and Cloudflare caches pictures, fonts and styles but never pages, because Page View Counts are counted on the server (#13).

Two reasons drive this. First, the help centre is where people turn when Stockist misbehaves, and later it holds service status (SR5), so a Stockist outage or deploy must never take it down, and a help deploy must never touch the warehouse system (SQ6). Second, keeping Meilisearch on the same server means the words readers type never leave it, which suits rule 11. A future reader might wonder why the help centre has a separate server when the dev server could host one more site, and why it has a server at all when Cloudflare Pages is free. This ADR answers both.

## Considered options

- **Another site on the dev and demo server.** It would be cheapest, but a Stockist deploy, a demo rebuild or a full disk would take the help centre down with it, and the two would share secrets and a database server.
- **Laravel Cloud in London, with Meilisearch's London cloud.** Laravel looks after the servers, and pull request previews come with the Growth plan. But it costs roughly five times as much (about $60 to $80 a month against about $12), and search words would go to a third party before our cleaning applied.
- **A static export on Cloudflare Pages.** It is free and the marketing site already uses it. But search, feedback and counts would have to be written again in TypeScript, and SR2's sign-in and SR4's branded centres need a running Laravel app anyway.
- **Statamic or Prezet instead of our own app.** Statamic brings a control panel nobody here would use, and a licence, and its docs say its multi-site feature is not meant for multi-tenant use (SR4). Prezet's required fields and its SQLite index don't fit our Article details or Laravel's search tools.
- **Caching pages at Cloudflare's edge.** Pages would be faster for distant readers, but cached views never reach the server, so Page View Counts would undercount without a script on the page, and rule 11 rules that script out.

Decided by AJ on 24 October 2026 (support site map #5, ticket #16).
