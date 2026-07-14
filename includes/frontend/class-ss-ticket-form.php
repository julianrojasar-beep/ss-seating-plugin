<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SS_Ticket_Form — Formulario de compra de tickets.
 * Shortcode: [ss_ticket_form]
 *
 * Usa SS_Event_Service para obtener datos del evento.
 */
class SS_Ticket_Form {

    private static ?SS_Ticket_Form $instance = null;

    private function __construct() {
        add_shortcode( 'ss_ticket_form', array( $this, 'render' ) );
    }

    public static function init(): void {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
    }

    /**
     * Renderiza el formulario de tickets.
     */
    public function render( $atts = array() ): string {
        $atts = shortcode_atts( array(
            'event_id' => 0,
        ), $atts, 'ss_ticket_form' );

        $event_id = (int) $atts['event_id'];
        if ( ! $event_id && is_singular() ) {
            $event_id = get_the_ID();
        }
        if ( ! $event_id ) {
            return '';
        }

        $service = SS_Event_Service::instance();
        $event   = $service->get_event( $event_id );
        if ( ! $event ) {
            return '';
        }

        $layout       = $service->get_layout( $event_id );
        $ticket_types = $service->get_ticket_types( $event_id );
        $sale_mode    = $service->get_sale_mode( $event_id );

        // Obtener product_id de WC asociado al evento
        $product_id = self::get_product_id( $event_id );

        // Encolar JS del formulario
        $this->enqueue_assets( $event_id, $sale_mode, $product_id );

        // Determinar si el evento ya pasó
        $event_date_raw = get_post_meta( $event_id, '_ss_event_date', true );
        $is_past        = $event_date_raw && $event_date_raw < current_time( 'Y-m-d' );

        // Renderizar template
        ob_start();
        include plugin_dir_path( dirname( __DIR__ ) ) . 'templates/ticket-form.php';
        return (string) ob_get_clean();
    }

    /**
     * Obtener el product_id de WC vinculado al evento.
     * Producto oculto creado por SS_Event_CPT::ensure_wc_product().
     */
    public static function get_product_id( int $event_id ): int {
        $pid = (int) get_post_meta( $event_id, '_ss_product_id', true );
        return $pid ?: 0;
    }

