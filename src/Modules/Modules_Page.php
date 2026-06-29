<?php
/**
 * Admin "Modules" page — lists every optional first-party module and
 * exposes an on/off toggle backed by {@see Module_Manager}.
 *
 * @package TejCart\Modules
 */

declare(strict_types=1);

namespace TejCart\Modules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the TejCart → Modules admin screen and processes the
 * toggle form submission.
 */
final class Modules_Page {
    public const MENU_SLUG       = 'tejcart-modules';
    public const TOGGLE_NONCE    = 'tejcart_modules_toggle';
    public const NOTICE_PREFIX   = 'tejcart_modules_notice_';
    public const NOTICE_TTL      = 30;

    public function init(): void {
        add_action( 'admin_menu', array( $this, 'register_menu' ), 30 );
        add_action( 'admin_init', array( $this, 'maybe_handle_toggle' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * Enqueue the Modules-page card-grid stylesheet on this screen only.
     */
    public function enqueue_assets( string $hook_suffix ): void {
        // The submenu hook suffix is `tejcart_page_tejcart-modules`. Bail on
        // every other admin screen so we don't add a kilobyte to wp-admin's
        // critical path for pages that never render module cards.
        if ( false === strpos( $hook_suffix, self::MENU_SLUG ) ) {
            return;
        }
        $rel  = 'assets/css/admin/modules.css';
        $base = defined( 'TEJCART_PLUGIN_DIR' ) ? TEJCART_PLUGIN_DIR : dirname( __DIR__, 2 ) . '/';
        $url  = function_exists( 'tejcart_asset_url' )
            ? tejcart_asset_url( $rel )
            : ( defined( 'TEJCART_PLUGIN_URL' ) ? TEJCART_PLUGIN_URL . $rel : plugins_url( $rel, $base . 'tejcart.php' ) );

        $version = ( defined( 'TEJCART_VERSION' ) && '' !== TEJCART_VERSION )
            ? TEJCART_VERSION
            : (string) @filemtime( $base . $rel ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- fallback for dev installs.

        wp_enqueue_style( 'dashicons' );
        wp_enqueue_style(
            'tejcart-modules-page',
            $url,
            array( 'dashicons' ),
            $version
        );
    }

    public function register_menu(): void {
        // Hide the Modules submenu entirely when no module folders are
        // present on disk — otherwise 1.0.0 (which ships without the
        // modules/ folder) would render an empty "Modules" admin page
        // that looks like a broken feature.
        if ( empty( Module_Manager::instance()->get_registry() ) ) {
            return;
        }

        // Sidebar slot is owned by TejCart core's reorder_submenu() hook
        // (admin_menu priority 9999) — see Menu::canonical_submenu_order().
        // F-CORE-008: use tejcart_manage_store so Shop Managers can access
        // the Modules page without needing full site-admin manage_options.
        add_submenu_page(
            'tejcart',
            __( 'Modules', 'tejcart' ),
            __( 'Modules', 'tejcart' ),
            \TejCart\Core\Capabilities::MANAGE_STORE,
            self::MENU_SLUG,
            array( $this, 'render' )
        );
    }

    /**
     * Handle a single-module enable/disable action posted from a per-row
     * button. PRG pattern: redirects back with a notice once persisted.
     */
    public function maybe_handle_toggle(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if ( empty( $_POST['tejcart_modules_toggle_submit'] ) ) {
            return;
        }
        // F-CORE-008: match the menu capability so Shop Managers can toggle modules.
        if ( ! current_user_can( \TejCart\Core\Capabilities::MANAGE_STORE ) ) {
            wp_die( esc_html__( 'You do not have permission to manage TejCart modules.', 'tejcart' ) );
        }
        check_admin_referer( self::TOGGLE_NONCE );

        $slug   = isset( $_POST['module_slug'] ) ? sanitize_key( wp_unslash( (string) $_POST['module_slug'] ) ) : '';
        $action = isset( $_POST['module_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['module_action'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $manager  = Module_Manager::instance();
        $registry = $manager->get_registry();

        $notice = 'invalid';
        if ( '' !== $slug && array_key_exists( $slug, $registry ) && in_array( $action, array( 'enable', 'disable' ), true ) ) {
            $states          = $manager->get_states();
            $states[ $slug ] = ( 'enable' === $action );
            $manager->update_states( $states );
            $notice = ( 'enable' === $action ) ? 'enabled' : 'disabled';
        }

        // Stash the notice in a per-user transient so the redirect can land
        // on a clean URL (no module_notice / module_slug query args). The
        // notice is consumed and deleted on the next render — see render().
        $user_id = (int) get_current_user_id();
        if ( $user_id > 0 ) {
            set_transient(
                self::NOTICE_PREFIX . $user_id,
                array(
                    'notice' => $notice,
                    'slug'   => $slug,
                ),
                self::NOTICE_TTL
            );
        }

        $redirect = add_query_arg(
            array( 'page' => self::MENU_SLUG ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    public function render(): void {
        // F-CORE-008: match the menu capability so Shop Managers can view the page.
        if ( ! current_user_can( \TejCart\Core\Capabilities::MANAGE_STORE ) ) {
            wp_die( esc_html__( 'You do not have permission to manage TejCart modules.', 'tejcart' ) );
        }

        $manager  = Module_Manager::instance();
        $registry = $manager->get_registry();
        $states   = $manager->get_states();

        // Read (and consume) the post-redirect notice from the per-user
        // transient that maybe_handle_toggle() stashed. The URL itself is
        // clean — see https://en.wikipedia.org/wiki/Post/Redirect/Get.
        $module_notice = '';
        $notice_slug   = '';
        $user_id       = (int) get_current_user_id();
        if ( $user_id > 0 ) {
            $stashed = get_transient( self::NOTICE_PREFIX . $user_id );
            if ( is_array( $stashed ) ) {
                delete_transient( self::NOTICE_PREFIX . $user_id );
                $module_notice = isset( $stashed['notice'] ) ? sanitize_key( (string) $stashed['notice'] ) : '';
                $notice_slug   = isset( $stashed['slug'] ) ? sanitize_key( (string) $stashed['slug'] ) : '';
            }
        }
        $notice_name = ( '' !== $notice_slug && isset( $registry[ $notice_slug ]['name'] ) )
            ? (string) $registry[ $notice_slug ]['name']
            : $notice_slug;

        $enabled_count = 0;
        foreach ( $registry as $slug => $_entry ) {
            if ( ! empty( $states[ $slug ] ) ) {
                $enabled_count++;
            }
        }
        $total_count    = count( $registry );
        $disabled_count = $total_count - $enabled_count;

        // Read the filter chip selection (?filter=enabled|disabled|all). Any
        // other value falls back to "all" so a bookmarked URL with a stale
        // value never shows an empty page with no way out.
        $filter_raw = isset( $_GET['filter'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, no state change.
            ? sanitize_key( wp_unslash( (string) $_GET['filter'] ) )
            : 'all';
        $filter = in_array( $filter_raw, array( 'enabled', 'disabled' ), true ) ? $filter_raw : 'all';

        $categories = Module_Manager::default_categories();

        // Group the registry by category, preserving registry order within
        // each group. Unknown categories fall under `other` so a third-party
        // module that registers with an unrecognised slug still shows up.
        $grouped = array();
        foreach ( $categories as $cat_slug => $_cat ) {
            $grouped[ $cat_slug ] = array();
        }
        foreach ( $registry as $slug => $entry ) {
            $cat = isset( $entry['category'] ) ? (string) $entry['category'] : 'other';
            if ( ! isset( $grouped[ $cat ] ) ) {
                $cat = 'other';
            }
            // Apply the filter chip selection.
            $is_on = ! empty( $states[ $slug ] );
            if ( 'enabled' === $filter && ! $is_on ) {
                continue;
            }
            if ( 'disabled' === $filter && $is_on ) {
                continue;
            }
            $grouped[ $cat ][ $slug ] = $entry;
        }

        $base_url = add_query_arg( array( 'page' => self::MENU_SLUG ), admin_url( 'admin.php' ) );
        $chip_url = static function ( string $value ) use ( $base_url ): string {
            return 'all' === $value
                ? $base_url
                : add_query_arg( 'filter', $value, $base_url );
        };

        $visible_count = 0;
        foreach ( $grouped as $bucket ) {
            $visible_count += count( $bucket );
        }

        ?>
        <div class="wrap tejcart-admin-wrap nxc-modules nxc-modules--cards">
            <header class="nxc-page-header">
                <div class="nxc-page-header__title">
                    <h1><?php esc_html_e( 'Modules', 'tejcart' ); ?></h1>
                    <p class="nxc-page-header__subtitle">
                        <?php
                        echo esc_html( sprintf(
                            /* translators: 1: enabled modules count, 2: total modules count */
                            _n(
                                'Enable only the modules your store needs · %1$s of %2$s module enabled.',
                                'Enable only the modules your store needs · %1$s of %2$s modules enabled.',
                                $total_count,
                                'tejcart'
                            ),
                            number_format_i18n( $enabled_count ),
                            number_format_i18n( $total_count )
                        ) );
                        ?>
                    </p>
                </div>
            </header>
            <span class="wp-header-end"></span>

            <?php
            if ( 'enabled' === $module_notice ) {
                \TejCart\Admin\Flash_Notice::render(
                    sprintf(
                        /* translators: %s: module name */
                        __( '%s module enabled.', 'tejcart' ),
                        $notice_name
                    ),
                    '',
                    \TejCart\Admin\Flash_Notice::TONE_SUCCESS
                );
            } elseif ( 'disabled' === $module_notice ) {
                \TejCart\Admin\Flash_Notice::render(
                    sprintf(
                        /* translators: %s: module name */
                        __( '%s module disabled.', 'tejcart' ),
                        $notice_name
                    ),
                    '',
                    \TejCart\Admin\Flash_Notice::TONE_SUCCESS
                );
            } elseif ( 'invalid' === $module_notice ) {
                \TejCart\Admin\Flash_Notice::render(
                    __( 'Unknown module — nothing changed.', 'tejcart' ),
                    '',
                    \TejCart\Admin\Flash_Notice::TONE_ERROR
                );
            }
            ?>

            <nav class="nxc-modules-filters" aria-label="<?php esc_attr_e( 'Filter modules', 'tejcart' ); ?>">
                <a
                    href="<?php echo esc_url( $chip_url( 'all' ) ); ?>"
                    class="nxc-modules-chip<?php echo 'all' === $filter ? ' is-active' : ''; ?>"
                    <?php echo 'all' === $filter ? 'aria-current="page"' : ''; ?>
                >
                    <?php
                    printf(
                        /* translators: %s: total module count */
                        esc_html__( 'All %s', 'tejcart' ),
                        '<span class="nxc-modules-chip__count">' . esc_html( number_format_i18n( $total_count ) ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- count escaped inline.
                    );
                    ?>
                </a>
                <a
                    href="<?php echo esc_url( $chip_url( 'enabled' ) ); ?>"
                    class="nxc-modules-chip<?php echo 'enabled' === $filter ? ' is-active' : ''; ?>"
                    <?php echo 'enabled' === $filter ? 'aria-current="page"' : ''; ?>
                >
                    <?php
                    printf(
                        /* translators: %s: enabled module count */
                        esc_html__( 'Enabled %s', 'tejcart' ),
                        '<span class="nxc-modules-chip__count">' . esc_html( number_format_i18n( $enabled_count ) ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- count escaped inline.
                    );
                    ?>
                </a>
                <a
                    href="<?php echo esc_url( $chip_url( 'disabled' ) ); ?>"
                    class="nxc-modules-chip<?php echo 'disabled' === $filter ? ' is-active' : ''; ?>"
                    <?php echo 'disabled' === $filter ? 'aria-current="page"' : ''; ?>
                >
                    <?php
                    printf(
                        /* translators: %s: disabled module count */
                        esc_html__( 'Disabled %s', 'tejcart' ),
                        '<span class="nxc-modules-chip__count">' . esc_html( number_format_i18n( $disabled_count ) ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- count escaped inline.
                    );
                    ?>
                </a>
            </nav>

            <?php if ( 0 === $visible_count ) : ?>
                <div class="nxc-modules-empty">
                    <span class="nxc-modules-empty__icon" aria-hidden="true">
                        <span class="dashicons dashicons-screenoptions"></span>
                    </span>
                    <h2 class="nxc-modules-empty__title">
                        <?php
                        echo esc_html(
                            'enabled' === $filter
                                ? __( 'No modules enabled yet', 'tejcart' )
                                : __( 'No modules match this filter', 'tejcart' )
                        );
                        ?>
                    </h2>
                    <p class="nxc-modules-empty__copy">
                        <?php
                        echo esc_html(
                            'enabled' === $filter
                                ? __( 'Browse the catalogue and turn on the features your store actually needs.', 'tejcart' )
                                : __( 'Switch to "All" to see every module available in this build.', 'tejcart' )
                        );
                        ?>
                    </p>
                    <a class="nxc-modules-empty__link" href="<?php echo esc_url( $chip_url( 'all' ) ); ?>">
                        <?php esc_html_e( 'Show all modules', 'tejcart' ); ?>
                    </a>
                </div>
            <?php else : ?>
                <?php foreach ( $grouped as $cat_slug => $entries ) :
                    if ( empty( $entries ) ) {
                        continue;
                    }
                    $cat = $categories[ $cat_slug ] ?? array( 'label' => ucfirst( $cat_slug ), 'description' => '' );
                    ?>
                    <section class="nxc-modules-section" data-category="<?php echo esc_attr( $cat_slug ); ?>">
                        <header class="nxc-modules-section__header">
                            <h2 class="nxc-modules-section__title">
                                <?php echo esc_html( (string) $cat['label'] ); ?>
                            </h2>
                            <?php if ( '' !== (string) ( $cat['description'] ?? '' ) ) : ?>
                                <p class="nxc-modules-section__copy">
                                    <?php echo esc_html( (string) $cat['description'] ); ?>
                                </p>
                            <?php endif; ?>
                        </header>
                        <div class="nxc-modules-grid">
                            <?php foreach ( $entries as $slug => $entry ) :
                                $this->render_module_card( $slug, $entry, ! empty( $states[ $slug ] ) );
                            endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render a single module card. Extracted from render() to keep the
     * outer template loop readable.
     *
     * @param string               $slug    Module slug.
     * @param array<string, mixed> $entry   Registry entry.
     * @param bool                 $enabled Whether the module is currently on.
     */
    private function render_module_card( string $slug, array $entry, bool $enabled ): void {
        $name        = (string) ( $entry['name'] ?? $slug );
        $description = (string) ( $entry['description'] ?? '' );
        $icon        = (string) ( $entry['icon'] ?? 'dashicons-screenoptions' );
        $recommended = ! empty( $entry['recommended'] );

        $toggle_action = $enabled ? 'disable' : 'enable';
        $aria_label    = $enabled
            ? sprintf( /* translators: %s: module name. */ __( 'Disable %s', 'tejcart' ), $name )
            : sprintf( /* translators: %s: module name. */ __( 'Enable %s', 'tejcart' ), $name );

        $view_raw = isset( $entry['view'] ) ? (string) $entry['view'] : '';
        /**
         * Filter the per-module "Configure" link target shown on the
         * Modules admin screen once the module is enabled. Return an
         * empty string to hide the link entirely.
         *
         * @param string $view_raw Relative admin URL or absolute URL.
         * @param string $slug     Module slug.
         * @param array  $entry    Registry entry.
         */
        $view_raw = (string) apply_filters( 'tejcart_modules_view_url', $view_raw, $slug, $entry );
        $view_url = '';
        if ( '' !== $view_raw ) {
            $view_url = ( false !== strpos( $view_raw, '://' ) )
                ? $view_raw
                : admin_url( $view_raw );
        }

        /**
         * Whether a module that is currently enabled still needs first-time
         * setup (e.g. provider credentials). Modules opt in by returning
         * true; the default is false so a card never falsely accuses a
         * fully-configured module of being unconfigured.
         *
         * @param bool   $setup_required Default false.
         * @param string $slug           Module slug.
         * @param array  $entry          Registry entry.
         */
        $setup_required = $enabled
            ? (bool) apply_filters( 'tejcart_module_setup_required', false, $slug, $entry )
            : false;

        $card_classes = array( 'nxc-module-card' );
        if ( $enabled ) {
            $card_classes[] = 'is-enabled';
        }
        if ( $setup_required ) {
            $card_classes[] = 'needs-setup';
        }
        ?>
        <article class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>" data-module-slug="<?php echo esc_attr( $slug ); ?>">
            <header class="nxc-module-card__head">
                <span class="nxc-module-card__icon" aria-hidden="true">
                    <span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
                </span>
                <div class="nxc-module-card__badges">
                    <?php if ( $recommended ) : ?>
                        <span class="nxc-module-card__badge nxc-module-card__badge--recommended">
                            <?php esc_html_e( 'Recommended', 'tejcart' ); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ( $setup_required ) : ?>
                        <span class="nxc-module-card__badge nxc-module-card__badge--setup">
                            <?php esc_html_e( 'Setup required', 'tejcart' ); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </header>

            <h3 class="nxc-module-card__title"><?php echo esc_html( $name ); ?></h3>
            <p class="nxc-module-card__description"><?php echo esc_html( $description ); ?></p>

            <footer class="nxc-module-card__foot">
                <form method="post" action="" class="nxc-module-card__form">
                    <?php wp_nonce_field( self::TOGGLE_NONCE ); ?>
                    <input type="hidden" name="module_slug" value="<?php echo esc_attr( $slug ); ?>" />
                    <input type="hidden" name="module_action" value="<?php echo esc_attr( $toggle_action ); ?>" />
                    <button
                        type="submit"
                        name="tejcart_modules_toggle_submit"
                        value="1"
                        class="nxc-module-card__toggle"
                        aria-pressed="<?php echo $enabled ? 'true' : 'false'; ?>"
                        aria-label="<?php echo esc_attr( $aria_label ); ?>"
                    >
                        <span class="nxc-module-card__toggle-track" aria-hidden="true">
                            <span class="nxc-module-card__toggle-knob"></span>
                        </span>
                        <span class="nxc-module-card__toggle-text" aria-hidden="true">
                            <?php
                            echo esc_html(
                                $enabled ? __( 'On', 'tejcart' ) : __( 'Off', 'tejcart' )
                            );
                            ?>
                        </span>
                    </button>
                </form>

                <?php if ( $enabled && '' !== $view_url ) : ?>
                    <a class="nxc-module-card__configure" href="<?php echo esc_url( $view_url ); ?>">
                        <?php esc_html_e( 'Configure', 'tejcart' ); ?>
                        <span aria-hidden="true">→</span>
                    </a>
                <?php endif; ?>
            </footer>
        </article>
        <?php
    }
}
