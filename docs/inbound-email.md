---
title: Inbound email
slug: inbound-email
order: 35
summary: Tickets by email (Pro) — Postmark, Mailgun and SendGrid webhooks, an IMAP mailbox or a pipe, reply addresses, loop protection and attachments.
---

**Pro.** Customers write to your support address and a ticket is raised; they reply to any email
CSR sends them and the reply lands on their ticket. Most support still arrives by email, and
without this the desk only hears from people who find the form.

Switch it on under **CSR → Settings → Inbound email**, set **The desk's address**
(`support@example.com`, or an environment variable), and connect one of the ways in below.

## How a reply finds its ticket

Every email CSR sends a customer — the acknowledgement, the desk's replies, status letters, the
satisfaction survey — goes out with a **reply address** carrying a tag that belongs to their
ticket:

```
Reply-To: support+k3v9q2…@example.com
```

When the reply comes back, the tag is read from the recipient address and looked up exactly; it is
32 random lower-case letters and digits, separate from the link in the email, and nothing else
about the address is trusted. Every mainstream mail provider delivers `support+anything@` to
`support@`. If a mail client drops the tagged address, CSR falls back to the email's
`In-Reply-To` and `References` headers: every email CSR sends carries a Message-ID it remembers,
and the first email the customer sent is remembered too.

CSR never matches on the subject line or on "ticket #1234" in the text. Anybody can type a number.

## What happens to an email

1. **Verified.** The provider's signature or credentials are checked before anything is read. A
   provider with nothing configured is refused (HTTP 403); a wrong signature or password is
   HTTP 401.
2. **Stored and queued.** Duplicates (the same Message-ID delivered twice — providers retry) are
   recognised and dropped. A sender over the hourly limit is dropped. Attachments are checked
   against the support form's rules and copied somewhere safe. The provider gets its 200
   straight away; the rest happens in a queue job.
3. **Loops and automation are ignored**: `Auto-Submitted` (anything but `no`), `Precedence: bulk`,
   `list` or `junk`, `X-Autoreply` and friends, mailing-list headers, bounces from
   `MAILER-DAEMON`, and anything *from* the desk's own address. CSR's own acknowledgement is sent
   with `Auto-Submitted: auto-replied`, so a customer's out-of-office leaves it alone.
4. **Matched to a ticket**, by the tag and then the headers.
5. **The sender is checked.** A reply only joins a ticket if it comes from that ticket's customer.
   The reply address is in every email they were sent and can be forwarded; mail from anybody else
   becomes their own new ticket, or is refused, depending on **A reply from somebody other than
   the ticket's customer**.
6. **A reply** has quoted history ("On … wrote:", Outlook's header block, `>` quotes) and the
   signature ("-- ", "Sent from my iPhone") cut off, is added to the ticket as the customer's, and
   does everything a portal reply does: it takes a ticket out of pending (resuming the SLA clock)
   and reopens a recently closed one. **A new email** raises a ticket with the source *Email*.
7. **HTML is never rendered.** It is turned into text — links keep their address — and the text is
   escaped.
8. **Attachments** are stored in the support form's volume, under its extension, size and count
   limits, and only if the file's content matches its extension. Anything refused is listed in an
   internal note on the ticket, so the agent knows the customer sent it.

Every received email is logged — the last ten are on the settings screen, and
`php craft csr/inbound/log` shows more.

## Postmark

Postmark does not sign inbound webhooks; it authenticates with credentials in the URL. Set a
**Username** and **Password** in CSR, then in Postmark's inbound settings use:

```
https://USERNAME:PASSWORD@example.com/actions/csr/inbound/postmark
```

Postmark sends them as HTTP basic auth, compared in constant time. Point your MX (or a forwarding
rule) at Postmark's inbound address as usual.

## Mailgun

Create a route that matches your support address with the action
`forward("https://example.com/actions/csr/inbound/mailgun")`, and paste your **HTTP webhook
signing key** into CSR. Every post is signed (HMAC-SHA256 over the timestamp and a token); CSR also
refuses a timestamp outside the **Replay window** (five minutes by default) and any token it has
seen before. The `store()` + notify route is not supported — use `forward()`.

## SendGrid

Point Inbound Parse at `https://example.com/actions/csr/inbound/sendgrid`, with either the default
parsed payload or "POST the raw, full MIME message". Two ways to secure it:

- **Signature verification (preferred).** Attach a security policy with signature verification to
  the Parse webhook and paste its public key into CSR. SendGrid signs the timestamp plus the raw
  request body with ECDSA. **PHP throws the raw body of a multipart request away** once it has
  parsed it, so the webhook route has to be served with `enable_post_data_reading = Off` — for
  example, in nginx:

  ```nginx
  location = /actions/csr/inbound/sendgrid {
      fastcgi_param PHP_VALUE "enable_post_data_reading=Off";
      # …your usual fastcgi_pass / include lines…
  }
  ```

  CSR then parses the body itself, after checking the signature over exactly those bytes. Without
  the setting, deliveries are refused and the log says why.
- **Basic auth.** Leave the public key empty, set a username and password in CSR, and put them in
  the Parse URL (`https://user:pass@example.com/…`).

## A mailbox (IMAP)

If you would rather CSR read a mailbox, set the host, port, encryption, username, password and
folder, and run the poll from cron:

```sh
*/2 * * * * php /path/to/craft csr/inbound/poll
```

Unread messages are taken in and then marked read (or deleted). A message is only marked once it
has been stored, so a failure leaves the rest for the next run.

The poll needs **PHP's `imap` extension**, which most hosts don't have and which PHP 8.4 removed
from core (it's on PECL now). Without it the command says so and exits with code 78; a provider
webhook needs nothing extra.

## Piping from your own mail server

`php craft csr/inbound/import -` reads one raw message from standard input, so an alias on your own
MTA works with no provider and no extension:

```
support: "|/usr/bin/php /path/to/craft csr/inbound/import -"
```

## Console

```sh
php craft csr/inbound/poll [--limit=50] [--sync]   # read the IMAP mailbox
php craft csr/inbound/import <file.eml|-> [--sync] # take in one raw message
php craft csr/inbound/process [--limit=100]        # handle queued emails now (no queue runner)
php craft csr/inbound/retry                        # re-queue emails that failed
php craft csr/inbound/log [--limit=20]             # what the last emails became
```

## Events

```php
use craft\base\Event;
use justinholtweb\csr\events\InboundEmailEvent;
use justinholtweb\csr\services\Inbound;

Event::on(Inbound::class, Inbound::EVENT_BEFORE_PROCESS, function(InboundEmailEvent $e) {
    // $e->message (parsed, past the loop guard), $e->ticket (matched, or null for a new ticket).
    // Set $e->isValid = false to drop it, or change $e->ticket.
});

Event::on(Inbound::class, Inbound::EVENT_AFTER_PROCESS, function(InboundEmailEvent $e) {
    // $e->outcome: ticket, reply, ignored or rejected; $e->ticket, $e->reply.
});
```
