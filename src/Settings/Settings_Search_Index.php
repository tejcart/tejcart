<?php
/**
 * Searchable index of every settings field, used by the admin
 * Cmd-K settings palette.
 *
 * @package TejCart\Settings
 */

declare( strict_types=1 );

namespace TejCart\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Walks the Settings_Tabs registry and produces a flat, JSON-serialisable
 * list of every searchable setting on the admin Settings page.
 *
 * Each entry carries enough breadcrumb context (tab, group, section
 * heading) for the client-side palette to render a Stripe-style result
 * list and a deep-link anchor (`tejcart-field-{tab}-{name}`) so a pick
 * can scroll the specific field into view and flash it on arrival.
 */
class Settings_Search_Index {
    /**
     * Tabs manager.
     *
     * @var Settings_Tabs
     */
    protected Settings_Tabs $tabs;

    /**
     * Optional gateway-list provider. When `null`, gateways are pulled
     * from `\TejCart\Gateways\Gateway_Registry::get_gateways()` at build
     * time. Tests inject a fake list via this callable so they don't
     * need to boot the full DI container.
     *
     * @var callable|null
     */
    protected $gateway_provider;

    /**
     * @param Settings_Tabs|null $tabs             Tabs registry (created on demand when omitted).
     * @param callable|null      $gateway_provider Optional `(): array<object>` for tests.
     */
    public function __construct( ?Settings_Tabs $tabs = null, ?callable $gateway_provider = null ) {
        $this->tabs             = $tabs ?: new Settings_Tabs();
        $this->gateway_provider = $gateway_provider;
    }

