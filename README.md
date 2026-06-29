# WC GitHub Publisher

> Publish GitHub release assets — including from **private** repositories — as native WooCommerce downloadable product files.

[![Tests](https://github.com/bdecentgmbh/wordpress_wc-github-publisher/actions/workflows/tests.yml/badge.svg)](https://github.com/bdecentgmbh/wordpress_wc-github-publisher/actions/workflows/tests.yml)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](LICENSE)

WC GitHub Publisher automates a single step: taking a GitHub release asset and
attaching it to a WooCommerce product as a downloadable file. Everything *after*
that — purchase/subscription entitlement, the **My Account → Downloads** page,
download permissions and secure file serving — is handled by WooCommerce itself.
**The plugin is never in the customer download path.**

This is ideal when you distribute software (for example Moodle plugins) as GitHub
release `.zip` assets in private repositories and sell access through a
WooCommerce shop.

---

## Features

- **Private repositories supported.** A fine-grained Personal Access Token reads
  your releases. The plugin follows GitHub's signed-URL redirect *server-side* and
  stores the file locally, so the token and signed URL are never exposed to
  customers.
- **Token stored encrypted at rest** (libsodium, with an OpenSSL fallback). The
  key is derived from your WordPress salts, so a database dump alone does not
  reveal it.
- **Multi-repository bundles.** A product can publish from several repositories
  at once. The download becomes a single zip containing one release asset per
  repo plus an auto-generated `INSTALL.md`, and its name ends in `— UNZIP ME` so
  customers unpack it before installing into Moodle. A single-repo product is
  attached directly, with no wrapping. Install paths in INSTALL.md are derived
  from each repo name (`moodle-{type}_{name}` → the Moodle plugin directory),
  with a per-repo override.
- **Simple, variable and variable-subscription products.** Publish to a whole
  product, to all variations, or to the variations matching an attribute value
  (e.g. `Platform = Moodle`).
- **Auto-coverage of new variations.** A newly created variation that matches an
  existing mapping is covered automatically on save.
- **Source-zip fallback.** GitHub's auto-generated *Source code (zip)* is offered
  for every release, so releases without uploaded assets are still publishable.
- **Friendly file names.** Downloads are named after the product title and
  release version (e.g. `Media Time 1.1 R3`) rather than the raw asset filename.
- **Retention pruning.** Keep the latest *N* versions per product; older managed
  files are detached and deleted automatically (manually added files are never
  touched).
- **Rate-limit & error awareness.** Release listings are cached and revalidated
  with an ETag; admin notices warn about auth/token-expiry errors and quota
  exhaustion.
- **HPOS compatible** (High-Performance Order Storage).

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.4 |
| WooCommerce | active |
| PHP | 7.4 |

## Installation

1. Copy the `wc-github-publisher` directory into `wp-content/plugins/` (or install
   the packaged `.zip` via **Plugins → Add New → Upload**).
2. Activate **WC GitHub Publisher**.
3. No build step is required — the plugin ships with a lightweight PSR-4
   autoloader and runs without `composer install`. (`composer install` is only
   needed for development and tests.)

## Usage

1. **Add a token.** Go to **WooCommerce → GitHub Publisher** and paste a
   fine-grained Personal Access Token with read-only **Contents** access to your
   release repositories. Optionally set a default organization/owner so you can
   enter just a repo name on products.
2. **Link a product.** Edit a product, open the **GitHub** tab, add one or more
   `owner/repo` repositories (or just the repo name when a default owner is set),
   mark one as **primary**, and save the product.
3. **Publish.** Click **Load releases**, pick a release per repository (latest by
   default), and click **Publish bundle**. For variable products, pick which
   variations should receive the bundle first.
4. The assets are downloaded server-side into WooCommerce's protected uploads
   directory. A single repository is attached as-is; several are wrapped into one
   `… — UNZIP ME.zip` with an `INSTALL.md`. Older versions are pruned to the
   configured limit (default 3).

### What it does **not** do

- It does not build a custom download endpoint, My Account tab, or entitlement
  logic — WooCommerce owns all of that.
- It does not provide in-product auto-updates.

## How access is controlled

Entirely by WooCommerce. Whoever WooCommerce grants download permission to
(through an order or a WooCommerce Subscription) can download the file; everyone
else cannot. The plugin only performs the publish step.

## Development

```bash
composer install      # install dev dependencies (PHPUnit)
composer test         # run the unit test suite
```

The tests are **pure unit tests**: rather than boot a full WordPress +
WooCommerce + MySQL stack, [`tests/bootstrap.php`](tests/bootstrap.php) stubs the
handful of WordPress functions the units under test touch, and the tests exercise
the plugin's own logic — download naming, repository normalization, token
encryption round-trips, and variation/attribute matching. They run anywhere PHP
and Composer are available, and on every push via GitHub Actions.

### Project layout

```
wc-github-publisher.php      Bootstrap: constants, autoloader, HPOS, textdomain
src/
  Plugin.php                 Wires the admin pieces together
  Publisher.php              Download → package → attach to target(s) → prune
  Repos.php                  Product repo list (normalize, primary, back-compat)
  Targets.php                Resolve publish targets (product / variations)
  Status.php                 Last-error + rate-limit snapshot store
  Moodle/ComponentMap.php    Repo name → Moodle component + install directory
  Bundle/Packager.php        Assemble component zips + INSTALL.md into one zip
  Bundle/InstallDoc.php      Render the bundled INSTALL.md
  GitHub/Client.php          REST client: list releases, read asset metadata
  GitHub/AssetDownloader.php Stream a (private) asset to uploads or a temp file
  Security/TokenStore.php    Encrypted token storage + settings
  Admin/SettingsPage.php     Token & options screen
  Admin/ProductGitHubTab.php Product "GitHub" tab + AJAX endpoints
  Admin/Notices.php          Admin error/rate-limit notices
tests/                       PHPUnit unit tests + bootstrap
```

## Roadmap

- **Email notification on publish** — optionally notify a configurable email
  address when a new release is published. (Planned for the next release.)

## License

[GPL-2.0-or-later](LICENSE) © [bdecent gmbh](https://bdecent.de)
