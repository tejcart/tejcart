<?php
/**
 * Product Type Registry.
 *
 * @package TejCart\Product
 */

declare( strict_types=1 );

namespace TejCart\Product;

use TejCart\Product\Product_Types\Physical_Product;
use TejCart\Product\Product_Types\Digital_Product;
use TejCart\Product\Product_Types\Virtual_Product;
use TejCart\Product\Product_Types\Bundle_Product;
use TejCart\Product\Product_Types\Variable_Product;
use TejCart\Product\Product_Types\Variation;
use TejCart\Product\Product_Types\Grouped_Product;
use TejCart\Product\Product_Types\External_Product;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Single source of truth for product-type metadata.
 *
 * Third-party plugins register a new product type (subscription, pre-order,
 * deposit, rental, etc.) by hooking the `tejcart_product_types` filter and
 * adding an entry of the shape:
 *
 *     [
 *         'label'       => 'Subscription',
 *         'description' => 'Recurring billing.',
 *         'icon'        => 'update',                          // Dashicon slug.
 *         'class'       => My_Subscription_Product::class,    // Extends Abstract_Product.
 *         'tabs'        => [ 'overview', 'pricing', 'inventory', 'subscription', 'links' ],
 *         'supports'    => [ 'price', 'inventory', 'sku', 'recurring' ],
 *         'admin'       => true,    // Show in admin "new product" hero / list filter.
 *         'rest'        => true,    // Accept via REST schema enums.
 *         'needs_shipping' => false,
 *     ]
 *
 * The class must extend {@see \TejCart\Product\Product_Types\Abstract_Product}
 * and implement `get_type()` returning the same slug used as the array key.
 */
final class Product_Type_Registry {
    /**
     * Memoised result of the filtered registry for the current request.
     *
     * Cleared via {@see clear_cache()} after a `tejcart_product_types` filter
     * is registered late (e.g. in tests).
     *
     * @var array<string, array<string, mixed>>|null
     */
    private static $cache = null;

    /**
     * The supported keys of a single type definition. Anything else passed
     * through the filter is preserved as-is so addons can stash arbitrary
     * metadata under their own keys.
     *
     * @var string[]
     */
    private const KNOWN_KEYS = [
        'label',
        'description',
        'icon',
        'class',
        'tabs',
        'supports',
        'admin',
        'rest',
        'needs_shipping',
        'is_virtual',
    ];