    /**
     * Build the searchable index.
     *
     * Returns a list of entries. Each entry is shaped like:
     * ```
     * [
     *   'tab'         => 'general',
     *   'tabLabel'    => 'General',
     *   'tabIcon'     => 'dashicons-admin-generic',
     *   'group'       => 'store',
     *   'groupLabel'  => 'Store',
     *   'section'     => 'REST API',       // last heading row above the field, or ''
     *   'name'        => 'store_name',
     *   'label'       => 'Store Name',
     *   'desc'        => 'Used on emails and the storefront.',
     *   'type'        => 'text',
     *   'anchor'      => 'tejcart-field-general-store_name',
     *   'url'         => 'admin.php?page=tejcart-settings&tab=general#tejcart-field-general-store_name',
     *   'keywords'    => 'store name general store...',
     * ]
     * ```
     *
     * Headings and notes are included as `kind = 'section'` entries so
     * sub-navigation labels ("REST API", "Diagnostics", "Security") are
     * also searchable. Preview-only fields are excluded — they have no
     * persistent value to jump to.
     *
     * Extensions can register additional entries via the
     * `tejcart_settings_search_index` filter.
     *
     * @return array<int, array<string, string>>
     */
    public function build(): array {
        $tabs   = $this->tabs->get_tabs();
        $groups = $this->tabs->get_groups();

        // Reverse map: tab id => [groupId, groupLabel] so each entry
        // carries its group context for breadcrumbs.
        $tab_group = array();
        foreach ( $groups as $group_id => $group ) {
            foreach ( (array) $group['tabs'] as $tab_id ) {
                $tab_group[ $tab_id ] = array(
                    'id'    => (string) $group_id,
                    'label' => (string) $group['label'],
                );
            }
        }

        $entries = array();

        foreach ( $tabs as $tab_id => $tab ) {
            $group        = isset( $tab_group[ $tab_id ] ) ? $tab_group[ $tab_id ] : array( 'id' => '', 'label' => '' );
            $tab_label    = isset( $tab['label'] ) ? (string) $tab['label'] : (string) $tab_id;
            $tab_icon     = isset( $tab['icon'] )  ? (string) $tab['icon']  : '';
            $tab_desc     = isset( $tab['desc'] )  ? (string) $tab['desc']  : '';
            $tab_url      = $this->tab_url( (string) $tab_id );

            // Tab "summary" entry so typing a tab name produces a clean
            // jump-to-tab result even when no field label matches.
            $entries[] = $this->normalise( array(
                'kind'       => 'tab',
                'tab'        => (string) $tab_id,
                'tabLabel'   => $tab_label,
                'tabIcon'    => $tab_icon,
                'group'      => $group['id'],
                'groupLabel' => $group['label'],
                'section'    => '',
                'name'       => '',
                'label'      => $tab_label,
                'desc'       => $tab_desc,
                'type'       => 'tab',
                'anchor'     => '',
                'url'        => $tab_url,
            ) );

            $current_section = '';
            $fields          = (array) $this->tabs->get_tab_fields( (string) $tab_id );

            foreach ( $fields as $field ) {
                if ( ! is_array( $field ) ) {
                    continue;
                }

                $type  = isset( $field['type'] )  ? (string) $field['type']  : 'text';
                $name  = isset( $field['name'] )  ? (string) $field['name']  : '';
                $label = isset( $field['label'] ) ? (string) $field['label'] : '';
                $desc  = $this->extract_desc( $field );

                if ( 'heading' === $type ) {
                    $current_section = $label;
                    $entries[]       = $this->normalise( array(
                        'kind'       => 'section',
                        'tab'        => (string) $tab_id,
                        'tabLabel'   => $tab_label,
                        'tabIcon'    => $tab_icon,
                        'group'      => $group['id'],
                        'groupLabel' => $group['label'],
                        'section'    => $current_section,
                        'name'       => $name,
                        'label'      => $label,
                        'desc'       => $desc,
                        'type'       => 'heading',
                        'anchor'     => $this->anchor_for( (string) $tab_id, $name ),
                        'url'        => $tab_url . '#' . $this->anchor_for( (string) $tab_id, $name ),
                    ) );
                    continue;
                }

                if ( 'preview' === $type ) {
                    // Preview slots are interactive sandboxes, not real
                    // settings — they have no value to land on.
                    continue;
                }

                // `note` rows are non-editable but their label / link
                // text is meaningful navigation ("API Keys", "Tax Rates",
                // "Status & Logs"). Keep them as section-style entries.
                $kind = ( 'note' === $type ) ? 'section' : 'field';

                $entries[] = $this->normalise( array(
                    'kind'       => $kind,
                    'tab'        => (string) $tab_id,
                    'tabLabel'   => $tab_label,
                    'tabIcon'    => $tab_icon,
                    'group'      => $group['id'],
                    'groupLabel' => $group['label'],
                    'section'    => $current_section,
                    'name'       => $name,
                    'label'      => $label,
                    'desc'       => $desc,
                    'type'       => $type,
                    'anchor'     => $this->anchor_for( (string) $tab_id, $name ),
                    'url'        => $tab_url . '#' . $this->anchor_for( (string) $tab_id, $name ),
                ) );
            }

            // Sub-section entries — every tab that ships a horizontal
            // sub-nav (Advanced, Tax, Shipping, plus module-owned tabs
            // like AI Content, Order Tracking, Currency) exposes
            // discoverable destinations that are NOT in `get_tab_fields`.
            // Walk those filters / hardcoded lists so a search for
            // "Prompt Templates" or "API Keys" lands on the right page.
            foreach ( $this->collect_subnav_items( (string) $tab_id ) as $section_key => $section_label ) {
                $section_label = (string) $section_label;
                if ( '' === $section_label ) {
                    continue;
                }
                $section_url = $tab_url;
                if ( '' !== (string) $section_key ) {
                    $section_url .= '&section=' . rawurlencode( (string) $section_key );
                }
                $entries[] = $this->normalise( array(
                    'kind'       => 'section',
                    'tab'        => (string) $tab_id,
                    'tabLabel'   => $tab_label,
                    'tabIcon'    => $tab_icon,
                    'group'      => $group['id'],
                    'groupLabel' => $group['label'],
                    'section'    => $section_label,
                    'name'       => (string) $section_key,
                    'label'      => $section_label,
                    'desc'       => '',
                    'type'       => 'subnav',
                    'anchor'     => '',
                    'url'        => $section_url,
                ) );
            }

            // Payment gateways — the Payments tab body is rendered by
            // `Payment_Methods_List`, not by `get_tab_fields`. Enumerate
            // every registered gateway so a search for "PayPal", "Stripe"
            // (when the addon is installed), "Bank Transfer", "COD",
            // "Apple Pay" etc. lands on the gateway's settings page.
            if ( 'payments' === (string) $tab_id ) {
                foreach ( $this->collect_payment_gateways() as $gateway_entry ) {
                    $entries[] = $this->normalise( array_merge(
                        array(
                            'tab'        => 'payments',
                            'tabLabel'   => $tab_label,
                            'tabIcon'    => $tab_icon,
                            'group'      => $group['id'],
                            'groupLabel' => $group['label'],
                        ),
                        $gateway_entry
                    ) );
                }
            }
        }

        /**
         * Filter the searchable settings index.
         *
         * Modules and addons can append (or replace) entries so their
         * sub-pages and custom fields show up in the Cmd-K palette.
         * Each entry must include `tab`, `label`, `url` at minimum;
         * the filter also normalises the result back through the
         * shaping pass so callers do not need to compute `keywords`.
         *
         * @param array<int, array<string, string>> $entries Default entries built from Settings_Tabs.
         */
        $entries = (array) apply_filters( 'tejcart_settings_search_index', $entries );

        // Re-normalise in case the filter inserted raw entries without
        // a pre-computed `keywords` haystack. Skip anything that does
        // not have a `tab` and `label`.
        $final = array();
        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            if ( ! isset( $entry['tab'], $entry['label'] ) ) {
                continue;
            }
            if ( '' === (string) $entry['label'] ) {
                continue;
            }
            $final[] = $this->normalise( $entry );
        }

