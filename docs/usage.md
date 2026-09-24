---
title: Usage
slug: usage
order: 30
summary: The craft.csr Twig API, taking over the templates, the support form and its suggestions, the customer's ticket page, console commands, events, permissions and email.
---

## craft.csr

```twig
{% set articles = craft.csr.articles({ category: 'billing', limit: 10 }).all() %}
{% set results = craft.csr.search(craft.app.request.getParam('q') ?? '') %}

{% for category in craft.csr.categories() %}
    <a href="{{ category.url }}">{{ category.name }}</a>
{% endfor %}
```

| Method | Returns |
| --- | --- |
| `articles(criteria)` | An article query already limited to what the current visitor may read. |
| `allArticles(criteria)` | An article query with no visibility filter. For control panel templates and exports. |
| `article(idOrSlug, siteId)` | One article, if the visitor may read it. |
| `categories(ticketsOnly)` | Top-level categories, with `children` attached. |
| `category(handleOrId)` | One category. |
| `popular(limit, categoryId)`, `featured(limit)` | Arrays of articles. |
| `search(query, options)` | Search results, best first. **Logged**, so it appears in the search reports. |
| `suggest(text, categoryId)` | Likely answers for part-typed text. Not logged. |
| `kbUrl(path)`, `supportUrl()` | The help centre and support form URLs on the current site. |
| `myVote(article)` | `true`, `false` or `null`: what this visitor already said about an article. |
| `myTickets(criteria)` | The signed-in customer's own tickets. Matches nothing for a guest. |
| `ticket(reference)` | A ticket by its reference, which is how a guest reaches their own ticket. |
| `tickets(criteria)` | A ticket query with **no filtering at all**. Never use it on a public page. |
| `formFields(category)` | Your custom questions for a category. Always empty in Lite. |
| `stamp()`, `honeypotField()` | What the spam checks expect a form to post. |
| `captcha()` | The CAPTCHA widget, when a provider is configured on the Spam screen. Nothing otherwise. |
| `priorities()`, `settings()`, `isPro()` | |
| `articleSchema(article)`, `faqSchema(category, articles)` | Ready-to-print JSON-LD. |

Article queries also accept `categoryId`, `featured`, `visibility`, `authorId` and `unhelpful`. The
`category` parameter takes handles and includes each category's children.

Each search result has `article`, `score`, `getSnippet(length)` and `getHighlightedSnippet(length)`.

`articles()` filters for you because the most common way a knowledge base leaks a restricted page
is a listing written by someone who didn't know there was anything to filter. `allArticles()` has
a longer name on purpose, so it isn't used by accident.

## Taking over the templates

CSR ships a working help centre, support form and ticket page. To change one page, copy it into
`templates/csr/` and edit your copy. A template in your site always takes precedence over CSR's,
and you only need to copy the ones you change.

```sh
cp vendor/justinholtweb/craft-csr/src/templates/_kb/article.twig templates/csr/_kb/article.twig
```

| Template | Receives |
| --- | --- |
| `_kb/index` | `categories`, `featured`, `popular` |
| `_kb/category` | `category`, `articles`, `children` |
| `_kb/article` | `article`, `category`, `related`, `existingVote` |
| `_kb/search` | `query`, `results`, `categories` |
| `_support/form` | `ticket`, `categories`, `category`, `formFields`, `stamp`, `honeypot`, `priorities` |
| `_portal/ticket` | `ticket`, `replies`, `reference`, `canRate` |

Every page also receives `settings`, `kbName` and `baseUri`. The shared shell is
`_kb/_partials/_layout`, and every page puts its content in a `content` block, so your copy can
extend your own layout instead.

The Sites screen also has a **Template** setting for articles. A template named there is rendered
by CSR's article controller with the same variables as `article.twig`, so views are still counted,
search clicks are still attributed and restricted articles are still refused. Copying
`article.twig` does the same without a setting.

## The support form

The form posts `action=csr/support/submit` with `subject`, `message`, `name`, `email`, `categoryId`,
`priority`, `answers[<handle>]` and `attachments[]`, plus `csrStamp` and the honeypot field. The
server decides what it accepts. A category that doesn't take tickets is ignored, a priority is only
used if visitors may choose one, and answers are only read for questions you have configured.

The suggestions panel is progressive enhancement. The script looks for
`[data-csr-support-form]` with `data-csr-suggest-url` and `data-csr-min-chars`, and inside it
`[data-csr-subject]`, `[data-csr-category]`, `[data-csr-suggestions]`,
`[data-csr-suggestion-list]` and a `[data-csr-deflected]` button. If JavaScript is blocked, the
form works as an ordinary contact form. Keep those attributes when you rewrite the form.

The **Yes, that answers it** button is what makes the deflection rate a real measurement. See
[FAQ](faq).

