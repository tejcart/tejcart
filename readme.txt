=== TejCart ===
Contributors: tejcart
Tags: ecommerce, shopping cart, store, payments, digital downloads
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.0.6
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete shopping cart for WordPress — sell physical, digital & variable products with cart, checkout, orders, coupons, tax and shipping.

== Description ==

TejCart is a complete, self-contained shopping cart for WordPress. Sell physical goods, digital downloads, or services — products, cart, checkout, orders, customers, coupons, taxes, shipping, emails, and reports are all built in. It is a standalone storefront that needs no companion ecommerce plugin, and there is no monthly platform fee.

TejCart gives shoppers a fast, single-page checkout that accepts credit and debit cards, Pay Later, and digital wallets including Apple Pay and Google Pay. Raw card data never touches your server: card details are tokenised in the shopper's browser, keeping your store in the simplest PCI scope (SAQ A).

Built on modern, typed PHP (8.2+) with a purpose-built data layer and full Gutenberg block support, TejCart is fast for shoppers and predictable for developers.

= Sell any kind of product =

* Simple, variable, digital, downloadable, grouped, external, and bundle products
* Product attributes and variations with per-variation pricing, images, and stock
* Inventory tracking with low-stock and out-of-stock handling
* Secure, time-limited download URLs for digital and downloadable goods
* CSV import and export for catalog migration and bulk edits

= A checkout that converts =

* AJAX shopping cart with persistent storage for logged-in shoppers
* Fast single-page checkout with guest checkout
* Credit and debit cards, Pay Later, and digital wallets (Apple Pay, Google Pay)
* Saved payment methods with vault tokens encrypted at rest
* Coupons and discounts with flexible cart- and product-level rules

= Everything to run the store =

* Order management with partial and full refunds, order notes, and invoices
* Customer accounts with order history and a saved address book
* Tax rules and shipping zones, classes, and rates
* Transactional emails with theme-overridable templates
* Reports for revenue, orders, items sold, and coupon usage
* Capabilities scoped to store management for staff roles

= Built for WordPress =

TejCart follows WordPress the right way. It uses core hooks, the REST API, and templates you can override from your theme — front-end markup, transactional emails, and pricing are all filterable. Gutenberg blocks are provided for cart, checkout, account, and product displays, and a full WP-CLI command set (`wp tejcart …`) is included for automation and bulk tasks. Multisite is fully supported.

= Privacy and PCI by design =

Your store never accepts or stores raw card numbers, CVV codes, or expiry dates. Card data is tokenised in the buyer's browser, and only opaque references — capture IDs, payer IDs, and vault tokens — ever reach your database, encrypted at rest with AES-256-GCM. This keeps your store within PCI DSS SAQ A scope.

= Built-in modules =

Keep your site lean: advanced capabilities ship inside the plugin as modules that are off by default and load only when you switch them on from **TejCart > Modules**. Nothing extra runs — and no third party is ever contacted — until you enable the module you want. The following modules are bundled and ready to turn on:

* **Storefront Search** — fuzzy, weighted product search with live autocomplete that finds "blue jeans" even when a shopper types "bleu jeans".
* **Product Filters** — faceted shop navigation to filter by category, brand, price, rating, stock and attributes, with instant AJAX updates and clean, shareable URLs.
* **Variation Swatches** — show variable-product options as colour, image and label swatches instead of dropdowns, on product pages and shop archives.
* **Address Autocomplete** — shoppers pick their address from a dropdown that fills city, state and postcode in one tap, a proven checkout conversion lever (powered by Google Places; your own API key).
* **Currency Switcher** — show prices in the shopper's local currency with hourly exchange rates, optional geolocation, psychological price rounding, and per-currency payment-gateway rules.
* **Real-time Shipping** — live carrier rates from FedEx, UPS, USPS, DHL, Royal Mail, Australia Post, Canada Post and more; each carrier stays off until you enter its credentials.
* **Order Tracking** — add tracking numbers and carrier deep-links to orders and let customers check shipment status without contacting support.
* **Tax Providers** — accurate sales tax, VAT and GST through Stripe Tax, TaxJar or Avalara, with no manual rate tables to maintain (your own provider credentials).
* **AI Content SmartSuite** — generate product titles, descriptions, tags and FAQs with OpenAI, with fully editable prompts, 35+ languages, and one-click apply (your own OpenAI key).
* **Bot Protection (CAPTCHA)** — put Cloudflare Turnstile, hCaptcha or Google reCAPTCHA in front of login, checkout, cart and coupon endpoints to stop card-testing bots that ride past per-IP rate limits.

