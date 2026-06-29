=== WC GitHub Publisher ===
Contributors: bdecent
Tags: woocommerce, github, releases, downloadable, digital products
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish GitHub release assets (including from private repositories) as native WooCommerce downloadable product files.

== Description ==

WC GitHub Publisher automates a single step: taking a GitHub release asset and
attaching it to a WooCommerce product as a downloadable file. Everything after
that — purchase/subscription entitlement, the My Account → Downloads page,
download permissions and secure file serving — is handled by WooCommerce itself.
The plugin is never in the customer download path.

This is ideal when you distribute software (for example Moodle plugins) as
GitHub release `.zip` assets in private repositories and sell access through a
WooCommerce shop.

= How it works =

1. Add a fine-grained GitHub Personal Access Token (read-only "Contents") under
   WooCommerce → GitHub Publisher. It is stored encrypted.
2. Edit a product, open the **GitHub** tab, and enter the `owner/repo`.
3. Click **Fetch releases**, then **Publish** on the asset you want to sell.
4. The asset is downloaded server-side into WooCommerce's protected uploads
   directory and added as a downloadable file on the product. Older versions are
   pruned to the configured limit (default 3).

= What it does NOT do =

* It does not build a custom download endpoint, My Account tab, or entitlement
  logic. WooCommerce owns all of that.
* It does not provide in-product auto-updates.

== Frequently Asked Questions ==

= Are private repositories supported? =

Yes. The token-authenticated request follows GitHub's signed-URL redirect
server-side and stores the file locally, so the token and signed URL are never
exposed to customers.

= Do variable and subscription products work? =

Yes. For variable and variable-subscription products you publish an asset to the
variations that should receive it by choosing an attribute value (for example
Platform = Moodle), or "All variations". The file is attached to each matching
variation's downloadable files, and newly created variations matching an existing
mapping are covered automatically on save.

= How is access controlled? =

Entirely by WooCommerce. Whoever WooCommerce grants download permission to
(through an order or a WooCommerce Subscription) can download the file; everyone
else cannot.

= What about very large assets? =

Assets are streamed to disk rather than buffered in memory. For very large files
you may need to raise your web server's proxy/FastCGI timeouts.

== Changelog ==

= 0.4.1 =
* Fix release ordering: the GitHub list endpoint can omit or mis-order the actual
  latest release. The plugin now also queries /releases/latest, merges it in, sorts
  releases by publish date (newest first), and badges the latest release.

= 0.4.0 =
* Offer GitHub's auto-generated source zip ("Source code (zip)") for every
  release, alongside any uploaded assets — so Moodle plugin releases without
  uploaded assets are publishable. Delivered as-is.
* Default organization/owner setting: enter just a repo name on a product and the
  configured owner (e.g. bdecentgmbh) is prepended automatically.

= 0.3.0 =
* Variable & variable-subscription product support: publish assets to variations.
* Target by attribute value (e.g. Platform = Moodle) — covers all matching
  variations (any subscription period), or choose "All variations".
* Newly created variations are auto-covered by existing mappings on save.
* Files are downloaded once and shared across matching variations; removal/pruning
  deletes a file from disk only when no variation still references it.
* "Currently published" now lists each publish with its target and variation count.

= 0.2.0 =
* Published-state indicators: the GitHub tab marks assets already published to the
  product and lists currently published files with a Remove button.
* Admin notices for GitHub auth/token-expiry errors and rate-limit exhaustion,
  with a link to update the token.
* Rate-limit awareness and a cached "Fetch" plus an explicit "Refresh" that forces
  a fresh pull; the tab shows cache age and remaining API quota.
* Multi-asset publish: select several assets in a release and publish them at once.

= 0.1.0 =
* Initial release: GitHub token settings, product GitHub tab, fetch releases,
  publish an asset as a WooCommerce downloadable file, retention pruning.
