<?php
/**
 * Global product attributes (tejcart_pa_* taxonomies).
 *
 * @package TejCart\Product
 */

declare( strict_types=1 );

namespace TejCart\Product;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers per-attribute WP taxonomies so attributes like Color or Size
 * become first-class terms shared across products.
 *
 * Taxonomies are registered under the plugin-unique `tejcart_pa_*` prefix
 * (not the generic `pa_*` other stores use) so two stores that briefly run
 * both plugins side by side — e.g. during a store migration
 * — never collide on a shared taxonomy identifier. Public attribute URLs are
 * unaffected: the `rewrite` slug below uses the bare attribute slug.
 *
 * The list of attributes is stored in the `tejcart_product_attributes`
 * option as an array of `{slug, name}`.
 */
class Global_Attributes {
    /**
     * Taxonomy name prefix for registered attributes.
     *
     * Plugin-unique to avoid collisions with other stores' `pa_*` taxonomies.
     * WordPress caps taxonomy names at 32 characters; {@see self::get_attributes()}
     * truncates each slug so `TAXONOMY_PREFIX . $slug` always fits.
     */
    public const TAXONOMY_PREFIX = 'tejcart_pa_';

    /**
     * Option key for the attribute registry.
     */
    public const OPTION = 'tejcart_product_attributes';

