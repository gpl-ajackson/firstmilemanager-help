# First Mile Manager Help Centre

Terms for the help centre and, later, the request desk. Every warehouse, client and role term comes from the main repository's `GLOSSARY.md` (in `gpl-ajackson/firstmilemanager`) and is not repeated here. This help centre began as a copy of Stockist's, so its terms match that one's.

## Language

### Articles

**Article**:
A help page we write about one task, idea, message or question, for one Audience Tier. Release Notes and Glossary Entries are not Articles.
_Avoid_: Doc, page, help topic, knowledge base entry

**Article Name**:
The permanent name in an Article's address (`/articles/<name>`), chosen when it is first published and never changed or reused, whatever happens to its title or section.
_Avoid_: Slug, URL, path, article ID

**Section Name**:
A permanent name a writer gives to a part of an Article that something links to, kept when the heading above it is reworded. "Section" always means a part of an Article, never a part of the site.
_Avoid_: Anchor, heading link, fragment

**Article Type**:
The kind of Article, which sets the template its writer follows: Getting-started, How-to, Concept, Reference, Troubleshooting (one situation, quoting word for word every First Mile Manager message it explains) or FAQ (one question per Article).
_Avoid_: Category, format, content type

**Article Template**:
The parts an Article of one Article Type must hold, in their order, and the header data it carries. There is one per Article Type, plus one for Glossary Entries; an Article missing a required part fails its checks.
_Avoid_: Skeleton, boilerplate, outline

**Style Guide**:
The help centre's short set of writing rules, on top of the brand's Voice, that every Article and Glossary Entry is drafted and reviewed by: how the reader is spoken to, how long sentences and steps may be, how terms, screen words and codes look, and how titles and summaries are written. Kept by the Content Owner beside the Article Templates.
_Avoid_: Writing guidelines, house style, tone of voice

**Placeholder**:
The part of a First Mile Manager message, quoted in a Troubleshooting Article, that changes with the reader's own data (a name, code, number or date), written as a short description in square brackets: "[client] is on hold, so no deliveries can be booked in for it." Wording that First Mile Manager itself varies is never a Placeholder: each wording is quoted in full.
_Avoid_: Variable, parameter, token, wildcard

**Product Area**:
The one part of First Mile Manager an Article belongs to, which gives its place and breadcrumb on the site; roles, types and the getting-started path only list it elsewhere. Product Areas follow First Mile Manager's main menus, name for name, and one appears on the site only once it has a published Article.
_Avoid_: Category, topic, folder, section

