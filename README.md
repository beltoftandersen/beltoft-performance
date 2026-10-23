# Beltoft Performance

Makes WordPress load only what each kind of page uses. A **check** watches which plugins and files every page type
actually needs and proposes rules ("relevanssi isn't used on the product page"). The rules are tested, and **you
approve each one**. Once approved, a small must-use loader removes those plugins for that page type *before*
WordPress loads them, and the plugin drops the unused scripts and styles from the page.

Nothing is switched off without a rule you accepted. With any doubt (an unknown page type, a missing or broken rule
file, the kill switch), the loader changes nothing.

## How it works

1. **Check:** `wp beltoft-performance check` renders every kind of page on the site (front page, shop, categories,
   tags, brands, product, blog, search, page, post, cart, checkout, account) with a signed header. While it does,
   the Recorder notes which plugins and files the page touches. Logged-in and cart states are checked through a QA
   customer the check configures for itself.
2. **Proposals:** the result is a list of candidate rules, such as "unload slim-seo on shop, categories, blog…".
   You find them in *Settings › Performance* and in `wp beltoft-performance status`.
3. **Trial and browser test:** a candidate can be tried (`trial`) on its pages, and checked in a real browser
   (`browser`, Playwright). The page must render the same layout with no JavaScript errors and no failed requests.
4. **Accept or reject:** an accepted rule is compiled into `wp-content/uploads/beltoft-performance/rules.php` (or
   under `UPLOADS`). Rejected rules aren't proposed again.
5. **Loader:** the mu-plugin `beltoft-performance-loader.php`, installed by the plugin from its `mu/` folder, reads
   only that compiled file. For each front-end request it removes the plugins its rule names for that page type.
   It never acts on admin, AJAX, REST, XML-RPC or cron requests.
6. **Assets:** scripts and styles a page type doesn't use are dequeued (`Assets\Suppressor`), with their
   dependencies respected (`Assets\Graph`).

Plugins that must never be removed are protected: WooCommerce, this plugin, and the page cache, security, e-mail
and analytics plugins (the list is in `src/Rules/Store.php`). Switching a plugin off by hand on cart, checkout,
order received or account requires `--tested-by-hand`, unless the rule passed a trial and a browser test there.

## Requirements

- WordPress with WooCommerce, PHP **8.3+**, WP-CLI.
- For browser tests: Node.js with Playwright (`playwright-core` and a Chromium). Pass their paths with `--node` /
  `--pw` if they aren't found automatically. The browser is found as: `--chrome=<path>`, then `BP_CHROME`, then Playwright's own
  Chromium, then `/usr/bin/google-chrome`, `/usr/bin/chromium`, `/usr/bin/chromium-browser`; otherwise the test stops
  with a message naming `--chrome=` and `BP_CHROME`.
- The server must be able to fetch its own pages (the check requests them over HTTP).

## Setup

1. Copy the plugin to `wp-content/plugins/beltoft-performance/` and activate it. Activation installs the loader into
   `wp-content/mu-plugins/` (`Loader\LoaderManager`). When activated or run as root (`--allow-root`), the plugin hands
   `uploads/beltoft-performance` to the uploads folder's owner; `check` stops with a clear error if that folder isn't writable. Deactivating removes the loader again.
2. Run a first check: `sudo -u www-data wp beltoft-performance check`.
3. Review the proposals in *Settings › Performance*, or with `wp beltoft-performance status`.
4. Test and accept rules one at a time (see Commands). Clear the page cache afterwards.

**Kill switch:** `define( 'BELTOFT_PERFORMANCE_DISABLED', true );` in `wp-config.php` makes the loader and the
plugin change nothing, without deactivating anything.

## Commands

```bash
wp beltoft-performance status                        # loader version, rule count, last check, open candidates
wp beltoft-performance rules                         # the accepted rules
wp beltoft-performance check [--pages=<list>] [--states=<list>] [--new]
                                                     # check every page type, or only the listed ones
wp beltoft-performance trial <target> [--pages=<list>]
wp beltoft-performance browser <target> [--node=<path>] [--pw=<path>] [--chrome=<path>]
wp beltoft-performance usage [--pages=<list>] [--states=<list>] [--node=<path>] [--pw=<path>] [--chrome=<path>]
wp beltoft-performance accept <target> --by=<login> [--pages=<list>]
wp beltoft-performance reject <target>
wp beltoft-performance switch-off <target> --scope=<scope>[,<scope>] --by=<login> [--tested-by-hand] [--override-protection]
wp beltoft-performance switch-on  <target> --scope=<scope>[,<scope>]
wp beltoft-performance db-cleanup [--dry-run]        # old revisions, auto-drafts, spam/trash comments, expired transients
wp beltoft-performance import-perfmatters --by=<login> [--dry-run] [--force] | --undo [--dry-run]
```

`<target>` is a rule such as `unload:relevanssi` (a whole plugin) or a file target as `status` prints it. Scopes are
page types: `front`, `shop`, `archive_cat`, `archive_tag`, `archive_brand`, `product`, `blog`, `search`, `page`,
`post`, `cart`, `checkout`, `order_received`, `account`.

`import-perfmatters` converts Perfmatters' Script Manager removals into manual rules before Perfmatters is switched
off. It is refused unless those removals are live; `--undo` removes them again.

The database clean-up also runs nightly (*Settings › Performance › Database*).

## Tests

Pure unit tests, no WordPress needed (`tests/bootstrap.php` stubs the few functions the pure classes use):

```bash
cd wp-content/plugins/beltoft-performance && php <path-to>/phpunit
```

JS: `node --test tests/js`.

The `bin/` scripts are the Playwright helpers the `browser` and `usage` commands run (`browser-check.js`,
`cart-check.js`, `layout-key.js`, plus `chrome-path.js`, the browser lookup). The signed check header reaches them through the environment
(`BP_CHECK_HEADER`), never the command line.

## Troubleshooting

| Symptom | What to do |
|---|---|
| A page breaks after accepting a rule | `wp beltoft-performance switch-on <target> --scope=<page type>`, or the kill switch, then purge the page cache |
| `status` says the loader isn't installed | Reactivate the plugin; the loader file is copied from `mu/` |
| Proposals include a plugin that's obviously needed | Reject it; check runs can miss rare states (a coupon, a logged-in-only widget) |
| Browser test can't start | Node or Playwright not found: pass `--node=` and `--pw=`. No Chrome found: pass `--chrome=` or set `BP_CHROME` |
| Check says the uploads folder isn't writable | `chown -R www-data: wp-content/uploads/beltoft-performance` |

## Authorship

Written by beltoft.net. Licence: GPL-2.0-or-later.
