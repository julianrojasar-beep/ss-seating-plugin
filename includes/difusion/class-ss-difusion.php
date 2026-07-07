<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SS_Difusion — Core: series, active events, UTM builder, template renderer.
 */
class SS_Difusion {

    const SERIES_OPTION = 'ss_difusion_series';
    const ACTIVE_PREFIX = 'ss_difusion_active_';

    public static function init(): void {
        add_action( 'init',              array( __CLASS__, 'register_rewrite_rules' ) );
        add_filter( 'query_vars',        array( __CLASS__, 'add_query_vars' ) );
        add_action( 'template_redirect', array( __CLASS__, 'handle_smart_link' ) );
        add_action( 'save_post_ss_event', array( __CLASS__, 'save_event_meta' ), 20 );
    }

    // ── Series ──────────────────────────────────────────────────────────

    public static function get_series(): array {
        $series = get_option( self::SERIES_OPTION, array() );
        return is_array( $series ) ? $series : array();
    }

    public static function get_serie( string $serie_id ): ?array {
        foreach ( self::get_series() as $s ) {
            if ( isset( $s['id'] ) && $s['id'] === $serie_id ) {
                return $s;
            }
        }
        return null;
    }

    public static function save_series( array $series ): void {
        update_option( self::SERIES_OPTION, array_values( $series ) );
        // Borra las reglas del DB para forzar regeneración en el siguiente request
        // (cuando init ya tiene los datos nuevos). Más confiable que flush_rewrite_rules()
        // dentro del mismo request donde init ya corrió con datos viejos.
        delete_option( 'rewrite_rules' );
    }

    // ── Active Event ─────────────────────────────────────────────────────

    public static function get_active_event_id( string $serie_id ): int {
        return (int) get_option( self::ACTIVE_PREFIX . $serie_id, 0 );
    }

    public static function set_active_event( int $event_id, string $serie_id ): void {
        $prev = self::get_active_event_id( $serie_id );
        if ( $prev && $prev !== $event_id ) {
            update_post_meta( $prev, '_ss_difusion_is_active', '0' );
        }
        update_option( self::ACTIVE_PREFIX . $serie_id, $event_id );
        update_post_meta( $event_id, '_ss_difusion_is_active', '1' );
    }

    public static function clear_active_event( string $serie_id ): void {
        $prev = self::get_active_event_id( $serie_id );
        if ( $prev ) {
            update_post_meta( $prev, '_ss_difusion_is_active', '0' );
        }
        delete_option( self::ACTIVE_PREFIX . $serie_id );
    }

    // ── Save from event admin ────────────────────────────────────────────

    public static function save_event_meta( int $post_id ): void {
        if ( ! isset( $_POST['_ss_difusion_nonce'] ) ) { return; }
        if ( ! wp_verify_nonce( wp_unslash( $_POST['_ss_difusion_nonce'] ), 'ss_difusion_save' ) ) { return; }

        $serie_id  = sanitize_key( wp_unslash( $_POST['ss_difusion_serie_id'] ?? '' ) );
        $is_active = ! empty( $_POST['ss_difusion_is_active'] );
        $artists   = sanitize_textarea_field( wp_unslash( $_POST['ss_event_artists'] ?? '' ) );

        update_post_meta( $post_id, '_ss_event_artists', $artists );

        if ( $serie_id ) {
            update_post_meta( $post_id, '_ss_difusion_serie_id', $serie_id );
            if ( $is_active ) {
                self::set_active_event( $post_id, $serie_id );
            } else {
                if ( self::get_active_event_id( $serie_id ) === $post_id ) {
                    self::clear_active_event( $serie_id );
                } else {
                    update_post_meta( $post_id, '_ss_difusion_is_active', '0' );
                }
            }
        } else {
            $old_serie = get_post_meta( $post_id, '_ss_difusion_serie_id', true );
            if ( $old_serie && self::get_active_event_id( $old_serie ) === $post_id ) {
                self::clear_active_event( $old_serie );
            }
            delete_post_meta( $post_id, '_ss_difusion_serie_id' );
            delete_post_meta( $post_id, '_ss_difusion_is_active' );
        }
    }

    // ── Rewrite Rules ────────────────────────────────────────────────────

