<?php
/**
 * PayPal Gateway Settings
 *
 * @package TejCart\Gateways\PayPal
 */

declare( strict_types=1 );

namespace TejCart\Gateways\PayPal;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Defines all settings fields for the PayPal payment gateway.
 */
class PayPal_Settings {
    /**
     * Get the full array of PayPal gateway settings fields.
     *
     * @return array Associative array of field definitions keyed by field ID.
     */
    public function get_form_fields(): array {
        return array(

            'paypal_connection'        => array(
                'type'        => 'connection',
                'title'       => __( 'Connection Status', 'tejcart' ),
                'description' => '',
                'default'     => '',
            ),

            'general_heading'          => array(
                'type'        => 'heading',
                'title'       => __( 'General', 'tejcart' ),
                'description' => __( 'Basic display settings for PayPal at checkout.', 'tejcart' ),
            ),
            'enabled'                  => array(
                'type'        => 'checkbox',
                'title'       => __( 'Enable/Disable', 'tejcart' ),
                'description' => __( 'Enable PayPal as a payment method.', 'tejcart' ),
                'default'     => 'no',
            ),
            'title'                    => array(
                'type'        => 'text',
                'title'       => __( 'Title', 'tejcart' ),
                'description' => __( 'The title displayed to customers during checkout.', 'tejcart' ),
                'default'     => __( 'PayPal', 'tejcart' ),
            ),
            'description'              => array(
                'type'        => 'textarea',
                'title'       => __( 'Description', 'tejcart' ),
                'description' => __( 'The description displayed to customers during checkout.', 'tejcart' ),
                'default'     => __( 'Pay securely via PayPal.', 'tejcart' ),
            ),

            'sandbox_mode'             => array(
                'type'        => 'segmented',
                'title'       => __( 'Environment', 'tejcart' ),
                'description' => __( 'Sandbox lets you test with fake credentials. Flip to Live before accepting real payments.', 'tejcart' ),
                'default'     => 'yes',
                'options'     => array(
                    'yes' => __( 'Sandbox', 'tejcart' ),
                    'no'  => __( 'Live', 'tejcart' ),
                ),
            ),

            'credentials_heading'      => array(
                'type'        => 'heading',
                'title'       => __( 'Manual credentials', 'tejcart' ),
                'description' => __( 'Optional. Use these fields only if you cannot complete the one-click Connect flow above.', 'tejcart' ),
                'collapsible' => true,
                'collapsed'   => true,
            ),
            'client_id'                => array(
                'type'        => 'text',
                'title'       => __( 'Live Client ID', 'tejcart' ),
                'description' => '',
                'default'     => '',
                'env'         => 'live',
            ),
            'client_secret'            => array(
                'type'        => 'password',
                'title'       => __( 'Live Client Secret', 'tejcart' ),
                'description' => '',
                'default'     => '',
                'env'         => 'live',
            ),
            'sandbox_client_id'        => array(
                'type'        => 'text',
                'title'       => __( 'Sandbox Client ID', 'tejcart' ),
                'description' => '',
                'default'     => '',
                'env'         => 'sandbox',
            ),
            'sandbox_client_secret'    => array(
                'type'        => 'password',
                'title'       => __( 'Sandbox Client Secret', 'tejcart' ),
                'description' => '',
                'default'     => '',
                'env'         => 'sandbox',
            ),

            'checkout_heading'         => array(
                'type'        => 'heading',
                'title'       => __( 'Checkout Behavior', 'tejcart' ),
                'description' => __( 'Control how the PayPal checkout flow behaves and what data is sent.', 'tejcart' ),
            ),
            'brand_name'               => array(
                'type'        => 'text',
                'title'       => __( 'Brand Name', 'tejcart' ),
                'description' => __( 'Your brand name shown on the PayPal review page. Defaults to your store name when blank. Maximum 127 characters.', 'tejcart' ),
                'default'     => '',
            ),
            'soft_descriptor'          => array(
                'type'        => 'text',
                'title'       => __( 'Statement Descriptor', 'tejcart' ),
                'description' => __( 'Text shown on the buyer\'s card or bank statement. Maximum 22 characters. Allowed: letters, numbers, dot, comma, dash, and space.', 'tejcart' ),
                'default'     => '',
            ),
            'invoice_prefix'           => array(
                'type'        => 'text',
                'title'       => __( 'Invoice Prefix', 'tejcart' ),
                'description' => __( 'Prefix added to your order numbers when sent to PayPal. Useful when running multiple stores on the same PayPal account to avoid duplicate invoice errors.', 'tejcart' ),
                'default'     => 'TEJ-',
            ),
            'locale'                   => array(
                'type'        => 'select',
                'title'       => __( 'Force PayPal Language', 'tejcart' ),
                'description' => __( 'Forces the PayPal checkout pages and Smart Buttons to render in a specific language. Choose "Auto" to use the buyer\'s browser language.', 'tejcart' ),
                'default'     => '',
                'options'     => array(
                    ''      => __( 'Auto (browser locale)', 'tejcart' ),
                    'ar_EG' => __( 'Arabic (Egypt)', 'tejcart' ),
                    'cs_CZ' => __( 'Czech', 'tejcart' ),
                    'da_DK' => __( 'Danish', 'tejcart' ),
                    'de_DE' => __( 'German', 'tejcart' ),
                    'el_GR' => __( 'Greek', 'tejcart' ),
                    'en_AU' => __( 'English (Australia)', 'tejcart' ),
                    'en_GB' => __( 'English (UK)', 'tejcart' ),
                    'en_IN' => __( 'English (India)', 'tejcart' ),
                    'en_US' => __( 'English (US)', 'tejcart' ),
                    'es_ES' => __( 'Spanish (Spain)', 'tejcart' ),
                    'es_XC' => __( 'Spanish (Worldwide)', 'tejcart' ),
                    'fi_FI' => __( 'Finnish', 'tejcart' ),
                    'fr_CA' => __( 'French (Canada)', 'tejcart' ),
                    'fr_FR' => __( 'French (France)', 'tejcart' ),
                    'fr_XC' => __( 'French (Worldwide)', 'tejcart' ),
                    'he_IL' => __( 'Hebrew (Israel)', 'tejcart' ),
                    'hu_HU' => __( 'Hungarian', 'tejcart' ),
                    'id_ID' => __( 'Indonesian', 'tejcart' ),
                    'it_IT' => __( 'Italian', 'tejcart' ),
                    'ja_JP' => __( 'Japanese', 'tejcart' ),
                    'ko_KR' => __( 'Korean', 'tejcart' ),
                    'nl_NL' => __( 'Dutch', 'tejcart' ),
                    'no_NO' => __( 'Norwegian', 'tejcart' ),
                    'pl_PL' => __( 'Polish', 'tejcart' ),
                    'pt_BR' => __( 'Portuguese (Brazil)', 'tejcart' ),
                    'pt_PT' => __( 'Portuguese (Portugal)', 'tejcart' ),
                    'ru_RU' => __( 'Russian', 'tejcart' ),
                    'sk_SK' => __( 'Slovak', 'tejcart' ),
                    'sv_SE' => __( 'Swedish', 'tejcart' ),
                    'th_TH' => __( 'Thai', 'tejcart' ),
                    'tr_TR' => __( 'Turkish', 'tejcart' ),
                    'zh_CN' => __( 'Chinese (Simplified)', 'tejcart' ),
                    'zh_HK' => __( 'Chinese (Hong Kong)', 'tejcart' ),
                    'zh_TW' => __( 'Chinese (Taiwan)', 'tejcart' ),
                    'zh_XC' => __( 'Chinese (Worldwide)', 'tejcart' ),
                ),
            ),
            'landing_page'             => array(
                'type'        => 'select',
                'title'       => __( 'Landing Page', 'tejcart' ),
                'description' => __( 'Controls which page customers see when they click the PayPal button.', 'tejcart' ),
                'default'     => 'NO_PREFERENCE',
                'options'     => array(
                    'LOGIN'         => __( 'PayPal Account Login', 'tejcart' ),
                    'BILLING'       => __( 'Card / Billing Information', 'tejcart' ),
                    'NO_PREFERENCE' => __( 'PayPal Default (Recommended)', 'tejcart' ),
                ),
            ),
            'shipping_preference'      => array(
                'type'        => 'select',
                'title'       => __( 'Shipping Preference', 'tejcart' ),
                'description' => __( 'Controls how shipping address is handled during checkout.', 'tejcart' ),
                'default'     => 'GET_FROM_FILE',
                'options'     => array(
                    'GET_FROM_FILE'        => __( 'Use Buyer\'s PayPal Address (Recommended)', 'tejcart' ),
                    'NO_SHIPPING'          => __( 'No Shipping (Digital Products Only)', 'tejcart' ),
                    'SET_PROVIDED_ADDRESS' => __( 'Lock to Checkout Address', 'tejcart' ),
                ),
            ),
            'user_action'              => array(
                'type'        => 'select',
                'title'       => __( 'PayPal Review Page Button', 'tejcart' ),
                'description' => __( 'Controls the button label and behavior on the PayPal review page.', 'tejcart' ),
                'default'     => 'PAY_NOW',
                'options'     => array(
                    'PAY_NOW'  => __( 'Complete Payment Now', 'tejcart' ),
                    'CONTINUE' => __( 'Return to Store for Final Review', 'tejcart' ),
                ),
            ),
            'payment_action'           => array(
                'type'        => 'select',
                'title'       => __( 'Payment Action', 'tejcart' ),
                'description' => __( 'Choose whether to charge customer immediately or authorize and capture later.', 'tejcart' ),
                'default'     => 'capture',
                'options'     => array(
                    'capture'   => __( 'Charge Immediately (Recommended)', 'tejcart' ),
                    'authorize' => __( 'Authorize & Capture Later', 'tejcart' ),
                ),
            ),
            'save_payment_methods'     => array(
                'type'        => 'checkbox',
                'title'       => __( 'Save Payment Methods', 'tejcart' ),
                'description' => __( 'Allow logged-in customers to securely save their PayPal accounts for faster future purchases. Requires Vaulting to be enabled on your PayPal account.', 'tejcart' ),
                'default'     => 'yes',
            ),
            'send_line_items'          => array(
                'type'        => 'checkbox',
                'title'       => __( 'Send Cart Line Items', 'tejcart' ),
                'description' => __( 'Send detailed cart contents (product names, SKUs, quantities) to PayPal. Required for Pay Later eligibility in some markets.', 'tejcart' ),
                'default'     => 'yes',
            ),
            'capture_virtual_only'     => array(
                'type'        => 'checkbox',
                'title'       => __( 'Auto-capture Virtual Orders', 'tejcart' ),
                'description' => __( 'When using "Authorize & Capture Later" mode, automatically capture orders containing only virtual or downloadable products.', 'tejcart' ),
                'default'     => 'no',
            ),

            'subgateways_heading'      => array(
                'type'        => 'heading',
                'title'       => __( 'Alternative Payment Methods', 'tejcart' ),
                'description' => __( 'Toggles for alternative payment methods funded by PayPal PPCP that do not have a dedicated settings page. Google Pay, Apple Pay, Card, Fastlane and Pay Later are configured on their own settings pages.', 'tejcart' ),
            ),
            'enable_venmo'             => array(
                'type'        => 'checkbox',
                'title'       => __( 'Venmo', 'tejcart' ),
                'description' => __( 'Enable Venmo payments (US only).', 'tejcart' ),
                'default'     => 'yes',
            ),
            'enable_paylater'          => array(
                'type'        => 'checkbox',
                'title'       => __( 'Pay Later Messaging', 'tejcart' ),
                'description' => __( 'Master switch for the PayPal Pay Later / BNPL message. Use the placements and style controls below to fine-tune where and how it renders.', 'tejcart' ),
                'default'     => 'no',
            ),

            'paylater_placements_heading' => array(
                'type'        => 'heading',
                'title'       => __( 'Pay Later Placements', 'tejcart' ),
                'description' => __( 'Surface-by-surface control of the Pay Later messaging block. PayPal recommends rendering messaging on the product, cart, and checkout pages so eligible buyers see financing options before they commit.', 'tejcart' ),
                'parent'      => 'enable_paylater',
            ),
            'paylater_product_page'    => array(
                'type'        => 'checkbox',
                'title'       => __( 'Product Page', 'tejcart' ),
                'description' => __( 'Show Pay Later messaging on individual product pages (PayPal recommended).', 'tejcart' ),
                'default'     => 'yes',
                'parent'      => 'enable_paylater',
            ),
            'paylater_cart_page'       => array(
                'type'        => 'checkbox',
                'title'       => __( 'Cart Page', 'tejcart' ),
                'description' => __( 'Show Pay Later messaging on the cart page (PayPal recommended).', 'tejcart' ),
                'default'     => 'yes',
                'parent'      => 'enable_paylater',
            ),
            'paylater_side_cart'       => array(
                'type'        => 'checkbox',
                'title'       => __( 'Side Cart (Drawer)', 'tejcart' ),
                'description' => __( 'Show Pay Later messaging inside the slide-out cart drawer.', 'tejcart' ),
                'default'     => 'no',
                'parent'      => 'enable_paylater',
            ),
            'paylater_checkout'        => array(
                'type'        => 'checkbox',
                'title'       => __( 'Checkout (Above Form)', 'tejcart' ),
                'description' => __( 'Show Pay Later messaging at the top of the checkout page (PayPal recommended).', 'tejcart' ),
                'default'     => 'yes',
                'parent'      => 'enable_paylater',
            ),
            'paylater_express_checkout' => array(
                'type'        => 'checkbox',
                'title'       => __( 'Express Checkout Section', 'tejcart' ),
                'description' => __( 'Show Pay Later messaging beneath the express-checkout buttons. PayPal recommends keeping this off when you already render messaging above the form to avoid duplication.', 'tejcart' ),
                'default'     => 'no',
                'parent'      => 'enable_paylater',
            ),

            'paylater_style_heading'   => array(
                'type'        => 'heading',
                'title'       => __( 'Pay Later Messaging Style', 'tejcart' ),
                'description' => __( 'Customize the visual style of the Pay Later message. Defaults follow PayPal\'s recommended styles for highest conversion.', 'tejcart' ),
                'parent'      => 'enable_paylater',
            ),
            'paylater_style_layout'    => array(
                'type'        => 'select',
                'title'       => __( 'Message Layout', 'tejcart' ),
                'description' => __( 'Text is a single inline sentence (recommended for product/cart). Flex is a banner card (recommended for checkout / high-value carts).', 'tejcart' ),
                'default'     => 'text',
                'options'     => array(
                    'text' => __( 'Text (Recommended)', 'tejcart' ),
                    'flex' => __( 'Flex (Banner)', 'tejcart' ),
                ),
                'parent'      => 'enable_paylater',
            ),
            'paylater_style_logo_type' => array(
                'type'        => 'select',
                'title'       => __( 'Logo Type', 'tejcart' ),
                'description' => __( 'Controls the PayPal logo treatment in the message. "Primary" is the full-color PayPal mark.', 'tejcart' ),
                'default'     => 'primary',
                'options'     => array(
                    'primary'     => __( 'Primary (Full Color)', 'tejcart' ),
                    'alternative' => __( 'Alternative (Mono PayPal)', 'tejcart' ),
                    'inline'      => __( 'Inline (Logo Within Text)', 'tejcart' ),
                    'none'        => __( 'None (Text Only)', 'tejcart' ),
                ),
                'parent'      => 'enable_paylater',
            ),
            'paylater_style_text_color' => array(
                'type'        => 'select',
                'title'       => __( 'Text Color', 'tejcart' ),
                'description' => __( 'Choose a color that contrasts with the surface the message sits on. White is for dark backgrounds.', 'tejcart' ),
                'default'     => 'black',
                'options'     => array(
                    'black'      => __( 'Black (Default)', 'tejcart' ),
                    'white'      => __( 'White (For Dark Backgrounds)', 'tejcart' ),
                    'monochrome' => __( 'Monochrome', 'tejcart' ),
                    'grayscale'  => __( 'Grayscale', 'tejcart' ),
                ),
                'parent'      => 'enable_paylater',
            ),

            'smart_button_heading'     => array(
                'type'        => 'heading',
                'title'       => __( 'Smart Button Placement', 'tejcart' ),
                'description' => __( 'Choose where PayPal Smart Buttons appear on your store.', 'tejcart' ),
            ),
            'button_product_page'      => array(
                'type'        => 'checkbox',
                'title'       => __( 'Product Page', 'tejcart' ),
                'description' => __( 'Show Smart Buttons on individual product pages.', 'tejcart' ),
                'default'     => 'yes',
            ),
            'button_cart_page'         => array(
                'type'        => 'checkbox',
                'title'       => __( 'Cart Page', 'tejcart' ),
                'description' => __( 'Show Smart Buttons on the cart page.', 'tejcart' ),
                'default'     => 'yes',
            ),
            'button_express_checkout'  => array(
                'type'        => 'checkbox',
                'title'       => __( 'Express Checkout (Top of Checkout)', 'tejcart' ),
                'description' => __( 'Show Smart Buttons at the top of the checkout page for quick payment.', 'tejcart' ),
                'default'     => 'yes',
            ),
            'button_side_cart'         => array(
                'type'        => 'checkbox',
                'title'       => __( 'Side Cart (Cart Drawer)', 'tejcart' ),
                'description' => __( 'Show Smart Buttons in the slide-out cart drawer.', 'tejcart' ),
                'default'     => 'yes',
            ),
            'button_checkout'          => array(
                'type'        => 'checkbox',
                'title'       => __( 'Checkout Payment Section', 'tejcart' ),
                'description' => __( 'Offer PayPal as a selectable method in the checkout payment section. The PayPal Smart Button is the inline payment method, so turning this off removes PayPal from the methods list entirely — buyers can still pay with PayPal through the express checkout buttons above the form.', 'tejcart' ),
                'default'     => 'yes',
            ),

            'button_style_heading'     => array(
                'type'        => 'heading',
                'title'       => __( 'Smart Button Style', 'tejcart' ),
                'description' => __( 'Customize the appearance of PayPal Smart Buttons on your store.', 'tejcart' ),
            ),
            'button_layout'            => array(
                'type'        => 'select',
                'title'       => __( 'Button Layout', 'tejcart' ),
                'description' => __( 'How PayPal buttons are arranged on your store.', 'tejcart' ),
                'default'     => 'vertical',
                'options'     => array(
                    'vertical'   => __( 'Stacked (One Per Row)', 'tejcart' ),
                    'horizontal' => __( 'Side by Side (Recommended)', 'tejcart' ),
                ),
            ),
            'button_color'             => array(
                'type'        => 'select',
                'title'       => __( 'Button Color', 'tejcart' ),
                'description' => __( 'PayPal button color theme. Gold is PayPal recommended.', 'tejcart' ),
                'default'     => 'gold',
                'options'     => array(
                    'gold'   => __( 'Gold (PayPal Recommended)', 'tejcart' ),
                    'blue'   => __( 'Blue', 'tejcart' ),
                    'silver' => __( 'Silver', 'tejcart' ),
                    'white'  => __( 'White', 'tejcart' ),
                    'black'  => __( 'Black', 'tejcart' ),
                ),
            ),
            'button_shape'             => array(
                'type'        => 'select',
                'title'       => __( 'Button Shape', 'tejcart' ),
                'description' => __( 'Button corner style.', 'tejcart' ),
                'default'     => 'rect',
                'options'     => array(
                    'rect' => __( 'Rectangular (Sharp Corners)', 'tejcart' ),
                    'pill' => __( 'Rounded (Pill Shape)', 'tejcart' ),
                ),
            ),
            'button_label'             => array(
                'type'        => 'select',
                'title'       => __( 'Button Label', 'tejcart' ),
                'description' => __( 'Text intent of the PayPal button. PayPal\'s Web SDK v6 currently renders the standard PayPal mark; this label drives the admin preview and forward-compatible button intent.', 'tejcart' ),
                'default'     => 'paypal',
                'options'     => array(
                    'paypal'    => __( 'PayPal (Recommended)', 'tejcart' ),
                    'checkout'  => __( 'Checkout', 'tejcart' ),
                    'buynow'    => __( 'Buy Now', 'tejcart' ),
                    'pay'       => __( 'Pay', 'tejcart' ),
                    'subscribe' => __( 'Subscribe', 'tejcart' ),
                    'donate'    => __( 'Donate', 'tejcart' ),
                ),
            ),
            'button_tagline'           => array(
                'type'        => 'checkbox',
                'title'       => __( 'Show Tagline', 'tejcart' ),
                'description' => __( 'Display the PayPal tagline beneath buttons. Only available when Button Layout is "Side by Side" and Button Color is "Gold".', 'tejcart' ),
                'default'     => 'no',
            ),
            'button_height'            => array(
                'type'        => 'number',
                'title'       => __( 'Button Height (px)', 'tejcart' ),
                'description' => __( 'Height of PayPal buttons in pixels. Allowed range: 25-55. Leave blank to use the PayPal default.', 'tejcart' ),
                'default'     => '',
                'min'         => 25,
                'max'         => 55,
                'step'        => 1,
            ),

            'advanced_heading'         => array(
                'type'        => 'heading',
                'title'       => __( 'Advanced', 'tejcart' ),
                'description' => __( 'Advanced configuration for funding sources and logging.', 'tejcart' ),
            ),
            'disable_funding'          => array(
                'type'        => 'multicheck',
                'title'       => __( 'Disable Funding Sources', 'tejcart' ),
                'description' => __( 'Hide selected funding sources at checkout. Leave all unchecked to allow every PayPal-eligible method for this account.', 'tejcart' ),
                'default'     => '',
                'columns'     => 3,

                'options'     => array(
                    'card'        => __( 'Credit / Debit Card', 'tejcart' ),
                    'credit'      => __( 'PayPal Credit', 'tejcart' ),
                    'paylater'    => __( 'Pay Later', 'tejcart' ),
                    'venmo'       => __( 'Venmo', 'tejcart' ),
                    'bancontact'  => __( 'Bancontact', 'tejcart' ),
                    'blik'        => __( 'BLIK', 'tejcart' ),
                    'eps'         => __( 'eps', 'tejcart' ),
                    'giropay'     => __( 'giropay', 'tejcart' ),
                    'ideal'       => __( 'iDEAL', 'tejcart' ),
                    'mercadopago' => __( 'Mercado Pago', 'tejcart' ),
                    'multibanco'  => __( 'Multibanco', 'tejcart' ),
                    'mybank'      => __( 'MyBank', 'tejcart' ),
                    'oxxo'        => __( 'OXXO', 'tejcart' ),
                    'p24'         => __( 'Przelewy24', 'tejcart' ),
                    'sepa'        => __( 'SEPA Direct Debit', 'tejcart' ),
                    'sofort'      => __( 'Sofort', 'tejcart' ),
                    'trustly'     => __( 'Trustly', 'tejcart' ),
                    'wechatpay'   => __( 'WeChat Pay', 'tejcart' ),
                ),
            ),
            'disable_cards'            => array(
                'type'        => 'multicheck',
                'title'       => __( 'Disable Card Brands', 'tejcart' ),
                'description' => __( 'Hide selected card brands from the Advanced Card Fields. Useful for regions where you do not want to accept a specific network.', 'tejcart' ),
                'default'     => '',
                'columns'     => 3,

                'options'     => array(
                    'visa'       => __( 'Visa', 'tejcart' ),
                    'mastercard' => __( 'Mastercard', 'tejcart' ),
                    'amex'       => __( 'American Express', 'tejcart' ),
                    'discover'   => __( 'Discover', 'tejcart' ),
                    'jcb'        => __( 'JCB', 'tejcart' ),
                    'elo'        => __( 'Elo', 'tejcart' ),
                    'hiper'      => __( 'Hiper', 'tejcart' ),
                    'maestro'    => __( 'Maestro', 'tejcart' ),
                    'cup'        => __( 'UnionPay (CUP)', 'tejcart' ),
                ),
            ),
        );
    }
}
