# Cart, checkout and account

Implemented and verified on the isolated cart Playground at **http://127.0.0.1:9404/**, WordPress **7.1.3**, WooCommerce **11.2.1**, PHP **8.4.25**, Polish locale. No production changes, added plugins, git commands or edits to another area's source files.

## Delivered

- Native WooCommerce Mini-Cart contents: right-hand **420 px** drawer, full width at **390 px**, tiny `KOSZYK (n)` title, localized close control, 3:4 images, size metadata, native quantity/removal controls with `USUŃ`, outline cart link and black checkout link. Empty state links to the configured shop. WooCommerce retains focus management, Escape, cart actions and stock validation.
- Cart and checkout templates retain WooCommerce's `page-content-wrapper` and the assigned page's **block content**, so merchants and integrations can edit/extend those blocks. The local fixture contains Cart/Checkout blocks, not classic checkout shortcodes.
- Checkout has the logo/back-to-cart header, a minimal legal footer, square hairline inputs, small uppercase labels, black buttons, mist summary, ink focus outlines and rose errors. Payment, express-payment, pickup, shipping, order-note and additional-information blocks remain present.
- Store API extension `extensions.lunae` supplies the delivery message and percentage. Mini-Cart consumes native Interactivity API bindings; the cart page subscribes to the Blocks cart store. No replacement cart controller, polling loop, jQuery addition or CDN.
- Theme-level order-button filter and Blocks `placeOrderButtonLabel` filter supply **ZAMAWIAM I PŁACĘ**.
- Order confirmation preserves WooCommerce's protected order blocks, gateway instructions, addresses, downloads and additional fields, with the same minimal header/footer.
- My Account retains the native shortcode/endpoints. Its localized page resolves to the account template, with desktop navigation/content columns, stacked mobile layout, matching forms and desktop login/registration columns.

## Files

Theme files:

- `theme/lunae/inc/cart.php`
- `theme/lunae/assets/css/cart.css`, `assets/js/cart.js`
- `theme/lunae/parts/mini-cart.html`, `parts/checkout-header.html`
- `theme/lunae/templates/page-cart.html`, `page-checkout.html`, `page-my-account.html`, `order-confirmation.html`
- `theme/lunae/patterns/cart-checkout-header.php`, `cart-legal-footer.php`, `cart-empty.php`

Separate local configuration: `tools/dev-store-setup.php`.

Repeatable QA: `qa/cart-flow.mjs`, `qa/cart-php-checks.php`, `qa/cart-account.mjs`, `qa/cart-account-fixture.php`. Evidence and screenshots are under `qa/shots/cart-*` (ignored by the existing repository rules; transfer them separately with the worktree).

## Free-delivery behavior

The threshold is read from the enabled free-shipping method in the customer's matching shipping zone. Calculation follows WooCommerce's displayed subtotal, tax display and `ignore_discounts` behavior, rather than the total including postage. An available free-shipping coupon is respected. The required **200 PLN fallback** applies only when no enabled free-shipping method is configured anywhere and the currency is PLN.

Progress is omitted for empty/virtual carts, multiple shipping packages, unsupported destination zones, or coupon-dependent rules that cannot be represented honestly by an amount bar. It also avoids announcing success when a method availability filter denies free delivery. A store with configured Polish free shipping does not advertise it to a German destination.

Verified: 139 zł → 61 zł remaining; quantity two → free delivery; changing the method to 250 zł → 111 zł remaining; exact threshold qualifies; a 20 zł coupon → 81 zł remaining, or 61 zł when the method ignores discounts. No theme hook writes shipping, payment, tax or legal-page settings.

## Local fixture and owner settings

`tools/dev-store-setup.php` refuses non-local environments and non-WP-CLI execution. Run it after WooCommerce/page/catalog setup:

```sh
wp eval-file /path/to/repo/tools/dev-store-setup.php
```

For a fresh Playground blueprint, add this step **after the catalog import**, when the launcher's fresh tools snapshot includes the file:

```json
{"step":"wp-cli","command":["wp","eval-file","/lunae-repo/tools/dev-store-setup.php"]}
```

The existing foundation launcher was not edited. This run executed the actual file with Playground's WP-CLI through a disposable local runtime wrapper; the wrapper and copied PHP were removed after each execution. A repeated fixture run succeeded without duplicate shipping methods. If new PHP patterns were added after Playground boot, refresh the theme pattern cache (`wp_get_theme()->delete_pattern_cache()`); this was necessary during development.

The local fixture configures:

- One PL zone: **Kurier 15 zł**, **Paczkomat (podgląd) 12 zł**, **Darmowa dostawa from 200 zł**, threshold after discounts.
- **BACS** and cheque testing gateways; no fabricated bank account. BACS confirmation explicitly says the order is a test and no transfer should be made.
- Guest checkout, registration on My Account, optional company and phone fields, Polish shipping/billing, and no tax calculation in this isolated fixture.
- `/koszyk/`, `/zamowienie/`, `/moje-konto/` page slugs and native blocks.
- Existing imported `/regulamin/` and `/polityka-prywatnosci/` pages remain intact. If absent, clearly identified local placeholder pages are created. WooCommerce terms and WordPress privacy page IDs are assigned.
- The Checkout Terms block is configured with **`checkbox: true`** and the linked wording **“Akceptuję regulamin i potwierdzam zapoznanie się z polityką prywatności.”** Checking only the global terms-page setting is insufficient: the block's checkbox option also needs enabling.

Before production, the owner/deployer must configure the real shipping zones/rates, tax rules, allowed destinations, gateway credentials/bank details, guest/registration preferences, and approved legal pages. Enable the terms checkbox and linked text on the actual Checkout page. Confirm the assigned Cart and Checkout pages contain the native blocks; templates deliberately render editable page content. Do not run this local setup script against production or copy its database options wholesale.

