---
title: How First Mile Manager connects to Stockist
summary: How a business that runs Stockist switches First Mile Manager on, shares one Sign-in, and keeps each client as one client across the two.
type: Concept
product_area: Settings
audience_tier: Staff
owner: gpl-ajackson
status: Published
feature: Staff sign-in and roles
joint_only: true
checked_on: 2026-11-27
next_review_on: 2027-05-27
team_roles: [Admin, Account Manager, Finance Manager]
source_references:
  - fmm#57
  - fmm#58
  - ADR-0011
  - ADR-0014
  - ADR-0015
related_articles: [sign-in-to-first-mile-manager, invite-people-to-first-mile-manager, staff-roles-in-first-mile-manager, waiting-for-stockist, settle-a-possible-match-in-link-review]
---

## In short

A business that already runs Stockist switches First Mile Manager on from Stockist. It opens already connected, with the owner as its Admin, and each person has one Sign-in for both.

## Switching First Mile Manager on {#switching-on}

The Stockist Admin switches First Mile Manager on from Stockist's settings. That sets up your business with its [Setup Type](term:setup-type) as Joint and its [Connection](term:connection) made. There is nothing to sign up for. First Mile Manager starts with nobody but you: you choose who uses it.

The Connection is fixed: it ties your business to that one Stockist team for good. **Settings** shows it under **Connected to Stockist**, with the team's name and the date it was made.

## One Sign-in for both {#one-sign-in}

Each person's [Sign-in](term:sign-in) is the same in both apps. Signing in to one signs them in to the other, and signing out of one signs them out of both. A changed password reaches the other app within a minute. First Mile Manager checks Sign-ins itself, so people can still sign in while Stockist can't be reached.

Roles are separate. A person's [Staff Roles](term:staff-role) in First Mile Manager are given in First Mile Manager, and their Stockist roles in Stockist. At Trentside Haulage, Gemma Hartley is a Transport Manager with no Stockist role. Her Sign-in opens First Mile Manager only.

## Adding someone from your Stockist team {#add-from-stockist}

An Admin can give someone who already works in your Stockist team their First Mile Manager roles without an invitation. Go to **People**, select **Add from your Stockist team**, pick the person and their roles, and select **Add**. They sign in with the Sign-in they already use for Stockist, and their Stockist roles stay as they are.

## Moving between the two {#moving-between}

Select **Open Stockist** in First Mile Manager's menu to open your Stockist team, already signed in. If First Mile Manager can't reach Stockist, Admins see a message saying so. Anything waiting shows **Waiting for Stockist** until it goes through: see [](article:waiting-for-stockist).

## Shadow Clients {#shadow-clients}

Stockist needs the clients you collect from too, for its rate cards, labels and customs paperwork. Nobody enters them twice. For a [client](term:client) Stockist doesn't already have, First Mile Manager keeps a [Shadow Client](term:shadow-client) in Stockist.

A Shadow Client holds only the client's Code, name, invoice address, tax and customs numbers and Client Status. Stockist shows it read-only, marked as coming from First Mile Manager.

You change a Shadow Client by changing the client in First Mile Manager. Each change reaches Stockist by itself, in the order you made it, usually within a minute.

Contacts, Collection Addresses, notifications, the Account Manager, the Invoice Frequency and the Difference Rules stay in First Mile Manager. Stockist never holds them.

## Client Links {#client-links}

Every client has a [Client Link](term:client-link): the tie to the one Stockist client that is the same business. A client can't go Active until it has one. Its checklist shows this as **Its Client Link**.

When you save a new client, First Mile Manager asks Stockist whether it already has that business. Stockist answers with each of its clients that has:

- the same company registration number;
- the same VAT number;
- the same name, once "Ltd", "Limited", "Co." and punctuation are left out;
- the same invoice postcode and a name with the same first word.

First Mile Manager then does one of three things.

| What Stockist has | What happens |
| --- | --- |
| Exactly one client with the same company registration number | A [Sure Match](term:sure-match). The client is linked to that Stockist client by itself, and no Shadow Client is made. |
| No client that could be the same business | The client gets a Shadow Client by itself. |
| Anything else | The client waits for Link Review. Its checklist says **Waiting for Link Review**. |

### A Sure Match {#sure-match}

The Account Manager gets a message under the bell, naming the Stockist client and its Code. The client's history records the link too. If the two are not the same business, tell your Finance Manager or Admin before the client's first booking.

A VAT number alone is never a Sure Match, because the companies in a VAT group share one number. A client with no company registration number can't be a Sure Match either.

### Waiting for Link Review {#waiting-for-link-review}

Stockist has a client that could be the same business, and a person has to decide. The client stays in Onboarding until your Finance Manager has decided, in [Link Review](term:link-review). Nothing is made in Stockist while it waits, so the same business is never set up twice.

The Finance Manager settles it as **Same business** or **Not the same**: see [](article:settle-a-possible-match-in-link-review).

At Trentside Logistics, Brew Republic Coffee Co. is already a warehouse client. A new client with the same VAT number but another company registration number waits for Link Review.

### Codes {#codes}

A Code is used once across both apps. When you enter a Code a Stockist client already uses, First Mile Manager refuses it at once:

> BREW is already used by Brew Republic Coffee Co. in Stockist. Choose a different Code.

Choose another Code and save.

### When Stockist can't be reached {#client-links-waiting}

Your new client is saved and stays in Onboarding, marked **Waiting for Stockist**. First Mile Manager makes no Shadow Client while it can't ask, because Stockist may already have the business. It asks by itself within a minute of Stockist coming back, and then makes the Client Link. You don't need to do anything.