Modules that connect to an outside service only do so after you enable them and enter your own credentials; the exact endpoints and providers are listed under **External Services** below.

= Requirements =

* WordPress 6.3 or later
* PHP 8.2 or later
* HTTPS enabled

== Installation ==

1. Upload the `tejcart` folder to the `/wp-content/plugins/` directory, or install the plugin through the **Plugins > Add New** screen in WordPress.
2. Activate the plugin through the **Plugins** screen.
3. Visit **TejCart > Setup Wizard** in the WordPress admin to configure currency, store address, payment methods, shipping zones, and tax preferences.
4. Connect a payment account from **TejCart > Settings > Payments** to start accepting payments.
5. Add your first product under **TejCart > Products > Add New**.

The Setup Wizard is optional — every setting it touches is also available under **TejCart > Settings**.

== Frequently Asked Questions ==

= Is TejCart free? =

Yes. TejCart is free and open source under the GPL, with no monthly platform fee. Payments are processed by your connected payment provider under their standard rates, and a few optional modules connect to third-party services you choose and pay for directly.

= Do I need a separate ecommerce plugin to use TejCart? =

No. TejCart is a complete, standalone store. Products, cart, checkout, orders, customers, coupons, tax, and shipping are all built in — no companion ecommerce plugin is required.

= What do I need to accept payments? =

Connect a supported payment provider in a few clicks from the setup wizard to accept cards and digital wallets. You can build your catalog and configure the store before connecting, and offline payment methods (such as bank transfer or cash on delivery) are available as well.

= Does TejCart work with any WordPress theme? =

Yes. TejCart uses standard WordPress hooks and templates and works with any properly coded theme. Every front-end template can be overridden from your theme for full control of the markup.

= Can I sell digital products? =

Yes. TejCart supports digital and downloadable products with secure, time-limited download URLs and per-product download limits.

= Can I import an existing catalog? =

Yes. Use the built-in CSV import to add products in bulk, and CSV export to back up your catalog or move it between sites.

= Does TejCart support multisite? =

Yes. TejCart installs per site across a multisite network, creates its tables automatically on new sites, and cleans up when a site is deleted.

= Can developers extend TejCart? =

Yes. TejCart exposes actions and filters throughout, a REST API, overridable templates, and a `wp tejcart` WP-CLI command set, so you can customise and automate without editing plugin files.

= Does TejCart store credit-card data? =

No. TejCart never accepts or stores raw card numbers, CVV codes, or expiry dates. Card data is tokenised in the buyer's browser by the payment provider's hosted fields, and only opaque references — capture IDs, payer IDs, and vault tokens — ever reach your database. Vault tokens used for saved payment methods are encrypted at rest with AES-256-GCM. Your store does not enter PCI DSS scope beyond SAQ A.

== Screenshots ==

1. The cart page with a discount applied and the free-shipping progress bar.
2. The single-page checkout with card fields, digital wallets, and Pay Later.
3. The admin orders list with quick filters and bulk actions.
4. The product editor for a variable product with attributes and variations.
5. The Setup Wizard guiding a new merchant through currency, payments, and shipping.
6. The reports dashboard showing revenue, orders, items sold, and coupons used.

== External Services ==

TejCart connects to the external services below to provide payment processing and merchant onboarding. Data is only sent when the corresponding feature is actively used.

= PayPal =

When a shopper checks out, or a merchant refunds, captures, or voids a transaction, TejCart calls PayPal's REST API at `https://api-m.paypal.com` (live) or `https://api-m.sandbox.paypal.com` (sandbox). Order totals, line items, billing and shipping addresses, and buyer contact information are sent as part of the checkout flow. The plugin also loads the PayPal JavaScript SDK from `https://www.paypal.com/sdk/js` and the partner-onboarding lightbox from `https://www.paypal.com/webapps/merchantboarding/js/lib/lightbox/partner.js` on the connect screen.

* Service provider: PayPal, Inc.
* Terms of service: https://www.paypal.com/us/legalhub/useragreement-full
* Privacy policy: https://www.paypal.com/us/legalhub/privacy-full

= Google Pay =

When the merchant enables Google Pay, the plugin loads Google's Pay JavaScript SDK from `https://pay.google.com/gp/p/js/pay.js` on the cart and checkout pages so the Google Pay button can render and tokenize the shopper's card.

* Service provider: Google LLC
* Terms of service: https://payments.google.com/payments/apis-secure/get_legal_document?ldo=0&ldt=googlepaytos&ldl=en
* Privacy policy: https://policies.google.com/privacy