**Company/NIP:** the native optional company field is shown. A company name does not collect or validate NIP. No NIP field or plugin was added. Decide the invoicing workflow with the owner; a future NIP field should use WooCommerce's additional checkout fields API or a verified block-compatible invoicing integration and persist its value to the order.

## Verification

Final browser runs: **31 assertions each at 1440 × 1000 and 390 × 844**, no uncaught JavaScript errors or console errors, no horizontal overflow in captured states. Both runs:

1. Opened the empty drawer and verified Escape restores focus.
2. Viewed the real variable product with size 70B selected and added its available variation through the Store API.
3. Opened the native drawer; checked width, 15 forward Tab steps staying inside, count/quantity updates, both delivery states, native remove, and re-add.
4. Used the cart page's native quantity controls and verified live delivery updates.
5. Submitted empty checkout to exercise required-field errors, filled Polish guest data including diacritics, and selected both 12 zł and 15 zł shipping rates.
6. Verified the explicit order label and both legal links. Unchecked terms blocked submission.
7. Accepted terms and placed a **bank-transfer** order through the UI, then reached confirmation and the empty cart.

Final orders **126 (desktop)** and **127 (mobile)** were verified server-side: **guest**, **BACS**, **on-hold** (awaiting bank transfer), one **variation 20 / 70B**, **139 zł + 15 zł shipping = 154 zł**. Earlier successful visual runs created test orders 121–124. No payment was claimed as received.

PHP checks cover all cart PHP files and the shipping cases above. `cart-php-checks.log` records the checks and actual stored orders. A first development fixture used the wrong location value shape; it was corrected to WooCommerce's expected objects. Subsequent setup, browser and PHP runs produced **no new PHP notices, warnings or fatals**; the historical log entry was retained, not suppressed.

Separate logged-in tests verified the account navigation/content columns, mobile stacking and edit-account forms, with no overflow or browser errors. Run `qa/cart-account-fixture.php` with local WP-CLI before `qa/cart-account.mjs`; the fixture is a test-only customer with credentials present in those QA files. It is not installed by the theme or store setup script.

Browser reproduction after local setup:

```sh
export PATH="$HOME/.local/opt/node/bin:$PATH"
node qa/cart-flow.mjs desktop final
node qa/cart-flow.mjs mobile final
# Optional signed-in account checks after the local account fixture:
node qa/cart-account.mjs
# Run qa/cart-php-checks.php through local WP-CLI, separately from browser runs.
```

Three visual passes were reviewed at each size. They corrected component CSS specificity, tiny summary headings, square confirmation surfaces, drawer image alignment, rose errors and classic account clearfix/grid interactions. Browser tests wait for rendered payment controls and completed address updates, rather than mistaking a transient loading skeleton for a finished page.

## Screenshots

All final names below have **`-desktop.png` and `-mobile.png`** suffixes under `qa/shots/`:

| Prefix | State |
| --- | --- |
| `cart-final-product` | Real variable product, size 70B |
| `cart-final-empty-drawer` | Empty Mini-Cart |
| `cart-final-minicart` | Filled drawer, 61 zł remaining |
| `cart-final-minicart-free` | Quantity two, free-delivery message |
| `cart-final-page` | Filled Cart block |
| `cart-final-page-free` | Cart after quantity update |
| `cart-final-checkout` | Loaded guest checkout |
| `cart-final-checkout-errors` | Required-field/terms validation |
| `cart-final-checkout-filled` | Polish guest data, BACS, accepted terms |
| `cart-final-confirmation` | Actual order received |
| `cart-final-empty-page` | Cart after placing order |
| `cart-final-account` | Login/registration |
| `cart-final-account-dashboard` | Logged-in account navigation/content |
| `cart-final-account-details` | Edit-account form |

Machine-readable evidence: `cart-final-checks-desktop.json`, `cart-final-checks-mobile.json`, `cart-account-checks.json`, `cart-php-checks.log`, `cart-store-setup.log`, `cart-settings-checks.log`. Earlier `cart-iteration*` files preserve the visual iterations, including intermediate states; use **final** images for review.

## Integration boundaries and requests

- Foundation: optionally add the explicit local fixture blueprint step above, or expose a generic post-import local setup hook. No foundation source edits were needed.
- Product/catalog: the product page in QA is the native fallback supplied in this worktree; final product/catalog design belongs to those areas. Real size selection and cart data already work. Cart cross-sells retain the imported paired item with area-scoped minimalist styling.
- Real InPost maps, Przelewy24 and PayU were **not installed or payment-tested**. Their block insertion points remain present, and no unknown extension container is hidden or replaced. Verify the chosen integrations on staging with their own credentials/test modes.
- Split-shipment or coupon-plus-minimum promotions intentionally omit the simple progress promise when it would be misleading; they still use WooCommerce shipping/checkout normally.
- WooCommerce's debug dependency detector can warn about the dependency-free theme script's guarded access to native Blocks globals. It does not add/load another framework; production UI and the checked browser flows have no JavaScript errors. Checkout itself retains WooCommerce's normal JavaScript requirement.
- Production deployment, tax/legal approval, transactional email delivery and live payment confirmation were outside this local theme task.

Implementation references: WooCommerce's [Store API extension guide](https://developer.woocommerce.com/docs/apis/store-api/extending-store-api/extend-store-api-add-data/) and [checkout button filters](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/filters-in-cart-and-checkout/checkout-and-place-order-button/), checked against the installed 11.2.1 source.
