<p align="center">
  <img src="assets/banner-1544x500.png" alt="TejCart — a complete shopping cart for WordPress" width="100%" />
</p>

<h1 align="center">TejCart</h1>

<p align="center">
  <strong>A complete, self-contained shopping cart for WordPress. PayPal-first. No monthly platform fee.</strong>
</p>

<p align="center">
  <a href="https://wordpress.org/plugins/tejcart/"><img src="https://img.shields.io/wordpress/plugin/v/tejcart?label=version&style=flat-square" alt="Version" /></a>
  <a href="https://wordpress.org/plugins/tejcart/"><img src="https://img.shields.io/wordpress/plugin/installs/tejcart?label=installs&style=flat-square" alt="Installs" /></a>
  <a href="https://wordpress.org/plugins/tejcart/"><img src="https://img.shields.io/wordpress/plugin/tested/tejcart?label=tested%20up%20to&style=flat-square" alt="Tested WP" /></a>
  <a href="https://wordpress.org/plugins/tejcart/"><img src="https://img.shields.io/wordpress/plugin/required-php/tejcart?label=php&style=flat-square" alt="PHP" /></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-GPL--2.0--or--later-blue?style=flat-square" alt="License" /></a>
</p>

<p align="center">
  <a href="https://wordpress.org/plugins/tejcart/">Directory</a> ·
  <a href="https://tejcart.com/docs">Documentation</a> ·
  <a href="https://github.com/tejcart/tejcart/issues">Issues</a> ·
  <a href="https://wordpress.org/support/plugin/tejcart/">Support</a>
</p>

---

## Description

TejCart is a complete, self-contained shopping cart for WordPress. Sell physical goods, digital downloads, or services — products, cart, checkout, orders, customers, coupons, taxes, shipping, emails, and reports are all built in. It is a standalone storefront that needs no companion ecommerce plugin, and there is no monthly platform fee.

TejCart gives shoppers a fast, single-page checkout that accepts credit and debit cards, Pay Later, and digital wallets including Apple Pay and Google Pay. Raw card data never touches your server: card details are tokenised in the shopper's browser, keeping your store in the simplest PCI scope (SAQ A).

Built on modern, typed PHP (8.2+) with a purpose-built data layer and full Gutenberg block support, TejCart is fast for shoppers and predictable for developers.

## Features

**Sell any kind of product**

- Simple, variable, digital, downloadable, grouped, external, and bundle products
- Product attributes and variations with per-variation pricing, images, and stock
- Inventory tracking with low-stock and out-of-stock handling
- Secure, time-limited download URLs for digital and downloadable goods
- CSV import and export for catalog migration and bulk edits

**A checkout that converts**

- AJAX shopping cart with persistent storage for logged-in shoppers
- Fast single-page checkout with guest checkout
- Credit and debit cards, Pay Later, and digital wallets (Apple Pay, Google Pay)
- Saved payment methods with vault tokens encrypted at rest
- Coupons and discounts with flexible cart- and product-level rules

**Everything to run the store**

- Order management with partial and full refunds, order notes, and invoices
- Customer accounts with order history and a saved address book
- Tax rules and shipping zones, classes, and rates
- Transactional emails with theme-overridable templates
- Reports for revenue, orders, items sold, and coupon usage
- Capabilities scoped to store management for staff roles

**Built for WordPress**

TejCart follows WordPress the right way. It uses core hooks, the REST API, and templates you can override from your theme — front-end markup, transactional emails, and pricing are all filterable. Gutenberg blocks are provided for cart, checkout, account, and product displays, and a full WP-CLI command set (`wp tejcart …`) is included for automation and bulk tasks. Multisite is fully supported.

**Privacy and PCI by design**

Your store never accepts or stores raw card numbers, CVV codes, or expiry dates. Card data is tokenised in the buyer's browser, and only opaque references — capture IDs, payer IDs, and vault tokens — ever reach your database, encrypted at rest with AES-256-GCM. This keeps your store within PCI DSS SAQ A scope.

## Requirements

- WordPress 6.3 or later
- PHP 8.2 or later
- HTTPS enabled

## Installation

**From the WordPress directory**

