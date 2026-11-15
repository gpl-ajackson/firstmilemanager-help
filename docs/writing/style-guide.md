---
# The words no Article or Glossary Entry uses. Launch Check 5 (Words) fails each one, with
# GLOSSARY.md's avoid words, in any capitals and in the plural. To name a banned word as the
# wrong word, put it alone in quotation marks. Quoted First Mile Manager messages and code spans are skipped.
banned_words:
  # Voice rule 1: speak to the reader as "you".
  - word: we
    say_instead: you
  # Voice rule 2: the same Article is read at a desk and on a handheld.
  - word: click
    say_instead: select
  - word: tap
    say_instead: select
  - word: press
    say_instead: select
  - word: hit
    say_instead: select
  # Voice rule 3: name First Mile Manager only to tell it apart from something else.
  - word: the system
    say_instead: First Mile Manager
  - word: the app
    say_instead: First Mile Manager
  - word: the software
    say_instead: First Mile Manager
  - word: FMM
    say_instead: First Mile Manager
  # Voice rule 4: words that add nothing.
  - please
  - simply
  - just
  - easy
  - easily
  - note that
  - word: e.g.
    say_instead: such as
  - word: i.e.
    say_instead: that is
  - word: etc.
    say_instead: name the rest, or "and so on"
  - word: despatch
    say_instead: dispatch
  # Voice rule 5: the Team Role, even where a screen says "administrator".
  - word: administrator
    say_instead: Admin
  # The brand book's Voice: never these.
  - seamless
  - revolutionary
  - best-in-class
  - powerful
  - robust
  - cutting-edge
  - AI-powered
---

# Style Guide