    public static function register_rewrite_rules(): void {
        foreach ( self::get_series() as $serie ) {
            if ( empty( $serie['slug'] ) ) { continue; }
            $slug = sanitize_title( $serie['slug'] );
            add_rewrite_rule(
                '^' . preg_quote( $slug, '#' ) . '/?$',
                'index.php?ss_smart_link=' . urlencode( $serie['id'] ),
                'top'
            );
        }
    }

    public static function add_query_vars( array $vars ): array {
        $vars[] = 'ss_smart_link';
        return $vars;
    }

    public static function handle_smart_link(): void {
        $serie_id = get_query_var( 'ss_smart_link' );
        if ( ! $serie_id ) { return; }

        $event_id = self::get_active_event_id( $serie_id );
        if ( ! $event_id ) {
            wp_redirect( home_url( '/' ) );
            exit;
        }
        wp_redirect( get_permalink( $event_id ), 302 );
        exit;
    }

    // ── UTM Builder ──────────────────────────────────────────────────────

    public static function get_smart_link( array $serie ): string {
        return home_url( '/' . sanitize_title( $serie['slug'] ) . '/' );
    }

    public static function build_campaign( int $event_id, array $serie ): string {
        $prefix = sanitize_title( $serie['campaign_prefix'] ?? $serie['id'] );
        $date   = get_post_meta( $event_id, '_ss_event_date', true );
        if ( $date ) {
            $ts   = strtotime( $date );
            $year = date( 'Y', $ts );
            $mon  = date( 'm', $ts );
        } else {
            $year = date( 'Y' );
            $mon  = date( 'm' );
        }
        return $prefix . '_' . $year . '_' . $mon;
    }

    public static function build_utm_url( int $event_id, array $serie, string $source, string $medium ): string {
        $base     = self::get_smart_link( $serie );
        $campaign = self::build_campaign( $event_id, $serie );
        return add_query_arg( array(
            'utm_source'   => $source,
            'utm_medium'   => $medium,
            'utm_campaign' => $campaign,
        ), $base );
    }

    public static function get_channels(): array {
        return array(
            'whatsapp'      => array( 'label' => 'WhatsApp',      'source' => 'meta',      'medium' => 'whatsapp' ),
            'instagram_bio' => array( 'label' => 'Instagram Bio', 'source' => 'instagram', 'medium' => 'bio' ),
            'qr'            => array( 'label' => 'QR / Poster',   'source' => 'qr',        'medium' => 'poster' ),
        );
    }

    // ── Template Variables ────────────────────────────────────────────────

    public static function get_event_vars( int $event_id, array $serie ): array {
        $date_raw = get_post_meta( $event_id, '_ss_event_date', true );
        $time_raw = get_post_meta( $event_id, '_ss_event_time', true );
        $venue    = get_post_meta( $event_id, '_ss_location_venue', true )
                 ?: get_post_meta( $event_id, '_ss_location', true );
        $artists  = get_post_meta( $event_id, '_ss_event_artists', true );

        $fecha_larga = '';
        if ( $date_raw ) {
            $ts    = strtotime( $date_raw );
            $dias  = array( 'domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado' );
            $meses = array( '', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
                            'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );
            $fecha_larga = $dias[ (int) date( 'w', $ts ) ] . ' ' . (int) date( 'j', $ts )
                         . ' de ' . $meses[ (int) date( 'n', $ts ) ] . ' de ' . date( 'Y', $ts );
        }

        $hora = '';
        if ( $time_raw ) {
            $ts_t = strtotime( $time_raw );
            $hora = $ts_t ? date( 'g:i a', $ts_t ) : $time_raw;
        }

        $ticket_types = SS_Event_Service::instance()->get_ticket_types( $event_id );
        $prices       = array_filter( array_column( $ticket_types, 'price' ) );
        $precio       = '';
        if ( $prices ) {
            $precio = '$' . number_format( (float) min( $prices ), 0, ',', '.' );
        }

        return array(
            'fecha_larga' => $fecha_larga,
            'artistas'    => $artists ?: '',
            'precio'      => $precio,
            'hora'        => $hora,
            'teatro'      => $venue ?: '',
            'link'        => self::get_smart_link( $serie ),
        );
    }

    public static function render_template( int $event_id, array $serie ): string {
        $template = $serie['wa_template'] ?? '';
        if ( ! $template ) { return ''; }
        $vars = self::get_event_vars( $event_id, $serie );
        foreach ( $vars as $key => $val ) {
            $template = str_replace( '{' . $key . '}', $val, $template );
        }
        return $template;
    }
}
