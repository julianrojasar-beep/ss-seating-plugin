<?php
/**
 * Template: Página de evento — SS Seating (dark premium theme).
 *
 * Variables disponibles (desde SS_Ticket_Form::render):
 *   $event_id     — int
 *   $event        — array { id, title, post_type, status }
 *   $layout       — array|null (layout decodificado)
 *   $ticket_types — array [ { zone, price, capacity }, ... ]
 *   $sale_mode    — string (seat|general|hybrid)
 *   $product_id   — int (WC product ID)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ── Datos extra del evento ──
$post_obj = get_post( $event_id );

// Hero image: _ss_event_hero → fallback a post thumbnail
$hero_id   = (int) get_post_meta( $event_id, '_ss_event_hero', true );
$thumbnail = $hero_id ? wp_get_attachment_image_url( $hero_id, 'large' ) : '';
if ( ! $thumbnail ) {
    $thumbnail = get_the_post_thumbnail_url( $event_id, 'large' );
}

// Galería
$gallery_ids = get_post_meta( $event_id, '_ss_event_gallery', true );
$gallery_ids = is_array( $gallery_ids ) ? array_filter( array_map( 'intval', $gallery_ids ) ) : array();

// Descripción — wpautop, NO apply_filters('the_content') para evitar recursión
$raw_content = $post_obj ? $post_obj->post_content : '';
$raw_content = preg_replace( '/\[ss_ticket_form[^\]]*\]/', '', $raw_content );
$raw_content = preg_replace( '/\[ss_seating[^\]]*\]/', '', $raw_content );
$description = $raw_content ? wpautop( do_shortcode( $raw_content ) ) : '';
$has_description = $description && trim( wp_strip_all_tags( $description ) ) !== '';

// Fecha
$event_date_formatted = '';
$ss_date = get_post_meta( $event_id, '_ss_event_date', true );
$ss_time = get_post_meta( $event_id, '_ss_event_time', true );

if ( $ss_date ) {
    $datetime_str = $ss_time ? "$ss_date $ss_time" : $ss_date;
    $tz = wp_timezone();
    $dt = date_create( $datetime_str, $tz );
    if ( $dt ) {
        $event_date_formatted = wp_date( 'l, j \d\e F \d\e Y · g:i A', $dt->getTimestamp() );
    }
}

// Ubicación
$event_location = get_post_meta( $event_id, '_ss_location_venue', true );
$event_street   = get_post_meta( $event_id, '_ss_location_street', true );
$event_city     = get_post_meta( $event_id, '_ss_location_city', true );
$event_address  = implode( ', ', array_filter( array( $event_street, $event_city ) ) );

$has_map = $layout && in_array( $sale_mode, array( 'seat', 'hybrid', 'general' ), true );

// Inventario real por zona (sold + reserved descontados)
$zone_inventory = function_exists( 'ss_get_zone_inventory' ) ? ss_get_zone_inventory( $event_id ) : array();

// Enriquecer ticket_types con disponibilidad real
foreach ( $ticket_types as &$tt ) {
    $zone_key = $tt['zone'];
    $inv = $zone_inventory[ $zone_key ] ?? ( $zone_inventory[ strtoupper( $zone_key ) ] ?? null );
    if ( $inv ) {
        $tt['available'] = $inv['available'];
        $tt['total']     = $inv['total'];
        $tt['sold']      = $inv['sold'];
        $tt['reserved']  = $inv['reserved'];
    } else {
        $tt['available'] = $tt['capacity'];
        $tt['total']     = $tt['capacity'];
        $tt['sold']      = 0;
        $tt['reserved']  = 0;
    }
}
unset( $tt );

// Calcular disponibilidad total del evento
$total_available = 0;
foreach ( $ticket_types as $tt ) {
    $total_available += max( 0, (int) $tt['available'] );
}
$is_sold_out = ( $total_available <= 0 );

// Colores de sillas desde settings
$color_available = SS_Settings::get( 'seat_available_color', '#4CAF50' );
$color_reserved  = SS_Settings::get( 'seat_reserved_color', '#FF9800' );
$color_sold      = SS_Settings::get( 'seat_sold_color', '#e0e0e0' );
?>

<div class="tk-loader" id="ss-loader">
    <div class="tk-loader__spinner"></div>
</div>
<div class="tk-root ss-ticket-form" style="opacity:0"
     data-event-id="<?php echo esc_attr( $event_id ); ?>"
     data-product-id="<?php echo esc_attr( $product_id ); ?>">

    <!-- ═══ HERO ═══ -->
    <?php
    // Construir array de slides: hero principal + galería
    $slides = array();
    if ( $thumbnail ) {
        $hero_full = $hero_id ? wp_get_attachment_image_url( $hero_id, 'full' ) : get_the_post_thumbnail_url( $event_id, 'full' );
        $slides[] = array( 'large' => $thumbnail, 'full' => $hero_full ?: $thumbnail );
    }
    foreach ( $gallery_ids as $att_id ) {
        $large = wp_get_attachment_image_url( $att_id, 'large' );
        $full  = wp_get_attachment_image_url( $att_id, 'full' );
        if ( $large ) {
            $slides[] = array( 'large' => $large, 'full' => $full ?: $large );
        }
    }
    $has_slides = count( $slides ) > 0;
    $has_gallery = count( $slides ) > 1;
    ?>

    <?php if ( $has_slides ) : ?>
    <section class="hero" id="ss-hero">
        <img class="hero__image" id="ss-hero-img"
             src="<?php echo esc_url( $slides[0]['large'] ); ?>"
             data-full="<?php echo esc_url( $slides[0]['full'] ); ?>"
             alt="<?php echo esc_attr( $event['title'] ); ?>">
        <div class="hero__overlay">
            <div class="hero__content">
                <?php if ( $is_sold_out ) : ?>
                <div class="hero__badge hero__badge--sold-out">Agotado</div>
                <?php else : ?>
                <div class="hero__badge">En vivo</div>
                <?php endif; ?>
                <h1 class="hero__title"><?php echo esc_html( $event['title'] ); ?></h1>
                <?php if ( $event_date_formatted || $event_location || $event_address ) : ?>
                <div class="hero__meta">
                    <?php if ( $event_date_formatted ) : ?>
                    <span class="hero__meta-item">
                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M8 2v3M16 2v3M3 9h18M5 4h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V6a2 2 0 012-2z"/></svg>
                        <?php echo esc_html( $event_date_formatted ); ?>
                    </span>
                    <?php endif; ?>
                    <?php if ( $event_location ) : ?>
                    <span class="hero__meta-item">
                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <?php echo esc_html( $event_location ); ?><?php if ( $event_address ) : ?> · <?php echo esc_html( $event_address ); ?><?php endif; ?>
                    </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php if ( $has_gallery ) : ?>
            <div class="hero__dots" id="ss-hero-dots">
                <?php for ( $i = 0; $i < count( $slides ); $i++ ) : ?>
                <button type="button" class="hero__dot<?php echo $i === 0 ? ' active' : ''; ?>" data-index="<?php echo $i; ?>"></button>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>
    <?php else : ?>
    <section class="hero hero--no-image">
        <div class="hero__bg-art"></div>
        <div class="hero__stars"></div>
        <div class="hero__grid-lines"></div>
        <div class="hero__content">
            <?php if ( $is_sold_out ) : ?>
            <div class="hero__badge hero__badge--sold-out">Agotado</div>
            <?php else : ?>
            <div class="hero__badge">En vivo</div>
            <?php endif; ?>
            <h1 class="hero__title"><?php echo esc_html( $event['title'] ); ?></h1>
            <?php if ( $event_date_formatted || $event_location || $event_address ) : ?>
            <div class="hero__meta">
                <?php if ( $event_date_formatted ) : ?>
                <span class="hero__meta-item">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M8 2v3M16 2v3M3 9h18M5 4h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V6a2 2 0 012-2z"/></svg>
                    <?php echo esc_html( $event_date_formatted ); ?>
                </span>
                <?php endif; ?>
                <?php if ( $event_location ) : ?>
                <span class="hero__meta-item">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <?php echo esc_html( $event_location ); ?><?php if ( $event_address ) : ?> · <?php echo esc_html( $event_address ); ?><?php endif; ?>
                </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ═══ THUMBNAILS ═══ -->
    <?php if ( $has_gallery ) : ?>
    <div class="thumb-row">
        <?php foreach ( $slides as $idx => $slide ) :
            $thumb_url = $slide['large'];
        ?>
        <button type="button" class="thumb<?php echo $idx === 0 ? ' active' : ''; ?>" data-index="<?php echo $idx; ?>">
            <img src="<?php echo esc_url( $thumb_url ); ?>" alt="" loading="lazy">
        </button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ═══ MAIN LAYOUT: CSS Grid 1fr 340px ═══ -->
    <div class="main-layout">

        <!-- ── Izquierda: descripción + mapa ── -->
        <div class="seat-map-wrapper">

            <!-- Descripción arriba del mapa -->
            <?php if ( $has_description ) : ?>
            <div class="description-block">
                <p class="section-label">Acerca de este evento</p>
                <div class="description-block__body">
                    <?php echo wp_kses_post( $description ); ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Mapa de asientos -->
            <?php if ( $has_map ) : ?>
            <p class="section-label"><?php echo $sale_mode === 'general' ? 'Mapa del evento' : 'Selecciona tus asientos'; ?></p>
            <div class="seat-map-container">
                <div id="ss-floor-tabs-frontend" style="display:flex;gap:4px;margin-bottom:8px;flex-wrap:wrap;align-items:center;min-height:24px;"></div>
                <div id="ss-konva-container"></div>

                <!-- Leyenda -->
                <?php if ( $sale_mode !== 'general' ) : ?>
                <div class="legend">
                    <?php
                    $zones_legend = array();
                    if ( ! empty( $layout['zones'] ) && is_array( $layout['zones'] ) ) {
                        foreach ( $layout['zones'] as $z ) {
                            if ( ! empty( $z['id'] ) && ! empty( $z['color'] ) ) {
                                $zones_legend[ $z['id'] ] = $z['color'];
                            }
                        }
                    }
                    ?>
                    <?php if ( ! empty( $zones_legend ) ) : ?>
                        <?php foreach ( $zones_legend as $zone_name => $zone_color ) : ?>
                        <div class="legend-item">
                            <span class="legend-dot" style="background:<?php echo esc_attr( $zone_color ); ?>;"></span>
                            <span><?php echo esc_html( $zone_name ); ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <div class="legend-item">
                            <span class="legend-dot" style="background:<?php echo esc_attr( $color_available ); ?>;"></span>
                            <span>Disponible</span>
                        </div>
                    <?php endif; ?>
                    <div class="legend-item">
                        <span class="legend-dot" style="background:<?php echo esc_attr( $color_reserved ); ?>;"></span>
                        <span>Reservado</span>
                    </div>
                    <div class="legend-item">
                        <span class="legend-dot" style="background:<?php echo esc_attr( $color_sold ); ?>;"></span>
                        <span>Vendido</span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Derecha: sidebar sticky ── -->
        <div class="sidebar">
            <div class="event-card">

                <!-- Card header -->
                <div class="event-card__header">
                    <h2 class="event-card__title"><?php echo esc_html( $event['title'] ); ?></h2>
                </div>

                <?php if ( $event_date_formatted || $event_location || $event_address ) : ?>
                <div class="event-card__details">
                    <?php if ( $event_date_formatted ) : ?>
                    <div class="event-card__detail-row">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M8 2v3M16 2v3M3 9h18M5 4h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V6a2 2 0 012-2z"/></svg>
                        <span><?php echo esc_html( $event_date_formatted ); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ( $event_location ) : ?>
                    <div class="event-card__detail-row">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <span><?php echo esc_html( $event_location ); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ( $event_address ) : ?>
                    <div class="event-card__detail-row">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0a1 1 0 01-1-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 01-1 1h-2z"/></svg>
                        <span><?php echo esc_html( $event_address ); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Entradas / zonas -->
                <?php if ( ! empty( $ticket_types ) ) : ?>
                <div class="ticket-section">
                    <p class="ticket-section__title">Entradas</p>
                    <?php foreach ( $ticket_types as $ticket ) :
                        $zone_sold_out = ( (int) $ticket['available'] <= 0 );
                    ?>
                    <div class="ticket-type ss-ticket-form__zone-item<?php echo $zone_sold_out ? ' ticket-type--sold-out' : ''; ?>"
                         data-zone="<?php echo esc_attr( $ticket['zone'] ); ?>"
                         data-price="<?php echo esc_attr( $ticket['price'] ); ?>"
                         data-available="<?php echo esc_attr( $ticket['available'] ); ?>">
                        <div class="ticket-type__info">
                            <span class="ticket-type__name"><?php echo esc_html( $ticket['zone'] ); ?></span>
                            <?php if ( $zone_sold_out ) : ?>
                            <span class="ticket-type__avail ticket-type__avail--sold-out" data-zone="<?php echo esc_attr( $ticket['zone'] ); ?>">Agotado</span>
                            <?php else : ?>
                            <span class="ticket-type__avail" data-zone="<?php echo esc_attr( $ticket['zone'] ); ?>"><?php echo (int) $ticket['available']; ?> disponibles</span>
                            <?php endif; ?>
                        </div>
                        <div class="ticket-type__right">
                            <span class="ticket-type__price">
                                <?php echo $ticket['price'] > 0 ? wp_kses_post( wc_price( $ticket['price'] ) ) : esc_html( 'Gratis' ); ?>
                            </span>
                            <?php if ( $sale_mode !== 'seat' ) : ?>
                            <div class="qty-control ss-ticket-form__zone-qty<?php echo $zone_sold_out ? ' qty-control--disabled' : ''; ?>">
                                <button type="button" class="qty-btn ss-qty-minus" data-zone="<?php echo esc_attr( $ticket['zone'] ); ?>"<?php echo $zone_sold_out ? ' disabled' : ''; ?>>&#8722;</button>
                                <input type="number" class="ss-qty-input" value="0" min="0"
                                       max="<?php echo esc_attr( $ticket['available'] ); ?>"
                                       data-zone="<?php echo esc_attr( $ticket['zone'] ); ?>" readonly<?php echo $zone_sold_out ? ' disabled' : ''; ?>>
                                <button type="button" class="qty-btn ss-qty-plus" data-zone="<?php echo esc_attr( $ticket['zone'] ); ?>"<?php echo $zone_sold_out ? ' disabled' : ''; ?>>+</button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Resumen -->
                <div class="summary-section ss-ticket-form__summary" style="display:none;">
                    <div class="summary-row">
                        <span>Asientos seleccionados</span>
                        <span class="ss-ticket-form__summary-count">0</span>
                    </div>
                    <div class="summary-items ss-ticket-form__summary-items"></div>
                    <div class="summary-total ss-ticket-form__summary-total">
                        <span>Total</span>
                        <span class="ss-ticket-form__total-price">$0</span>
                    </div>
                </div>

                <!-- Hidden inputs -->
                <input type="hidden" name="ss_seats" id="ss_seats_input" value="">
                <input type="hidden" id="ss-selected-seats" value="">
                <input type="hidden" name="ss_zone" id="ss-ticket-form-zone" value="">

                <!-- CTA -->
                <?php if ( ! empty( $is_past ) ) : ?>
                <div class="cta-section">
                    <button type="button" class="ss-ticket-form__buy-btn ss-ticket-form__buy-btn--sold-out" disabled style="cursor:default;">
                        Este evento ya finalizó
                    </button>
                </div>
                <?php elseif ( $product_id ) : ?>
                <div class="cta-section">
                    <?php if ( $is_sold_out ) : ?>
                    <button type="button" class="ss-ticket-form__buy-btn ss-ticket-form__buy-btn--sold-out" disabled>
                        Agotado
                    </button>
                    <?php else : ?>
                    <button type="button" class="ss-ticket-form__buy-btn" disabled>
                        <?php echo esc_html( SS_Settings::get( 'text_buy_button', 'Selecciona tus asientos' ) ); ?>
                    </button>
                    <p class="cta-section__hint">Los asientos se reservan por 10 minutos</p>
                    <?php endif; ?>
                </div>

                <script>
                var ssTicketFormExtra = {
                    addToCartUrl: '<?php echo esc_url( add_query_arg( 'add-to-cart', $product_id, get_permalink( $event_id ) ) ); ?>',
                    eventId: <?php echo (int) $event_id; ?>,
                    productId: <?php echo (int) $product_id; ?>
                };
                </script>
                <?php else : ?>
                <div class="cta-section">
                    <p class="cta-section__no-product">
                        Este evento no tiene un producto WooCommerce asociado.
                    </p>
                </div>
                <?php endif; ?>

            </div><!-- .event-card -->
        </div><!-- .sidebar -->

    </div><!-- .main-layout -->

    <!-- ═══ LIGHTBOX ═══ -->
    <div class="lightbox" id="ss-lightbox">
        <button type="button" class="lightbox__close" id="ss-lightbox-close">&times;</button>
        <img src="" alt="" id="ss-lightbox-img">
    </div>

    <!-- ═══ HERO GALLERY + LIGHTBOX JS ═══ -->
    <?php if ( $has_slides ) : ?>
    <script>
    (function(){
        var slides = <?php echo wp_json_encode( array_values( $slides ) ); ?>;
        var current = 0;
        var heroImg = document.getElementById('ss-hero-img');
        var dots    = document.querySelectorAll('.tk-root .hero__dot');
        var thumbs  = document.querySelectorAll('.tk-root .thumb');
        var lb      = document.getElementById('ss-lightbox');
        var lbImg   = document.getElementById('ss-lightbox-img');

        function goTo(idx) {
            if (idx < 0 || idx >= slides.length || idx === current) return;
            current = idx;
            heroImg.style.opacity = '0';
            setTimeout(function(){
                heroImg.src = slides[current].large;
                heroImg.dataset.full = slides[current].full;
                heroImg.style.opacity = '1';
            }, 200);
            dots.forEach(function(d, i){ d.classList.toggle('active', i === current); });
            thumbs.forEach(function(t, i){ t.classList.toggle('active', i === current); });
        }

        // Thumbnail click → change hero
        thumbs.forEach(function(btn, i){
            btn.addEventListener('click', function(){ goTo(i); });
        });

        // Dot click → change hero
        dots.forEach(function(dot, i){
            dot.addEventListener('click', function(){ goTo(i); });
        });

        // Click hero image → open lightbox with full-size
        if (heroImg && lb) {
            heroImg.style.cursor = 'zoom-in';
            heroImg.addEventListener('click', function(){
                lbImg.src = heroImg.dataset.full || heroImg.src;
                lb.classList.add('ss-active');
            });
        }

        // Close lightbox
        if (lb) {
            lb.addEventListener('click', function(e){
                if (e.target === lbImg) return;
                lb.classList.remove('ss-active');
                lbImg.src = '';
            });
            document.addEventListener('keydown', function(e){
                if (e.key === 'Escape' && lb.classList.contains('ss-active')) {
                    lb.classList.remove('ss-active');
                    lbImg.src = '';
                }
            });
        }
    })();
    </script>
    <?php endif; ?>

</div><!-- .tk-root -->
<script>
(function(){
    var root = document.querySelector('.tk-root');
    var loader = document.getElementById('ss-loader');
    function reveal() {
        root.style.transition = 'opacity .4s ease';
        root.style.opacity = '1';
        if (loader) loader.style.display = 'none';
    }
    if (document.readyState === 'complete') { reveal(); }
    else { window.addEventListener('load', reveal); }
})();
</script>
