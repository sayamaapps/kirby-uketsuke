# Uketsuke: privacy notes

This is an English summary of [docs/DATENSCHUTZ.md](docs/DATENSCHUTZ.md), the German text
block for privacy policies, which is the authoritative version. Both describe the
implementation. They are not legal advice. Each operator has to determine the legal
basis, retention period, data subject rights and hosting details for their own site.

## What happens

- Digistore24 sends a notification for each order event to the site. The module checks
  the signature, stores the notification and, for a payment, the purchase.
- The module makes **no outbound requests**. Nothing is sent to Digistore24, to a
  service or to a third party. Mails are sent only by the site's own hooks.
- No cookies, no browser storage, no analytics.

## What is stored

- **purchases** (one row per product of an order): order id, product, buyer e-mail, first and last
  name, country, affiliate, custom field, buyer id, amount, currency, status and
  payment time.
- **deliveries** (one row per purchase and channel): written by the site, for example the
  status of a sent e-mail, a text error and a message id. E-mail addresses in error
  texts are removed before storage.
- **notifications** (one row per received notification): event, order id, transaction
  id, product id, time, and an allow-list of non-personal fields (for example product
  name, payment mode, billing status, quantity, order date). Names, e-mail addresses
  and addresses are not stored here.

The database is `storage/uketsuke/uketsuke.sqlite`, outside the web root.

## Retention

Notifications are deleted after the `retention` option (default 90 days). Purchases and
deliveries are kept until the operator deletes them. Backups of the storage directory
need their own retention.

## Your rights

To delete an order, delete its rows from `deliveries` and `purchases` (matched by
`order_id`) with an SQLite client, or remove the storage directory. Stop the module
(`active` set to false) before you change the database.
