# Uketsuke

Digistore24 IPN receiver for Kirby 5. It checks the signature of each Instant Payment Notification, stores the purchase in a small SQLite ledger, and fires Kirby hooks so the site can react, for example by sending an access e-mail.

*Uketsuke* (受付) is the reception desk: it takes the notification, writes it down, and passes the purchase on.

## Requirements

- PHP 8.2 or newer, with `ext-json`, `ext-pdo` and `ext-pdo_sqlite`
- Kirby 5 (`^5.0`)
- A storage directory outside the web root

## Installation

With Composer, into the site's root:

```sh
composer require sayamaapps/kirby-uketsuke
```

The installer places the plugin in `site/plugins/uketsuke/`. Without Composer, copy the folder there.

## Setup at Digistore24

1. Digistore24: **Settings → Integrations → IPN → Generic**.
2. URL: `https://example.com/uketsuke/digistore24` (your domain and the `path` option). Without a query string: the signature covers the posted form only, and the module reads nothing else.
3. Passphrase: choose one, and enter the same value as the `passphrase` option.
4. Events: `on_payment`, `on_refund`, `on_chargeback`.
5. Press **test connection**. It must answer `OK`.

Digistore24 counts a notification as delivered only when it receives the text `OK`. Any other answer, or no answer within 30 seconds, makes it retry: at least 20 times over 10 days, the first retry after 3 minutes, the interval growing to 24 hours after 10 tries. Hooks therefore have to finish well within 30 seconds. The module answers `OK` once the notification is stored, even if a hook fails.

Answers of the IPN route:

| Answer | Meaning |
|---|---|
| `200 OK` | Received and stored. Also duplicates, connection tests and events without a purchase. |
| `403 INVALID_SIGNATURE` | Signature missing or wrong. Nothing is stored. |
| `413 PAYLOAD_TOO_LARGE` | Body over 64 KB. Nothing is read. |
| `415 UNSUPPORTED_MEDIA_TYPE` | Not a form (`application/x-www-form-urlencoded` or `multipart/form-data`). Nothing is read. |
| `503 STORAGE_ERROR` | The database cannot be written, or the storage directory is not configured (the log names the reason). Digistore24 sends the notification again. |
| `503 NOT_CONFIGURED` | `active` is true, but no passphrase is set. |
| 404 | `active` is false. |

## Usage

Uketsuke only receives and records. The site decides what a purchase means, with hooks:

```php
// site/config/config.php
use Sayamaapps\Uketsuke\Notification;
use Sayamaapps\Uketsuke\Purchase;
use Sayamaapps\Uketsuke\Uketsuke;

return [
    'sayamaapps.uketsuke.active' => true,
    // passphrase and token: in site/config/env.php, see Options

    'hooks' => [
        'uketsuke.payment:after' => function (Purchase $purchase, Notification $notification, bool $retry) {
            // First payment of the order. $retry is true when the route wiederholen fired it again.
            // Runs inside the request: keep it short (Digistore24 waits 30 seconds).
            if ($purchase->test) {
                return;
            }
            // send the e-mail here, then record the outcome:
            Uketsuke::ledger()->recordDelivery($purchase, 'mail', 'sent', null, ['message_id' => 'abc']);
        },
    ],
];
```

Hooks and their arguments (Kirby matches the arguments by parameter name, so a hook may declare only the ones it needs):

| Hook | Arguments | Fired when |
|---|---|---|
| `uketsuke.payment:after` | `Purchase $purchase`, `Notification $notification`, `bool $retry` | First `on_payment` of a product of an order. Also by the retry route, with `$retry = true`. |
| `uketsuke.payment:repeat` | `Purchase $purchase`, `Notification $notification` | Another `on_payment` for a product that is already paid (instalments). |
| `uketsuke.refund:after` | `Purchase $purchase`, `Notification $notification` | `on_refund` that changed the status to `refunded`. |
| `uketsuke.chargeback:after` | `Purchase $purchase`, `Notification $notification` | `on_chargeback` that changed the status to `chargeback`. |
| `uketsuke.notification:after` | `Notification $notification`, `?Purchase $purchase` | Every valid, non-duplicate notification, except the silent events below. |

