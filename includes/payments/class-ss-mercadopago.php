<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SS_Mercadopago — Datos financieros reales (comisión, retenciones, neto) de
 * pagos procesados por el gateway "woo-mercado-pago-custom", consultados vía
 * la API de Mercado Pago y cacheados en meta del pedido.
 *
 * Fuente del payment ID: meta de pedido `_Mercado_Pago_Payment_IDs` (la guarda
 * el propio gateway). Fuente del Access Token: option `_mp_access_token_prod`
 * (config global del plugin oficial de Mercado Pago, confirmada en sesión —
 * no vive en el array de settings del gateway de WooCommerce).
 *
 * Cache: una vez resuelto un pedido (éxito o error), no se vuelve a golpear la
 * API en cada request al endpoint de reportes — ver `_ss_mp_fetch_status` /
 * `_ss_mp_fetched_at`. Un error solo se reintenta después de ERROR_RETRY_SECONDS.
 */
class SS_Mercadopago {

    const ERROR_RETRY_SECONDS = DAY_IN_SECONDS;

    /**
     * { comision_pago, retencion_ica, retencion_fuente, neto_recibido }
     * Todos null si el pedido no tiene payment ID de Mercado Pago, o si la
     * consulta a la API falló (nunca lanza error hacia afuera).
     */
    public static function get_order_financials( \WC_Order $order ): array {
        $empty = array(
            'comision_pago'    => null,
            'retencion_ica'    => null,
            'retencion_fuente' => null,
            'neto_recibido'    => null,
        );

        $payment_ids = self::extract_payment_ids( $order );
        if ( empty( $payment_ids ) ) {
            return $empty;
        }

        $status = $order->get_meta( '_ss_mp_fetch_status' );

        if ( 'ok' === $status ) {
            return array(
                'comision_pago'    => self::meta_float( $order, '_ss_mp_fee' ),
                'retencion_ica'    => self::meta_float( $order, '_ss_mp_ica' ),
                'retencion_fuente' => self::meta_float( $order, '_ss_mp_fuente' ),
                'neto_recibido'    => self::meta_float( $order, '_ss_mp_net_received' ),
            );
        }

        // 'unresolved': ya se determinó antes que ningún ID de la lista es el
        // pago aprobado (ambigüedad de datos, no un fallo transitorio) — no se
        // reintenta nunca, a diferencia de 'error'.
        if ( 'unresolved' === $status ) {
            return $empty;
        }

        if ( 'error' === $status ) {
            $fetched_at = (int) $order->get_meta( '_ss_mp_fetched_at' );
            if ( $fetched_at && ( time() - $fetched_at ) < self::ERROR_RETRY_SECONDS ) {
                return $empty; // Todavía en cooldown, no reintentar.
            }
        }

        $resolved = self::resolve_approved_payment( $payment_ids );

        if ( 'unresolved' === $resolved['state'] ) {
            self::save_unresolved( $order );
            if ( defined( 'SS_SEATING_DEBUG' ) && SS_SEATING_DEBUG ) {
                error_log( '[ss-seating][mercadopago] No se pudo determinar cuál de los payment IDs (' . implode( ',', $payment_ids ) . ') del pedido ' . $order->get_id() . ' está aprobado — ninguno con status=approved.' );
            }
            return $empty;
        }

        if ( 'error' === $resolved['state'] ) {
            self::save_error( $order );
            if ( defined( 'SS_SEATING_DEBUG' ) && SS_SEATING_DEBUG ) {
                error_log( '[ss-seating][mercadopago] Error consultando payment IDs (' . implode( ',', $payment_ids ) . ') del pedido ' . $order->get_id() . ': ' . $resolved['error'] );
            }
            return $empty;
        }

        $parsed = self::parse_payment( $resolved['payment'] );
        if ( null === $parsed ) {
            self::save_error( $order );
            if ( defined( 'SS_SEATING_DEBUG' ) && SS_SEATING_DEBUG ) {
                error_log( '[ss-seating][mercadopago] Respuesta incompleta de Mercado Pago para payment ' . $resolved['payment_id'] . ' (pedido ' . $order->get_id() . ')' );
            }
            return $empty;
        }

        self::save_ok( $order, $parsed );

        return array(
            'comision_pago'    => $parsed['fee'],
            'retencion_ica'    => $parsed['ica'],
            'retencion_fuente' => $parsed['fuente'],
            'neto_recibido'    => $parsed['net_received'],
        );
    }

