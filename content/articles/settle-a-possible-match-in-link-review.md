---
title: Settle a possible match in Link Review
summary: Decide whether a new client is a business you already have in Stockist, so it is linked to that client or gets a Shadow Client of its own.
type: How-to
product_area: Clients
audience_tier: Staff
owner: gpl-ajackson
status: Published
feature: Clients and rate cards
joint_only: true
checked_on: 2026-11-27
next_review_on: 2027-05-27
team_roles: [Admin, Finance Manager]
source_references:
  - fmm#58
  - ADR-0014
  - ADR-0015
related_articles: [how-first-mile-manager-connects-to-stockist, waiting-for-stockist, set-up-a-client, client-statuses]
---

## In short

Go to **Link Review**. Beside each new client, select **Same business** on the Stockist client it is, or **Not the same** if it is none of them.

## Before you start

You need to be a Finance Manager or an Admin. Nobody else sees **Link Review** in the menu. If you are the client's Account Manager, ask your Finance Manager to settle it.

A client is in [Link Review](term:link-review) when Stockist has a client that could be the same business. It is not a [Sure Match](term:sure-match), so a person decides. [Client Links](article:how-first-mile-manager-connects-to-stockist#client-links) explains how that is decided.

Have to hand what tells two businesses apart: the company registration number, the VAT number and the invoice address. If you are not sure, ask the client's Account Manager, who is named on the page.

## Decide first

Take care with **Same business**. It ties the new client to a client your warehouse already bills. The companies in a VAT group share one VAT number, so the same VAT number alone does not make two businesses the same.

## Steps

1. Go to **Link Review**. Each waiting client has its own box, with the longest wait first.
2. Compare **The new client, in First Mile Manager** with each client under **Already in Stockist**. **Why it could be the same business** lists what is the same.
3. If one of them is the same business, select **Same business** under it, then **Same business** again to confirm. The client leaves Link Review.
4. If none of them is, select **Not the same**, then **Not the same** again to confirm. The client leaves Link Review.

The reasons under **Why it could be the same business** are:

- The same company registration number (when more than one Stockist client has it)
- The same VAT number
- Almost the same name
- The same invoice postcode and a similar name

At Trentside Logistics, Mark Ellison opens **Link Review** and finds Brew Republic Coffee Company beside Brew Republic Coffee Co. They share a VAT number and an invoice postcode. Mark selects **Same business**.

## What happens next

**Same business** links the client to the Stockist client you picked. No [Shadow Client](term:shadow-client) is made. **Not the same** gives the client a Shadow Client of its own.

Either way the client now gets its [Client Link](term:client-link), and can go Active once the rest of its checklist is done. If Stockist can't be reached, the client shows [Waiting for Stockist](article:waiting-for-stockist) until it can.

Your decision is recorded in the client's history, with your name and the time. Stockist records it too.

If Stockist can't make the link, you get a message under the bell with its reason. This happens when the Stockist client was removed, or linked to another client, after you opened the page. First Mile Manager asks Stockist again, and the client may come back to Link Review.

### While a client waits {#while-it-waits}

The client stays in Onboarding. Its checklist shows **Waiting for Link Review** beside **Its Client Link**, and it can't go Active.

### When a client waits 2 working days {#alerts}

After 2 working days in Link Review, a message appears under the bell. Your Finance Managers and the client's Account Manager each get it:

> Brew Republic Coffee Company has waited 2 working days in Link Review

A working day is 10 [Working Hours](term:working-hours), so evenings, weekends and bank holidays don't count. A client that joins Link Review at 3pm on Friday alerts you at 3pm on Tuesday.

If your business has no Finance Manager, your Admins get the message. It goes by itself once the client is settled. No email is sent.

### If someone else settled it first {#already-settled}

Two people can have **Link Review** open at once. If someone settles a client before you do, you see:

> Brew Republic Coffee Company is not waiting for Link Review. Someone may have settled it already.

Nothing changes. Open the client to see what was decided, in its history.
