<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SS_Loyalty — Sistema de fidelización por asistencia.
 *
 * Identificador: email de facturación (válido para guest checkout).
 * Tiers: 0% → 5% → 10% (configurable en Settings del plugin).
 * Tabla: wp_ss_loyalty
 */
class SS_Loyalty {

    /** Versión de la tabla — incrementar para forzar dbDelta */
    const TABLE_VERSION = 3;

    // ── Bootstrap ────────────────────────────────────────────────────────────

    public static function init(): void {
        // Crear tabla si no existe (seguro en cualquier init)
        add_action( 'init', array( __CLASS__, 'ensure_table' ) );

        // Cron: evaluar asistencia después de cada evento
        add_action( 'ss_loyalty_evaluate_event', array( __CLASS__, 'evaluate_event' ) );

        // Programar cron cuando se guarda/publica un evento
        add_action( 'save_post_ss_event', array( __CLASS__, 'schedule_evaluation' ), 20, 2 );

        // Marcar compra cuando el pedido pasa a processing/completed
        // (en este punto ya están persistidos los item metas ss_event_id)
        add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_order_paid' ), 10, 1 );
        add_action( 'woocommerce_order_status_completed',  array( __CLASS__, 'on_order_paid' ), 10, 1 );

        // Aviso en checkout si el cliente tiene loyalty
        add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'show_checkout_notice' ) );
        add_action( 'woocommerce_before_order_review', array( __CLASS__, 'show_checkout_notice' ) );

        // AJAX: lookup de loyalty para el badge del BO
        add_action( 'wp_ajax_ss_bo_loyalty_lookup',        array( __CLASS__, 'ajax_bo_loyalty_lookup' ) );
        add_action( 'wp_ajax_nopriv_ss_bo_loyalty_lookup', array( __CLASS__, 'ajax_bo_loyalty_lookup' ) );
    }

    // ── Tabla ─────────────────────────────────────────────────────────────────

    public static function ensure_table(): void {
        global $wpdb;
        $stored = (int) get_option( 'ss_loyalty_table_version', 0 );
        if ( $stored >= self::TABLE_VERSION ) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $table = $wpdb->prefix . 'ss_loyalty';
        $sql = "CREATE TABLE {$table} (
            id              bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            email           varchar(191)        NOT NULL,
            tier            tinyint(3)          NOT NULL DEFAULT 0,
            shows_purchased int(11)             NOT NULL DEFAULT 0,
            shows_attended  int(11)             NOT NULL DEFAULT 0,
            redeemed_events longtext                     DEFAULT NULL,
            updated_at      datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY email (email)
        ) {$charset};";
        dbDelta( $sql );

        $log_table = $wpdb->prefix . 'ss_loyalty_log';
        $sql_log = "CREATE TABLE {$log_table} (
            id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            email       varchar(191)        NOT NULL,
            event_id    bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            accion      varchar(30)         NOT NULL,
            tier_before tinyint(3)          NOT NULL DEFAULT 0,
            tier_after  tinyint(3)          NOT NULL DEFAULT 0,
            nota        text                         DEFAULT NULL,
            created_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_email (email),
            KEY idx_event_id (event_id)
        ) {$charset};";
        dbDelta( $sql_log );

        update_option( 'ss_loyalty_table_version', self::TABLE_VERSION );
    }

    // ── Configuración ────────────────────────────────────────────────────────

    public static function tier1_pct(): int {
        return (int) get_option( 'ss_loyalty_tier1_pct', 5 );
    }

    public static function tier2_pct(): int {
        return (int) get_option( 'ss_loyalty_tier2_pct', 10 );
    }

    // ── CRUD ─────────────────────────────────────────────────────────────────

    public static function get( string $email ): array {
        global $wpdb;
        $email = strtolower( trim( $email ) );
        $table = $wpdb->prefix . 'ss_loyalty';
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email ),
            ARRAY_A
        );
        if ( ! $row ) {
            return array(
                'email'           => $email,
                'tier'            => 0,
                'shows_purchased' => 0,
                'shows_attended'  => 0,
                'redeemed_events' => null,
            );
        }
        return $row;
    }

    private static function upsert( string $email, array $data ): void {
        global $wpdb;
        $email = strtolower( trim( $email ) );
        $table = $wpdb->prefix . 'ss_loyalty';
        $existing = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s", $email )
        );
        $data['email']      = $email;
        $data['updated_at'] = current_time( 'mysql' );
        if ( $existing ) {
            $wpdb->update( $table, $data, array( 'email' => $email ) );
        } else {
            $wpdb->insert( $table, $data );
        }
    }

    // ── Lógica de tiers ──────────────────────────────────────────────────────

    /**
     * Recompensar compra: cada compra en el track sube un tier.
     * tier 0 → tier1 → tier2 (máximo).
     */
    public static function reward( string $email, int $event_id = 0 ): void {
        $row         = self::get( $email );
        $tier_before = (int) $row['tier'];
        $purchased   = (int) $row['shows_purchased'] + 1;

        $t1 = self::tier1_pct();
        $t2 = self::tier2_pct();

        if ( $tier_before === 0 ) {
            $new_tier = $t1;        // 0% → 5%
        } elseif ( $tier_before === $t1 ) {
            $new_tier = $t2;        // 5% → 10%
        } else {
            $new_tier = $tier_before; // ya en 10%, se mantiene
        }

        self::upsert( $email, array(
            'tier'            => $new_tier,
            'shows_purchased' => $purchased,
            'shows_attended'  => (int) $row['shows_attended'],
        ) );
        self::write_log( $email, 'reward', $tier_before, $new_tier, $event_id );
    }

    /**
     * Penalizar no-compra: baja un tier.
     * tier2 → tier1 → tier0. Si ya está en 0 no hace nada.
     */
    public static function penalize( string $email, int $event_id = 0 ): void {
        $row         = self::get( $email );
        $tier_before = (int) $row['tier'];
        if ( $tier_before <= 0 ) { return; }

        $t1       = self::tier1_pct();
        $new_tier = ( $tier_before >= self::tier2_pct() ) ? $t1 : 0;

        self::upsert( $email, array(
            'tier'            => $new_tier,
            'shows_purchased' => (int) $row['shows_purchased'],
            'shows_attended'  => (int) $row['shows_attended'],
        ) );
        self::write_log( $email, 'penalty', $tier_before, $new_tier, $event_id );
    }

    /**
     * Insertar fila en el log de fidelización.
     */
    private static function write_log( string $email, string $accion, int $tier_before, int $tier_after, int $event_id = 0, ?string $nota = null ): void {
        global $wpdb;
        $log_table = $wpdb->prefix . 'ss_loyalty_log';
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$log_table}'" ) ) { return; }
        $wpdb->insert( $log_table, array(
            'email'       => strtolower( trim( $email ) ),
            'event_id'    => $event_id,
            'accion'      => $accion,
            'tier_before' => $tier_before,
            'tier_after'  => $tier_after,
            'nota'        => $nota,
            'created_at'  => current_time( 'mysql' ),
        ), array( '%s', '%d', '%s', '%d', '%d', '%s', '%s' ) );
    }

    /**
     * Marcar compra sin cambiar tier (obsoleta, mantenida por compatibilidad).
     * Usar reward() en código nuevo.
     */
    public static function mark_purchase( string $email ): void {
        $row = self::get( $email );
        self::upsert( $email, array(
            'tier'            => (int) $row['tier'],
            'shows_purchased' => (int) $row['shows_purchased'] + 1,
            'shows_attended'  => (int) $row['shows_attended'],
        ) );
    }

    /**
     * Registrar que el email ya usó su descuento de loyalty para un evento.
     * Solo guarda si no estaba ya registrado (idempotente).
     */
    public static function mark_redeemed( string $email, int $event_id ): void {
        $row      = self::get( $email );
        $redeemed = json_decode( $row['redeemed_events'] ?? '[]', true );
        if ( ! is_array( $redeemed ) ) {
            $redeemed = array();
        }
        if ( ! in_array( $event_id, $redeemed, true ) ) {
            $redeemed[] = $event_id;
            self::upsert( $email, array(
                'tier'            => (int) $row['tier'],
                'shows_purchased' => (int) $row['shows_purchased'],
                'shows_attended'  => (int) $row['shows_attended'],
                'redeemed_events' => wp_json_encode( $redeemed ),
            ) );
        }
    }

    /**
     * Reset manual de un email (desde panel admin).
     */
    public static function reset( string $email ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_loyalty';
        $wpdb->update(
            $table,
            array( 'tier' => 0, 'shows_purchased' => 0, 'shows_attended' => 0 ),
            array( 'email' => strtolower( trim( $email ) ) )
        );
    }

    /**
     * Devuelve el % de descuento aplicable para un email en el carrito actual.
     * Solo aplica si el evento en el carrito tiene _ss_loyalty_enabled = 1.
     */
    public static function get_applicable_for_cart( string $email ): int {
        if ( empty( $email ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
            return 0;
        }

        $record   = self::get( $email );
        $pct      = (int) $record['tier'];
        if ( $pct <= 0 ) {
            return 0;
        }

        // Verificar que el carrito tiene eventos con loyalty habilitado
        // y que el email NO haya ya redimido su descuento en ese evento
        $redeemed = json_decode( $record['redeemed_events'] ?? '[]', true );
        if ( ! is_array( $redeemed ) ) {
            $redeemed = array();
        }

        $has_eligible_event = false;
        foreach ( WC()->cart->get_cart() as $item ) {
            $event_id = (int) ( $item['ss_event_id'] ?? 0 );
            if ( ! $event_id ) {
                continue;
            }
            if ( get_post_meta( $event_id, '_ss_loyalty_enabled', true ) !== '1' ) {
                continue;
            }
            if ( in_array( $event_id, $redeemed, true ) ) {
                continue; // ya redimió loyalty en este evento
            }
            $has_eligible_event = true;
            break;
        }

        return $has_eligible_event ? $pct : 0;
    }

    // ── Checkout hooks ────────────────────────────────────────────────────────

    /**
     * Aplicar descuento como fee negativo en el pedido.
     * Se dispara en woocommerce_checkout_create_order (email ya disponible).
     */
    public static function apply_discount_to_order( \WC_Order $order, array $data ): void {
        $email = strtolower( trim( $data['billing_email'] ?? '' ) );
        if ( empty( $email ) ) {
            return;
        }

        $group_pct   = SS_Group_Discount::get_discount_for_cart();
        $loyalty_pct = self::get_applicable_for_cart( $email );

        // Usar el mayor; en empate fidelización gana
        if ( $loyalty_pct >= $group_pct && $loyalty_pct > 0 ) {
            $pct   = $loyalty_pct;
            $label = sprintf( 'Descuento fidelización (%d%%)', $pct );
        } elseif ( $group_pct > 0 ) {
            $pct   = $group_pct;
            $label = sprintf( 'Descuento grupal (%d%%)', $pct );
        } else {
            return;
        }

        $subtotal = 0;
        foreach ( $order->get_items() as $item ) {
            $subtotal += (float) $item->get_subtotal();
        }
        if ( $subtotal <= 0 ) {
            return;
        }

        $discount = -round( $subtotal * $pct / 100, 2 );

        $fee = new \WC_Order_Item_Fee();
        $fee->set_name( $label );
        $fee->set_amount( $discount );
        $fee->set_total( $discount );
        $fee->set_tax_status( 'none' );
        $order->add_item( $fee );

        // Guardar meta para referencia
        $order->update_meta_data( '_ss_discount_pct', $pct );
        $order->update_meta_data( '_ss_discount_type', $loyalty_pct >= $group_pct ? 'loyalty' : 'group' );
    }

    /**
     * Cuando el pedido se paga (processing/completed), marcar la compra en loyalty.
     * Se ejecuta después de que los item metas ya están guardados en BD.
     */
    public static function on_order_paid( int $order_id ): void {
        // Evitar doble conteo si el estado cambia varias veces
        if ( get_post_meta( $order_id, '_ss_loyalty_purchase_marked', true ) === '1' ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $email = strtolower( trim( $order->get_billing_email() ) );
        if ( empty( $email ) ) {
            return;
        }
        // Solo recompensar si el pedido tiene un evento con loyalty habilitado
        foreach ( $order->get_items() as $item ) {
            $event_id = (int) $item->get_meta( 'ss_event_id' );
            if ( ! $event_id || get_post_meta( $event_id, '_ss_loyalty_enabled', true ) !== '1' ) {
                continue;
            }

            // Deduplicación: un solo reward por show (agrupado o no) por email.
            // Usa el grupo si existe; si no, usa el event_id como clave.
            $group    = trim( (string) get_post_meta( $event_id, '_ss_loyalty_show_group', true ) );
            $lock_key = $group
                ? 'ss_loyalty_grp_' . md5( $group ) . '_' . md5( $email )
                : 'ss_loyalty_reward_' . $event_id . '_' . md5( $email );

            update_post_meta( $order_id, '_ss_loyalty_purchase_marked', '1' );

            if ( get_option( $lock_key ) ) {
                // Ya recompensado para este show — no subir tier de nuevo
                break;
            }

            self::reward( $email, $event_id );
            update_option( $lock_key, '1', false );

            if ( function_exists( 'ss_boxoffice_log' ) ) {
                $tier_row = self::get( $email );
                ss_boxoffice_log( $event_id, 'sistema', 'loyalty_reward',
                    array( $email, 'tier=' . $tier_row['tier'] . '%' ), $order_id );
            }
            break;
        }
    }

    /**
     * Mostrar aviso en checkout si el cliente tiene loyalty disponible.
     */
    public static function show_checkout_notice(): void {
        if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
            return;
        }
        // Leer email del customer si ya está disponible
        $email = '';
        if ( function_exists( 'WC' ) && WC()->customer ) {
            $email = WC()->customer->get_billing_email();
        }
        if ( empty( $email ) ) {
            return;
        }

        $loyalty_pct = self::get_applicable_for_cart( $email );
        if ( $loyalty_pct <= 0 ) {
            return;
        }

        $t2 = self::tier2_pct();
        $msg = sprintf(
            '🎟 <strong>Tienes %d%% de descuento por fidelización.</strong> Se aplicará automáticamente en tu pedido.',
            $loyalty_pct
        );
        wc_print_notice( $msg, 'notice' );
    }

    // ── Cron ─────────────────────────────────────────────────────────────────

    /**
     * Programar evaluación de loyalty 3h después del evento.
     */
    public static function schedule_evaluation( int $post_id, \WP_Post $post ): void {
        if ( $post->post_status !== 'publish' ) {
            return;
        }
        if ( ! get_post_meta( $post_id, '_ss_loyalty_enabled', true ) ) {
            return;
        }
        $date = get_post_meta( $post_id, '_ss_event_date', true );
        $time = get_post_meta( $post_id, '_ss_event_time', true );
        if ( ! $date ) {
            return;
        }
        $datetime_str = $time ? "$date $time" : "$date 23:59";
        try {
            $dt = new \DateTime( $datetime_str, wp_timezone() );
            $dt->modify( '+3 hours' );
            $ts = $dt->getTimestamp();
        } catch ( \Exception $e ) {
            return;
        }
        if ( $ts <= time() ) {
            return; // Evento pasado, no programar
        }
        // Cancelar programación anterior para este evento
        $hook      = 'ss_loyalty_evaluate_event';
        $scheduled = wp_next_scheduled( $hook, array( $post_id ) );
        if ( $scheduled ) {
            wp_unschedule_event( $scheduled, $hook, array( $post_id ) );
        }
        wp_schedule_single_event( $ts, $hook, array( $post_id ) );

        // Auto-reanudar temporada si estaba pausada
        if ( get_option( 'ss_loyalty_season_paused' ) === '1' ) {
            update_option( 'ss_loyalty_season_paused', '0' );
        }
    }

    /**
     * Recalcula el tier de un email recorriendo TODOS los eventos del track
     * en orden cronológico y aplicando reward/penalize según compras reales.
     * Es idempotente: correrlo N veces produce el mismo resultado.
     */
    public static function recalculate_email( string $email ): void {
        $email = strtolower( trim( $email ) );

        // Todos los eventos del track pasados, en orden cronológico
        $track_events = get_posts( array(
            'post_type'      => 'ss_event',
            'posts_per_page' => -1,
            'meta_key'       => '_ss_event_date',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => array(
                array( 'key' => '_ss_loyalty_enabled', 'value' => '1' ),
                array( 'key' => '_ss_event_date', 'value' => gmdate( 'Y-m-d' ), 'compare' => '<=' ),
            ),
        ) );

        if ( empty( $track_events ) ) {
            return;
        }

        // IDs de eventos que este email compró
        $purchased_ids = self::get_all_purchased_event_ids_for_email( $email );

        $tier            = 0;
        $shows_purchased = 0;
        $t1              = self::tier1_pct();
        $t2              = self::tier2_pct();
        $seen_groups     = array();
        $seen_singles    = array();

        foreach ( $track_events as $ev ) {
            $eid   = $ev->ID;
            $group = trim( (string) get_post_meta( $eid, '_ss_loyalty_show_group', true ) );

            // Deduplicar shows agrupados: cada grupo/evento cuenta una sola vez
            if ( $group ) {
                if ( in_array( $group, $seen_groups, true ) ) { continue; }
                $seen_groups[] = $group;
                $group_ids     = self::get_events_by_group( $eid );
                $purchased     = ! empty( array_intersect( $group_ids, $purchased_ids ) );
            } else {
                if ( in_array( $eid, $seen_singles, true ) ) { continue; }
                $seen_singles[] = $eid;
                $purchased      = in_array( $eid, $purchased_ids, true );
            }

            if ( $purchased ) {
                $shows_purchased++;
                if ( $tier === 0 )       { $tier = $t1; }
                elseif ( $tier === $t1 ) { $tier = $t2; }
                // en t2 se mantiene
            } else {
                // Solo penalizar si ya estaba en el programa (tier > 0)
                if ( $tier > 0 ) {
                    $tier = ( $tier >= $t2 ) ? $t1 : 0;
                }
            }
        }

        $row = self::get( $email );
        self::upsert( $email, array(
            'tier'            => $tier,
            'shows_purchased' => $shows_purchased,
            'shows_attended'  => (int) $row['shows_attended'],
        ) );
    }

    /**
     * Devuelve los event IDs (únicos) comprados por un email en cualquier pedido.
     */
    private static function get_all_purchased_event_ids_for_email( string $email ): array {
        $orders = wc_get_orders( array(
            'billing_email' => $email,
            'status'        => array( 'processing', 'completed' ),
            'limit'         => -1,
        ) );
        $ids = array();
        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                $eid = (int) $item->get_meta( 'ss_event_id' );
                if ( $eid ) {
                    $ids[] = $eid;
                }
            }
        }
        return array_unique( $ids );
    }

    /**
     * Re-evaluación forzada desde el admin: recalcula el tier de todos los
     * clientes afectados por este evento usando el historial completo de compras.
     * Es idempotente — correrlo varias veces no infla los contadores.
     */
    public static function reevaluate_event( int $event_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_loyalty';

        // Emails a recalcular: todos en la tabla + compradores de este evento (grupo)
        $in_table   = $wpdb->get_col( "SELECT email FROM {$table}" );
        $purchasers = self::get_group_purchaser_emails( $event_id );
        $all_emails = array_unique( array_merge( $in_table, $purchasers ) );

        $rewarded  = 0;
        $penalized = 0;

        foreach ( $all_emails as $email ) {
            $before_tier = (int) self::get( $email )['tier'];
            self::recalculate_email( $email );
            $after_tier = (int) self::get( $email )['tier'];

            if ( $after_tier > $before_tier )      { $rewarded++;  }
            elseif ( $after_tier < $before_tier )  { $penalized++; }
        }

        return array(
            'rewarded'  => $rewarded,
            'penalized' => $penalized,
        );
    }

    /**
     * Devuelve los emails (lowercase) de quienes compraron un evento.
     */
    private static function get_event_purchaser_emails( int $event_id ): array {
        $orders     = wc_get_orders( array( 'status' => array( 'processing', 'completed' ), 'limit' => -1 ) );
        $purchasers = array();
        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                if ( (int) $item->get_meta( 'ss_event_id' ) === $event_id ) {
                    $purchasers[] = strtolower( trim( $order->get_billing_email() ) );
                    break;
                }
            }
        }
        return array_unique( $purchasers );
    }

    /**
     * Devuelve los IDs de eventos del mismo grupo de show.
     * Si el grupo está vacío, devuelve solo el evento dado.
     */
    private static function get_events_by_group( int $event_id ): array {
        $group = trim( (string) get_post_meta( $event_id, '_ss_loyalty_show_group', true ) );
        if ( empty( $group ) ) {
            return array( $event_id );
        }
        $ids = get_posts( array(
            'post_type'      => 'ss_event',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array( 'key' => '_ss_loyalty_show_group', 'value' => $group ),
            ),
        ) );
        return ! empty( $ids ) ? (array) $ids : array( $event_id );
    }

    /**
     * Devuelve los emails de compradores de todos los eventos del grupo del evento dado.
     * Si no hay grupo, equivale a get_event_purchaser_emails().
     */
    private static function get_group_purchaser_emails( int $event_id ): array {
        $event_ids  = self::get_events_by_group( $event_id );
        $purchasers = array();
        foreach ( $event_ids as $eid ) {
            $purchasers = array_merge( $purchasers, self::get_event_purchaser_emails( $eid ) );
        }
        return array_unique( $purchasers );
    }

    /**
     * Handler del cron: penaliza a los clientes del programa que NO compraron este evento.
     * Las recompensas ya ocurrieron en on_order_paid() al momento de la compra.
     */
    public static function evaluate_event( int $event_id ): void {
        if ( get_post_meta( $event_id, '_ss_loyalty_enabled', true ) !== '1' ) { return; }
        if ( get_option( 'ss_loyalty_season_paused' ) === '1' ) { return; }

        global $wpdb;
        $table = $wpdb->prefix . 'ss_loyalty';

        // Emails que SÍ compraron este evento (o cualquier noche del mismo grupo)
        $purchasers = self::get_group_purchaser_emails( $event_id );

        // Todos los clientes con tier > 0 en el programa
        $loyalty_customers = $wpdb->get_results( "SELECT email FROM {$table} WHERE tier > 0", ARRAY_A );

        foreach ( $loyalty_customers as $row ) {
            $email    = strtolower( trim( $row['email'] ) );
            $lock_key = 'ss_loyalty_eval_' . $event_id . '_' . md5( $email );

            if ( in_array( $email, $purchasers, true ) ) { continue; }   // compró → ya recompensado
            if ( get_option( $lock_key ) )                { continue; }   // ya penalizado antes

            self::penalize( $email, $event_id );
            update_option( $lock_key, '1', false );

            if ( function_exists( 'ss_boxoffice_log' ) ) {
                $tier_row = self::get( $email );
                ss_boxoffice_log( $event_id, 'sistema', 'loyalty_penalty',
                    array( $email, 'tier=' . $tier_row['tier'] . '%' ), null );
            }
        }

        // Auto-pausar si ya no hay eventos futuros del track
        $future = get_posts( array(
            'post_type'      => 'ss_event',
            'posts_per_page' => 1,
            'meta_query'     => array(
                array( 'key' => '_ss_loyalty_enabled', 'value' => '1' ),
                array( 'key' => '_ss_event_date', 'value' => gmdate( 'Y-m-d' ), 'compare' => '>' ),
            ),
        ) );
        if ( empty( $future ) ) {
            update_option( 'ss_loyalty_season_paused', '1' );
        }
    }

    // ── AJAX: BO loyalty badge ────────────────────────────────────────────────

    public static function ajax_bo_loyalty_lookup(): void {
        $email = strtolower( trim( sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) ) );
        if ( empty( $email ) ) {
            wp_send_json_error();
        }
        $row = self::get( $email );
        wp_send_json_success( array(
            'tier'  => (int) $row['tier'],
            'shows' => (int) $row['shows_purchased'],
        ) );
    }

    // ── Panel admin ───────────────────────────────────────────────────────────

    public static function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        global $wpdb;
        $table     = $wpdb->prefix . 'ss_loyalty';
        $log_table = $wpdb->prefix . 'ss_loyalty_log';

        // ── Vista detalle de cliente ──────────────────────────────────────────
        $customer_email = isset( $_GET['ss_customer'] ) ? sanitize_email( wp_unslash( $_GET['ss_customer'] ) ) : '';
        if ( $customer_email ) {
            // Handle edit save
            if ( isset( $_POST['ss_loyalty_edit_customer'], $_POST['ss_loyalty_admin_nonce'] )
                 && wp_verify_nonce( $_POST['ss_loyalty_admin_nonce'], 'ss_loyalty_admin' ) ) {
                $current = self::get( $customer_email );
                $new_tier   = absint( $_POST['ss_loyalty_tier'] ?? $current['tier'] );
                $new_shows  = absint( $_POST['ss_loyalty_shows'] ?? $current['shows_purchased'] );
                $nota_text  = sanitize_textarea_field( wp_unslash( $_POST['ss_loyalty_nota'] ?? '' ) );

                if ( $new_tier !== (int) $current['tier'] ) {
                    self::write_log( $customer_email, 'manual_tier', (int) $current['tier'], $new_tier, 0, $nota_text ?: null );
                }
                if ( $new_shows !== (int) $current['shows_purchased'] ) {
                    self::write_log( $customer_email, 'manual_shows', (int) $current['tier'], $new_tier, 0, $nota_text ?: null );
                }
                if ( $nota_text && $new_tier === (int) $current['tier'] && $new_shows === (int) $current['shows_purchased'] ) {
                    self::write_log( $customer_email, 'note', (int) $current['tier'], (int) $current['tier'], 0, $nota_text );
                }

                self::upsert( $customer_email, array(
                    'tier'            => $new_tier,
                    'shows_purchased' => $new_shows,
                    'shows_attended'  => (int) $current['shows_attended'],
                ) );
                echo '<div class="notice notice-success"><p>Cambios guardados para <strong>' . esc_html( $customer_email ) . '</strong>.</p></div>';
            }

            $cust     = self::get( $customer_email );
            $t1       = self::tier1_pct();
            $t2       = self::tier2_pct();
            $log_rows = $wpdb->get_results( $wpdb->get_var( "SHOW TABLES LIKE '{$log_table}'" )
                ? $wpdb->prepare( "SELECT * FROM {$log_table} WHERE email = %s ORDER BY created_at DESC LIMIT 200", $customer_email )
                : 'SELECT 1 WHERE 0', ARRAY_A );

            $back_url = admin_url( 'admin.php?page=ss-loyalty' );
            ?>
            <div class="wrap">
                <p><a href="<?php echo esc_url( $back_url ); ?>" style="text-decoration:none;">&larr; Volver a clientes</a></p>
                <h1 style="margin-top:8px;"><?php echo esc_html( $customer_email ); ?> &mdash; Fidelización</h1>

                <form method="post" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:20px 24px;max-width:540px;margin-bottom:2rem;">
                    <?php wp_nonce_field( 'ss_loyalty_admin', 'ss_loyalty_admin_nonce' ); ?>
                    <input type="hidden" name="ss_loyalty_edit_customer" value="1">

                    <table class="form-table" style="margin-top:0">
                        <tr>
                            <th style="width:160px;">Tier actual</th>
                            <td>
                                <select name="ss_loyalty_tier" style="min-width:140px;">
                                    <option value="0" <?php selected( $cust['tier'], 0 ); ?>>0% — Sin fidelización</option>
                                    <option value="<?php echo esc_attr( $t1 ); ?>" <?php selected( (int) $cust['tier'], $t1 ); ?>><?php echo esc_html( $t1 ); ?>%</option>
                                    <option value="<?php echo esc_attr( $t2 ); ?>" <?php selected( (int) $cust['tier'], $t2 ); ?>><?php echo esc_html( $t2 ); ?>%</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Shows comprados</th>
                            <td><input type="number" name="ss_loyalty_shows" value="<?php echo esc_attr( (int) $cust['shows_purchased'] ); ?>" min="0" style="width:80px;"></td>
                        </tr>
                        <tr>
                            <th>Nota / comentario</th>
                            <td><textarea name="ss_loyalty_nota" rows="2" style="width:100%;max-width:340px;" placeholder="Motivo del cambio (opcional)"></textarea></td>
                        </tr>
                    </table>
                    <?php submit_button( 'Guardar cambios', 'primary', 'submit', false ); ?>
                </form>

                <h2>Historial</h2>
                <?php if ( empty( $log_rows ) ) : ?>
                <p style="color:#888;">Sin entradas de historial aún.</p>
                <?php else : ?>
                <table class="wp-list-table widefat fixed striped" style="max-width:780px;">
                    <thead>
                        <tr>
                            <th style="width:140px;">Fecha</th>
                            <th style="width:200px;">Evento</th>
                            <th style="width:110px;">Acción</th>
                            <th style="width:140px;">Tier antes → después</th>
                            <th>Nota</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $log_rows as $lr ) :
                        $accion_label = array(
                            'reward'        => '⬆ Recompensa',
                            'penalty'       => '⬇ Penalización',
                            'manual_tier'   => '✏ Tier manual',
                            'manual_shows'  => '✏ Shows manual',
                            'note'          => '💬 Nota',
                        )[ $lr['accion'] ] ?? esc_html( $lr['accion'] );
                        $ev_title = $lr['event_id'] ? get_the_title( (int) $lr['event_id'] ) : '—';
                    ?>
                    <tr>
                        <td><?php echo esc_html( $lr['created_at'] ); ?></td>
                        <td><?php echo $ev_title ? esc_html( $ev_title ) : '—'; ?></td>
                        <td><?php echo $accion_label; ?></td>
                        <td><?php echo esc_html( $lr['tier_before'] . '% → ' . $lr['tier_after'] . '%' ); ?></td>
                        <td><?php echo $lr['nota'] ? esc_html( $lr['nota'] ) : ''; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php
            return;
        }

        // Re-evaluación forzada: borra la marca de los pedidos del evento y vuelve a evaluar
        if ( isset( $_POST['ss_loyalty_reevaluate'], $_POST['ss_loyalty_admin_nonce'] )
             && wp_verify_nonce( $_POST['ss_loyalty_admin_nonce'], 'ss_loyalty_admin' ) ) {
            $force_eid = absint( $_POST['ss_loyalty_event_id'] ?? 0 );
            if ( $force_eid ) {
                $result = self::reevaluate_event( $force_eid );
                echo '<div class="notice notice-success"><p>'
                    . esc_html( "Re-evaluación completada para el evento #{$force_eid}: {$result['rewarded']} compras recompensadas, {$result['penalized']} clientes penalizados." )
                    . '</p></div>';
            }
        }

        // Evaluación manual de evento (para eventos ya pasados sin cron programado)
        if ( isset( $_GET['ss_force_evaluate'] ) && current_user_can( 'manage_options' ) ) {
            $force_eid = absint( $_GET['ss_force_evaluate'] );
            if ( $force_eid ) {
                self::evaluate_event( $force_eid );
                echo '<div class="notice notice-success"><p>Evaluación de loyalty ejecutada para evento #' . $force_eid . '.</p></div>';
            }
        }

        // Toggle manual de temporada (override de emergencia)
        if ( isset( $_POST['ss_loyalty_toggle_season'], $_POST['ss_loyalty_admin_nonce'] )
             && wp_verify_nonce( $_POST['ss_loyalty_admin_nonce'], 'ss_loyalty_admin' ) ) {
            $new_state = get_option( 'ss_loyalty_season_paused' ) === '1' ? '0' : '1';
            update_option( 'ss_loyalty_season_paused', $new_state );
            $msg = $new_state === '1' ? 'Temporada pausada manualmente.' : 'Temporada reanudada manualmente.';
            echo '<div class="notice notice-success"><p>' . esc_html( $msg ) . '</p></div>';
        }

        // Guardar toggle de fidelización y grupo de un evento desde la timeline
        if ( isset( $_POST['ss_loyalty_timeline_save'], $_POST['ss_loyalty_admin_nonce'] )
             && wp_verify_nonce( $_POST['ss_loyalty_admin_nonce'], 'ss_loyalty_admin' ) ) {
            $tl_event_id = absint( $_POST['ss_loyalty_tl_event_id'] ?? 0 );
            $tl_enabled  = isset( $_POST['ss_loyalty_tl_enabled'] ) ? '1' : '0';
            $tl_group    = sanitize_text_field( wp_unslash( $_POST['ss_loyalty_tl_group'] ?? '' ) );
            if ( $tl_event_id ) {
                update_post_meta( $tl_event_id, '_ss_loyalty_enabled', $tl_enabled );
                update_post_meta( $tl_event_id, '_ss_loyalty_show_group', $tl_group );
                $ev_post = get_post( $tl_event_id );
                if ( $ev_post && $tl_enabled === '1' ) {
                    self::schedule_evaluation( $tl_event_id, $ev_post );
                }
                echo '<div class="notice notice-success"><p>Actualizado: <strong>' . esc_html( get_the_title( $tl_event_id ) ) . '</strong> '
                    . ( $tl_enabled === '1' ? '→ incluido en fidelización.' : '→ excluido del track.' )
                    . ( $tl_group ? ' Grupo: <code>' . esc_html( $tl_group ) . '</code>.' : '' )
                    . '</p></div>';
            }
        }

        // Handle reset action
        if ( isset( $_POST['ss_loyalty_reset_email'], $_POST['ss_loyalty_admin_nonce'] )
             && wp_verify_nonce( $_POST['ss_loyalty_admin_nonce'], 'ss_loyalty_admin' ) ) {
            self::reset( sanitize_email( $_POST['ss_loyalty_reset_email'] ) );
            echo '<div class="notice notice-success"><p>Tier reseteado.</p></div>';
        }

        // Handle config save
        if ( isset( $_POST['ss_loyalty_save_config'], $_POST['ss_loyalty_admin_nonce'] )
             && wp_verify_nonce( $_POST['ss_loyalty_admin_nonce'], 'ss_loyalty_admin' ) ) {
            update_option( 'ss_loyalty_tier1_pct', absint( $_POST['ss_loyalty_tier1_pct'] ) );
            update_option( 'ss_loyalty_tier2_pct', absint( $_POST['ss_loyalty_tier2_pct'] ) );
            echo '<div class="notice notice-success"><p>Configuración guardada.</p></div>';
        }

        // Search
        $search = isset( $_GET['ss_search'] ) ? sanitize_email( $_GET['ss_search'] ) : '';
        $where  = $search ? $wpdb->prepare( 'WHERE email LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' ) : '';
        $rows   = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY tier DESC, shows_attended DESC LIMIT 100", ARRAY_A );
        $t1 = self::tier1_pct();
        $t2 = self::tier2_pct();
        ?>
        <div class="wrap">
            <h1>Fidelización — SS Seating</h1>

            <h2>Configuración de tiers</h2>
            <form method="post" style="margin-bottom:2rem;">
                <?php wp_nonce_field( 'ss_loyalty_admin', 'ss_loyalty_admin_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th>Descuento Tier 1 (%)</th>
                        <td><input type="number" name="ss_loyalty_tier1_pct" value="<?php echo esc_attr( $t1 ); ?>" min="1" max="100" style="width:80px;"></td>
                    </tr>
                    <tr>
                        <th>Descuento Tier 2 / máximo (%)</th>
                        <td><input type="number" name="ss_loyalty_tier2_pct" value="<?php echo esc_attr( $t2 ); ?>" min="1" max="100" style="width:80px;"></td>
                    </tr>
                </table>
                <input type="hidden" name="ss_loyalty_save_config" value="1">
                <?php submit_button( 'Guardar configuración', 'primary', 'submit', false ); ?>
            </form>

            <h2>Re-evaluar evento</h2>
            <p style="color:#555;max-width:600px">
                Recalcula el tier de todos los clientes del programa recorriendo el historial completo
                de compras en orden cronológico. El resultado es el mismo sin importar cuántas veces
                se ejecute. Úsalo para corregir datos incorrectos o después de agregar/cambiar grupos.
            </p>
            <form method="post" style="margin-bottom:2rem;" onsubmit="return confirm('¿Confirmas la re-evaluación? Esto modificará los tiers de los clientes del evento seleccionado.')">
                <?php wp_nonce_field( 'ss_loyalty_admin', 'ss_loyalty_admin_nonce' ); ?>
                <?php
                $all_events = get_posts( array(
                    'post_type'      => 'ss_event',
                    'post_status'    => array( 'publish', 'private' ),
                    'posts_per_page' => 300,
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                    'fields'         => 'ids',
                ) );
                ?>
                <select name="ss_loyalty_event_id" required style="min-width:280px;margin-right:8px">
                    <option value="">— Selecciona un evento —</option>
                    <?php foreach ( $all_events as $eid ) :
                        $loyalty_on = get_post_meta( $eid, '_ss_loyalty_enabled', true ) === '1';
                    ?>
                    <option value="<?php echo esc_attr( $eid ); ?>">
                        <?php echo esc_html( get_the_title( $eid ) ); ?>
                        <?php echo $loyalty_on ? '' : ' (loyalty desactivado)'; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="ss_loyalty_reevaluate" value="1">
                <button type="submit" class="button button-primary">⟳ Re-evaluar evento</button>
            </form>

            <?php
            // ── Banner de estado de temporada ────────────────────────────────
            $is_paused = get_option( 'ss_loyalty_season_paused' ) === '1';
            if ( $is_paused ) :
            ?>
            <div style="background:#fff8e1;border-left:4px solid #ff9800;padding:14px 18px;margin:0 0 24px;display:flex;align-items:center;justify-content:space-between;gap:16px;border-radius:2px;">
                <div>
                    <strong style="font-size:15px;">⏸ TEMPORADA EN PAUSA</strong>
                    <p style="margin:4px 0 0;color:#555;">Los tiers están congelados. Nadie pierde ni gana descuento hasta que se reactive la temporada.</p>
                </div>
                <form method="post" style="flex-shrink:0;">
                    <?php wp_nonce_field( 'ss_loyalty_admin', 'ss_loyalty_admin_nonce' ); ?>
                    <input type="hidden" name="ss_loyalty_toggle_season" value="1">
                    <button type="submit" class="button button-primary" style="background:#2271b1;border-color:#2271b1;">▶ Iniciar temporada</button>
                </form>
            </div>
            <?php else : ?>
            <div style="background:#f0fdf4;border-left:4px solid #00a32a;padding:14px 18px;margin:0 0 24px;display:flex;align-items:center;justify-content:space-between;gap:16px;border-radius:2px;">
                <div>
                    <strong style="font-size:15px;">🟢 TEMPORADA ACTIVA</strong>
                    <p style="margin:4px 0 0;color:#555;">Las penalizaciones se aplican con normalidad. El sistema evalúa cada evento 3 horas después de finalizado.</p>
                </div>
                <form method="post" style="flex-shrink:0;">
                    <?php wp_nonce_field( 'ss_loyalty_admin', 'ss_loyalty_admin_nonce' ); ?>
                    <input type="hidden" name="ss_loyalty_toggle_season" value="1">
                    <button type="submit" class="button" onclick="return confirm('¿Pausar la temporada? Los tiers quedarán congelados hasta que la reactive manualmente.')">⏸ Pausar temporada</button>
                </form>
            </div>
            <?php endif; ?>

            <?php
            // ── Timeline de eventos ──────────────────────────────────────────
            $timeline_events = get_posts( array(
                'post_type'      => 'ss_event',
                'post_status'    => array( 'publish', 'private', 'future' ),
                'posts_per_page' => 500,
                'meta_key'       => '_ss_event_date',
                'orderby'        => 'meta_value',
                'order'          => 'ASC',
            ) );
            ?>
            <h2 style="margin-top:0">Timeline de eventos</h2>
            <?php if ( empty( $timeline_events ) ) : ?>
                <p style="color:#888;">No hay eventos publicados.</p>
            <?php else : ?>
            <div style="border:1px solid #ddd;border-radius:4px;overflow:hidden;margin-bottom:2rem;">
                <?php
                $current_month = '';
                foreach ( $timeline_events as $ev ) :
                    $ev_date    = get_post_meta( $ev->ID, '_ss_event_date', true );
                    $ev_enabled = get_post_meta( $ev->ID, '_ss_loyalty_enabled', true ) === '1';
                    $ev_group   = get_post_meta( $ev->ID, '_ss_loyalty_show_group', true );
                    $month_key  = $ev_date ? date_i18n( 'F Y', strtotime( $ev_date ) ) : 'Sin fecha';

                    if ( $month_key !== $current_month ) :
                        if ( $current_month !== '' ) : ?>
                        </tbody></table>
                        <?php endif; ?>
                        <div style="background:#f6f7f7;padding:8px 14px;border-top:1px solid #ddd;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#50575e;">
                            <?php echo esc_html( $month_key ); ?>
                        </div>
                        <table style="width:100%;border-collapse:collapse;">
                        <tbody>
                        <?php $current_month = $month_key;
                    endif;
                    ?>
                    <tr style="border-top:1px solid #f0f0f0;">
                        <td style="padding:10px 14px;width:32px;text-align:center;font-size:18px;">
                            <?php echo $ev_enabled ? '●' : '○'; ?>
                        </td>
                        <td style="padding:10px 6px;">
                            <strong><?php echo esc_html( $ev->post_title ); ?></strong>
                            <?php if ( $ev_date ) : ?>
                            <span style="color:#888;margin-left:8px;font-size:12px;"><?php echo esc_html( date_i18n( 'd M', strtotime( $ev_date ) ) ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:8px 14px;text-align:right;">
                            <div style="display:flex;align-items:center;justify-content:flex-end;gap:6px;flex-wrap:wrap;">

                                <?php /* ── Campo grupo + Guardar ── */ ?>
                                <form method="post" style="display:flex;align-items:center;gap:4px;">
                                    <?php wp_nonce_field( 'ss_loyalty_admin', 'ss_loyalty_admin_nonce' ); ?>
                                    <input type="hidden" name="ss_loyalty_tl_event_id" value="<?php echo esc_attr( $ev->ID ); ?>">
                                    <?php if ( $ev_enabled ) : ?>
                                    <input type="hidden" name="ss_loyalty_tl_enabled" value="1">
                                    <?php endif; ?>
                                    <input type="text"
                                           name="ss_loyalty_tl_group"
                                           value="<?php echo esc_attr( $ev_group ); ?>"
                                           placeholder="Grupo (ej: jdm-may-2026)"
                                           style="width:150px;font-size:12px;padding:3px 6px;"
                                           title="Eventos con el mismo grupo se tratan como un solo show para fidelización">
                                    <button type="submit" name="ss_loyalty_timeline_save" value="1" class="button button-small">Guardar</button>
                                </form>

                                <?php /* ── Toggle incluir / excluir ── */ ?>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field( 'ss_loyalty_admin', 'ss_loyalty_admin_nonce' ); ?>
                                    <input type="hidden" name="ss_loyalty_tl_event_id" value="<?php echo esc_attr( $ev->ID ); ?>">
                                    <input type="hidden" name="ss_loyalty_tl_group" value="<?php echo esc_attr( $ev_group ); ?>">
                                    <?php if ( $ev_enabled ) : ?>
                                        <button type="submit" name="ss_loyalty_timeline_save" value="1" class="button button-small"
                                                onclick="return confirm('¿Excluir este evento del track de fidelización?')"
                                                style="color:#b32d2e;border-color:#b32d2e;">Excluir</button>
                                    <?php else : ?>
                                        <input type="hidden" name="ss_loyalty_tl_enabled" value="1">
                                        <button type="submit" name="ss_loyalty_timeline_save" value="1" class="button button-small button-primary">Incluir</button>
                                    <?php endif; ?>
                                </form>

                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody></table>
            </div>
            <?php endif; ?>

            <h2>Clientes fidelizados</h2>
            <form method="get" style="margin-bottom:1rem;">
                <input type="hidden" name="page" value="ss-loyalty">
                <input type="search" name="ss_search" value="<?php echo esc_attr( $search ); ?>" placeholder="Buscar por email">
                <?php submit_button( 'Buscar', 'secondary', 'submit', false ); ?>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Tier actual</th>
                        <th>Shows comprados</th>
                        <th>Shows asistidos</th>
                        <th>Última actualización</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="6">Sin registros aún.</td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) :
                        $tier_label = $row['tier'] === '0' ? '—' : $row['tier'] . '%';
                        $tier_color = $row['tier'] >= $t2 ? '#2271b1' : ( $row['tier'] >= $t1 ? '#00a32a' : '#666' );
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ss-loyalty&ss_customer=' . rawurlencode( $row['email'] ) ) ); ?>">
                                <?php echo esc_html( $row['email'] ); ?>
                            </a>
                        </td>
                        <td><strong style="color:<?php echo esc_attr( $tier_color ); ?>"><?php echo esc_html( $tier_label ); ?></strong></td>
                        <td><?php echo (int) $row['shows_purchased']; ?></td>
                        <td><?php echo (int) $row['shows_attended']; ?></td>
                        <td><?php echo esc_html( $row['updated_at'] ); ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'ss_loyalty_admin', 'ss_loyalty_admin_nonce' ); ?>
                                <input type="hidden" name="ss_loyalty_reset_email" value="<?php echo esc_attr( $row['email'] ); ?>">
                                <button type="submit" class="button button-small" onclick="return confirm('¿Resetear tier de <?php echo esc_js( $row['email'] ); ?>?')">Reset</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
