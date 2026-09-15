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
    const ROLE_VERSION = '2';

    // Rate limit por usuario autenticado (Application Password), para que una
    // credencial filtrada no pueda hacer scraping ilimitado de /reports/*.
    const RATE_LIMIT_MAX    = 60;   // requests
    const RATE_LIMIT_WINDOW = 300;  // segundos (5 min)

    public static function init(): void {
        add_action( 'init', array( __CLASS__, 'ensure_reports_role' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes(): void {
        register_rest_route( 'ss-seating/v1', '/reports/customers', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'get_customers_report' ),
            'permission_callback' => array( __CLASS__, 'check_permission' ),
            'args'                => array(
                // Opcionales: sin params, comportamiento idéntico a antes (dataset completo).
                // Permiten acotar el volumen exportado por request (mitiga impacto si la
                // Application Password se filtra).
                'desde' => array(
                    'required' => false,
                    'type'     => 'string',
                ),
                'hasta' => array(
                    'required' => false,
                    'type'     => 'string',
                ),
            ),
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
            // Solo la capability de reportes — sin 'read', que es de más para un
            // bot que exclusivamente consume estos 2 endpoints REST.
            add_role( self::BOT_ROLE, 'Dashboard Reports Bot', array(
                self::CAP => true,
            ) );
        } else {
            $role = get_role( self::BOT_ROLE );
            $role->add_cap( self::CAP );
            // Mínimo privilegio: sitios donde el rol ya existía de una versión
            // previa (v1) tenían 'read' de más; se retira al re-sincronizar.
            if ( $role->has_cap( 'read' ) ) {
                $role->remove_cap( 'read' );
            }
        }

        foreach ( array( 'administrator', 'shop_manager' ) as $existing_role ) {
            $role = get_role( $existing_role );
            if ( $role && ! $role->has_cap( self::CAP ) ) {
                $role->add_cap( self::CAP );
            }
        }

        update_option( 'ss_reports_role_version', self::ROLE_VERSION );
    }

    public static function check_permission() {
        // Defensa adicional: nunca servir estos datos por HTTP plano, aunque el
        // servidor no fuerce el redirect a HTTPS (las Application Passwords viajan
        // como Basic Auth, sin cifrado propio).
        if ( ! is_ssl() ) {
            return false;
        }
        if ( ! current_user_can( self::CAP ) ) {
            return false;
        }
        if ( ! self::check_rate_limit() ) {
            return new \WP_Error(
                'ss_reports_rate_limited',
                'Demasiadas solicitudes a los reportes. Intenta de nuevo en unos minutos.',
                array( 'status' => 429 )
            );
        }
        return true;
    }

    /**
     * Límite simple por usuario autenticado, ventana fija de RATE_LIMIT_WINDOW
     * segundos. No frena al dashboard legítimo (refrescos normales quedan muy
     * por debajo del límite) pero acota el daño si la Application Password se
     * filtra y alguien intenta iterar event_id o volcar /reports/customers
     * repetidamente en loop.
     *
     * Ventana fija real (no sliding window): el transient que marca el inicio
     * de la ventana se crea una sola vez y nunca se vuelve a tocar dentro de
     * ella — set_transient() reinicia el TTL cada vez que se llama, así que si
     * lo actualizáramos en cada request el conteo nunca expiraría con tráfico
     * constante y terminaría bloqueando al dashboard para siempre.
     */
    private static function check_rate_limit(): bool {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return true; // No debería ocurrir tras current_user_can(), pero no bloquear por error propio.
        }
        $window_key = 'ss_reports_rl_window_' . $user_id;
        $count_key  = 'ss_reports_rl_count_' . $user_id;

        if ( false === get_transient( $window_key ) ) {
            set_transient( $window_key, 1, self::RATE_LIMIT_WINDOW );
            update_option( $count_key, 1, false );
            return true;
        }

        $count = (int) get_option( $count_key, 0 ) + 1;
        update_option( $count_key, $count, false );
        return $count <= self::RATE_LIMIT_MAX;
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

        // Filtro de fecha opcional (retrocompatible: sin params = dataset completo, igual que antes).
        $desde = (string) $request->get_param( 'desde' );
        $hasta = (string) $request->get_param( 'hasta' );
        $desde_ts = $desde !== '' ? strtotime( $desde . ' 00:00:00' ) : false;
        $hasta_ts = $hasta !== '' ? strtotime( $hasta . ' 23:59:59' ) : false;

        $customers = array();

        foreach ( $order_event_map as $order_id => $event_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) { continue; }
            if ( ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) { continue; }

            $fecha_orden_obj = $order->get_date_created();
            if ( $desde_ts && ( ! $fecha_orden_obj || $fecha_orden_obj->getTimestamp() < $desde_ts ) ) { continue; }
            if ( $hasta_ts && ( ! $fecha_orden_obj || $fecha_orden_obj->getTimestamp() > $hasta_ts ) ) { continue; }

            $email = $order->get_billing_email();
            if ( ! $email ) { continue; }

            $is_bo = $order->get_meta( '_ss_boxoffice_sale' ) === 'yes';
            $valor = $is_bo ? (int) $order->get_meta( '_ss_valor_cobrado' ) : (float) $order->get_total();

            $zonas   = array();
            $boletas = 0;
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
                $boletas += self::count_item_boletas( $item );
            }

            $fecha = $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : '';

            if ( ! isset( $customers[ $email ] ) ) {
                $customers[ $email ] = array(
                    'email'           => $email,
                    'nombre'          => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                    'total_gastado'   => 0,
                    'total_boletas'   => 0,
                    'zonas'           => array(),
                    'compras'         => array(),
                    'primera_compra'  => $fecha,
                    'ultima_compra'   => $fecha,
                );
            }

            $customers[ $email ]['total_gastado'] += $valor;
            $customers[ $email ]['total_boletas'] += $boletas;
            $customers[ $email ]['zonas'] = array_values( array_unique( array_merge( $customers[ $email ]['zonas'], array_keys( $zonas ) ) ) );
            $customers[ $email ]['compras'][] = array(
                'event_id' => $event_id,
                'evento'   => get_the_title( $event_id ),
                'order_id' => $order_id,
                'canal'    => $is_bo ? 'bo' : 'web',
                'boletas'  => $boletas,
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
     * Cantidad real de boletas de un ítem de pedido, con prioridad de fuentes:
     * Box Office (ss_ticket_qtys, varias zonas por ítem) → modo asiento
     * (ss_seat_data/ss_seats, una silla por boleta) → modo zona Web
     * (ss_ticket_qty explícito) → quantity nativo de WooCommerce como último
     * recurso. Necesario porque el carrito de modo asiento siempre agrega el
     * producto con quantity=1 sin importar cuántas sillas se compren
     * (ss_ajax_add_to_cart fuerza qty=1), así que el quantity nativo de WC
     * subestima las boletas reales en ese modo.
     */
    public static function count_item_boletas( \WC_Order_Item $item ): int {
        $ticket_qtys = $item->get_meta( 'ss_ticket_qtys' );
        if ( is_array( $ticket_qtys ) && ! empty( $ticket_qtys ) ) {
            return (int) array_sum( $ticket_qtys );
        }
        $seat_data = $item->get_meta( 'ss_seat_data' );
        if ( is_array( $seat_data ) && ! empty( $seat_data ) ) {
            return count( $seat_data );
        }
        $seats_meta = $item->get_meta( 'ss_seats' );
        if ( is_array( $seats_meta ) && ! empty( $seats_meta ) ) {
            return count( $seats_meta );
        }
        $ticket_qty_single = $item->get_meta( 'ss_ticket_qty' );
        if ( $ticket_qty_single !== '' ) {
            return (int) $ticket_qty_single;
        }
        return (int) $item->get_quantity();
    }

    /**
     * Resuelve el origen/atribución de un pedido, priorizando la "Atribución de
     * pedido" nativa de WooCommerce (WC 8.5+, sourcebuster.js) sobre la captura
     * propia del plugin (_ss_utm_source/medium/campaign), que queda como respaldo
     * cuando WC no tiene el dato. Ver CLAUDE.md, "Atribución de Meta Ads".
     * Reutilizado por el reporte REST y por el Cierre Contable.
     *
     * @return array { origen, utm_medium, utm_campaign, fbclid, device_type, referrer }
     */
    public static function get_order_attribution( \WC_Order $order, bool $is_bo ): array {
        if ( $is_bo ) {
            $origen = (string) $order->get_meta( '_ss_bo_sale_origin' );
            // "Otro" es un valor de catálogo interno; para reportes se muestra el
            // detalle libre que escribió el cajero en vez del literal "otro".
            if ( $origen === 'otro' ) {
                $detalle = (string) $order->get_meta( '_ss_bo_sale_origin_detail' );
                if ( $detalle !== '' ) {
                    $origen = $detalle;
                }
            }
            return array(
                'origen'       => $origen,
                'utm_medium'   => '',
                'utm_campaign' => '',
                'fbclid'       => '',
                'device_type'  => '',
                'referrer'     => '',
            );
        }

        $origen = (string) $order->get_meta( '_wc_order_attribution_utm_source' );
        if ( '' === $origen ) {
            $origen = (string) $order->get_meta( '_ss_utm_source' );
        }
        $utm_medium = (string) $order->get_meta( '_wc_order_attribution_utm_medium' );
        if ( '' === $utm_medium ) {
            $utm_medium = (string) $order->get_meta( '_ss_utm_medium' );
        }
        $utm_campaign = (string) $order->get_meta( '_wc_order_attribution_utm_campaign' );
        if ( '' === $utm_campaign ) {
            $utm_campaign = (string) $order->get_meta( '_ss_utm_campaign' );
        }

        return array(
            'origen'       => $origen,
            'utm_medium'   => $utm_medium,
            'utm_campaign' => $utm_campaign,
            'fbclid'       => (string) $order->get_meta( '_ss_fbclid' ),
            // Campos nativos de WC Order Attribution, no leídos hasta ahora en el plugin.
            'utm_content'  => (string) $order->get_meta( '_wc_order_attribution_utm_content' ),
            'utm_term'     => (string) $order->get_meta( '_wc_order_attribution_utm_term' ),
            'device_type'  => (string) $order->get_meta( '_wc_order_attribution_device_type' ),
            'referrer'     => (string) $order->get_meta( '_wc_order_attribution_referrer' ),
        );
    }

    /**
     * Descuento aplicado al pedido, comparando el subtotal (antes de fees) contra
     * el total pagado. Solo aplica a Web: los descuentos de grupo/pareja/fidelización
     * se aplican como fee negativo (`ss_apply_event_discounts_to_cart()`), así que
     * `get_fees()` trae el nombre exacto usado en el carrito (ej. "Descuento grupal (20%)").
     * Box Office no usa este mecanismo — el cajero anota un valor manual único
     * (`_ss_valor_cobrado`) que puede ya incluir cortesía/descuento no estructurado,
     * así que ahí se reporta sin descuento (bruto = pagado).
     */
    private static function get_order_discount_info( \WC_Order $order, bool $is_bo, float $valor_pagado ): array {
        if ( $is_bo ) {
            return array(
                'tuvo_descuento'  => false,
                'tipo_descuento'  => '',
                'monto_descuento' => 0.0,
                'precio_bruto'    => $valor_pagado,
                'total_pagado'    => $valor_pagado,
            );
        }

        $monto_descuento = 0.0;
        $tipos           = array();
        foreach ( $order->get_fees() as $fee ) {
            $amount = (float) $fee->get_total();
            if ( $amount < 0 ) {
                $monto_descuento += abs( $amount );
                $tipos[] = $fee->get_name();
            }
        }

        return array(
            'tuvo_descuento'  => $monto_descuento > 0,
            'tipo_descuento'  => implode( ', ', $tipos ),
            'monto_descuento' => round( $monto_descuento, 2 ),
            'precio_bruto'    => (float) $order->get_subtotal(),
            'total_pagado'    => $valor_pagado,
        );
    }

    /**
     * Mapa ZONA_NORMALIZADA => precio de catálogo actual, para estimar precio
     * unitario cuando un solo order item mezcla varias zonas (Box Office con
     * ss_ticket_qtys multi-zona, o modo asiento sin zona por ticket-type). Es un
     * precio de referencia del catálogo vigente, no necesariamente el precio
     * histórico exacto cobrado si cambió desde la venta.
     */
    private static function build_zone_price_map( array $ticket_types, bool $is_presale ): array {
        $map = array();
        foreach ( $ticket_types as $tt ) {
            $zone = strtoupper( trim( (string) ( $tt['zone'] ?? '' ) ) );
            if ( '' === $zone ) { continue; }
            $normal_price  = (float) ( $tt['price'] ?? 0 );
            $presale_price = isset( $tt['presale_price'] ) ? (float) $tt['presale_price'] : 0;
            $map[ $zone ] = ( $is_presale && $presale_price > 0 ) ? $presale_price : $normal_price;
        }
        return $map;
    }

    /**
     * Desglose de boletas por zona/tipo de ticket para UN order item, con
     * precio unitario exacto cuando es derivable de los datos reales del pedido,
     * o estimado desde el catálogo vigente cuando el item mezcla varias zonas.
     * Cada fila: { zona, cantidad, precio_unitario, subtotal, estimado }.
     */
    private static function get_item_ticket_breakdown( \WC_Order_Item $item, array $zone_price_map ): array {
        $rows = array();

        // Box Office: un item puede traer varias zonas con su cantidad exacta,
        // pero sin precio real por zona (valor_cobrado es un total manual único).
        $ticket_qtys = $item->get_meta( 'ss_ticket_qtys' );
        if ( is_array( $ticket_qtys ) && ! empty( $ticket_qtys ) ) {
            foreach ( $ticket_qtys as $zona => $qty ) {
                $qty   = (int) $qty;
                $zona_n = strtoupper( trim( (string) $zona ) );
                $precio = $zone_price_map[ $zona_n ] ?? 0.0;
                $rows[] = array(
                    'zona'            => (string) $zona,
                    'cantidad'        => $qty,
                    'precio_unitario' => $precio,
                    'subtotal'        => round( $precio * $qty, 2 ),
                    'estimado'        => true,
                );
            }
            return $rows;
        }

        // Modo asiento: sillas con zona por silla. Precio exacto (subtotal real
        // del item / total de sillas) solo si todas pertenecen a la misma zona;
        // si el item mezcla zonas, se estima con el catálogo por zona.
        $seat_data = $item->get_meta( 'ss_seat_data' );
        if ( is_array( $seat_data ) && ! empty( $seat_data ) ) {
            $por_zona = array();
            foreach ( $seat_data as $sd ) {
                $zona = ! empty( $sd['zone'] ) ? (string) $sd['zone'] : 'GENERAL';
                $por_zona[ $zona ] = ( $por_zona[ $zona ] ?? 0 ) + 1;
            }
            $total_sillas = array_sum( $por_zona );
            $mismo_precio = count( $por_zona ) === 1 && $total_sillas > 0;
            $precio_real  = $mismo_precio ? ( (float) $item->get_subtotal() / $total_sillas ) : 0.0;

            foreach ( $por_zona as $zona => $qty ) {
                if ( $mismo_precio ) {
                    $rows[] = array(
                        'zona'            => $zona,
                        'cantidad'        => $qty,
                        'precio_unitario' => round( $precio_real, 2 ),
                        'subtotal'        => round( $precio_real * $qty, 2 ),
                        'estimado'        => false,
                    );
                } else {
                    $zona_n = strtoupper( trim( $zona ) );
                    $precio = $zone_price_map[ $zona_n ] ?? 0.0;
                    $rows[] = array(
                        'zona'            => $zona,
                        'cantidad'        => $qty,
                        'precio_unitario' => $precio,
                        'subtotal'        => round( $precio * $qty, 2 ),
                        'estimado'        => true,
                    );
                }
            }
            return $rows;
        }

        // Modo zona/general/hybrid Web: un item = una sola zona, cantidad real en
        // ss_ticket_qty/get_quantity(), precio exacto = subtotal real del item / cantidad.
        $zona_item = $item->get_meta( 'ss_zone' );
        $cantidad  = self::count_item_boletas( $item );
        if ( $cantidad <= 0 ) {
            return $rows;
        }
        $precio_real = (float) $item->get_subtotal() / $cantidad;
        $rows[] = array(
            'zona'            => $zona_item ? (string) $zona_item : 'GENERAL',
            'cantidad'        => $cantidad,
            'precio_unitario' => round( $precio_real, 2 ),
            'subtotal'        => round( (float) $item->get_subtotal(), 2 ),
            'estimado'        => false,
        );
        return $rows;
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
        $is_presale_now  = SS_Event_Service::instance()->is_presale_active( $event_id );
        $zone_price_map  = self::build_zone_price_map( $ticket_types, $is_presale_now );

        $transacciones   = array();
        $ingresos_web    = 0.0;
        $ingresos_bo     = 0.0;
        $serie_diaria    = array();

        foreach ( $order_event_map as $order_id => $mapped_event_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) { continue; }
            if ( ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) { continue; }

            $is_bo = $order->get_meta( '_ss_boxoffice_sale' ) === 'yes';
            $valor = $is_bo ? (int) $order->get_meta( '_ss_valor_cobrado' ) : (float) $order->get_total();

            $attribution  = self::get_order_attribution( $order, $is_bo );
            $origen       = $attribution['origen'];
            $utm_medium   = $attribution['utm_medium'];
            $utm_campaign = $attribution['utm_campaign'];
            $utm_content  = $attribution['utm_content'];
            $utm_term     = $attribution['utm_term'];
            $fbclid       = $attribution['fbclid'];
            $device_type  = $attribution['device_type'];
            $referrer     = $attribution['referrer'];
            $descuento    = self::get_order_discount_info( $order, $is_bo, $valor );

            $zonas_orden = array();
            $boletas_orden = 0;
            $desglose_zonas = array(); // zona => { cantidad, subtotal, estimado }
            foreach ( $order->get_items() as $item ) {
                $ticket_qtys = $item->get_meta( 'ss_ticket_qtys' );
                if ( is_array( $ticket_qtys ) && ! empty( $ticket_qtys ) ) {
                    foreach ( $ticket_qtys as $z => $qty ) {
                        $vendidas_por_zona[ $z ] = ( $vendidas_por_zona[ $z ] ?? 0 ) + (int) $qty;
                        $zonas_orden[] = $z;
                    }
                }
                $boletas_orden += self::count_item_boletas( $item );
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

                foreach ( self::get_item_ticket_breakdown( $item, $zone_price_map ) as $row ) {
                    $z = $row['zona'];
                    if ( ! isset( $desglose_zonas[ $z ] ) ) {
                        $desglose_zonas[ $z ] = array( 'cantidad' => 0, 'subtotal' => 0.0, 'estimado' => false );
                    }
                    $desglose_zonas[ $z ]['cantidad'] += $row['cantidad'];
                    $desglose_zonas[ $z ]['subtotal'] += $row['subtotal'];
                    $desglose_zonas[ $z ]['estimado']  = $desglose_zonas[ $z ]['estimado'] || $row['estimado'];
                }
            }

            $desglose = array();
            foreach ( $desglose_zonas as $z => $d ) {
                $desglose[] = array(
                    'zona'            => $z,
                    'cantidad'        => $d['cantidad'],
                    'precio_unitario' => $d['cantidad'] > 0 ? round( $d['subtotal'] / $d['cantidad'], 2 ) : 0.0,
                    'subtotal'        => round( $d['subtotal'], 2 ),
                    'estimado'        => $d['estimado'],
                );
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

            $cliente = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
            if ( '' === $cliente ) {
                $cliente = $order->get_billing_email();
            }

            $fecha_creacion = $order->get_date_created();

            $transacciones[] = array(
                'order_id'      => $order_id,
                'canal'         => $is_bo ? 'bo' : 'web',
                'cliente'       => $cliente,
                'medio_pago'    => $order->get_payment_method_title(),
                'origen'        => $origen,
                'utm_medium'    => $utm_medium,
                'utm_campaign'  => $utm_campaign,
                'fbclid'        => $fbclid,
                'device_type'   => $device_type,
                'referrer'      => $referrer,
                'boletas'       => $boletas_orden,
                'zonas'         => array_values( array_unique( array_filter( $zonas_orden ) ) ),
                'valor'         => $valor,
                'fecha'         => $fecha_creacion ? $fecha_creacion->format( 'c' ) : '',
                // ── Campos nuevos (retrocompatibles), ver CLAUDE.md "REST API — reportes" ──
                'utm_content'      => $utm_content,
                'utm_term'         => $utm_term,
                'desglose'         => $desglose,
                'tuvo_descuento'   => $descuento['tuvo_descuento'],
                'tipo_descuento'   => $descuento['tipo_descuento'],
                'monto_descuento'  => $descuento['monto_descuento'],
                'precio_bruto'     => $descuento['precio_bruto'],
                'total_pagado'     => $descuento['total_pagado'],
            );

            if ( $fecha_creacion ) {
                $dia = $fecha_creacion->format( 'Y-m-d' );
                if ( ! isset( $serie_diaria[ $dia ] ) ) {
                    $serie_diaria[ $dia ] = array(
                        'fecha'   => $dia,
                        'ingresos_web' => 0.0,
                        'ingresos_bo'  => 0.0,
                        'boletas' => 0,
                    );
                }
                if ( $is_bo ) {
                    $serie_diaria[ $dia ]['ingresos_bo'] += $valor;
                } else {
                    $serie_diaria[ $dia ]['ingresos_web'] += $valor;
                }
                $serie_diaria[ $dia ]['boletas'] += $boletas_orden;
            }
        }

        ksort( $serie_diaria );
        foreach ( $serie_diaria as &$dia_row ) {
            $dia_row['ingresos_total'] = $dia_row['ingresos_web'] + $dia_row['ingresos_bo'];
        }
        unset( $dia_row );

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
            'serie_diaria'  => array_values( $serie_diaria ),
        ), 200 );
    }
}
