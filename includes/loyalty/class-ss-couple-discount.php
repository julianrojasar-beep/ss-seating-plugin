<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SS_Couple_Discount — Descuento por compra en pareja (2 boletas).
 *
 * Configurado por evento (el admin elige un tipo, no ambos a la vez):
 *   _ss_couple_discount_enabled     (1/0)
 *   _ss_couple_discount_type        ('percentage' | 'fixed_price', default 'percentage')
 *   _ss_couple_discount_pct         (int 0-100)          — usado si type = percentage
 *   _ss_couple_discount_fixed_price (float, precio total) — usado si type = fixed_price
 *
 * El umbral es fijo en 2 boletas y no es configurable por evento.
 * El modo "precio fijo" solo aplica cuando se compran EXACTAMENTE 2 boletas
 * y ambas son de la misma zona/tipo (si hay más boletas o zonas distintas,
 * no se aplica automáticamente).
 */
class SS_Couple_Discount {

    const MIN_QTY = 2;

    /**
     * Devuelve el % de descuento de pareja aplicable para el carrito actual
     * (solo considera eventos configurados en modo 'percentage').
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
            if ( get_post_meta( $event_id, '_ss_couple_discount_enabled', true ) !== '1' ) {
                continue;
            }
            if ( self::get_type( $event_id ) !== 'percentage' ) {
                continue;
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
            if ( $ticket_qty < self::MIN_QTY ) {
                continue;
            }
            $pct = (int) get_post_meta( $event_id, '_ss_couple_discount_pct', true );
            if ( $pct > $best_pct ) {
                $best_pct = $pct;
            }
        }

        return $best_pct;
    }

    /**
     * Devuelve el monto (en moneda) que se ahorra por precio fijo de pareja,
     * para eventos configurados en modo 'fixed_price'. Solo aplica cuando el
     * carrito tiene EXACTAMENTE 2 boletas de ese evento y ambas son de la
     * misma zona. Retorna 0 si no aplica.
     */
    public static function get_fixed_price_amount_for_cart( WC_Cart $cart ): float {
        if ( ! $cart || $cart->is_empty() ) {
            return 0;
        }

        $by_event = array();
        foreach ( $cart->get_cart() as $item ) {
            $event_id = (int) ( $item['ss_event_id'] ?? 0 );
            if ( ! $event_id ) {
                continue;
            }
            $by_event[ $event_id ][] = $item;
        }

        $best_amount = 0;

        foreach ( $by_event as $event_id => $items ) {
            if ( get_post_meta( $event_id, '_ss_couple_discount_enabled', true ) !== '1' ) {
                continue;
            }
            if ( self::get_type( $event_id ) !== 'fixed_price' ) {
                continue;
            }
            $fixed_price = (float) get_post_meta( $event_id, '_ss_couple_discount_fixed_price', true );
            if ( $fixed_price <= 0 ) {
                continue;
            }

            $sale_mode  = get_post_meta( $event_id, '_ss_sale_mode', true ) ?: 'seat';
            $zone_qty   = array();
            $zone_raw   = array();
            $seat_zones = null;

            foreach ( $items as $item ) {
                if ( $sale_mode === 'seat' && ! empty( $item['ss_seats'] ) && is_array( $item['ss_seats'] ) ) {
                    if ( $seat_zones === null ) {
                        $seat_zones = self::get_seat_zone_map( $event_id );
                    }
                    $num_seats  = count( $item['ss_seats'] );
                    $unit_price = ( $num_seats > 0 && isset( $item['data'] ) && $item['data'] instanceof WC_Product )
                        ? ( (float) $item['data']->get_price() / $num_seats )
                        : 0;
                    foreach ( $item['ss_seats'] as $seat ) {
                        $zone = $seat_zones[ $seat ] ?? 'GENERAL';
                        $zone_qty[ $zone ] = ( $zone_qty[ $zone ] ?? 0 ) + 1;
                        $zone_raw[ $zone ] = ( $zone_raw[ $zone ] ?? 0 ) + $unit_price;
                    }
                } else {
                    $zone = strtoupper( trim( (string) ( $item['ss_zone'] ?? '' ) ) );
                    $qty  = isset( $item['ss_ticket_qty'] ) ? (int) $item['ss_ticket_qty'] : (int) $item['quantity'];
                    if ( $qty <= 0 ) {
                        continue;
                    }
                    $unit_price = ( isset( $item['data'] ) && $item['data'] instanceof WC_Product )
                        ? (float) $item['data']->get_price()
                        : 0;
                    $zone_qty[ $zone ] = ( $zone_qty[ $zone ] ?? 0 ) + $qty;
                    $zone_raw[ $zone ] = ( $zone_raw[ $zone ] ?? 0 ) + ( $unit_price * $qty );
                }
            }

            // Solo aplica con exactamente 2 boletas en total, de una única zona.
            $total_qty = array_sum( $zone_qty );
            if ( $total_qty !== self::MIN_QTY || count( $zone_qty ) !== 1 ) {
                continue;
            }

            $zone = array_key_first( $zone_qty );
            $diff = $zone_raw[ $zone ] - $fixed_price;
            if ( $diff > $best_amount ) {
                $best_amount = $diff;
            }
        }

        return max( 0, round( $best_amount, 2 ) );
    }

