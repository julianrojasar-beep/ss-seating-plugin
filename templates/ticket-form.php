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

// Hero image: _ss_event_hero → fallback a post thumbnail.
// wp_get_attachment_image_src() (no solo la URL) para tener width/height reales
// y ponerlos como atributos del <img> — así el navegador reserva el espacio
// correcto desde el primer frame, sin esperar a que cargue el CSS
// (evita el "flash" de la imagen a su tamaño nativo en píxeles antes del
// width:100% — justo lo que se veía en algunos eventos).
$hero_id        = (int) get_post_meta( $event_id, '_ss_event_hero', true );
$thumbnail      = '';
$thumbnail_w    = 0;
$thumbnail_h    = 0;
$hero_src       = $hero_id ? wp_get_attachment_image_src( $hero_id, 'large' ) : false;
if ( $hero_src ) {
    list( $thumbnail, $thumbnail_w, $thumbnail_h ) = $hero_src;
}
if ( ! $thumbnail ) {
    $fallback_id = get_post_thumbnail_id( $event_id );
    $fallback_src = $fallback_id ? wp_get_attachment_image_src( $fallback_id, 'large' ) : false;
    if ( $fallback_src ) {
        list( $thumbnail, $thumbnail_w, $thumbnail_h ) = $fallback_src;
    }
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

// Índice case-insensitive: el nombre de zona en _ss_ticket_types puede tener
// mayúsculas/minúsculas distintas al del mapa (ej. "General" en el mapa vs
// "GENERAL" en Tickets) — sin esto, la búsqueda directa/uppercase de abajo
// falla en esa combinación y cae al fallback (capacidad completa sin
// descontar ventas), aunque el % vendido general (que no depende de este
// match) siga siendo correcto.
$zone_inventory_ci = array();
foreach ( $zone_inventory as $inv_key => $inv_row ) {
    $zone_inventory_ci[ mb_strtoupper( trim( $inv_key ) ) ] = $inv_row;
}

// Preventa: ¿aplica ahora mismo?
$is_presale = class_exists( 'SS_Event_Service' ) ? SS_Event_Service::instance()->is_presale_active( $event_id ) : false;

$presale_end_formatted  = '';
$presale_end_timestamp  = 0;
if ( $is_presale ) {
    $presale_end_raw = get_post_meta( $event_id, '_ss_presale_end_date', true );
    if ( $presale_end_raw ) {
        $tz = wp_timezone();
        $dt = date_create( $presale_end_raw . ' 00:00:00', $tz );
        if ( $dt ) {
            $presale_end_formatted = wp_date( 'j \d\e F, Y', $dt->getTimestamp() );
            $presale_end_timestamp = $dt->getTimestamp() * 1000; // ms, para el contador JS
        }
    }
}

// Enriquecer ticket_types con disponibilidad real y precio efectivo (preventa/normal)
foreach ( $ticket_types as &$tt ) {
    $zone_key = $tt['zone'];
    $inv = $zone_inventory_ci[ mb_strtoupper( trim( $zone_key ) ) ] ?? null;
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

    $presale_price = isset( $tt['presale_price'] ) ? (float) $tt['presale_price'] : 0;
    $tt['is_presale_price'] = ( $is_presale && $presale_price > 0 );
    $tt['effective_price']  = $tt['is_presale_price'] ? $presale_price : (float) $tt['price'];
}
unset( $tt );

// Calcular disponibilidad total del evento (mismo helper que usa la tarjeta de evento)
$availability_summary = function_exists( 'ss_get_event_availability_summary' )
    ? ss_get_event_availability_summary( $event_id )
    : array( 'total' => 0, 'available' => 0, 'percentage' => 0 );
$total_available = $availability_summary['available'];
$is_sold_out      = ( $availability_summary['total'] > 0 && $total_available <= 0 );

// Colores de ESTADO de silla — configurables en SS Seating → Configuración →
// Estilo (SS_Settings). La categoría comercial (cualquier zona definida por
// el admin) ya no se indica con el color de relleno, sino con la rayita
// inferior coloreada según el color de zona del builder (ver leyenda más
// abajo) — mismo criterio que ss-konva-renderer.js (SEAT_COLORS).
$color_available = SS_Settings::get( 'seat_available_color', '#64748B' );
$color_reserved  = SS_Settings::get( 'seat_reserved_color',  '#F59E0B' );
$color_sold      = SS_Settings::get( 'seat_sold_color',      '#F1F5F9' );
$color_selected  = SS_Settings::get( 'seat_selected_color',  '#8B5CF6' );

// Zonas con rayita de categoría propia — mismo criterio que drawSeat() en
// ss-konva-renderer.js: el color es el que el admin le asignó a esa zona en
// el builder (_ss_layout.zones). Sin nombres de zona hardcodeados — una zona
// sin color propio asignado simplemente no aparece acá (ni dibuja rayita en
// el mapa).
$zone_colors_map = array();
if ( ! empty( $layout['zones'] ) && is_array( $layout['zones'] ) ) {
    foreach ( $layout['zones'] as $z ) {
        if ( ! empty( $z['id'] ) ) {
            $zone_colors_map[ $z['id'] ] = $z['color'] ?? '';
        }
    }
}
$category_zones = array(); // zone_name => accent_color
foreach ( $ticket_types as $tt_check ) {
    $zname = trim( $tt_check['zone'] ?? '' );
    if ( $zname === '' || isset( $category_zones[ $zname ] ) ) {
        continue;
    }
    $accent = $zone_colors_map[ $zname ] ?? '';
    if ( $accent === '' ) {
        continue; // sin color asignado todavía — el mapa tampoco dibuja rayita para esta zona
    }
    $category_zones[ $zname ] = $accent;
}
// ¿Hay alguna zona de este evento sin color asignado? (para el ejemplo "sin
// categoría" en la leyenda — genérico, no un nombre de zona hardcodeado).
$has_uncategorized_zone = false;
foreach ( $ticket_types as $tt_check ) {
    $zname = trim( $tt_check['zone'] ?? '' );
    if ( $zname !== '' && ! isset( $category_zones[ $zname ] ) ) {
        $has_uncategorized_zone = true;
        break;
    }
}
?>

<div class="tk-root ss-ticket-form"
     data-event-id="<?php echo esc_attr( $event_id ); ?>"
     data-product-id="<?php echo esc_attr( $product_id ); ?>">

    <!-- ═══ HERO ═══ -->
    <?php
    // Construir array de slides: hero principal + galería
    $slides = array();
    if ( $thumbnail ) {
        $hero_full = $hero_id ? wp_get_attachment_image_url( $hero_id, 'full' ) : get_the_post_thumbnail_url( $event_id, 'full' );
        $slides[] = array( 'large' => $thumbnail, 'full' => $hero_full ?: $thumbnail, 'w' => $thumbnail_w, 'h' => $thumbnail_h );
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
             <?php if ( ! empty( $slides[0]['w'] ) && ! empty( $slides[0]['h'] ) ) : ?>
             width="<?php echo esc_attr( $slides[0]['w'] ); ?>"
             height="<?php echo esc_attr( $slides[0]['h'] ); ?>"
             <?php endif; ?>
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

        <!-- ── Izquierda: mapa + descripción ── -->
        <div class="seat-map-wrapper">

            <!-- Mapa de asientos: va primero — lo que la persona debe ver y poder
                 usar de inmediato sin tener que pasar la descripción -->
            <?php if ( $has_map ) : ?>
            <p class="section-label"><?php echo $sale_mode === 'general' ? 'Mapa del evento' : 'Selecciona tus asientos'; ?></p>
            <div class="seat-map-container">
                <div id="ss-floor-tabs-frontend" style="display:flex;gap:4px;margin-bottom:8px;flex-wrap:wrap;align-items:center;min-height:24px;"></div>

                <!-- Wrapper propio para que los controles/hint se posicionen contra el
                     borde del CANVAS, no contra toda la tarjeta (que incluye la leyenda
                     debajo) — #ss-konva-container se vacía entero en cada cambio de piso,
                     así que los controles viven afuera de él, acá, para sobrevivir eso. -->
                <div class="ss-map-canvas-wrap">
                    <div id="ss-konva-container"></div>
                    <!-- Loader acotado SOLO al mapa (no a toda la página — ver el script al
                         final, escucha "ss:konva-ready"). Antes toda la página quedaba en
                         opacity:0 hasta window.load completo, lo que hacía que Chrome midiera
                         el LCP del hero como si tardara lo mismo que cargar Konva entero. -->
                    <div class="ss-map-loader" id="ss-map-loader">
                        <div class="tk-loader__spinner"></div>
                    </div>

                    <?php if ( $sale_mode !== 'general' ) : ?>
                    <!-- Zoom/pan ya existían (rueda, pellizco, arrastre) pero no eran
                         descubribles — estos botones + el hint los hacen visibles. -->
                    <div class="ss-map-controls">
                        <button type="button" class="ss-map-controls__btn" id="ss-map-zoom-in" aria-label="Acercar">+</button>
                        <button type="button" class="ss-map-controls__btn" id="ss-map-zoom-out" aria-label="Alejar">&minus;</button>
                        <button type="button" class="ss-map-controls__btn ss-map-controls__btn--fit" id="ss-map-zoom-fit" aria-label="Ver todo el mapa">
                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M8 3H5a2 2 0 00-2 2v3m18 0V5a2 2 0 00-2-2h-3m0 18h3a2 2 0 002-2v-3M3 16v3a2 2 0 002 2h3"/></svg>
                        </button>
                    </div>
                    <div class="ss-map-nav-hint" id="ss-map-nav-hint">
                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg>
                        Pellizca o usa los botones para moverte por el mapa
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Leyenda: Estado (relleno) + Categoría comercial (rayita inferior,
                     color = el que le asignaste a la zona en el builder), dos conceptos
                     independientes — ver ss-konva-renderer.js SEAT_COLORS / drawSeat(). -->
                <?php if ( $sale_mode !== 'general' ) : ?>
                <div class="legend">
                    <div class="legend-group">
                        <p class="legend-group__title">Estado</p>
                        <div class="legend-group__items">
                            <div class="legend-item">
                                <span class="legend-seat" style="background:<?php echo esc_attr( $color_available ); ?>;"></span>
                                <span>Disponible</span>
                            </div>
                            <div class="legend-item">
                                <span class="legend-seat" style="background:<?php echo esc_attr( $color_reserved ); ?>;"></span>
                                <span>Reservada</span>
                            </div>
                            <div class="legend-item">
                                <span class="legend-seat" style="background:<?php echo esc_attr( $color_sold ); ?>;border-color:rgba(0,0,0,.15);"></span>
                                <span>Vendida</span>
                            </div>
                            <div class="legend-item">
                                <span class="legend-seat" style="background:<?php echo esc_attr( $color_selected ); ?>;border-color:#E9D5FF;"></span>
                                <span>Seleccionada</span>
                            </div>
                        </div>
                    </div>

                    <?php if ( ! empty( $category_zones ) ) : ?>
                    <div class="legend-group">
                        <p class="legend-group__title">Zonas</p>
                        <div class="legend-group__items">
                            <?php foreach ( $category_zones as $zname => $accent ) : ?>
                            <div class="legend-item">
                                <span class="legend-seat legend-seat--accent" style="background:<?php echo esc_attr( $color_available ); ?>;--legend-accent:<?php echo esc_attr( $accent ); ?>;"></span>
                                <span><?php echo esc_html( $zname ); ?></span>
                            </div>
                            <?php endforeach; ?>
                            <?php if ( $has_uncategorized_zone ) : ?>
                            <div class="legend-item">
                                <span class="legend-seat" style="background:<?php echo esc_attr( $color_available ); ?>;"></span>
                                <span>Sin categoría</span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Descripción: debajo del mapa a propósito (ver orden arriba) -->
            <?php if ( $has_description ) : ?>
            <div class="description-block" id="ss-event-description">
                <p class="section-label">Acerca de este evento</p>
                <div class="description-block__body">
                    <?php echo wp_kses_post( $description ); ?>
                </div>
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

                <!-- Pasos de compra -->
                <ol class="ss-steps" aria-label="Pasos de compra">
                    <li class="ss-steps__item" data-step="0">
                        <span class="ss-steps__indicator">
                            <span class="ss-steps__num">1</span>
                            <svg class="ss-steps__check" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 13l4 4L19 7"/></svg>
                        </span>
                        <span class="ss-steps__label"><?php echo $sale_mode === 'seat' ? 'Asientos' : 'Entradas'; ?></span>
                    </li>
                    <li class="ss-steps__item" data-step="1">
                        <span class="ss-steps__indicator">
                            <span class="ss-steps__num">2</span>
                            <svg class="ss-steps__check" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 13l4 4L19 7"/></svg>
                        </span>
                        <span class="ss-steps__label">Resumen</span>
                    </li>
                    <li class="ss-steps__item" data-step="2">
                        <span class="ss-steps__indicator">
                            <span class="ss-steps__num">3</span>
                            <svg class="ss-steps__check" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 13l4 4L19 7"/></svg>
                        </span>
                        <span class="ss-steps__label">Pago</span>
                    </li>
                </ol>

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

                <!-- Preventa -->
                <?php if ( $is_presale && $presale_end_formatted ) : ?>
                <div class="event-card__presale-banner">
                    <strong>Preventa activa</strong>
                    <span>Termina el <?php echo esc_html( $presale_end_formatted ); ?></span>
                </div>
                <?php endif; ?>

                <!-- Entradas / zonas -->
                <?php if ( ! empty( $ticket_types ) ) : ?>
                <div class="ticket-section">
                    <p class="ticket-section__title">Entradas</p>

                    <?php if ( $availability_summary['total'] > 0 && ! $is_sold_out ) :
                        // 'percentage' del helper ya es % VENDIDO — la barra se llena a medida que se vende.
                        $sold_pct = (int) $availability_summary['percentage'];
                    ?>
                    <div class="event-card__avail-wrap">
                        <span class="event-card__avail-label"><strong><?php echo (int) $sold_pct; ?>%</strong> vendido</span>
                        <div class="event-card__avail-bar" role="progressbar"
                             aria-valuenow="<?php echo esc_attr( $sold_pct ); ?>"
                             aria-valuemin="0" aria-valuemax="100"
                             aria-label="Porcentaje vendido del evento">
                            <div class="event-card__avail-bar-fill" style="width:<?php echo esc_attr( $sold_pct ); ?>%"></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php foreach ( $ticket_types as $ticket ) :
                        $zone_sold_out  = ( (int) $ticket['available'] <= 0 );
                        $zone_accent    = $zone_colors_map[ trim( $ticket['zone'] ) ] ?? '';
                    ?>
                    <div class="ticket-type ss-ticket-form__zone-item<?php echo $zone_sold_out ? ' ticket-type--sold-out' : ''; ?><?php echo $zone_accent !== '' ? ' ticket-type--accent' : ''; ?>"
                         data-zone="<?php echo esc_attr( $ticket['zone'] ); ?>"
                         data-price="<?php echo esc_attr( $ticket['effective_price'] ); ?>"
                         data-available="<?php echo esc_attr( $ticket['available'] ); ?>"
                         <?php if ( $zone_accent !== '' ) : ?>style="--zone-accent:<?php echo esc_attr( $zone_accent ); ?>;"<?php endif; ?>>
                        <div class="ticket-type__info">
                            <span class="ticket-type__name">
                                <?php echo esc_html( $ticket['zone'] ); ?>
                                <?php if ( $ticket['is_presale_price'] ) : ?>
                                <span class="ticket-type__presale-tag">Preventa</span>
                                <?php endif; ?>
                            </span>
                            <?php if ( $zone_sold_out ) : ?>
                            <span class="ticket-type__avail ticket-type__avail--sold-out" data-zone="<?php echo esc_attr( $ticket['zone'] ); ?>">Agotado</span>
                            <?php else : ?>
                            <span class="ticket-type__avail" data-zone="<?php echo esc_attr( $ticket['zone'] ); ?>"><?php echo (int) $ticket['available']; ?> disponibles</span>
                            <?php endif; ?>
                        </div>
                        <div class="ticket-type__right">
                            <span class="ticket-type__price">
                                <?php if ( $ticket['is_presale_price'] && $ticket['price'] > $ticket['effective_price'] ) : ?>
                                    <span class="ticket-type__price--regular"><?php echo wp_kses_post( wc_price( $ticket['price'] ) ); ?></span>
                                <?php endif; ?>
                                <?php echo $ticket['effective_price'] > 0 ? wp_kses_post( wc_price( $ticket['effective_price'] ) ) : esc_html( 'Gratis' ); ?>
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

            <!-- Contador de preventa -->
            <?php if ( $is_presale && $presale_end_timestamp > 0 ) : ?>
            <div class="presale-countdown-card" data-presale-end="<?php echo esc_attr( $presale_end_timestamp ); ?>">
                <p class="presale-countdown-card__title">La preventa termina en</p>
                <div class="presale-countdown-card__grid" aria-live="polite">
                    <div class="presale-countdown-card__box">
                        <span class="presale-countdown-card__value" data-unit="days">—</span>
                        <span class="presale-countdown-card__label">Días</span>
                    </div>
                    <span class="presale-countdown-card__sep">:</span>
                    <div class="presale-countdown-card__box">
                        <span class="presale-countdown-card__value" data-unit="hours">—</span>
                        <span class="presale-countdown-card__label">Horas</span>
                    </div>
                    <span class="presale-countdown-card__sep">:</span>
                    <div class="presale-countdown-card__box">
                        <span class="presale-countdown-card__value" data-unit="minutes">—</span>
                        <span class="presale-countdown-card__label">Min</span>
                    </div>
                    <span class="presale-countdown-card__sep">:</span>
                    <div class="presale-countdown-card__box">
                        <span class="presale-countdown-card__value" data-unit="seconds">—</span>
                        <span class="presale-countdown-card__label">Seg</span>
                    </div>
                </div>
                <?php if ( $presale_end_formatted ) : ?>
                <p class="presale-countdown-card__end-date">Preventa hasta el <strong><?php echo esc_html( $presale_end_formatted ); ?></strong></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div><!-- .sidebar -->

    </div><!-- .main-layout -->

    <!-- ═══ COMPRA FLOTANTE: shortcut a la tarjeta de compra real, visible solo
         mientras esta última no está en pantalla (ver ss-ticket-form.js) ═══ -->
    <?php if ( $product_id && empty( $is_past ) && ! $is_sold_out ) : ?>
    <div class="ss-float-buy" id="ss-float-buy" aria-hidden="true">
        <ol class="ss-float-buy__steps" aria-hidden="true">
            <li class="ss-float-buy__dot" data-step="0"></li>
            <li class="ss-float-buy__dot" data-step="1"></li>
            <li class="ss-float-buy__dot" data-step="2"></li>
        </ol>
        <div class="ss-float-buy__info">
            <span class="ss-float-buy__count" id="ss-float-buy-count">0 entradas</span>
            <span class="ss-float-buy__total" id="ss-float-buy-total">$0</span>
        </div>
        <button type="button" class="ss-float-buy__btn" id="ss-float-buy-btn" disabled>
            Comprar entradas
        </button>
    </div>
    <?php endif; ?>

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
<?php if ( $is_presale && $presale_end_timestamp > 0 ) : ?>
<script>
(function(){
    var box = document.querySelector('.presale-countdown-card');
    if (!box) { return; }
    var daysEl    = box.querySelector('[data-unit="days"]');
    var hoursEl   = box.querySelector('[data-unit="hours"]');
    var minutesEl = box.querySelector('[data-unit="minutes"]');
    var secondsEl = box.querySelector('[data-unit="seconds"]');
    var endTime = <?php echo (int) $presale_end_timestamp; ?>;
    var timer = null;
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function tick() {
        var diff = endTime - Date.now();
        if (diff <= 0) {
            box.style.display = 'none';
            if (timer) { clearInterval(timer); }
            return;
        }
        var days = Math.floor(diff / 86400000);
        var hours = Math.floor((diff % 86400000) / 3600000);
        var minutes = Math.floor((diff % 3600000) / 60000);
        var seconds = Math.floor((diff % 60000) / 1000);
        daysEl.textContent = pad(days);
        hoursEl.textContent = pad(hours);
        minutesEl.textContent = pad(minutes);
        secondsEl.textContent = pad(seconds);
    }
    tick();
    timer = setInterval(tick, 1000);
})();
</script>
<?php endif; ?>
<script>
(function(){
    // El loader del mapa (no de toda la página) se apaga solo cuando el
    // renderer Konva avisa que terminó de dibujar (evento ya existente,
    // ss-konva-renderer.js). Si no hay mapa en este evento (modo no_map, o
    // sale_mode sin Konva encolado), nunca llega el evento — se oculta igual
    // a los 2s como red de seguridad.
    var mapLoader = document.getElementById('ss-map-loader');
    if (mapLoader) {
        var hideMapLoader = function () { mapLoader.style.display = 'none'; };
        document.addEventListener('ss:konva-ready', hideMapLoader, { once: true });
        setTimeout(hideMapLoader, 2000);
    }
})();
</script>