    /**
     * Built-in defaults. Each entry is intentionally complete so addons that
     * only override a single key (e.g. relabelling "Physical" to "Goods")
     * don't have to repeat the rest.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function defaults(): array {
        return [
            'physical'  => [
                'label'          => __( 'Physical', 'tejcart' ),
                'description'    => __( 'Ships to a customer.', 'tejcart' ),
                'icon'           => 'archive',
                'class'          => Physical_Product::class,
                'tabs'           => [ 'overview', 'pricing', 'inventory', 'shipping', 'links' ],
                'supports'       => [ 'price', 'sku', 'inventory', 'shipping', 'tax', 'reviews' ],
                'admin'          => true,
                'rest'           => true,
                'needs_shipping' => true,
                'is_virtual'     => false,
            ],
            'digital'   => [
                'label'          => __( 'Digital', 'tejcart' ),
                'description'    => __( 'Downloadable file delivered after purchase.', 'tejcart' ),
                'icon'           => 'download',
                'class'          => Digital_Product::class,
                'tabs'           => [ 'overview', 'pricing', 'inventory', 'files', 'links' ],
                'supports'       => [ 'price', 'sku', 'inventory', 'tax', 'reviews', 'downloads' ],
                'admin'          => true,
                'rest'           => true,
                'needs_shipping' => false,
                'is_virtual'     => true,
            ],
            'virtual'   => [
                'label'          => __( 'Virtual', 'tejcart' ),
                'description'    => __( 'Service or non-shippable item.', 'tejcart' ),
                'icon'           => 'admin-generic',
                'class'          => Virtual_Product::class,
                'tabs'           => [ 'overview', 'pricing', 'inventory', 'links' ],
                'supports'       => [ 'price', 'sku', 'inventory', 'tax', 'reviews' ],
                'admin'          => true,
                'rest'           => true,
                'needs_shipping' => false,
                'is_virtual'     => true,
            ],
            'bundle'    => [
                'label'          => __( 'Bundle', 'tejcart' ),
                'description'    => __( 'Multiple products sold together.', 'tejcart' ),
                'icon'           => 'screenoptions',
                'class'          => Bundle_Product::class,
                'tabs'           => [ 'overview', 'pricing', 'inventory', 'components', 'links' ],
                'supports'       => [ 'price', 'sku', 'inventory', 'tax', 'reviews', 'bundled_items', 'derived_price' ],
                'admin'          => true,
                'rest'           => true,
                'needs_shipping' => true,
                'is_virtual'     => false,
            ],
            'external'  => [
                'label'          => __( 'External / Affiliate', 'tejcart' ),
                'description'    => __( 'Links to an external or affiliate page.', 'tejcart' ),
                'icon'           => 'admin-site-alt3',
                'class'          => External_Product::class,
                'tabs'           => [ 'overview', 'pricing', 'external', 'links' ],
                'supports'       => [ 'price', 'sku', 'reviews', 'external_url' ],
                'admin'          => true,
                'rest'           => true,
                'needs_shipping' => false,
                'is_virtual'     => true,
            ],
            'variable'  => [
                'label'          => __( 'Variable', 'tejcart' ),
                'description'    => __( 'Different sizes, colours or other options.', 'tejcart' ),
                'icon'           => 'editor-table',
                'class'          => Variable_Product::class,
                'tabs'           => [ 'overview', 'pricing', 'variations', 'shipping', 'links' ],
                'supports'       => [ 'price', 'sku', 'inventory', 'shipping', 'tax', 'reviews', 'variations', 'derived_price' ],
                'admin'          => true,
                'rest'           => true,
                'needs_shipping' => true,
                'is_virtual'     => false,
            ],
            'grouped'   => [
                'label'          => __( 'Grouped', 'tejcart' ),
                'description'    => __( 'A display container for products bought separately.', 'tejcart' ),
                'icon'           => 'networking',
                'class'          => Grouped_Product::class,
                'tabs'           => [ 'overview', 'children', 'links' ],
                'supports'       => [ 'reviews', 'grouped_children', 'derived_price' ],
                'admin'          => true,
                'rest'           => true,
                'needs_shipping' => false,
                'is_virtual'     => false,
            ],
            'variation' => [
                'label'          => __( 'Variation', 'tejcart' ),
                'description'    => __( 'A single variation of a variable parent.', 'tejcart' ),
                'icon'           => 'editor-table',
                'class'          => Variation::class,
                'tabs'           => [],
                'supports'       => [ 'price', 'sku', 'inventory', 'shipping', 'tax' ],
                'admin'          => false, // Created via the Variable parent, not directly.
                'rest'           => true,  // Listed in REST `?type=variation` queries.
                'needs_shipping' => true,
                'is_virtual'     => false,
            ],
        ];
    }

    /**
     * Get the full filtered registry, keyed by type slug.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function get_types(): array {
        if ( null !== self::$cache ) {
            return self::$cache;
        }

        /**
         * Filter the product type registry.
         *
         * Add a new product type by inserting an entry keyed by your type
         * slug. The class you point at MUST extend
         * {@see \TejCart\Product\Product_Types\Abstract_Product} and return
         * the same slug from `get_type()`.
         *
         * Example — register a "subscription" type:
         *
         *     add_filter( 'tejcart_product_types', function ( array $types ): array {
         *         $types['subscription'] = [
         *             'label'       => __( 'Subscription', 'my-addon' ),
         *             'description' => __( 'Recurring billing.', 'my-addon' ),
         *             'icon'        => 'update',
         *             'class'       => \My_Addon\Subscription_Product::class,
         *             'tabs'        => [ 'overview', 'pricing', 'inventory', 'subscription', 'links' ],
         *             'supports'    => [ 'price', 'sku', 'inventory', 'recurring' ],
         *             'admin'       => true,
         *             'rest'        => true,
         *         ];
         *         return $types;
         *     } );
         *
         * @param array<string, array<string, mixed>> $types Type slug => metadata array.
         */
        $types = (array) apply_filters( 'tejcart_product_types', self::defaults() );

