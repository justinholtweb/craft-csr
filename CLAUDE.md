# CSR — Craft CMS 5 Plugin

## Project Overview

CSR is a public knowledge base and a support desk for Craft CMS 5. Heroic Knowledge Base and
Awesome Support are the benchmarks; what they cannot do, and CSR can, is be *Craft-native* all the
way down — articles and tickets are elements, so field layouts, per-site URLs, search, relations,
permissions, the trash, element indexes and the exporter are Craft's own rather than a second
implementation of each.

Distributed as `justinholtweb/craft-csr`. Lite (free) + Pro.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.4+**, Yii2, Twig
- **No build step anywhere.** The front-end runtime is a plain script and plain CSS; there are no
  vendored third-party libraries at all.
- No hard dependencies beyond Craft. Pigeon is detected at runtime and never imported.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\csr`
- Package: `justinholtweb/craft-csr`
- Handle: `csr`

### The load-bearing distinctions

Four of them, and most of the design falls out of them.

**1. One taxonomy, read from both ends.** `csr_categories` is shared: an article filed under
Billing is what CSR offers somebody about to raise a Billing ticket. Two parallel taxonomies would
need a mapping between them, and the mapping is the entire value.

**2. A column is something CSR has to reason about; a field is the site's.** Category, visibility,
keywords and the rating counters drive queries, deflection and reports, so they are columns. The
body of an article is the field layout's, and CSR has no opinion about it.

**3. Form fields and the ticket field layout are opposite directions.** `models\FormField` is what
the *customer* answers: validated on the server, rendered in the site's markup, stored in
`csr_tickets.answers`. The Ticket element's field layout is what *the desk* records afterwards, and
the customer never sees it. A Craft field layout cannot do the first job — it renders CP HTML for a
trusted author and has no concept of "a stranger must fill this in".

**4. Deflection is measured, not inferred.** "They went away" and "they were helped" are identical
from the server, so `csr_deflections` records an explicit outcome, set by a button the visitor
presses. Abandoned sessions are counted in neither half of the rate.

### Data model

- `elements\Article` — localized, per-site URIs from `csr_articles_sites` (project config), one
  field layout found by `getLayoutByType()`. **No drafts**: CSR renders its own edit screen, and
  advertising drafts while editing outside the element editor produces draft URLs that lead
  nowhere.
- `elements\Ticket` — **not** localized. The submitting site is `submittedSiteId`, *not* `siteId`:
  `craft\base\ElementTrait` already declares that property. `number` is sequential per install;
  element IDs jump by hundreds between two tickets an hour apart.
- `csr_tickets.requesterId` is `SET NULL` and the email and name are columns — most people who
  write to support have no account.
- `csr_ticketevents` is append-only. A `statusId` column cannot record who moved a ticket, when, or
  from what.
- `csr_searches` is one row per search, not a counter per phrase: "how often was `refund` searched
  **and nothing came back**" needs each search's result count, and "what did they open afterwards"
  needs the click.
- Replies and internal notes share `csr_replies`, distinguished by `isNote`. The timeline is one
  sequence; two tables would be merged in PHP on every render and would eventually disagree about
  the order of two things written in the same second.

### Services

- `articles` / `categories` — per-site settings and the field layout (project config), the tree,
  relations, the view counters
- `search` — the **one** implementation behind the search page, the live dropdown, the deflection
  panel and the related-articles fallback. If the panel used a different matcher from the page,
  "nothing found" would not mean the same thing in both and the failed-search report would be
  measuring two different knowledge bases.
- `ratings` / `deflection` — the two things a reader tells you
- `tickets` / `replies` — the submission pipeline (the only anonymous write path) and everything a
  reply implies
- `statuses` / `slas` / `teams` / `forms` — project config, mirrored into records so IDs exist for
  foreign keys
- `notifications` — eight Craft system messages plus the per-status letters
- `analytics` — the reports; `deflection->getStats()` is the one the plugin exists for
- `spam` — honeypot, signed timestamp, rate limit, optional CAPTCHA, in cost order
- `pigeon` — the optional bridge, reached entirely through `getPlugins()` and duck typing
- `schema` / `exporter` — the exporter writes the same CSV to a streamed response or a file
- `inbound` — email in (Pro): webhooks, IMAP, reply addresses; see the section below
- `digest` — the scheduled desk digest, ported from Yarn's (Theme 7 reference)

### Where the trust boundary is

`SupportController::actionSubmit()` and `Tickets::submit()`. Everything there assumes the request
is hostile: the category is looked up and re-asked whether it accepts tickets; the questions are
enumerated from *configuration* and each pulls its own value out of the request, so a value posted
under an unknown handle is never read; the priority is honoured only if the site said visitors may
choose one; the status is the desk's default; uploads come from the form's own file input; and
every spam refusal returns the identical message.

## Inbound email (reference for the family)

CSR's email-to-ticket is the **Theme 8 reference**: Pigeon ports it next (reply-by-email to
threads). There is no shared package — copy the files, rename the namespace, adapt the marked part.

### Files to copy

| File | Generic? | What to adapt |
| --- | --- | --- |
| `src/mail/*.php` (`Addresses`, `InboundMessage`, `InboundAttachment`, `InboundRequest`, `MimeParser`, `MultipartParser`, `ReplyParser`, `LoopGuard`) | Yes — plain PHP, no Craft, no plugin classes | Namespace only |
| `src/mail/providers/*.php` (`ProviderInterface`, `Credentials`, `Postmark`, `Mailgun`, `SendGrid`) | Yes | Namespace only. Secrets come in through the constructor, never from settings |
| `src/services/Inbound.php` | Above "What CSR does with it" | Table names, `Edition` check, settings names (`env()` helper on the model). Below the line rewrite `handle()` / `appendReply()` / `raiseTicket()` / `findTicket()` / `senderMatches()` for your thread model; keep `process()`'s shape (claim the row → loop guard → find → verify sender → event → act → record → clean files) |
| `Inbound::createTables()` + a migration + `Install.php` | Yes | Table names; the `ticketId`/`replyId` foreign keys point at your own tables |
| `src/controllers/InboundController.php` | Yes | `allowAnonymous` + `enableCsrfValidation = false` + 404 when off; nothing else |
| `src/console/controllers/InboundController.php` | Yes | Messages. Keep exit codes: 78 (CONFIG) for "off / no ext-imap", 65 (DATAERR) for a refused import |
| `src/jobs/ProcessInboundEmail.php`, `src/events/InboundEmailEvent.php` | Yes | Namespace; the event's `ticket`/`reply` become your thread/message |
| `Settings`: the `inbound*`, `postmark*`, `mailgun*`, `sendgrid*`, `imap*` properties, their rules, `env()`, `getIgnoredAddresses()`, `validateInboundAddress()` | Yes | — |
| A per-thread `replyToken` column (`char(32)`, unique, lower-case alphabet) + backfill in the migration | Yes | Your element/table |
| `Notifications`: call `prepareOutgoing($message, $thread, $automatic)` on every email to the *customer* | — | Wherever your plugin composes customer mail; `$automatic` only for acknowledgements |
| `templates/settings/_inbound.twig`, `tests/integration/inbound.php`, `inbound-http.php`, `_inbound-fixtures.php`, `tests/fixtures/inbound/*.eml` | Mostly | Fixture senders and what "a ticket" means in the assertions |

### The rules the pattern exists to keep

- **Verify before parse.** `ProviderInterface::verify()` runs on the raw request; nothing is
  decoded or written until it says yes. **Unconfigured = refused (403)**, never "no secret, so
  skip the check". Bad proof = 401. Compare with `hash_equals()` — both halves of basic auth always.
- **Postmark** has no signature: basic auth in the webhook URL is its documented mechanism.
  **Mailgun**: HMAC-SHA256(`timestamp . token`), then the replay window, then the single-use token
  (only after the HMAC passes, so a forger cannot burn tokens). **SendGrid**: ECDSA P-256/SHA-256
  over `timestamp . rawBody`, base64 DER signature, public key accepted as bare base64 or PEM. PHP
  discards the raw body of multipart posts, so the signed route needs `enable_post_data_reading =
  Off` and `MultipartParser` (binary-safe) parses it; the basic-auth fallback is for hosts that
  can't. Document the nginx snippet.
- **Answer fast, work in a job.** `receive()` → `accept()`: dedupe by Message-ID hash (unique
  index; no Message-ID → content hash), rate-limit per sender per hour (rows counted, dropped ones
  recorded), screen attachments (extension allowlist + size + count + **libmagic sniff must agree
  with the extension**), copy kept files to `storage/csr-inbound/` (the request's temp files die
  with it), insert the row, push the job, 200. The job claims the row (`queued` → `processing` by
  conditional UPDATE) so a doubled job does the work once; failures keep the payload for `retry`.
- **Exact-match threading only.** Reply token from the plus-address on the desk's *own* mailbox
  (`Addresses::tokenFrom`), lower-cased then equality + `hash_equals` — never `Db::parseParam`.
  Fallback: SHA-256 of each `In-Reply-To`/`References` id against a table of Message-IDs you sent
  (`prepareOutgoing` sets your own `Message-ID` and records it) and received. Never subject lines,
  never "#1234" in the text. Follow merges.
- **Sender verification.** A reply joins a thread only if `From` equals the thread's customer;
  otherwise new thread or reject (setting). The token is in every email and can be forwarded.
- **Loop guard** before anything else: `Auto-Submitted` ≠ `no`, `Precedence` bulk/list/junk,
  `X-Autoreply`-family, `List-Id`/`List-Unsubscribe`, `MAILER-DAEMON`/`multipart/report`, and
  mail *from* the desk's own address (and its plus variants, and Craft's system sender). Mark your
  own acknowledgements `Auto-Submitted: auto-replied`.
- **HTML is never rendered.** `ReplyParser::htmlToText()` (DOM, drops script/style/blockquote/
  gmail_quote/Outlook's `divRplyFwdMsg` and what follows), then `textToHtml()` escapes.
  `ReplyParser::newText()` strips quotes/signatures for **replies only** — a new email's quoted part
  (a forward) is usually the point.
- **php-imap is optional.** `Inbound::imapAvailable()`; the poll throws a RuntimeException with an
  actionable message and the console exits 78. Mark a message `\Seen`/deleted only after it was
  stored. `import -` (stdin) is the no-extension, no-provider way in.
- **Tests:** no provider is reachable — `_inbound-fixtures.php` builds each provider's real payload
  signed with test secrets (an EC key pair is generated per run for SendGrid); every negative case
  is a fixture with one thing changed. Drive `receive($handle, $request, $now)` then `process($id)`.
  HTTP checks need the settings persisted (fresh-process restore, see `inbound-http.php`).

## Scheduled digests (ported from Yarn)

`services/Digest.php` is Yarn 884cef3's generic half unchanged but for names (table `csr_digests`,
handle `desk`, cache keys `csr:digest:*`), with CSR's `recipients()` (the list plus, optionally,
every agent), `collect()` (`open:<id>` per open ticket, `late:<id>` per breaching one, so a ticket
going late is news), `subject()` and `variables()`. `helpers/Mailer`, `jobs/SendDigest`,
`events/DigestEvent`, the console and web `DigestController`s are Yarn's. The settings live on the
Notifications screen; the test button is a `.formsubmit` with `data-action` (that screen is a
`fullPageForm`), not a second form.

## Conditions, GraphQL, console

- `elements/conditions/TicketCondition` + nine rules. Every rule is registered whatever the
  edition. Rules with a `null` param (a deleted status still in a saved source) add `0=1` rather
  than passing null, which Craft reads as "no filter". `SlaDueWithinConditionRule` has no operator
  and a setter-backed `hours` (Yii assigns configs straight to public typed properties).
- `gql/` — `CsrArticle` (one type, one layout) + `CsrCategory` object type, components
  `csrArticles.all:read` and `csrCategories.all:read`. `ArticleResolver` applies `forUser(false)`,
  enabled and not trashed **after** the arguments, so the result never depends on who is signed in
  (Craft caches GraphQL by schema + query, not user). Return `$query->andWhere('0=1')`, not an
  empty collection — `csrArticle`/`csrArticleCount` call `one()`/`count()` on it.
- `console/controllers/TicketsController` mirrors every CP bulk action through the same service
  methods, takes `--ids/--numbers/--status/--kind` and refuses to run with no selection.

## Traps found while building this

- **`Element::getStatus(): ?string` is a fixed signature.** `Ticket::getStatus(): ?Status` is a
  compile-time fatal the moment the class autoloads — which *disables the whole plugin*, nowhere
  near the call site. It is `getTicketStatus()` for this reason. Same family as `Model::load()`,
  `Model::rules()` and `Model::toArray()`.
- **`yii\base\Controller::render()` is public**, so a `private function render()` on a controller is
  the same fatal. The front-end controllers call theirs `page()`.
- `tests/integration/checks.php` walks every class looking for exactly this class of collision.
  Both were found that way rather than by running the code.
- **Twig resolves `foo.kind` to the *property* before `getKind()`.** `status.kind` is the bare
  string column, so `status.kind.label` compiles and then throws at render time. Hence
  `getKindLabel()`, `getPriorityLabel()`, `getPriorityColor()` and `getSourceLabel()`. **Compiling a
  template proves its syntax; only rendering one proves it works** — which is what
  `tests/integration/screens.php` is for.
- **Yii has no `notIn` validator.** It is `['in', 'not' => true]`. The wrong name is not a
  validation failure but a `NotInstantiableException` thrown the first time anything validates.
- **`$this->uid()` in a migration is `NOT NULL DEFAULT '0'`.** Right for a row's own uid, wrong for
  an optional pointer at another row — `escalateToTeamUid` had to be `char(36)`, or every policy
  without an escalation target failed to insert.
- **A `UniqueValidator` naming two attributes checks them as a *combined* key.** "Same handle,
  different name" passed validation and then failed on the unique index. Two rules, not one.
- **`csr_tickets` and `csr_deflections` point at each other**, so there is no order in which the
  uninstall can drop them. `safeDown()` suspends integrity checks for the duration; without it the
  uninstall fails halfway and leaves a schema that will not reinstall.
- **Uninstalling does not remove a plugin's project config.** Reinstalling then produced ten
  statuses in YAML against five in the database, and site settings whose records never came back —
  because writing a value that is already there is a no-op and fires no handler. `afterUninstall()`
  removes the `csr` path.
- **Set project config one leaf at a time.** Writing the whole `csr.articleSites` array at the
  parent path did not fire the `{uid}` handlers, so the config was stored and the mirrored records
  never created — a plugin that saved your settings and then ignored them.
- **PHP's `/` returns an `int` when both operands are ints and the division is exact.** A perfect
  1/1 deflection rate came back as `int(1)` while 1/3 came back as a float, so anything comparing
  the type disagreed with itself depending on the numbers. Every ratio is cast.
- **Twig has no `repeat` filter.** `|repeat` compiles and throws at render time, on the one ticket
  somebody happened to rate.
- **`StringHelper::toCamelCase()` keeps punctuation**, so "Account & Billing" seeded a category
  whose handle was `account&Billing` — saved with validation off, and refused the first time anybody
  opened it.
- **Status letters render sandboxed** (5.0.1): `Notifications::renderStatusTemplate()` uses
  `renderSandboxedString()` when it exists (Craft 5.9+; CSR allows 5.4) — Airwave's `Trust` pattern.
  It only sandboxes with `enableTwigSandbox` on, which the harness has.
- **CP styling is `web/assets/cp`** (5.0.1), registered from `View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE`
  for any `csr/…` page template and by `DeskWidget` itself. No inline `style=` in CP templates —
  `tests/integration/security.php` scans for them. Craft borders panes with `#content .pane`, so a
  rule that changes a pane's border needs an id in its selector too; and every pane but the first
  gets a top margin, which staggers a grid of them (`#content .csr-stats > .pane`).
- **`FIELD()` is MySQL-only** and `DATE()` is not spelled the same on Postgres; the queue ordering
  is a `CASE` and the ticket trend groups in PHP.
- **Craft's element GraphQL `status`/`trashed` arguments only exist when the schema has
  `elements.inactive:read`**, so a gate test without it proves nothing — `gql.php` adds it for the
  "both" schema. Querying a field the schema lacks *with arguments* can throw a `TypeError` inside
  graphql-php's validator rather than returning `{"data": []}`.
- **`craft\mail\Message::getReplyTo()` returns `''`**, not null, when unset.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-csr/tests/integration/checks.php   # 217 checks
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-csr/tests/integration/screens.php  # every page, over HTTP
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-csr/tests/integration/security.php # 22: sandboxed letters, CP template conventions, test-digest action
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-csr/tests/integration/inbound.php      # 75: parsers, signed provider fixtures, email → tickets/replies
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-csr/tests/integration/inbound-http.php # 14: webhooks over HTTP, csr/inbound/* (persists settings, restores in a fresh process)
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-csr/tests/integration/conditions.php   # 21: every rule against real tickets, query vs matchElement
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-csr/tests/integration/gql.php          # 24: one process per schema
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-csr/tests/integration/digest.php       # 20
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-csr/tests/integration/console.php      # 17: the bulk commands, run for real
docker exec ddev-plugin-testing-web bash -c 'find /var/www/craft-csr/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

`checks.php` is idempotent and self-cleaning: each run builds its own categories, articles, tickets
and policies, prefixes them `chk` so a crashed earlier run is swept up, and removes them at the
end. Nothing sends mail — notifications are switched off through the settings model rather than by
mocking the mailer, so the code takes the branch it takes in production on a site that has them
off. Settings and the edition are changed **in memory only** (`$plugin->edition = …`), because the
harness's project config is contended and persisting from a long script produces
`StaleResourceException` in unrelated code.

`screens.php` needs a control panel login and adapts its assertions to the installed edition. Both
suites were run green against Lite and Pro. To switch:

```php
Craft::$app->getPlugins()->switchEdition('csr', 'pro');
Craft::$app->getProjectConfig()->saveModifiedConfigData();
```

(`plugin/switch-edition` is not a Craft console command.)

Use `docker exec`, not `ddev exec` — see `[[plugin-testing-harness]]`.

## Coding conventions

- `Craft::t('csr', '…')` for user-facing strings; `src/translations/en/csr.php` lists all 800
- Business logic in services; controllers stay thin
- Never mark plugin settings `required` — it breaks a fresh install
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- The front end is progressive enhancement, meant literally: nothing in `csr.js` may *create*
  content, only enhance what the server already rendered
- Anything reachable by an anonymous visitor validates against something the *site* decided, never
  against something the request said
- The visibility gate lives in the query, and internal notes are excluded in the query — a site
  rewriting a template must not be able to leak either
