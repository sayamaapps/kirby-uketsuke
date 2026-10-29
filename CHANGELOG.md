# Changelog

All notable changes to this project are documented here. Versions follow
[Semantic Versioning](https://semver.org).

## [1.1.0] — 2026-10-29

### Changed

- Purchases and deliveries are keyed by order and product. Digistore24 sends one
  `on_payment` per product of an order (order bumps, upsells) with the same order id;
  each product now gets its own purchase, `uketsuke.payment:after`, deliveries and
  status. Ledger schema version 2; a version 1 ledger is migrated on first use.
- `delivery()`, `deliveries()` and `recordDelivery()` take a `Purchase`; an order id
  still works while the order has a single product.
- The IPN route reads the form body only, as Digistore24's reference receiver does;
  the query string is no longer part of the signature.
- `uketsuke.refund:after` and `uketsuke.chargeback:after` fire only when the status
  changes, so a notification repeated after the retention period does not fire twice.
- The bearer scheme of the protocol and retry routes is case-insensitive.
- Internal ledger methods moved to `Notifications` (`Ledger::notifications()`); the
  removed methods were never documented.

### Added

- Hooks that never ran to the end (fatal error, time limit) fire again when
  Digistore24 repeats the notification, instead of the repetition counting as a duplicate.
- `Ledger::order()` lists every product of an order; `purchase()` takes an optional
  product id.
- Retry route: `failed` lists hooks that threw; `limit` and `after` page through a
  channel (`next` cursor); status `missing` selects paid purchases without a delivery.
- Protocol route: `hooks_pending` per purchase.
- The IPN route refuses bodies that are not a form (415) or larger than 64 KB (413).

### Fixed

- The retry route reported success when the hook threw.
- An empty `path` option (`'/'`) put the receiver on the home page; it now falls back
  to the default.
- E-mail addresses in the `meta` of `recordDelivery()` are removed like those in `$error`.
- Storage configuration errors are logged with their reason, not only the class name.
- Missing `api_mode` counts as live; the documentation said otherwise.

## [1.0.0] — 2026-10-29

### Added

- IPN receiver at `{path}` (default `uketsuke/digistore24`): signature check
  (sha_sign, SHA-512), answers `OK`, `INVALID_SIGNATURE`, `STORAGE_ERROR` and
  `NOT_CONFIGURED`.
- Idempotency per order, event and transaction: duplicates are stored and answered
  `OK` without hooks.
- Purchase ledger in SQLite (`purchases`, `notifications`, `deliveries`), WAL mode,
  schema version in `PRAGMA user_version`, storage outside the web root enforced.
- Hooks `uketsuke.payment:after`, `uketsuke.payment:repeat`, `uketsuke.refund:after`,
  `uketsuke.chargeback:after` and `uketsuke.notification:after`.
- Protocol route `{path}/protokoll` (JSON, no buyer data) and retry route
  `{path}/wiederholen`, both behind a bearer token.
- PHP API `Uketsuke::ledger()` and `Uketsuke::options()`: `purchase()`, `purchases()`,
  `delivery()`, `recordDelivery()`.
- Retention of notifications (`retention` option, default 90 days).
- Privacy notes in English (`PRIVACY.md`) and German (`docs/DATENSCHUTZ.md`).