    private static function get_access_token(): string {
        return (string) get_option( '_mp_access_token_prod', '' );
    }

    /**
     * `_Mercado_Pago_Payment_IDs` puede traer varios IDs separados por coma si
     * hubo más de un intento de pago (ej. un rechazo seguido de un pago
     * aprobado). Devuelve la lista completa, sin asumir cuál es el correcto.
     *
     * @return int[]
     */
    private static function extract_payment_ids( \WC_Order $order ): array {
        $raw = (string) $order->get_meta( '_Mercado_Pago_Payment_IDs' );
        if ( '' === trim( $raw ) ) {
            return array();
        }
        $parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
        return array_values( array_map( 'intval', $parts ) );
    }

    /**
     * Con un solo ID, se usa directamente. Con varios, se consulta cada uno y
     * se usa el que tenga `status === 'approved'` — es el único criterio
     * confiable para saber cuál de varios intentos de pago es el real. Si
     * ninguno de los que se pudo consultar está aprobado, se marca como
     * 'unresolved' (no se adivina) en vez de usar uno arbitrario.
     *
     * @return array{state:string, payment?:array, payment_id?:int, error?:string}
     */
    private static function resolve_approved_payment( array $payment_ids ): array {
        if ( 1 === count( $payment_ids ) ) {
            $payment = self::fetch_payment( $payment_ids[0] );
            if ( is_wp_error( $payment ) ) {
                return array( 'state' => 'error', 'error' => $payment->get_error_message() );
            }
            return array( 'state' => 'ok', 'payment' => $payment, 'payment_id' => $payment_ids[0] );
        }

        $last_error   = '';
        $any_success  = false;
        foreach ( $payment_ids as $id ) {
            $payment = self::fetch_payment( $id );
            if ( is_wp_error( $payment ) ) {
                $last_error = $payment->get_error_message();
                continue;
            }
            $any_success = true;
            if ( isset( $payment['status'] ) && 'approved' === $payment['status'] ) {
                return array( 'state' => 'ok', 'payment' => $payment, 'payment_id' => $id );
            }
        }

        // Se pudo consultar al menos un ID sin error, pero ninguno estaba
        // aprobado: es ambigüedad de datos, no un fallo transitorio de red/API.
        if ( $any_success ) {
            return array( 'state' => 'unresolved' );
        }

        // Todos los IDs fallaron por error de red/API — sí es transitorio, se
        // reintenta más adelante (a diferencia de 'unresolved').
        return array( 'state' => 'error', 'error' => $last_error ?: 'Ningún payment ID pudo consultarse.' );
    }

