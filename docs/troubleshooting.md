---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: Article URLs that 404, a support form that refuses everything, missing email, SLAs that never breach, an empty suggestions panel, and locked reports.
---

CSR logs under the `csr` category, so start with `storage/logs`. Spam refusals, email that could not
be sent, CAPTCHA provider outages and Pigeon bridge failures all appear there.

## Article URLs return a 404

- **The site has no URI format.** Open **CSR → Settings → Sites**. An empty **Article URI format**
  means that site has no article pages. Each site needs its own row, and a site added after CSR was
  installed has none until you save this screen.
- **The URI format changed a moment ago.** Craft stores each article's URI when the article is
  saved. Changing the format, or adding a site, queues every article to be saved again, so the new
  URLs appear once the queue has run. Check **Utilities → Queue Manager**, or run
  `php craft resave/csr-articles` yourself.
- **The knowledge base is switched off.** With **The public knowledge base** off under General,
  every CSR article, category and search page returns a 404.
- **The article is restricted** (Pro). A reader who isn't allowed to see it gets a 403 on the
  article page, and it doesn't appear in listings or search results for them.
- **The index is somewhere else.** Each site's index, search and category pages live at its
  **The help centre index lives at** path on the Sites screen, or at **Default help centre path**
  when that is empty. See [Configuration](configuration#sites).

## The support form refuses everything

Every spam check shows the same message: *"We couldn't send that. Please try again in a moment."*
This is intentional. A form that says which check caught you is telling a bot which check to work
around. The actual reason is logged, e.g. `CSR refused a support submission (tooFast)`.

- **`honeypot`**: the hidden field was filled in. Browser autofill can do this if your own template
  gives the honeypot a label or `name` that looks like a real field. Keep it off-screen, unlabelled,
  and `autocomplete="off"`.
- **`tooFast`**: the form was sent sooner than **Refuse submissions faster than** allows (3 seconds),
  or it had no valid `csrStamp`. A rewritten template must include
  `<input type="hidden" name="csrStamp" value="{{ craft.csr.stamp() }}">`. A page cached across a
  change to the security key also fails this check. Set the value to `0` to turn the check off.
- **`rateLimit`**: that email address has raised as many tickets this hour as the limit allows,
  or — on the customer's ticket page — posted as many replies as **Replies one address may post
  per hour** allows. This is common in testing, where one address sends everything.
- **`captcha`**: a provider is chosen, but the form didn't post the provider's response field
  (`cf-turnstile-response` or `g-recaptcha-response`). CSR's own templates render the widget with
  `{{ craft.csr.captcha() }}`. A copy of `_support/form.twig` or `_portal/ticket.twig` made
  before you turned CAPTCHA on needs that line added. If the provider can't be reached, the check
  lets the submission through, so an outage never stops customers reaching you.

A refusal that isn't one of these (a missing email address, or an unanswered required question)
says what is wrong, because it isn't a spam check.

## No emails arrive

- **The queue isn't running.** Emails are queued by default. Check **Utilities → Queue Manager**
  for waiting jobs, or turn off **Send through the queue** to test.
- **Nobody to tell.** Emails to the desk go to **Who hears about new tickets**, the assigned agent,
  and the ticket's team. If there are none, the log says *"there is nobody to tell"*.
- **That notification is off.** Each one has its own switch under **Notifications**. Status
  emails are set on each status, with **Email the customer when a ticket moves here**.
- **Craft can't send mail.** Use **Settings → Email → Test** to check. CSR uses Craft's mailer, and
  if the mailer refuses a message, CSR logs it.
- **You expected a copy of your own reply.** CSR doesn't send one. A customer's reply goes to the
  desk, and an agent's reply goes to the customer.

## SLAs never breach

- **Cron isn't running `csr/sla/check`.** The control panel shows a late ticket in red as soon as
  its deadline passes. The breach is only recorded, emailed and escalated when the sweep runs. Run
  it by hand. It reports how many breaches it found.
- **SLAs are off.** If **Put a clock on tickets** is off, the command says so and exits. Tickets
  raised while SLAs were off have no deadlines.
- **The site is on Lite.** Service levels are Pro. On Lite the sweep does nothing.
- **No policy matched.** Policies are tried in order and the first match wins. If none match, the
  ticket has no clock. Add a policy with no conditions at the bottom as a catch-all.
- **The ticket is waiting on the customer.** A Pending-kind status pauses the clock, and the
  deadline moves forward by the time spent waiting. See [FAQ](faq).
- **Opening hours.** A policy that only counts opening hours doesn't run over weekends or
  holidays. Run `php craft csr/sla/preview 240` to see when a target would be due.

## The suggestions panel stays empty

- **Not enough typed yet.** Suggestions start after **Start suggesting after** characters (8 by
  default). Shorter queries would match everything.
- **The matches are too weak.** The panel only shows fairly strong matches, because three weak
  guesses are worse than none for someone who is already frustrated. Fill in **Also found by** with
  the words customers use. A keyword match counts for almost as much as a title match.
- **Your template dropped the hooks.** The script needs the `data-csr-*` attributes described in
  [Usage](usage#the-support-form), and the page must output `{{ head() }}` and `{{ endBody() }}`
  so the script loads.
- **The articles are restricted or disabled on this site.** Suggestions go through the same
  visibility filter as everything else.

## A report says it needs Pro

On Lite, the reports index and **Top searches** work. Failed and abandoned searches, article
performance and feedback, deflection, desk reports and CSV export are Pro. `csr/kb/gaps` prints a
notice on Lite and exits. Lite also keeps only 30 days of searches, so a newly upgraded site has
at most a month of history to report on.

## Email replies become new tickets

- **The reply came from a different address.** A reply only joins a ticket when it's from the
  ticket's customer; anything else is a new ticket (or refused). Look at `php craft
  csr/inbound/log` — the reason is on each row.
- **The reply address was lost.** Some clients reply to `From` rather than `Reply-To`. CSR then
  falls back to the `In-Reply-To` header, which works for any email CSR sent after inbound email was
  switched on.
- **Your provider doesn't deliver `+tag` addresses** to the base mailbox. All the big ones do; a
  custom MTA may need a `recipient_delimiter = +` (Postfix) or equivalent.

## The webhook answers 401 or 403

403 means no secret is set for that provider in CSR. 401 means the credentials or signature were
wrong, or a signed timestamp was outside the replay window (check the server's clock). For SendGrid
with signature verification, a 401 logged as *raw body unavailable* means the route isn't served
with `enable_post_data_reading = Off` — see [Inbound email](inbound-email#sendgrid). 404 means
inbound email is switched off, or the site is on Lite.

