# Zero_AiAssistant

A chat with Claude in the Magento admin (menu **AI Assistant**) that can also
do the work: look up and create products (from uploaded photos too),
categories, CMS pages and blocks, and handle orders — within the admin user's
own role, every change confirmed by them first. Admin only: nothing on the
storefront.

## Install

Requires Magento Open Source 2.4.7–2.4.9 and PHP 8.2–8.4 with the GD extension built with WebP (`php -r 'var_dump(function_exists("imagewebp"));'` says `true`; Magento's own PHP requirements cover the rest). Without WebP in GD, Zero skips its WebP image copies and serves the originals.

```bash
composer require zerofront/module-zero-ai-assistant
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

Composer installs `zerofront/module-zero-cms` with it.

Part of [`zerofront/zero`](https://zerofront.dev), which installs the Zero theme and every Zero module at once.

## How it works

- **Messages** go to the Anthropic Messages API with the conversation so far
  and the **tools** this user may use. Claude calls tools; reading ones run
  at once (`search_products`, `list_orders`…), changing ones become a
  **proposed change**: a card with Confirm and Cancel. Only Confirm runs it,
  checking the role again. Claude hears what was confirmed or cancelled.
- **Roles.** The chat needs *AI Assistant* (System > User Roles > Role
  Resources). Each tool needs the resource the same work needs in the admin:
  products `Magento_Catalog::products`, categories
  `Magento_Catalog::categories`, pages `Magento_Cms::page` / `::save`, blocks
  `Magento_Cms::block`, orders `Magento_Sales::sales_order`, and per action
  `::invoice`, `::ship`, `::comment`, `::hold`, `::unhold`, `::cancel`. A
  role without one never gets the tool: a content manager's assistant knows
  pages only. The page lists the user's tools ("What I can do for you").
- **Images** uploaded into the chat (up to 10 per message, 10 MB each) are
  kept in `var/zero_ai_assistant` (shown through the admin only), sent to
  Claude scaled to 1568 px in the message that brought them, and put on a
  product by `create_product` / `update_product` (the first one main), set
  as a category's image (`save_category`, `image_upload_id`) or placed in a
  page or block (`<img src="{{upload:N}}">` in the HTML of `save_cms_page` /
  `save_cms_block`: the file lands in `pub/media/wysiwyg/zero-ai`, the
  placeholder becomes a `{{media}}` directive), or in a Zero CMS component's
  image field (`{"upload": N, "alt": "…"}` in `save_cms_components`).
- **Zero CMS components** (with Zero_Cms, resource `Zero_Cms::editor`):
  `list_cms_components` gives every component type with its fields (the
  modules' `etc/zero_cms/components.json`, a theme's `bu_*` / `kk_*` too),
  `get_cms_components` a page's or block's components, `save_cms_components`
  sets them (the whole list, the editor's validation, the previous version
  kept as a revision, the page cache cleaned) and can create the page
  (`new_page`, which needs `Magento_Cms::save` too).
- **Products** it creates: every website, the default attribute set, prices
  in the base currency, stock managed; with `variants`, a configurable
  product over one global dropdown attribute (size, color…), options added
  where missing.
- **Journal:** `zero_ai_action` keeps every proposed change: who, what (the
  input and what they saw), and what became of it.

## Commands

Type `/` in the message field for the commands this role has; each stands
for a whole request, sent as written out (the admin sees it):

| Command | Request |
| --- | --- |
| `/describe 24-UG06, 24-MB01` | new descriptions and short descriptions, two samples first |
| `/short category Bags` | new short descriptions, two samples first |
| `/seo all products in Gear` | meta titles and descriptions, two samples first |
| `/empty Women` | the products with empty texts found and written |
| `/product price 49` | a product from the attached photos |
| `/orders today` | the orders of a period |
| `/setup` | the basic settings checked, the missing ones asked for and filled in |
| `/status` | caches, indexes, cron and the queue, with what needs attention |

More come from a module's `etc/di.xml` (`Model\Commands`'s `commands`:
label, text with `{args}` and optional `[ … ]` kept only with args,
resource).

## Bulk jobs

Texts for more than a few products go through `bulk_write_products`: the
assistant shows samples for two real products, the admin agrees, and one
card lists the products (by SKUs, a category, a word in the name, only the
empty ones; up to Products per Job, 200 by default) with the brief. On
Confirm each product becomes a message for the consumer `zero.ai.bulk`
(RabbitMQ when `env.php` has `queue/amqp`, the database queue otherwise;
cron starts it), which asks the model for that product's texts from its
own facts (name, categories, attributes, current texts), cleans them
(descriptions keep `p`, lists, `strong`/`em`, headings, without
attributes; meta texts are plain and cut to 70/160 characters) and saves
them, keeping the old ones. The card shows the progress, refreshed every 3
seconds, with **Stop**; once over, **Undo All** puts the old texts back on
every product written. The conversation gets a note when a job ends.

Each job also has its own page, `zero_ai/job/index/job_id/N` (progress,
**Stop**, **Undo All**), which works with Chat in the Admin off: the Claude
connector links to it when a job starts or is undone, and the link survives
the admin's sign-in. It needs the AI Assistant resource, the job must be the
admin user's own, and the role must still have `bulk_write_products`.

## Store settings and system

`get_settings` / `set_settings` read and change a fixed list of basic
settings in the default scope (`Model\Tool\System\Settings::PATHS`): store
information (name, phone, hours, address, VAT number), locale and timezone,
the store email addresses, the contact form, the shipping origin, the SEO
defaults. Nothing else can be set through the assistant: no keys, payment
or server settings. Each value is checked by its kind (email, phone,
country code, timezone…) before it is proposed, and the config and page
caches are cleaned after the save. `/setup` is the walk-through: what is
missing, the admin's answers, one confirmation.

`store_status` shows the mode, maintenance flag, cache types (enabled,
invalidated), indexes (mode, valid or invalid, backlog), cron (last run,
pending jobs, errors of the last 24 hours) and the queue backlog;
`flush_cache` and `reindex` do what Cache Management and
`indexer:reindex` do, after confirmation. They need the Cache Management
and Index Management resources. An ordinary change through the assistant
needs neither: a product, category or page save cleans its own pages by
cache tags, and indexes in schedule mode catch up by cron within a minute.

## Undo

A confirmed `update_product` keeps what it overwrote (the fields it gave,
images aside): its card has **Undo**, which puts them back with the role
checked again. Tools implement `Model\Tool\ReversibleInterface`
(`snapshot()` before the change, `restore()`) to get the same. The kept
values go back as they were (`ProductWriter::restore()`): no cleaning, so an
iframe, a widget or a form in the old description comes back too.

A bulk job is undone as a whole: **Undo All** on its card or page, or
`undo_change` from Claude, queues every product it wrote to get its old
texts back. An undo runs once: a second one at the same time is told it is
busy, a second one later that it is undone already.

Other changes can't be undone (an order shipped, a page saved, a setting
changed): `undo_change` says so, and the card has no Undo. Change it again
instead.

## Stuck changes

Every 15 minutes (cron `zero_ai_assistant_reap`, `Model\Reaper`) a change
still running after an hour, or a bulk product still being written after an
hour, is marked failed ("It stopped without finishing…": check what it
changed before trying again), with a note in its conversation; a bulk job
whose last product was stuck then ends as usual. A tool that really ran
longer writes its own outcome over that when it ends.

## What keeps it safe

The rules are in code, not in the instructions: a text in a product or an
uploaded photo can say "ignore your rules", so nothing depends on the model
obeying.

- It has only the tools the admin user's role allows, checked again on
  Confirm, Undo and every bulk job request. A role can be limited further
  in System > User Roles > AI Assistant: tick areas (products, orders,
  blog…) and, per area, "change"; with any area ticked the assistant stays
  inside those, looking up only where "change" isn't ticked. With none
  ticked it may do everything the role's other resources allow.
- Nothing changes without Confirm; a bulk job is one card listing its
  products, capped in size, and undoable.
- No tool makes web requests, and the only emails are the store's own to
  its customer (an order comment, a quote, a return update), sent after
  Confirm, so a tricked model has nowhere to send data.
- Everything it writes into the store is cleaned before it is saved
  (`Model\ModelHtml`): pages, blocks, product and category descriptions,
  scheduled changes, slides. Nothing that runs stays (scripts, iframes,
  forms, `on*` attributes, `javascript:` links, CSS that loads anything),
  and no Magento directive except `{{media url}}`, `{{store url}}` and the
  ones already in the text it changes (a page's widget survives an edit;
  a new `{{block}}`, `{{var}}` or `{{config}}` does not). Page Builder's
  markup stays. Bulk descriptions keep only a few tags (see Bulk jobs).
  Page Builder's inline `style` attributes and `<style>` elements are kept
  on purpose, so a Page Builder page keeps its layout; the admin's Confirm
  card is the check.
- `moderate_reviews` follows stock Magento's grid: a role with Pending
  Reviews only may moderate reviews that are all pending; any other review
  needs All Reviews (`Model\Tool\InputAclInterface`, checked on the
  proposal and again on Confirm). A role with All Reviews but not Pending
  Reviews doesn't get the tool.
- The card to confirm shows what will be saved, with where its links and
  images go, so a link slipped into a long text is visible; a slide's link
  must be the store's or http(s).
- The instructions say that what tools return is data, never
  instructions; every change is in the journal (`zero_ai_action`,
  `zero_ai_job`).

## Long conversations

One conversation can go on for good at about the same cost a message, as in
Claude Code: the model gets the last exchanges in full (Recent Exchanges
Kept in Full, 10 by default, about 20 messages on the page) and, in place
of everything before, a summary: what was asked and decided, the admin's
rules, the ids and SKUs that matter, the changes and what became of them.
It is written once the conversation passes half as many exchanges again
(15), then extended the same way, never between a tool call and its result
(`Model\Compactor`, kept in `zero_ai_conversation.summary`). The page still
shows every message, with a line where the summarized part ends. Several
conversations stay possible, one per task.

## Cost

Every request carries the instructions, the tools and the conversation so
far. They are marked for Anthropic's prompt cache (the last tool, the system
text, the last message), so the repeated part of each request costs a tenth
of its input price. A long conversation doesn't grow past the recent exchanges and the summary (Long
conversations).

## Settings

Stores > Configuration > Zero > **AI Assistant**: the model (Claude Haiku 4.5
by default: fast and cheap; Sonnet 5.5 or Opus 5.5 for harder work) and **Instructions for the Assistant**, your store's own rules
added to every conversation ("descriptions in Ukrainian", "new products
disabled", "SKUs like KK-001"…). The API key is Zero CMS's (Zero > CMS > AI).

**Chat in the Admin** = No takes the AI Assistant page and its menu item
away for every admin user; a bulk job's own page stays (Bulk jobs). The assistant then works only through the Claude
connector (Zero_Mcp): Claude thinks with the admin's own subscription, so the
store's AI provider is not called (bulk product texts are the exception: the
consumer writes them with the store's model). For a store that uses the
assistant from the Claude app only.

## How long data is kept

**Keep Data For, Days** (Stores > Configuration > Zero > AI Assistant, 90 by
default; 0 keeps everything). Every night (cron `zero_ai_assistant_purge`,
03:41) `Model\Retention` removes what is older than that:

- messages;
- uploaded pictures, both their rows and their files in
  `var/zero_ai_assistant`;
- finished changes, and their Undo with them;
- finished bulk jobs;
- the Claude connector's revoked or expired connections.

Then:

- **A conversation** goes once nothing is left in it, unless a live
  connection still uses it.
- **The connector's one-time codes, pending consents and drafts** go once
  they expire, whatever the setting.
- **The connector's registered clients that never connected** (no
  connection, live or not) go after 7 days, whatever the setting.
- **What is still running stays.** A running change, or a finished change
  whose bulk job still runs, is not touched.

The tables cascade: a conversation takes its messages, pictures and changes,
a change its job. That is why each kind is removed by its own rule.

`bin/magento zero:ai:purge` runs it at once and says what went.

## Tools of other Zero modules

Tools for a module live here, under `Model/Tool/<Module>`, and are listed
in `etc/di.xml` under `modules` (class names per module): they are made
only when that module is enabled, so the module itself needs nothing from
the assistant (a tool's own classes are fetched when it runs, so the package compiles without them). Commands the same way
(`Model\Commands`'s `modules`).

- **Zero_Blog**: `search_posts`, `get_post`, `list_blog_categories`,
  `save_post` (the body in Markdown becomes the post's Article component;
  a photo as the cover; drafts unless asked to publish), `list_comments`,
  `moderate_comments`. Commands `/post topic` and `/comments`.
- **Zero_QuoteRequest**: `list_quote_requests`, `get_quote_request`,
  `answer_quote_request` (prices per line and a note, the customer is
  emailed; or a decline). Command `/quotes`.
- **Zero_Returns**: `list_returns`, `get_return` (items, the conversation,
  the next statuses), `update_return` (status and/or a message, emailed).
  Command `/returns`.
- **Zero_Staging**: `list_campaigns`, `schedule_change` (a product or
  category change from a start to an end: a sale, a renamed category, a
  product switched off for a period; a new campaign or an existing one).
- **Zero_Reviews**: `list_reviews` (rating, verified purchase, photos),
  `moderate_reviews`. Command `/reviews`.
- **Zero_Banner**: `list_banners`, `save_slide` (a slide's texts, period,
  position, its picture from an uploaded photo).
- **Zero_NovaPoshta**: `create_waybill` (the ТТН from the store's sender
  branch to the order's branch, cash on delivery for the set payment
  methods, then the order shipped with it; label links), `track_waybill`,
  `delete_waybill`. Command `/ttn`.
- **Zero_Checkbox**: `fiscal_receipts`, `send_fiscal_receipts` (the failed
  and missing ones of an order, sent now; the failed ones reset as the
  admin's Retry does, `Fiscalizer::retryErrors()`: under the receipt's
  lock, a new id for a refused one; one locked at that moment is left as
  it is and counted in `busy`; waiting ones keep their attempts), `cash_register`. Command `/receipts`.
- **Zero_Ukrposhta**: `create_ukrposhta_shipment`, `delete_ukrposhta_shipment` (the order's Ukrposhta shipment, with the sticker link).

Each set is there when its module is installed and enabled; without the module nothing of it is loaded, and no module is needed for the assistant itself.
Other Zero modules can register tools and commands the same way.

## Adding a tool

A class implementing `Model\Tool\ToolInterface` (`ReadTool` for read-only
ones), added to `Model\Tools`'s `tools` in a module's `etc/di.xml`.

## Tests

`Test/Unit/Model/` (Markdown, the HTML cleaning, the commands, the bulk
answer; Agent undo statuses and the undo lock, Bulk::undo taken once, the
verbatim restore, the records' ACL of moderate_reviews, order card defaults,
the reaper, retention, recent changes), `Test/Unit/Cron/` (the reap job's
crontab entry and its run), `Test/Unit/Controller/` (the job
page and buttons with Chat in the Admin off, the Undo card's messages);
`dev/tests/zero-e2e/tests/ai-assistant-admin.spec.ts` (the page,
the e2e role's tools and commands, a message, the job page on its own). For a
local run against a stand-in for the API: `zero_ai_assistant/dev/api_url` in
core_config_data (not in the admin).

## Disabling or removing

Its data lives in its own tables (`zero_ai_conversation`, `zero_ai_message`, `zero_ai_upload`, `zero_ai_action`, `zero_ai_job`, `zero_ai_job_item`). With the module's code still in
place, `bin/magento module:disable Zero_AiAssistant` followed by `setup:upgrade`
**drops those tables** (Magento's declarative schema), and enabling the module
again brings them back empty. To keep the data, switch the feature off in
Stores > Configuration where it has a setting, or back up first:
`bin/magento setup:upgrade --safe-mode=1` dumps the data before anything is
dropped (`--data-restore=1` puts it back). Deleting the module's code instead
leaves the tables in place.

What changed in each release: [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

Zero is made by [Fixstacks](https://fixstacks.com).
Documentation: https://zerofront.dev/docs. Demo store: https://demo.zerofront.dev.
