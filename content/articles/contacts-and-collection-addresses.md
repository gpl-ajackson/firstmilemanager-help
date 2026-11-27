---
title: Contacts and collection addresses
summary: Add the people you deal with at a client and the places you collect from, pick the default address, and remove or retire one.
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
related_articles: [set-up-a-client, client-notifications]
---

## In short

Open the client from **Clients**. Select **Add a contact** for each person you deal with, and **Add a Collection Address** for each place you collect from.

## Before you start

You need to be an Admin or an [Account Manager](term:account-manager). A Finance Manager sees a client's contacts and addresses but can't change them.

The [client](term:client) must be set up first: see [](article:set-up-a-client).

## Steps

### Add a contact {#add-contact}

1. Go to **Clients** and select the client.
2. Under **Contacts**, select **Add a contact**.
3. In **Name**, enter a person's name, or a shared mailbox's such as Goods out.
4. Enter an **Email address**, a **Phone number** or both.
5. Under **Purposes**, select every purpose that fits. One [contact](term:contact) can hold them all.
6. Select **Add contact**. Nothing is sent to them, and they need no [Sign-in](term:sign-in).

The purposes are:

- **Primary**: your main contact at the client.
- **Bookings**: who you talk to about bookings.
- **Collection alerts**: who the emails about a collection go to.
- **Invoices**: who you talk to about invoices.
- **Escalation**: who you go to when something needs sorting out.

### Change or remove a contact {#change-contact}

1. Beside the contact, select **Change**. Change what you need and select **Save**.
2. To remove a contact, select **Remove** beside it and confirm.

### Add a Collection Address {#add-address}

1. Under **Collection Addresses**, select **Add a Collection Address**.
2. In **Name**, enter what you call the place, such as Main warehouse. Then enter its address.
3. Enter the **Site contact** and the **Site contact's phone number**. Give a mobile number if you can.
4. Enter the **Usual collection hours**, and any **Access notes** such as which door to use.
5. Select **Add Collection Address**. A client's first [Collection Address](term:collection-address) is its default.

Two addresses in use at one client can't share a name:

> This client already has a Collection Address called Print works. Give this one a different name.

### Pick the default {#default}

1. Beside the address, open its menu and select **Make default**. The address moves to the top, marked Default.

### Remove or retire a Collection Address {#retire}

1. Beside the address, open its menu and select **Remove**, then confirm.

An address that a collection has used shows **Retire** in place of **Remove**, so past collections keep their address. A retired address stays in the list with its details, marked Retired. It can't be changed, made the default or brought back.

If the address was the default, the oldest address still in use becomes the default.

## What happens next

Each change shows under **History** on the client's page, with your name and the time. A client needs a contact with the Primary purpose and a Collection Address before it can go Active.

To choose what the client hears about a collection, see [](article:client-notifications).