Silent events (`on_payment_missed`, `on_rebill_cancelled`, `on_rebill_resumed`, `last_paid_day`, `customform`, `eticket`, `on_affiliation`) are stored and answered `OK` without any hook. Unknown events get `uketsuke.notification:after` only.

A failing hook is recorded in `notifications.hook_error` with the exception class name, and logged. The request still answers `OK`.

If the request dies inside a hook (fatal error, time limit), Digistore24 gets no `OK` and sends the notification again. Each notification remembers whether its hooks ran to the end; when such a repetition arrives more than two minutes later, the hooks fire again instead of the repetition counting as a duplicate. Hooks should therefore check what they already did, for example with `delivery()`.

### Orders with several products

Digistore24 sends one `on_payment` per product of an order (order bumps, upsells), all with the same order id. Uketsuke keeps one purchase per product: each gets its own `uketsuke.payment:after`, its own deliveries and its own status. A refund with a product id changes that product only; without one, every product of the order.

### PHP API for the site

```php
use Sayamaapps\Uketsuke\Uketsuke;

$ledger = Uketsuke::ledger();
$ledger->purchase('ORD-1');                         // ?Purchase, the first product of the order
$ledger->purchase('ORD-1', '12345');                // ?Purchase, one product of the order
$ledger->order('ORD-1');                            // list<Purchase>, every product of the order
$ledger->purchases($since, $until);                 // list<Purchase>, paid in [since, until)
$ledger->delivery($purchase, 'mail');               // ?array: status, attempts, error, sent_at, meta
$ledger->deliveries($purchase);                     // list of those arrays, by channel
$ledger->recordDelivery($purchase, 'mail', 'failed', 'SMTP timeout', ['attempt' => 2]); // attempts + 1
```

`delivery()`, `deliveries()` and `recordDelivery()` take the `Purchase`, or an order id while the order has a single product; for an order with several products an order id throws `InvalidArgumentException`. `recordDelivery()` accepts the statuses `pending`, `sent`, `failed`, `skipped` and `simulated`. E-mail addresses in `$error` and in string values of `$meta` are replaced by `[address]` before storage.

Methods marked `@internal` (and the classes `Notifications` and `Sql`) serve the receiver and the routes; they may change in any version.

`Purchase` is a readonly class with `orderId`, `productId`, `productName`, `productNameIntern`, `email`, `firstName`, `lastName`, `status`, `paidAt`, `refundedAt`, `test`, `language`, `country`, `affiliate`, `campaignKey`, `amount`, `currency`, `custom`, `buyerId`, and `toArray()`.

`test` is true when `api_mode` is present and not `live`, or when `pay_method` is `test`. A missing `api_mode` counts as live, unlike Digistore24's reference receiver: a real buyer must not lose a delivery because a field is absent.

## Routes

`{path}` is the `path` option, `uketsuke/digistore24` by default.

| Route | Answer |
|---|---|
| `POST {path}` | IPN receiver, see the table above. |
| `GET {path}` | `200 OK` while active. Reveals nothing else. |
| `GET {path}/protokoll?since=YYYY-MM-DD&until=YYYY-MM-DD` | JSON list of purchases with their deliveries, without buyer data. `since` defaults to 30 days ago, `until` is inclusive, dates are Europe/Berlin. `hooks_pending` is true while the hooks of the first payment never ran to the end. |
| `POST {path}/wiederholen` | Fires `uketsuke.payment:after` again with `retry = true`, for paid purchases. Give `order_id` (every paid product of the order), or `channel` and `status` to retry the paid purchases with such a delivery; status `missing` selects paid purchases without any delivery in the channel. Answers `{"triggered": [...], "failed": [...], "next": null}`, see below. |

The last two need the `token` option and `Authorization: Bearer <token>`. Without a token option they answer 404; a wrong or missing token answers `401 UNAUTHORIZED`. Apache with FastCGI often drops the `Authorization` header before PHP sees it; Kirby's default `.htaccess` passes it on with `SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1`, keep that line.

The retry answer lists one order id per retried purchase in `triggered`, and each hook that threw in `failed` (`order_id`, `product_id`, `error` with the exception class). A retry by channel handles at most `limit` purchases per request (default 20, at most 100), because each hook may send mail within the request's time limit. While more are left, `next` holds a cursor: send it as `after` with the same channel and status for the next page.

