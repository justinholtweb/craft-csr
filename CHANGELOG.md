# Release Notes for CSR

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
