# Release Notes for CSR

## 5.1.0 - 2026-10-09
### Added

- Tickets by email (Pro). Postmark, Mailgun and SendGrid inbound webhooks at `/actions/csr/inbound/<provider>`, each verified by the provider's own means before anything is read — basic auth for Postmark, Mailgun's HMAC with a replay window and single-use tokens, SendGrid's ECDSA signature (or basic auth) — and refused outright when nothing is configured. Or read a mailbox with `php craft csr/inbound/poll` (needs PHP's imap extension), or pipe raw mail to `php craft csr/inbound/import -`.
- Every email to a customer carries a reply address for their ticket (`support+<tag>@example.com`) and a Message-ID CSR remembers, so a reply lands on the right ticket, by the tag or by its `In-Reply-To`/`References` headers. Quoted history and signatures are cut off, HTML is turned into text, and a reply only joins a ticket when it comes from that ticket's customer.
- Out-of-office replies, bulk and list mail, bounces and mail from the desk's own address are ignored; the acknowledgement email says it is automatic (`Auto-Submitted`); senders are rate limited; duplicate deliveries are recognised; attachments are kept under the support form's rules and refused when their content doesn't match their extension, with a note to the agent.
- `csr/inbound/process`, `retry` and `log` console commands, and `Inbound::EVENT_BEFORE_PROCESS` / `EVENT_AFTER_PROCESS`.
- Ticket condition rules — ticket status, status kind, priority, assigned to (including Unassigned and Me), team, category, source, past an SLA target, and SLA due within N hours — for the tickets index filters and custom sources.
- GraphQL for the knowledge base: `csrArticles`, `csrArticle`, `csrArticleCount`, `csrCategories` and `csrCategory`, behind two schema permissions. Public, enabled articles only, whatever the query asks for.
- A daily or weekly desk digest: open tickets, new tickets and SLA breaches since the last one, to a list and (optionally) everybody who can answer tickets. `php craft csr/digest/send` for cron, a web-request fallback, a "Send a test digest now" button and its own permission.
- Console equivalents of every control panel bulk action, through the same services: `csr/tickets/set-status`, `set-priority`, `assign`, `set-team`, `set-category`, `mark-spam`, `merge`, `delete` and `export`, and `csr/kb/export-searches` and `export-feedback`.

### Changed

- Customer-facing emails set `Reply-To` to the ticket's reply address while inbound email is on. With it off, nothing changes.

## 5.0.1 - 2026-10-08

> {warning} A status's own email subject and body are now rendered in Craft's Twig sandbox, where `enableTwigSandbox` is on (Craft 5.9+). A letter that uses `craft.app` or other parts of the Craft API is logged and no longer sent — check your statuses' letters if you wrote anything beyond the ticket variables.

### Security

- Status letters are rendered with `renderSandboxedString()`. Only admins can edit statuses, but the letters travel through project config, and the plain render handed whatever was in them the whole Craft API every time a ticket changed status. The ticket variables (`requesterName`, `number`, `statusName` and the rest) work as before.

### Fixed

- The control panel has a stylesheet of its own. The ticket, reports, settings and dashboard widget carried more than a hundred inline styles that couldn't follow the control panel; they're classes now, on Craft's own colour variables.
- An internal note's yellow edge shows again — Craft's pane border had been winning over it.
- The report tiles line up. Craft's spacing between panes staggered every tile after the first.
- The tickets-a-day chart is drawn in SVG, in the link colour rather than the brand red, which read as an alarm.
- Forms use `actionInput()` and `hiddenInput()` rather than hand-written hidden inputs, and the report site picker no longer uses an inline `onchange` handler.

## 5.0.0

Initial release.

### The knowledge base

- Articles are Craft elements: per-site URIs and templates, a field layout so the body is the
  site's own fields, search, relations, the trash, and a proper element index.
- A shared category tree that articles are filed under **and** tickets are raised about, so the
  articles offered to somebody filing a ticket are found by the same taxonomy the ticket is routed
  by.
- An `Also found by` field on every article, for the phrases customers use that the documentation
  does not. Nobody searches for "payment instrument reconciliation".
- "Was this helpful?" under every article, with a comment box that appears only after a *no* —
  the one moment somebody is both motivated to answer and holding the sentence the page needed.
- A working help centre out of the box — index, category, article and search pages — that a site
  overrules by copying any single template into `templates/csr/`.
- `Article` and `FAQPage` structured data, the latter emitted only when the articles in a category
  really are questions.

### The support desk

- Tickets are elements too, with a sequential ticket number rather than an element ID, and a
  reference that lets somebody without an account read and reply to their own ticket.
- A support form that shows likely answers **before** it will take a message, and records whether
  they worked.
- Statuses a site names itself, over five fixed *kinds* CSR can reason about — including a pending
  kind that pauses the SLA clock while a ticket waits on the customer.
- Internal notes in the same timeline as replies, excluded from the customer's view in the query
  rather than in a template.
- Replies that stop the first-response clock, move a new ticket to open, take a pending ticket out
  of pending, and reopen a closed one within a configurable window.
- Honeypot, signed timestamp, per-address rate limiting (tickets and replies counted separately)
  and optional Turnstile or reCAPTCHA, rendered by `craft.csr.captcha()`, in
  that order, with one identical refusal message.
- A two-way Pigeon bridge: a ticket opens a Pigeon thread, and replies and notes flow both ways.

### Pro

- Failed-search analytics: what people searched for and got nothing back, plus what they searched
  for, found something, and abandoned anyway.
- Article performance, unhelpful-article and never-read reports, and every comment left after a
  *no*.
- Deflection reporting: how many people the suggestions panel sent away happy, and which articles
  earn it.
- SLA policies with first-response and resolution targets, opening hours, holidays, breach alerts
  and escalation.
- Canned replies with placeholders, teams with routing by category and round-robin or least-busy
  assignment, custom questions on the support form, restricted articles, a satisfaction survey
  emailed after a configurable delay, ticket merging and CSV export.

### Console

- `resave/csr-articles` and `resave/csr-tickets`, beside Craft's own `resave/*` commands. Changing
  an article URI format queues the re-save automatically.
