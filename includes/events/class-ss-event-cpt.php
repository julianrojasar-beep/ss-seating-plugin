<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Registra el Custom Post Type ss_event.
 *
 * Al publicar un evento, crea automáticamente un producto WC oculto
 * para que add-to-cart funcione.
 */
class SS_Event_CPT {

    public static function register(): void {
        register_post_type( 'ss_event', array(
            'labels' => array(
                'name'               => 'Eventos SS',
                'singular_name'      => 'Evento SS',
                'add_new'            => 'Agregar evento',
                'add_new_item'       => 'Agregar nuevo evento',
                'edit_item'          => 'Editar evento',
                'new_item'           => 'Nuevo evento',
                'view_item'          => 'Ver evento',
                'search_items'       => 'Buscar eventos',
                'not_found'          => 'No se encontraron eventos',
                'not_found_in_trash' => 'No se encontraron eventos en la papelera',
                'menu_name'          => 'Eventos SS',
            ),
            'public'       => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-tickets-alt',
            'supports'     => array( 'title', 'thumbnail' ),
            'has_archive'  => true,
            'rewrite'      => array( 'slug' => 'ss-evento' ),
        ) );

        // Crear producto WC oculto al publicar/guardar un ss_event
        add_action( 'save_post_ss_event', array( __CLASS__, 'ensure_wc_product' ), 20, 2 );

        // Duplicar evento: mismo mapa/tickets/config, fecha en blanco para forzar elegir la nueva
        add_filter( 'post_row_actions', array( __CLASS__, 'add_duplicate_row_action' ), 10, 2 );
        add_action( 'admin_action_ss_duplicate_event', array( __CLASS__, 'handle_duplicate' ) );
        add_action( 'admin_notices', array( __CLASS__, 'duplicate_admin_notice' ) );
    }

    /**
     * Meta que NO se copia al duplicar un evento: son datos de venta/estado
     * de la instancia original, no de la configuración reutilizable (mapa,
     * tickets, descuentos, difusión, etc.).
     */
    private static function duplicate_skip_meta(): array {
        return array(
            '_ss_product_id',          // se regenera solo al publicar el duplicado (ensure_wc_product)
            '_ss_sold_seats',           // ventas del evento original, no se heredan
            '_ss_zone_sold_qty',        // ventas por zona del evento original
            '_ss_event_checkin_token',  // se autogenera la primera vez que se visita /control-ingreso/
            '_ss_cierre_extras',        // extras de taquilla ya facturados del evento original
            '_ss_difusion_is_active',   // evitar 2 eventos "activos" a la vez en la misma serie
            '_edit_lock',
            '_edit_last',
        );
    }

    /**
     * Agrega el link "Duplicar" a la lista de eventos (SS Seating → Eventos).
     */
    public static function add_duplicate_row_action( array $actions, \WP_Post $post ): array {
        if ( $post->post_type !== 'ss_event' || ! current_user_can( 'edit_post', $post->ID ) ) {
            return $actions;
        }
        $url = wp_nonce_url(
            add_query_arg( array( 'action' => 'ss_duplicate_event', 'post' => $post->ID ), admin_url( 'admin.php' ) ),
            'ss_duplicate_event_' . $post->ID
        );
        $actions['ss_duplicate'] = '<a href="' . esc_url( $url ) . '">Duplicar</a>';
        return $actions;
    }

