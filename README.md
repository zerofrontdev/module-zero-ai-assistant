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
resource, `sort_order`; see "Adding a tool").

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

The tools for another Zero module live in a **bridge module** next to it,
`Zero_<Module>AiAssistant`, not here. Zero_AiAssistant names no class of
another module, and neither the assistant nor the module needs the other:
a store installs the bridge when it uses both. A bridge sequences
Zero_AiAssistant and its module in `module.xml` (and requires both
packages in composer.json), and adds its tools and commands to the pools
below in its own `etc/di.xml`.

| Bridge | Tools | Command |
| --- | --- | --- |
| Zero_BlogAiAssistant | `search_posts`, `get_post`, `list_blog_categories`, `save_post` (the body in Markdown becomes the post's Article component; a photo as the cover; drafts unless asked to publish), `list_comments`, `moderate_comments` | `/post topic`, `/comments` |
| Zero_QuoteRequestAiAssistant | `list_quote_requests`, `get_quote_request`, `answer_quote_request` (prices per line and a note, the customer is emailed; or a decline) | `/quotes` |
| Zero_ReturnsAiAssistant | `list_returns`, `get_return` (items, the conversation, the next statuses), `update_return` (status and/or a message, emailed) | `/returns` |
| Zero_ReviewsAiAssistant | `list_reviews` (rating, verified purchase, photos), `moderate_reviews` | `/reviews` |
| Zero_BannerAiAssistant | `list_banners`, `save_slide` (a slide's texts, period, position, its picture from an uploaded photo) | |
| Zero_StagingAiAssistant (licensed) | `list_campaigns`, `schedule_change` (a product or category change from a start to an end: a sale, a renamed category, a product switched off for a period) | |
| Zero_NovaPoshtaAiAssistant (licensed) | `create_waybill` (the ТТН from the store's sender branch to the order's branch, cash on delivery for the set payment methods, then the order shipped with it; label links), `track_waybill`, `delete_waybill` | `/ttn` |
| Zero_UkrposhtaAiAssistant (licensed) | `create_ukrposhta_shipment`, `delete_ukrposhta_shipment` (the order's Ukrposhta shipment, with the sticker link) | |
| Zero_CheckboxAiAssistant (licensed) | `fiscal_receipts`, `send_fiscal_receipts` (the failed and missing ones of an order, sent now; the failed ones reset as the admin's Retry does, `Fiscalizer::retryErrors()`: under the receipt's lock, a new id for a refused one; one locked at that moment is left as it is and counted in `busy`; waiting ones keep their attempts), `cash_register` | `/receipts` |

The Zero_Cms component tools (`list_cms_components`, `get_cms_components`,
`save_cms_components`) stay here: Zero_Cms is a hard dependency.

Tool names, ACL resources and areas are the ones the tools had before they
moved, so roles, stored proposals and the connector keep working. The area
resources `Zero_AiAssistant::area_*` stay in this module's `acl.xml`.

## Adding a tool

A class implementing `Model\Tool\ToolInterface` (`ReadTool` for read-only
ones). The contract for tool authors is marked `@api`: `ToolInterface`,
`ReadTool`, `Context`, `InputAclInterface`, `ReversibleInterface`,
`Tool\Sales\Orders`, `Uploads` and `ModelHtml`.

A tool for one of this module's own areas is added to `etc/di.xml` here.
A tool for **another module** goes into a new bridge module
`Zero_<Module>AiAssistant`, copied from an existing one (for example
`Zero_ReturnsAiAssistant`), with plain constructor injection of that module's
services (no ObjectManager):

1. `module.xml`: `<sequence>` of `Zero_AiAssistant` and the module.
   composer.json: both packages in `require`.
2. `etc/di.xml`, in `Zero\AiAssistant\Model\Tools`:
   - `tools`: one `<item name="tool_name" xsi:type="object" sortOrder="N">Vendor\Bridge\Model\Tool\X\Proxy</item>`
     per tool. The `\Proxy` keeps a tool of a disabled module from ever being
     built. `sortOrder` is the position in the list the model and the admin
     see; it must be unique across **all** modules (the groups use
     1010-1930 in steps of 10, core tools 10-260; take a free number).
   - `requires`: `<item name="tool_name" xsi:type="string">Vendor_Module</item>`.
     The tool shows only while that module is enabled. It is needed because
     in an `app/code` install nothing stops a bridge from being enabled
     while its module is off.
3. Commands (optional), in `Zero\AiAssistant\Model\Commands`: `commands`
   items with `label`, `text` (`{args}` and `[ ... ]` as above), `resource`,
   `area`, `changes`, `example`, and `sort_order`; `requires` maps the
   command to the module. **Order:** a command without `sort_order` comes
   first and can override a built-in one with the same name; a command with
   a positive `sort_order` comes after the built-in ones, in that order.
4. Register the module in `app/etc/config.php` with the same value as the
   module it bridges.

`Test/Unit/Model/ToolPoolTest.php` and `PoolOrderTest.php` read every
module's `di.xml` and fail on a `requires` key that is no tool, a repeated
`sortOrder`, a tool class that does not exist, a bridge that does not
sequence its module, or a class of another Zero module named in this
module. `Zero_Core`'s `NoObjectManagerTest` fails on any use of the
ObjectManager outside its allow-list.

## Tests

`Test/Unit/Model/` (the tool and command pools: ToolsTest, PoolOrderTest, ToolPoolTest; Markdown, the HTML cleaning, the commands, the bulk
answer; Agent undo statuses and the undo lock, Bulk::undo taken once, the
verbatim restore, the records' ACL of moderate_reviews, order card defaults,
the reaper, retention, recent changes), `Test/Unit/Cron/` (the reap job's
crontab entry and its run), `Test/Unit/Controller/` (the job
page and buttons with Chat in the Admin off, the Undo card's messages);
`dev/tests/zero-e2e/tests/ai-assistant-admin.spec.ts` (the page,
the e2e role's tools and commands, a message, the job page on its own). For a
local run against a stand-in for the API: `zero_ai_assistant/dev/api_url` in
core_config_data (not in the admin).

PHPUnit 12 reads data providers from the `#[DataProvider('name')]` attribute
(`PHPUnit\Framework\Attributes\DataProvider`) on a `public static` method,
not from the old `@dataProvider` annotation; write new ones that way.

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