```sh
curl -H "Authorization: Bearer $UKETSUKE_TOKEN" \
  "https://example.com/uketsuke/digistore24/protokoll?since=2026-10-01&until=2026-10-31"

curl -X POST -H "Authorization: Bearer $UKETSUKE_TOKEN" \
  -d "order_id=ORD-1" "https://example.com/uketsuke/digistore24/wiederholen"

curl -X POST -H "Authorization: Bearer $UKETSUKE_TOKEN" \
  -d "channel=mail&status=failed" "https://example.com/uketsuke/digistore24/wiederholen"

curl -X POST -H "Authorization: Bearer $UKETSUKE_TOKEN" \
  -d "channel=mail&status=failed&after=42" "https://example.com/uketsuke/digistore24/wiederholen"
```

## Options

All options are read as `sayamaapps.uketsuke.<key>`.

| Option | Default | Meaning |
|---|---|---|
| `active` | `false` | `false`: all routes answer 404 and nothing is written. |
| `passphrase` | `null` | The IPN passphrase from Digistore24. Required while active. |
| `token` | `null` | Bearer token for the protocol and retry routes. `null` disables both routes. |
| `path` | `'uketsuke/digistore24'` | Route prefix. An empty prefix (`'/'`) falls back to the default. |
| `retention` | `90` | Days after which notifications are deleted (at least 1). Purchases and deliveries are kept. |
| `storage` | `null` | Storage directory. `null` means `<storage root>/uketsuke`; Kirby 5 defines no `storage` root by itself, so set either this option or the root (for example `'storage' => dirname(__DIR__) . '/storage'` in the roots of `public/index.php`). It must lie outside the web root, also through symlinks; otherwise the module refuses to write and answers `503 STORAGE_ERROR`. |

Put the passphrase and the token in `site/config/env.php`. Kirby loads that file per environment, and it stays out of the repository, for example `'sayamaapps.uketsuke.passphrase' => '...'`.

## Data and retention

- Database: `<storage>/uketsuke/uketsuke.sqlite` (WAL mode; the `-wal` and `-shm` files sit next to it). The directory is created with mode `0700`.
- `purchases`: one row per product of an order with buyer e-mail, name, country and order data.
- `deliveries`: one row per purchase and channel, written by the site through `recordDelivery()`.
- `notifications`: every received notification, with an allow-list of non-personal fields only. Rows older than `retention` days are deleted whenever a notification arrives. A notification Digistore24 repeats after that is no longer recognised as a duplicate: a payment then fires `uketsuke.payment:repeat`, a refund or chargeback fires no hook because the status does not change.
- The module makes no outbound requests and sends no data anywhere.

To remove one order, stop the module or open the database with `sqlite3` and delete from `deliveries` and `purchases` where `order_id` matches. See `docs/DATENSCHUTZ.md` (German) and `PRIVACY.md` (English).

## Maintenance

- Logs go to `error_log()` with the prefix `Uketsuke:`. They contain order ids and exception class names, never e-mail addresses, names or the passphrase.
- The schema version is kept in `PRAGMA user_version`. Version 2 (module 1.1) keys purchases and deliveries by order and product; a version 1 ledger is migrated on first use.
- The ledger uses only SQL that SQLite 3.7.17 understands, because some shared hosters still ship it.

## Development

```sh
composer install
composer test                     # PHPUnit
composer analyze:psalm            # Psalm
composer analyze:phpmd            # PHP Mess Detector
composer analyze:unused           # unused Composer packages
composer analyze:require-checker  # undeclared dependencies
composer normalize:check          # composer.json layout
```

Code style follows Kirby's rule set (`getkirby/php-cs`, binary `kirby-cs`). See `.github/CONTRIBUTING.md`.

## Repository layout

```
index.php           registration only
autoload.php        class map for F::loadClasses()
config/             options, routes
src/                one class per file, namespace Sayamaapps\Uketsuke
tests/              PHPUnit, boots a real Kirby app per test class
docs/               German privacy text (not in the package)
PRIVACY.md          English privacy notes
CHANGELOG.md        version history
```

## License

MIT, see `LICENSE.md`.