How every help-centre Article and Glossary Entry is written, here and in a Help Proposal from the main project. AJ settled these rules on 24 October 2026 (support-site issue #22). Draft each Article with its type's template in [`templates/`](templates/), and each Glossary Entry with [`templates/glossary-entry.md`](templates/glossary-entry.md). Review step 3 ("Reads right") checks Articles against this guide.

The Content Owner changes this guide and the templates through a support-site pull request. A changed rule doesn't force a sweep: Articles catch up when they are next touched or reviewed.

## Voice

The brand folder's voice note (`brand/README.md`) applies in full: short, plain and certain, in the words couriers and their drivers already use. Numbers beat adjectives. Say what to do next. Never claim what isn't built. No exclamation marks or emoji. Help pages use the office register: full sentences, numbered steps, and the fix for every problem.

On top of that, five rules for Articles:

1. **Speak to the reader as "you"**, and write steps as commands ("Enter their mobile number"). Never write "we": in a Client-tier Article it would mean nobody the client knows.
2. **"Select" for anything chosen on a screen, "scan" for a barcode, "enter" for typing.** Never "click", "tap", "press" or "hit", because the same Article is read at a desk and on a handheld.
3. **Name First Mile Manager only to tell it apart from something else** ("the carrier's site, not First Mile Manager"). Never "the system", "the app", "the software" or "FMM". Name Stockist only in a Joint-only Article (`setup_types: [Joint]`), which readers at an FMM-only Operator never see.
4. **No filler.** Never "please", "simply", "just", "easy", "easily", "note that", "e.g.", "i.e." or "etc.", and always "dispatch", never "despatch".
5. **"Admin"**, the Team Role, even where a screen says "administrator". A screen that says "administrator" is raised on the main project, so the screen comes to match.

The `banned_words` above list these words with the brand folder voice note's (seamless, revolutionary, best-in-class, powerful, robust, cutting-edge, AI-powered). Launch Check 5 fails each one, and each `GLOSSARY.md` avoid word, in any Article or Glossary Entry. To name a wrong word so the reader knows the right one, put it alone in quotation marks: Stockist calls a "bin" a location.

## Lengths

- **A sentence** has 20 words at most. **A paragraph** has three sentences at most.
- **A step** is one action and what it changes, in two sentences or fewer: "Select **Place hold**. The client's status changes to On Hold."
- **A run of steps** has ten steps at most. Split a longer task into parts, each under its own heading with a Section Name, still on one page.
- **"In short"** has two sentences or fewer, about 40 words, and gives the answer itself. A reader who knows the screens can stop there.
- **Article lengths** are a guide for the reviewer, not a rule: How-to and Troubleshooting under about 400 words, Getting-started under about 250, Concept under about 600, Reference as long as its tables need. Each template's `guide_words` gives its type's.

The Launch Checks report long sentences, long runs of steps and Articles over their guide length for the reviewer. These reports never fail a build.

## How words look

- **Terms.** Use `GLOSSARY.md`'s terms (in the main project), exactly. A term of one word is lower case in running text (operator, connection, client, contact), except Team Role names (Admin, Driver) and status values as the screen shows them (Active, On Hold). A term of two or more words keeps `GLOSSARY.md`'s capitals (Staff Role, Setup Type, Worker Type, Collection Address).
- **Courier words.** Say clients (never customers), collection, round and check-in.
- **Screen words** (buttons, fields, tabs, menus) are in bold, exactly as the screen shows them. Use "›" for a route through menus: "Go to **People** and select **Invite**." First Mile Manager's buttons are in sentence case; its field and object labels are in title case.
- **What a reader scans or types** is in the mono face (a code span), exactly as shown: an email address or mobile number, a code.
- **First Mile Manager messages** are quoted word for word in a quote block, never paraphrased. A Troubleshooting Article lists them in its header data instead (see its template).
- **Numbers** are digits: 3 drivers, 12 collections. **Dates** are written 24 October 2026, **times** 2pm (or 17:30 where the screen shows a clock time), **money** in pounds and pence (£4.20), and weights and sizes in the screen's units.

## Titles and summaries

Titles are in sentence case, 60 characters at most, with one pattern per Article Type:

| Article Type | Title pattern | Example |
| --- | --- | --- |
| How-to | A command, in the words a reader searches for, in every tier. Never "How to…". The button's own words go in the steps. | Put a client on hold |
| Getting-started | A command with "your". The page shows "Step 3 of 11" from its place in the path. | Invite your team |
| Concept | The idea, or "How … works" | Inventory statuses and quarantine |
| Reference | What is looked up | Location codes and code styles |
| Troubleshooting | What the reader sees or can't do, in their words | Stock won't go into this location |
| FAQ | The question as a reader asks it | Can a client have more than one contact? |

The **summary** is one sentence of 160 characters at most, saying what the reader will be able to do or know. Never start "This article…". Search results, `llms.txt` and search engines show it.

The **Article Name** (the file name, its address for good) is the title in lower case with hyphens, shortened where that reads well: `put-a-client-on-hold`. It never contains "stockist", except a Joint-only Article's, so it reads right to any reader who may see it.

## Who can do this

- On a **How-to or Getting-started** Article, `team_roles` are the roles that can do the task in First Mile Manager as built, and the page labels them "Who can do this". On a **Concept, Reference or Troubleshooting** Article they are the roles it is written for, labelled "For".
- **"Before you start"** opens with the same fact in a sentence ("You need to be an Admin."), then what someone without it sees and does ("If you don't see **Invite**, ask an Admin.").
- **A step only some of the tagged roles can do** starts with the role: "Admin only: select **Make Admin**."
- **Name Team Roles only**, exactly as First Mile Manager has them (its Staff Roles: Admin, Finance Manager, Account Manager, Transport Manager, Driver, Warehouse Manager, Warehouse Operative; for clients, once built, Client Viewer, Client User and Client Account Administrator). Never a job title First Mile Manager doesn't have, such as "team leader". The business's owner is "an Admin".
- **The one exception** is the client line "your account manager", which names a person the client knows.

## Linking

- **Link the first use of a term** in an Article to its Glossary Entry, for the same tier: `[Staff Role](term:staff-role)`, naming the term as its Glossary Entry's file name does. Only the first link to a term on a page counts. There are no hover pop-ups, which don't work on handhelds.
- **Link other help by name**, never by address: `[words](article:<name>)`, `[words](article:<name>#<section>)`, `[words](#<section>)` for this Article's own Section, and `[](article:<name>)` to show the Article's title.
- **A Section Name** goes at the end of its heading, only for parts something links to: `## The invitation has run out {#run-out}`.
- **Related articles** are picked by hand, two to five. A How-to always lists its Concept, and a Concept its How-tos.

## Pictures and diagrams

- Put a Picture where a step needs it: the Getting-started Path, every How-to a Driver or Warehouse Operative follows on a phone, and the Client-tier Articles. Concepts and References are text and simple diagrams.
- Put a Picture under its step, on a line of its own: `![](picture:<Picture Name>)`. Its alt text is in its Picture Recipe, never in the Article: one sentence of about 125 characters at most, saying what the reader needs from it, in `GLOSSARY.md` terms, never "Screenshot of". No captions.
- A Picture never holds anything its step leaves out, so every step works read aloud or with pictures off.
- Draw a structure or lifecycle as a Mermaid diagram with an `accDescr:` line saying what it shows.

## Examples

Examples come from the Trentside Logistics demo, never from real customers: its people as First Mile Manager's demo seeders hold them, and its clients and stories as each slice's spec adds them. An Article that uses a story lists that spec as a Source Reference. An email address, phone number or postcode is one the demo holds, or `hello@firstmilemanager.co.uk`.

## Client-tier Articles

A Client-tier Article (and a Client Glossary entry) uses the same templates with these rules:

- Say "your courier", and never name Stockist anywhere a reader sees it: title, summary, messages, words, alt text or web addresses.
- Link only to Client-tier Articles and the Client Glossary.
- Leave out internal detail: hold reasons, other clients, staff names.
- The page ends with "Questions about your stock or deliveries go to your account manager." Never write it yourself.

## Describe First Mile Manager as built

Help describes First Mile Manager as it is on the demo, with no versions. Nothing is written about features that aren't built, and there are no placeholder pages. An Article whose feature isn't on the demo yet waits, hidden, until it is. A step that doesn't match First Mile Manager is fixed in the Article if the Article is wrong, or raised on the main project if First Mile Manager is, never papered over. Help never states prices or plans: link to the marketing site for them.
