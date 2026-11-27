---
title: Waiting for Stockist
summary: Why a client shows Waiting for Stockist after you change it, who is told when a change waits a long time, and what happens when Stockist refuses one.
type: Concept
product_area: Clients
audience_tier: Staff
owner: gpl-ajackson
status: Published
feature: Clients and rate cards
joint_only: true
checked_on: 2026-11-27
next_review_on: 2027-05-27
team_roles: [Admin, Account Manager, Finance Manager]
source_references:
  - fmm#58
  - ADR-0011
  - ADR-0012
related_articles: [how-first-mile-manager-connects-to-stockist, set-up-a-client]
---

## In short

**Waiting for Stockist** means a change is saved and in use in First Mile Manager, but Stockist hasn't taken it yet. Stockist takes it by itself, and you don't need to do anything.

## What Waiting for Stockist means {#what-it-means}

Saving never waits for Stockist. Take a [client](term:client) that has a [Shadow Client](term:shadow-client). When you change it, First Mile Manager keeps the change at once, then sends it to Stockist.

Most changes reach Stockist within a minute. If Stockist can't be reached, the change waits, and the client shows **Waiting for Stockist** until Stockist has taken it. First Mile Manager tries again every minute.

Stockist takes the waiting changes in the order you made them. Two changes to the same client always arrive the right way round.

At Trentside Haulage, Priya Nair changes the invoice address of Sherwood Print Works while Stockist is down. The new address shows in First Mile Manager at once. **Waiting for Stockist** shows on the client until Stockist is back.

## Where you see it {#where}

- At the top of the client's page. It says "A change to this client is saved here and hasn't reached Stockist yet."
- In the **Clients** list, in the **Saving** column.
- In the **Delivery Services** list, in the **Saving** column, beside a [Delivery Service](term:delivery-service) you have added or changed.
- On the **Collections Rate Card**, under **Latest changes**, beside prices an Admin has approved.

Everyone who can open the client sees it. To list only the clients with a waiting change, open the filters on **Clients** and turn on **Waiting for Stockist**.

While First Mile Manager can't reach Stockist, Admins also see a message at the top of every page.

## When a change waits a long time {#alerts}

A short wait alerts nobody. A long one does, so that an outage is noticed.

- **After 4 [Working Hours](term:working-hours)**, the client's Account Manager is told. The message reads "A change to Sherwood Print Works has waited more than 4 working hours for Stockist".
- **After 1 working day**, your Admins are told too.

A change to a Delivery Service has no Account Manager. After 4 Working Hours, whoever made the change is told instead. For approved prices on the Collections Rate Card, it is whoever sent them for approval.

Working Hours are Monday to Friday, 8am to 6pm UK time, outside bank holidays. Evenings and weekends don't count. A change saved at 5pm on Friday alerts the Account Manager at 11am on Monday.

The messages are under the bell at the top of the page, and each one links to the client. They go by themselves once Stockist takes the change. No email is sent.

After the first message, you don't need to do anything. If you are an Admin and get the second, check that Stockist is working for your team. If it is, get in touch with First Mile Manager.

## What never waits {#what-never-waits}

Only the details a Shadow Client holds go to Stockist. They are the Code, the name, the invoice address, the VAT number, the company registration number and the customs numbers.

Contacts, Collection Addresses, notifications, the Account Manager, the Invoice Frequency and the Difference Rules stay in First Mile Manager. A change to any of them never shows **Waiting for Stockist**.

## When Stockist refuses a change {#refused}

Stockist refuses a change it can't accept, such as a Code one of its own clients already uses. First Mile Manager still holds what you saved, but Stockist doesn't.

The client's Account Manager is told at once, under the bell at the top of the page. The message reads "Stockist refused a change to Sherwood Print Works", with Stockist's reason. The client's page shows the same reason at the top until it is put right.

To put it right, open the client, select **Change details**, correct what the reason names and select **Save changes**. The correction goes to Stockist as a new change.
