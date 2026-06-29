<?php
/**
 * WP-CLI Commands for TejCart.
 *
 * @package TejCart\CLI
 */

declare( strict_types=1 );

namespace TejCart\CLI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manage TejCart products, orders, customers, and tools from the command line.
 */
class TejCart_CLI {

    /**
     * Rebuild the daily-summary aggregate from authoritative orders data.
     *
     * Useful after a backfill or recovering from a missed
     * tejcart_order_status_changed hook.
     *
     * ## OPTIONS
     *
     * [--from=<date>]
     * : Start date (YYYY-MM-DD). Defaults to 30 days ago.
     *
     * [--to=<date>]
     * : End date (YYYY-MM-DD). Defaults to today.
     *
     * [--currency=<code>]
     * : Limit rebuild to a single ISO-4217 currency. Omit to rebuild every
     *   currency that actually saw orders on each day (default).
     *
     * ## EXAMPLES
     *
     *     wp tejcart reports rebuild
     *     wp tejcart reports rebuild --from=2026-01-01 --to=2026-04-30
     *     wp tejcart reports rebuild --currency=JPY
     *
     * @subcommand reports
     */
    public function reports( $args, $assoc_args ) {
        $sub = isset( $args[0] ) ? (string) $args[0] : '';
        if ( '' === $sub || ! in_array( $sub, array( 'rebuild', 'export' ), true ) ) {
            \WP_CLI::error( 'Usage: wp tejcart reports <rebuild|export> [...]' );
            return;
        }

        if ( 'rebuild' === $sub ) {
            $from = isset( $assoc_args['from'] )
                ? (string) $assoc_args['from']
                : gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );
            $to = isset( $assoc_args['to'] )
                ? (string) $assoc_args['to']
                : gmdate( 'Y-m-d' );
            $currency_arg = isset( $assoc_args['currency'] ) ? strtoupper( (string) $assoc_args['currency'] ) : '';
            $summary      = new \TejCart\Reports\Daily_Summary();
            $start_ts     = strtotime( $from );
            $end_ts       = strtotime( $to );
            if ( false === $start_ts || false === $end_ts || $start_ts > $end_ts ) {
                \WP_CLI::error( 'Invalid --from / --to range.' );
                return;
            }
            $count = 0;
            for ( $ts = $start_ts; $ts <= $end_ts; $ts += DAY_IN_SECONDS ) {
                $day = gmdate( 'Y-m-d', $ts );

                if ( '' !== $currency_arg ) {
                    $currencies = array( $currency_arg );
                } else {
                    // F-005: rebuild every currency that has orders for
                    // the day so a multi-currency store catches up every
                    // bucket, not just the shop default.
                    $currencies = self::distinct_currencies_for_day( $day );
                    if ( array() === $currencies ) {
                        $currencies = array( (string) get_option( 'tejcart_currency', 'USD' ) );
                    }
                }

                foreach ( $currencies as $currency ) {
                    $summary->rebuild_bucket( $day, $currency );
                    ++$count;
                }
            }
            $label = '' !== $currency_arg ? $currency_arg : 'all currencies';
            \WP_CLI::success( sprintf( 'Rebuilt %d daily-summary buckets (%s → %s, %s).', $count, $from, $to, $label ) );
            return;
        }

        // 'export' subcommand.
        $type = isset( $args[1] ) ? (string) $args[1] : '';
        $allowed = array( 'sales', 'customers', 'stock', 'tax', 'refunds', 'products' );
        if ( ! in_array( $type, $allowed, true ) ) {
            \WP_CLI::error( 'Usage: wp tejcart reports export <sales|customers|stock|tax|refunds|products> [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--output=path|-] [--scope=low|all]' );
            return;
        }
        $output = isset( $assoc_args['output'] ) ? (string) $assoc_args['output'] : '-';
        $from   = isset( $assoc_args['from'] ) ? (string) $assoc_args['from'] : gmdate( 'Y-m-01' );
        $to     = isset( $assoc_args['to'] )   ? (string) $assoc_args['to']   : gmdate( 'Y-m-d' );
        $scope  = isset( $assoc_args['scope'] ) ? (string) $assoc_args['scope'] : 'low';

        $handle = ( '-' === $output ) ? STDOUT : @fopen( $output, 'w' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if ( ! $handle ) {
            \WP_CLI::error( sprintf( 'Unable to open output: %s', $output ) );
            return;
        }
        $written = \TejCart\Reports\CLI_Exporter::stream( $handle, $type, $from, $to, $scope );
        if ( STDOUT !== $handle ) {
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        }
        \WP_CLI::success( sprintf( 'Exported %d rows for %s (%s → %s).', $written, $type, $from, $to ) );
    }

