<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SS_Event_Admin — Interfaz unificada de administración para ss_event.
 *
 * Reemplaza los metaboxes separados (details, media, seating config)
 * con un solo metabox con pestañas:
 *   1. Información (descripción, hero, galería)
 *   2. Fecha (date + time separados)
 *   3. Ubicación (venue, ciudad, dirección)
 *   4. Tickets (tabla editable de tipos de boleta)
 *   5. Mapa (builder visual Konva — reutiliza render existente)
 */
class SS_Event_Admin {

    public static function init(): void {
        add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'remove_old_metaboxes' ), 99 );
        add_action( 'save_post_ss_event', array( __CLASS__, 'save' ), 15, 2 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }

    /** Remove old separate metaboxes for ss_event — we absorb them. */
    public static function remove_old_metaboxes(): void {
        remove_meta_box( 'ss_event_details', 'ss_event', 'normal' );
        remove_meta_box( 'ss_event_media', 'ss_event', 'normal' );
        remove_meta_box( 'ss_seating_config', 'ss_event', 'normal' );
    }

    public static function register(): void {
        add_meta_box(
            'ss_event_admin',
            'Configurar Evento',
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

        wp_enqueue_style(
            'ss-event-admin',
            $plugin_url . 'assets/css/ss-event-admin.css',
            array(),
            filemtime( $plugin_path . 'assets/css/ss-event-admin.css' )
        );

        wp_enqueue_script(
            'ss-event-admin',
            $plugin_url . 'assets/js/ss-event-admin.js',
            array( 'jquery', 'wp-util' ),
            filemtime( $plugin_path . 'assets/js/ss-event-admin.js' ),
            true
        );

        // Pasar ubicaciones y organizadores guardados al JS del formulario
        wp_localize_script( 'ss-event-admin', 'ssRegistros', array(
            'locations'  => array_values( SS_Settings::get_locations() ),
            'organizers' => array_values( SS_Settings::get_organizers() ),
        ) );
    }

    // ─────────────────────────────────────────────────────────────────
    //  RENDER
    // ─────────────────────────────────────────────────────────────────

    public static function render( \WP_Post $post ): void {
        wp_nonce_field( 'ss_event_admin_save', 'ss_event_admin_nonce' );
        ?>
        <div class="ss-admin-tabs">
            <nav class="ss-admin-tabs__nav">
                <button type="button" class="ss-admin-tabs__btn ss-active" data-tab="info">
                    <span class="dashicons dashicons-info-outline"></span> Información
                </button>
                <button type="button" class="ss-admin-tabs__btn" data-tab="fecha">
                    <span class="dashicons dashicons-calendar-alt"></span> Fecha
                </button>
                <button type="button" class="ss-admin-tabs__btn" data-tab="ubicacion">
                    <span class="dashicons dashicons-location"></span> Ubicación
                </button>
                <button type="button" class="ss-admin-tabs__btn" data-tab="organizador">
                    <span class="dashicons dashicons-businessperson"></span> Organizador
                </button>
                <button type="button" class="ss-admin-tabs__btn" data-tab="tickets">
                    <span class="dashicons dashicons-tickets-alt"></span> Tickets
                </button>
                <button type="button" class="ss-admin-tabs__btn" data-tab="mapa">
                    <span class="dashicons dashicons-layout"></span> Mapa
                </button>
                <button type="button" class="ss-admin-tabs__btn" data-tab="descuentos">
                    <span class="dashicons dashicons-tag"></span> Descuentos
                </button>
                <button type="button" class="ss-admin-tabs__btn" data-tab="difusion">
                    <span class="dashicons dashicons-megaphone"></span> Difusión
                </button>
            </nav>

            <div class="ss-admin-tabs__panels">
                <?php
                self::render_tab_info( $post );
                self::render_tab_fecha( $post );
                self::render_tab_ubicacion( $post );
                self::render_tab_organizador( $post );
                self::render_tab_tickets( $post );
                self::render_tab_mapa( $post );
                SS_Difusion_Admin::render_tab( $post );
                ?>
            </div>
        </div>
        <?php
    }

    // ── Tab: Información ─────────────────────────────────────────────

    private static function render_tab_info( \WP_Post $post ): void {
        $hero_id  = (int) get_post_meta( $post->ID, '_ss_event_hero', true );
        $gallery  = get_post_meta( $post->ID, '_ss_event_gallery', true );
        $gallery  = is_array( $gallery ) ? array_map( 'intval', $gallery ) : array();
        $hero_url = $hero_id ? wp_get_attachment_image_url( $hero_id, 'medium' ) : '';
        ?>
        <div class="ss-admin-tabs__panel ss-active" data-tab="info">

            <!-- Descripción -->
            <div class="ss-admin-field">
                <label class="ss-admin-field__label">Descripción del evento</label>
                <?php
                wp_editor( $post->post_content, 'ss_event_content', array(
                    'textarea_name' => 'ss_event_content',
                    'media_buttons' => true,
                    'textarea_rows' => 10,
                    'teeny'         => false,
                    'quicktags'     => true,
                ) );
                ?>
            </div>

            <!-- Hero -->
            <div class="ss-admin-field">
                <label class="ss-admin-field__label">Imagen principal</label>
                <div class="ss-admin-hero-preview" id="ss-hero-preview">
                    <?php if ( $hero_url ) : ?>
                        <img src="<?php echo esc_url( $hero_url ); ?>" alt="">
                    <?php endif; ?>
                </div>
                <input type="hidden" name="ss_event_hero" id="ss-hero-input"
                       value="<?php echo esc_attr( $hero_id ?: '' ); ?>">
                <button type="button" class="button" id="ss-hero-select">Seleccionar imagen</button>
                <button type="button" class="button ss-admin-remove-btn<?php echo $hero_id ? '' : ' hidden'; ?>"
                        id="ss-hero-remove">Quitar</button>
            </div>

            <!-- Galería -->
            <div class="ss-admin-field">
                <label class="ss-admin-field__label">Galería de imágenes</label>
                <div class="ss-admin-gallery" id="ss-gallery-container">
                    <?php foreach ( $gallery as $att_id ) :
                        $thumb = wp_get_attachment_image_url( $att_id, 'thumbnail' );
                        if ( ! $thumb ) { continue; }
                    ?>
                    <div class="ss-admin-gallery__item" data-id="<?php echo esc_attr( $att_id ); ?>">
                        <img src="<?php echo esc_url( $thumb ); ?>" alt="">
                        <button type="button" class="ss-admin-gallery__remove" title="Quitar">&times;</button>
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

    // ── Tab: Fecha ───────────────────────────────────────────────────

    private static function render_tab_fecha( \WP_Post $post ): void {
        $date            = get_post_meta( $post->ID, '_ss_event_date', true );
        $time            = get_post_meta( $post->ID, '_ss_event_time', true );
        $presale_end     = get_post_meta( $post->ID, '_ss_presale_end_date', true );

        // Backward compat: try old _ss_event_start_datetime
        if ( ! $date ) {
            $old = get_post_meta( $post->ID, '_ss_event_start_datetime', true );
            if ( $old ) {
                $ts = strtotime( $old );
                if ( $ts ) {
                    $date = gmdate( 'Y-m-d', $ts );
                    $time = $time ?: gmdate( 'H:i', $ts );
                }
            }
        }
        ?>
        <div class="ss-admin-tabs__panel" data-tab="fecha">
            <div class="ss-admin-field-row">
                <div class="ss-admin-field">
                    <label class="ss-admin-field__label" for="ss-event-date">Fecha</label>
                    <input type="date" id="ss-event-date" name="ss_event_date"
                           value="<?php echo esc_attr( $date ); ?>">
                </div>
                <div class="ss-admin-field">
                    <label class="ss-admin-field__label" for="ss-event-time">Hora</label>
                    <input type="time" id="ss-event-time" name="ss_event_time"
                           value="<?php echo esc_attr( $time ); ?>"
                           step="300">
                </div>
            </div>
            <p class="description">
                Zona horaria de WordPress: <strong><?php echo esc_html( wp_timezone_string() ); ?></strong>
            </p>
            <div class="ss-admin-field-row">
                <div class="ss-admin-field">
                    <label class="ss-admin-field__label" for="ss-presale-end-date">Fin de preventa (opcional)</label>
                    <input type="date" id="ss-presale-end-date" name="ss_presale_end_date"
                           value="<?php echo esc_attr( $presale_end ); ?>">
                </div>
            </div>
            <p class="description">
                Antes de esta fecha se cobra el precio de preventa de cada tipo de boleta (pestaña Tickets). Dejar vacío para no usar preventa.
            </p>
        </div>
        <?php
    }

    // ── Tab: Ubicación ───────────────────────────────────────────────

    private static function render_tab_ubicacion( \WP_Post $post ): void {
        $venue  = get_post_meta( $post->ID, '_ss_location_venue', true );
        $city   = get_post_meta( $post->ID, '_ss_location_city', true );
        $street = get_post_meta( $post->ID, '_ss_location_street', true );

        // Backward compat
        if ( ! $venue ) {
            $venue = get_post_meta( $post->ID, '_ss_location', true );
        }
        ?>
        <div class="ss-admin-tabs__panel" data-tab="ubicacion">
            <div class="ss-admin-field">
                <label class="ss-admin-field__label" for="ss-loc-preset">Seleccionar ubicación guardada</label>
                <select id="ss-loc-preset" data-type="location" style="max-width:400px">
                    <option value="">— Escribir manualmente —</option>
                </select>
                <p class="description">Al seleccionar, los campos se pre-llenan. Puedes editarlos libremente.</p>
            </div>
            <div class="ss-admin-field">
                <label class="ss-admin-field__label" for="ss-loc-venue">Venue / Lugar</label>
                <input type="text" id="ss-loc-venue" name="ss_location_venue"
                       value="<?php echo esc_attr( $venue ); ?>" class="widefat">
            </div>
            <div class="ss-admin-field-row">
                <div class="ss-admin-field" style="flex:2">
                    <label class="ss-admin-field__label" for="ss-loc-street">Dirección</label>
                    <input type="text" id="ss-loc-street" name="ss_location_street"
                           value="<?php echo esc_attr( $street ); ?>" class="widefat">
                </div>
                <div class="ss-admin-field" style="flex:1">
                    <label class="ss-admin-field__label" for="ss-loc-city">Ciudad</label>
                    <input type="text" id="ss-loc-city" name="ss_location_city"
                           value="<?php echo esc_attr( $city ); ?>" class="widefat">
                </div>
            </div>
        </div>
        <?php
    }

    // ── Tab: Organizador ────────────────────────────────────────────

    private static function render_tab_organizador( \WP_Post $post ): void {
        $org_name  = get_post_meta( $post->ID, '_ss_organizer_name', true );
        $org_email = get_post_meta( $post->ID, '_ss_organizer_email', true );
        $org_phone = get_post_meta( $post->ID, '_ss_organizer_phone', true );
        ?>
        <div class="ss-admin-tabs__panel" data-tab="organizador">
            <div class="ss-admin-field">
                <label class="ss-admin-field__label" for="ss-org-preset">Seleccionar organizador guardado</label>
                <select id="ss-org-preset" data-type="organizer" style="max-width:400px">
                    <option value="">— Escribir manualmente —</option>
                </select>
                <p class="description">Al seleccionar, los campos se pre-llenan. Puedes editarlos libremente.</p>
            </div>
            <div class="ss-admin-field">
                <label class="ss-admin-field__label" for="ss-org-name">Nombre del organizador</label>
                <input type="text" id="ss-org-name" name="ss_organizer_name"
                       value="<?php echo esc_attr( $org_name ); ?>" class="widefat"
                       placeholder="Ej: Mi Organización">
            </div>
            <div class="ss-admin-field-row">
                <div class="ss-admin-field">
                    <label class="ss-admin-field__label" for="ss-org-email">Email</label>
                    <input type="email" id="ss-org-email" name="ss_organizer_email"
                           value="<?php echo esc_attr( $org_email ); ?>" class="widefat">
                </div>
                <div class="ss-admin-field">
                    <label class="ss-admin-field__label" for="ss-org-phone">Teléfono</label>
                    <input type="text" id="ss-org-phone" name="ss_organizer_phone"
                           value="<?php echo esc_attr( $org_phone ); ?>" class="widefat">
                </div>
            </div>
        </div>
        <?php
    }

    // ── Tab: Tickets ─────────────────────────────────────────────────

    private static function render_tab_tickets( \WP_Post $post ): void {
        $ticket_types = get_post_meta( $post->ID, '_ss_ticket_types', true );
        if ( ! is_array( $ticket_types ) ) {
            $ticket_types = array();
        }
        ?>
        <div class="ss-admin-tabs__panel" data-tab="tickets">
            <p class="description" style="margin-bottom:12px;">
                Define los tipos de boleta, precio y capacidad. La capacidad se actualiza automáticamente
                al guardar el mapa de asientos.
            </p>
            <table class="ss-admin-ticket-table" id="ss-ticket-table">
                <thead>
                    <tr>
                        <th>Zona / Tipo <span class="ss-help" title="Debe coincidir exactamente con el nombre de zona definido en el mapa (ej: VIP, GENERAL). Las mayúsculas importan.">?</span></th>
                        <th>Precio <span class="ss-help" title="Precio en la moneda configurada en WooCommerce. Deja 0 si el evento es gratuito.">?</span></th>
                        <th>Precio Preventa <span class="ss-help" title="Opcional. Se cobra este precio antes de la fecha de fin de preventa (pestaña Fecha). Deja 0 para no usar preventa en este tipo.">?</span></th>
                        <th>Capacidad <span class="ss-help" title="Se calcula automáticamente al guardar el mapa de asientos. Si no hay mapa, ingrésala manualmente.">?</span></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="ss-ticket-tbody">
                    <?php if ( ! empty( $ticket_types ) ) :
                        foreach ( $ticket_types as $i => $tt ) : ?>
                    <tr class="ss-ticket-row">
                        <td>
                            <input type="text" name="ss_tt[<?php echo $i; ?>][zone]"
                                   value="<?php echo esc_attr( $tt['zone'] ?? '' ); ?>"
                                   placeholder="Ej: VIP" class="widefat">
                        </td>
                        <td>
                            <input type="number" name="ss_tt[<?php echo $i; ?>][price]"
                                   value="<?php echo esc_attr( $tt['price'] ?? 0 ); ?>"
                                   min="0" step="100" class="widefat">
                        </td>
                        <td>
                            <input type="number" name="ss_tt[<?php echo $i; ?>][presale_price]"
                                   value="<?php echo esc_attr( $tt['presale_price'] ?? 0 ); ?>"
                                   min="0" step="100" class="widefat">
                        </td>
                        <td>
                            <input type="number" name="ss_tt[<?php echo $i; ?>][capacity]"
                                   value="<?php echo esc_attr( $tt['capacity'] ?? 0 ); ?>"
                                   min="0" class="widefat">
                        </td>
                        <td>
                            <button type="button" class="button ss-ticket-remove" title="Eliminar">&times;</button>
                        </td>
                    </tr>
                    <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
            <button type="button" class="button" id="ss-ticket-add" style="margin-top:8px;">
                + Agregar tipo de boleta
            </button>
        </div>
        <?php
    }

    // ── Tab: Mapa ────────────────────────────────────────────────────

    private static function render_tab_mapa( \WP_Post $post ): void {
        ?>
        <div class="ss-admin-tabs__panel" data-tab="mapa">
            <?php
            // ── Importar mapa de otro evento ─────────────────────────────
            $event_id  = $post->ID;
            $sale_mode = get_post_meta( $event_id, '_ss_sale_mode', true );
            $events_with_layout = get_posts( array(
                'post_type'      => 'ss_event',
                'post_status'    => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => -1,
                'exclude'        => array( $event_id ),
                'meta_query'     => array( array(
                    'key'     => '_ss_layout',
                    'value'   => '',
                    'compare' => '!=',
                ) ),
                'orderby'        => 'title',
                'order'          => 'ASC',
            ) );
            // Mostrar siempre el bloque (aunque no haya eventos con layout, para debug)
            if ( true ) :
                $copy_nonce = wp_create_nonce( 'ss_copy_layout' );
            ?>
            <div class="ss-copy-layout-box ss-mapa-builder-block" data-show-display="flex" style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:12px 16px;margin-bottom:16px;display:<?php echo $sale_mode === 'no_map' ? 'none' : 'flex'; ?>;align-items:center;gap:10px;flex-wrap:wrap;">
                <strong style="margin-right:4px;">Importar mapa de: <span class="ss-help" title="Copia el diseño de filas y zonas de otro evento existente. Útil cuando la sala es siempre la misma. El mapa importado reemplaza el actual.">?</span></strong>
                <?php if ( empty( $events_with_layout ) ) : ?>
                    <span style="color:#777;font-size:13px;">No hay otros eventos con mapa guardado.</span>
                <?php else : ?>
                <select id="ss-copy-layout-source" style="min-width:200px;">
                    <option value="">— Seleccionar evento —</option>
                    <?php foreach ( $events_with_layout as $ev ) : ?>
                    <option value="<?php echo (int) $ev->ID; ?>"><?php echo esc_html( $ev->post_title ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="ss-copy-layout-btn" class="button">Importar mapa</button>
                <span id="ss-copy-layout-msg" style="color:#d63638;font-size:13px;"></span>
                <?php endif; ?>
            </div>
            <script>
            (function() {
                var btn    = document.getElementById('ss-copy-layout-btn');
                var select = document.getElementById('ss-copy-layout-source');
                var msg    = document.getElementById('ss-copy-layout-msg');
                if (!btn) return;
                btn.addEventListener('click', function() {
                    var sourceId = select.value;
                    if (!sourceId) { msg.textContent = 'Selecciona un evento origen.'; return; }
                    if (!confirm('¿Importar el mapa de ese evento? Esto reemplazará el mapa actual del evento.')) return;
                    btn.disabled = true;
                    btn.textContent = 'Importando...';
                    msg.textContent = '';
                    var data = new FormData();
                    data.append('action',    'ss_copy_layout');
                    data.append('nonce',     <?php echo wp_json_encode( $copy_nonce ); ?>);
                    data.append('source_id', sourceId);
                    data.append('dest_id',   <?php echo (int) $event_id; ?>);
                    fetch(ajaxurl, { method: 'POST', body: data })
                        .then(function(r) { return r.json(); })
                        .then(function(r) {
                            if (r.success) {
                                location.reload();
                            } else {
                                msg.textContent = r.data || 'Error al importar.';
                                btn.disabled = false;
                                btn.textContent = 'Importar mapa';
                            }
                        })
                        .catch(function() {
                            msg.textContent = 'Error de conexión.';
                            btn.disabled = false;
                            btn.textContent = 'Importar mapa';
                        });
                });
            }());
            </script>
            <?php endif; ?>

            <?php
            // Reutilizar el render existente del builder
            if ( function_exists( 'ss_seating_metabox_render' ) ) {
                ss_seating_metabox_render( $post );
            }
            ?>
        </div>

        <div class="ss-admin-tabs__panel" data-tab="descuentos">
            <?php
            $gd_enabled = get_post_meta( $post->ID, '_ss_group_discount_enabled', true );
            $gd_min_qty = (int) get_post_meta( $post->ID, '_ss_group_discount_min_qty', true );
            $gd_pct     = (int) get_post_meta( $post->ID, '_ss_group_discount_pct', true );
            $cd_enabled     = get_post_meta( $post->ID, '_ss_couple_discount_enabled', true );
            $cd_type        = get_post_meta( $post->ID, '_ss_couple_discount_type', true );
            $cd_type        = $cd_type === 'fixed_price' ? 'fixed_price' : 'percentage';
            $cd_pct         = (int) get_post_meta( $post->ID, '_ss_couple_discount_pct', true );
            $cd_fixed_price = (float) get_post_meta( $post->ID, '_ss_couple_discount_fixed_price', true );
            $ly_enabled = SS_FIDELIZACION_ENABLED ? get_post_meta( $post->ID, '_ss_loyalty_enabled', true ) : '0';
            if ( $gd_min_qty <= 0 ) { $gd_min_qty = 5; }
            ?>
            <h3 style="margin-top:0">Descuento por grupo</h3>
            <table class="form-table">
                <tr>
                    <th>Activar descuento grupal</th>
                    <td>
                        <label>
                            <input type="checkbox" name="ss_group_discount_enabled" value="1" <?php checked( $gd_enabled, '1' ); ?>>
                            Activar para este evento
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Cantidad mínima de boletas</th>
                    <td>
                        <input type="number" name="ss_group_discount_min_qty" value="<?php echo esc_attr( $gd_min_qty ); ?>" min="2" max="999" style="width:80px;">
                        <p class="description">Número de boletas a partir del cual aplica el descuento.</p>
                    </td>
                </tr>
                <tr>
                    <th>Porcentaje de descuento (%)</th>
                    <td>
                        <input type="number" name="ss_group_discount_pct" value="<?php echo esc_attr( $gd_pct ); ?>" min="0" max="100" style="width:80px;">
                    </td>
                </tr>
            </table>

            <hr>
            <h3>Descuento por pareja</h3>
            <table class="form-table">
                <tr>
                    <th>Activar descuento por pareja</th>
                    <td>
                        <label>
                            <input type="checkbox" name="ss_couple_discount_enabled" value="1" <?php checked( $cd_enabled, '1' ); ?>>
                            Activar para este evento
                        </label>
                        <p class="description">Aplica automáticamente a partir de 2 boletas.</p>
                    </td>
                </tr>
                <tr>
                    <th>Tipo de descuento</th>
                    <td>
                        <label style="margin-right:16px;">
                            <input type="radio" id="ss-couple-type-pct" name="ss_couple_discount_type" value="percentage" <?php checked( $cd_type, 'percentage' ); ?>>
                            Porcentaje
                        </label>
                        <label>
                            <input type="radio" id="ss-couple-type-fixed" name="ss_couple_discount_type" value="fixed_price" <?php checked( $cd_type, 'fixed_price' ); ?>>
                            Precio fijo por pareja
                        </label>
                        <span class="ss-help" title="El precio fijo solo se aplica automáticamente cuando se compran EXACTAMENTE 2 boletas de la MISMA zona/tipo. Si se compran 3 o más boletas, o boletas de zonas distintas, el precio fijo no aplica (podría aplicar en su lugar el descuento grupal si está configurado).">?</span>
                    </td>
                </tr>
                <tr id="ss-couple-pct-row" style="<?php echo $cd_type !== 'percentage' ? 'display:none;' : ''; ?>">
                    <th>Porcentaje de descuento (%)</th>
                    <td>
                        <input type="number" name="ss_couple_discount_pct" value="<?php echo esc_attr( $cd_pct ); ?>" min="0" max="100" style="width:80px;">
                    </td>
                </tr>
                <tr id="ss-couple-fixed-row" style="<?php echo $cd_type !== 'fixed_price' ? 'display:none;' : ''; ?>">
                    <th>Precio fijo por pareja</th>
                    <td>
                        <input type="number" name="ss_couple_discount_fixed_price" value="<?php echo esc_attr( $cd_fixed_price ); ?>" min="0" step="100" style="width:120px;">
                        <p class="description">Precio total por las 2 boletas juntas (reemplaza la suma de sus precios individuales).</p>
                    </td>
                </tr>
            </table>
            <script>
            (function(){
                var pctRadio = document.getElementById('ss-couple-type-pct');
                var fixedRadio = document.getElementById('ss-couple-type-fixed');
                var pctRow = document.getElementById('ss-couple-pct-row');
                var fixedRow = document.getElementById('ss-couple-fixed-row');
                function toggle() {
                    if (!pctRow || !fixedRow) { return; }
                    pctRow.style.display = fixedRadio && fixedRadio.checked ? 'none' : '';
                    fixedRow.style.display = fixedRadio && fixedRadio.checked ? '' : 'none';
                }
                if (pctRadio) { pctRadio.addEventListener('change', toggle); }
                if (fixedRadio) { fixedRadio.addEventListener('change', toggle); }
            })();
            </script>

            <?php if ( SS_FIDELIZACION_ENABLED ) : ?>
            <hr>
            <h3>Sistema de fidelización</h3>
            <table class="form-table">
                <tr>
                    <th>Activar fidelización</th>
                    <td>
                        <label>
                            <input type="checkbox" name="ss_loyalty_enabled" value="1" <?php checked( $ly_enabled, '1' ); ?>>
                            Este evento participa en el sistema de fidelización
                        </label>
                        <p class="description">Si está activo, las compras de este evento acumulan asistencia y los clientes con descuento de fidelización lo podrán aplicar aquí.</p>
                    </td>
                </tr>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────
    //  SAVE
    // ─────────────────────────────────────────────────────────────────

    public static function save( int $post_id, \WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! isset( $_POST['ss_event_admin_nonce'] )
             || ! wp_verify_nonce( $_POST['ss_event_admin_nonce'], 'ss_event_admin_save' ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // ── Descripción → post_content ──
        if ( isset( $_POST['ss_event_content'] ) ) {
            $content = wp_kses_post( wp_unslash( $_POST['ss_event_content'] ) );
            // Avoid infinite loop
            remove_action( 'save_post_ss_event', array( __CLASS__, 'save' ), 15 );
            wp_update_post( array(
                'ID'           => $post_id,
                'post_content' => $content,
            ) );
            add_action( 'save_post_ss_event', array( __CLASS__, 'save' ), 15, 2 );
        }

        // ── Hero image ──
        $hero = isset( $_POST['ss_event_hero'] ) ? absint( $_POST['ss_event_hero'] ) : 0;
        if ( $hero > 0 ) {
            update_post_meta( $post_id, '_ss_event_hero', $hero );
        } else {
            delete_post_meta( $post_id, '_ss_event_hero' );
        }

        // ── Gallery ──
        $gallery_raw = isset( $_POST['ss_event_gallery'] ) ? sanitize_text_field( $_POST['ss_event_gallery'] ) : '';
        if ( $gallery_raw !== '' ) {
            $ids = array_values( array_filter( array_map( 'absint', explode( ',', $gallery_raw ) ) ) );
            update_post_meta( $post_id, '_ss_event_gallery', $ids );
        } else {
            delete_post_meta( $post_id, '_ss_event_gallery' );
        }

        // ── Date + Time (separate fields) ──
        $date = isset( $_POST['ss_event_date'] ) ? sanitize_text_field( $_POST['ss_event_date'] ) : '';
        $time = isset( $_POST['ss_event_time'] ) ? sanitize_text_field( $_POST['ss_event_time'] ) : '';

        self::update_or_delete( $post_id, '_ss_event_date', $date );
        self::update_or_delete( $post_id, '_ss_event_time', $time );

        // ── Fin de preventa ──
        $presale_end = isset( $_POST['ss_presale_end_date'] ) ? sanitize_text_field( $_POST['ss_presale_end_date'] ) : '';
        self::update_or_delete( $post_id, '_ss_presale_end_date', $presale_end );

        // Also write combined datetime for legacy compat
        if ( $date ) {
            $combined = $time ? "$date $time" : "$date 00:00";
            update_post_meta( $post_id, '_ss_event_start_datetime', $combined );
        } else {
            delete_post_meta( $post_id, '_ss_event_start_datetime' );
        }

        // ── Location ──
        $venue  = isset( $_POST['ss_location_venue'] )  ? sanitize_text_field( $_POST['ss_location_venue'] )  : '';
        $city   = isset( $_POST['ss_location_city'] )   ? sanitize_text_field( $_POST['ss_location_city'] )   : '';
        $street = isset( $_POST['ss_location_street'] ) ? sanitize_text_field( $_POST['ss_location_street'] ) : '';

        self::update_or_delete( $post_id, '_ss_location_venue', $venue );
        self::update_or_delete( $post_id, '_ss_location_city', $city );
        self::update_or_delete( $post_id, '_ss_location_street', $street );
        // Legacy key
        self::update_or_delete( $post_id, '_ss_location', $venue );

        // ── Organizer ──
        $org_name  = isset( $_POST['ss_organizer_name'] )  ? sanitize_text_field( $_POST['ss_organizer_name'] )  : '';
        $org_email = isset( $_POST['ss_organizer_email'] ) ? sanitize_email( $_POST['ss_organizer_email'] )      : '';
        $org_phone = isset( $_POST['ss_organizer_phone'] ) ? sanitize_text_field( $_POST['ss_organizer_phone'] ) : '';

        self::update_or_delete( $post_id, '_ss_organizer_name', $org_name );
        self::update_or_delete( $post_id, '_ss_organizer_email', $org_email );
        self::update_or_delete( $post_id, '_ss_organizer_phone', $org_phone );

        // ── Descuento grupal ──
        $gd_enabled = ! empty( $_POST['ss_group_discount_enabled'] ) ? '1' : '0';
        $gd_min_qty = isset( $_POST['ss_group_discount_min_qty'] ) ? max( 2, absint( $_POST['ss_group_discount_min_qty'] ) ) : 5;
        $gd_pct     = isset( $_POST['ss_group_discount_pct'] ) ? min( 100, max( 0, absint( $_POST['ss_group_discount_pct'] ) ) ) : 0;
        update_post_meta( $post_id, '_ss_group_discount_enabled', $gd_enabled );
        update_post_meta( $post_id, '_ss_group_discount_min_qty', $gd_min_qty );
        update_post_meta( $post_id, '_ss_group_discount_pct', $gd_pct );

        // ── Descuento por pareja ──
        $cd_enabled     = ! empty( $_POST['ss_couple_discount_enabled'] ) ? '1' : '0';
        $cd_type        = isset( $_POST['ss_couple_discount_type'] ) && $_POST['ss_couple_discount_type'] === 'fixed_price' ? 'fixed_price' : 'percentage';
        $cd_pct         = isset( $_POST['ss_couple_discount_pct'] ) ? min( 100, max( 0, absint( $_POST['ss_couple_discount_pct'] ) ) ) : 0;
        $cd_fixed_price = isset( $_POST['ss_couple_discount_fixed_price'] ) ? max( 0, (float) $_POST['ss_couple_discount_fixed_price'] ) : 0;
        update_post_meta( $post_id, '_ss_couple_discount_enabled', $cd_enabled );
        update_post_meta( $post_id, '_ss_couple_discount_type', $cd_type );
        update_post_meta( $post_id, '_ss_couple_discount_pct', $cd_pct );
        update_post_meta( $post_id, '_ss_couple_discount_fixed_price', $cd_fixed_price );

        // ── Fidelización ──
        if ( SS_FIDELIZACION_ENABLED ) {
            $ly_enabled = ! empty( $_POST['ss_loyalty_enabled'] ) ? '1' : '0';
            update_post_meta( $post_id, '_ss_loyalty_enabled', $ly_enabled );
        }

        // ── Ticket types ──
        $raw_tt = isset( $_POST['ss_tt'] ) && is_array( $_POST['ss_tt'] ) ? $_POST['ss_tt'] : array();
        $ticket_types = array();
        foreach ( $raw_tt as $tt ) {
            $zone = isset( $tt['zone'] ) ? sanitize_text_field( $tt['zone'] ) : '';
            if ( $zone === '' ) {
                continue;
            }
            $ticket_types[] = array(
                'zone'          => $zone,
                'price'         => isset( $tt['price'] ) ? max( 0, (float) $tt['price'] ) : 0,
                'presale_price' => isset( $tt['presale_price'] ) ? max( 0, (float) $tt['presale_price'] ) : 0,
                'capacity'      => isset( $tt['capacity'] ) ? max( 0, (int) $tt['capacity'] ) : 0,
            );
        }
        update_post_meta( $post_id, '_ss_ticket_types', $ticket_types );
    }

    private static function update_or_delete( int $post_id, string $key, string $value ): void {
        if ( $value !== '' ) {
            update_post_meta( $post_id, $key, $value );
        } else {
            delete_post_meta( $post_id, $key );
        }
    }
}