        $normalised = [];
        foreach ( $types as $slug => $definition ) {
            if ( ! is_string( $slug ) || '' === $slug || ! is_array( $definition ) ) {
                continue;
            }
            $clean = self::normalise( (string) $slug, $definition );
            if ( null === $clean ) {
                continue;
            }
            $normalised[ $slug ] = $clean;
        }

        self::$cache = $normalised;
        return self::$cache;
    }

    /**
     * Get a single type definition, or null when unknown.
     *
     * @param string $slug Type slug.
     * @return array<string, mixed>|null
     */
    public static function get_type( string $slug ): ?array {
        $types = self::get_types();
        return $types[ $slug ] ?? null;
    }

    /**
     * The class-name map consumed by {@see Product_Factory}.
     *
     * Preserves the legacy `tejcart_product_class_map` filter so addons that
     * predate the registry continue to work; new addons should use
     * `tejcart_product_types` instead.
     *
     * @param string|null $type       The type currently being resolved (passed
     *                                through to the legacy filter).
     * @param int         $product_id The product ID being resolved (passed
     *                                through to the legacy filter).
     * @return array<string, string>
     */
    public static function get_class_map( ?string $type = null, int $product_id = 0 ): array {
        $map = [];
        foreach ( self::get_types() as $slug => $definition ) {
            $class = (string) ( $definition['class'] ?? '' );
            if ( '' !== $class ) {
                $map[ $slug ] = $class;
            }
        }

        /**
         * Legacy filter for the type => class map.
         *
         * Prefer `tejcart_product_types` for new code. This filter remains
         * for backwards compatibility with addons released before the
         * registry was introduced.
         *
         * @param array<string, string> $map        Type slug => class name.
         * @param string|null           $type       Type currently being resolved.
         * @param int                   $product_id Product ID being resolved.
         */
        if ( null !== $type ) {
            $map = (array) apply_filters( 'tejcart_product_class_map', $map, $type, $product_id );
        } else {
            $map = (array) apply_filters( 'tejcart_product_class_map', $map );
        }

        return $map;
    }

    /**
     * The list of type slugs that should appear in admin pickers.
     *
     * Excludes any type with `admin => false` (e.g. `variation`, which is
     * managed through its parent product).
     *
     * @return string[]
     */
    public static function get_admin_types(): array {
        $out = [];
        foreach ( self::get_types() as $slug => $definition ) {
            if ( ! empty( $definition['admin'] ) ) {
                $out[] = (string) $slug;
            }
        }
        return $out;
    }

    /**
     * The list of type slugs that should appear in REST schema enums.
     *
     * @return string[]
     */
    public static function get_rest_types(): array {
        $out = [];
        foreach ( self::get_types() as $slug => $definition ) {
            if ( ! empty( $definition['rest'] ) ) {
                $out[] = (string) $slug;
            }
        }
        return $out;
    }

    /**
     * Get the admin-form tabs for a type.
     *
     * Falls back to a minimal `[overview, links]` set when the type is
     * unknown so addons that forget the `tabs` key still get a workable
     * edit screen.
     *
     * @param string $type Type slug.
     * @return string[]
     */
    public static function get_tabs( string $type ): array {
        $definition = self::get_type( $type );
        $tabs       = isset( $definition['tabs'] ) && is_array( $definition['tabs'] )
            ? array_values( array_map( 'strval', $definition['tabs'] ) )
            : [ 'overview', 'links' ];

        /**
         * Filter the admin-form tabs for a single product type.
         *
         * Runs after the registry resolves the default tabs for the type so
         * addons can reorder, hide, or insert a custom tab.
         *
         * @param string[] $tabs Tab IDs in render order.
         * @param string   $type Product type slug.
         */
        return array_values( (array) apply_filters( 'tejcart_product_type_tabs', $tabs, $type ) );
    }

    /**
     * Whether a given type advertises support for a feature.
     *
     * Pass the full feature list as a `supports` array on the type; this
     * helper just answers "is X in there". Useful for templates and gateway
     * compatibility checks.
     *
     * @param string $type    Type slug.
     * @param string $feature Feature key (e.g. `inventory`, `recurring`).
     * @return bool
     */
    public static function type_supports( string $type, string $feature ): bool {
        $definition = self::get_type( $type );
        if ( null === $definition ) {
            return false;
        }
        $supports = isset( $definition['supports'] ) && is_array( $definition['supports'] )
            ? $definition['supports']
            : [];
        $result   = in_array( $feature, $supports, true );

        /**
         * Filter the supports check for a product type.
         *
         * Runs after the registry's own answer so addons can grant or
         * revoke a feature for a type they didn't author.
         *
         * @param bool   $result  Default answer from the registry.
         * @param string $type    Type slug.
         * @param string $feature Feature key.
         */
        return (bool) apply_filters( 'tejcart_product_type_supports', $result, $type, $feature );
    }

    /**
     * Whether a type is shipping-bearing by default.
     *
     * Concrete product instances can still override `needs_shipping()` per
     * row; this returns the type-level default surfaced to the cart and
     * shipping calculator before a product is loaded.
     *
     * @param string $type Type slug.
     * @return bool
     */
    public static function type_needs_shipping( string $type ): bool {
        $definition = self::get_type( $type );
        return ! empty( $definition['needs_shipping'] );
    }

    /**
     * Reset the request-scoped cache.
     *
     * Tests call this between scenarios that register different filters.
     *
     * @return void
     */
    public static function clear_cache(): void {
        self::$cache = null;
    }

    /**
     * Validate and shape a single type definition.
     *
     * Drops entries pointing at a non-existent class or a class that does
     * not extend `Abstract_Product` — silently ignoring is preferable to
     * fataling the request when an addon mis-registers.
     *
     * @param string               $slug       Type slug.
     * @param array<string, mixed> $definition Raw definition from the filter.
     * @return array<string, mixed>|null Normalised definition, or null when invalid.
     */
    private static function normalise( string $slug, array $definition ): ?array {
        $class = isset( $definition['class'] ) ? (string) $definition['class'] : '';
        if ( '' === $class || ! class_exists( $class ) ) {
            return null;
        }

        if ( ! is_subclass_of( $class, Product_Types\Abstract_Product::class ) ) {
            return null;
        }

        $defaults = [
            'label'          => ucfirst( $slug ),
            'description'    => '',
            'icon'           => 'admin-generic',
            'tabs'           => [ 'overview', 'links' ],
            'supports'       => [],
            'admin'          => true,
            'rest'           => true,
            'needs_shipping' => false,
            'is_virtual'     => false,
        ];

        $merged          = array_merge( $defaults, $definition );
        $merged['class'] = $class;

        // Coerce the array-shape fields so a sloppy addon doesn't blow up
        // downstream consumers that array_map over them.
        $merged['tabs']     = is_array( $merged['tabs'] ) ? array_values( array_map( 'strval', $merged['tabs'] ) ) : [];
        $merged['supports'] = is_array( $merged['supports'] ) ? array_values( array_map( 'strval', $merged['supports'] ) ) : [];

        $merged['admin']          = (bool) $merged['admin'];
        $merged['rest']           = (bool) $merged['rest'];
        $merged['needs_shipping'] = (bool) $merged['needs_shipping'];
        $merged['is_virtual']     = (bool) $merged['is_virtual'];

        return $merged;
    }
}
