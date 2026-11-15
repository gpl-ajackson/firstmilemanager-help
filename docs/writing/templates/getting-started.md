---
template: Getting-started
guide_words: 250
parts:
  - part: Place in the path
    field: path_position
  - part: In short
  - part: Before you start
    when_needed: true
  - part: Decide first
    when_needed: true
  - part: Checklist
  - part: What happens next
  - part: Related articles
    field: related_articles
    when_needed: true
---

# Getting-started template

A Getting-started Article is one step of the Getting-started Path, which takes a new Operator from an empty account to its first client's first order dispatched. It links to the How-tos and never repeats them: a detail missing from a How-to is added to the How-to, never here. Write it by the [Style Guide](../style-guide.md).

The header data above lists the parts in page order for the Launch Checks: a part with `field` is built by the page from that header data field, and every other part is a `## <part>` heading in the body, in this order.

## Header data

As the [How-to template](how-to.md), with:

```yaml
title: Build your warehouse          # a command with "your"
type: Getting-started
path_position: 3                     # its step in the path; the page shows "Step 3 of 11" and links the next step
team_roles: [Admin]                  # who can do these tasks in First Mile Manager as built
related_articles: []                 # only when needed: the checklist already links its How-tos
```

## The parts

```markdown
## In short

What you'll have at the end of this step, in two sentences or fewer.
By the end of this step, your warehouse has its zones and every location, ready to label.

## Decide first

Choices a new Operator should make before it starts and can't easily change later, each with a recommendation for a first set-up.
Choose your warehouse's code now. Once a delivery has been booked in under it, it is fixed. For one warehouse, `W1` works well.

## Checklist

1. Add your warehouse and its zones with [](article:add-a-warehouse-and-zones). Do this first, because locations sit in zones.
2. Create your aisles, racks and shelves in one go with [](article:batch-generate-locations). Do this before labelling, because labels print from the locations you make.

## What happens next

You're done when your locations show in **Warehouses › Locations**.
```

- **Place in the path** comes from `path_position`. The page shows "Step 3 of 11" above the title and links the next step after the Article, so never write either.
- **In short** says what the reader will have at the end of the step.
- **Before you start** is only for what this step needs that the path hasn't given already.
- **Decide first** holds the choices that are hard to change later (a warehouse's code, location code styles), each with a recommendation. This is what the path adds that no How-to holds.
- **Checklist** is a numbered list of the tasks in order: one sentence per task saying what to do and why it matters at this point, then a link to its How-to. Example values come from Lumen & Loom and Trentside, so the whole path builds one warehouse and one client.
- **What happens next** opens with "You're done when…" and names one thing the reader can see on screen ("Lumen & Loom shows as Active in **Clients**").
- **Related articles** only when needed.

The welcome page (`get-started`) has its own short pattern: what the ten steps build, in order, and what First Mile Manager doesn't do yet, linking to the marketing site's "What's coming". It still opens with "In short".
