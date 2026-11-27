---
title: Client notifications
summary: Choose whether a client gets an email, an SMS, both or neither at each of four moments of a collection, and check who would get them.
type: How-to
product_area: Clients
audience_tier: Staff
owner: gpl-ajackson
status: Published
feature: Clients and rate cards
checked_on: 2026-11-27
next_review_on: 2027-05-27
team_roles: [Admin, Account Manager]
source_references:
  - fmm#58
  - ADR-0009
related_articles: [contacts-and-collection-addresses, set-up-a-client]
---

## In short

Open the client from **Clients**, select **More**, then **Change notifications**. For each of Booking confirmed, Driver on the way, Collected and Failed, choose Email, SMS, Email and SMS, or Neither.

## Before you start

You need to be an Admin or an [Account Manager](term:account-manager). A Finance Manager sees the choices under **Notifications** but doesn't see **Change notifications** under **More**.

Emails go to the client's [contacts](term:contact) with the Collection alerts purpose. An SMS goes to the site contact at the [Collection Address](term:collection-address) being collected from. To add them, see [](article:contacts-and-collection-addresses).

## Steps

### Choose what the client gets {#choose}

1. Go to **Clients** and select the [client](term:client).
2. Select **More**, then **Change notifications**.
3. For each of **Booking confirmed**, **Driver on the way**, **Collected** and **Failed**, choose **Email**, **SMS**, **Email and SMS** or **Neither**.
4. Select **Save**. The change shows under **History**, with your name and the time.

Until you choose, every moment is set to Email.

### Check who would get them {#who}

1. On the client's page, find **Notifications**. It shows each moment's choice and the contacts emails go to. For each Collection Address, it shows the site contact an SMS goes to.

When emails are chosen and nobody would get one, **Notifications** says so:

> No contact has the Collection alerts purpose and an email address, so no email would go. Add one under Contacts.

An SMS goes only to a mobile number. A Collection Address without one shows:

> Print works: no mobile number for the site contact, so no SMS would go

## What happens next

Choosing sends nothing. The choices are kept with the client, and anyone who can open the client sees them under **Notifications**.
