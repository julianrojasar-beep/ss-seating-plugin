<?php
/**
 * Template: Franja compacta de próximos eventos — [ss_events style="compact"].
 *
 * Variables disponibles:
 *   $upcoming   — WP_Post[]  (eventos futuros, ordenados por fecha ASC)
 *   $primary    — string     (color primario hex)
 *   $text_color — string     (color de texto hex)
 *   $title      — string     (título opcional de la sección)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$r = hexdec( substr( $primary, 1, 2 ) );
$g = hexdec( substr( $primary, 3, 2 ) );
$b = hexdec( substr( $primary, 5, 2 ) );
$lighter = sprintf( '#%02x%02x%02x', min( 255, $r + 25 ), min( 255, $g + 25 ), min( 255, $b + 25 ) );
$contrast = ss_get_contrast_text_color( $primary );

$tr = hexdec( substr( $text_color, 1, 2 ) );
$tg = hexdec( substr( $text_color, 3, 2 ) );
$tb = hexdec( substr( $text_color, 5, 2 ) );
?>

<div class="ss-events-archive ss-events-compact" style="--ss-primary:<?php echo esc_attr( $primary ); ?>;--ss-primary-light:<?php echo esc_attr( $lighter ); ?>;--ss-primary-rgb:<?php echo "$r,$g,$b"; ?>;--ss-primary-contrast:<?php echo esc_attr( $contrast ); ?>;--ss-text:<?php echo esc_attr( $text_color ); ?>;--ss-text-rgb:<?php echo "$tr,$tg,$tb"; ?>;">

<?php if ( ! empty( $upcoming ) ) : ?>
    <section class="ss-events-section">
        <?php if ( $title !== '' ) : ?>
            <h2 class="ss-events-section__title"><?php echo esc_html( $title ); ?></h2>
            <div class="ss-events-section__line"></div>
        <?php endif; ?>
        <div class="ss-events-grid ss-events-grid--compact">
            <?php foreach ( $upcoming as $i => $ev ) :
                echo ss_render_event_card( $ev, false, 0 === $i );
            endforeach; ?>
        </div>
    </section>
<?php else : ?>
    <p class="ss-events-empty">No hay próximos eventos.</p>
<?php endif; ?>

</div>
