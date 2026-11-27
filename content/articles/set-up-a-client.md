---
title: Set up a client
summary: Add a business you collect from, change its details, hand it over, set how it is billed and make it Active once its checklist is done.
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
  - ADR-0013
related_articles: [client-statuses, contacts-and-collection-addresses, client-notifications, staff-roles-in-first-mile-manager, invite-people-to-first-mile-manager]
---

## In short

Go to **Clients** and select **Add a client**. Enter its Code, name and invoice address, then select **Add client**. The client starts in Onboarding, with a checklist of what it needs to go Active.

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
7. Select **Add client**. The client's page opens, with its checklist under **Client Status**. You are its Account Manager.

A Code another of your clients already has is refused as soon as you leave the field:

> SHERWOOD is already used by Sherwood Print Works. Choose a different Code.

### Change a client's details {#change}

1. Go to **Clients** and select the client.
2. Select **Change details**, change what you need and select **Save changes**. Each change shows under **History** with your name and the time.

### Hand a client to another Account Manager {#hand-over}

1. Go to **Clients** and select the client.
2. Select **More**, then **Change Account Manager**, and choose the person under **New Account Manager**. The list holds everyone with the Account Manager or Admin role.
3. Select **Hand over**. The handover shows under **History**.

### Set the Invoice Frequency {#invoice-frequency}

You need to be an Admin or a Finance Manager.

1. Go to **Clients** and select the client.
2. Select **More**, then **Set Invoice Frequency**.
3. Under **Invoice Frequency**, choose **Daily**, **Weekly**, **Fortnightly** or **Monthly**.
4. In **From**, choose the date it starts from: today or a later date.
5. Select **Save**. The [Invoice Frequency](term:invoice-frequency) shows under **Billing settings**, with its date.

### Set the Difference Rules {#difference-rules}

You need to be an Admin or a Finance Manager. The four [Difference Rules](term:difference-rules) are set together.

1. On the client's page, select **More**, then **Set Difference Rules**.
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

### Make the client Active {#checklist}

You need to be an Admin, an Account Manager or a Finance Manager.

While a client is Onboarding, its page shows a checklist under **Client Status**. The client needs three things to go Active:

- **A Primary Contact**: a contact with the Primary purpose.
- **A Collection Address** that isn't retired.
- **The client saved in full**. This is done by itself, usually in a moment. While it shows Waiting, see [](article:still-saving). At some businesses this line reads **Its Client Link**.

Each line shows Done, Still needed or Waiting. Nothing else is needed: the tax and customs numbers, billing settings and notifications can wait.

1. Add what the checklist shows as Still needed: see [](article:contacts-and-collection-addresses). The heading changes to **Ready to go Active**.
2. Select **Client Status**, then **Go Active**, and confirm. The client's [Client Status](term:client-status) changes to Active.

Going Active too soon is refused, with what is missing:

> Sherwood Print Works can't go Active yet. It still needs: a Primary Contact, a Collection Address.

For what the other statuses mean, see [](article:client-statuses).

## What happens next

The client shows in **Clients** with the status Onboarding until you make it Active. Its page keeps every change under **History**, newest first, with who made it and when. A client is never deleted, so its history is always there.

Until someone sets them, **Billing settings** shows Not set yet for both. Setting them sends nothing to the client and raises no invoice. Each change shows under **History** with its date.

Next, add the client's contacts and the places you collect from: see [](article:contacts-and-collection-addresses).