    /**
     * Devuelve info del descuento de pareja para un evento específico.
     * Usado por el Box Office para pre-rellenar la calculadora.
     */
    public static function get_for_event( int $event_id ): array {
        $defaults = array(
            'enabled'     => false,
            'min_qty'     => self::MIN_QTY,
            'type'        => 'percentage',
            'pct'         => 0,
            'fixed_price' => 0,
        );
        if ( get_post_meta( $event_id, '_ss_couple_discount_enabled', true ) !== '1' ) {
            return $defaults;
        }
        return array(
            'enabled'     => true,
            'min_qty'     => self::MIN_QTY,
            'type'        => self::get_type( $event_id ),
            'pct'         => (int) get_post_meta( $event_id, '_ss_couple_discount_pct', true ),
            'fixed_price' => (float) get_post_meta( $event_id, '_ss_couple_discount_fixed_price', true ),
        );
    }

    /**
     * Tipo de descuento configurado para el evento: 'percentage' o 'fixed_price'.
     */
    public static function get_type( int $event_id ): string {
        $type = get_post_meta( $event_id, '_ss_couple_discount_type', true );
        return $type === 'fixed_price' ? 'fixed_price' : 'percentage';
    }

    /**
     * Mapea seat_id => zone a partir del layout del evento (_ss_layout).
     * Duplicado intencional del mismo mapeo usado en ss_get_zone_inventory():
     * evita acoplar este módulo a funciones internas del archivo principal.
     */
    private static function get_seat_zone_map( int $event_id ): array {
        if ( ! class_exists( 'SS_Event_Service' ) ) {
            return array();
        }
        $layout_raw = SS_Event_Service::instance()->get_layout_raw( $event_id );
        if ( empty( $layout_raw ) ) {
            return array();
        }
        $layout = json_decode( $layout_raw, true );
        $rows   = function_exists( 'ss_layout_get_rows' ) ? ss_layout_get_rows( $layout ?: array() ) : array();

        $map = array();
        foreach ( $rows as $row ) {
            if ( isset( $row['type'] ) && $row['type'] === 'empty' ) {
                continue;
            }
            $zone  = isset( $row['zone'] ) ? trim( $row['zone'] ) : 'GENERAL';
            $label = isset( $row['label'] ) ? $row['label'] : '';
            $count = isset( $row['count'] ) ? (int) $row['count'] : 0;
            if ( $zone === '' ) {
                $zone = 'GENERAL';
            }
            $removed = array();
            if ( ! empty( $row['removedSeats'] ) && is_array( $row['removedSeats'] ) ) {
                foreach ( $row['removedSeats'] as $rs ) {
                    $removed[ (int) $rs ] = true;
                }
            }
            for ( $s = 1; $s <= $count; $s++ ) {
                if ( isset( $removed[ $s ] ) ) {
                    continue;
                }
                $map[ $label . $s ] = $zone;
            }
        }
        return $map;
    }
}
