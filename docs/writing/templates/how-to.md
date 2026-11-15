---
template: How-to
guide_words: 400
parts:
  - part: In short
  - part: Before you start
  - part: Decide first
    when_needed: true
  - part: Steps
  - part: What happens next
  - part: Related articles
    field: related_articles
---

# How-to template

One How-to per task a person sets out to do. Small actions on the same screen fold into the task they belong to: deactivate and reactivate are one Article. Write it by the [Style Guide](../style-guide.md).

The header data above lists the parts in page order for the Launch Checks: a part with `field` is built by the page from that header data field, and every other part is a `## <part>` heading in the body, in this order. A part marked `when_needed` is left out when the task has nothing for it.

## Header data

```yaml
---
title: Put a client on hold          # a command, in the words a reader searches for; never "How to…"; 60 characters at most
summary: Stop deliveries and orders for a client while you sort out a problem, and lift the hold when it's fixed.
type: How-to
product_area: Clients                # exactly as the app's menu names it (content/product-areas.yaml)
audience_tier: Staff             # or Client
owner: gpl-ajackson                  # the Article Owner's GitHub login; never shown to readers
status: Draft                        # Draft, Published or Retired
feature: Staff sign-in and roles       # the feature it is about (content/features.yaml); it shows once that is on the demo. Left out on help-centre pages
# joint_only: true                  # only for an Article about Stockist, for Joint Operators: never listed or searched
checked_on: 2026-10-27               # the day you last made a Demo Walk of it
next_review_on: 2027-04-27           # at most six months after checked_on
team_roles: [Admin, Account Manager, Finance Manager]   # who can do the task in First Mile Manager as built; left out on a Client-tier Article
source_references:                   # the plans, specs and ADRs it describes, in the main project's short forms
  - .junie/plans/Client/2026-10-19-132600-client-account-management.md
  - ADR-0014
related_articles: [client-account-lifecycle, cant-receive-for-this-client]   # two to five, picked by hand; always its Concept
---
```

## The parts

```markdown
## In short

The answer, in two sentences or fewer (about 40 words), so a reader who knows the screens can stop here.
Go to **Clients › Peak Fuel** and select **Place hold**, giving a reason. Deliveries and orders stop until you select **Lift hold**.

## Before you start

You need to be an Admin, an Account Manager or a Finance Manager. If you don't see **Place hold**, ask the client's Account Manager.
Then what must exist first, and what to have to hand.

## Decide first

Only for a choice that is hard to change later, each with a recommendation for a first set-up.

## Steps

1. Go to **Clients › Peak Fuel**.
2. Select **Place hold**. Enter the reason your team will see.
3. Select **Place hold** again to confirm. The client's status changes to On Hold.

![](picture:clients-place-hold)

## What happens next

What changes, who sees it, and what to do after. Peak Fuel's people see that their account is on hold, never your reason.
```

- **In short** is the body's first Section, and gives the answer itself, not a promise of one.
- A First Mile Manager message the task can show is quoted word for word in a quote block in the step it belongs to. Only a Troubleshooting Article lists messages in its header data.
- **Before you start** opens with who can do the task, in a sentence ("You need to be an Admin."), then what someone without that role sees and does. Then what must exist first and what to have to hand.
- **Steps** is a numbered list: one action and what it changes per step, two sentences at most, ten steps at most in one run. A longer task is split into parts, each a `### <part> {#section-name}` heading under "Steps", with its own numbered list. A step only some of the tagged roles can do starts with the role ("Admin only: select **Override hold**."). A Picture sits on its own line under its step.
- **What happens next** says what changes, who sees it and what to do after.
- **Related articles** come from `related_articles`: two to five, picked by hand, always including the Concept that explains the task.

A Client-tier How-to follows the same template with the client-tier rules in the Style Guide; the page adds its closing line about the account manager.
