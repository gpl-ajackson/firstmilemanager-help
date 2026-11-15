<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Going public
    |--------------------------------------------------------------------------
    |
    | The one setting that opens the help centre to everyone and to search engines (spec #30,
    | "HTTP rules"). While it is false every page and robots.txt say "noindex" and the sitemap is
    | empty. It lives here, not in the environment, so that turning it on is one reviewed change
    | in this repository: the going-public pull request, which also adds the first Release Note.
    |
    */

    'going_public' => true,

    /*
    |--------------------------------------------------------------------------
    | Help Preview
    |--------------------------------------------------------------------------
    |
    | Null on the help centre itself. Only `php artisan help:preview` sets it, while it writes a
    | pull request's static Help Preview (App\Preview\HelpPreview, #59): then Draft Articles are
    | shown marked as drafts, search and feedback do nothing, every page says noindex and
    | "Help Preview", and nothing is recorded.
    |
    */

    'preview' => null,

    /*
    |--------------------------------------------------------------------------
    | The help centre's address
    |--------------------------------------------------------------------------
    |
    | Where readers and search engines find the help centre (ticket #9, ADR-0002), as the
    | marketing site's site.helpUrl names it. The sitemap, robots.txt and llms.txt give their
    | addresses in full from here, whatever address a request came in on.
    |
    */

    'address' => 'https://help.firstmilemanager.co.uk',

    /*
    |--------------------------------------------------------------------------
    | Content folder
    |--------------------------------------------------------------------------
    |
    | The folder of Markdown that help:rebuild reads. It is the only source of every Article;
    | the database copy is rebuilt from it on each deploy and never edited by hand.
    | help:rebuild --content=<path> reads another folder (tests and Help Previews).
    |
    */

    'content_path' => base_path('content'),

    /*
    |--------------------------------------------------------------------------
    | Picture tool
    |--------------------------------------------------------------------------
    |
    | pictures_path is the folder the picture tool keeps each Picture and its Picture Recipe in
    | (pictures/<Picture Name>.png and .yaml, ADR-0005); help:rebuild --pictures=<path> reads
    | another. served_pictures_path is where the rebuild leaves the sized Pictures and drawn
    | diagrams the site serves at /pictures/<fingerprint>/<file>; each file's name never changes
    | its contents, so the folder is kept between deploys and rebuilds only add to it.
    |
    */

    'pictures_path' => base_path('pictures'),

    'served_pictures_path' => storage_path('app/pictures'),

    /*
    |--------------------------------------------------------------------------
    | "Still stuck?" address
    |--------------------------------------------------------------------------
    |
    | Where a staff Article's "Still stuck?" sends 3PL staff until SR2's "Contact support"
    | replaces it: Stockist's contact address, as on the marketing site (src/data/site.ts). A
    | Client-tier Article never shows it; clients are sent to their 3PL's account manager.
    |
    */

    'still_stuck_email' => 'hello@firstmilemanager.co.uk',

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | Meilisearch, on the help centre's own server, reached only through App\Search\SearchService
    | (spec #30, "Search"). help:rebuild fills the index named here on every deploy; tests and
    | CI run a real Meilisearch too, because ranking and typo rules are the engine's work. The key
    | is the instance's master key (the server is the only client). Each rebuild builds
    | "<index>_next" and swaps it in, so readers never search a half-built index.
    |
    */

    'search' => [
        'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index' => env('MEILISEARCH_INDEX', 'fmm_help'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Picture tool
    |--------------------------------------------------------------------------
    |
    | The picture tool (App\Pictures, php artisan pictures:take; ADR-0005). Every Picture is
    | taken from a fresh Trentside copy with Stockist's clock held at the Picture Date: a
    | Wednesday at 10:30 UK time, moved once a year in January to the second Wednesday, in one
    | `Help only: yearly Picture Date` change that retakes every Picture. It lives here, not in
    | the environment, so moving it is that one reviewed change.
    |
    | The tool keeps its Stockist checkouts and installs in work_path, and its seeded copies in
    | Postgres databases named database_prefix_<commit>: give each worktree its own prefix.
    |
    */

    'pictures' => [
        'date' => '2026-01-14 10:30 Europe/London',
        'stockist_repository' => env('PICTURES_STOCKIST_REPOSITORY', 'https://github.com/gpl-ajackson/stockist.git'),
        'stockist_ref' => env('PICTURES_STOCKIST_REF', 'main'),
        'work_path' => env('PICTURES_WORK_PATH', storage_path('pictures')),
        'database_prefix' => env('PICTURES_DATABASE_PREFIX', 'pictures'),
        'offline_fonts' => (bool) env('PICTURES_OFFLINE_FONTS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | The main project's read-only key
    |--------------------------------------------------------------------------
    |
    | The Launch Checks' Source References check reads the main project's issues (specs, cited as
    | fmm#n) from GitHub with it: CI's FMM_READ_TOKEN secret, which needs read access
    | to the issues of gpl-ajackson/firstmilemanager. Without it, issue references aren't checked and the
    | run says so.
    |
    */

    'main_project_read_token' => env('FMM_READ_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Content Owner
    |--------------------------------------------------------------------------
    |
    | The GitHub login of the Content Owner (AJ), who keeps the help centre in step with Stockist
    | and is assigned the issues the help centre's checks raise for them, such as the Glossary
    | Check's weekly findings (help:glossary-check --post-to).
    |
    */

    'content_owner' => 'gpl-ajackson',

    /*
    |--------------------------------------------------------------------------
    | Issue desk
    |--------------------------------------------------------------------------
    |
    | The issue desk (App\IssueDesk) opens and comments on issues in this repository, and only
    | here: "doesn't match" votes reach their Article Owners as issues the same day, and the
    | monthly reports are posted as issues. The key is the server's HELP_GITHUB_ISSUES_TOKEN, a
    | fine-grained token with issues read and write on this repository alone (help#2).
    | Without it, votes wait and help:doesnt-match-issues says so in the log.
    |
    */

    'issue_desk' => [
        'repository' => 'gpl-ajackson/firstmilemanager-help',
        'token' => env('HELP_GITHUB_ISSUES_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Uptime monitor
    |--------------------------------------------------------------------------
    |
    | Better Stack Uptime checks the help home and one Article every five minutes and emails AJ
    | when either fails (spec #30, user story 160; ticket #34). help:record-uptime reads each
    | monitor's availability every night and keeps monthly uptime for the Help Gaps Report
    | (App\Records\UptimeMonitor). The token is the server's HELP_UPTIME_API_TOKEN, a Better
    | Stack API token; monitor_ids is HELP_UPTIME_MONITOR_IDS, the monitors' ids separated by
    | commas. Without either, help:record-uptime fails saying so.
    |
    */

    'uptime' => [
        'token' => env('HELP_UPTIME_API_TOKEN'),
        'monitor_ids' => env('HELP_UPTIME_MONITOR_IDS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Access service token
    |--------------------------------------------------------------------------
    |
    | Until going public, Cloudflare Access gates the help centre's real address. Launch Check 11
    | times each page template there too before launch (help:launch-checks --speed-at=<address>,
    | the run for the Launch Check issue), sending this service token so Access lets it through
    | (ticket #33). Without it, the run meets Access's sign-in page and says so.
    |
    */

    'access' => [
        'client_id' => env('CF_ACCESS_CLIENT_ID'),
        'client_secret' => env('CF_ACCESS_CLIENT_SECRET'),
    ],
];
