<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * API central de eventos — lectura directa de meta para ss_event.
 * Uso: SS_Event_Service::instance()->get_layout( $event_id );
 */
class SS_Event_Service {

    private static ?SS_Event_Service $instance = null;

    private function __construct() {}

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Devuelve datos básicos del evento (título, tipo, estado).
     */
    public function get_event( int $event_id ): ?array {
        $post = get_post( $event_id );
        if ( ! $post || $post->post_type !== 'ss_event' ) {
            return null;
        }
        return array(
            'id'        => $event_id,
            'title'     => $post->post_title,
            'post_type' => $post->post_type,
            'status'    => $post->post_status,
        );
    }

    /**
     * Layout JSON crudo (string).
     */
    public function get_layout_raw( int $event_id ): string {
        $raw = get_post_meta( $event_id, '_ss_layout', true );
        return is_string( $raw ) ? $raw : '';
    }

    /**
     * Layout JSON del evento (decoded).
     */
    public function get_layout( int $event_id ): ?array {
        $raw = get_post_meta( $event_id, '_ss_layout', true );
        if ( empty( $raw ) ) { return null; }
        $decoded = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
        return is_array( $decoded ) ? $decoded : null;
    }

    /**
     * Tipos de ticket normalizados.
     */
    public function get_ticket_types( int $event_id ): array {
        $raw = get_post_meta( $event_id, '_ss_ticket_types', true );
        if ( ! empty( $raw ) && is_array( $raw ) ) {
            return $raw;
        }
        return array();
    }

    /**
     * Modo de venta: seat, general, hybrid o no_map.
     */
    public function get_sale_mode( int $event_id ): string {
        $mode = get_post_meta( $event_id, '_ss_sale_mode', true );
        // Admin saves 'zone'; normalize to 'general' for consistency.
        if ( $mode === 'zone' ) { $mode = 'general'; }
        return in_array( $mode, array( 'seat', 'general', 'hybrid', 'no_map' ), true ) ? $mode : 'seat';
    }

    /**
     * True si el evento tiene fecha de fin de preventa configurada y todavía no pasó.
     */
    public function is_presale_active( int $event_id ): bool {
        $cutoff = get_post_meta( $event_id, '_ss_presale_end_date', true );
        if ( ! $cutoff ) {
            return false;
        }
        return current_time( 'Y-m-d' ) < $cutoff;
    }
}
