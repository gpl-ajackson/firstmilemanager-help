---
title: Set up a client
summary: Add a business you collect from, with its Code, name, tax and customs numbers and invoice address, change its details, hand it over and set how it is billed.
type: How-to
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
  - ADR-0008
  - ADR-0010
related_articles: [contacts-and-collection-addresses, client-notifications, staff-roles-in-first-mile-manager, invite-people-to-first-mile-manager]
---

## In short

Go to **Clients** and select **Add a client**. Enter its Code, name and invoice address, then select **Add client**. The client starts in Onboarding, with you as its Account Manager.

## Before you start

You need to be an Admin or an [Account Manager](term:account-manager). A Finance Manager can open **Clients** but doesn't see **Add a client**. Anyone else asks their Admin.

The billing settings are the other way round. An Admin or a [Finance Manager](term:finance-manager) sets them, and an Account Manager sees them under **Billing settings**.

Have the [client](term:client)'s registered name, invoice address, and company registration and VAT numbers to hand. If its parcels go abroad, have its EORI, UKIMS and IOSS numbers too.

## Steps

### Add the client {#add}

1. Go to **Clients** and select **Add a client**.
2. In **Code**, enter a short tag of up to 10 characters, such as `SHERWOOD`. Each client needs its own.
3. In **Name**, enter the client's name, such as Sherwood Print Works.
4. Enter its **Company registration number** and **VAT number**. Leave one blank if the client has none.
5. Under **Customs numbers**, enter its **EORI number**, **UKIMS number** and **IOSS number** if its parcels go abroad. You can add them later.
6. Under **Invoice address**, fill in **Address line 1**, **Town or city** and **Postcode**. **Country** starts as the United Kingdom.
7. Select **Add client**. The client's page opens, showing its details and its history.

A Code another of your clients already has is refused as soon as you leave the field:

> SHERWOOD is already used by Sherwood Print Works. Choose a different Code.

### Change a client's details {#change}

1. Go to **Clients** and select the client.
2. Select **Change details**, change what you need and select **Save changes**. Each change shows under **History** with your name and the time.

### Hand a client to another Account Manager {#hand-over}

1. Go to **Clients** and select the client.
2. Select **Change Account Manager** and choose the person under **New Account Manager**. The list holds everyone with the Account Manager or Admin role.
3. Select **Hand over**. The handover shows under **History**.

### Set the Invoice Frequency {#invoice-frequency}

You need to be an Admin or a Finance Manager.

1. Go to **Clients** and select the client.
2. Select **Set Invoice Frequency**.
3. Under **Invoice Frequency**, choose **Daily**, **Weekly**, **Fortnightly** or **Monthly**.
4. In **From**, choose the date it starts from: today or a later date.
5. Select **Save**. The [Invoice Frequency](term:invoice-frequency) shows under **Billing settings**, with its date.

### Set the Difference Rules {#difference-rules}

You need to be an Admin or a Finance Manager. The four [Difference Rules](term:difference-rules) are set together.

1. On the client's page, select **Set Difference Rules**.
2. In **Weight tolerance**, enter a percentage from 0 to 100. This is how far the measured weight may differ from the declared weight.
3. In **Volume tolerance**, enter the same for volume.
4. Under **A difference beyond the tolerance**, choose **Adjust it automatically** or **Hold it for the Account Manager**.
5. Turn **Admin fee** on if the client pays an admin fee for each item beyond the tolerance.
6. In **From**, choose the date the rules start from: today or a later date.
7. Select **Save**. The rules show under **Billing settings**, with their date.

### Change a billing setting from a date {#from-a-date}

Set it again with a new **From** date. A setting applies to collections from its date on. Collections before that date keep the setting they had, so a change never alters the past.

A date before today is refused:

> Choose today or a later date. A change can't reach back before today.

A change with a later date shows under **Changes to come** until its date arrives. To replace it, set it again from the same date. To take it away, set that date back to what applies before it.

## What happens next

The client shows in **Clients** with the status Onboarding. Its page keeps every change under **History**, newest first, with who made it and when. A client is never deleted, so its history is always there.

Until someone sets them, **Billing settings** shows Not set yet for both. Setting them sends nothing to the client and raises no invoice. Each change shows under **History** with its date.

Next, add the client's contacts and the places you collect from: see [](article:contacts-and-collection-addresses).