    /**
     * Encolar Konva renderer + script del formulario y pasar datos al JS.
     */
    private function enqueue_assets( int $event_id, string $sale_mode, int $product_id ): void {
        $plugin_url  = plugin_dir_url( dirname( __DIR__ ) );
        $plugin_path = plugin_dir_path( dirname( __DIR__ ) );

        // CSS de la página de evento
        wp_enqueue_style(
            'ss-event-page',
            $plugin_url . 'assets/css/ss-event-page.css',
            array(),
            filemtime( $plugin_path . 'assets/css/ss-event-page.css' )
        );

        // Inyectar color primario + color de texto como CSS variables
        $primary = SS_Settings::get( 'color_primary', '#6d28d9' );
        // Generar variante más clara (+15% lightness aprox)
        $r = hexdec( substr( $primary, 1, 2 ) );
        $g = hexdec( substr( $primary, 3, 2 ) );
        $b = hexdec( substr( $primary, 5, 2 ) );
        $lighter  = sprintf( '#%02x%02x%02x', min( 255, $r + 25 ), min( 255, $g + 25 ), min( 255, $b + 25 ) );
        $contrast = ss_get_contrast_text_color( $primary );

        $text_color = SS_Settings::get( 'text_color', '#f0ede8' );
        $tr = hexdec( substr( $text_color, 1, 2 ) );
        $tg = hexdec( substr( $text_color, 3, 2 ) );
        $tb = hexdec( substr( $text_color, 5, 2 ) );

        wp_add_inline_style( 'ss-event-page', ":root { --ss-primary: {$primary}; --ss-primary-light: {$lighter}; --ss-primary-rgb: {$r},{$g},{$b}; --ss-primary-contrast: {$contrast}; --ss-text: {$text_color}; --ss-text-rgb: {$tr},{$tg},{$tb}; }" );

        // Konva renderer stack (si hay layout con filas)
        $layout_raw = SS_Event_Service::instance()->get_layout_raw( $event_id );
        $layout_decoded = is_string( $layout_raw ) ? json_decode( $layout_raw, true ) : null;

        if ( is_array( $layout_decoded ) && ! empty( ss_layout_get_rows( $layout_decoded ) )
             && in_array( $sale_mode, array( 'seat', 'hybrid', 'general' ), true ) ) {

            $renderer_ver = filemtime( $plugin_path . 'assets/js/ss-konva-renderer.js' ) ?: '1.1.0';

            wp_enqueue_script( 'konva', $plugin_url . 'assets/js/konva.min.js', array(), '9.3.6', true );
            wp_enqueue_script( 'ss-seat-engine', $plugin_url . 'assets/js/seat-engine.js', array(), $renderer_ver, true );
            wp_enqueue_script( 'ss-konva-renderer', $plugin_url . 'assets/js/ss-konva-renderer.js', array( 'konva', 'ss-seat-engine' ), $renderer_ver, true );

            // Layout + estado de asientos para el renderer
            $current_session = function_exists( 'WC' ) && WC()->session ? (string) WC()->session->get_customer_id() : '';
            if ( function_exists( 'ss_cleanup_expired_reservations' ) ) {
                ss_cleanup_expired_reservations( $event_id );
            }
            wp_localize_script( 'ss-konva-renderer', 'ssLayoutData', $layout_decoded );

            // ── Sold seats: ledger con fallback a WC orders ──
            $sold_seats = ss_seats_read( $event_id );

            // Fallback: si el ledger devuelve vacío, leer directamente de pedidos WC
            if ( empty( $sold_seats ) && function_exists( 'wc_get_orders' ) ) {
                $sold_seats = ss_seats_read_from_orders( $event_id );
            }

            // ── Reserved seats: ledger (con protección de errores) ──
            $ledger_blocked = array();
            if ( function_exists( 'ss_ledger_get_blocked_seats' ) ) {
                $ledger_blocked = ss_ledger_get_blocked_seats( $event_id, $current_session );
                if ( ! is_array( $ledger_blocked ) ) { $ledger_blocked = array(); }
            }

            $state_data = array(
                'sold'          => array_values( $sold_seats ),
                'reserved'      => array_values( array_unique( $ledger_blocked ) ),
                'zoneInventory' => function_exists( 'ss_get_zone_inventory' ) ? ss_get_zone_inventory( $event_id ) : array(),
            );

            wp_localize_script( 'ss-konva-renderer', 'ssSeatingState', $state_data );

            // DEBUG: panel visible (quitar después de confirmar que funciona)
            if ( defined( 'SS_SEATING_DEBUG' ) && SS_SEATING_DEBUG ) {
                add_action( 'wp_footer', function () use ( $state_data, $event_id, $current_session ) {
                    echo '<!-- SS DEBUG event=' . $event_id . ' session=' . esc_html( $current_session ) . ' -->';
                    echo '<div id="ss-debug" style="position:fixed;bottom:0;left:0;right:0;background:#111;color:#0f0;font-family:monospace;font-size:11px;padding:8px 12px;z-index:99999;max-height:120px;overflow:auto;">';
                    echo '<strong>SS DEBUG</strong> event=' . $event_id;
                    echo ' | sold(' . count( $state_data['sold'] ) . '): ' . esc_html( implode( ', ', array_slice( $state_data['sold'], 0, 20 ) ) );
                    echo ' | reserved(' . count( $state_data['reserved'] ) . '): ' . esc_html( implode( ', ', array_slice( $state_data['reserved'], 0, 20 ) ) );
                    echo ' | session=' . esc_html( $current_session );
                    global $wpdb;
                    $t = $wpdb->prefix . 'ss_seat_ledger';
                    $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$t}'" );
                    echo ' | table=' . ( $exists ? 'YES' : 'NO' );
                    if ( $exists ) {
                        $cols = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$t}" ), 'Field' );
                        echo ' cols=[' . implode( ',', $cols ) . ']';
                        $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
                        echo ' rows=' . $count;
                    }
                    echo '</div>';
                }, 9999 );
            }

            // Pasar saleMode al renderer para que sepa si es interactivo o no
            wp_localize_script( 'ss-konva-renderer', 'ssSeatingAjax', array(
                'url'      => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'ss_save_seats' ),
                'eventId'  => $event_id,
                'saleMode' => $sale_mode,
                'colors'   => array(
                    'sold'     => SS_Settings::get( 'seat_sold_color',     '#9e9e9e' ),
                    'reserved' => SS_Settings::get( 'seat_reserved_color', '#FF9800' ),
                ),
            ) );

            $form_deps = array( 'jquery', 'ss-konva-renderer' );
        } else {
            $form_deps = array( 'jquery' );
        }

        // Script del formulario
        wp_enqueue_script(
            'ss-ticket-form',
            $plugin_url . 'assets/js/ss-ticket-form.js',
            $form_deps,
            filemtime( $plugin_path . 'assets/js/ss-ticket-form.js' ),
            true
        );

        wp_localize_script( 'ss-ticket-form', 'ssTicketForm', array(
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'ss_save_seats' ),
            'cartUrl'    => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
            'eventId'    => $event_id,
            'productId'  => $product_id,
            'saleMode'   => $sale_mode,
        ) );
    }
}
