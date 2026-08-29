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
- `schema` / `exporter`

### Where the trust boundary is

`SupportController::actionSubmit()` and `Tickets::submit()`. Everything there assumes the request
is hostile: the category is looked up and re-asked whether it accepts tickets; the questions are
enumerated from *configuration* and each pulls its own value out of the request, so a value posted
under an unknown handle is never read; the priority is honoured only if the site said visitors may
choose one; the status is the desk's default; uploads come from the form's own file input; and
every spam refusal returns the identical message.

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
- **`FIELD()` is MySQL-only** and `DATE()` is not spelled the same on Postgres; the queue ordering
  is a `CASE` and the ticket trend groups in PHP.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-csr/tests/integration/checks.php   # 198 checks
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-csr/tests/integration/screens.php  # every page, over HTTP
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

- `Craft::t('csr', '…')` for user-facing strings; `src/translations/en/csr.php` lists all 657
- Business logic in services; controllers stay thin
- Never mark plugin settings `required` — it breaks a fresh install
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- The front end is progressive enhancement, meant literally: nothing in `csr.js` may *create*
  content, only enhance what the server already rendered
- Anything reachable by an anonymous visitor validates against something the *site* decided, never
  against something the request said
- The visibility gate lives in the query, and internal notes are excluded in the query — a site
  rewriting a template must not be able to leak either