    /**
     * @return array|\WP_Error Respuesta JSON decodificada de Mercado Pago, o WP_Error.
     */
    private static function fetch_payment( int $payment_id ) {
        $token = self::get_access_token();
        if ( '' === $token ) {
            return new \WP_Error( 'ss_mp_no_token', 'Access Token de Mercado Pago no configurado (_mp_access_token_prod vacío).' );
        }

        $response = wp_remote_get( 'https://api.mercadopago.com/v1/payments/' . $payment_id, array(
            'timeout' => 8,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return new \WP_Error( 'ss_mp_http_' . $code, 'Mercado Pago respondió HTTP ' . $code . ' para payment ' . $payment_id );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return new \WP_Error( 'ss_mp_bad_json', 'Respuesta de Mercado Pago no es JSON válido para payment ' . $payment_id );
        }

        return $body;
    }

    /**
     * Comisión desde fee_details[].type === 'mercadopago_fee'. Retenciones
     * (ICA / fuente) desde charges_details, con match específico confirmado
     * contra una respuesta real de Mercado Pago (payment 170728170326):
     *   - ICA:    type === 'tax' y name empieza con 'tax_withholding-ica'
     *             (el sufijo con la ciudad/región varía, ej. "_valledelcauca_cali").
     *   - Fuente: type === 'tax' y name empieza con 'tax_withholding-fuente'.
     * Cualquier otro cargo en charges_details (incluida la propia comisión de
     * MP si aparece duplicada ahí) se ignora — la comisión sale solo de
     * fee_details para no contarla dos veces. Neto real desde
     * transaction_details.net_received_amount (nunca calculado a mano).
     *
     * @return array|null null si la respuesta no trae ni fee ni neto (se
     *                     considera incompleta/no útil).
     */
    private static function parse_payment( array $payment ): ?array {
        $fee = null;
        if ( ! empty( $payment['fee_details'] ) && is_array( $payment['fee_details'] ) ) {
            foreach ( $payment['fee_details'] as $fd ) {
                if ( isset( $fd['type'], $fd['amount'] ) && 'mercadopago_fee' === $fd['type'] ) {
                    $fee = (float) $fd['amount'];
                    break;
                }
            }
        }

        $ica          = 0.0;
        $fuente       = 0.0;
        $found_ica    = false;
        $found_fuente = false;
        if ( ! empty( $payment['charges_details'] ) && is_array( $payment['charges_details'] ) ) {
            foreach ( $payment['charges_details'] as $cd ) {
                $type = strtolower( (string) ( $cd['type'] ?? '' ) );
                if ( 'tax' !== $type ) {
                    continue; // Descarta 'fee' y cualquier otro tipo que no sea una retención de impuesto.
                }
                $name   = strtolower( (string) ( $cd['name'] ?? '' ) );
                $amount = isset( $cd['amounts']['original'] ) ? (float) $cd['amounts']['original'] : ( isset( $cd['amount'] ) ? (float) $cd['amount'] : 0.0 );

                if ( 0 === strpos( $name, 'tax_withholding-ica' ) ) {
                    $ica      += $amount;
                    $found_ica = true;
                } elseif ( 0 === strpos( $name, 'tax_withholding-fuente' ) ) {
                    $fuente      += $amount;
                    $found_fuente = true;
                }
            }
        }

        $net_received = isset( $payment['transaction_details']['net_received_amount'] )
            ? (float) $payment['transaction_details']['net_received_amount']
            : null;

        if ( null === $fee && null === $net_received ) {
            return null;
        }

        return array(
            'fee'          => $fee,
            'ica'          => $found_ica ? round( $ica, 2 ) : null,
            'fuente'       => $found_fuente ? round( $fuente, 2 ) : null,
            'net_received' => $net_received,
        );
    }

    private static function save_ok( \WC_Order $order, array $parsed ): void {
        $order->update_meta_data( '_ss_mp_fee', $parsed['fee'] );
        $order->update_meta_data( '_ss_mp_ica', $parsed['ica'] );
        $order->update_meta_data( '_ss_mp_fuente', $parsed['fuente'] );
        $order->update_meta_data( '_ss_mp_net_received', $parsed['net_received'] );
        $order->update_meta_data( '_ss_mp_fetch_status', 'ok' );
        $order->update_meta_data( '_ss_mp_fetched_at', time() );
        $order->save_meta_data();
    }

    private static function save_error( \WC_Order $order ): void {
        $order->update_meta_data( '_ss_mp_fetch_status', 'error' );
        $order->update_meta_data( '_ss_mp_fetched_at', time() );
        $order->save_meta_data();
    }

    private static function save_unresolved( \WC_Order $order ): void {
        $order->update_meta_data( '_ss_mp_fetch_status', 'unresolved' );
        $order->update_meta_data( '_ss_mp_fetched_at', time() );
        $order->save_meta_data();
    }

    private static function meta_float( \WC_Order $order, string $key ) {
        $val = $order->get_meta( $key );
        return ( '' === $val || null === $val ) ? null : (float) $val;
    }
}
