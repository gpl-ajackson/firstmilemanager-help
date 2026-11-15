---
template: FAQ
parts:
  - part: In short
  - part: Related articles
    field: related_articles
---

# FAQ template

There are no FAQs at launch. One is added only when Search Records or requests show a real, repeated question, and this template is written now so the first one follows it. Write it by the [Style Guide](../style-guide.md).

The header data above lists the parts in page order for the Launch Checks: a part with `field` is built by the page from that header data field, and every other part is a `## <part>` heading in the body.

## Header data

As the [How-to template](how-to.md), with:

```yaml
title: Can a client have more than one contact?   # the question as a reader asks it
type: FAQ
team_roles: [Admin]                               # who it is written for (shown as "For")
related_articles: [client-contacts-and-people, client-360]   # the Article with the detail first
```

## The parts

```markdown
## In short

The whole answer, in two sentences or fewer.
Yes. A client can have several contacts, each with its own purposes, such as Invoices or Dispatch Alerts.

## More about contact purposes

Only when needed: one short paragraph.
```

- **In short** is the whole answer.
- **The body** is only when needed: one short paragraph under one `## <heading>`.
- **Related articles** come from `related_articles`: the Article with the detail first.
