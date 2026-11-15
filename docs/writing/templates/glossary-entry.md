---
template: Glossary Entry
---

# Glossary Entry template

A Glossary Entry explains one `GLOSSARY.md` term in plain language, for someone with no warehouse or software knowledge (the Finance Manager is often an outside bookkeeper). Write it by the [Style Guide](../style-guide.md).

- An entry exists only for a term a reader meets in First Mile Manager as built: its Term Status in `content/term-status.yaml` is Published. The ticket for its home Product Area writes it, so no two tickets write the same entry.
- It says what the term is and leaves who may use it to the Articles.
- It may say less than `GLOSSARY.md` while the rest of the definition isn't built, but never something different. Mark the term `ahead: true` in `content/term-status.yaml` when it does.
- When you check it against `GLOSSARY.md`, set `checked_on` here and the term's `fingerprint` in `content/term-status.yaml` to what the Glossary Check gives.

## Staff entry: `content/glossary/<term>.md`

The file name is the term in lower case with hyphens: `Stock Container` is `stock-container.md`.

```markdown
---
term: Location                        # exactly as GLOSSARY.md names it
audience_tier: Staff
other_names: [bin, slot]              # only words readers really use for it; shown as "Also called"
where_you_see_it: On the Warehouses menu, on every location label, and on the Rapid Scanner.
checked_on: 2026-10-27                # the day you last checked it against GLOSSARY.md
---

One place in a warehouse where stock can be put, stored and picked from. Each location has its own code, printed on a label you scan.
```

## A status: one entry for every value

```markdown
---
term: Client Status
audience_tier: Staff
where_you_see_it: On each client's page, under its name, and in the Clients list.
status_values:
  - value: Onboarding
    meaning: Being set up. Deliveries wait until it goes live, unless you allow early receipt.
  - value: Active
    meaning: Live. Deliveries and orders go ahead.
  - value: On Hold
    meaning: Paused by your team. Stored stock stays put, but nothing new comes in or goes out.
checked_on: 2026-10-27
---

Where a client account is in its life with your warehouse, which decides what work can be done for it.
```

A value that is a term in its own right (On Hold) also has its own entry; the page links it.

## Client Glossary entry: `content/client-glossary/<term>.md`

The same template with `audience_tier: Client`, written to the client-tier rules: "your courier", never "Stockist", and links only to Client-tier Articles and Client Glossary entries. A client's status entry says what each value means for them and what they can do, without internal detail. A staff-only term (Hold Reason, Dock Override) has no client entry.

```markdown
---
term: On Hold
audience_tier: Client
where_you_see_it: Under **Your account**, beside your company's name.
checked_on: 2026-10-27
---

Your courier has paused new deliveries and orders on your account. Contact your account manager to find out more.
```

## The parts

- **The term**, exactly as `GLOSSARY.md` names it, capitals and all.
- **One or two plain sentences** on what it is, never contradicting `GLOSSARY.md`.
- **Also called** (`other_names`): picked from the `GLOSSARY.md` avoid list, the term's former names and words from searches with no results. Leave out developer-only words ("client entity") and everyday words used correctly ("site", "area") unless searches show readers use them for this term.
- **Where you'll see it** (`where_you_see_it`): the menu, screen, label or message, as the app names it.
- **The Articles that use it** come from the Articles' `term:` links. Never write them by hand.
- **For a status**, each value with a line on what it means (`status_values`).
- **Former names** (`former_names`) only when `GLOSSARY.md` renames the term: rename the file to the new name and keep the old one here, so its address redirects.