= TejCart Partner Onboarding Proxy =

To enable one-click merchant onboarding without shipping partner credentials in the plugin, TejCart calls a proxy at `https://tejcart.com/ppcp-seller-onboarding/seller-onboarding.php`. The proxy is only contacted while a merchant is connecting or disconnecting a payment account. The plugin sends the merchant's WordPress admin email, the environment (sandbox or live), a return URL pointing back to the WordPress admin, and the requested product bundle. No shopper data, order data, or API credentials are sent.

* Service provider: TejCart
* Terms of service: https://tejcart.com/terms-of-service.html
* Privacy policy: https://tejcart.com/privacy-policy.html

= Optional bot-protection providers (disabled by default) =

The captcha providers below are opt-in. They are disabled by default and only become active after the merchant explicitly enables a provider under **TejCart > Settings > Security** and supplies their own site key + secret key. When enabled, the plugin verifies a challenge token on the cart, checkout, login, and registration forms by sending the visitor's IP address and the challenge token to the provider's `siteverify` endpoint.

**hCaptcha** — When enabled, the plugin calls `https://hcaptcha.com/siteverify` to verify a challenge response.

* Service provider: Intuition Machines, Inc. (hCaptcha)
* Terms of service: https://www.hcaptcha.com/terms
* Privacy policy: https://www.hcaptcha.com/privacy

**Cloudflare Turnstile** — When enabled, the plugin calls `https://challenges.cloudflare.com/turnstile/v0/siteverify` to verify a challenge response.

* Service provider: Cloudflare, Inc.
* Terms of service: https://www.cloudflare.com/website-terms/
* Privacy policy: https://www.cloudflare.com/privacypolicy/

**Google reCAPTCHA** — When enabled, the plugin calls `https://www.google.com/recaptcha/api/siteverify` to verify a challenge response.

* Service provider: Google LLC
* Terms of service: https://policies.google.com/terms
* Privacy policy: https://policies.google.com/privacy

= Optional bundled-module services (disabled by default) =

TejCart ships an optional Tax module that is OFF by default. It does not contact any third party until a merchant turns the module ON under **TejCart > Modules**, enables a specific provider, and enters that provider's own API credentials. Each service below is only contacted when its feature is actively used.

**Live tax providers (Tax module).** When a provider is enabled, TejCart sends the cart/line totals and the order's billing/shipping address to calculate tax at checkout and, where supported, to record the finalised transaction.

* TaxJar — `https://api.taxjar.com` — Terms: https://www.taxjar.com/terms — Privacy: https://www.taxjar.com/privacy-policy
* Avalara AvaTax — `https://rest.avatax.com` — Terms: https://www.avalara.com/us/en/legal/terms.html — Privacy: https://www.avalara.com/us/en/legal/privacy-policy.html
* Stripe Tax — `https://api.stripe.com` — Terms: https://stripe.com/legal/ssa — Privacy: https://stripe.com/privacy

**AI Content SmartSuite module.** When this module is enabled and you add your own OpenAI API key, TejCart sends the product fields you choose to generate copy for — name, description, short description, tags, categories and attributes — to OpenAI to produce titles, descriptions, tags and FAQs. No customer data, pricing or personally identifiable information is transmitted. Nothing is sent until you enter a key and click Generate.

* OpenAI — `https://api.openai.com` — Terms: https://openai.com/policies/terms-of-use — Privacy: https://openai.com/policies/privacy-policy

**Tracking & Pixels module.** This module is OFF by default and ships with every destination disabled. Nothing is sent to any provider below until you turn the module ON under **TejCart > Modules**, enable a specific destination, and enter that destination's own credentials. When a destination is enabled, TejCart sends purchase/refund conversion data and, for the marketing destinations, customer profile data (such as email, name and order history) so the events appear in your account.

* Google Analytics 4 (Measurement Protocol) — `https://www.google-analytics.com/mp/collect` — Terms: https://marketingplatform.google.com/about/analytics/terms/us/ — Privacy: https://policies.google.com/privacy
* Meta (Conversions API) — `https://graph.facebook.com` — Terms: https://www.facebook.com/legal/terms — Privacy: https://www.facebook.com/privacy/policy/
* Klaviyo — `https://a.klaviyo.com` — Terms: https://www.klaviyo.com/legal/terms-of-service — Privacy: https://www.klaviyo.com/legal/privacy-notice
* Mailchimp — `https://<dc>.api.mailchimp.com` (your account data-center subdomain) — Terms: https://mailchimp.com/legal/terms/ — Privacy: https://www.intuit.com/privacy/statement/

== Changelog ==

