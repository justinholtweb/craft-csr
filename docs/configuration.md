---
title: Configuration
slug: configuration
order: 20
summary: Every settings screen, the field layouts, statuses, teams, SLAs and form questions, and overriding it all from a config file.
---

Settings live at **CSR → Settings**. The link appears for admins when `allowAdminChanges` is on,
which is also where Craft's own settings are edited. The screens that write project config
(Sites, field layouts, statuses, teams, SLAs and form questions) deploy with the rest of your
project config.

## General

| Setting | Default | What it does |
| --- | --- | --- |
| What the help centre is called | `Help Center` | Used in page titles and breadcrumbs. |
| The public knowledge base | On | Articles, categories, search and ratings. Off makes them all 404. |
| Default help centre path | `kb` | Where the index, search and category pages live on a site that hasn't set its own on the Sites screen. |
| The support form | On | Where tickets are raised. |
| The customer's own ticket page | On | Where a customer reads and replies to a ticket, reached from the link in every email. |
| Close resolved tickets after | 7 days | Zero leaves them resolved. |
| A reply reopens a closed ticket for | 30 days | After this, a reply to an old thread doesn't reopen it. |
| Delete tickets after | 0 (never) | Permanently deletes resolved and closed tickets. Nothing goes to the trash. |
| First ticket number | `1000` | Nobody wants to be told they are ticket 1. |
| Assign new tickets | Leave them unassigned | **Pro.** In turn, or to whoever has the fewest open tickets — among the members of the ticket's team when it has one. |
| Hand the conversation to Pigeon | Off | Only shown when Pigeon is installed. See [Usage](usage#pigeon). |

Resolved and closed are kept separate on purpose. Resolved is the desk saying the problem is fixed.
Closed is what happens when the customer doesn't disagree. The gap between the two is when
someone can reply "that didn't fix it" and carry on the same conversation.

## Knowledge base

| Setting | Default | What it does |
| --- | --- | --- |
| Articles per page | `20` | In the built-in category and search pages. |
| Related articles to show | `4` | When an author has not picked any, the most-read articles in the same category fill in. |
| "Popular" means the last | 30 days | The window for popular articles and top searches. |
| Ask readers whether an article helped | On | |
| Ask what they were looking for after a "no" | On | Shows a comment box only after a *no*. |
| Record what people search for | On | Powers the search reports. The visitor is stored as a salted hash. |
| Count article views | On | |
| Keep searches for | 365 days | Zero keeps them forever. Lite keeps 30 days regardless. |
| Shortest search worth running | `2` | Characters, for the live dropdown. |
| Suggestions in the live dropdown | `8` | |
| Words that mean the same thing | none | One group per row, comma separated: `refund, money back, chargeback`. |

A synonym match scores lower than the word the visitor actually typed, so an article called
Refunds still ranks first for "refund".

## Sites

Each site has its own article settings, like a section's site settings:

| Setting | Default | What it does |
| --- | --- | --- |
| New articles are enabled here | On | |
| Article URI format | `kb/{slug}` | Empty gives this site no article pages. |
| Template | none | Optional. Empty uses CSR's own article page. |
| The help centre index lives at | `kb` | Where this site's index, search and category pages live. Empty uses the **Default help centre path**. |

Category pages sit at `<index>/category/<handle>`. Categories get their own segment so that an
article and a category with the same slug can't end up at the same URL.

Changing a URI format, or giving a new site its article settings, queues every article to be saved
again, so their URLs update without anybody running a command. `php craft resave/csr-articles`
does the same by hand.

A named **Template** is rendered by CSR's own article controller, with the same variables as
`article.twig` (`article`, `category`, `related`, `existingVote`), so restricted articles are still
refused and views and search clicks are still counted.

## Support form

| Setting | Default | What it does |
| --- | --- | --- |
| Where the form lives | `support` | A path on every site. |
| Where a customer reads their own ticket | `support/ticket` | Must be different from the form path. |
| The link stays usable for | 0 (forever) | Days after the last reply. |
| Suggest articles as they type | On | The suggestions panel. Included in Lite. |
| Start suggesting after | `8` | Characters typed before CSR looks. Shorter queries match everything. |
| How many to show | `3` | |
| Ask them to look first | Off | Sending within two seconds of the suggestions appearing brings them into view and sends the form when the two seconds are up. A pause, never a wall. Needs the runtime script. |
| Anyone can raise a ticket | On | Off requires visitors to sign in. |
| Default priority | Normal | Low, Normal, High or Urgent. |
| Let people choose the priority | Off | If this is off, a priority in the request is ignored. |
| Let people attach files | Off | Attachments also need a volume. |
| Volume, Sub-folder | none, `tickets` | Files are stored under `<sub-folder>/<ticket number>`. |
| Allowed file types | `png,jpg,jpeg,gif,webp,pdf,txt,log,csv,zip` | |
| Largest file, Files per message | 10 MB, 3 | |

Attachments are always served through CSR and only to the person the ticket belongs to, never from
the asset's own URL. A file that is too big or has the wrong type is skipped, and the ticket is
still created.

## Notifications

| Setting | Default | What it does |
| --- | --- | --- |
| Who hears about new tickets | none | One address per line. The assigned agent and the ticket's team are added automatically. |
| Confirm to the customer / Tell the desk when a ticket is raised | On | |
| Email the customer when the desk replies / Email the desk when the customer replies | On | |
| Send through the queue | On | So a slow mail server never makes the support form hang. |
| Put a clock on tickets | Off | **Pro.** Switches SLAs on. |
| Tell the desk when a target is missed | On | **Pro.** |
| Ask "how did we do?" after a ticket is resolved | Off | **Pro.** Emails the customer a link to rate the ticket 1–5, and shows the rating on their ticket page. |
| Wait before asking | 24 hours | **Pro.** Not sent if the ticket has been reopened, rated or merged in the meantime. |
| Opening hours, time zone, days the desk is shut | 9–5, Mon–Fri | **Pro.** Used by policies that only count opening hours. |

The wording of each email is edited under **Settings → Email → System Messages**. See
[Usage](usage#emails).

### Desk digest

On the same screen. Once a day or once a week, CSR emails the desk how many tickets are open,
awaiting a first reply and unassigned, which tickets are new since the last digest and — with
service levels on (Pro) — which have gone past a target since the last one.

| Setting | Default | What it does |
| --- | --- | --- |
| Email a desk digest | Off | |
| How often | Daily | Daily, or weekly on a chosen day. |
| Hour | 08:00 | In the system time zone. Sent on the first run at or after this hour, once per period. |
| Digest recipients | none | Addresses separated by commas, or an environment variable. |
| Also send it to everybody who can answer tickets | On | Everyone with **Answer and manage tickets**. |
| Send even when nothing is new | Off | |
| Check from web requests too | On | For sites without cron; see below. |

Run `php craft csr/digest/send` from cron every 15 minutes — it sends once per period however often
it runs — or leave **Check from web requests too** on and the end of a web request looks at the
schedule at most every five minutes and queues the digest. It never hangs off garbage collection.
**Send a test digest now** sends what the next one would say, marked as a test, without counting as
this period's digest. `php craft csr/digest/status` shows the last and next send.

## Inbound email

**Pro.** Under **CSR → Settings → Inbound email**; the whole feature is described in
[Inbound email](inbound-email).

| Setting | Default | What it does |
| --- | --- | --- |
| Take tickets by email | Off | |
| The desk's address | none | `support@example.com`. Reply addresses are this with a tag: `support+<tag>@example.com`. |
| Raise a ticket for new email | On | Off, only replies to existing tickets are taken. |
| A reply from somebody other than the ticket's customer | Raise it as a new ticket | Or refuse it. It never joins their conversation. |
| Emails one sender may send per hour | 20 | Zero is unlimited. |
| Keep attachments | On | Under the support form's attachment settings. |
| Other addresses the desk sends from | none | Mail from these is never turned into a ticket. |
| Postmark username / password | none | Basic auth in the webhook URL. |
| Mailgun HTTP webhook signing key | none | |
| SendGrid signature public key, or username / password | none | |
| Replay window | 300 seconds | How far a signed timestamp may be from now. |
| IMAP host, port, encryption, username, password, mailbox, afterwards | 993, SSL, INBOX, mark read | For `csr/inbound/poll`. |

Every secret accepts an environment variable (`$MAILGUN_SIGNING_KEY`). A variable that isn't set
counts as not configured, so that provider refuses everything.

## Spam

| Setting | Default | What it does |
| --- | --- | --- |
| Honeypot field name | `csrCompanyUrl` | `p`, `token`, `action` and `siteId` are refused, because Craft uses those. |
| Refuse submissions faster than | 3 seconds | Zero turns the check off. |
| Tickets one address may raise per hour | `5` | Counted per email address, not per IP. Zero is unlimited. |
| Replies one address may post per hour | `20` | From the customer's own ticket pages, counted separately from new tickets. Zero is unlimited. |
| Store the sender's IP address | On | |
| CAPTCHA provider, site key, secret | None | Cloudflare Turnstile or Google reCAPTCHA (v2 checkbox). The key and secret can be environment variables. |

CSR runs the checks from cheapest to most expensive, so most junk is refused before any network
request. CSR's own support form and ticket page render the CAPTCHA widget with
`{{ craft.csr.captcha() }}`; a template of your own needs the same line. See
[Troubleshooting](troubleshooting#the-support-form-refuses-everything) for what each check means
for your templates.

## Field layouts, statuses, teams, SLAs and questions

- **Article fields**: the article body. CSR keeps the category, visibility, keywords and ratings
  itself and leaves the rest to your fields.
- **Ticket fields**: what your team records about a ticket after it arrives. Customers never see
  these.
- **Statuses**: a name, a colour, and a *kind* (New, Open, Pending, Resolved or Closed), which is
  what CSR actually uses. One status is where new tickets land. Any status can email the customer
  when a ticket moves to it, with its own Twig subject and body or the generic message. Lite allows
  five statuses.
- **Teams** (Pro): a name, a shared address that is copied on the team's tickets, and members.
  Members are stored in the database, not project config, because user IDs differ between
  environments. A category routes its tickets to a team with **Route its tickets to** on the
  category's own screen; a child category with no team uses its parent's.
- **Service levels** (Pro): first-reply and resolution targets in minutes, whether to count only
  opening hours, conditions (priority, category, team, where the ticket came from), breach alerts,
  and escalation to a team a set number of minutes after the first-reply deadline. Policies are
  tried in order and the first match wins, so put the most specific ones at the top.
- **Form questions** (Pro): text, multi-line text, email, URL, number, date, dropdown, radio buttons,
  checkboxes, a single checkbox, or hidden. Each can be required, and each can be limited to
  certain categories.

## Config file

Any setting can be set in `config/csr.php`, which takes precedence over the control panel. It
supports Craft's per-environment keys:

```php
<?php

use craft\helpers\App;

return [
    '*' => [
        'kbPath' => 'help',
        'supportPath' => 'help/contact',
        'portalPath' => 'help/ticket',
        'synonyms' => [['refund', 'money back', 'chargeback']],
        'notificationRecipients' => ['support@example.com'],
    ],
    'production' => [
        'captchaProvider' => 'turnstile',
        'captchaSiteKey' => '$TURNSTILE_SITE_KEY',
        'captchaSecret' => '$TURNSTILE_SECRET',
        'autoAssign' => 'roundRobin',
    ],
];
```

`autoAssign` (**Pro**) is `none`, `roundRobin` (whoever got a ticket longest ago) or `leastBusy`
(whoever has the fewest open tickets). Auto-assignment only picks agents who can answer tickets,
and only from the ticket's team if it has one. The CAPTCHA key and secret accept `$ENV_VAR`
references, here or on the Spam screen.

`businessHours` takes `['monday' => ['open' => '09:00', 'close' => '17:00'], …]`, and `holidays`
takes a list of `YYYY-MM-DD` dates.
