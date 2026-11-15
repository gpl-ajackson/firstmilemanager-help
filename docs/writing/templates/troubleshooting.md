---
template: Troubleshooting
guide_words: 400
parts:
  - part: In short
  - part: What you see
    field: messages
  - part: Related articles
    field: related_articles
---

# Troubleshooting template

One Troubleshooting Article per situation, not per message. It quotes every First Mile Manager message it explains, word for word, so search finds it from any of them. Write it by the [Style Guide](../style-guide.md).

A message that shows internal ids, or that no reader can act on, gets no Article: raise it on the main project for plain wording, because the fix belongs in the app.

The header data above lists the parts in page order for the Launch Checks: a part with `field` is built by the page from that header data field, and every other part is a `## <part>` heading in the body.

## Header data

As the [How-to template](how-to.md), with:

```yaml
title: Stock won't go into this location   # what the reader sees or can't do, in their words
type: Troubleshooting
product_area: Stock Containers             # the Product Area of the work
team_roles: [Admin, Warehouse Operative]   # who it is written for (shown as "For")
messages:                                  # every message it explains, word for word
  - "[location] is too small for this container."
  - "[location] can't take more weight: it holds [weight] of [maximum]."
```

## Messages and Placeholders

- Write each message once, in `messages`, exactly as First Mile Manager shows it. The page shows them under "What you see" after "In short", and search and the app's help links read the same list. Never repeat them in the body.
- A Placeholder stands only for the reader's own data (a name, code, number or date): a short plain description in square brackets, such as `[client]`, `[location]` or `[contact]`. Everything else stays word for word.
- Where First Mile Manager changes the wording itself (one item or several, say), quote each full wording separately, so search matches real sentences.

## The parts

```markdown
## In short

The commonest cause and its fix, in two sentences or fewer.
The location is too small or too full for what you're putting in it. Choose another location, or split the stock.

## The location is too small {#too-small}

What's happening, then what to do: steps, or a link to the How-to.
The container is bigger than the location's size. Scan a larger location, or [unpack it](article:pack-and-unpack) and move the stock loose.

## The location is too heavy {#too-heavy}

The stock would take the location over its maximum weight. Ask a Floor Supervisor for a Weight Override, or choose another location.
```

- **In short** gives the commonest cause and its fix.
- **What you see** is built from `messages`.
- **One Section per cause**, headed in plain words with a Section Name (`## The location is too small {#too-small}`): what's happening, then what to do. When the reader's role can't fix it, say who can ("Ask an Admin to lift the hold").
- **Related articles** come from `related_articles`, two to five. The page ends with the "Still stuck?" line.
