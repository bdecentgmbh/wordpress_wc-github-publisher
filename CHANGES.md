# Changelog

All notable changes to **WC GitHub Publisher** are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Project documentation and developer tooling: `README.md`, this changelog, a
  `LICENSE` file (GPL-2.0), and a PHPUnit unit-test suite with CI.

### Changed
- Downloadable files are now named after the **product title** and the formatted
  release version (e.g. `Media Time 1.1 R3`) instead of the raw asset filename
  and tag. The stored file uses the same name with dashes
  (`Media-Time-1.1-R3.zip`), since WordPress sanitizes spaces out of upload
  filenames.

### Planned
- Optional **email notification** to a configurable address when a new release is
  published.

## [0.4.1]

### Fixed
- Release ordering: the GitHub list endpoint can omit or mis-order the actual
  latest release. The plugin now also queries `/releases/latest`, merges it in,
  sorts releases by publish date (newest first), and badges the latest release.

## [0.4.0]

### Added
- Offer GitHub's auto-generated source zip (*Source code (zip)*) for every
  release, alongside any uploaded assets — so Moodle plugin releases without
  uploaded assets are publishable. Delivered as-is.
- Default organization/owner setting: enter just a repo name on a product and the
  configured owner (e.g. `bdecentgmbh`) is prepended automatically.

## [0.3.0]

### Added
- Variable & variable-subscription product support: publish assets to variations.
- Target by attribute value (e.g. `Platform = Moodle`) — covers all matching
  variations (any subscription period), or choose *All variations*.
- Newly created variations are auto-covered by existing mappings on save.
- "Currently published" now lists each publish with its target and variation
  count.

### Changed
- Files are downloaded once and shared across matching variations; removal /
  pruning deletes a file from disk only when no variation still references it.

## [0.2.0]

### Added
- Published-state indicators: the GitHub tab marks assets already published to the
  product and lists currently published files with a Remove button.
- Admin notices for GitHub auth/token-expiry errors and rate-limit exhaustion,
  with a link to update the token.
- Rate-limit awareness and a cached *Fetch* plus an explicit *Refresh* that forces
  a fresh pull; the tab shows cache age and remaining API quota.
- Multi-asset publish: select several assets in a release and publish them at
  once.

## [0.1.0]

### Added
- Initial release: GitHub token settings, product GitHub tab, fetch releases,
  publish an asset as a WooCommerce downloadable file, retention pruning.

[Unreleased]: https://github.com/bdecentgmbh/wordpress_wc-github-publisher/compare/v0.4.1...HEAD
[0.4.1]: https://github.com/bdecentgmbh/wordpress_wc-github-publisher/releases/tag/v0.4.1
[0.4.0]: https://github.com/bdecentgmbh/wordpress_wc-github-publisher/releases/tag/v0.4.0
[0.3.0]: https://github.com/bdecentgmbh/wordpress_wc-github-publisher/releases/tag/v0.3.0
[0.2.0]: https://github.com/bdecentgmbh/wordpress_wc-github-publisher/releases/tag/v0.2.0
[0.1.0]: https://github.com/bdecentgmbh/wordpress_wc-github-publisher/releases/tag/v0.1.0
