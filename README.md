<p align="center"><img src="src/icon.svg" width="96" alt="CSR"></p>

<h1 align="center">CSR</h1>

<p align="center">A public knowledge base and a support desk for Craft CMS 5.</p>

---

The cheapest support ticket is the one nobody raises.

CSR is built around that one sentence. Articles are real pages with real URLs that Google can
find. The search box writes down what it could not answer, so somebody knows what to write next.
The support form offers the likely answer *before* it will take a message, and counts how often
that worked. The tickets that do arrive have a clock on them.

It is Heroic Knowledge Base and Awesome Support, done Craft-native all the way down: articles and
tickets are **elements**, so field layouts, per-site URLs, search, relations, permissions, the
trash, element indexes and the exporter are Craft's own rather than a second implementation of
each.

## Requirements

Craft CMS 5.4 or later, PHP 8.2 or later. No other dependencies — no build step, no vendored
JavaScript library, nothing to compile.

## Installation

```sh
composer require justinholtweb/craft-csr
php craft plugin/install csr
```

Installing gives you five ticket statuses, four categories to rename, and article URLs at `/kb`.
The help centre works immediately.

## The idea

### One taxonomy, read from both ends

An article filed under Billing is what CSR offers somebody who is about to raise a Billing ticket.
That is the whole design. Two parallel taxonomies — one for documentation, one for routing — would
need a mapping between them, and the mapping is the entire value.

### The field nobody ships

Every article has an **Also found by** column: the phrases customers use that the article does not
contain. Nobody searches for "payment instrument reconciliation"; they search for *"why was I
charged twice"*. Every knowledge base that works has this filled in, and every one that does not,
does not.

### The report that pays for the licence

A knowledge base's most valuable page is the one it does not have yet, and the list of searches
that came back empty is the only place its name is ever written down. CSR keeps it, ranked by how
many people hit the same wall — and keeps the other list too, the searches that *found* something
and were abandoned anyway, which is a title problem rather than a missing article.

### Deflection is measured, not inferred

"They went away" and "they were helped" look identical from the server, so a deflection rate
inferred from an absence is really a measurement of how many laptops ran out of battery. CSR asks:
one button, *"Yes, that answers it"*. The number it reports is the number of people who pressed it.

### The clock pauses, it does not stop or run

A ticket waiting on the *customer* must not burn its resolution target, or every policy is breached
by people who reply slowly to their own support requests and the SLA report becomes a measure of
customer responsiveness. Nor may it stop, or a ticket parked in "pending" keeps a clean record
forever. So it pauses, and the time is given back when the customer replies.

## Templating

`craft.csr` is the front end's whole surface area.

```twig
{% set articles = craft.csr.articles({ category: 'billing', limit: 10 }).all() %}
{% set results = craft.csr.search(craft.app.request.getParam('q')) %}
{% set suggestions = craft.csr.suggest(subject, categoryId) %}

{% for category in craft.csr.categories() %}
    <a href="{{ category.url }}">{{ category.name }}</a>
{% endfor %}
```

`craft.csr.articles()` is pre-filtered to what the current visitor may read, because the commonest
way a knowledge base leaks a restricted page is a listing somebody wrote without knowing there was
anything to filter. A template that genuinely wants everything says `craft.csr.allArticles()`,
which is a longer name on purpose.

### Taking over the markup

CSR ships a working help centre and support form. To take over any single page, copy it into your
own templates and edit it there:

```sh
cp vendor/justinholtweb/craft-csr/src/templates/_kb/article.twig templates/csr/_kb/article.twig
```

No configuration, no overrides file, and no need to replace all of them to change one — a template
that exists in the site wins.

## Console commands

```sh
php craft csr/sla/check              # the SLA sweep, for cron — every five minutes is sensible
php craft csr/kb/gaps                # what people searched for and did not find
php craft csr/tickets/close-resolved # close what was resolved and nobody argued with
php craft csr/tickets/prune          # delete tickets past the retention period
php craft resave/csr-articles        # re-save every article, like Craft's own resave/* commands
php craft csr/digest/send            # the daily or weekly desk digest
php craft csr/inbound/poll           # read the support mailbox (Pro, needs ext-imap)
php craft csr/tickets/set-status resolved --numbers=1041,1042   # every bulk action has a command
```

## Email in, GraphQL out

**Email becomes tickets (Pro).** Point Postmark, Mailgun or SendGrid's inbound webhook at CSR — every
delivery verified by the provider's own signature or credentials — or let it read a mailbox. New
mail raises a ticket; replies land on the ticket they answer through a per-ticket reply address,
with quoted history and signatures cut off, attachments kept under the support form's rules, and
out-of-office replies ignored. See [docs/inbound-email.md](docs/inbound-email.md).

**A headless help centre.** `csrArticles`, `csrArticle`, `csrCategories` and friends, behind their own
schema permissions — public articles only, whatever the query asks for.

## Lite and Pro

**Lite is a working support desk, not a demo.** A company with one person answering the email never
meets a paywall: writing articles, publishing them at proper URLs, letting readers say whether they
helped, taking tickets, replying to them, and showing somebody a likely answer before they open the
form are all in Lite, uncapped. There is deliberately no limit on articles, categories or tickets.

Pro is the two halves that only start to matter once one person cannot read everything:

| | Lite | Pro |
|---|---|---|
| Articles, categories, tickets | Unlimited | Unlimited |
| Help centre, search, ratings | ● | ● |
| Suggestions before the form | ● | ● |
| Ticket statuses | 5 | Unlimited |
| Top searches | ● | ● |
| **Failed and abandoned searches** | | ● |
| Article performance and feedback | | ● |
| Desk reports: volume, response times, workload | | ● |
| Deflection reporting | | ● |
| SLAs, opening hours, escalation | | ● |
| Canned replies | | ● |
| Teams and routing | | ● |
| Custom form questions | | ● |
| Restricted articles | | ● |
| Satisfaction survey, merging, export | | ● |
| Ticket condition rules, GraphQL, desk digest | ● | ● |
| Tickets by email (webhooks, IMAP) | | ● |

## Pigeon

CSR and [Pigeon](https://github.com/justinholtweb/craft-pigeon) overlap on exactly one thing — a
threaded back-and-forth with somebody who may not have an account — and Pigeon does it better.
Where both are installed, switching on the bridge hands the conversation half of every ticket to
Pigeon and leaves CSR doing what it is for: the article that should have prevented the ticket, and
the clock on the promise to answer it.

CSR does not require Pigeon, imports none of its classes, and never fails a ticket because the
bridge did not work.

## Licence

Proprietary. See `LICENSE.md`.
