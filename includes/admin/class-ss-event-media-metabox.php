<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SS_Event_Media_Metabox — Imagen hero + galería para ss_event.
 *
 * Meta keys:
 *   _ss_event_hero    — int (attachment ID)
 *   _ss_event_gallery — array de int (attachment IDs)
 */
class SS_Event_Media_Metabox {

    public static function init(): void {
        add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
        add_action( 'save_post_ss_event', array( __CLASS__, 'save' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }

    public static function register(): void {
        add_meta_box(
            'ss_event_media',
            'Medios del Evento',
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

        wp_enqueue_media();

        $plugin_url  = plugin_dir_url( dirname( __DIR__ ) );
        $plugin_path = plugin_dir_path( dirname( __DIR__ ) );

        wp_enqueue_script(
            'ss-event-admin-media',
            $plugin_url . 'assets/js/ss-event-admin-media.js',
            array( 'jquery' ),
            filemtime( $plugin_path . 'assets/js/ss-event-admin-media.js' ),
            true
        );

        wp_enqueue_style(
            'ss-event-admin-media',
            $plugin_url . 'assets/css/ss-event-admin-media.css',
            array(),
            filemtime( $plugin_path . 'assets/css/ss-event-admin-media.css' )
        );
    }

    public static function render( \WP_Post $post ): void {
        wp_nonce_field( 'ss_event_media_save', 'ss_event_media_nonce' );

        $hero_id    = (int) get_post_meta( $post->ID, '_ss_event_hero', true );
        $gallery    = get_post_meta( $post->ID, '_ss_event_gallery', true );
        $gallery    = is_array( $gallery ) ? array_map( 'intval', $gallery ) : array();
        $hero_url   = $hero_id ? wp_get_attachment_image_url( $hero_id, 'medium' ) : '';
        ?>
        <div class="ss-media-metabox">

            <!-- ── Imagen principal ── -->
            <div class="ss-media-metabox__section">
                <h4>Imagen principal del evento</h4>
                <div class="ss-media-metabox__hero-preview" id="ss-hero-preview">
                    <?php if ( $hero_url ) : ?>
                        <img src="<?php echo esc_url( $hero_url ); ?>" alt="">
                    <?php endif; ?>
                </div>
                <input type="hidden" name="ss_event_hero" id="ss-hero-input" value="<?php echo esc_attr( $hero_id ?: '' ); ?>">
                <button type="button" class="button" id="ss-hero-select">Seleccionar imagen</button>
                <button type="button" class="button ss-media-metabox__remove<?php echo $hero_id ? '' : ' hidden'; ?>" id="ss-hero-remove">Quitar</button>
            </div>

            <!-- ── Galería ── -->
            <div class="ss-media-metabox__section">
                <h4>Galería de imágenes</h4>
                <div class="ss-media-metabox__gallery" id="ss-gallery-container">
                    <?php foreach ( $gallery as $att_id ) :
                        $thumb = wp_get_attachment_image_url( $att_id, 'thumbnail' );
                        if ( ! $thumb ) { continue; }
                    ?>
                    <div class="ss-media-metabox__gallery-item" data-id="<?php echo esc_attr( $att_id ); ?>">
                        <img src="<?php echo esc_url( $thumb ); ?>" alt="">
                        <button type="button" class="ss-gallery-remove" title="Quitar">&times;</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="ss_event_gallery" id="ss-gallery-input"
                       value="<?php echo esc_attr( implode( ',', $gallery ) ); ?>">
                <button type="button" class="button" id="ss-gallery-add">Agregar imágenes</button>
            </div>

        </div>
        <?php
    }

    public static function save( int $post_id, \WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! isset( $_POST['ss_event_media_nonce'] )
             || ! wp_verify_nonce( $_POST['ss_event_media_nonce'], 'ss_event_media_save' ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Hero
        $hero = isset( $_POST['ss_event_hero'] ) ? absint( $_POST['ss_event_hero'] ) : 0;
        if ( $hero > 0 ) {
            update_post_meta( $post_id, '_ss_event_hero', $hero );
        } else {
            delete_post_meta( $post_id, '_ss_event_hero' );
        }

        // Gallery
        $gallery_raw = isset( $_POST['ss_event_gallery'] ) ? sanitize_text_field( $_POST['ss_event_gallery'] ) : '';
        if ( $gallery_raw !== '' ) {
            $ids = array_values( array_filter( array_map( 'absint', explode( ',', $gallery_raw ) ) ) );
            update_post_meta( $post_id, '_ss_event_gallery', $ids );
        } else {
            delete_post_meta( $post_id, '_ss_event_gallery' );
        }
    }
}