    /**
     * Crea una copia del evento como borrador: mismo mapa, tickets, precios,
     * ubicación, organizador y difusión — fecha/hora y preventa en blanco
     * a propósito, para obligar a fijar la fecha nueva antes de publicar.
     */
    public static function handle_duplicate(): void {
        $post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
        if ( ! $post_id || ! check_admin_referer( 'ss_duplicate_event_' . $post_id ) ) {
            wp_die( 'Solicitud inválida.' );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( 'No tienes permiso para duplicar este evento.' );
        }

        $original = get_post( $post_id );
        if ( ! $original || $original->post_type !== 'ss_event' ) {
            wp_die( 'Evento no encontrado.' );
        }

        $new_id = wp_insert_post( array(
            'post_title'   => $original->post_title . ' (copia)',
            'post_content' => $original->post_content,
            'post_type'    => 'ss_event',
            'post_status'  => 'draft',
            'post_author'  => get_current_user_id(),
        ), true );

        if ( is_wp_error( $new_id ) || ! $new_id ) {
            wp_die( 'No se pudo duplicar el evento.' );
        }

        $skip_meta = self::duplicate_skip_meta();
        foreach ( get_post_meta( $post_id ) as $meta_key => $values ) {
            if ( in_array( $meta_key, $skip_meta, true ) ) {
                continue;
            }
            foreach ( $values as $value ) {
                add_post_meta( $new_id, $meta_key, maybe_unserialize( $value ) );
            }
        }

        // Fecha/hora/preventa en blanco: es la razón de ser de "duplicar un evento
        // recurrente" — forzar a elegir la fecha nueva antes de publicar.
        delete_post_meta( $new_id, '_ss_event_date' );
        delete_post_meta( $new_id, '_ss_event_time' );
        delete_post_meta( $new_id, '_ss_presale_end_date' );

        wp_safe_redirect( admin_url( 'post.php?post=' . $new_id . '&action=edit&ss_duplicated=1' ) );
        exit;
    }

    /**
     * Aviso tras duplicar, en la pantalla de edición del nuevo borrador.
     */
    public static function duplicate_admin_notice(): void {
        if ( empty( $_GET['ss_duplicated'] ) ) {
            return;
        }
        echo '<div class="notice notice-success is-dismissible"><p>'
            . 'Evento duplicado. Revisa y fija la <strong>fecha/hora</strong> antes de publicarlo — quedó en blanco a propósito.'
            . '</p></div>';
    }

    /**
     * Crea un producto WC oculto vinculado al evento (si no existe).
     * Patrón idéntico a MPWEM: producto simple, virtual, precio 0.01,
     * excluido de catálogo y búsqueda.
     */
    public static function ensure_wc_product( int $post_id, \WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! function_exists( 'wc_get_product' ) ) {
            return;
        }
        if ( $post->post_status !== 'publish' ) {
            // Evento no publicado (borrador/papelera/pendiente): si ya tiene
            // producto vinculado, asegurar que no quede público ni comprable.
            self::sync_product_commercial_status( $post_id );
            return;
        }

        // Ya tiene producto vinculado y es válido?
        $existing_pid = (int) get_post_meta( $post_id, '_ss_product_id', true );
        if ( $existing_pid > 0 ) {
            $existing = wc_get_product( $existing_pid );
            if ( $existing && $existing->get_id() > 0 ) {
                // Producto válido — sincronizar título, imagen y descripción
                $existing->set_name( $post->post_title );
                self::sync_product_image( $post_id, $existing );
                self::sync_product_description( $post_id, $post, $existing );
                $existing->save();
                self::sync_product_commercial_status( $post_id, $existing );
                return;
            }
            // Producto inválido — limpiar meta y crear uno nuevo
            delete_post_meta( $post_id, '_ss_product_id' );
            wp_delete_post( $existing_pid, true );
        }

        // Crear producto WC oculto usando la API de WooCommerce
        $product = new \WC_Product_Simple();
        $product->set_name( $post->post_title );
        $product->set_slug( 'ss-event-product-' . $post_id );
        $product->set_status( 'publish' );
        $product->set_catalog_visibility( 'hidden' );
        $product->set_price( 0.01 );
        $product->set_regular_price( 0.01 );
        $product->set_virtual( true );
        $product->set_sold_individually( true );
        $product->set_tax_status( 'none' );
        $product->set_stock_status( 'instock' );
        $product->set_manage_stock( false );

