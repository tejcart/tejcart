<?php
/**
 * Low stock digest email template (gold-grade, inline styles).
 *
 * Sent to the store admin summarising every product that has reached its
 * low-stock or out-of-stock threshold. Header and footer are supplied by
 * Abstract_Email::send().
 *
 * @package TejCart\Templates\Emails
 *
 * @var array<int, array{id:int,name:string,sku:string,stock:int}> $out_products
 * @var array<int, array{id:int,name:string,sku:string,stock:int}> $low_products
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

include __DIR__ . '/parts/styles.php';

$out_products = isset( $out_products ) && is_array( $out_products ) ? $out_products : array();
$low_products = isset( $low_products ) && is_array( $low_products ) ? $low_products : array();

$total = count( $out_products ) + count( $low_products );

/**
 * Render one product row inside a section table.
 *
 * @param array{id:int,name:string,sku:string,stock:int} $product Product row.
 * @param string                                          $td      Cell style.
 * @param string                                          $tdr     Right cell style.
 */
$render_row = static function ( array $product, string $td, string $tdr ): string {
    $edit_url = admin_url( 'admin.php?page=tejcart-products&action=edit&product_id=' . (int) $product['id'] );
    $sku      = '' !== (string) $product['sku'] ? (string) $product['sku'] : __( 'N/A', 'tejcart' );

    $name_cell  = '<td style="' . esc_attr( $td ) . '">';
    $name_cell .= '<a href="' . esc_url( $edit_url ) . '" style="color:inherit;text-decoration:none;font-weight:600;">' . esc_html( (string) $product['name'] ) . '</a>';
    $name_cell .= '</td>';

    $sku_cell   = '<td style="' . esc_attr( $td ) . '">' . esc_html( $sku ) . '</td>';
    $stock_cell = '<td style="' . esc_attr( $tdr ) . '">' . esc_html( (string) (int) $product['stock'] ) . '</td>';

    return '<tr>' . $name_cell . $sku_cell . $stock_cell . '</tr>';
};
?>

<p class="nx-text" style="<?php echo esc_attr( $nx_p_style ); ?>">
    <?php
    echo esc_html(
        sprintf(
            /* translators: %d: number of products needing attention */
            _n(
                '%d product needs your attention.',
                '%d products need your attention.',
                $total,
                'tejcart'
            ),
            $total
        )
    );
    ?>
</p>

<?php if ( ! empty( $out_products ) ) : ?>
    <h2 class="nx-h2" style="<?php echo esc_attr( $nx_h3_style ); ?>"><?php echo esc_html__( 'Out of stock', 'tejcart' ); ?></h2>
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" class="nx-table-row" style="<?php echo esc_attr( $nx_table_style ); ?>">
        <thead>
            <tr>
                <th class="nx-th" style="<?php echo esc_attr( $nx_th_style ); ?>"><?php echo esc_html__( 'Product', 'tejcart' ); ?></th>
                <th class="nx-th" style="<?php echo esc_attr( $nx_th_style ); ?>"><?php echo esc_html__( 'SKU', 'tejcart' ); ?></th>
                <th class="nx-th" style="<?php echo esc_attr( $nx_th_right ); ?>"><?php echo esc_html__( 'Stock', 'tejcart' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            foreach ( $out_products as $product ) {
                echo $render_row( $product, $nx_td_style, $nx_tdr_style );
            }
            ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if ( ! empty( $low_products ) ) : ?>
    <h2 class="nx-h2" style="<?php echo esc_attr( $nx_h3_style ); ?>"><?php echo esc_html__( 'Low stock', 'tejcart' ); ?></h2>
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" class="nx-table-row" style="<?php echo esc_attr( $nx_table_style ); ?>">
        <thead>
            <tr>
                <th class="nx-th" style="<?php echo esc_attr( $nx_th_style ); ?>"><?php echo esc_html__( 'Product', 'tejcart' ); ?></th>
                <th class="nx-th" style="<?php echo esc_attr( $nx_th_style ); ?>"><?php echo esc_html__( 'SKU', 'tejcart' ); ?></th>
                <th class="nx-th" style="<?php echo esc_attr( $nx_th_right ); ?>"><?php echo esc_html__( 'Remaining', 'tejcart' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            foreach ( $low_products as $product ) {
                echo $render_row( $product, $nx_td_style, $nx_tdr_style );
            }
            ?>
        </tbody>
    </table>
<?php endif; ?>

<p class="nx-muted" style="<?php echo esc_attr( $nx_muted_style ); ?>">
    <?php echo esc_html__( 'Please restock these items as soon as possible.', 'tejcart' ); ?>
</p>

<?php
if ( function_exists( 'tejcart_email_button' ) ) {
    echo tejcart_email_button( admin_url( 'admin.php?page=tejcart-products' ), __( 'Manage products', 'tejcart' ) );
}
?>
