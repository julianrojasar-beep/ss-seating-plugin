<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SS_Group_Discount — Descuento por compra grupal.
 *
 * Configurado por evento:
 *   _ss_group_discount_enabled  (1/0)
 *   _ss_group_discount_min_qty  (int, default 5)
 *   _ss_group_discount_pct      (int 0-100)
 */
class SS_Group_Discount {

    /**
     * Devuelve el % de descuento grupal aplicable para el carrito actual.
     * Retorna 0 si no aplica.
     */
    public static function get_discount_for_cart(): int {
        if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
            return 0;
        }

        $best_pct = 0;

        foreach ( WC()->cart->get_cart() as $item ) {
            $event_id = (int) ( $item['ss_event_id'] ?? 0 );
            if ( ! $event_id ) {
                continue;
            }
            if ( get_post_meta( $event_id, '_ss_group_discount_enabled', true ) !== '1' ) {
                continue;
            }
            $min_qty = (int) get_post_meta( $event_id, '_ss_group_discount_min_qty', true );
            if ( $min_qty <= 0 ) {
                $min_qty = 5;
            }
            // En modo seat, quantity = 1 siempre; el conteo real está en ss_seats[]
            $sale_mode = get_post_meta( $event_id, '_ss_sale_mode', true ) ?: 'seat';
            if ( $sale_mode === 'seat' && ! empty( $item['ss_seats'] ) && is_array( $item['ss_seats'] ) ) {
                $ticket_qty = count( $item['ss_seats'] );
            } else {
                $qty        = (int) $item['quantity'];
                $ticket_qty = isset( $item['ss_ticket_qty'] ) ? (int) $item['ss_ticket_qty'] : $qty;
                if ( $ticket_qty <= 0 ) {
                    $ticket_qty = $qty;
                }
            }
            if ( $ticket_qty < $min_qty ) {
                continue;
            }
            $pct = (int) get_post_meta( $event_id, '_ss_group_discount_pct', true );
            if ( $pct > $best_pct ) {
                $best_pct = $pct;
            }
        }

        return $best_pct;
    }

    /**
     * Devuelve info del descuento grupal para un evento específico.
     * Usado por el Box Office para pre-rellenar la calculadora.
     */
    public static function get_for_event( int $event_id ): array {
        if ( get_post_meta( $event_id, '_ss_group_discount_enabled', true ) !== '1' ) {
            return array( 'enabled' => false, 'min_qty' => 5, 'pct' => 0 );
        }
        $min_qty = (int) get_post_meta( $event_id, '_ss_group_discount_min_qty', true );
        $pct     = (int) get_post_meta( $event_id, '_ss_group_discount_pct', true );
        return array(
            'enabled' => true,
            'min_qty' => $min_qty > 0 ? $min_qty : 5,
            'pct'     => $pct,
        );
    }
}