1. Install **TejCart** from **Plugins → Add New**, or download it from [wordpress.org/plugins/tejcart](https://wordpress.org/plugins/tejcart/).
2. Activate the plugin through the **Plugins** screen.
3. Visit **TejCart → Setup Wizard** to configure currency, store address, payment methods, shipping zones, and tax.
4. Connect a payment account from **TejCart → Settings → Payments** to start accepting payments.
5. Add your first product under **TejCart → Products → Add New**.

The Setup Wizard is optional — every setting it touches is also available under **TejCart → Settings**.

**From source (development)**

```bash
cd wp-content/plugins
git clone git@github.com:tejcart/tejcart.git tejcart
cd tejcart

# If the Gutenberg blocks are built from source, document the command here, e.g.:
# npm install && npm run build

wp plugin activate tejcart
```

## Frequently Asked Questions

**Is TejCart free?**
Yes. TejCart is free and open source under the GPL, with no monthly platform fee. Payments are processed by your connected payment provider under their standard rates.

**Do I need a separate ecommerce plugin?**
No. TejCart is a complete, standalone store. Products, cart, checkout, orders, customers, coupons, tax, and shipping are all built in.

**Does TejCart work with any theme?**
Yes. It uses standard WordPress hooks and templates and works with any properly coded theme. Every front-end template can be overridden from your theme.

**Can I sell digital products?**
Yes — digital and downloadable products with secure, time-limited download URLs and per-product download limits.

**Does TejCart support multisite?**
Yes. It installs per site across a network, creates its tables automatically on new sites, and cleans up when a site is deleted.

**Can developers extend TejCart?**
Yes. It exposes actions and filters throughout, a REST API, overridable templates, and a `wp tejcart` WP-CLI command set.

**Does TejCart store credit-card data?**
No. Card data is tokenised in the buyer's browser by the payment provider's hosted fields; only opaque references reach your database, encrypted at rest with AES-256-GCM. Your store stays within PCI DSS SAQ A scope.

## Screenshots

> Place these PNGs in the repo's `assets/` folder (the same files used on the WordPress.org listing) so they render reliably on GitHub.

| | |
|:---:|:---:|
| ![Cart](assets/screenshot-1.png) | ![Checkout](assets/screenshot-2.png) |
| *Cart with discount & free-shipping bar* | *Single-page checkout* |
| ![Orders](assets/screenshot-3.png) | ![Reports](assets/screenshot-6.png) |
| *Admin orders list* | *Reports dashboard* |

## Modules

All modules are **off by default** and load only when enabled under **TejCart → Modules**. None contacts an external service until you enable it and supply your own credentials.

| Module | Purpose | External service |
|---|---|---|
| Storefront Search | Typo-tolerant search with live autocomplete | — |
| Product Filters | Faceted AJAX shop navigation | — |
| Variation Swatches | Color / image / label swatches | — |
| Address Autocomplete | Checkout address dropdown | Google Places |
| Currency Switcher | Local-currency display, hourly rates | — |
| Real-time Shipping | Live carrier rates | FedEx, UPS, USPS, DHL… |
| Order Tracking | Tracking numbers & carrier deep-links | — |
| Tax Providers | Automated sales tax / VAT / GST | Stripe Tax, TaxJar, Avalara |
| AI Content SmartSuite | Generate product copy | OpenAI |
| Bot Protection | CAPTCHA on key endpoints | Turnstile / hCaptcha / reCAPTCHA |
| Tracking & Pixels | Server-side conversion events | GA4, Meta, Klaviyo, Mailchimp |

## For Developers

> Hook, route, and command names below are illustrative — verify against your build or the [docs](https://tejcart.com/docs).

**Hooks & filters**

```php
add_filter( 'tejcart_cart_item_price', function ( float $price, $item ): float {
    return $price;
}, 10, 2 );

add_action( 'tejcart_order_completed', function ( int $order_id ): void {
    // e.g. sync to an external system
}, 10, 1 );
```

**REST API** — namespaced under `tejcart/v1`:

```bash
curl https://example.com/wp-json/tejcart/v1/products
```

**WP-CLI:**

```bash
wp tejcart product list --status=publish
wp tejcart order get <id> --format=json
```

**Template overrides** — copy any file from `templates/` into `your-theme/tejcart/` to override it.

## External Services

TejCart contacts external services only to process payments and onboard merchants; modules call out only after you enable them with your own credentials. The complete list of endpoints, providers, and their terms/privacy links is maintained in [`readme.txt`](readme.txt) under **External Services**.

## Security

TejCart handles payment flows. **Please do not file public issues for vulnerabilities** — email **security@tejcart.com** with details and reproduction steps. *(Update this address, or add a `SECURITY.md`.)*

## Changelog

The full, versioned changelog is maintained in [`readme.txt`](readme.txt). Latest:

**1.0.6** — Tracking & Pixels (server-side conversions), Store Insights (cohort/LTV analytics), and a unified Disputes queue, plus security and multisite cleanup refinements.

## License

[GPL-2.0-or-later](LICENSE). Payments are processed by your connected provider under their standard rates; optional modules connect to third-party services you choose and pay for directly.
