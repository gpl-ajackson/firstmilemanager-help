---
template: Reference
parts:
  - part: In short
  - part: Related articles
    field: related_articles
---

# Reference template

A Reference holds what people look up mid-task: codes, file columns, the values a field takes. Write one only for that, and write it by the [Style Guide](../style-guide.md). It is as long as its tables need.

The header data above lists the parts in page order for the Launch Checks: a part with `field` is built by the page from that header data field, and every other part is a `## <part>` heading in the body.

## Header data

As the [How-to template](how-to.md), with:

```yaml
title: Location codes and code styles     # names what is looked up
type: Reference
team_roles: [Admin, Warehouse Operative]  # who it is written for (shown as "For")
related_articles: [batch-generate-locations, warehouse-layout]
```

## The parts

```markdown
## In short

What this lists and when you need it, in two sentences or fewer.
This lists how location codes are built, for when you create locations or read a label.

## Code styles {#code-styles}

| Style | Example | Built from |
| --- | --- | --- |
| Short | `A1A1` | aisle, rack, shelf and location, with no separator |
| Custom | `A-01-R-01` | each level's prefix and padding, joined by a separator |
```

- **In short** says what the Reference lists and when a reader needs it.
- **The body** is tables, one row per item, under `## <heading>` Sections. Codes and values a reader scans or types are in the mono face, exactly as shown.
- **Related articles** come from `related_articles`, two to five.
