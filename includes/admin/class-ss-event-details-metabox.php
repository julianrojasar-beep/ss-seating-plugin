<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SS_Event_Details_Metabox — Fecha, ubicación y tipo de evento para ss_event.
 *
 * Meta keys:
 *   _ss_event_start_datetime — string (Y-m-d H:i)
 *   _ss_event_end_datetime   — string (Y-m-d H:i)
 *   _ss_location_venue       — string
 *   _ss_location_street      — string
 *   _ss_location_city        — string
 *   _ss_location_state       — string
 *   _ss_location_country     — string
 *   _ss_location_postcode    — string
 *   _ss_event_type           — string (presencial|virtual)
 */
class SS_Event_Details_Metabox {

    /** All location meta keys in display order. */
    private static array $location_fields = array(
        '_ss_location_venue'    => 'Lugar / Venue',
        '_ss_location_street'   => 'Dirección',
        '_ss_location_city'     => 'Ciudad',
        '_ss_location_state'    => 'Estado / Departamento',
        '_ss_location_country'  => 'País',
        '_ss_location_postcode' => 'Código postal',
    );

    public static function init(): void {
        add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
        add_action( 'save_post_ss_event', array( __CLASS__, 'save' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }

    public static function register(): void {
        add_meta_box(
            'ss_event_details',
            'Detalles del Evento',
            array( __CLASS__, 'render' ),
            'ss_event',
            'normal',
            'high'
        );
    }

    public static function enqueue( string $hook ): void {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'ss_event' ) {
            return;
        }

        $plugin_url  = plugin_dir_url( dirname( __DIR__ ) );
        $plugin_path = plugin_dir_path( dirname( __DIR__ ) );

        wp_enqueue_style(
            'ss-event-admin-details',
            $plugin_url . 'assets/css/ss-event-admin-details.css',
            array(),
            filemtime( $plugin_path . 'assets/css/ss-event-admin-details.css' )
        );
    }

    public static function render( \WP_Post $post ): void {
        wp_nonce_field( 'ss_event_details_save', 'ss_event_details_nonce' );

        $start = get_post_meta( $post->ID, '_ss_event_start_datetime', true );
        $end   = get_post_meta( $post->ID, '_ss_event_end_datetime', true );
        $type  = get_post_meta( $post->ID, '_ss_event_type', true ) ?: 'presencial';

        // Backward compat: if new keys are empty, try old _ss_event_date
        if ( ! $start ) {
            $old_date = get_post_meta( $post->ID, '_ss_event_date', true );
            if ( $old_date ) {
                $start = $old_date;
            }
        }

        // Format for datetime-local input (Y-m-d\TH:i)
        // El valor se almacena como hora local del sitio (no UTC).
        $start_val = $start ? str_replace( ' ', 'T', substr( $start, 0, 16 ) ) : '';
        $end_val   = $end   ? str_replace( ' ', 'T', substr( $end, 0, 16 ) )   : '';
        ?>
        <div class="ss-details-metabox">

            <!-- ── Fecha y hora ── -->
            <div class="ss-details-metabox__section">
                <h4>Fecha y hora</h4>
                <div class="ss-details-metabox__row">
                    <div class="ss-details-metabox__field">
                        <label for="ss-event-start">Inicio</label>
                        <input type="datetime-local" id="ss-event-start"
                               name="ss_event_start_datetime"
                               value="<?php echo esc_attr( $start_val ); ?>">
                    </div>
                    <div class="ss-details-metabox__field">
                        <label for="ss-event-end">Fin</label>
                        <input type="datetime-local" id="ss-event-end"
                               name="ss_event_end_datetime"
                               value="<?php echo esc_attr( $end_val ); ?>">
                    </div>
                </div>
            </div>

            <!-- ── Tipo de evento ── -->
            <div class="ss-details-metabox__section">
                <h4>Tipo de evento</h4>
                <div class="ss-details-metabox__radio-group">
                    <label>
                        <input type="radio" name="ss_event_type" value="presencial"
                               <?php checked( $type, 'presencial' ); ?>>
                        Presencial
                    </label>
                    <label>
                        <input type="radio" name="ss_event_type" value="virtual"
                               <?php checked( $type, 'virtual' ); ?>>
                        Virtual
                    </label>
                </div>
            </div>

            <!-- ── Ubicación ── -->
            <div class="ss-details-metabox__section ss-details-metabox__location">
                <h4>Ubicación</h4>
                <?php foreach ( self::$location_fields as $meta_key => $label ) :
                    $value = get_post_meta( $post->ID, $meta_key, true );

                    // Backward compat: venue fallback to old _ss_location
                    if ( ! $value && $meta_key === '_ss_location_venue' ) {
                        $value = get_post_meta( $post->ID, '_ss_location', true );
                    }

                    $input_name = substr( $meta_key, 1 ); // strip leading underscore for form name
                ?>
                <div class="ss-details-metabox__field">
                    <label for="<?php echo esc_attr( $input_name ); ?>"><?php echo esc_html( $label ); ?></label>
                    <input type="text" id="<?php echo esc_attr( $input_name ); ?>"
                           name="<?php echo esc_attr( $input_name ); ?>"
                           value="<?php echo esc_attr( $value ); ?>"
                           class="widefat">
                </div>
                <?php endforeach; ?>
            </div>

        </div>
        <?php
    }

    public static function save( int $post_id, \WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! isset( $_POST['ss_event_details_nonce'] )
             || ! wp_verify_nonce( $_POST['ss_event_details_nonce'], 'ss_event_details_save' ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // ── Date/time ──
        $start_raw = isset( $_POST['ss_event_start_datetime'] ) ? sanitize_text_field( $_POST['ss_event_start_datetime'] ) : '';
        $end_raw   = isset( $_POST['ss_event_end_datetime'] )   ? sanitize_text_field( $_POST['ss_event_end_datetime'] )   : '';

        // Convert from datetime-local (Y-m-d\TH:i) to storable format (Y-m-d H:i)
        // Se guarda como hora local del sitio (NO UTC) para evitar desfases al leer.
        $start = $start_raw ? str_replace( 'T', ' ', $start_raw ) : '';
        $end   = $end_raw   ? str_replace( 'T', ' ', $end_raw )   : '';

        self::update_or_delete( $post_id, '_ss_event_start_datetime', $start );
        self::update_or_delete( $post_id, '_ss_event_end_datetime', $end );

        // Also write to legacy _ss_event_date for backward compat with templates
        self::update_or_delete( $post_id, '_ss_event_date', $start );

        // ── Event type ──
        $type = isset( $_POST['ss_event_type'] ) ? sanitize_text_field( $_POST['ss_event_type'] ) : 'presencial';
        if ( ! in_array( $type, array( 'presencial', 'virtual' ), true ) ) {
            $type = 'presencial';
        }
        update_post_meta( $post_id, '_ss_event_type', $type );

        // ── Location fields ──
        foreach ( self::$location_fields as $meta_key => $label ) {
            $input_name = substr( $meta_key, 1 );
            $value = isset( $_POST[ $input_name ] ) ? sanitize_text_field( $_POST[ $input_name ] ) : '';
            self::update_or_delete( $post_id, $meta_key, $value );
        }

        // Also write venue to legacy _ss_location for backward compat
        $venue = isset( $_POST['ss_location_venue'] ) ? sanitize_text_field( $_POST['ss_location_venue'] ) : '';
        self::update_or_delete( $post_id, '_ss_location', $venue );
    }

    private static function update_or_delete( int $post_id, string $key, string $value ): void {
        if ( $value !== '' ) {
            update_post_meta( $post_id, $key, $value );
        } else {
            delete_post_meta( $post_id, $key );
        }
    }
}
