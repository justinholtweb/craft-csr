---
title: Installation
slug: installation
order: 10
summary: Requirements, install, what you get on day one, the two editions, and the one cron line worth adding.
---

## Requirements

- Craft CMS 5.4 or later
- PHP 8.2 or later

There is nothing else. No build step, no vendored JavaScript library, nothing to compile. The front
end is one plain script and one plain stylesheet. [Pigeon](usage#pigeon) is optional, and CSR
detects it at runtime.

## Install

```sh
composer require justinholtweb/craft-csr
php craft plugin/install csr
```

Or search for **CSR** in the Plugin Store.

## What installing gives you

The help centre works as soon as the install finishes:

- **Five ticket statuses:** New, Open, Waiting on customer, Resolved and Closed. New is where
  tickets land.
- **Four categories:** Getting Started, Account & Billing, Troubleshooting, and Frequently Asked
  Questions. Rename them. They are shared by articles and tickets, so what you file an article
  under is also what somebody picks when they write in.
- **Article URLs at `/kb`:** every site gets the URI format `kb/{slug}`, with the index at `/kb`.
- **A support form at `/support`**, and a page at `/support/ticket` where customers read and reply
  to their own tickets.

If the project config you are applying already holds CSR's statuses and site settings, as it does
when you deploy from another environment, the install does not seed them again. Seeding on top
would create duplicates with different UIDs on every environment.

## Editions

| Edition | Price | What you get |
| --- | --- | --- |
| Lite | Free | A working help centre and support desk. No caps on articles, categories or tickets. |
| Pro | $99, then $79/year | Measurement and scale: failed-search and deflection reports, SLAs, teams, canned replies, custom form questions and more. |

Lite is a working support desk, not a demo. A company with one person answering the email never
meets a paywall. Pro covers the things that only start to matter once one person can't read
everything.

| | Lite | Pro |
| --- | --- | --- |
| Articles, categories, tickets | Unlimited | Unlimited |
| Help centre, search, ratings | ● | ● |
| Suggestions before the form | ● | ● |
| Ticket statuses | 5 | Unlimited |
| Top searches | ● | ● |
| Failed and abandoned searches | | ● |
| Article performance and feedback | | ● |
| Desk reports: volume, response times, workload | | ● |
| Deflection reporting | | ● |
| SLAs, opening hours, escalation | | ● |
| Canned replies | | ● |
| Teams and routing | | ● |
| Custom form questions | | ● |
| Restricted articles | | ● |
| Satisfaction survey, merging, export | | ● |

Lite also keeps 30 days of search history, whatever the retention setting says. That covers "what
happened this month", which is the question the top-searches report answers.

Going back from Pro to Lite is a downgrade, not a lockout. Existing tickets keep their statuses and
every status you created is kept. Lite only stops you creating a sixth.

## First run

1. **Rename the categories** under **CSR → Categories**. Turn off **Tickets can be filed under it**
   on any category that should hold articles only.
2. **Check the URLs** under **CSR → Settings → Sites**. Each site has an article URI format and the
   address of its help centre index. See [Configuration](configuration#sites).
3. **Add fields to articles** under **CSR → Settings → Article fields**. The body of an article is
   your own fields, so add a CKEditor or Matrix field before you write anything.
4. **Say who hears about new tickets** under **CSR → Settings → Notifications**. With nobody listed
   and nobody assigned, CSR has no one to tell, and it logs a warning instead.
5. **Write an article**, and fill in **Also found by** with the words customers actually use.
6. **Open `/support` in a private window**, type a subject line, and watch your article get
   suggested before you can send the message.

## Scheduled tasks

Put the SLA sweep on a schedule if you use service levels (Pro):

```sh
*/5 * * * * php /path/to/craft csr/sla/check
```

A ticket turns red in the control panel as soon as it goes past its deadline, because that is
checked every time the ticket is read. The sweep does the work nobody is watching the screen for:
recording the breach so reports can count it, emailing the desk, and escalating. It is safe to run
as often as you like. A breach is recorded once and emailed once.

CSR's other housekeeping runs during Craft's garbage collection: closing resolved tickets, applying
retention periods, and marking abandoned suggestion sessions. The console commands in
[Usage](usage#console-commands) let you schedule those at a fixed time instead.

If you use the desk digest, schedule it too (it sends once per period however often it runs):

```sh
*/15 * * * * php /path/to/craft csr/digest/send
```

And if inbound email (Pro) reads a mailbox rather than taking provider webhooks:

```sh
*/2 * * * * php /path/to/craft csr/inbound/poll
```

CSR sends email through the queue by default. Make sure your queue is running, whether that's
Craft's web-triggered runner or a `php craft queue/listen` daemon.
