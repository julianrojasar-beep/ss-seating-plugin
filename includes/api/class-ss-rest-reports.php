<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Endpoints REST de solo lectura para el dashboard externo (D:\ECM-Operaciones).
 * Namespace: ss-seating/v1. Auth: Application Passwords nativas de WP +
 * capability propia ss_view_reports (más angosta que manage_woocommerce:
 * solo lee reportes, no puede editar productos/pedidos).
 */
class SS_REST_Reports {

    const CAP          = 'ss_view_reports';
    const BOT_ROLE     = 'dashboard_reports_bot';
    const ROLE_VERSION = '1';

    public static function init(): void {
        add_action( 'init', array( __CLASS__, 'ensure_reports_role' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes(): void {
        register_rest_route( 'ss-seating/v1', '/reports/customers', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'get_customers_report' ),
            'permission_callback' => array( __CLASS__, 'check_permission' ),
        ) );

        register_rest_route( 'ss-seating/v1', '/reports/sales', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'get_sales_report' ),
            'permission_callback' => array( __CLASS__, 'check_permission' ),
            'args'                => array(
                'event_id' => array(
                    'required' => true,
                    'type'     => 'integer',
                ),
            ),
        ) );
    }

    /**
     * Crea la capability ss_view_reports y el rol dedicado dashboard_reports_bot
     * (para la Application Password del dashboard externo), y se la otorga también
     * a administrator/shop_manager para no romper el acceso que ya tienen vía
     * manage_woocommerce. Idempotente y versionado (mismo patrón que
     * ss_ensure_ledger_schema) para que sitios donde el plugin ya estaba activo
     * lo apliquen en la próxima carga, sin depender de un activation hook.
     */
    public static function ensure_reports_role(): void {
        if ( get_option( 'ss_reports_role_version' ) === self::ROLE_VERSION ) {
            return;
        }

        if ( ! get_role( self::BOT_ROLE ) ) {
            add_role( self::BOT_ROLE, 'Dashboard Reports Bot', array(
                'read'   => true,
                self::CAP => true,
            ) );
        } else {
            get_role( self::BOT_ROLE )->add_cap( self::CAP );
        }

        foreach ( array( 'administrator', 'shop_manager' ) as $existing_role ) {
            $role = get_role( $existing_role );
            if ( $role && ! $role->has_cap( self::CAP ) ) {
                $role->add_cap( self::CAP );
            }
        }

        update_option( 'ss_reports_role_version', self::ROLE_VERSION );
    }

    public static function check_permission(): bool {
        // Defensa adicional: nunca servir estos datos por HTTP plano, aunque el
        // servidor no fuerce el redirect a HTTPS (las Application Passwords viajan
        // como Basic Auth, sin cifrado propio).
        if ( ! is_ssl() ) {
            return false;
        }
        return current_user_can( self::CAP );
    }

    /**
     * Todos los order_id que tienen ss_event_id en item meta, con su event_id.
     * Mismo patrón de JOIN que ya usa Cierre Contable (order_items + order_itemmeta).
     */
    private static function get_order_event_map( ?int $event_id = null ): array {
        global $wpdb;

        $sql = "SELECT oi.order_id, oim.meta_value AS event_id
                FROM {$wpdb->prefix}woocommerce_order_items AS oi
                INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim
                    ON oi.order_item_id = oim.order_item_id
                WHERE oim.meta_key = 'ss_event_id'";

        if ( $event_id ) {
            $sql = $wpdb->prepare( $sql . ' AND oim.meta_value = %s', (string) $event_id );
        }

        $rows = $wpdb->get_results( $sql, ARRAY_A );

        $map = array();
        foreach ( $rows as $row ) {
            $oid = (int) $row['order_id'];
            if ( ! isset( $map[ $oid ] ) ) {
                $map[ $oid ] = (int) $row['event_id'];
            }
        }
        return $map;
    }

    /**
     * GET /reports/customers
     * Agregado por email cruzando pedidos Web + BO de todos los eventos.
     */
    public static function get_customers_report( \WP_REST_Request $request ): \WP_REST_Response {
        $order_event_map = self::get_order_event_map();

        $customers = array();

        foreach ( $order_event_map as $order_id => $event_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) { continue; }
            if ( ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) { continue; }

            $email = $order->get_billing_email();
            if ( ! $email ) { continue; }

            $is_bo = $order->get_meta( '_ss_boxoffice_sale' ) === 'yes';
            $valor = $is_bo ? (int) $order->get_meta( '_ss_valor_cobrado' ) : (float) $order->get_total();

            $zonas = array();
            foreach ( $order->get_items() as $item ) {
                $zona_item = $item->get_meta( 'ss_zone' );
                if ( $zona_item ) {
                    foreach ( explode( ',', $zona_item ) as $z ) {
                        $z = trim( $z );
                        if ( $z !== '' ) { $zonas[ $z ] = true; }
                    }
                }
                $ticket_qtys = $item->get_meta( 'ss_ticket_qtys' );
                if ( is_array( $ticket_qtys ) ) {
                    foreach ( array_keys( $ticket_qtys ) as $z ) {
                        $zonas[ $z ] = true;
                    }
                }
            }

            $fecha = $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : '';

            if ( ! isset( $customers[ $email ] ) ) {
                $customers[ $email ] = array(
                    'email'          => $email,
                    'nombre'         => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                    'total_gastado'  => 0,
                    'zonas'          => array(),
                    'compras'        => array(),
                    'primera_compra' => $fecha,
                    'ultima_compra'  => $fecha,
                );
            }

            $customers[ $email ]['total_gastado'] += $valor;
            $customers[ $email ]['zonas'] = array_values( array_unique( array_merge( $customers[ $email ]['zonas'], array_keys( $zonas ) ) ) );
            $customers[ $email ]['compras'][] = array(
                'event_id' => $event_id,
                'evento'   => get_the_title( $event_id ),
                'order_id' => $order_id,
                'canal'    => $is_bo ? 'bo' : 'web',
                'valor'    => $valor,
                'fecha'    => $fecha,
            );

            if ( $fecha && $fecha < $customers[ $email ]['primera_compra'] ) {
                $customers[ $email ]['primera_compra'] = $fecha;
            }
            if ( $fecha && $fecha > $customers[ $email ]['ultima_compra'] ) {
                $customers[ $email ]['ultima_compra'] = $fecha;
            }
        }

        return new \WP_REST_Response( array_values( $customers ), 200 );
    }

    /**
     * GET /reports/sales?event_id=
     * Ocupación, desglose Web/BO/canal, ingresos y timestamp por transacción de un evento.
     */
    public static function get_sales_report( \WP_REST_Request $request ): \WP_REST_Response {
        $event_id = (int) $request->get_param( 'event_id' );

        if ( ! $event_id || get_post_type( $event_id ) !== 'ss_event' ) {
            return new \WP_REST_Response( array( 'error' => 'event_id inválido' ), 400 );
        }

        // Ocupación en modo asiento (sillas): fuente canónica del ledger, igual que el resto del plugin.
        $ticket_types = SS_Event_Service::instance()->get_ticket_types( $event_id );
        $sold_seats   = ss_seats_read( $event_id );
        $zone_map     = ss_seats_zone_map( $event_id );

        $vendidas_por_zona = array();
        foreach ( $sold_seats as $seat ) {
            $zona = $zone_map[ $seat ] ?? 'GENERAL';
            $vendidas_por_zona[ $zona ] = ( $vendidas_por_zona[ $zona ] ?? 0 ) + 1;
        }

        $order_event_map = self::get_order_event_map( $event_id );

        $transacciones   = array();
        $ingresos_web    = 0.0;
        $ingresos_bo     = 0.0;

        foreach ( $order_event_map as $order_id => $mapped_event_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) { continue; }
            if ( ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) { continue; }

            $is_bo = $order->get_meta( '_ss_boxoffice_sale' ) === 'yes';
            $valor = $is_bo ? (int) $order->get_meta( '_ss_valor_cobrado' ) : (float) $order->get_total();

            if ( $is_bo ) {
                $origen = (string) $order->get_meta( '_ss_bo_sale_origin' );
            } else {
                $origen = (string) $order->get_meta( '_ss_utm_source' );
            }
            $utm_campaign = $is_bo ? '' : (string) $order->get_meta( '_ss_utm_campaign' );

            $zonas_orden = array();
            foreach ( $order->get_items() as $item ) {
                $ticket_qtys = $item->get_meta( 'ss_ticket_qtys' );
                if ( is_array( $ticket_qtys ) ) {
                    foreach ( $ticket_qtys as $z => $qty ) {
                        $vendidas_por_zona[ $z ] = ( $vendidas_por_zona[ $z ] ?? 0 ) + (int) $qty;
                        $zonas_orden[] = $z;
                    }
                }
                $zona_item = $item->get_meta( 'ss_zone' );
                if ( $zona_item ) {
                    $zonas_orden[] = $zona_item;
                }
                // Modo asiento: la zona vive por silla en ss_seat_data, no en ss_zone/ss_ticket_qtys.
                $seat_data = $item->get_meta( 'ss_seat_data' );
                if ( is_array( $seat_data ) ) {
                    foreach ( $seat_data as $sd ) {
                        if ( ! empty( $sd['zone'] ) ) {
                            $zonas_orden[] = $sd['zone'];
                        }
                    }
                }
            }

            // Sin zona/asiento en el ítem (compra directa de producto, sin selección de
            // zona) y el evento tiene un único tipo de ticket: no hay ambigüedad posible.
            if ( empty( $zonas_orden ) && count( $ticket_types ) === 1 && ! empty( $ticket_types[0]['zone'] ) ) {
                $zonas_orden[] = $ticket_types[0]['zone'];
            }

            if ( $is_bo ) {
                $ingresos_bo += $valor;
            } else {
                $ingresos_web += $valor;
            }

            $transacciones[] = array(
                'order_id'     => $order_id,
                'canal'        => $is_bo ? 'bo' : 'web',
                'origen'       => $origen,
                'utm_campaign' => $utm_campaign,
                'zonas'        => array_values( array_unique( array_filter( $zonas_orden ) ) ),
                'valor'        => $valor,
                'fecha'        => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : '',
            );
        }

        $ocupacion = array();
        foreach ( $ticket_types as $tt ) {
            $zona = $tt['zone'] ?? 'GENERAL';
            $ocupacion[] = array(
                'zona'      => $zona,
                'capacidad' => (int) ( $tt['capacity'] ?? 0 ),
                'vendidas'  => (int) ( $vendidas_por_zona[ $zona ] ?? 0 ),
            );
        }

        return new \WP_REST_Response( array(
            'event_id'      => $event_id,
            'evento'        => get_the_title( $event_id ),
            'ocupacion'     => $ocupacion,
            'ingresos'      => array(
                'web'   => $ingresos_web,
                'bo'    => $ingresos_bo,
                'total' => $ingresos_web + $ingresos_bo,
            ),
            'transacciones' => $transacciones,
        ), 200 );
    }
}