## The customer's ticket page

Every ticket has a 32-character random reference. The link in each email is
`/support/ticket?csrRef=…`, which lets someone without an account read and reply to their own
ticket. The parameter is `csrRef` and not `token` because Craft reserves `token` and rejects a
request with an unrecognised one before any plugin runs. A signed-in customer can also open their
own tickets with `?ticketId=`. A reference that doesn't match returns a 404, not a 403, so nobody
can tell whether a reference exists.

The page shows `ticket.publicReplies`. Internal notes are left out by the database query, not by
the template, so a rewritten template can't show one by mistake.

## Ratings and structured data

"Was this helpful?" posts `action=csr/kb/rate` with `articleId`, `helpful` (`1` or `0`) and,
after a *no*, `comment`. The shipped template uses ordinary forms, and the script turns them into
background requests.

`articleSchema()` prints `Article` and breadcrumb JSON-LD. `faqSchema()` prints `FAQPage` only
when a category has at least two articles and most of their titles look like questions. Using
`FAQPage` on reference pages is against search engines' structured-data guidelines.

## Console commands

```sh
php craft csr/sla/check [--limit=500]          # the SLA sweep; run it from cron
php craft csr/sla/preview 240                  # when a 240-minute target set now would be due
php craft csr/kb/gaps [--days=30] [--limit=20] # failed and abandoned searches (Pro)
php craft csr/kb/recount-ratings               # rebuild helpful/unhelpful totals
php craft csr/kb/prune-searches [--days=N]     # delete search rows past the retention period
php craft csr/tickets/close-resolved [--days=N] [--dry-run]
php craft csr/tickets/prune [--days=N] [--dry-run]
php craft csr/tickets/recount                  # rebuild reply counters
php craft resave/csr-articles [--queue]        # re-save articles, like Craft's resave/* commands
php craft resave/csr-tickets [--queue]
```

`close-resolved` and `prune` use the General settings unless you pass `--days`. `prune`
permanently deletes resolved and closed tickets and asks for confirmation when run interactively.
`prune-searches` uses **Keep searches for** (capped at 30 days on Lite) unless you pass `--days`.

## Events

```php
use craft\base\Event;
use justinholtweb\csr\events\TicketEvent;
use justinholtweb\csr\services\Replies;
use justinholtweb\csr\services\Tickets;

Event::on(Tickets::class, Tickets::EVENT_AFTER_SUBMIT, function(TicketEvent $e) {
    // $e->ticket has been saved, numbered and notified
});

Event::on(Tickets::class, Tickets::EVENT_AFTER_STATUS_CHANGE, function(TicketEvent $e) {
    // $e->ticket, $e->previousStatus
});

Event::on(Replies::class, Replies::EVENT_AFTER_REPLY, function(TicketEvent $e) {
    // $e->ticket, $e->reply (check $e->reply->isNote and $e->reply->byAgent)
});
```

## Permissions

**View knowledge base articles**, with **Write and edit articles** (and **Delete articles**) and
**Manage categories** beneath it. **View tickets**, with **Answer and manage tickets** (and
**Delete tickets**), **Export tickets and searches** and **Manage canned replies** beneath it.
**View reports** stands alone. Settings are for admins. Auto-assignment only gives tickets to
people with **Answer and manage tickets**.

## Emails

CSR adds eight messages to **Settings → Email → System Messages**, where you can translate them
per site and they use your HTML email template:

`csr_ticket_raised`, `csr_ticket_raised_agent`, `csr_ticket_reply`, `csr_ticket_reply_agent`,
`csr_ticket_status`, `csr_ticket_assigned`, `csr_sla_alert` and `csr_satisfaction`.

The available variables are `ticket`, `reply`, `status`, `number`, `subject`, `message`,
`requesterName`, `requesterEmail`, `agentName`, `statusName`, `category`, `priority`, `replyBody`,
`ticketUrl`, `cpUrl`, `target` and `organizationName`. Emails to the desk set Reply-To to the
customer, so hitting reply answers them.

## Pigeon

If [Pigeon](https://github.com/justinholtweb/craft-pigeon) is installed and **Hand the conversation
to Pigeon** is on, each new ticket opens a Pigeon support thread with the customer's message in it,
and the conversation flows both ways: replies and internal notes written in CSR are posted to the
thread, and messages written in Pigeon become replies on the ticket — from the desk when the author
can answer tickets, from the customer otherwise — so the first-response clock and the ticket's
status move either way. Pigeon emails the people on the thread about every message, so on a
bridged ticket CSR leaves reply emails to Pigeon rather than sending each answer twice.

The ticket screen links to the thread. CSR imports none of Pigeon's classes. If the bridge fails, a
warning is logged and the ticket is still created.
