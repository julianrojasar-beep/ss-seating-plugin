<?php
/**
 * Template: Página de archivo de eventos — SS Seating.
 *
 * Variables disponibles:
 *   $upcoming — WP_Post[]  (eventos futuros, ordenados por fecha ASC)
 *   $past     — WP_Post[]  (eventos pasados, ordenados por fecha DESC)
 *   $primary    — string   (color primario hex)
 *   $text_color — string   (color de texto hex)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// CSS variables inline
$r = hexdec( substr( $primary, 1, 2 ) );
$g = hexdec( substr( $primary, 3, 2 ) );
$b = hexdec( substr( $primary, 5, 2 ) );
$lighter = sprintf( '#%02x%02x%02x', min( 255, $r + 25 ), min( 255, $g + 25 ), min( 255, $b + 25 ) );
$contrast = ss_get_contrast_text_color( $primary );

$tr = hexdec( substr( $text_color, 1, 2 ) );
$tg = hexdec( substr( $text_color, 3, 2 ) );
$tb = hexdec( substr( $text_color, 5, 2 ) );
?>


<div class="ss-events-archive" style="--ss-primary:<?php echo esc_attr( $primary ); ?>;--ss-primary-light:<?php echo esc_attr( $lighter ); ?>;--ss-primary-rgb:<?php echo "$r,$g,$b"; ?>;--ss-primary-contrast:<?php echo esc_attr( $contrast ); ?>;--ss-text:<?php echo esc_attr( $text_color ); ?>;--ss-text-rgb:<?php echo "$tr,$tg,$tb"; ?>;">

<?php if ( ! empty( $upcoming ) ) : ?>
    <section class="ss-events-section">
        <h2 class="ss-events-section__title">Pr&oacute;ximos Eventos</h2>
        <div class="ss-events-section__line"></div>
        <div class="ss-events-grid">
            <?php foreach ( $upcoming as $i => $ev ) :
                echo ss_render_event_card( $ev, false, 0 === $i );
            endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ( ! empty( $past ) ) : ?>
    <section class="ss-events-section ss-events-section--past">
        <h2 class="ss-events-section__title">Eventos Pasados</h2>
        <div class="ss-events-section__line"></div>
        <div class="ss-events-grid">
            <?php foreach ( $past as $ev ) :
                echo ss_render_event_card( $ev, true );
            endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ( empty( $upcoming ) && empty( $past ) ) : ?>
    <p class="ss-events-empty">No hay eventos disponibles.</p>
<?php endif; ?>

</div>
