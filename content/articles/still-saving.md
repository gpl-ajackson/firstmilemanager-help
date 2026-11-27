---
title: Still saving
summary: Why a client shows Still saving after you change it, what you can carry on doing, and what to do when a change could not be saved.
type: Concept
product_area: Clients
audience_tier: Staff
owner: gpl-ajackson
status: Published
feature: Clients and rate cards
checked_on: 2026-11-27
next_review_on: 2027-05-27
team_roles: [Admin, Account Manager, Finance Manager]
source_references:
  - fmm#58
  - ADR-0011
related_articles: [set-up-a-client, contacts-and-collection-addresses]
---

## In short

**Still saving** means a change to a client is kept and already in use, but its last step hasn't finished. It finishes by itself, and you don't need to do anything.

## What Still saving means {#what-it-means}

When you add a [client](term:client) or change its details, First Mile Manager keeps the change at once. You can carry on working with the client straight away.

A few details take one more step to finish saving. They are the Code, the name, the invoice address, the VAT number, the company registration number and the customs numbers. That step usually takes a moment. When it takes longer, the client shows **Still saving** until it is done.

At Trentside Haulage, Priya Nair changes the invoice address of Sherwood Print Works. The new address shows on the client's page at once. **Still saving** shows at the top of the page for a few minutes, then goes.

## Where you see it {#where}

- At the top of the client's page. It says "A change to this client is taking longer than usual to finish saving."
- In the **Clients** list, in the **Saving** column.

Everyone who can open the client sees it. To list only the clients with a change still saving, open the filters on **Clients** and turn on **Still saving**.

While several changes are taking longer than usual, Admins also see a message at the top of every page.

## What never shows it {#what-never-shows-it}

Contacts, Collection Addresses, notifications, the Account Manager, the Invoice Frequency and the Difference Rules are saved in one step. A change to any of them never shows **Still saving**.

## When a change could not be saved {#refused}

Sometimes a change can't finish saving, such as a Code that is already in use. The client's Account Manager is told at once, under the bell at the top of the page. The message reads "A change to Sherwood Print Works could not be saved", with the reason.

The client's page shows the same reason at the top until it is put right. To put it right, open the client, select **Change details**, correct what the reason names and select **Save changes**.
