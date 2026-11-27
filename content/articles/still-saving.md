---
title: Still saving
summary: Why a client shows Still saving after you change it, who is told when it takes a long time, and what to do when a change could not be saved.
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
  - ADR-0012
  - ADR-0021
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
- In the **Delivery Services** list, in the **Saving** column, beside a [Delivery Service](term:delivery-service) you have added or changed.
- On the **Collections Rate Card**, under **Latest changes**, beside prices an Admin has approved.
- On a client's **Collections Rate Card**, under **Latest changes**, beside prices set for that client. The client shows the marker too.

Everyone who can open the client sees it. To list only the clients with a change still saving, open the filters on **Clients** and turn on **Still saving**.

While several changes are taking longer than usual, Admins also see a message at the top of every page.

## When a change takes a long time {#alerts}

A short wait alerts nobody. A long one does, so that it is noticed.

- **After 4 [Working Hours](term:working-hours)**, the client's Account Manager is told. The message reads "A change to Sherwood Print Works is still saving after 4 working hours".
- **After 1 working day**, your Admins are told too.

A change to a Delivery Service has no Account Manager. After 4 Working Hours, whoever made the change is told instead. For approved prices on your standard Collections Rate Card, it is whoever sent them for approval. Prices on a client's card are a change to that client, so its Account Manager is told.

Working Hours are Monday to Friday, 8am to 6pm UK time, outside bank holidays. Evenings and weekends don't count. A change saved at 5pm on Friday alerts the Account Manager at 11am on Monday.

The messages are under the bell at the top of the page, and each one links to the client. They go by themselves once the change has finished saving. No email is sent.

After the first message, you don't need to do anything. If you are an Admin and get the second, get in touch with First Mile Manager.

## When prices haven't been checked {#prices-not-checked}

First Mile Manager prices from each client's [Collections Rate Card](term:collections-rate-card), and checks those prices by itself several times a day. When it can't check a client's prices for more than 1 working day, your Finance Managers are told. If you have no Finance Manager, your Admins are told instead.

The message reads "The collection prices of Sherwood Print Works haven't been checked for more than a working day". When several clients are affected, it gives the number of clients instead, and you get one message for all of them.

This happens when checking has not worked for a working day. It also happens when a price change could not be saved and nobody has saved it again. Look under **Latest changes** on the client's card for prices marked **Needs saving again**, and select **Save again** beside them.

First Mile Manager keeps using the prices on each client's card, with every change you have saved. Nothing is lost.

The message is under the bell at the top of the page. It goes by itself once the prices have been checked. If it is still there the next day, get in touch with First Mile Manager.

## What never shows it {#what-never-shows-it}

Contacts, Collection Addresses, notifications, the Account Manager, the Invoice Frequency and the Difference Rules are saved in one step. A change to any of them never shows **Still saving**.

## When a change could not be saved {#refused}

Sometimes a change can't finish saving, such as a Code that is already in use. The client's Account Manager is told at once, under the bell at the top of the page. The message reads "A change to Sherwood Print Works could not be saved", with the reason.

The client's page shows the same reason at the top until it is put right. To put it right, open the client, select **Change details**, correct what the reason names and select **Save changes**.