    /**
     * Register the `init` hook and admin-action handler.
     *
     * The `tejcart_pa_*` taxonomies are what per-product attributes serialize
     * to; registering them here keeps that storage intact even though the
     * dedicated Attributes admin page has been removed. The admin_init
     * handler is kept in place so any surviving POST entry points still
     * process cleanly.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'init', array( $this, 'register_taxonomies' ), 5 );
        add_action( 'admin_init', array( $this, 'handle_actions' ) );
    }

    /**
     * Return the stored attribute list.
     *
     * @return array<int, array{slug:string, name:string}>
     */
    public static function get_attributes(): array {
        $raw = get_option( self::OPTION, array() );
        if ( ! is_array( $raw ) ) {
            return array();
        }

        // Keep the registered taxonomy name (`TAXONOMY_PREFIX . $slug`) within
        // WordPress's 32-character ceiling by truncating the slug here — the
        // single chokepoint every consumer (registration, admin, swatches)
        // reads from, so the slug stays consistent everywhere.
        $max_slug_len = 32 - strlen( self::TAXONOMY_PREFIX );

        $clean = array();
        foreach ( $raw as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['slug'] ) ) {
                continue;
            }
            $clean[] = array(
                'slug' => substr( sanitize_key( (string) $entry['slug'] ), 0, $max_slug_len ),
                'name' => sanitize_text_field( (string) ( $entry['name'] ?? $entry['slug'] ) ),
            );
        }
        return $clean;
    }

    /**
     * Register one WP taxonomy per saved attribute.
     *
     * @return void
     */
    public function register_taxonomies(): void {
        foreach ( self::get_attributes() as $attribute ) {
            $taxonomy = self::TAXONOMY_PREFIX . $attribute['slug'];
            if ( taxonomy_exists( $taxonomy ) ) {
                continue;
            }

            register_taxonomy(
                $taxonomy,
                array(),
                array(
                    'labels'            => array(
                        'name'          => $attribute['name'],
                        'singular_name' => $attribute['name'],
                        'menu_name'     => $attribute['name'],
                    ),
                    'hierarchical'      => false,
                    'public'            => true,
                    'show_ui'           => true,
                    'show_admin_column' => false,
                    'show_in_rest'      => true,
                    'rewrite'           => array( 'slug' => $attribute['slug'] ),
                )
            );
        }
    }

    /**
     * Add / delete attribute actions.
     *
     * @return void
     */
    public function handle_actions(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['tejcart_attribute_action'] ) && 'add' === $_POST['tejcart_attribute_action'] ) {
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'tejcart_add_attribute' ) ) {
                return;
            }

            $name = isset( $_POST['attribute_name'] ) ? sanitize_text_field( wp_unslash( $_POST['attribute_name'] ) ) : '';
            $slug = isset( $_POST['attribute_slug'] ) ? sanitize_key( wp_unslash( $_POST['attribute_slug'] ) ) : sanitize_key( $name );

            if ( '' === $slug || '' === $name ) {
                return;
            }

            $existing = self::get_attributes();
            foreach ( $existing as $row ) {
                if ( $row['slug'] === $slug ) {
                    wp_safe_redirect( admin_url( 'admin.php?page=tejcart-products&section=attributes&duplicate=1' ) );
                    exit;
                }
            }
            $existing[] = array( 'slug' => $slug, 'name' => $name );
            update_option( self::OPTION, $existing );

            flush_rewrite_rules( false );

            wp_safe_redirect( admin_url( 'admin.php?page=tejcart-products&section=attributes&added=1' ) );
            exit;
        }

        if ( isset( $_GET['action'], $_GET['slug'], $_GET['_wpnonce'] ) && 'delete_attribute' === $_GET['action'] ) {
            $slug = sanitize_key( wp_unslash( $_GET['slug'] ) );
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'tejcart_delete_attribute_' . $slug ) ) {
                return;
            }

            $existing = array_values( array_filter( self::get_attributes(), static fn( $r ) => $r['slug'] !== $slug ) );
            update_option( self::OPTION, $existing );
            flush_rewrite_rules( false );

            wp_safe_redirect( admin_url( 'admin.php?page=tejcart-products&section=attributes&deleted=1' ) );
            exit;
        }

        if ( isset( $_POST['tejcart_attribute_action'] ) && 'add_term' === $_POST['tejcart_attribute_action'] ) {
            $attr_slug = isset( $_POST['attribute_slug'] ) ? sanitize_key( wp_unslash( $_POST['attribute_slug'] ) ) : '';
            if ( '' === $attr_slug ) {
                return;
            }
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'tejcart_add_term_' . $attr_slug ) ) {
                return;
            }

            $term_name = isset( $_POST['term_name'] ) ? sanitize_text_field( wp_unslash( $_POST['term_name'] ) ) : '';
            if ( '' === $term_name ) {
                return;
            }

            $taxonomy = self::TAXONOMY_PREFIX . $attr_slug;
            if ( ! taxonomy_exists( $taxonomy ) ) {
                wp_safe_redirect( admin_url( 'admin.php?page=tejcart-products&section=attributes&term_error=1' ) );
                exit;
            }

            $result = wp_insert_term( $term_name, $taxonomy );
            $flag   = is_wp_error( $result ) ? 'term_error=1' : 'term_added=1';

            wp_safe_redirect( admin_url( 'admin.php?page=tejcart-products&section=attributes&' . $flag ) );
            exit;
        }

        if ( isset( $_GET['action'], $_GET['_wpnonce'], $_GET['term_id'], $_GET['attr_slug'] ) && 'delete_term' === $_GET['action'] ) {
            $attr_slug = sanitize_key( wp_unslash( $_GET['attr_slug'] ) );
            $term_id   = absint( $_GET['term_id'] );

            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'tejcart_delete_term_' . $attr_slug . '_' . $term_id ) ) {
                return;
            }

            $taxonomy = self::TAXONOMY_PREFIX . $attr_slug;
            if ( taxonomy_exists( $taxonomy ) && $term_id > 0 ) {
                wp_delete_term( $term_id, $taxonomy );
            }

            wp_safe_redirect( admin_url( 'admin.php?page=tejcart-products&section=attributes&term_deleted=1' ) );
            exit;
        }
    }

    /**
     * Render the management page.
     *
     * @param bool $embedded When true, skip the outer `<div class="wrap">`
     *                      and page header for composition inside the
     *                      Products → Attributes sub-page.
     * @return void
     */
    public function render_page( bool $embedded = false ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die(
                esc_html__( 'You do not have permission to manage product attributes.', 'tejcart' ),
                '',
                array( 'response' => 403 )
            );
        }

        $attributes = self::get_attributes();
        ?>
        <?php if ( ! $embedded ) : ?>
        <div class="wrap tejcart-admin-wrap">
            <h1><?php esc_html_e( 'Product Attributes', 'tejcart' ); ?></h1>
            <p class="tejcart-page-subtitle"><?php esc_html_e( 'Shared attributes like Color and Size that can be attached to any product.', 'tejcart' ); ?></p>
        <?php endif; ?>

            <?php if ( ! empty( $_GET['added'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Attribute added. Use the row to add term values inline.', 'tejcart' ); ?></p></div>
            <?php elseif ( ! empty( $_GET['duplicate'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'An attribute with that slug already exists.', 'tejcart' ); ?></p></div>
            <?php elseif ( ! empty( $_GET['deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Attribute removed.', 'tejcart' ); ?></p></div>
            <?php elseif ( ! empty( $_GET['term_added'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Term added.', 'tejcart' ); ?></p></div>
            <?php elseif ( ! empty( $_GET['term_deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Term removed.', 'tejcart' ); ?></p></div>
            <?php elseif ( ! empty( $_GET['term_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not save the term — name may be empty or already in use.', 'tejcart' ); ?></p></div>
            <?php endif; ?>

            <div style="display: flex; gap: 24px; margin-top: 20px; align-items:flex-start;">
                <div class="tejcart-card" style="flex: 0 0 360px;">
                    <div class="tejcart-card-header"><h3><?php esc_html_e( 'Add a new attribute', 'tejcart' ); ?></h3></div>
                    <form method="post" style="padding:16px;">
                        <?php wp_nonce_field( 'tejcart_add_attribute' ); ?>
                        <input type="hidden" name="tejcart_attribute_action" value="add" />
                        <p>
                            <label><strong><?php esc_html_e( 'Name', 'tejcart' ); ?></strong></label>
                            <input type="text" name="attribute_name" class="widefat" required placeholder="<?php esc_attr_e( 'e.g. Color', 'tejcart' ); ?>" />
                        </p>
                        <p>
                            <label><strong><?php esc_html_e( 'Slug', 'tejcart' ); ?></strong></label>
                            <input type="text" name="attribute_slug" class="widefat" placeholder="<?php esc_attr_e( 'auto from name', 'tejcart' ); ?>" />
                            <small><?php esc_html_e( 'Used in URLs. Registered as a tejcart_pa_ taxonomy.', 'tejcart' ); ?></small>
                        </p>
                        <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Add attribute', 'tejcart' ); ?></button></p>
                    </form>
                </div>

                <table class="wp-list-table widefat fixed striped" style="flex:1;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Name', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Slug', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Taxonomy', 'tejcart' ); ?></th>
                            <th><?php esc_html_e( 'Terms', 'tejcart' ); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $attributes ) ) : ?>
                        <tr><td colspan="5"><?php esc_html_e( 'No attributes yet.', 'tejcart' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $attributes as $attribute ) :
                            $taxonomy   = self::TAXONOMY_PREFIX . $attribute['slug'];
                            $terms      = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
                            $terms      = is_array( $terms ) ? $terms : array();
                            $manage_url = admin_url( 'edit-tags.php?taxonomy=' . $taxonomy );
                        ?>
                            <tr>
                                <td><strong><?php echo esc_html( $attribute['name'] ); ?></strong></td>
                                <td><code><?php echo esc_html( $attribute['slug'] ); ?></code></td>
                                <td><code><?php echo esc_html( $taxonomy ); ?></code></td>
                                <td>
                                    <?php if ( empty( $terms ) ) : ?>
                                        <em><?php esc_html_e( 'No terms yet', 'tejcart' ); ?></em>
                                    <?php else : ?>
                                        <ul class="tejcart-term-chips">
                                            <?php foreach ( $terms as $term ) :
                                                $del_url = wp_nonce_url(
                                                    admin_url( 'admin.php?page=tejcart-products&section=attributes&action=delete_term&attr_slug=' . $attribute['slug'] . '&term_id=' . (int) $term->term_id ),
                                                    'tejcart_delete_term_' . $attribute['slug'] . '_' . (int) $term->term_id
                                                );
                                                ?>
                                                <li>
                                                    <span><?php echo esc_html( $term->name ); ?></span>
                                                    <a class="tejcart-term-chip-x" title="<?php esc_attr_e( 'Remove', 'tejcart' ); ?>"
                                                       href="<?php echo esc_url( $del_url ); ?>"
                                                       onclick="return confirm('<?php echo esc_js( __( 'Remove this term?', 'tejcart' ) ); ?>');">&times;</a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                    <form method="post" class="tejcart-add-term-inline">
                                        <?php wp_nonce_field( 'tejcart_add_term_' . $attribute['slug'] ); ?>
                                        <input type="hidden" name="tejcart_attribute_action" value="add_term" />
                                        <input type="hidden" name="attribute_slug" value="<?php echo esc_attr( $attribute['slug'] ); ?>" />
                                        <input type="text" name="term_name" placeholder="<?php esc_attr_e( 'Add a term…', 'tejcart' ); ?>" required />
                                        <button type="submit" class="button button-small"><?php esc_html_e( '+', 'tejcart' ); ?></button>
                                    </form>
                                </td>
                                <td>
                                    <a class="button-link" href="<?php echo esc_url( $manage_url ); ?>"><?php esc_html_e( 'Advanced', 'tejcart' ); ?></a>
                                    |
                                    <a class="button-link delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=tejcart-products&section=attributes&action=delete_attribute&slug=' . $attribute['slug'] ), 'tejcart_delete_attribute_' . $attribute['slug'] ) ); ?>"
                                       onclick="return confirm('<?php esc_attr_e( 'Delete attribute? Term assignments on products will remain but the taxonomy will no longer be registered.', 'tejcart' ); ?>');">
                                        <?php esc_html_e( 'Delete', 'tejcart' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php if ( ! $embedded ) : ?>
        </div>
        <?php endif; ?>
        <?php
    }
}
