# Changelog

All notable changes to this project are documented here. Versions follow
[Semantic Versioning](https://semver.org).

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
