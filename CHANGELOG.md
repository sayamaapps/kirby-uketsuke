# Changelog

All notable changes to this project are documented here. Versions follow
[Semantic Versioning](https://semver.org).

## [1.3.0] — 2026-11-07

### Added

- Vendor tracking key per purchase. The receiver reads the first non-blank of the IPN fields
  `trackingkey` (the name in Digistore24's IPN documentation), `tracking_key` and `tracking_param`
  (the name in the API), trims it and keeps at most 255 characters. It is stored as
  `purchases.tracking_key`, exposed as `Purchase::$trackingKey`, listed as `tracking_key` in the
  protocol route and kept in `notifications.payload`. Purchases stored before 1.3.0 keep `null`.
- Schema version 4: `purchases.tracking_key TEXT` is added by a migration from version 3. A new
  ledger gets the same column layout.

## [1.2.2] — 2026-11-03

### Fixed

- `Ledger::purchase($orderId)` without a product id returned the mark of an order-wide refund or
  chargeback (the row without product id) instead of the first product when that refund or
  chargeback arrived before the payments of its order. It now returns the first product, and the
  mark only while the order has no product. `purchase($orderId, '')` still reads the mark by its key.
  The same lookup served the purchase passed on with a notification whose product id is empty.
  The 1.2.1 note that the mark has a later row id held only when the refund followed the payments.

## [1.2.1] — 2026-11-03

### Fixed

- A payment that arrives after a refund or chargeback of the whole order (no product id)
  is now stored with that status also when the order already had other products; before,
  only an order that was still unknown was handled. The late product fires
  `uketsuke.refund:after` or `uketsuke.chargeback:after` instead of `uketsuke.payment:after`,
  and the retry route no longer picks it up.
- The mark that settles the whole order is a row without product id in the ledger. Next to
  real products, `Ledger::order()`, `Ledger::purchases()` (and with it the protocol route) and
  an order id passed to `delivery()`, `deliveries()` or `recordDelivery()` do not count it.

## [1.2.0] — 2026-11-03

### Changed

- `uketsuke.refund:after` and `uketsuke.chargeback:after` fire once per product whose
  status changed. A refund or chargeback without product id fires once for every product
  of the order; before, only for the first. `uketsuke.notification:after` still fires once.
- Ledger schema version 3: `notifications.attempted_at`. A version 2 ledger (and a
  version 1 ledger) is migrated on first use.

### Fixed

- A refund or chargeback without product id that arrived before the payments of its
  order left the later `on_payment` booked as paid, with `uketsuke.payment:after` and
  retries. The product is now stored as refunded or charged back and fires
  `uketsuke.refund:after` or `uketsuke.chargeback:after` instead.
- Two overlapping repetitions of a notification whose hooks never finished both fired
  the hooks. A repetition now claims the notification (`attempted_at`) first.
- A symbolic link in place of the database file (or its `-wal`, `-shm` or `-journal`
  file) was followed, so buyer data could end up in a publicly served file. The module
  refuses to open it and answers `503 STORAGE_ERROR`.

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