    /**
     * Show plugin status including version, tables, order count, and product count.
     *
     * ## EXAMPLES
     *
     *     wp tejcart status
     *
     * @subcommand status
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function status( $args, $assoc_args ) {
        global $wpdb;

        $version         = defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : 'unknown';
        $orders_table    = $wpdb->prefix . 'tejcart_orders';
        $products_table  = $wpdb->prefix . 'tejcart_products';
        $customers_table = $wpdb->prefix . 'tejcart_customers';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $order_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$orders_table}" );
        $product_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$products_table}" );
        $customer_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$customers_table}" );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $tables = array(
            'tejcart_products',
            'tejcart_product_meta',
            'tejcart_orders',
            'tejcart_order_items',
            'tejcart_order_meta',
            'tejcart_customers',
            'tejcart_coupons',
            'tejcart_sessions',
            'tejcart_term_relationships',
        );

        $existing_tables = 0;
        foreach ( $tables as $table ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $result = $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            if ( $result ) {
                $existing_tables++;
            }
        }

        \WP_CLI::line( '' );
        \WP_CLI::line( "TejCart Version:  {$version}" );
        \WP_CLI::line( "Database Tables:  {$existing_tables}/" . count( $tables ) . ' installed' );
        \WP_CLI::line( "Products:         {$product_count}" );
        \WP_CLI::line( "Orders:           {$order_count}" );
        \WP_CLI::line( "Customers:        {$customer_count}" );
        \WP_CLI::line( '' );

        \WP_CLI::success( 'TejCart is active and running.' );
    }

    /**
     * Manage orders.
     *
     * ## EXAMPLES
     *
     *     wp tejcart order list
     *     wp tejcart order list --status=completed --limit=5
     *     wp tejcart order get 42
     *     wp tejcart order update-status 42 completed
     *
     * @subcommand order
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function order( $args, $assoc_args ) {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( 'Please specify a subcommand: list, get, or update-status.' );
            return;
        }

        $subcommand = $args[0];

        switch ( $subcommand ) {
            case 'list':
                $this->order_list( $assoc_args );
                break;
            case 'get':
                if ( empty( $args[1] ) ) {
                    \WP_CLI::error( 'Please specify an order ID.' );
                    return;
                }
                $this->order_get( absint( $args[1] ) );
                break;
            case 'update-status':
                if ( empty( $args[1] ) || empty( $args[2] ) ) {
                    \WP_CLI::error( 'Usage: wp tejcart order update-status <id> <status>' );
                    return;
                }
                $this->order_update_status( absint( $args[1] ), sanitize_text_field( $args[2] ) );
                break;
            default:
                \WP_CLI::error( "Unknown subcommand: {$subcommand}. Use list, get, or update-status." );
        }
    }

    /**
     * List orders with optional filters.
     *
     * @param array $assoc_args Associative arguments (status, limit).
     */
    private function order_list( $assoc_args ) {
        global $wpdb;

        $table  = $wpdb->prefix . 'tejcart_orders';
        $status = isset( $assoc_args['status'] ) ? sanitize_text_field( $assoc_args['status'] ) : '';
        $limit  = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 20;

        $where = '';
        if ( $status ) {
            $where = $wpdb->prepare( ' WHERE status = %s', $status );
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $orders = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id, order_number, status, customer_email, total, created_at FROM {$table}{$where} ORDER BY created_at DESC LIMIT %d",
                $limit
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( empty( $orders ) ) {
            \WP_CLI::line( 'No orders found.' );
            return;
        }

        $items = array();
        foreach ( $orders as $order ) {
            $items[] = array(
                'ID'       => $order->id,
                'Number'   => $order->order_number,
                'Status'   => $order->status,
                'Email'    => $order->customer_email,
                'Total'    => $order->total,
                'Created'  => $order->created_at,
            );
        }

        \WP_CLI\Utils\format_items( 'table', $items, array( 'ID', 'Number', 'Status', 'Email', 'Total', 'Created' ) );
    }

    /**
     * Show details for a single order.
     *
     * @param int $id Order ID.
     */
    private function order_get( $id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_orders';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $order = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( ! $order ) {
            \WP_CLI::error( "Order #{$id} not found." );
            return;
        }

        // Order money columns are BIGINT minor units in $order->currency.
        // Render as a major-unit decimal string for human readability.
        $order_currency = (string) $order->currency;
        $fmt = static function ( $minor ) use ( $order_currency ) {
            return \TejCart\Money\Money::from_minor_units( (int) $minor, $order_currency )->as_decimal_string()
                . ' ' . $order_currency;
        };

        \WP_CLI::line( '' );
        \WP_CLI::line( "Order #{$order->id}" );
        \WP_CLI::line( str_repeat( '-', 40 ) );
        \WP_CLI::line( "Order Number:    {$order->order_number}" );
        \WP_CLI::line( "Status:          {$order->status}" );
        \WP_CLI::line( "Currency:        {$order->currency}" );
        \WP_CLI::line( 'Subtotal:        ' . $fmt( $order->subtotal ) );
        \WP_CLI::line( 'Discount:        ' . $fmt( $order->discount_total ) );
        \WP_CLI::line( 'Shipping:        ' . $fmt( $order->shipping_total ) );
        \WP_CLI::line( 'Tax:             ' . $fmt( $order->tax_total ) );
        \WP_CLI::line( 'Total:           ' . $fmt( $order->total ) );
        \WP_CLI::line( "Payment Method:  {$order->payment_method}" );
        \WP_CLI::line( "Transaction ID:  {$order->transaction_id}" );
        \WP_CLI::line( "Customer Email:  {$order->customer_email}" );
        \WP_CLI::line( "Customer Name:   {$order->customer_name}" );
        \WP_CLI::line( "IP Address:      {$order->ip_address}" );
        \WP_CLI::line( "Created:         {$order->created_at}" );
        \WP_CLI::line( "Updated:         {$order->updated_at}" );
        \WP_CLI::line( '' );

        $items_table = $wpdb->prefix . 'tejcart_order_items';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $items = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT * FROM {$items_table} WHERE order_id = %d", $id )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( $items ) {
            $item_rows = array();
            foreach ( $items as $item ) {
                $item_rows[] = array(
                    'Product ID'   => $item->product_id,
                    'Product Name' => $item->product_name,
                    'Quantity'     => $item->quantity,
                    'Unit Price'   => $item->unit_price,
                    // line_total is BIGINT minor units in the parent
                    // order's currency; render as major-unit decimal.
                    'Line Total'   => \TejCart\Money\Money::from_minor_units(
                        (int) $item->line_total,
                        $order_currency
                    )->as_decimal_string(),
                );
            }
            \WP_CLI::line( 'Order Items:' );
            \WP_CLI\Utils\format_items( 'table', $item_rows, array( 'Product ID', 'Product Name', 'Quantity', 'Unit Price', 'Line Total' ) );
        }
    }

    /**
     * Update the status of an order.
     *
     * @param int    $id     Order ID.
     * @param string $status New status.
     */
    private function order_update_status( $id, $status ) {
        $valid_statuses = array( 'pending', 'processing', 'completed', 'on-hold', 'cancelled', 'refunded', 'failed' );

        if ( ! in_array( $status, $valid_statuses, true ) ) {
            \WP_CLI::error( "Invalid status '{$status}'. Valid statuses: " . implode( ', ', $valid_statuses ) );
            return;
        }

        $order = new \TejCart\Order\Order( $id );
        if ( ! $order->get_id() ) {
            \WP_CLI::error( "Order #{$id} not found." );
            return;
        }

        $order->update_status( $status, __( 'Status updated via WP-CLI.', 'tejcart' ) );
        \WP_CLI::success( "Order #{$id} status updated to '{$status}'." );
    }

    /**
     * Manage products.
     *
     * ## EXAMPLES
     *
     *     wp tejcart product list
     *     wp tejcart product list --type=simple --status=publish --limit=10
     *     wp tejcart product get 5
     *     wp tejcart product create --name="Test Product" --price=19.99
     *     wp tejcart product delete 5 --force
     *     wp tejcart product import /path/to/products.csv
     *     wp tejcart product import /path/to/products.csv --batch-size=500 --dry-run
     *
     * @subcommand product
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function product( $args, $assoc_args ) {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( 'Please specify a subcommand: list, get, create, duplicate, delete, or import.' );
            return;
        }

        $subcommand = $args[0];

        switch ( $subcommand ) {
            case 'list':
                $this->product_list( $assoc_args );
                break;
            case 'get':
                if ( empty( $args[1] ) ) {
                    \WP_CLI::error( 'Please specify a product ID.' );
                    return;
                }
                $this->product_get( absint( $args[1] ) );
                break;
            case 'create':
                $this->product_create( $assoc_args );
                break;
            case 'duplicate':
                if ( empty( $args[1] ) ) {
                    \WP_CLI::error( 'Please specify a product ID to duplicate.' );
                    return;
                }
                $this->product_duplicate( absint( $args[1] ) );
                break;
            case 'delete':
                if ( empty( $args[1] ) ) {
                    \WP_CLI::error( 'Please specify a product ID.' );
                    return;
                }
                $this->product_delete( absint( $args[1] ), $assoc_args );
                break;
            case 'import':
                if ( empty( $args[1] ) ) {
                    \WP_CLI::error( 'Please specify a CSV file path.' );
                    return;
                }
                $this->product_import( (string) $args[1], $assoc_args );
                break;
            default:
                \WP_CLI::error( "Unknown subcommand: {$subcommand}. Use list, get, create, duplicate, delete, or import." );
        }
    }

    /**
     * Bulk-import products from a CSV file.
     *
     * The CLI path is the recommended way to import very large catalogs (10K+
     * rows). It bypasses HTTP timeouts entirely and can be re-run if it dies
     * — products match by SKU so already-imported rows are simply updated
     * in-place on a retry.
     *
     * For very large catalogs with --with-images, the importer pipelines image
     * fetches through curl_multi (see --image-concurrency). For 15K+ image
     * catalogs, --defer-images queues the downloads to Action Scheduler so the
     * import returns in seconds and images stream in afterward.
     *
     * ## OPTIONS
     *
     * <file>
     * : Absolute (or working-directory-relative) path to the CSV.
     *
     * [--batch-size=<n>]
     * : Rows per DB transaction. Smaller batches trade throughput for finer
     *   progress reporting; larger batches reduce COMMIT overhead. Default 500.
     *
     * [--with-images]
     * : Sideload remote image URLs found in the image_url / gallery_image_urls
     *   columns. Disabled by default; enable to fetch images during import.
     *
     * [--image-concurrency=<n>]
     * : How many image downloads to run in parallel via curl_multi. Default
     *   auto-detects from PHP memory_limit (clamped 4..32). Set to 1 to fall
     *   back to the legacy sequential path; max 64.
     *
     * [--defer-images]
     * : Instead of fetching images inline, enqueue them to Action Scheduler
     *   under the `tejcart_import_image_sideload` hook. Recommended for
     *   15K+ image catalogs — the CLI returns quickly and AS processes
     *   downloads in the background, surviving deploys / process restarts.
     *
     * [--dry-run]
     * : Parse and validate the file, report what would change, then roll back
     *   without persisting any rows.
     *
     * [--quiet]
     * : Suppress the per-batch progress bar.
     *
     * ## EXAMPLES
     *
     *     # Standard 15K-row import with parallel image fetching.
     *     wp tejcart product import ./catalog.csv --with-images --image-concurrency=16
     *
     *     # Huge catalog (50K+ images): defer images and return immediately.
     *     wp tejcart product import ./catalog.csv --with-images --defer-images
     *
     *     # Dry-run validation (no writes).
     *     wp tejcart product import ./catalog.csv --dry-run
     *
     * @param string $path       CSV path.
     * @param array  $assoc_args Associative arguments.
     */
    private function product_import( string $path, array $assoc_args ): void {
        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            \WP_CLI::error( "CSV file not found or not readable: {$path}" );
            return;
        }

        $dry_run     = ! empty( $assoc_args['dry-run'] );
        $skip_images = empty( $assoc_args['with-images'] );
        $defer       = ! $skip_images && ! empty( $assoc_args['defer-images'] );
        $quiet       = ! empty( $assoc_args['quiet'] );

        // Default to 500 rows per batch. The legacy default of 200 is fine
        // but on CLI we can comfortably push higher — no PHP-FPM timeout to
        // worry about and the per-row work is cheap when images are
        // parallel-fetched (or skipped / deferred).
        $batch_size = isset( $assoc_args['batch-size'] ) ? max( 1, (int) $assoc_args['batch-size'] ) : 500;

        // Concurrency: caller can override; otherwise auto-derive from
        // PHP memory_limit. Disabled (=0) when images are skipped.
        if ( $skip_images ) {
            $image_concurrency = 0;
        } elseif ( isset( $assoc_args['image-concurrency'] ) ) {
            $image_concurrency = max( 1, min( 64, (int) $assoc_args['image-concurrency'] ) );
        } else {
            $image_concurrency = \TejCart\Admin\Import\Image_Sideloader::default_concurrency();
        }

        $importer = new \TejCart\Admin\Product_Import_Export();

        \WP_CLI::line( "Importing products from: {$path}" );
        $images_mode = $skip_images ? 'skip' : ( $defer ? "defer (concurrency={$image_concurrency})" : "parallel (concurrency={$image_concurrency})" );
        \WP_CLI::line( "Batch size: {$batch_size} | Dry run: " . ( $dry_run ? 'yes' : 'no' ) . " | Images: {$images_mode}" );

        $start = microtime( true );

        $progress         = null;
        $progress_handler = null;
        if ( ! $quiet && function_exists( 'WP_CLI\Utils\make_progress_bar' ) ) {
            // Three passes (parents, variations, references) — the bar
            // updates per pass with a fresh denominator each time.
            $progress_handler = function ( array $event ) use ( &$progress ) {
                if ( 'pass_start' === $event['event'] ) {
                    if ( $progress ) {
                        $progress->finish();
                        $progress = null;
                    }
                    $total    = max( 1, (int) ( $event['total_rows'] ?? 0 ) );
                    $label    = sprintf( 'Pass: %s', (string) $event['pass'] );
                    $progress = \WP_CLI\Utils\make_progress_bar( $label, $total );
                } elseif ( 'batch' === $event['event'] && $progress ) {
                    $rows = max( 1, (int) ( $event['rows'] ?? 1 ) );
                    for ( $i = 0; $i < $rows; $i++ ) {
                        $progress->tick();
                    }
                } elseif ( 'done' === $event['event'] ) {
                    if ( $progress ) {
                        $progress->finish();
                        $progress = null;
                    }
                }
            };
        }

        $summary = $importer->import_products(
            $path,
            $dry_run,
            array(
                'batch_size'        => $batch_size,
                'skip_images'       => $skip_images,
                'image_concurrency' => $image_concurrency,
                'image_defer'       => $defer,
                'progress_callback' => $progress_handler,
            )
        );

        $elapsed = number_format( microtime( true ) - $start, 1 );

        \WP_CLI::line( '' );
        \WP_CLI::line( "Created: {$summary['created']}" );
        \WP_CLI::line( "Updated: {$summary['updated']}" );
        \WP_CLI::line( "Skipped: {$summary['skipped']}" );
        \WP_CLI::line( "Errors:  {$summary['errors']}" );
        \WP_CLI::line( "Elapsed: {$elapsed}s" );

        if ( ! empty( $summary['error_messages'] ) ) {
            \WP_CLI::line( '' );
            \WP_CLI::line( 'First errors:' );
            foreach ( array_slice( $summary['error_messages'], 0, 25 ) as $msg ) {
                \WP_CLI::line( '  - ' . $msg );
            }
            if ( count( $summary['error_messages'] ) > 25 ) {
                $remaining = count( $summary['error_messages'] ) - 25;
                \WP_CLI::line( "  …and {$remaining} more." );
            }
        }

        if ( $summary['errors'] > 0 ) {
            \WP_CLI::warning( "Import completed with {$summary['errors']} error(s)." );
        } else {
            \WP_CLI::success( $dry_run ? 'Dry run complete — no changes were written.' : 'Import complete.' );
        }
    }

    /**
     * Clone an existing product as a new draft.
     *
     * @param int $product_id Source product ID.
     */
    private function product_duplicate( int $product_id ): void {
        $new_id = \TejCart\Product\Product_Factory::duplicate( $product_id );
        if ( $new_id <= 0 ) {
            \WP_CLI::error( "Failed to duplicate product #{$product_id}." );
            return;
        }
        \WP_CLI::success( "Duplicated product #{$product_id} -> new draft #{$new_id}." );
    }

    /**
     * List products with optional filters.
     *
     * @param array $assoc_args Associative arguments (type, status, limit).
     */
    private function product_list( $assoc_args ) {
        global $wpdb;

        $table  = $wpdb->prefix . 'tejcart_products';
        $type   = isset( $assoc_args['type'] ) ? sanitize_text_field( $assoc_args['type'] ) : '';
        $status = isset( $assoc_args['status'] ) ? sanitize_text_field( $assoc_args['status'] ) : '';
        $limit  = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 20;

        $conditions = array();
        $values     = array();

        if ( $type ) {
            $conditions[] = 'type = %s';
            $values[]     = $type;
        }
        if ( $status ) {
            $conditions[] = 'status = %s';
            $values[]     = $status;
        }

        $where = '';
        if ( ! empty( $conditions ) ) {
            $where = ' WHERE ' . implode( ' AND ', $conditions );
        }

        $values[] = $limit;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $products = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id, name, type, status, sku, price, sale_price, stock_quantity, stock_status FROM {$table}{$where} ORDER BY created_at DESC LIMIT %d",
                ...$values
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( empty( $products ) ) {
            \WP_CLI::line( 'No products found.' );
            return;
        }

        $items = array();
        foreach ( $products as $product ) {
            $items[] = array(
                'ID'     => $product->id,
                'Name'   => $product->name,
                'Type'   => $product->type,
                'Status' => $product->status,
                'SKU'    => $product->sku ?: '-',
                'Price'  => $product->price,
                'Sale'   => $product->sale_price ?: '-',
                'Stock'  => $product->stock_quantity ?? '-',
            );
        }

        \WP_CLI\Utils\format_items( 'table', $items, array( 'ID', 'Name', 'Type', 'Status', 'SKU', 'Price', 'Sale', 'Stock' ) );
    }

    /**
     * Show details for a single product.
     *
     * @param int $id Product ID.
     */
    private function product_get( $id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_products';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $product = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( ! $product ) {
            \WP_CLI::error( "Product #{$id} not found." );
            return;
        }

        \WP_CLI::line( '' );
        \WP_CLI::line( "Product #{$product->id}" );
        \WP_CLI::line( str_repeat( '-', 40 ) );
        \WP_CLI::line( "Name:            {$product->name}" );
        \WP_CLI::line( "Slug:            {$product->slug}" );
        \WP_CLI::line( "Type:            {$product->type}" );
        \WP_CLI::line( "Status:          {$product->status}" );
        \WP_CLI::line( "SKU:             " . ( $product->sku ?: '-' ) );
        \WP_CLI::line( "Price:           {$product->price}" );
        \WP_CLI::line( "Sale Price:      " . ( $product->sale_price ?: '-' ) );
        \WP_CLI::line( "Stock Quantity:  " . ( $product->stock_quantity ?? '-' ) );
        \WP_CLI::line( "Stock Status:    {$product->stock_status}" );
        \WP_CLI::line( "Manage Stock:    " . ( $product->manage_stock ? 'Yes' : 'No' ) );
        \WP_CLI::line( "Downloadable:    " . ( $product->downloadable ? 'Yes' : 'No' ) );
        \WP_CLI::line( "Virtual:         " . ( $product->virtual ? 'Yes' : 'No' ) );
        \WP_CLI::line( "Created:         {$product->created_at}" );
        \WP_CLI::line( "Updated:         {$product->updated_at}" );
        \WP_CLI::line( '' );
    }

    /**
     * Create a new product.
     *
     * @param array $assoc_args Associative arguments (name, price, type).
     */
    private function product_create( $assoc_args ) {
        global $wpdb;

        if ( empty( $assoc_args['name'] ) || ! isset( $assoc_args['price'] ) ) {
            \WP_CLI::error( 'Required: --name=<name> --price=<price>' );
            return;
        }

        $name  = sanitize_text_field( $assoc_args['name'] );
        $price = floatval( $assoc_args['price'] );
        $type  = isset( $assoc_args['type'] ) ? sanitize_text_field( $assoc_args['type'] ) : 'simple';
        $slug  = sanitize_title( $name );

        $table = $wpdb->prefix . 'tejcart_products';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $inserted = $wpdb->insert(
            $table,
            array(
                'name'   => $name,
                'slug'   => $slug,
                'type'   => $type,
                'status' => 'publish',
                'price'  => $price,
                'description'       => '',
                'short_description' => '',
            ),
            array( '%s', '%s', '%s', '%s', '%f', '%s', '%s' )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( $inserted ) {
            $product_id = $wpdb->insert_id;
            \WP_CLI::success( "Product #{$product_id} '{$name}' created." );
        } else {
            \WP_CLI::error( 'Failed to create product.' );
        }
    }

    /**
     * Delete a product.
     *
     * @param int   $id         Product ID.
     * @param array $assoc_args Associative arguments (force).
     */
    private function product_delete( $id, $assoc_args ) {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_products';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $product = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT id, name FROM {$table} WHERE id = %d", $id )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( ! $product ) {
            \WP_CLI::error( "Product #{$id} not found." );
            return;
        }

        $force = isset( $assoc_args['force'] );

        if ( ! $force ) {
            \WP_CLI::confirm( "Are you sure you want to delete product #{$id} '{$product->name}'?" );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

        if ( $deleted ) {
            $meta_table = $wpdb->prefix . 'tejcart_product_meta';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->delete( $meta_table, array( 'product_id' => $id ), array( '%d' ) );

            \WP_CLI::success( "Product #{$id} deleted." );
        } else {
            \WP_CLI::error( "Failed to delete product #{$id}." );
        }
    }

    /**
     * Manage customers.
     *
     * ## EXAMPLES
     *
     *     wp tejcart customer list
     *     wp tejcart customer list --limit=10
     *     wp tejcart customer get 3
     *
     * @subcommand customer
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function customer( $args, $assoc_args ) {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( 'Please specify a subcommand: list or get.' );
            return;
        }

        $subcommand = $args[0];

        switch ( $subcommand ) {
            case 'list':
                $this->customer_list( $assoc_args );
                break;
            case 'get':
                if ( empty( $args[1] ) ) {
                    \WP_CLI::error( 'Please specify a customer ID.' );
                    return;
                }
                $this->customer_get( absint( $args[1] ) );
                break;
            case 'rfm':
                $this->customer_rfm( $args, $assoc_args );
                break;
            default:
                \WP_CLI::error( "Unknown subcommand: {$subcommand}. Use list, get, or rfm." );
        }
    }

    /**
     * List customers.
     *
     * @param array $assoc_args Associative arguments (limit).
     */
    private function customer_list( $assoc_args ) {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_customers';
        $limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 20;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $customers = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id, user_id, email, first_name, last_name, created_at FROM {$table} ORDER BY created_at DESC LIMIT %d",
                $limit
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( empty( $customers ) ) {
            \WP_CLI::line( 'No customers found.' );
            return;
        }

        $items = array();
        foreach ( $customers as $customer ) {
            $items[] = array(
                'ID'         => $customer->id,
                'User ID'    => $customer->user_id ?: '-',
                'Name'       => trim( $customer->first_name . ' ' . $customer->last_name ),
                'Email'      => $customer->email,
                'Registered' => $customer->created_at,
            );
        }

        \WP_CLI\Utils\format_items( 'table', $items, array( 'ID', 'User ID', 'Name', 'Email', 'Registered' ) );
    }

    /**
     * Show details for a single customer.
     *
     * @param int $id Customer ID.
     */
    private function customer_get( $id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_customers';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $customer = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( ! $customer ) {
            \WP_CLI::error( "Customer #{$id} not found." );
            return;
        }

        $orders_table = $wpdb->prefix . 'tejcart_orders';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $order_count = (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( "SELECT COUNT(*) FROM {$orders_table} WHERE customer_id = %d", $id )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        // SUM(total) is BIGINT minor units in the order's currency.
        // Customer-aggregate display assumes the shop currency (M1
        // does not bucket per-currency; see docs/money-representation.md §3).
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total_spent_minor = (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT COALESCE(SUM(total), 0) FROM {$orders_table} WHERE customer_id = %d AND status NOT IN ('cancelled','refunded')",
                $id
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $shop_currency = function_exists( 'tejcart_get_currency' )
            ? (string) tejcart_get_currency()
            : (string) get_option( 'tejcart_currency', 'USD' );
        $total_spent   = \TejCart\Money\Currency::from_minor_units( $total_spent_minor, $shop_currency );

        \WP_CLI::line( '' );
        \WP_CLI::line( "Customer #{$customer->id}" );
        \WP_CLI::line( str_repeat( '-', 40 ) );
        \WP_CLI::line( "Name:         {$customer->first_name} {$customer->last_name}" );
        \WP_CLI::line( "Email:        {$customer->email}" );
        \WP_CLI::line( "User ID:      " . ( $customer->user_id ?: '-' ) );
        \WP_CLI::line( "Orders:       {$order_count}" );
        \WP_CLI::line( "Total Spent:  " . number_format( $total_spent, 2 ) . ' ' . $shop_currency );
        \WP_CLI::line( "Registered:   {$customer->created_at}" );
        \WP_CLI::line( '' );
    }

    /**
     * Handle `wp tejcart customer rfm` subcommands.
     *
     * @param array $args       Positional arguments (rfm is $args[0]).
     * @param array $assoc_args Associative arguments.
     */
    private function customer_rfm( $args, $assoc_args ) {
        $sub = $args[1] ?? '';

        switch ( $sub ) {
            case 'rebuild':
                $this->customer_rfm_rebuild();
                break;
            case 'summary':
                $this->customer_rfm_summary();
                break;
            default:
                \WP_CLI::error( 'Usage: wp tejcart customer rfm <rebuild|summary>' );
        }
    }

    private function customer_rfm_rebuild(): void {
        \WP_CLI::line( 'Rebuilding RFM scores for all customers...' );

        $scorer = new \TejCart\Customer\RFM_Scorer();
        $result = $scorer->run_full_rebuild();

        \WP_CLI::success( sprintf(
            'RFM rebuild complete: %d customers scored in %.2f seconds.',
            $result['customers'],
            $result['elapsed']
        ) );
    }

    private function customer_rfm_summary(): void {
        $summary         = \TejCart\Customer\Segment::segment_summary();
        $shop_currency   = function_exists( 'tejcart_get_currency' )
            ? (string) tejcart_get_currency()
            : (string) get_option( 'tejcart_currency', 'USD' );
        $auto_segments   = \TejCart\Customer\Segment::AUTO_SEGMENTS;

        if ( empty( $summary ) ) {
            \WP_CLI::warning( 'No segment data found. Run: wp tejcart customer rfm rebuild' );
            return;
        }

        $items = array();
        foreach ( $auto_segments as $slug => $def ) {
            $data    = $summary[ $slug ] ?? array( 'count' => 0, 'revenue_minor' => 0 );
            $revenue = \TejCart\Money\Currency::from_minor_units( $data['revenue_minor'], $shop_currency );
            $items[] = array(
                'Segment'   => $def['label'],
                'Customers' => number_format_i18n( $data['count'] ),
                'Revenue'   => number_format( $revenue, 2 ) . ' ' . $shop_currency,
            );
        }

        \WP_CLI\Utils\format_items( 'table', $items, array( 'Segment', 'Customers', 'Revenue' ) );
    }

    /**
     * TejCart maintenance tools.
     *
     * ## EXAMPLES
     *
     *     wp tejcart tool clear-sessions
     *     wp tejcart tool clear-transients
     *     wp tejcart tool db-size
     *     wp tejcart tool recount
     *
     * @subcommand tool
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function tool( $args, $assoc_args ) {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( 'Please specify a tool: clear-sessions, clear-transients, db-size, or recount.' );
            return;
        }

        $subcommand = $args[0];

        switch ( $subcommand ) {
            case 'clear-sessions':
                $this->tool_clear_sessions();
                break;
            case 'clear-transients':
                $this->tool_clear_transients();
                break;
            case 'db-size':
                $this->tool_db_size();
                break;
            case 'recount':
                $this->tool_recount();
                break;
            default:
                \WP_CLI::error( "Unknown tool: {$subcommand}. Use clear-sessions, clear-transients, db-size, or recount." );
        }
    }

    /**
     * Clear expired sessions from the database.
     */
    private function tool_clear_sessions() {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_sessions';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $deleted = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE session_expiry < %d",
                time()
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( false === $deleted ) {
            \WP_CLI::error( 'Failed to clear sessions.' );
            return;
        }

        \WP_CLI::success( "Cleared {$deleted} expired session(s)." );
    }

    /**
     * Clear all TejCart transients from the options table.
     */
    private function tool_clear_transients() {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $deleted = $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_tejcart_' ) . '%',
                $wpdb->esc_like( '_transient_timeout_tejcart_' ) . '%'
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( false === $deleted ) {
            \WP_CLI::error( 'Failed to clear transients.' );
            return;
        }

        \WP_CLI::success( "Cleared {$deleted} TejCart transient(s)." );
    }

    /**
     * Show database table sizes for all TejCart tables.
     */
    private function tool_db_size() {
        global $wpdb;

        $tables = array(
            'tejcart_products',
            'tejcart_product_meta',
            'tejcart_orders',
            'tejcart_order_items',
            'tejcart_order_meta',
            'tejcart_customers',
            'tejcart_coupons',
            'tejcart_sessions',
            'tejcart_term_relationships',
        );

        $items = array();
        $total_size = 0;

        foreach ( $tables as $table ) {
            $full_table = $wpdb->prefix . $table;

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $row = $wpdb->get_row(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT table_rows AS rows, ROUND((data_length + index_length) / 1024, 2) AS size_kb
                     FROM information_schema.TABLES
                     WHERE table_schema = %s AND table_name = %s",
                    DB_NAME,
                    $full_table
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( $row ) {
                $items[] = array(
                    'Table'   => $table,
                    'Rows'    => (int) $row->rows,
                    'Size KB' => $row->size_kb,
                );
                $total_size += (float) $row->size_kb;
            } else {
                $items[] = array(
                    'Table'   => $table,
                    'Rows'    => 'N/A',
                    'Size KB' => 'N/A',
                );
            }
        }

        \WP_CLI\Utils\format_items( 'table', $items, array( 'Table', 'Rows', 'Size KB' ) );
        \WP_CLI::line( '' );
        \WP_CLI::line( 'Total size: ' . number_format( $total_size, 2 ) . ' KB' );
    }

    /**
     * Recount order totals and product stock levels.
     */
    private function tool_recount() {
        global $wpdb;

        $orders_table      = $wpdb->prefix . 'tejcart_orders';
        $order_items_table = $wpdb->prefix . 'tejcart_order_items';
        $products_table    = $wpdb->prefix . 'tejcart_products';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $orders = $wpdb->get_results( "SELECT id FROM {$orders_table}" );

        $order_updates = 0;
        foreach ( $orders as $order ) {
            // All money columns are BIGINT minor units in the order's
            // currency; the recount stays in integer arithmetic so there
            // is no float drift. discount_total is subtracted, not added.
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $line_total_minor = (int) $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT COALESCE(SUM(line_total), 0) FROM {$order_items_table} WHERE order_id = %d",
                    $order->id
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $current = $wpdb->get_row(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare( "SELECT subtotal, discount_total, shipping_total, tax_total FROM {$orders_table} WHERE id = %d", $order->id )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( $current ) {
                $recalculated_minor = $line_total_minor
                    - (int) $current->discount_total
                    + (int) $current->shipping_total
                    + (int) $current->tax_total;

                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->update(
                    $orders_table,
                    array(
                        'subtotal' => $line_total_minor,
                        'total'    => $recalculated_minor,
                    ),
                    array( 'id' => $order->id ),
                    array( '%d', '%d' ),
                    array( '%d' )
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $order_updates++;
            }
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $managed_products = $wpdb->get_results(
            "SELECT id FROM {$products_table} WHERE manage_stock = 1"
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $stock_updates = 0;
        foreach ( $managed_products as $product ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $sold = (int) $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT COALESCE(SUM(oi.quantity), 0)
                     FROM {$order_items_table} oi
                     INNER JOIN {$orders_table} o ON oi.order_id = o.id
                     WHERE oi.product_id = %d AND o.status IN ('processing', 'completed')",
                    $product->id
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $current_stock = (int) $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare( "SELECT stock_quantity FROM {$products_table} WHERE id = %d", $product->id )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            $stock_status = $current_stock > 0 ? 'instock' : 'outofstock';

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->update(
                $products_table,
                array( 'stock_status' => $stock_status ),
                array( 'id' => $product->id ),
                array( '%s' ),
                array( '%d' )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $stock_updates++;
        }

        \WP_CLI::success( "Recounted {$order_updates} order total(s) and {$stock_updates} stock level(s)." );
    }

    /**
     * Distinct currencies that have orders on the given UTC `Y-m-d`.
     *
     * Used by `wp tejcart reports rebuild` to fan out the per-day
     * recompute across every currency that saw activity, not just the
     * shop default (F-005).
     *
     * @return array<int,string>
     */
    private static function distinct_currencies_for_day( string $day ): array {
        global $wpdb;

        $start_ts = strtotime( $day . ' 00:00:00 UTC' );
        if ( ! is_int( $start_ts ) ) {
            return array();
        }
        $start = gmdate( 'Y-m-d H:i:s', $start_ts );
        $end   = gmdate( 'Y-m-d H:i:s', $start_ts + DAY_IN_SECONDS );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT currency FROM {$wpdb->prefix}tejcart_orders WHERE created_at >= %s AND created_at < %s",
                $start,
                $end
            )
        );

        if ( ! is_array( $rows ) ) {
            return array();
        }
        return array_values( array_filter( array_map( 'strval', $rows ), static fn ( string $c ): bool => '' !== $c ) );
    }
}