= 1.0.6 =
* New: Tracking & Pixels — send purchase and refund conversions to Google Analytics 4, Meta, Klaviyo and Mailchimp from your server, so tracking survives ad-blockers and Safari ITP; every destination stays off until you add its credentials (enable it under Modules).
* New: Store Insights — cohort retention tables, customer lifetime value by acquisition channel, segment revenue dashboards and trend charts with CSV export, all computed in your own store with no third-party subscription (enable it under Modules).
* New: Disputes — every PayPal chargeback and dispute collected into one admin queue with internal notes, manual resolve actions, evidence-due reminders and CSV export, so you never miss a response window (enable it under Modules).
* Improved: Hardened CSV exports across the new analytics and disputes tools against spreadsheet formula injection.
* Improved: Multisite sub-site deletion now cleans up the new modules' tables.

= 1.0.5 =
* New: Storefront search — fast, typo-tolerant product search with live autocomplete suggestions and a search box you can drop anywhere (enable it under Modules).
* New: Product filters — let shoppers narrow the shop by category, brand, tag, price, rating, stock status and custom attributes, with instant AJAX filtering, clean shareable URLs and filter blocks for the editor (enable it under Modules).
* New: Real-time shipping rates — show live rates from FedEx, UPS, USPS, DHL, Royal Mail, Australia Post, Canada Post, EasyPost, Shippo and more at checkout; each carrier stays off until you add its credentials (enable it under Modules).
* New: Currency switcher — let shoppers shop and check out in their own currency with hourly auto-refreshed exchange rates, optional location detection and tidy price rounding (enable it under Modules).
* New: Order tracking — add shipment tracking numbers with carrier deep-links to orders, give customers a self-service tracking lookup, and bulk-import tracking via WP-CLI (enable it under Modules).
* Improved: All store emails are now sent as fully designed, branded HTML messages. The low-stock and out-of-stock summary alerts and the "account email changed" security notice — previously sent as plain text — now match the look of every other TejCart email.
* Improved: Low-stock and out-of-stock admin alerts appear in the email log and can be customised or toggled like any other email under Settings → Emails.

= 1.0.4 =
* New: Variation swatches — show variable product options as visual color, image and label swatches instead of dropdowns, on product pages and shop archives (enable it under Modules).
* New: AI Content SmartSuite — generate product titles, descriptions, tags and FAQs with OpenAI, then review and apply them with one click; fully editable prompts, 35+ languages, and automatic FAQ rich snippets (powered by your own OpenAI key; enable it under Modules).
* Improved: More accurate inventory for variable products — stock stays in sync with the exact variation each shopper buys.
* Improved: Clearer sales and revenue reporting that accounts for refunds for a true picture of your store's performance.
* Improved: Faster, safer in-place updates with smoother background data handling right after an update.
* Performance, security, and stability refinements throughout.

= 1.0.3 =
* New: Optional address autocomplete at checkout — shoppers pick their building, society or street and the city, state and postcode fill in automatically (powered by Google Places; enable it under Modules).
* New: Smoother checkout — clearer state / region selector, phone number now optional, and a "secure & encrypted" reassurance at the payment step.
* Improved: Checkout styles and scripts now refresh reliably after an update.
* Fixed: Checkout now detects the shopper's country from their device time zone, so buyers outside the United States (e.g. India) no longer see the country field default to the wrong country.
* Fixed: When a refund cannot be processed, the order now records an order note and log entry explaining exactly why (e.g. the gateway's rejection reason), instead of only showing a generic "Refund failed" message with nothing to check.
* Fixed: Tax-inclusive carts that mix tax classes (e.g. standard and reduced rates) now strip the embedded tax per item rather than at a single store-wide rate.

= 1.0.2 =
* Improved payment reliability for PayPal checkout, captures, and refunds.
* More accurate cart, coupon, pricing, and shipping calculations.
* Performance, security, and stability refinements throughout.

= 1.0.1 =
* Refreshed admin notices with a consistent, modern design.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.6 =
Adds optional server-side analytics & marketing, advanced cohort/LTV analytics, and a unified disputes queue, plus security and multisite cleanup refinements.

= 1.0.5 =
Adds optional storefront search, product filters, real-time shipping rates, a currency switcher and order tracking, plus branded HTML store-alert and security emails.

= 1.0.4 =
Adds optional variation swatches plus improved inventory accuracy, clearer reporting, and reliability refinements.

= 1.0.3 =
Adds optional checkout address autocomplete and checkout usability improvements.

= 1.0.2 =
Recommended update with payment reliability and stability improvements.