        self::sync_product_image( $post_id, $product );
        self::sync_product_description( $post_id, $post, $product );

        $product_id = $product->save();

        if ( ! $product_id ) {
            return;
        }

        // Vincular evento ↔ producto
        update_post_meta( $post_id, '_ss_product_id', $product_id );
        update_post_meta( $product_id, '_ss_event_id', $post_id );

        self::sync_product_commercial_status( $post_id, $product );
    }

    /**
     * Pone el producto vinculado en 'publish' solo si el evento está
     * publicado y tiene una fecha registrada que no haya pasado. En
     * cualquier otro caso (borrador, papelera, sin fecha, fecha vencida)
     * lo pasa a 'private' para que no quede accesible como página pública
     * ni comprable, sin depender de que alguien reedite el evento a mano.
     */
    public static function sync_product_commercial_status( int $event_id, ?\WC_Product $product = null ): void {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return;
        }
        if ( ! $product ) {
            $product_id = (int) get_post_meta( $event_id, '_ss_product_id', true );
            if ( ! $product_id ) {
                return;
            }
            $product = wc_get_product( $product_id );
        }
        if ( ! $product ) {
            return;
        }

        $event_date = get_post_meta( $event_id, '_ss_event_date', true );
        $is_active  = get_post_status( $event_id ) === 'publish'
            && $event_date
            && $event_date >= current_time( 'Y-m-d' );

        $target_status = $is_active ? 'publish' : 'private';
        if ( $product->get_status() !== $target_status ) {
            $product->set_status( $target_status );
            $product->save();
        }
    }

    /**
     * Sincroniza la imagen del evento al producto WC.
     * Prioridad: _ss_event_hero → post thumbnail.
     */
    private static function sync_product_image( int $event_id, \WC_Product $product ): void {
        $image_id = (int) get_post_meta( $event_id, '_ss_event_hero', true );
        if ( ! $image_id ) {
            $image_id = (int) get_post_thumbnail_id( $event_id );
        }
        if ( $image_id ) {
            $product->set_image_id( $image_id );
        }
    }

    /**
     * Genera descripción automática del producto a partir de los datos del evento.
     */
    private static function sync_product_description( int $event_id, \WP_Post $post, \WC_Product $product ): void {
        $date  = get_post_meta( $event_id, '_ss_event_date', true );
        $time  = get_post_meta( $event_id, '_ss_event_time', true );
        $venue = get_post_meta( $event_id, '_ss_location_venue', true );
        $city  = get_post_meta( $event_id, '_ss_location_city', true );
        $org   = get_post_meta( $event_id, '_ss_organizer_name', true ) ?: get_bloginfo( 'name' );

        $date_formatted = '';
        $time_formatted = '';
        $wp_tz = wp_timezone();

        if ( $date ) {
            $datetime_str = $time ? "$date $time" : $date;
            try {
                $dt = new \DateTime( $datetime_str, $wp_tz );
                $ts = $dt->getTimestamp();
                $date_formatted = wp_date( 'j \d\e F, Y', $ts );
                if ( $time && wp_date( 'H:i', $ts ) !== '00:00' ) {
                    $time_formatted = wp_date( 'g:i A', $ts );
                }
            } catch ( \Exception $e ) { /* formato inválido */ }
        }

        $lines = array();
        $lines[] = 'Evento: ' . $post->post_title;
        if ( $date_formatted ) { $lines[] = 'Fecha: ' . $date_formatted; }
        if ( $time_formatted ) { $lines[] = 'Hora: ' . $time_formatted; }
        if ( $venue )          { $lines[] = 'Lugar: ' . $venue; }
        if ( $city )           { $lines[] = 'Ciudad: ' . $city; }
        $lines[] = 'Organizador: ' . $org;

        $desc = implode( "\n", $lines );
        $product->set_short_description( $desc );
        $product->set_description( $desc );
    }
}
