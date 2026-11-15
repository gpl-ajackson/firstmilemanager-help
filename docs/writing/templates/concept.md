---
template: Concept
guide_words: 600
parts:
  - part: In short
  - part: Related articles
    field: related_articles
---

# Concept template

A Concept explains an idea a reader must grasp before the How-tos make sense: how something works, not how to do it. Write one wherever a reader needs it, and write it by the [Style Guide](../style-guide.md).

The header data above lists the parts in page order for the Launch Checks: a part with `field` is built by the page from that header data field, and every other part is a `## <part>` heading in the body.

## Header data

As the [How-to template](how-to.md), with:

```yaml
title: Inventory statuses and quarantine   # names the idea, or "How … works"
type: Concept
team_roles: [Admin, Warehouse Operative]   # who it is written for (shown as "For")
related_articles: [release-from-quarantine, move-loose-stock]   # the How-tos it explains
```

## The parts

```markdown
## In short

The idea in two sentences or fewer.
Every item of stock has a status, which decides whether it can be picked, moved or sold. Quarantined stock stays in a quarantine location until someone releases it.

## What each status means {#statuses}

The body: how it works, in Sections with plain headings, with one demo example and a simple diagram for a structure or a lifecycle.
Hebden Botanicals' lavender oil arrived with a broken seal, so it went to quarantine on receipt.
```

- **In short** gives the idea itself in two sentences.
- **The body** is one or more `## <heading>` Sections, in plain words. Give one worked example from the Trentside demo's client stories, and draw a structure or lifecycle as a Mermaid diagram with its `accDescr:` line. Concepts have no Pictures and no numbered steps.
- **Related articles** come from `related_articles`: the How-tos it explains, two to five.