**Audience Tier**:
Who an Article is written for: Staff, or Client (the Operator's clients), never both.
_Avoid_: Audience, visibility, access level

**Client-tier Article**:
An Article whose Audience Tier is Client: written to the client, saying "your courier" and never "Stockist", linking only to other Client-tier Articles, with no Operator or Stockist branding in its pictures.
_Avoid_: Client article, portal article, external article

**Article Owner**:
The named First Mile Manager person who answers for an Article being correct and reviewed on time; never an agent.
_Avoid_: Author, maintainer, editor

**Article Status**:
Draft (never public), Published, or Retired. A Draft Article can sit on `main` without being shown, searched or counted; it appears only in Help Previews, marked as a draft. A Published Article is live as soon as it merges.
_Avoid_: State, visibility

**Retired Article**:
An Article taken out of use whose address still works: it redirects to its replacement, or shows a short notice of what changed when there is none.
_Avoid_: Deleted article, archived article, removed article

**Checked On**:
The date an Article was last reviewed against First Mile Manager, shown to readers.
_Avoid_: Last updated, version, release

**Release Note**:
The dated, plain-language record of what one FMM Release changed, made from its Change Summaries grouped by Product Area and linking to the Articles they changed. Written for Staff only; never shown in a courier's branded help centre. It is history, so it is never reviewed or retired.
_Avoid_: Changelog, release article, what's new article, client release note

### The Glossary

**Glossary Entry**:
The help centre's plain-language explanation of one term from the main repository's `GLOSSARY.md`, under that term's exact name, for one Audience Tier, written for a reader with no transport or software knowledge. A term gets one only once readers meet it in First Mile Manager as built; a status has one entry that explains each of its values. An entry says what the term is and leaves who may use it to the Articles; it may be narrower than the main definition while that runs ahead of the build, but never different in meaning.
_Avoid_: Definition, glossary article, term page

**Other Name**:
A word readers really use for a term, shown on its Glossary Entry ("Also called: bin") and used by search to find the term. Picked by hand from the term's avoid words, its former names and searches that found nothing; never a developer-only word.
_Avoid_: Synonym, alias, avoid word (the main glossary's list, of which Other Names are a chosen part)

**Client Glossary**:
The Client-tier Glossary Entries: the words a client meets in the client portal, explained to the client by the client-tier rules, listed under For your clients. A term can have a staff entry and a client entry.
_Avoid_: Client terms, portal glossary

**Term Status**:
Whether a term in the main repository's `GLOSSARY.md` is Published (it has a Glossary Entry), Internal (never published, because readers never meet it) or Not built yet (published when its feature ships). Every term has one, and a home Product Area whose Articles first use it. A Published term whose `GLOSSARY.md` definition describes more than is built is also marked Ahead, so it is rechecked when the rest ships.
_Avoid_: Coverage, publish flag

### Finding your way

**Role Path**:
The listing of every Article tagged with one Staff Role in First Mile Manager, grouped by Product Area, that a reader follows from the home page ("I'm a Driver"). There is one for each of the seven Staff Roles; the Warehouse Manager's also lists the Warehouse Operative's Articles.
_Avoid_: Role section, persona page, audience page

**Getting-started Path**:
The ordered Getting-started Articles that take a new Operator through its set-up in First Mile Manager, each a few steps linking to the How-tos for detail. It ends where First Mile Manager as built lets a new Operator get to: in slice 0, its team invited and signed in.
_Avoid_: Get started section, tutorial, setup wizard

**Launch Content**:
The Articles, Glossary Entries and listings the help centre holds on the day it goes public: an Article for each task, idea and look-up in First Mile Manager as built, a Troubleshooting Article for each situation floor staff hit, the Getting-started Path and the Client-tier Articles. FAQs are not part of it; they are added when real questions repeat.
_Avoid_: Initial docs, seed content, v1 content

**For your clients**:
The listing of Client-tier Articles on this help centre, for Staff to read and share with their clients.
_Avoid_: Client section, portal help, client centre

### Search and feedback

**Search**:
One settled search by a reader, counted once they settle on their words (they press Search, open a result or stop typing), never per keystroke. It ends as No results (nothing shown, not even a Glossary Entry), Opened (a result was opened from it) or Not opened. A refined search is a second Search.
_Avoid_: Query, lookup, keystroke

**Search Record**:
The anonymous record of one Search: its words with personal details replaced by markers, how many results were shown, its outcome and the result opened, the date and where it was made. It never holds who searched, their device, session or Operator, even once readers sign in.
_Avoid_: Search log, search analytics, query log

**Article Feedback**:
One reader's answer to "Did this answer your question?" at the end of an Article, with an optional Feedback Reason and comment when the answer is no, and the Article's Checked On date at the time. Anonymous; nothing stops a second vote.
_Avoid_: Rating, review, vote (alone), helpfulness score

**Feedback Reason**:
The reader's answer to "What was wrong?" after a no: It doesn't match what I see (in First Mile Manager), It didn't answer my question, It was hard to follow, or Something else. "Doesn't match" reaches the Article Owner the same day.
_Avoid_: Feedback category, complaint type

**Help Gaps Report**:
The monthly issue on this repository, beside the Help Drift Report, listing where readers fail to find help: the month's search and feedback measures, the commonest No results and Not opened Searches, candidate Other Names, Articles under 80% helpful and the month's "doesn't match" reports. It is the gaps list of S9 until SR5's support dashboard replaces it.
_Avoid_: Search report, feedback report, analytics report

**Page View Count**:
The help centre's own count of views per page per day, made on its server: a number and nothing about the reader. A page view is not a visit.
_Avoid_: Analytics, visits, traffic, sessions

### Keeping help in step

**Source Reference**:
A link between an Article and the main-project plan, spec, ADR or issue it describes, written in a fixed short form (a plan's repo path, `ADR-0019`, `fmm#128`). A plan or spec lists the Article back in its Help content section.
_Avoid_: Citation, backlink, related item

**Help Proposal**:
The support-site change that carries the help for one main-project spec, or for one change that has no spec: new and changed Articles, Glossary Entries, screen help links and its Change Summary. It merges straight after the change it describes.
_Avoid_: Help PR, docs PR, documentation change

**Help Preview**:
A private copy of the help centre built from one support-site pull request, so AJ sees its pages as readers will before merging. Only AJ, and anyone AJ adds, can open it; search engines never see it; drafts show, marked; search and feedback show but do nothing, and nothing done on it is recorded. It goes when the pull request closes.
_Avoid_: Staging, preview environment, review app, test site

**Change Summary**:
One or two plain sentences, drafted in a Help Proposal, on what a reader can now do or will notice, waiting in the Next Release folder for the next FMM Release's Release Note. A line that changes what clients see is marked "Affects your clients".
_Avoid_: Release note line, changelog entry, commit message

**FMM Release**:
A version of First Mile Manager reaching people outside the team: today AJ promoting the `demo` branch, and once couriers use First Mile Manager for real, a production deploy. It is the moment help must be complete, and it gets one Release Note when anything in it is news to a reader. A merge to the main project's `main` is not an FMM Release.
_Avoid_: Release (alone), deploy, version, merge

**Release Check**:
The list an agent prepares for AJ before an FMM Release: every main-project change since the last one with its help status, the draft Release Note, and every Picture retaken against the version being released. The release waits until every change's help is merged or it said "No help changes", and until no Picture differs. A Stockist release also retakes this help centre's Pictures, and waits for its help update when one differs.
_Avoid_: Release checklist, release gate

**Help Drift Report**:
The monthly issue on this repository listing where help may have fallen behind the main project: Articles whose sources changed, Source References that don't match or lead nowhere, changes merged without their help, Articles past review, and the Glossary Check's reports. It feeds review and retirement.
_Avoid_: Drift check, stale content report, audit

**Demo Walk**:
Following every step of a How-to or Getting-started Article in a freshly seeded Trentside Logistics demo, as the Team Role the Article is tagged for, to prove its steps match First Mile Manager. A step that doesn't match is fixed in the Article if the Article is wrong, or raised on the main project if First Mile Manager is.
_Avoid_: Test, QA pass, proofread

### Pictures

**Picture**:
An image of a First Mile Manager or Stockist screen in an Article, taken by the picture tool from a fresh Joint Trentside copy (Stockist at the version on the demo, with First Mile Manager) at the Picture Date by following its Picture Recipe, signed in as the person the Article is written for. Never taken by hand, never edited by hand, never from the public demo or real customer data. Simple diagrams are written as text and are not Pictures.
_Avoid_: Screenshot, image, screen grab

**Picture Recipe**:
The written steps that make one Picture: who signs in, the screen it starts on, what to click, type or scan to reach its state through the app's own screens, what to keep, what to outline, desk or handheld, and its alt text.
_Avoid_: Script, capture spec, screenshot test

**Picture Name**:
A Picture's name, made from the screen it shows and what it shows (`dock-receiving-terminal-flag-a-short-line`), starting `client-` for a Client-tier Picture. Unlike an Article Name it may change when the screen does.
_Avoid_: Filename, image ID

**Picture Date**:
The fixed moment every Picture is taken at, a Wednesday at 10:30 UK time, so the Trentside stories' dates are the same in every retake. It moves once a year, in January, when every Picture is retaken together.
_Avoid_: Capture date, screenshot date, demo date

**Picture Check**:
Retaking every Picture from one version of First Mile Manager and listing those a reader could see have changed, and any Picture Recipe that can no longer be followed. It runs on every main-project and support-site pull request, in the Release Check and in the Help Drift Report. A main-project change that changes a Picture needs a Help Proposal.
_Avoid_: Screenshot diff, visual regression test, picture refresh

### Launch and measures

**Launch Check**:
A pass-or-fail condition the help centre must meet before it goes public, either automated or a review step. The automated ones keep running on every support-site change afterwards, so the help centre never drops below its launch state. The help centre goes public only when every Launch Check passes and AJ signs off the Launch Check issue.
_Avoid_: Launch checklist, go-live criteria, quality gate

**Reported Measure**:
A figure worked out each month from the help centre's own records and shown against its target in the Help Gaps Report. A miss is reported, never blocks a change and never opens a ticket by itself. A share is judged only in a month with enough Searches, votes or views to mean anything; below that it shows as "too few to judge".
_Avoid_: KPI, metric, SLA, success metric

**Findability Set**:
The list, kept in this repository, of questions in readers' own words, each with the Article or Glossary Entry that answers it. Search must show that answer in its top three results, or the change fails. No-results Searches join it once they have an answer.
_Avoid_: Search tests, test queries, golden set

**Still Stuck Count**:
The help centre's own count of clicks on one Article's "Still stuck?" link (from SR2, "Contact support") per day, made on its server: a number and nothing about the reader. With Page View Counts it gives the "Self-service works" measure, since the help centre has no visits to count.
_Avoid_: Contact rate, escalation count, visits

**Ignored Word**:
An avoid word from the main repository's `GLOSSARY.md` that the avoid-word check skips because it is also everyday English in another sense ("site", "area"). Kept by the Content Owner; the same words are kept out of Other Names.
_Avoid_: Allowlist, stop word, exception