        return $final;
    }

    /**
     * Build the deep-link anchor for a field row.
     *
     * Returns `''` for entries without a name (the tab "summary" entry).
     *
     * @param string $tab_id Tab ID.
     * @param string $name   Field option name (no `tejcart_` prefix).
     * @return string
     */
    public function anchor_for( string $tab_id, string $name ): string {
        if ( '' === $tab_id || '' === $name ) {
            return '';
        }
        return 'tejcart-field-' . $tab_id . '-' . $name;
    }

    /**
     * Collect every sub-nav destination registered against a settings tab.
     *
     * Resolves three sources, in order:
     *
     *  1. Hardcoded core sub-navs that Settings_Page renders inline (the
     *     Advanced tab's "API Keys / Webhooks / Tools / Import-Export /
     *     Logs / System Status / Captcha" pages). These exist in
     *     `Settings_Page::render_advanced_sub_nav()` as plain HTML; we
     *     mirror the same key-label map here so the search picks them up
     *     without coupling to that private renderer.
     *
     *  2. Built-in Tax and Shipping sub-navs (Settings + Tax Rates;
     *     Zones) plus their public filters
     *     `tejcart_settings_tax_sub_nav_items` and
     *     `tejcart_settings_shipping_sub_nav_items` so modules like
     *     `tax-providers` can register a "Providers" page that becomes
     *     searchable for free.
     *
     *  3. The generic module sub-nav filter `tejcart_settings_subnav_items_{tab}`
     *     used by every module-owned tab (AI Content, Order Tracking,
     *     Currency Switcher, …). This is what makes "Prompt Templates"
     *     and similar module sub-sections searchable without each module
     *     having to also hook `tejcart_settings_search_index`.
     *
     * @param string $tab_id Tab ID.
     * @return array<string, string> Map of `section_key => label`. Empty
     *                               keys ('') are dropped — they map to
     *                               the tab's default view which already
     *                               has a tab-summary entry.
     */
    protected function collect_subnav_items( string $tab_id ): array {
        $items = array();

        switch ( $tab_id ) {
            case 'advanced':
                // Mirror render_advanced_sub_nav() in Settings_Page.
                $items = array(
                    'api-keys'      => __( 'API Keys', 'tejcart' ),
                    'webhooks'      => __( 'Webhooks', 'tejcart' ),
                    'tools'         => __( 'Tools', 'tejcart' ),
                    'import-export' => __( 'Import / Export', 'tejcart' ),
                    'logs'          => __( 'Logs', 'tejcart' ),
                    'system-status' => __( 'System Status', 'tejcart' ),
                );
                // Module-contributed sections (e.g. the `captcha` module)
                // register through the same sub-nav filter Settings_Page
                // reads, so a disabled module drops out of search too.
                $extra = apply_filters( 'tejcart_settings_advanced_sub_nav_items', array() );
                if ( is_array( $extra ) ) {
                    foreach ( $extra as $key => $label ) {
                        $key = (string) $key;
                        if ( '' === $key || isset( $items[ $key ] ) ) {
                            continue;
                        }
                        $items[ $key ] = (string) $label;
                    }
                }
                break;

            case 'tax':
                $items = array(
                    'rates' => __( 'Tax Rates', 'tejcart' ),
                );
                $extra = apply_filters( 'tejcart_settings_tax_sub_nav_items', array() );
                if ( is_array( $extra ) ) {
                    foreach ( $extra as $key => $label ) {
                        $key = (string) $key;
                        if ( '' === $key || 'rates' === $key ) {
                            continue;
                        }
                        $items[ $key ] = (string) $label;
                    }
                }
                break;

            case 'shipping':
                $items = array(
                    'zones' => __( 'Shipping Zones', 'tejcart' ),
                );
                $extra = apply_filters( 'tejcart_settings_shipping_sub_nav_items', array() );
                if ( is_array( $extra ) ) {
                    foreach ( $extra as $key => $label ) {
                        $key = (string) $key;
                        if ( '' === $key || 'zones' === $key ) {
                            continue;
                        }
                        $items[ $key ] = (string) $label;
                    }
                }
                break;
        }

        // Generic module sub-nav filter — Settings_Page reads this for
        // every tab that doesn't own a hardcoded renderer (AI Content,
        // Order Tracking, Currency Switcher, etc.).
        $module = apply_filters( 'tejcart_settings_subnav_items_' . $tab_id, array(), $tab_id );
        if ( is_array( $module ) ) {
            foreach ( $module as $key => $label ) {
                $key = (string) $key;
                if ( '' === $key ) {
                    continue;
                }
                $items[ $key ] = (string) $label;
            }
        }

        return $items;
    }

    /**
     * Enumerate every registered payment gateway as a search entry.
     *
     * The Payments tab is rendered by `Payment_Methods_List`, not by
     * `Settings_Tabs::get_tab_fields`, so gateways (PayPal, the PPCP
     * card/Apple/Google/Fastlane bundle, COD, Bank Transfer, Check, plus
     * any addon-registered gateway like the Stripe and Authorize.Net
     * addons) would otherwise be invisible to the search. The settings
     * URL routing matches `Payment_Methods_List::get_settings_url()` —
     * PayPal family goes to the unified manage page, everything else to
     * the legacy per-gateway settings page.
     *
     * Returns an empty array when the gateway layer can't be reached
     * (test environment without the registry booted, or extension
     * disabled the registry) so the rest of the index still builds.
     *
     * @return array<int, array<string, string>>
     */
    protected function collect_payment_gateways(): array {
        $resolver = '\\TejCart\\Admin\\Payment_Methods_List';
        $gateways = array();

        try {
            $list = $this->resolve_gateway_list();
        } catch ( \Throwable $e ) {
            return array();
        }
        if ( ! is_array( $list ) ) {
            return array();
        }

        $can_resolve_url = class_exists( $resolver )
            && is_callable( array( $resolver, 'get_settings_url' ) );

        foreach ( $list as $gateway ) {
            if ( ! is_object( $gateway ) ) {
                continue;
            }

            $id    = is_callable( array( $gateway, 'get_id' ) )    ? (string) $gateway->get_id()    : '';
            $title = is_callable( array( $gateway, 'get_title' ) ) ? (string) $gateway->get_title() : '';
            $desc  = is_callable( array( $gateway, 'get_description' ) )
                ? (string) $gateway->get_description()
                : '';

            if ( '' === $id || '' === $title ) {
                continue;
            }

            $url = $can_resolve_url
                ? (string) call_user_func( array( $resolver, 'get_settings_url' ), $id )
                : admin_url( 'admin.php?page=tejcart-settings&tab=payments' );

            $gateways[] = array(
                'kind'    => 'gateway',
                'section' => '',
                'name'    => $id,
                'label'   => $title,
                'desc'    => wp_strip_all_tags( $desc ),
                'type'    => 'gateway',
                'anchor'  => '',
                'url'     => $url,
            );
        }

        return $gateways;
    }

    /**
     * Resolve the list of gateway objects to walk.
     *
     * Defers to the injected callable (set via the constructor for
     * tests) when present, otherwise calls the real Gateway_Registry.
     * Returns an empty array when the registry class is unavailable
     * (e.g. extension-disabled environments) so the index still builds.
     *
     * @return array<int, object>
     */
    protected function resolve_gateway_list(): array {
        if ( null !== $this->gateway_provider ) {
            $list = call_user_func( $this->gateway_provider );
            return is_array( $list ) ? $list : array();
        }
        $registry = '\\TejCart\\Gateways\\Gateway_Registry';
        if ( ! class_exists( $registry ) || ! is_callable( array( $registry, 'get_gateways' ) ) ) {
            return array();
        }
        $list = call_user_func( array( $registry, 'get_gateways' ) );
        return is_array( $list ) ? $list : array();
    }

    /**
     * Resolve the settings tab URL.
     *
     * @param string $tab_id Tab ID.
     * @return string
     */
    protected function tab_url( string $tab_id ): string {
        return admin_url( 'admin.php?page=tejcart-settings&tab=' . rawurlencode( $tab_id ) );
    }

    /**
     * Extract a plain-text description from a field definition.
     *
     * Notes can include simple HTML (anchor tags, code), and other field
     * defs may use either `desc` or `description`. Strip tags so the
     * client-side fuzzy-match index is plain text.
     *
     * @param array<string, mixed> $field Field config.
     * @return string
     */
    protected function extract_desc( array $field ): string {
        $raw = '';
        if ( isset( $field['description'] ) && '' !== (string) $field['description'] ) {
            $raw = (string) $field['description'];
        } elseif ( isset( $field['desc'] ) ) {
            $raw = (string) $field['desc'];
        }
        if ( '' === $raw ) {
            return '';
        }
        $text = wp_strip_all_tags( $raw );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
    }

    /**
     * Ensure every entry has the same key set + a pre-computed lowercase
     * `keywords` haystack so the client never has to lowercase on every
     * keystroke.
     *
     * @param array<string, mixed> $entry Raw entry.
     * @return array<string, string>
     */
    protected function normalise( array $entry ): array {
        $defaults = array(
            'kind'       => 'field',
            'tab'        => '',
            'tabLabel'   => '',
            'tabIcon'    => '',
            'group'      => '',
            'groupLabel' => '',
            'section'    => '',
            'name'       => '',
            'label'      => '',
            'desc'       => '',
            'type'       => 'text',
            'anchor'     => '',
            'url'        => '',
            'keywords'   => '',
        );

        $merged = array_merge( $defaults, $entry );

        foreach ( $merged as $key => $value ) {
            $merged[ $key ] = is_scalar( $value ) ? (string) $value : '';
        }

        if ( '' === $merged['keywords'] ) {
            $merged['keywords'] = $this->build_keywords( $merged );
        } else {
            $merged['keywords'] = strtolower( $merged['keywords'] );
        }

        return $merged;
    }

    /**
     * Build the lowercase haystack used by the client-side substring
     * scorer. Includes the label, description, section, tab label,
     * group label, plus a few synonyms derived from the field type so
     * a search for "toggle" or "dropdown" still finds the right control.
     *
     * @param array<string, string> $entry Normalised entry.
     * @return string
     */
    protected function build_keywords( array $entry ): string {
        $parts = array(
            $entry['label'],
            $entry['desc'],
            $entry['section'],
            $entry['tabLabel'],
            $entry['groupLabel'],
            $entry['name'],
        );

        switch ( $entry['type'] ) {
            case 'checkbox':
                $parts[] = 'toggle switch enable disable';
                break;
            case 'select':
            case 'radio':
                $parts[] = 'dropdown choose option';
                break;
            case 'color':
                $parts[] = 'color colour hex';
                break;
            case 'textarea':
            case 'wysiwyg':
                $parts[] = 'text';
                break;
            case 'gateway':
                $parts[] = 'payment method gateway checkout';
                break;
            case 'subnav':
                $parts[] = 'page section';
                break;
        }

        $haystack = strtolower( implode( ' ', array_filter( array_map( 'strval', $parts ) ) ) );
        return trim( preg_replace( '/\s+/u', ' ', $haystack ) ?? '' );
    }
}
