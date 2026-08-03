<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Envío server-side del evento Purchase a Meta Conversions API (CAPI).
 *
 * Deshabilitado por defecto: solo se activa si SS_META_CAPI_PIXEL_ID y
 * SS_META_CAPI_TOKEN quedan con valor, ya sea forzadas en wp-config.php o
 * guardadas desde SS Seating → Configuración → Módulos (?tab=modulos&key=ssdev).
 * Sin ambos valores, esta clase no hace nada.
 *
 * event_id determinístico = 'wp_order_' + order_id — DEBE coincidir con el eventID
 * que use el Pixel de navegador (instalado fuera de este plugin, vía GTM/tema/otro
 * plugin) al hacer fbq('track', 'Purchase', {...}, {eventID: 'wp_order_'+order_id}),
 * si no Meta cuenta la misma compra dos veces (una por Pixel, otra por CAPI) en vez
 * de deduplicarlas. Ver dashboard del lado Sheets: D:\dashboard\CLAUDE.md, sección
 * "CAPI / purchase-level attribution", y CAPI_EVENT_ID_PREFIX en Codigo_produccion.gs.
 */
class SS_Meta_CAPI {

    const GRAPH_VERSION = 'v21.0';
    const EVENT_ID_PREFIX = 'wp_order_';

    public static function init(): void {
        if ( ! self::enabled() ) {
            return;
        }
        add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'send_purchase' ), 25, 1 );
        add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'send_purchase' ), 25, 1 );
    }

    public static function enabled(): bool {
        return defined( 'SS_META_CAPI_PIXEL_ID' ) && SS_META_CAPI_PIXEL_ID
            && defined( 'SS_META_CAPI_TOKEN' ) && SS_META_CAPI_TOKEN;
    }

    /**
     * Envía el Purchase una sola vez por pedido (idempotente vía meta _ss_capi_sent),
     * porque los hooks de cambio de estado pueden disparar más de una vez.
     */
    public static function send_purchase( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        if ( $order->get_meta( '_ss_capi_sent' ) === 'yes' ) {
            return;
        }
        // Las ventas de Box Office no tienen checkout web (no hay fbclid/cookies de
        // navegador que atribuir) — no tiene sentido mandarlas a CAPI.
        if ( $order->get_meta( '_ss_boxoffice_sale' ) === 'yes' ) {
            return;
        }

        $event_id = self::EVENT_ID_PREFIX . $order_id;
        $payload  = self::build_payload( $order, $event_id );

        $response = wp_remote_post(
            'https://graph.facebook.com/' . self::GRAPH_VERSION . '/' . SS_META_CAPI_PIXEL_ID . '/events',
            array(
                'timeout' => 8,
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode( array(
                    'data'         => array( $payload ),
                    'access_token' => SS_META_CAPI_TOKEN,
                ) ),
            )
        );

        if ( is_wp_error( $response ) ) {
            $order->update_meta_data( '_ss_capi_sent', 'error' );
            $order->update_meta_data( '_ss_capi_error', $response->get_error_message() );
            if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating][capi] error de red pedido ' . $order_id . ': ' . $response->get_error_message() ); }
        } else {
            $code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );
            if ( $code === 200 ) {
                $order->update_meta_data( '_ss_capi_sent', 'yes' );
                $order->update_meta_data( '_ss_capi_event_id', $event_id );
            } else {
                $order->update_meta_data( '_ss_capi_sent', 'error' );
                $order->update_meta_data( '_ss_capi_error', 'HTTP ' . $code . ': ' . $body );
                if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating][capi] HTTP ' . $code . ' pedido ' . $order_id . ': ' . $body ); }
            }
        }
        $order->save();
    }

    private static function build_payload( \WC_Order $order, string $event_id ): array {
        $user_data = array();

        $email = $order->get_billing_email();
        if ( $email ) {
            $user_data['em'] = array( hash( 'sha256', strtolower( trim( $email ) ) ) );
        }
        $phone = $order->get_billing_phone();
        if ( $phone ) {
            // Meta espera el teléfono sin símbolos, con código de país, antes del hash.
            $phone_normalizado = preg_replace( '/[^0-9]/', '', $phone );
            if ( $phone_normalizado ) {
                $user_data['ph'] = array( hash( 'sha256', $phone_normalizado ) );
            }
        }
        $fbc = $order->get_meta( '_ss_fbc' );
        if ( $fbc ) {
            $user_data['fbc'] = $fbc;
        }
        $fbp = $order->get_meta( '_ss_fbp' );
        if ( $fbp ) {
            $user_data['fbp'] = $fbp;
        }
        $ip = $order->get_customer_ip_address();
        if ( $ip ) {
            $user_data['client_ip_address'] = $ip;
        }
        $ua = $order->get_customer_user_agent();
        if ( $ua ) {
            $user_data['client_user_agent'] = $ua;
        }

        $boletas = 0;
        foreach ( $order->get_items() as $item ) {
            $qtys = $item->get_meta( 'ss_ticket_qtys' );
            if ( is_array( $qtys ) ) {
                $boletas += array_sum( $qtys );
            } else {
                $boletas += (int) $item->get_quantity();
            }
        }

        return array(
            'event_name'   => 'Purchase',
            'event_time'   => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time(),
            'event_id'     => $event_id,
            'action_source' => 'website',
            'user_data'    => $user_data,
            'custom_data'  => array(
                'currency'  => $order->get_currency(),
                'value'     => (float) $order->get_total(),
                'num_items' => $boletas ?: 1,
            ),
        );
    }
}
