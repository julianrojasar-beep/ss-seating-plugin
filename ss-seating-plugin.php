<?php
/**
 * Plugin Name: SS Seating
 * Plugin URI: https://tusitio.com
 * Description: Sistema de selección de sillas y venta de boletas con QR para eventos.
 * Version: 1.3.10
 * Author: Julian Rojas
 * Author URI: https://tusitio.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// ── Feature flags ─────────────────────────────────────────────────────────────
// wp-config.php puede forzar el valor con define('SS_FIDELIZACION_ENABLED', true/false).
// Si no está definido, lee la opción guardada en la DB desde SS Seating → Configuración → Módulos.
if ( ! defined( 'SS_FIDELIZACION_ENABLED' ) ) {
    define( 'SS_FIDELIZACION_ENABLED', get_option( 'ss_fidelizacion_enabled', '0' ) === '1' );
}

// ── Auto-updater via GitHub Releases ──────────────────────────────────────────
$ss_puc = plugin_dir_path( __FILE__ ) . 'lib/plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $ss_puc ) ) {
    require_once $ss_puc;
    $ss_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/julianrojasar-beep/ss-seating-plugin/',
        __FILE__,
        'ss-seating-plugin'
    );
    $ss_checker->setBranch( 'main' );
}

// Shortcode para probar el plugin (solo debugging manual)
function ss_test_shortcode($atts = array()) {
    return '<div style="padding:20px; background:#111; color:#fff; text-align:center;">
                <h2>SS Seating Activo</h2>
            </div>';
}

add_shortcode('ss_test', 'ss_test_shortcode');
if (!defined('ABSPATH')) {
    exit; // Seguridad: evita acceso directo
}

// Debug flag — activar en wp-config.php con:
// define('SS_SEATING_DEBUG', true);
// Nunca activar en producción.
if ( ! defined( 'SS_SEATING_DEBUG' ) ) {
    define( 'SS_SEATING_DEBUG', false );
}

// ── Event layer: CPT, Service ─────────────────────────────────────────────────
require_once plugin_dir_path( __FILE__ ) . 'includes/events/class-ss-event-cpt.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/events/class-ss-event-service.php';

// ── Admin: Settings + Metaboxes ──────────────────────────────────────────────
require_once plugin_dir_path( __FILE__ ) . 'includes/admin/class-ss-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/admin/class-ss-event-admin.php';

// ── Frontend: Ticket Form ────────────────────────────────────────────────────
require_once plugin_dir_path( __FILE__ ) . 'includes/frontend/class-ss-ticket-form.php';

// ── Difusión: Centro de Difusión ─────────────────────────────────────────────
require_once plugin_dir_path( __FILE__ ) . 'includes/difusion/class-ss-difusion.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/difusion/class-ss-difusion-admin.php';

// ── API REST: reportes para el dashboard externo ─────────────────────────────
require_once plugin_dir_path( __FILE__ ) . 'includes/api/class-ss-rest-reports.php';

if ( SS_FIDELIZACION_ENABLED ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/loyalty/class-ss-group-discount.php';
    require_once plugin_dir_path( __FILE__ ) . 'includes/loyalty/class-ss-loyalty.php';
}

add_action( 'init', array( 'SS_Event_CPT', 'register' ) );
SS_Settings::init();
SS_Event_Admin::init();
SS_Ticket_Form::init();
SS_Difusion::init();
SS_REST_Reports::init();
if ( SS_FIDELIZACION_ENABLED ) {
    SS_Loyalty::init();
}

// ── Menú admin: Fidelización ─────────────────────────────────────────────────
if ( SS_FIDELIZACION_ENABLED ) {
    add_action( 'admin_menu', 'ss_loyalty_add_admin_menu', 25 );
    function ss_loyalty_add_admin_menu(): void {
        add_submenu_page(
            'ss-seating-dashboard',
            'Fidelización — SS Seating',
            'Fidelización',
            'manage_options',
            'ss-loyalty',
            array( 'SS_Loyalty', 'render_admin_page' )
        );
    }
}

// ── Pasar config de grupo al Box Office JS ────────────────────────────────────
if ( SS_FIDELIZACION_ENABLED ) {
    add_filter( 'ss_boxoffice_event_data', 'ss_boxoffice_add_discount_data', 10, 2 );
    function ss_boxoffice_add_discount_data( array $data, int $event_id ): array {
        $data['groupDiscount'] = SS_Group_Discount::get_for_event( $event_id );
        return $data;
    }
}

// Legacy ss_seating_enqueue_assets removed — ss_event uses SS_Ticket_Form::enqueue_assets().

// ── Standalone template for ss_event (bypasses theme layout entirely) ──
add_filter( 'template_include', 'ss_event_override_template', 99 );

function ss_event_override_template( $template ) {
    if ( is_singular( 'ss_event' ) ) {
        $plugin_template = plugin_dir_path( __FILE__ ) . 'templates/single-ss-event.php';
        if ( file_exists( $plugin_template ) ) {
            return $plugin_template;
        }
    }
    return $template;
}

add_action('add_meta_boxes', 'ss_seating_add_metabox');
add_action('save_post_ss_event', 'ss_seating_save_metabox', 10, 2);
add_action('admin_notices', 'ss_seating_layout_locked_notice');
add_action('wp_after_insert_post', 'ss_sync_zones_to_ticket_types_on_save', 999, 2);

function ss_seating_add_metabox() {
    // ss_event uses the unified SS_Event_Admin tabbed metabox (Mapa tab calls ss_seating_metabox_render).
    // No standalone metabox needed.
}

/**
 * Tipos de ticket disponibles. Añadir aquí para extender en el futuro.
 */
function ss_seating_ticket_types() {
    return array(
        'GENERAL' => __('General', 'ss-seating'),
        'VIP'     => __('VIP',     'ss-seating'),
    );
}

function ss_seating_metabox_render($post) {
    wp_nonce_field('ss_seating_config_nonce', 'ss_seating_config_nonce');

    // --- Leer filas / columnas (lógica existente) ---
    $config = array('rows' => 5, 'cols' => 10);
    $meta_rows = get_post_meta($post->ID, 'ss_rows', true);
    $meta_cols = get_post_meta($post->ID, 'ss_columns', true);
    if ($meta_rows !== '' && $meta_rows !== false) {
        $config['rows'] = max(1, (int) $meta_rows);
    }
    if ($meta_cols !== '' && $meta_cols !== false) {
        $config['cols'] = max(1, (int) $meta_cols);
    }
    if ($config['rows'] === 5 && $config['cols'] === 10) {
        $raw = get_post_meta($post->ID, '_ss_seating_config', true);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                if (isset($decoded['rows'])) {
                    $config['rows'] = max(1, (int) $decoded['rows']);
                }
                if (isset($decoded['cols'])) {
                    $config['cols'] = max(1, (int) $decoded['cols']);
                }
            }
        }
    }

    // --- Leer tipos por fila guardados ---
    // Formato: array( 'A' => 'VIP', 'B' => 'GENERAL', ... )
    $row_types_raw = get_post_meta($post->ID, 'ss_row_types', true);
    $row_types = array();
    if (is_string($row_types_raw) && $row_types_raw !== '') {
        $decoded = json_decode($row_types_raw, true);
        if (is_array($decoded)) {
            $row_types = $decoded;
        }
    }

    // --- Leer modo de venta y fee ---
    $sale_mode   = get_post_meta( $post->ID, '_ss_sale_mode', true );
    if ( ! in_array( $sale_mode, array( 'seat', 'zone', 'hybrid' ), true ) ) {
        $sale_mode = 'seat';
    }
    $upgrade_fee = get_post_meta( $post->ID, '_ss_seat_upgrade_fee', true );
    $upgrade_fee = ( $upgrade_fee !== '' && $upgrade_fee !== false ) ? (float) $upgrade_fee : 0;

    $ticket_types = ss_seating_ticket_types();
    $row_labels   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    ?>

    <p style="margin:0 0 8px; font-weight:600;">
        <?php esc_html_e( 'Modo de venta', 'ss-seating' ); ?>
    </p>
    <p>
        <select id="ss_sale_mode" name="ss_sale_mode" style="width:100%;">
            <option value="seat" <?php selected( $sale_mode, 'seat' ); ?>>
                <?php esc_html_e( 'Asiento — mapa clickeable, +/- bloqueado, asientos obligatorios', 'ss-seating' ); ?>
            </option>
            <option value="zone" <?php selected( $sale_mode, 'zone' ); ?>>
                <?php esc_html_e( 'Zona — mapa visible no clickeable, +/- activo, sin asientos requeridos', 'ss-seating' ); ?>
            </option>
            <option value="hybrid" <?php selected( $sale_mode, 'hybrid' ); ?>>
                <?php esc_html_e( 'Híbrido — mapa clickeable, +/- activo, asientos opcionales con fee', 'ss-seating' ); ?>
            </option>
        </select>
    </p>
    <p id="ss_upgrade_fee_wrap" style="<?php echo $sale_mode !== 'hybrid' ? 'display:none;' : ''; ?>">
        <label for="ss_seat_upgrade_fee">
            <?php esc_html_e( 'Costo adicional por seleccionar asiento', 'ss-seating' ); ?>
        </label>
        <input type="number" id="ss_seat_upgrade_fee" name="ss_seat_upgrade_fee"
               value="<?php echo esc_attr( $upgrade_fee ); ?>"
               min="0" step="0.01" style="width:100%;">
        <span class="description">
            <?php esc_html_e( 'Se cobra automáticamente por cada asiento seleccionado en modo híbrido.', 'ss-seating' ); ?>
        </span>
    </p>
    <script>
    (function(){
        var sel = document.getElementById('ss_sale_mode');
        var wrap = document.getElementById('ss_upgrade_fee_wrap');
        if (sel && wrap) {
            sel.addEventListener('change', function(){
                wrap.style.display = this.value === 'hybrid' ? '' : 'none';
            });
        }
    })();
    </script>

    <?php
    // --- Layout Builder Visual (Konva) ---
    $layout_json = get_post_meta($post->ID, '_ss_layout', true);
    ?>

    <div id="ss-admin-builder-wrapper" class="ss-layout-editor">

        <!-- Canvas -->
        <div class="ss-layout-canvas">
          <div id="ss-floor-tabs" style="display:flex;gap:4px;margin-bottom:8px;flex-wrap:wrap;align-items:center;min-height:28px;"></div>
          <div id="container"></div>
          <div style="font-size:11px;color:#6b7280;margin-top:4px;padding:0 4px;">
            <strong>Click</strong> en un asiento para eliminarlo/restaurarlo &nbsp;·&nbsp;
            <strong>Shift+Click</strong> para agregar un espacio después
          </div>

          <div class="ss-layout-canvas__toolbar">
            <button type="button" id="zoomIn" class="button">+</button>
            <button type="button" id="zoomOut" class="button">−</button>
            <button type="button" id="zoomReset" class="button">Fit</button>
            <button type="button" id="toggleBuilder" class="button">Modo Producción</button>
            <button type="button" id="exportVenue" class="button button-primary">Guardar Layout</button>
          </div>
        </div>

        <!-- Panel lateral -->
        <div class="ss-layout-sidebar">
          <h4 style="margin-top:0;">Zonas (Tipos de Boleta) <span class="ss-help" title="Cada zona corresponde a un tipo de boleta (ej: VIP, General). Las filas se asignan a una zona y heredan su color en el mapa. Los precios se configuran en el producto WooCommerce del evento.">?</span></h4>
          <div style="display:flex; gap:6px; margin-bottom:8px; align-items:center;">
            <button type="button" id="addZone" class="button">Agregar zona</button>
          </div>
          <div id="zonesList" class="ss-layout-sidebar__list" style="max-height:180px;"></div>

          <hr>

          <h4>Filas</h4>
          <div style="display:flex; gap:6px; margin-bottom:6px; align-items:center; flex-wrap:wrap;">
            <input type="number" id="rowCount" placeholder="Sillas" style="width:65px;" title="Cantidad de sillas para la nueva fila" />
            <select id="rowType" title="Zona a la que pertenece la nueva fila"></select>
            <button type="button" id="addRow" class="button">+ Fila</button>
            <button type="button" id="addEmpty" class="button" title="Agrega una separación vertical entre filas">Espacio</button>
            <button type="button" id="addFloorLabel" class="button" title="Agrega un encabezado de texto (ej: BALCÓN) para separar secciones visualmente">+ Piso</button>
          </div>
          <div style="display:flex; gap:6px; margin-bottom:8px; flex-wrap:wrap;">
            <button type="button" id="reverseAllRows" class="button button-small ss-rows-global-btn" title="Invierte la numeración de todas las filas: el asiento 1 queda a la derecha">↔ Reversar todas</button>
            <button type="button" id="renumberAllRows" class="button button-small ss-rows-global-btn" title="Renumera todas las filas saltando los huecos: los números van seguidos sin interrupciones">⟳ Renum. todas</button>
          </div>
          <div id="rowsList" class="ss-layout-sidebar__list" style="max-height:360px;"></div>

          <hr>

          <h4>Rectángulos de Zona <span class="ss-help" title="Los rectángulos definen visualmente las áreas del mapa. Cuando un asiento queda dentro de un rectángulo, hereda automáticamente el color de esa zona. Arrástralos y redimensiónalos con el ratón en modo Builder.">?</span></h4>
          <button type="button" id="addZoneRect" class="button">Agregar rectángulo</button>
          <div id="zoneRectsList" class="ss-layout-sidebar__list" style="margin-top:8px; max-height:200px;"></div>
        </div>

      <!-- Hidden: main.js exportVenue writes to #output, adapter copies to hidden input -->
      <pre id="output" style="display:none;"></pre>
      <input type="hidden" name="ss_layout_json" id="ss_layout_hidden" value="<?php echo esc_attr($layout_json); ?>" />
    </div>

    <?php
    // --- URL de Control de Ingreso ---
    $ci_token = get_post_meta($post->ID, '_ss_event_checkin_token', true);
    if ( ! $ci_token ) {
        $ci_token = wp_generate_password( 32, false );
        update_post_meta( $post->ID, '_ss_event_checkin_token', $ci_token );
    }
    $ci_url = home_url( '/control-ingreso/' . $post->ID . '/?token=' . $ci_token );
    ?>
    <hr style="margin:12px 0;">
    <p style="margin:0 0 4px; font-weight:600;"><?php esc_html_e('URL de Control de Ingreso', 'ss-seating'); ?></p>
    <input
        type="text"
        value="<?php echo esc_url( $ci_url ); ?>"
        readonly
        onclick="this.select();"
        style="width:100%; font-family:monospace; font-size:11px; padding:6px; background:#f6f7f7; cursor:pointer;"
    >
    <p style="margin:4px 0 0; font-size:11px; opacity:.6;">
        <?php esc_html_e('Comparte este enlace con el personal de puerta. Protegido por token único.', 'ss-seating'); ?>
    </p>

    <?php
    // --- URL de Box Office ---
    $bo_url = home_url( '/box-office/' . $post->ID . '/' );
    ?>
    <hr style="margin:12px 0;">
    <p style="margin:0 0 4px; font-weight:600;"><?php esc_html_e('Link de Box Office', 'ss-seating'); ?></p>
    <div style="display:flex; gap:6px;">
        <input
            type="text"
            id="ss-bo-url-<?php echo esc_attr( $post->ID ); ?>"
            value="<?php echo esc_url( $bo_url ); ?>"
            readonly
            onclick="this.select();"
            style="flex:1; font-family:monospace; font-size:11px; padding:6px; background:#f6f7f7; cursor:pointer;"
        >
        <button type="button" class="button button-small" onclick="
            var inp = document.getElementById('ss-bo-url-<?php echo esc_js( $post->ID ); ?>');
            inp.select();
            navigator.clipboard.writeText(inp.value).then(function(){ inp.style.borderColor='#00a32a'; setTimeout(function(){ inp.style.borderColor=''; }, 1500); });
        "><?php esc_html_e('Copiar', 'ss-seating'); ?></button>
    </div>
    <p style="margin:4px 0 0; font-size:11px; opacity:.6;">
        <?php esc_html_e('Enlace para venta manual en taquilla. Requiere login de Box Office.', 'ss-seating'); ?>
    </p>
    <?php
}

// ── Helper: detecta si el evento tiene actividad de asientos ──────────────
function ss_event_has_seat_activity( int $event_id ): bool {
    $sold = ss_seats_read( $event_id );
    if ( ! empty( $sold ) ) {
        return true;
    }

    $reserved = get_post_meta( $event_id, '_ss_reserved_seats', true );
    if ( is_array( $reserved ) ) {
        $now = time();
        foreach ( $reserved as $data ) {
            if ( isset( $data['expires'] ) && $data['expires'] >= $now ) {
                return true;
            }
        }
    }

    $zone_sold = get_post_meta( $event_id, '_ss_zone_sold_qty', true );
    if ( is_array( $zone_sold ) ) {
        foreach ( $zone_sold as $qty ) {
            if ( (int) $qty > 0 ) {
                return true;
            }
        }
    }

    return false;
}

// ── Remap seat IDs across all storage locations ───────────────────────────────
function ss_remap_event_seats( int $event_id, array $remap ): array {
    if ( empty( $remap ) ) {
        return array( 'updated' => 0 );
    }

    global $wpdb;
    $updated = 0;

    // 1. wp_ss_seat_ledger — atomic CASE update to handle circular pairs (e.g. A5↔A7).
    //    Sequential UPDATEs would undo circular swaps; a single CASE expression is atomic.
    $ledger_table = $wpdb->prefix . 'ss_seat_ledger';
    $case_parts   = array();
    $old_escaped  = array();
    foreach ( $remap as $old_id => $new_id ) {
        $old_q        = "'" . esc_sql( (string) $old_id ) . "'";
        $new_q        = "'" . esc_sql( (string) $new_id ) . "'";
        $case_parts[] = "WHEN {$old_q} THEN {$new_q}";
        $old_escaped[] = $old_q;
    }
    $case_expr = 'CASE seat_id ' . implode( ' ', $case_parts ) . ' ELSE seat_id END';
    $in_list   = implode( ', ', $old_escaped );
    $eid       = (int) $event_id;
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $wpdb->query( "UPDATE `{$ledger_table}` SET seat_id = {$case_expr} WHERE event_id = {$eid} AND seat_id IN ({$in_list})" );

    // 2. Orders: order meta, item meta, checkins, tokens
    $orders = wc_get_orders( array(
        'meta_key'   => 'ss_event_id',
        'meta_value' => $event_id,
        'limit'      => -1,
        'status'     => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
    ) );

    foreach ( $orders as $order ) {
        $changed = false;

        // order meta ss_seats
        $seats = $order->get_meta( 'ss_seats' );
        if ( is_array( $seats ) ) {
            $new_seats = array_map( function( $s ) use ( $remap ) { return isset( $remap[ $s ] ) ? $remap[ $s ] : $s; }, $seats );
            if ( $new_seats !== $seats ) {
                $order->update_meta_data( 'ss_seats', $new_seats );
                $changed = true;
            }
        }

        // order item meta ss_seats
        foreach ( $order->get_items() as $item ) {
            $item_seats = $item->get_meta( 'ss_seats' );
            if ( ! empty( $item_seats ) ) {
                $arr = is_array( $item_seats ) ? $item_seats : array_map( 'trim', explode( ',', $item_seats ) );
                $new_arr = array_map( function( $s ) use ( $remap ) { return isset( $remap[ $s ] ) ? $remap[ $s ] : $s; }, $arr );
                if ( $new_arr !== $arr ) {
                    $item->update_meta_data( 'ss_seats', $new_arr );
                    $changed = true;
                }
            }
        }

        // _ss_seat_checkins (keys = seat IDs)
        $checkins = $order->get_meta( '_ss_seat_checkins' );
        if ( is_array( $checkins ) ) {
            $new_checkins = array();
            foreach ( $checkins as $seat => $ts ) {
                $new_checkins[ isset( $remap[ $seat ] ) ? $remap[ $seat ] : $seat ] = $ts;
            }
            if ( $new_checkins !== $checkins ) {
                $order->update_meta_data( '_ss_seat_checkins', $new_checkins );
                $changed = true;
            }
        }

        // _ss_seat_tokens (keys = seat IDs)
        $tokens = $order->get_meta( '_ss_seat_tokens' );
        if ( is_array( $tokens ) ) {
            $new_tokens = array();
            foreach ( $tokens as $seat => $tok ) {
                $new_tokens[ isset( $remap[ $seat ] ) ? $remap[ $seat ] : $seat ] = $tok;
            }
            if ( $new_tokens !== $tokens ) {
                $order->update_meta_data( '_ss_seat_tokens', $new_tokens );
                $changed = true;
            }
        }

        if ( $changed ) {
            $order->save();
            $updated++;
        }
    }

    // 3. _ss_reserved_seats (array keys = seat IDs)
    $reserved = get_post_meta( $event_id, '_ss_reserved_seats', true );
    if ( is_array( $reserved ) && ! empty( $reserved ) ) {
        $new_reserved = array();
        foreach ( $reserved as $seat => $data ) {
            $new_reserved[ isset( $remap[ $seat ] ) ? $remap[ $seat ] : $seat ] = $data;
        }
        update_post_meta( $event_id, '_ss_reserved_seats', $new_reserved );
    }

    // 4. _ss_manual_reserved_seats (array keys = seat IDs)
    $manual = get_post_meta( $event_id, '_ss_manual_reserved_seats', true );
    if ( is_array( $manual ) && ! empty( $manual ) ) {
        $new_manual = array();
        foreach ( $manual as $seat => $data ) {
            $new_manual[ isset( $remap[ $seat ] ) ? $remap[ $seat ] : $seat ] = $data;
        }
        update_post_meta( $event_id, '_ss_manual_reserved_seats', $new_manual );
    }

    return array( 'updated' => $updated );
}

add_action( 'wp_ajax_ss_remap_seats', 'ss_ajax_remap_seats' );
function ss_ajax_remap_seats(): void {
    check_ajax_referer( 'ss_remap_seats', 'nonce' );
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $event_id   = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
    $remap_raw  = isset( $_POST['remap'] ) ? wp_unslash( $_POST['remap'] ) : '';
    $new_layout = isset( $_POST['new_layout'] ) ? wp_unslash( $_POST['new_layout'] ) : '';

    if ( ! $event_id ) {
        wp_send_json_error( 'event_id inválido' );
    }

    $remap = json_decode( $remap_raw, true );
    if ( ! is_array( $remap ) || empty( $remap ) ) {
        wp_send_json_error( 'Remap vacío o inválido' );
    }

    $result = ss_remap_event_seats( $event_id, $remap );

    // Guardar el nuevo layout (bypass del lock)
    if ( $new_layout ) {
        update_post_meta( $event_id, '_ss_layout', $new_layout );
    }

    wp_send_json_success( $result );
}

add_action( 'wp_ajax_ss_patch_seats', 'ss_ajax_patch_seats' );
function ss_ajax_patch_seats(): void {
    check_ajax_referer( 'ss_remap_seats', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $event_id  = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
    $remap_raw = isset( $_POST['patch_remap'] ) ? wp_unslash( $_POST['patch_remap'] ) : '';

    if ( ! $event_id ) {
        wp_send_json_error( 'event_id inválido' );
    }

    $remap = json_decode( $remap_raw, true );
    if ( ! is_array( $remap ) || empty( $remap ) ) {
        wp_send_json_error( 'patch_remap vacío o inválido' );
    }

    global $wpdb;
    $log = array();

    // Patch ledger only (atomic CASE — same logic as ss_remap_event_seats)
    $ledger_table = $wpdb->prefix . 'ss_seat_ledger';
    $case_parts   = array();
    $old_escaped  = array();
    foreach ( $remap as $old_id => $new_id ) {
        $old_q         = "'" . esc_sql( (string) $old_id ) . "'";
        $new_q         = "'" . esc_sql( (string) $new_id ) . "'";
        $case_parts[]  = "WHEN {$old_q} THEN {$new_q}";
        $old_escaped[] = $old_q;
    }
    $case_expr = 'CASE seat_id ' . implode( ' ', $case_parts ) . ' ELSE seat_id END';
    $in_list   = implode( ', ', $old_escaped );
    $eid       = (int) $event_id;
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $rows = $wpdb->query( "UPDATE `{$ledger_table}` SET seat_id = {$case_expr} WHERE event_id = {$eid} AND seat_id IN ({$in_list})" );
    $log['ledger_rows'] = $rows;

    // Patch _ss_reserved_seats
    $reserved = get_post_meta( $event_id, '_ss_reserved_seats', true );
    if ( is_array( $reserved ) && ! empty( $reserved ) ) {
        $new_reserved = array();
        foreach ( $reserved as $seat => $data ) {
            $new_reserved[ isset( $remap[ $seat ] ) ? $remap[ $seat ] : $seat ] = $data;
        }
        update_post_meta( $event_id, '_ss_reserved_seats', $new_reserved );
        $log['reserved_patched'] = count( array_diff_key( $new_reserved, $reserved ) ) + count( array_diff_key( $reserved, $new_reserved ) );
    }

    // Patch _ss_manual_reserved_seats
    $manual = get_post_meta( $event_id, '_ss_manual_reserved_seats', true );
    if ( is_array( $manual ) && ! empty( $manual ) ) {
        $new_manual = array();
        foreach ( $manual as $seat => $data ) {
            $new_manual[ isset( $remap[ $seat ] ) ? $remap[ $seat ] : $seat ] = $data;
        }
        update_post_meta( $event_id, '_ss_manual_reserved_seats', $new_manual );
        $log['manual_patched'] = count( array_diff_key( $new_manual, $manual ) ) + count( array_diff_key( $manual, $new_manual ) );
    }

    wp_send_json_success( $log );
}

// ss_ajax_parse_mp_csv eliminado — el cierre contable usa WooCommerce como fuente de verdad.

// ── Guardar extras del cierre contable ────────────────────────────────────────
add_action( 'wp_ajax_ss_extras_save', 'ss_ajax_extras_save' );
function ss_ajax_extras_save(): void {
    check_ajax_referer( 'ss_bo_report', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $event_id   = absint( $_POST['event_id'] ?? 0 );
    $extras_raw = isset( $_POST['extras'] ) ? wp_unslash( $_POST['extras'] ) : '[]';
    if ( ! $event_id ) {
        wp_send_json_error( 'event_id inválido' );
    }

    $extras = json_decode( $extras_raw, true );
    if ( ! is_array( $extras ) ) {
        $extras = array();
    }

    $clean = array();
    foreach ( $extras as $item ) {
        $v = absint( $item['valor'] ?? 0 );
        if ( $v <= 0 ) { continue; }
        $clean[] = array(
            'desc'   => sanitize_text_field( $item['desc']   ?? '' ),
            'valor'  => $v,
            'metodo' => sanitize_text_field( $item['metodo'] ?? '' ),
            'quien'  => sanitize_text_field( $item['quien']  ?? '' ),
        );
    }

    update_post_meta( $event_id, '_ss_cierre_extras', $clean );
    wp_send_json_success( array( 'saved' => count( $clean ) ) );
}

function ss_seating_layout_locked_notice(): void {
    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== 'ss_event' ) {
        return;
    }
    global $post;
    if ( ! $post ) {
        return;
    }
    $msg = get_transient( 'ss_layout_locked_' . $post->ID );
    if ( $msg ) {
        delete_transient( 'ss_layout_locked_' . $post->ID );
        printf(
            '<div class="notice notice-error is-dismissible"><p><strong>SS Seating:</strong> %s</p></div>',
            esc_html( $msg )
        );
    }
}

function ss_seating_save_metabox($post_id, $post) {
    if (!isset($_POST['ss_seating_config_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ss_seating_config_nonce'])), 'ss_seating_config_nonce')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    // Nombres de input técnicos (ss_seating_rows / ss_seating_cols) se mantienen
    $rows = isset($_POST['ss_seating_rows']) ? max(1, (int) $_POST['ss_seating_rows']) : 5;
    $cols = isset($_POST['ss_seating_cols']) ? max(1, (int) $_POST['ss_seating_cols']) : 10;
    update_post_meta($post_id, 'ss_rows', $rows);
    update_post_meta($post_id, 'ss_columns', $cols);
    update_post_meta($post_id, '_ss_seating_config', wp_json_encode(array('rows' => $rows, 'cols' => $cols)));

    // --- Guardar tipos por fila ---
    // $_POST['ss_row_types'] llega como array( 'A' => 'VIP', 'B' => 'GENERAL', ... )
    $allowed_types  = array_keys(ss_seating_ticket_types());
    $row_labels     = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $sanitized_types = array();
    $raw_row_types  = isset($_POST['ss_row_types']) && is_array($_POST['ss_row_types'])
                      ? $_POST['ss_row_types'] : array();

    for ($r = 1; $r <= $rows; $r++) {
        $letter = ($r <= strlen($row_labels)) ? $row_labels[$r - 1] : (string) $r;
        $type   = isset($raw_row_types[$letter]) ? sanitize_text_field(wp_unslash($raw_row_types[$letter])) : 'GENERAL';
        // Solo aceptar tipos conocidos; fallback a GENERAL
        $sanitized_types[$letter] = in_array($type, $allowed_types, true) ? $type : 'GENERAL';
    }

    update_post_meta($post_id, 'ss_row_types', wp_json_encode($sanitized_types));

    // --- Guardar modo de venta ---
    $allowed_modes = array( 'seat', 'zone', 'hybrid' );
    $sale_mode = isset( $_POST['ss_sale_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['ss_sale_mode'] ) ) : 'seat';
    if ( ! in_array( $sale_mode, $allowed_modes, true ) ) {
        $sale_mode = 'seat';
    }
    update_post_meta( $post_id, '_ss_sale_mode', $sale_mode );

    $upgrade_fee = isset( $_POST['ss_seat_upgrade_fee'] ) ? (float) $_POST['ss_seat_upgrade_fee'] : 0;
    $upgrade_fee = max( 0, $upgrade_fee );
    update_post_meta( $post_id, '_ss_seat_upgrade_fee', $upgrade_fee );

    // --- Guardar layout JSON (opcional) ---
    // NOTE: No usar sanitize_text_field() — trunca JSON largo y elimina caracteres válidos.
    // La seguridad se garantiza con json_decode + re-encode (solo datos válidos pasan).
    if (isset($_POST['ss_layout_json'])) {
        $raw_layout = wp_unslash($_POST['ss_layout_json']);
        $raw_layout = is_string($raw_layout) ? trim($raw_layout) : '';

        // Bloquear cambios al layout si hay actividad de asientos
        if ( ss_event_has_seat_activity( $post_id ) ) {
            $existing_layout = get_post_meta( $post_id, '_ss_layout', true );
            $new_layout      = '';
            if ( $raw_layout !== '' ) {
                $decoded = json_decode( $raw_layout, true );
                if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                    $new_layout = wp_json_encode( $decoded );
                }
            }
            if ( $new_layout !== $existing_layout ) {
                set_transient(
                    'ss_layout_locked_' . $post_id,
                    'No se puede modificar el layout porque ya existen sillas vendidas o reservadas en este evento.',
                    30
                );
                // No guardar — saltar al resto del save
                goto ss_layout_save_done;
            }
        }

        if ($raw_layout === '') {
            delete_post_meta($post_id, '_ss_layout');
        } else {
            $decoded = json_decode($raw_layout, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                update_post_meta($post_id, '_ss_layout', wp_json_encode($decoded));
            }
        }
    }
    ss_layout_save_done:

    // NOTE: zone→ticket sync runs at priority 999 (ss_sync_zones_to_ticket_types_on_save)
    // to ensure it executes AFTER MPWEM saves its own ticket types.

    // --- Generar token de check-in del evento si no existe ---
    if ( ! get_post_meta( $post_id, '_ss_event_checkin_token', true ) ) {
        update_post_meta( $post_id, '_ss_event_checkin_token', wp_generate_password( 32, false ) );
    }
}

// ═══════════════════════════════════════════════════════════════════
//  ZONE → TICKET TYPE SYNC
//  Runs at priority 999 on save_post_ss_event
//  its own ticket types, so our values aren't overwritten.
// ═══════════════════════════════════════════════════════════════════

function ss_sync_zones_to_ticket_types_on_save($post_id, $post) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!$post || $post->post_type !== 'ss_event') return;
    if (!current_user_can('edit_post', $post_id)) return;
    ss_sync_zones_to_ticket_types($post_id);
}

/**
 * Normaliza el layout JSON a un array plano de filas.
 * Soporta tanto el formato legacy { rows: [] } como el nuevo { floors: [{ rows: [] }] }.
 */
function ss_layout_get_rows( array $layout ): array {
    if ( ! empty( $layout['floors'] ) && is_array( $layout['floors'] ) ) {
        $all_rows = array();
        foreach ( $layout['floors'] as $floor ) {
            if ( ! empty( $floor['rows'] ) && is_array( $floor['rows'] ) ) {
                $all_rows = array_merge( $all_rows, $floor['rows'] );
            }
        }
        return $all_rows;
    }
    return isset( $layout['rows'] ) && is_array( $layout['rows'] ) ? $layout['rows'] : array();
}

function ss_sync_zones_to_ticket_types($post_id) {
    $layout_raw = SS_Event_Service::instance()->get_layout_raw($post_id);
    if (empty($layout_raw)) {
        return;
    }

    $layout = json_decode($layout_raw, true);
    $rows = ss_layout_get_rows( $layout ?: array() );
    if (!is_array($layout) || empty($rows)) {
        return;
    }

    // 2) Count seats per zone
    $zone_counts = array();
    foreach ($rows as $row) {
        // Skip empty spacer rows
        if (isset($row['type']) && $row['type'] === 'empty') {
            continue;
        }

        $zone  = isset($row['zone']) ? trim($row['zone']) : 'GENERAL';
        $label = isset($row['label']) ? $row['label'] : '';
        $count = isset($row['count']) ? (int) $row['count'] : 0;

        if ($zone === '') {
            $zone = 'GENERAL';
        }

        // removedSeats stores position integers [2, 5, 7], not label+number strings
        $removed = array();
        if (!empty($row['removedSeats']) && is_array($row['removedSeats'])) {
            foreach ($row['removedSeats'] as $rs) {
                $removed[(int) $rs] = true;
            }
        }

        if (!isset($zone_counts[$zone])) {
            $zone_counts[$zone] = 0;
        }

        // Count non-removed seats in this row
        for ($s = 1; $s <= $count; $s++) {
            if (!isset($removed[$s])) {
                $zone_counts[$zone]++;
            }
        }
    }

    if (empty($zone_counts)) {
        return;
    }

    // 3) Update _ss_ticket_types: [{zone, price, capacity}]
    $existing = get_post_meta( $post_id, '_ss_ticket_types', true );
    if ( ! is_array( $existing ) ) {
        $existing = array();
    }

    $name_index = array();
    foreach ( $existing as $i => $tt ) {
        $name = isset( $tt['zone'] ) ? mb_strtoupper( trim( $tt['zone'] ) ) : '';
        if ( $name !== '' ) {
            $name_index[ $name ] = $i;
        }
    }

    foreach ( $zone_counts as $zone_name => $count ) {
        $key = mb_strtoupper( trim( $zone_name ) );
        if ( isset( $name_index[ $key ] ) ) {
            $existing[ $name_index[ $key ] ]['capacity'] = $count;
        } else {
            $existing[] = array(
                'zone'     => $zone_name,
                'price'    => 0,
                'capacity' => $count,
            );
        }
    }

    update_post_meta( $post_id, '_ss_ticket_types', array_values( $existing ) );
}

add_shortcode('ss_seating', 'ss_seating_shortcode');

/**
 * Legacy shortcode [ss_seating] — delega al nuevo [ss_ticket_form].
 * Se mantiene por compatibilidad: si alguien tiene [ss_seating post_id="X"]
 * en un post o widget, se renderiza como [ss_ticket_form].
 */
function ss_seating_shortcode($atts = array()) {
    $atts = shortcode_atts( array( 'post_id' => 0 ), $atts, 'ss_seating' );
    $event_id = (int) $atts['post_id'];
    if ( ! $event_id && is_singular( 'ss_event' ) ) {
        $event_id = get_the_ID();
    }
    if ( ! $event_id ) { return ''; }
    return do_shortcode( '[ss_ticket_form event_id="' . $event_id . '"]' );
}

/* ── Legacy ss_seating_shortcode original ELIMINADO ──
 * ~440 líneas de grid HTML + Konva inline fueron removidas.
 * Toda la lógica de renderización vive ahora en:
 *   - SS_Ticket_Form (includes/frontend/class-ss-ticket-form.php)
 *   - ss-konva-renderer.js (assets/js/)
 *   - ticket-form.php (templates/)
 * ───────────────────────────────────────────────────────────────── */

// ═══════════════════════════════════════════════════════════════════════════════
// EVENTOS ARCHIVE — pre-encolar CSS y añadir clase al body ANTES de wp_head
// Esto garantiza que el fondo oscuro esté en <head>, no en el footer.
// ═══════════════════════════════════════════════════════════════════════════════

add_filter( 'body_class', function( array $classes ): array {
    $post = get_queried_object();
    if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'ss_events' ) ) {
        $classes[] = 'ss-events-page';
    }
    return $classes;
} );

add_action( 'wp_enqueue_scripts', function(): void {
    $post = get_queried_object();
    if ( ! ( $post instanceof WP_Post ) ) {
        return;
    }
    if ( ! has_shortcode( $post->post_content, 'ss_events' ) ) {
        return;
    }
    $plugin_url  = plugin_dir_url( __FILE__ );
    $plugin_path = plugin_dir_path( __FILE__ );
    wp_enqueue_style(
        'ss-events-archive',
        $plugin_url . 'assets/css/ss-events-archive.css',
        array(),
        filemtime( $plugin_path . 'assets/css/ss-events-archive.css' )
    );
} );

// ═══════════════════════════════════════════════════════════════════════════════
// PAGE LOADER — fondo oscuro + spinner antes de que el contenido sea visible
// Se inyecta vía wp_head (background) y wp_body_open (overlay), que disparan
// ANTES de que el shortcode o el template rendericen contenido.
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'wp_head', function() {
    $post = get_queried_object();
    if ( ! ( $post instanceof WP_Post ) ) { return; }
    $is_list  = has_shortcode( $post->post_content, 'ss_events' );
    $is_event = is_singular( 'ss_event' );
    if ( ! $is_list && ! $is_event ) { return; }
    $primary = SS_Settings::get( 'color_primary', '#6d28d9' );
    $min_ms  = $is_event ? 700 : 800;
    $konva   = $is_event ? "document.addEventListener('ss:konva-ready',function(){d();},{once:true});" : '';
    // Script síncrono: ejecuta antes de que el navegador pinte cualquier pixel.
    // Pone el fondo oscuro en <html> INMEDIATAMENTE, sin depender de CSS.
    // También crea el loader y lo inserta en cuanto <body> esté disponible.
    echo '<script>
(function(){
  document.documentElement.style.background="#0a0a0f";
  var START=Date.now(),MIN=' . (int) $min_ms . ',done=false;
  function d(){if(done)return;done=true;var w=Math.max(0,MIN-(Date.now()-START));setTimeout(function(){var l=document.getElementById("ss-page-loader");if(l){l.style.opacity="0";setTimeout(function(){l.parentNode&&l.parentNode.removeChild(l);},420);}},w);}
  function mk(){
    if(document.getElementById("ss-page-loader"))return;
    var l=document.createElement("div");
    l.id="ss-page-loader";
    l.style.cssText="position:fixed;inset:0;background:#0a0a0f;z-index:999999;display:flex;align-items:center;justify-content:center;transition:opacity .4s ease;";
    l.innerHTML=\'<svg width="52" height="52" viewBox="0 0 44 44"><style>@keyframes ssS{to{transform:rotate(360deg)}}</style><circle cx="22" cy="22" r="18" fill="none" stroke="#1a1a2e" stroke-width="3"/><circle cx="22" cy="22" r="18" fill="none" stroke="' . esc_js( $primary ) . '" stroke-width="3" stroke-linecap="round" stroke-dasharray="80" stroke-dashoffset="60" style="transform-origin:22px 22px;animation:ssS .8s linear infinite"/></svg>\';
    (document.body||document.documentElement).prepend(l);
    ' . $konva . '
    window.addEventListener("load",function(){setTimeout(d,200);});
  }
  if(document.body){mk();}else{document.addEventListener("DOMContentLoaded",mk);}
})();
</script>' . "\n";
}, 1 );


// ═══════════════════════════════════════════════════════════════════════════════
// SHORTCODE [ss_events] — Página de archivo de eventos
// ═══════════════════════════════════════════════════════════════════════════════

add_shortcode( 'ss_events', 'ss_events_shortcode' );

function ss_events_shortcode( $atts = array() ) {
    $atts = shortcode_atts( array(
        'limit'    => -1,
        'past'     => 'yes',
    ), $atts, 'ss_events' );

    $now = current_time( 'Y-m-d H:i:s' );

    // Próximos eventos (fecha >= hoy), ordenados por fecha ASC
    $upcoming = get_posts( array(
        'post_type'      => 'ss_event',
        'post_status'    => 'publish',
        'posts_per_page' => (int) $atts['limit'],
        'meta_key'       => '_ss_event_date',
        'orderby'        => 'meta_value',
        'order'          => 'ASC',
        'meta_query'     => array( array(
            'key'     => '_ss_event_date',
            'value'   => current_time( 'Y-m-d' ),
            'compare' => '>=',
            'type'    => 'DATE',
        ) ),
    ) );

    // Eventos pasados (fecha < hoy), ordenados por fecha DESC
    $past = array();
    if ( $atts['past'] === 'yes' ) {
        $past = get_posts( array(
            'post_type'      => 'ss_event',
            'post_status'    => 'publish',
            'posts_per_page' => (int) $atts['limit'],
            'meta_key'       => '_ss_event_date',
            'orderby'        => 'meta_value',
            'order'          => 'DESC',
            'meta_query'     => array( array(
                'key'     => '_ss_event_date',
                'value'   => current_time( 'Y-m-d' ),
                'compare' => '<',
                'type'    => 'DATE',
            ) ),
        ) );
    }

    // Encolar CSS
    $plugin_url  = plugin_dir_url( __FILE__ );
    $plugin_path = plugin_dir_path( __FILE__ );
    wp_enqueue_style(
        'ss-events-archive',
        $plugin_url . 'assets/css/ss-events-archive.css',
        array(),
        filemtime( $plugin_path . 'assets/css/ss-events-archive.css' )
    );

    $primary = SS_Settings::get( 'color_primary', '#6d28d9' );

    ob_start();
    include $plugin_path . 'templates/events-archive.php';
    return ob_get_clean();
}

/**
 * Renderiza una card de evento individual.
 */
function ss_render_event_card( WP_Post $event, bool $is_past = false ): string {
    $event_id = $event->ID;

    // Imagen
    $hero_id   = (int) get_post_meta( $event_id, '_ss_event_hero', true );
    $thumbnail = $hero_id ? wp_get_attachment_image_url( $hero_id, 'medium_large' ) : '';
    if ( ! $thumbnail ) {
        $thumbnail = get_the_post_thumbnail_url( $event_id, 'medium_large' );
    }

    // Fecha
    $date_str = get_post_meta( $event_id, '_ss_event_date', true );
    $time_str = get_post_meta( $event_id, '_ss_event_time', true );
    $date_formatted = '';
    if ( $date_str ) {
        $datetime = $time_str ? "$date_str $time_str" : $date_str;
        $dt = date_create( $datetime, wp_timezone() );
        if ( $dt ) {
            $date_formatted = wp_date( 'j \d\e F, Y', $dt->getTimestamp() );
            if ( $time_str && wp_date( 'H:i', $dt->getTimestamp() ) !== '00:00' ) {
                $date_formatted .= ' · ' . wp_date( 'g:i A', $dt->getTimestamp() );
            }
        }
    }

    // Ubicación
    $venue  = get_post_meta( $event_id, '_ss_location_venue', true );
    $street = get_post_meta( $event_id, '_ss_location_street', true );
    $city   = get_post_meta( $event_id, '_ss_location_city', true );
    $location = implode( ', ', array_filter( array( $venue, $city ) ) );

    // Descripción
    $desc = $event->post_content;
    $desc = preg_replace( '/\[ss_ticket_form[^\]]*\]/', '', $desc );
    $desc = preg_replace( '/\[ss_seating[^\]]*\]/', '', $desc );
    $desc = wp_strip_all_tags( $desc );
    $desc = wp_trim_words( $desc, 25, '...' );

    // Sold out?
    $is_sold_out = false;
    if ( ! $is_past && function_exists( 'ss_get_zone_inventory' ) ) {
        $inv = ss_get_zone_inventory( $event_id );
        if ( ! empty( $inv ) ) {
            $total_available = 0;
            foreach ( $inv as $z ) { $total_available += max( 0, (int) ( $z['available'] ?? 0 ) ); }
            $is_sold_out = ( $total_available <= 0 );
        }
    }

    // Badges de descuento
    $badge_group   = ! $is_past && get_post_meta( $event_id, '_ss_group_discount_enabled', true ) === '1';
    $badge_loyalty = SS_FIDELIZACION_ENABLED && ! $is_past && get_post_meta( $event_id, '_ss_loyalty_enabled', true ) === '1';
    $gd_min_qty    = $badge_group ? (int) get_post_meta( $event_id, '_ss_group_discount_min_qty', true ) : 5;
    $gd_pct        = $badge_group ? (int) get_post_meta( $event_id, '_ss_group_discount_pct', true ) : 0;
    if ( $gd_min_qty <= 0 ) { $gd_min_qty = 5; }

    $permalink = get_permalink( $event_id );
    $cta_text  = $is_past ? 'Ver evento' : ( $is_sold_out ? 'Agotado' : 'Ver evento' );

    // SVG icons
    $icon_cal  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
    $icon_pin  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>';

    ob_start();
    ?>
    <article class="ss-event-card">
        <a href="<?php echo esc_url( $permalink ); ?>" style="text-decoration:none;color:inherit;">
            <div class="ss-event-card__image-wrap">
                <?php if ( $thumbnail ) : ?>
                    <img class="ss-event-card__image" src="<?php echo esc_url( $thumbnail ); ?>" alt="<?php echo esc_attr( $event->post_title ); ?>" loading="lazy">
                <?php else : ?>
                    <div class="ss-event-card__image-placeholder">&#127914;</div>
                <?php endif; ?>
                <?php if ( $is_sold_out && ! $is_past ) : ?>
                    <span class="ss-event-card__sold-out">Agotado</span>
                <?php endif; ?>
            </div>
            <div class="ss-event-card__body">
                <?php if ( $badge_group || $badge_loyalty ) : ?>
                <div class="ss-event-card__badges">
                    <?php if ( $badge_group ) : ?>
                        <span class="ss-event-card__badge ss-event-card__badge--group">
                            Grupos <?php echo esc_html( $gd_min_qty ); ?>+
                            <?php if ( $gd_pct > 0 ) : ?>&nbsp;<?php echo esc_html( $gd_pct ); ?>% OFF<?php endif; ?>
                        </span>
                    <?php endif; ?>
                    <?php if ( $badge_loyalty ) : ?>
                        <span class="ss-event-card__badge ss-event-card__badge--loyalty">Fidelización</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <h3 class="ss-event-card__title"><?php echo esc_html( $event->post_title ); ?></h3>
                <div class="ss-event-card__meta">
                    <?php if ( $date_formatted ) : ?>
                        <span class="ss-event-card__meta-row"><?php echo $icon_cal; ?> <?php echo esc_html( $date_formatted ); ?></span>
                    <?php endif; ?>
                    <?php if ( $location ) : ?>
                        <span class="ss-event-card__meta-row"><?php echo $icon_pin; ?> <?php echo esc_html( $location ); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ( $desc ) : ?>
                    <p class="ss-event-card__desc"><?php echo esc_html( $desc ); ?></p>
                <?php endif; ?>
                <span class="ss-event-card__cta"><?php echo esc_html( $cta_text ); ?></span>
            </div>
        </a>
    </article>
    <?php
    return ob_get_clean();
}

// ═══════════════════════════════════════════════════════════════════════════════
// CAMPO OCULTO EN EL FORM DE WOOCOMMERCE
// ═══════════════════════════════════════════════════════════════════════════════
//
// Inyectar <input name="ss_seats"> dentro del form WC antes del botón.
// El JS lo detecta con cartForm.querySelector('input[name="ss_seats"]') y lo
// reutiliza en lugar de crear uno nuevo. Esto garantiza que el input esté
// dentro del form correcto independientemente de la estructura de MPWEM.

add_action( 'woocommerce_before_add_to_cart_button', 'ss_seating_inject_form_field' );

function ss_seating_inject_form_field(): void {
    if ( ! is_singular( 'ss_event' ) ) {
        return;
    }
    echo '<input type="hidden" name="ss_seats" id="ss_seats_input" value="">';
}

// ═══════════════════════════════════════════════════════════════════════════════
// RESERVA TEMPORAL DE SILLAS AL AGREGAR / QUITAR DEL CARRITO
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'woocommerce_add_to_cart', 'ss_seating_on_add_to_cart', 10, 6 );

function ss_seating_on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ): void {
    if ( empty( $cart_item_data['ss_seats'] ) || empty( $cart_item_data['ss_event_id'] ) ) {
        return;
    }
    $event_id   = (int) $cart_item_data['ss_event_id'];
    $seats      = $cart_item_data['ss_seats'];
    $session_id = WC()->session ? (string) WC()->session->get_customer_id() : '';
    if ( ! $session_id ) {
        return;
    }
    ss_seats_reserve( $event_id, $seats, $session_id );
    ss_litespeed_purge_event( $event_id );
}

add_action( 'woocommerce_cart_item_removed', 'ss_seating_on_cart_item_removed', 10, 2 );

function ss_seating_on_cart_item_removed( $cart_item_key, $cart ): void {
    $item = $cart->removed_cart_contents[ $cart_item_key ] ?? null;
    if ( ! $item || empty( $item['ss_event_id'] ) ) {
        return;
    }
    $event_id   = (int) $item['ss_event_id'];
    $session_id = WC()->session ? (string) WC()->session->get_customer_id() : '';
    ss_seats_release( $event_id, $session_id );
    ss_litespeed_purge_event( $event_id );
}

add_action( 'woocommerce_before_cart_emptied', 'ss_seating_on_before_cart_emptied' );

function ss_seating_on_before_cart_emptied(): void {
    // MPWEM llama woocommerce_before_cart_emptied durante 'init', antes de que
    // WC_Cart esté inicializado. get_cart() en ese punto lanza un _doing_it_wrong.
    if ( ! did_action( 'wp_loaded' ) || ! WC()->cart ) {
        return;
    }
    $session_id = WC()->session ? (string) WC()->session->get_customer_id() : '';
    foreach ( WC()->cart->get_cart() as $item ) {
        if ( ! empty( $item['ss_event_id'] ) ) {
            $event_id = (int) $item['ss_event_id'];
            ss_seats_release( $event_id, $session_id );
            ss_litespeed_purge_event( $event_id );
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ENDPOINT AJAX: GUARDAR SILLAS EN WC SESSION
// ═══════════════════════════════════════════════════════════════════════════════
//
// El JS puede llamar a este endpoint (ssSeatingAjax.url + nonce) para persistir
// las sillas seleccionadas en la sesión ANTES de que se procese el add-to-cart.
// woocommerce_add_cart_item_data lee la sesión como fallback.
//
// Uso desde JS:
//   jQuery.post(ssSeatingAjax.url, {
//       action: 'ss_save_seats',
//       nonce:  ssSeatingAjax.nonce,
//       seats:  selectedSeats.join(','),
//   });

add_action( 'wp_ajax_ss_save_seats',        'ss_ajax_save_seats_to_session' );
add_action( 'wp_ajax_nopriv_ss_save_seats', 'ss_ajax_save_seats_to_session' );

function ss_ajax_save_seats_to_session(): void {
    check_ajax_referer( 'ss_save_seats', 'nonce' );

    $raw      = isset( $_POST['seats'] )    ? sanitize_text_field( wp_unslash( $_POST['seats'] ) ) : '';
    $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
    $rejected = array();

    // Reserva temporal inmediata (hybrid y seat mode)
    if ( $event_id > 0 && WC()->session ) {
        $session_id = (string) WC()->session->get_customer_id();
        if ( $session_id ) {
            // Primero liberar reservas previas de esta sesión para este evento
            ss_seats_release( $event_id, $session_id );

            if ( $raw !== '' ) {
                $seats = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
                if ( ! empty( $seats ) ) {
                    // Ledger es la fuente de verdad: intentar reservar
                    $result   = ss_ledger_temp_reserve( $event_id, $seats, $session_id );
                    $rejected = $result['rejected'];
                    $accepted = $result['reserved'];

                    // Solo escribir en post meta los asientos que el ledger aceptó
                    if ( ! empty( $accepted ) ) {
                        ss_seats_reserve( $event_id, $accepted, $session_id );
                    }

                    // Actualizar raw para que WC session solo tenga los aceptados
                    $raw = implode( ', ', $accepted );
                }
            }
            ss_litespeed_purge_event( $event_id );
        }
    }

    if ( WC()->session ) {
        WC()->session->set( 'ss_pending_seats', $raw );
    }

    $ttl_minutes = max( 1, (int) SS_Settings::get( 'reservation_ttl', 15 ) );

    wp_send_json_success( array(
        'seats'     => $raw,
        'rejected'  => $rejected,
        'expiresIn' => $raw !== '' ? $ttl_minutes * 60 : 0,
    ) );
}

// ═══════════════════════════════════════════════════════════════════════════════
// AJAX: ADD-TO-CART DIRECTO PARA SS_EVENT
// Evita depender de WC_Form_Handler y posibles conflictos con MPWEM.
// ═══════════════════════════════════════════════════════════════════════════════

// ── Pedidos de eventos SS van directo a "completado" (sin email de "procesando") ──
add_filter( 'woocommerce_payment_complete_order_status', 'ss_event_order_complete_status', 10, 3 );

function ss_event_order_complete_status( string $status, int $order_id, $order = null ): string {
    if ( ! $order ) { $order = wc_get_order( $order_id ); }
    if ( ! $order ) { return $status; }

    foreach ( $order->get_items() as $item ) {
        if ( $item->get_meta( 'ss_event_id' ) || $item->get_meta( 'ss_zone' ) || $item->get_meta( 'ss_seats' ) ) {
            return 'completed';
        }
    }
    return $status;
}

add_action( 'wp_ajax_ss_add_to_cart',        'ss_ajax_add_to_cart' );
add_action( 'wp_ajax_nopriv_ss_add_to_cart', 'ss_ajax_add_to_cart' );

function ss_ajax_add_to_cart(): void {
    check_ajax_referer( 'ss_save_seats', 'nonce' );

    $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
    $event_id   = isset( $_POST['event_id'] )   ? absint( $_POST['event_id'] )   : 0;
    $seats_raw  = isset( $_POST['seats'] )       ? sanitize_text_field( wp_unslash( $_POST['seats'] ) ) : '';

    if ( ! $product_id ) {
        wp_send_json_error( array( 'message' => 'Producto no encontrado.' ) );
    }

    // Bloquear eventos pasados antes de cualquier otra validación
    if ( $event_id > 0 ) {
        $event_date = get_post_meta( $event_id, '_ss_event_date', true );
        if ( $event_date && $event_date < current_time( 'Y-m-d' ) ) {
            wp_send_json_error( array( 'message' => 'Este evento ya finalizó.' ) );
        }
    }

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        wp_send_json_error( array( 'message' => 'Producto WC inválido (ID ' . $product_id . ').' ) );
    }

    if ( ! $product->is_purchasable() ) {
        wp_send_json_error( array( 'message' => 'Producto no es comprable. Verifica que el evento esté publicado.' ) );
    }

    if ( ! $product->is_in_stock() ) {
        wp_send_json_error( array( 'message' => 'Producto sin stock.' ) );
    }

    // ── Modo zone: cantidades por zona ──
    $sale_mode_post = isset( $_POST['sale_mode'] ) ? sanitize_text_field( $_POST['sale_mode'] ) : '';
    $zone_qtys_raw  = isset( $_POST['zone_qtys'] ) ? wp_unslash( $_POST['zone_qtys'] ) : '';

    if ( $sale_mode_post === 'zone' && $zone_qtys_raw !== '' ) {
        $zone_qtys = json_decode( $zone_qtys_raw, true );
        if ( ! is_array( $zone_qtys ) || empty( $zone_qtys ) ) {
            wp_send_json_error( array( 'message' => 'No se seleccionaron entradas.' ) );
        }

        // Limpiar items anteriores del mismo evento
        foreach ( WC()->cart->get_cart() as $cart_key => $cart_item ) {
            if ( isset( $cart_item['ss_event_id'] ) && (int) $cart_item['ss_event_id'] === $event_id ) {
                WC()->cart->remove_cart_item( $cart_key );
            }
        }

        // Validar disponibilidad real por zona
        $zone_inventory = ss_get_zone_inventory( $event_id );
        foreach ( $zone_qtys as $zone => $qty ) {
            $qty  = (int) $qty;
            if ( $qty <= 0 ) { continue; }
            $zkey = strtoupper( sanitize_text_field( $zone ) );
            $inv  = $zone_inventory[ $zkey ] ?? ( $zone_inventory[ sanitize_text_field( $zone ) ] ?? null );
            $avail = $inv ? (int) $inv['available'] : 0;
            if ( $qty > $avail ) {
                wp_send_json_error( array(
                    'message' => $avail <= 0
                        ? 'La zona ' . $zone . ' está agotada.'
                        : 'Solo quedan ' . $avail . ' entradas en la zona ' . $zone . '.',
                ) );
            }
        }

        wc_clear_notices();
        $added_any = false;

        foreach ( $zone_qtys as $zone => $qty ) {
            $qty = (int) $qty;
            if ( $qty <= 0 ) { continue; }
            $zone = sanitize_text_field( $zone );

            $cart_item_data = array(
                'ss_event_id'       => $event_id,
                'ss_zone'           => $zone,
                'ss_ticket_qty'     => $qty,
                'ss_seats'          => array(),
                'ss_sale_mode'      => 'zone',
                'ss_seating_unique' => md5( $zone . $event_id . microtime( true ) ),
            );

            $cart_key = WC()->cart->add_to_cart( $product_id, $qty, 0, array(), $cart_item_data );
            if ( $cart_key ) { $added_any = true; }
        }

        if ( $added_any ) {
            wp_send_json_success( array(
                'message'  => 'Añadido al carrito.',
                'cart_url' => wc_get_cart_url(),
            ) );
        } else {
            $notices = wc_get_notices( 'error' );
            wc_clear_notices();
            $msg = 'Error al añadir al carrito.';
            if ( ! empty( $notices ) ) {
                $first = is_array( $notices[0] ) ? $notices[0]['notice'] : (string) $notices[0];
                $msg  .= ' ' . wp_strip_all_tags( $first );
            }
            wp_send_json_error( array( 'message' => $msg ) );
        }
        return;
    }

    // ── Modo seat: flujo existente ──
    // Parse seats
    $seats_array = array();
    if ( $seats_raw !== '' ) {
        $seats_array = array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $seats_raw ) ) ) ) );
    }

    // Limpiar items anteriores del mismo evento para evitar duplicados en el carrito
    foreach ( WC()->cart->get_cart() as $cart_key => $cart_item ) {
        if ( isset( $cart_item['ss_event_id'] ) && (int) $cart_item['ss_event_id'] === $event_id ) {
            WC()->cart->remove_cart_item( $cart_key );
        }
    }

    // Guardar en sesión WC (para que add_cart_item_data las lea si hace falta)
    if ( WC()->session ) {
        WC()->session->set( 'ss_pending_seats', $seats_raw );
    }

    // Pre-populate cart item data con sillas y evento
    $cart_item_data = array(
        'ss_event_id' => $event_id,
        'ss_seats'    => $seats_array,
    );

    // Limpiar errores previos
    wc_clear_notices();

    // Añadir al carrito — quantity 1, sillas van en cart_item_data
    $cart_key = WC()->cart->add_to_cart( $product_id, 1, 0, array(), $cart_item_data );

    if ( $cart_key ) {
        wp_send_json_success( array(
            'message'  => 'Añadido al carrito.',
            'cart_url' => wc_get_cart_url(),
            'cart_key' => $cart_key,
        ) );
    } else {
        // Capturar notices de error de WC
        $notices = wc_get_notices( 'error' );
        wc_clear_notices();
        $msg = 'Error al añadir al carrito.';
        if ( ! empty( $notices ) ) {
            $first = is_array( $notices[0] ) ? $notices[0]['notice'] : (string) $notices[0];
            $msg  .= ' ' . wp_strip_all_tags( $first );
        }
        wp_send_json_error( array( 'message' => $msg ) );
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// VALIDACIÓN: RECHAZAR ADD-TO-CART SIN SILLAS CUANDO HAY LAYOUT ACTIVO
// ═══════════════════════════════════════════════════════════════════════════════

add_filter('woocommerce_add_to_cart_validation', 'ss_seating_require_seats_validation', 10, 3);

// Permitir qty > 1 en modo zone (el producto tiene sold_individually=true para modo seat)
add_filter( 'woocommerce_is_sold_individually', 'ss_allow_zone_quantity', 10, 2 );
function ss_allow_zone_quantity( bool $sold_individually, $product ): bool {
    if ( ! $sold_individually ) { return false; }
    $event_id = (int) get_post_meta( $product->get_id(), '_ss_event_id', true );
    if ( $event_id <= 0 ) { return $sold_individually; }
    $sale_mode = SS_Event_Service::instance()->get_sale_mode( $event_id );
    if ( in_array( $sale_mode, array( 'general', 'hybrid' ), true ) ) {
        return false; // Permitir qty > 1
    }
    return $sold_individually;
}

function ss_seating_clear_pending_session_reservation( int $event_id ): void {
    if ( ! WC()->session ) {
        return;
    }
    $session_id = (string) WC()->session->get_customer_id();
    if ( $session_id !== '' ) {
        ss_seats_release( $event_id, $session_id );
    }
    WC()->session->__unset( 'ss_pending_seats' );
    if ( $event_id > 0 ) {
        ss_litespeed_purge_event( $event_id );
    }
}

function ss_seating_require_seats_validation($passed, $product_id, $quantity) {
    // Find the event ID from POST data (MPWEM or SS Ticket Form)
    $event_id = 0;
    if (!empty($_POST['mpwem_post_id'])) {
        $event_id = (int) $_POST['mpwem_post_id'];
    } elseif (!empty($_POST['ss_event_id'])) {
        $event_id = (int) $_POST['ss_event_id'];
    } elseif (!empty($_POST['event_id'])) {
        $event_id = (int) $_POST['event_id'];
    }

    // Bloquear eventos pasados
    if ( $event_id > 0 ) {
        $event_date = get_post_meta( $event_id, '_ss_event_date', true );
        if ( $event_date && $event_date < current_time( 'Y-m-d' ) ) {
            wc_add_notice( 'Este evento ya finalizó y no acepta nuevas compras.', 'error' );
            return false;
        }
    }
    // Fallback: producto oculto vinculado a ss_event
    if ( ! $event_id && $product_id > 0 ) {
        $linked = (int) get_post_meta( $product_id, '_ss_event_id', true );
        if ( $linked > 0 ) {
            $event_id = $linked;
        }
    }

    if (!$event_id) {
        return $passed;
    }

    // Check if this event has an active Konva layout
    $layout_raw = SS_Event_Service::instance()->get_layout_raw($event_id);
    if (empty($layout_raw)) {
        return $passed;
    }

    $layout = json_decode($layout_raw, true);
    $rows = ss_layout_get_rows( $layout ?: array() );
    if (!is_array($layout) || empty($rows)) {
        return $passed;
    }

    // Check sale mode — only 'seat' mode requires seats
    $sale_mode = SS_Event_Service::instance()->get_sale_mode( $event_id );
    if ( $sale_mode !== 'seat' ) {
        return $passed; // zone and hybrid: seats are optional
    }

    // Layout is active and mode is seat — seats are required
    $seats_raw      = '';
    $has_seats_post = is_array($_POST) && array_key_exists('ss_seats', $_POST);
    if ($has_seats_post) {
        $seats_raw = trim(wc_clean(wp_unslash((string) $_POST['ss_seats'])));
    }

    // Fallback: key 'seats' (usado por ss_ajax_add_to_cart)
    if ( $seats_raw === '' && ! empty( $_POST['seats'] ) ) {
        $seats_raw = trim( wc_clean( wp_unslash( (string) $_POST['seats'] ) ) );
        $has_seats_post = true;
    }

    // Fallback: WC session (guardada por ss_save_seats AJAX)
    if (!$has_seats_post && WC()->session) {
        $session_val = WC()->session->get('ss_pending_seats');
        if ($session_val) {
            $seats_raw = trim(wc_clean($session_val));
        }
    }

    if ($seats_raw === '') {
        ss_seating_clear_pending_session_reservation( $event_id );
        wc_add_notice('Debes seleccionar tus asientos en el mapa antes de agregar al carrito.', 'error');
        return false;
    }

    $selected_seats = array_values(array_unique(array_filter(array_map('trim', explode(',', $seats_raw)))));
    $selected_count = count($selected_seats);
    if ($selected_count <= 0) {
        ss_seating_clear_pending_session_reservation( $event_id );
        wc_add_notice('Debes seleccionar tus asientos en el mapa antes de agregar al carrito.', 'error');
        return false;
    }

    // En modo seat, la cantidad solicitada en +/− debe coincidir con las sillas elegidas.
    $requested_qty = 0;
    if (!empty($_POST['option_qty']) && is_array($_POST['option_qty'])) {
        foreach ($_POST['option_qty'] as $q) {
            $requested_qty += max(0, (int) $q);
        }
    } else {
        $requested_qty = max(0, (int) $quantity);
    }

    // Para productos vinculados a ss_event, la cantidad WC siempre es 1
    // (sold_individually) — no comparar con el número de sillas seleccionadas.
    $is_ss_event_product = ( $product_id > 0 && (int) get_post_meta( $product_id, '_ss_event_id', true ) > 0 );
    if ( ! $is_ss_event_product && $requested_qty > 0 && $requested_qty !== $selected_count ) {
        ss_seating_clear_pending_session_reservation( $event_id );
        wc_add_notice(
            sprintf(
                'La cantidad de entradas (%d) debe coincidir con las sillas seleccionadas (%d).',
                $requested_qty,
                $selected_count
            ),
            'error'
        );
        return false;
    }

    return $passed;
}

// ═══════════════════════════════════════════════════════════════════════════════
// PRODUCTO WC OCULTO DE SS_EVENT → PURCHASABLE
// WooCommerce rechaza productos ocultos (exclude-from-catalog) por defecto.
// Este filtro los hace comprables si están vinculados a un ss_event publicado.
// ═══════════════════════════════════════════════════════════════════════════════

add_filter( 'woocommerce_is_purchasable', 'ss_make_event_product_purchasable', 10, 2 );

function ss_make_event_product_purchasable( $is_purchasable, $product ) {
    if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
        return $is_purchasable;
    }
    $linked_event = (int) get_post_meta( $product->get_id(), '_ss_event_id', true );
    if ( $linked_event > 0 && get_post_type( $linked_event ) === 'ss_event' && get_post_status( $linked_event ) === 'publish' ) {
        $event_date = get_post_meta( $linked_event, '_ss_event_date', true );
        if ( $event_date && $event_date < current_time( 'Y-m-d' ) ) {
            return false; // Evento pasado — bloquear compra
        }
        return true;
    }
    return $is_purchasable;
}

// ═══════════════════════════════════════════════════════════════════════════════
// CAPTURA DE SILLAS AL AÑADIR AL CARRITO
// ═══════════════════════════════════════════════════════════════════════════════

add_filter('woocommerce_add_cart_item_data', 'ss_seating_add_cart_item_data', 10, 3);

function ss_seating_add_cart_item_data($cart_item_data, $product_id, $variation_id) {
    // Track which ticket type index this call is for (MPWEM calls add_to_cart N times per request)
    if ( ! isset( $GLOBALS['_ss_cart_item_call_index'] ) ) {
        $GLOBALS['_ss_cart_item_call_index'] = 0;
    } else {
        $GLOBALS['_ss_cart_item_call_index']++;
    }
    $call_index = $GLOBALS['_ss_cart_item_call_index'];

    // Always capture event ID first (needed for QR, check-in, fee in all sale modes)
    $event_id = 0;
    if ( ! empty( $_POST['mpwem_post_id'] ) ) {
        $event_id = (int) $_POST['mpwem_post_id'];
    } elseif ( ! empty( $_POST['ss_event_id'] ) ) {
        $event_id = (int) $_POST['ss_event_id'];
    }
    // Fallback: si el producto es un producto oculto vinculado a un ss_event
    if ( ! $event_id && $product_id > 0 ) {
        $linked = (int) get_post_meta( $product_id, '_ss_event_id', true );
        if ( $linked > 0 ) {
            $event_id = $linked;
        }
    }
    if ( $event_id > 0 ) {
        ss_cleanup_expired_reservations( $event_id );
        $cart_item_data['ss_event_id'] = $event_id;
    }

    // Determine which ticket type this call is for (from POST option_name[] / option_qty[] arrays).
    // MPWEM only calls add_to_cart for ticket types with qty > 0, so we must skip
    // zero-qty entries when mapping call_index to option_name[].
    $ticket_name = '';
    if ( ! empty( $_POST['option_name'] ) && is_array( $_POST['option_name'] ) ) {
        $option_names = array_values( $_POST['option_name'] );
        $option_qtys  = ! empty( $_POST['option_qty'] ) && is_array( $_POST['option_qty'] )
                        ? array_values( $_POST['option_qty'] )
                        : array();

        // Build list of ticket names + qtys that have qty > 0 (these are the ones MPWEM actually adds)
        $active_names = array();
        $active_qtys  = array();
        for ( $i = 0; $i < count( $option_names ); $i++ ) {
            $q = isset( $option_qtys[ $i ] ) ? (int) $option_qtys[ $i ] : 0;
            if ( $q > 0 ) {
                $active_names[] = strtoupper( trim( sanitize_text_field( $option_names[ $i ] ) ) );
                $active_qtys[]  = $q;
            }
        }

        if ( isset( $active_names[ $call_index ] ) ) {
            $ticket_name = $active_names[ $call_index ];
        }
    }

    // Save ticket type name (zone) in cart item — needed for check-in
    if ( $ticket_name !== '' ) {
        $cart_item_data['ss_zone'] = $ticket_name;
    }

    // Save real ticket qty from the form (MPWEM always reports qty=1 per item)
    if ( isset( $active_qtys[ $call_index ] ) ) {
        $cart_item_data['ss_ticket_qty'] = $active_qtys[ $call_index ];
    }

    // Si ss_seats ya fue pre-populated (ej: desde ss_ajax_add_to_cart), no sobreescribir
    if ( ! empty( $cart_item_data['ss_seats'] ) && is_array( $cart_item_data['ss_seats'] ) ) {
        // Asegurar ss_seating_unique para que WC no colapse items
        if ( empty( $cart_item_data['ss_seating_unique'] ) ) {
            $cart_item_data['ss_seating_unique'] = md5( implode( ',', $cart_item_data['ss_seats'] ) . microtime( true ) );
        }
        return $cart_item_data;
    }

    // Fuente 1 (principal): WC Session — guardada por AJAX ss_save_seats
    $raw = '';
    if ( WC()->session ) {
        $session_val = WC()->session->get( 'ss_pending_seats' );
        if ( $session_val ) {
            $raw = wc_clean( $session_val );
        }
    }

    // Fuente 2 (fallback): POST directo — hidden input en el form
    if ( $raw === '' && ! empty( $_POST['ss_seats'] ) ) {
        $raw = wc_clean( wp_unslash( $_POST['ss_seats'] ) );
    }

    if ( $raw === '' ) {
        return $cart_item_data;
    }

    $all_seats = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );

    if ( empty( $all_seats ) ) {
        return $cart_item_data;
    }

    // Split seats by zone: only attach seats belonging to THIS ticket type's zone
    if ( $event_id > 0 && $ticket_name !== '' ) {
        $zone_map    = ss_seats_zone_map( $event_id );
        $my_seats    = array();
        $my_seat_data = array();
        foreach ( $all_seats as $seat ) {
            $seat_zone = isset( $zone_map[ $seat ] ) ? strtoupper( $zone_map[ $seat ] ) : '';
            if ( $seat_zone === $ticket_name ) {
                $my_seats[] = $seat;
                $my_seat_data[] = array( 'seat' => $seat, 'zone' => $seat_zone );
            }
        }
        if ( ! empty( $my_seats ) ) {
            $cart_item_data['ss_seats']          = $my_seats;
            $cart_item_data['ss_seat_data']       = $my_seat_data;
            $cart_item_data['ss_seating_unique']  = md5( implode( ',', $my_seats ) . microtime( true ) );
        }
    } else {
        // Fallback: no zone matching possible, attach all seats
        $cart_item_data['ss_seats']          = $all_seats;
        $cart_item_data['ss_seating_unique'] = md5( implode( ',', $all_seats ) . microtime( true ) );
        if ( $event_id > 0 ) {
            $cart_item_data['ss_seat_data'] = ss_build_seat_data( $all_seats, $event_id );
        }
    }

    return $cart_item_data;
}

// Limpiar sesión de sillas pendientes después de que todos los add-to-cart se procesaron.
// MPWEM llama add_to_cart N veces por request. Usamos shutdown para limpiar al final del request.
add_action( 'woocommerce_add_to_cart', 'ss_seating_schedule_session_cleanup' );
function ss_seating_schedule_session_cleanup(): void {
    // Registrar cleanup al final del request (solo una vez)
    if ( ! has_action( 'shutdown', 'ss_seating_do_session_cleanup' ) ) {
        add_action( 'shutdown', 'ss_seating_do_session_cleanup' );
    }
}
function ss_seating_do_session_cleanup(): void {
    if ( function_exists( 'WC' ) && WC()->session && WC()->session->get( 'ss_pending_seats' ) ) {
        WC()->session->__unset( 'ss_pending_seats' );
    }
}

add_filter('woocommerce_get_item_data', 'ss_seating_get_item_data', 10, 2);

function ss_seating_get_item_data($item_data, $cart_item) {
    if (!empty($cart_item['ss_seats']) && is_array($cart_item['ss_seats'])) {
        $label = __('Sillas', 'ss-seating');
        $value = implode(', ', array_map('wc_clean', $cart_item['ss_seats']));

        $item_data[] = array(
            'key'   => $label,
            'value' => $value,
        );
    }

    // Modo zone: mostrar zona
    if ( empty( $cart_item['ss_seats'] ) && ! empty( $cart_item['ss_zone'] ) ) {
        $item_data[] = array(
            'key'   => __( 'Zona', 'ss-seating' ),
            'value' => wc_clean( $cart_item['ss_zone'] ),
        );
    }

    return $item_data;
}

// ═══════════════════════════════════════════════════════════════════════════════
// FEE AUTOMÁTICO EN MODO HYBRID
// ═══════════════════════════════════════════════════════════════════════════════
//
// En modo hybrid, cada asiento seleccionado genera un fee adicional.
// WC limpia todos los fees antes de llamar este hook en cada recálculo,
// por lo que no hay riesgo de duplicación.

// ── Precio dinámico por zona ──────────────────────────────────────────────────
// El producto WC oculto se crea con precio 0.01.
// Aquí lo reemplazamos por el precio real del ticket type antes de calcular totales.
add_action( 'woocommerce_before_calculate_totals', 'ss_seating_set_dynamic_price', 10, 1 );

function ss_seating_set_dynamic_price( WC_Cart $cart ): void {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        return;
    }
    // Guard: usar flag estático para evitar doble ejecución en la misma request
    static $running = false;
    if ( $running ) {
        return;
    }
    $running = true;

    foreach ( $cart->get_cart() as &$item ) {
        $event_id = (int) ( $item['ss_event_id'] ?? 0 );
        if ( ! $event_id ) {
            continue;
        }
        $zone   = strtoupper( trim( (string) ( $item['ss_zone'] ?? '' ) ) );
        $tt_raw = get_post_meta( $event_id, '_ss_ticket_types', true );
        if ( ! is_array( $tt_raw ) || empty( $tt_raw ) ) {
            continue;
        }
        $price = null;
        foreach ( $tt_raw as $tt ) {
            $tt_zone = strtoupper( trim( (string) ( $tt['zone'] ?? '' ) ) );
            if ( $tt_zone === $zone || ( $zone === '' && $price === null ) ) {
                $price = (float) ( $tt['price'] ?? 0 );
                if ( $tt_zone === $zone ) {
                    break;
                }
            }
        }
        if ( $price === null || ! isset( $item['data'] ) || ! ( $item['data'] instanceof WC_Product ) ) {
            continue;
        }

        $sale_mode = get_post_meta( $event_id, '_ss_sale_mode', true ) ?: 'seat';

        if ( $sale_mode === 'seat' ) {
            // Modo seat: qty WC = 1, todas las sillas en ss_seats → precio total = precio × nº sillas
            $num_seats = ( ! empty( $item['ss_seats'] ) && is_array( $item['ss_seats'] ) )
                ? count( $item['ss_seats'] )
                : 1;
            $item['data']->set_price( $price * $num_seats );
        } else {
            // Modo zone/general/hybrid: WC qty ya refleja el número de boletas → solo precio unitario
            $item['data']->set_price( $price );
        }
    }

    $running = false;
}

add_action( 'woocommerce_cart_calculate_fees', 'ss_seating_hybrid_seat_fee' );
add_action( 'woocommerce_cart_calculate_fees', 'ss_apply_event_discounts_to_cart', 20 );

function ss_seating_hybrid_seat_fee( $cart ): void {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        return;
    }

    $total_fee        = 0;
    $total_seat_count = 0;

    foreach ( $cart->get_cart() as $cart_item ) {
        if ( empty( $cart_item['ss_seats'] ) || empty( $cart_item['ss_event_id'] ) ) {
            continue;
        }

        $event_id  = (int) $cart_item['ss_event_id'];
        $sale_mode = get_post_meta( $event_id, '_ss_sale_mode', true ) ?: 'seat';

        if ( $sale_mode !== 'hybrid' ) {
            continue;
        }

        $upgrade_fee = (float) ( get_post_meta( $event_id, '_ss_seat_upgrade_fee', true ) ?: 0 );
        if ( $upgrade_fee <= 0 ) {
            continue;
        }

        $seats = (array) $cart_item['ss_seats'];
        $count = count( $seats );
        if ( $count > 0 ) {
            $total_fee        += $count * $upgrade_fee;
            $total_seat_count += $count;
        }
    }

    if ( $total_fee > 0 ) {
        $cart->add_fee(
            sprintf(
                __( 'Selección de asiento (%d)', 'ss-seating' ),
                $total_seat_count
            ),
            $total_fee,
            true
        );
    }
}

/**
 * Aplicar descuentos de grupo y fidelización como fee negativo en el carrito.
 * Se aplican en carrito, checkout y se transfieren al pedido automáticamente.
 */
function ss_apply_event_discounts_to_cart( WC_Cart $cart ): void {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        return;
    }
    if ( ! class_exists( 'SS_Group_Discount' ) || ! class_exists( 'SS_Loyalty' ) ) {
        return;
    }

    // Descuento grupal (no requiere email)
    $group_pct = SS_Group_Discount::get_discount_for_cart();

    // Descuento de fidelización (requiere email — disponible para logueados o en checkout)
    $loyalty_pct = 0;
    $email = '';
    if ( function_exists( 'WC' ) && WC()->customer ) {
        $email = WC()->customer->get_billing_email();
    }
    // Fallback: leer del POST si estamos en proceso de checkout
    if ( empty( $email ) && ! empty( $_POST['billing_email'] ) ) {
        $email = sanitize_email( wp_unslash( $_POST['billing_email'] ) );
    }
    if ( ! empty( $email ) ) {
        $loyalty_pct = SS_Loyalty::get_applicable_for_cart( $email );
    }

    // Usar el mayor; en empate, fidelización gana
    if ( $loyalty_pct <= 0 && $group_pct <= 0 ) {
        return;
    }
    if ( $loyalty_pct >= $group_pct && $loyalty_pct > 0 ) {
        $pct   = $loyalty_pct;
        $label = sprintf( 'Descuento fidelización (%d%%)', $pct );
    } else {
        $pct   = $group_pct;
        $label = sprintf( 'Descuento grupal (%d%%)', $pct );
    }

    $subtotal = $cart->get_subtotal();
    if ( $subtotal <= 0 ) {
        return;
    }

    $discount = -round( $subtotal * $pct / 100, 2 );
    $cart->add_fee( $label, $discount, false );
}

// ── AJAX: consulta de loyalty por email (checkout en tiempo real) ────────────

add_action( 'wp_ajax_ss_check_loyalty',        'ss_ajax_check_loyalty' );
add_action( 'wp_ajax_nopriv_ss_check_loyalty', 'ss_ajax_check_loyalty' );

function ss_ajax_check_loyalty(): void {
    check_ajax_referer( 'ss_check_loyalty', 'nonce' );
    $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
    if ( empty( $email ) || ! class_exists( 'SS_Loyalty' ) ) {
        wp_send_json_success( array( 'pct' => 0 ) );
    }
    $pct = SS_Loyalty::get_applicable_for_cart( $email );
    wp_send_json_success( array( 'pct' => (int) $pct ) );
}

// ── JS en checkout: aviso de loyalty al escribir el email ────────────────────

add_action( 'wp_footer', 'ss_checkout_loyalty_notice_js' );

function ss_checkout_loyalty_notice_js(): void {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
        return;
    }
    if ( ! class_exists( 'SS_Loyalty' ) ) {
        return;
    }
    $nonce = wp_create_nonce( 'ss_check_loyalty' );
    ?>
<script>
(function() {
    'use strict';
    var AJAX_URL = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
    var NONCE    = <?php echo wp_json_encode( $nonce ); ?>;
    var NOTICE_ID = 'ss-loyalty-notice';
    var lastEmail = '';

    function showNotice( pct ) {
        var existing = document.getElementById( NOTICE_ID );
        if ( existing ) { existing.parentNode.removeChild( existing ); }
        if ( pct <= 0 ) { return; }
        var el = document.createElement( 'div' );
        el.id = NOTICE_ID;
        el.style.cssText = 'margin:12px 0;padding:10px 14px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;color:#166534;font-size:14px;';
        el.innerHTML = '🎟 <strong>Tienes ' + pct + '% de descuento por fidelización.</strong> Se aplicará automáticamente en tu pedido.';
        var emailField = document.getElementById( 'billing_email' );
        if ( emailField && emailField.parentNode ) {
            emailField.parentNode.insertAdjacentElement( 'afterend', el );
        }
    }

    function checkLoyalty( email ) {
        if ( email === lastEmail ) { return; }
        lastEmail = email;
        if ( ! email || email.indexOf('@') < 1 ) {
            showNotice( 0 );
            return;
        }
        var data = new FormData();
        data.append( 'action', 'ss_check_loyalty' );
        data.append( 'nonce', NONCE );
        data.append( 'email', email );
        fetch( AJAX_URL, { method: 'POST', body: data } )
            .then( function(r) { return r.json(); } )
            .then( function(r) {
                if ( r.success ) { showNotice( r.data.pct ); }
            } )
            .catch( function() {} );
    }

    function attachListener() {
        var field = document.getElementById( 'billing_email' );
        if ( ! field ) { return; }
        field.addEventListener( 'blur', function() {
            checkLoyalty( this.value.trim() );
        } );
        // También si WC repinta el checkout
        document.body.addEventListener( 'updated_checkout', function() {
            var f = document.getElementById( 'billing_email' );
            if ( f && f.value ) { checkLoyalty( f.value.trim() ); }
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', attachListener );
    } else {
        attachListener();
    }
}());
</script>
    <?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// GUARDADO GARANTIZADO DE ss_seats EN EL PEDIDO
// ═══════════════════════════════════════════════════════════════════════════════
//
// Problema: woocommerce_add_cart_item_data solo funciona en checkout clásico.
// En Block Checkout (y como red de seguridad general), guardamos ss_seats
// directamente en el pedido desde $_POST en woocommerce_checkout_create_order.
// ss_seats_get_from_order() lee AMBAS fuentes: item meta y order meta.

// ── Captura de UTM de llegada (Centro de Difusión genera utm_source/utm_campaign
// al compartir el smart link). Se guarda en WC session al aterrizar en la landing
// y se persiste en el pedido en woocommerce_checkout_create_order, igual que ss_seats.
add_action( 'wp', 'ss_seating_capture_utm_session' );

function ss_seating_capture_utm_session(): void {
    if ( is_admin() || empty( $_GET['utm_source'] ) ) {
        return;
    }
    if ( ! function_exists( 'WC' ) || ! WC()->session ) {
        return;
    }
    WC()->session->set( 'ss_pending_utm', array(
        'utm_source'   => sanitize_text_field( wp_unslash( $_GET['utm_source'] ) ),
        'utm_campaign' => isset( $_GET['utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_campaign'] ) ) : '',
    ) );
}

add_action( 'woocommerce_checkout_create_order', 'ss_seating_save_utm_to_order', 10, 2 );

function ss_seating_save_utm_to_order( $order, $data ): void {
    if ( ! WC()->session ) {
        return;
    }
    $utm = WC()->session->get( 'ss_pending_utm' );
    if ( empty( $utm ) || empty( $utm['utm_source'] ) ) {
        return;
    }
    $order->update_meta_data( '_ss_utm_source', $utm['utm_source'] );
    if ( ! empty( $utm['utm_campaign'] ) ) {
        $order->update_meta_data( '_ss_utm_campaign', $utm['utm_campaign'] );
    }
}

add_action( 'woocommerce_checkout_create_order', 'ss_seating_save_seats_to_order', 10, 2 );

function ss_seating_save_seats_to_order( $order, $data ): void {
    // Fuente 1: POST directo
    $raw = isset( $_POST['ss_seats'] ) ? sanitize_text_field( wp_unslash( $_POST['ss_seats'] ) ) : '';

    // Fuente 2: WC session
    if ( $raw === '' && WC()->session ) {
        $session_val = WC()->session->get( 'ss_pending_seats' );
        if ( $session_val ) {
            $raw = wc_clean( $session_val );
        }
    }

    // Fuente 3: cart item data (si POST y session fallaron)
    if ( $raw === '' && WC()->cart ) {
        $cart_seats = array();
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( ! empty( $cart_item['ss_seats'] ) && is_array( $cart_item['ss_seats'] ) ) {
                $cart_seats = array_merge( $cart_seats, $cart_item['ss_seats'] );
            }
        }
        if ( ! empty( $cart_seats ) ) {
            $raw = implode( ',', $cart_seats );
        }
    }

    if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] woocommerce_checkout_create_order ejecutado. ss_seats: ' . ( $raw ?: '(vacío)' ) ); }

    if ( $raw === '' ) {
        return;
    }

    $seats = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );

    if ( empty( $seats ) ) {
        return;
    }

    // Guardar en el pedido directamente (accesible via get_meta en el order object)
    $order->update_meta_data( 'ss_seats', $seats );

    // Guardar session_id para que el guard universal pueda identificar al comprador
    if ( WC()->session ) {
        $order->update_meta_data( '_ss_checkout_session_id', (string) WC()->session->get_customer_id() );
    }

    // Enriquecer con zona si hay event_id en POST (MPWEM o SS Ticket Form)
    $event_id = ! empty( $_POST['mpwem_post_id'] ) ? (int) $_POST['mpwem_post_id']
              : ( ! empty( $_POST['ss_event_id'] ) ? (int) $_POST['ss_event_id'] : 0 );
    if ( $event_id > 0 ) {
        $order->update_meta_data( 'ss_event_id', $event_id );
        $seat_data = ss_build_seat_data( $seats, $event_id );
        $order->update_meta_data( 'ss_seat_data', $seat_data );
        // Extract unique zones for quick access
        $zones = array();
        foreach ( $seat_data as $sd ) {
            if ( ! empty( $sd['zone'] ) ) {
                $zones[] = $sd['zone'];
            }
        }
        if ( ! empty( $zones ) ) {
            $order->update_meta_data( 'ss_zone', implode( ', ', array_unique( $zones ) ) );
        }
    }

    if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] ss_seats guardado en pedido: ' . implode( ', ', $seats ) ); }
}

// ═══════════════════════════════════════════════════════════════════════════════
// SISTEMA QR + CHECK-IN — UN QR POR PEDIDO
// ═══════════════════════════════════════════════════════════════════════════════

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Genera el token de check-in para un pedido.
 * Combina el ID del pedido con la auth key de WordPress para que el
 * token sea único por instalación y no adivinable.
 */
function ss_checkin_token( int $order_id ): string {
    return hash( 'sha256', $order_id . wp_salt( 'auth' ) );
}

/**
 * Devuelve la URL de check-in completa para un pedido (legacy, para la página QR).
 */
function ss_checkin_url( int $order_id, string $token ): string {
    return home_url( '/ss-checkin/' . $order_id . '/' . $token . '/' );
}

/**
 * Devuelve el payload del QR: solo el token, sin URL.
 * Formato: SS-TICKET:{token}
 */
function ss_checkin_qr_payload( string $token ): string {
    return 'SS-TICKET:' . $token;
}

/**
 * Busca un pedido por su token de check-in.
 * Retorna el order_id o 0 si no se encuentra.
 */
function ss_find_order_by_token( string $token ): int {
    if ( empty( $token ) || strlen( $token ) !== 64 ) {
        return 0;
    }

    // HPOS-compatible: intentar con wc_get_orders primero
    if ( function_exists( 'wc_get_orders' ) ) {
        $orders = wc_get_orders( array(
            'meta_key'   => '_ss_checkin_token',
            'meta_value' => $token,
            'limit'      => 1,
            'return'     => 'ids',
        ) );
        if ( ! empty( $orders ) ) {
            return (int) $orders[0];
        }
    }

    // Fallback: WP meta query (legacy post meta)
    global $wpdb;
    $order_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ss_checkin_token' AND meta_value = %s LIMIT 1",
        $token
    ) );

    return $order_id ? (int) $order_id : 0;
}

/**
 * Genera (si no existe) el PNG del QR local con phpqrcode y devuelve su URL pública.
 * Guarda el archivo en wp-content/uploads/ss-qrs/order-{id}.png
 * y persiste la ruta en _ss_qr_path.
 */
function ss_qr_generate_local( int $order_id, string $qr_data, bool $force = false ): string {
    $upload = wp_upload_dir();
    $dir    = trailingslashit( $upload['basedir'] ) . 'ss-qrs';
    // Nombre impredecible: incluye hash parcial para evitar enumeración por order_id
    $name_hash = substr( hash( 'sha256', $order_id . '|qr|' . wp_salt( 'auth' ) ), 0, 16 );
    $file   = $dir . '/order-' . $order_id . '-' . $name_hash . '.png';
    $url    = trailingslashit( $upload['baseurl'] ) . 'ss-qrs/order-' . $order_id . '-' . $name_hash . '.png';

    // Migrar archivo legacy (nombre predecible) al nuevo nombre
    $legacy_file = $dir . '/order-' . $order_id . '.png';
    if ( file_exists( $legacy_file ) && ! file_exists( $file ) ) {
        rename( $legacy_file, $file );
    }

    // Crear directorio si no existe
    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
        file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );
        // Bloquear listado de directorio (Apache). No bloquear acceso directo a .png
        // porque los QRs deben ser accesibles públicamente para emails y thank-you page.
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Options -Indexes\n" );
        }
    }

    // Generar si no existe o si se fuerza regeneración
    if ( $force || ! file_exists( $file ) ) {
        require_once plugin_dir_path( __FILE__ ) . 'lib/phpqrcode/qrlib.php';
        QRcode::png( $qr_data, $file, QR_ECLEVEL_M, 6, 2 );
    }

    // Guardar ruta en meta del pedido (idempotente)
    update_post_meta( $order_id, '_ss_qr_path', $file );

    return $url;
}

// ═══════════════════════════════════════════════════════════════════════════════
// QR INDIVIDUAL POR ASIENTO
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Genera un token único por asiento: SHA256( order_id | seat_id | wp_salt ).
 */
function ss_checkin_seat_token( int $order_id, string $seat_id ): string {
    return hash( 'sha256', $order_id . '|' . $seat_id . '|' . wp_salt( 'auth' ) );
}

/**
 * Payload para QR de asiento individual.
 * Formato: SS-SEAT:{token}
 */
function ss_checkin_seat_qr_payload( string $token ): string {
    return 'SS-SEAT:' . $token;
}

/**
 * Genera el PNG del QR para un asiento individual.
 * Archivo: wp-content/uploads/ss-qrs/order-{id}-{seat}.png
 *
 * @return string URL pública del PNG.
 */
function ss_qr_generate_seat( int $order_id, string $seat_id, string $qr_data, bool $force = false ): string {
    $upload   = wp_upload_dir();
    $dir      = trailingslashit( $upload['basedir'] ) . 'ss-qrs';
    $safe     = preg_replace( '/[^A-Za-z0-9_-]/', '_', $seat_id );
    // Nombre impredecible para evitar enumeración
    $name_hash = substr( hash( 'sha256', $order_id . '|' . $seat_id . '|qr|' . wp_salt( 'auth' ) ), 0, 16 );
    $file     = $dir . '/order-' . $order_id . '-' . $safe . '-' . $name_hash . '.png';
    $url      = trailingslashit( $upload['baseurl'] ) . 'ss-qrs/order-' . $order_id . '-' . $safe . '-' . $name_hash . '.png';

    // Migrar archivo legacy
    $legacy_file = $dir . '/order-' . $order_id . '-' . $safe . '.png';
    if ( file_exists( $legacy_file ) && ! file_exists( $file ) ) {
        rename( $legacy_file, $file );
    }

    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
        file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );
        // Bloquear listado de directorio (Apache). No bloquear acceso directo a .png
        // porque los QRs deben ser accesibles públicamente para emails y thank-you page.
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Options -Indexes\n" );
        }
    }

    if ( $force || ! file_exists( $file ) ) {
        require_once plugin_dir_path( __FILE__ ) . 'lib/phpqrcode/qrlib.php';
        QRcode::png( $qr_data, $file, QR_ECLEVEL_M, 6, 2 );
    }

    return $url;
}

/**
 * Genera QRs individuales para todos los asientos de un pedido.
 * Guarda los tokens en order meta `_ss_seat_tokens` = { "A1": "abc...", "A2": "def..." }
 *
 * @return array  { "A1": "https://.../order-123-A1.png", ... }
 */
function ss_generate_seat_qrs( int $order_id, array $seats ): array {
    $tokens = array();
    $urls   = array();

    foreach ( $seats as $seat_id ) {
        $token   = ss_checkin_seat_token( $order_id, $seat_id );
        $payload = ss_checkin_seat_qr_payload( $token );
        $url     = ss_qr_generate_seat( $order_id, $seat_id, $payload, true );

        $tokens[ $seat_id ] = $token;
        $urls[ $seat_id ]   = $url;
    }

    update_post_meta( $order_id, '_ss_seat_tokens', $tokens );

    return $urls;
}

// ═══════════════════════════════════════════════════════════════════════════════
// QR INDIVIDUAL POR TICKET (ZONA)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Token único por ticket de zona: SHA256( order_id | ticket_index | wp_salt ).
 * El ticket_id tiene formato "ZONA-1", "ZONA-2", etc.
 */
function ss_checkin_ticket_token( int $order_id, string $ticket_id ): string {
    return hash( 'sha256', $order_id . '|ticket|' . $ticket_id . '|' . wp_salt( 'auth' ) );
}

/**
 * Payload para QR de ticket individual.
 * Formato: SS-TICKET:{token}
 */
function ss_checkin_ticket_qr_payload( string $token ): string {
    return 'SS-ZONETICKET:' . $token;
}

/**
 * Genera el PNG del QR para un ticket individual de zona.
 * Archivo: wp-content/uploads/ss-qrs/order-{id}-{ticket_id}-{hash}.png
 */
function ss_qr_generate_ticket( int $order_id, string $ticket_id, string $qr_data, bool $force = false ): string {
    $upload   = wp_upload_dir();
    $dir      = trailingslashit( $upload['basedir'] ) . 'ss-qrs';
    $safe     = preg_replace( '/[^A-Za-z0-9_-]/', '_', $ticket_id );
    $name_hash = substr( hash( 'sha256', $order_id . '|ticket|' . $ticket_id . '|qr|' . wp_salt( 'auth' ) ), 0, 16 );
    $file     = $dir . '/order-' . $order_id . '-' . $safe . '-' . $name_hash . '.png';
    $url      = trailingslashit( $upload['baseurl'] ) . 'ss-qrs/order-' . $order_id . '-' . $safe . '-' . $name_hash . '.png';

    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
        file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Options -Indexes\n" );
        }
    }

    if ( $force || ! file_exists( $file ) ) {
        require_once plugin_dir_path( __FILE__ ) . 'lib/phpqrcode/qrlib.php';

        // Generar QR en archivo temporal
        $tmp_file = $file . '.tmp.png';
        QRcode::png( $qr_data, $tmp_file, QR_ECLEVEL_M, 6, 2 );

        // Agregar pestaña con label encima del QR
        $qr_img = @imagecreatefrompng( $tmp_file );
        if ( $qr_img ) {
            $qr_w = imagesx( $qr_img );
            $qr_h = imagesy( $qr_img );

            $label      = $ticket_id . ' · #' . $order_id;
            $tab_height = 28;
            $font_size  = 3; // GD built-in font (1-5)

            // Crear imagen final con pestaña arriba
            $final = imagecreatetruecolor( $qr_w, $qr_h + $tab_height );
            $bg    = imagecolorallocate( $final, 17, 17, 30 );   // #11111e (dark)
            $white = imagecolorallocate( $final, 255, 255, 255 );

            // Fondo de la pestaña
            imagefilledrectangle( $final, 0, 0, $qr_w - 1, $tab_height - 1, $bg );

            // Texto centrado
            $text_w = imagefontwidth( $font_size ) * strlen( $label );
            $text_x = (int) ( ( $qr_w - $text_w ) / 2 );
            $text_y = (int) ( ( $tab_height - imagefontheight( $font_size ) ) / 2 );
            imagestring( $final, $font_size, max( $text_x, 4 ), $text_y, $label, $white );

            // Copiar QR debajo de la pestaña
            imagecopy( $final, $qr_img, 0, $tab_height, 0, 0, $qr_w, $qr_h );

            imagepng( $final, $file );
            imagedestroy( $final );
            imagedestroy( $qr_img );
            @unlink( $tmp_file );
        } else {
            // Fallback: usar QR sin pestaña
            rename( $tmp_file, $file );
        }
    }

    return $url;
}

/**
 * Genera QRs individuales para todos los tickets de zona de un pedido.
 * Guarda los tokens en order meta `_ss_ticket_tokens` = { "GENERAL-1": "abc...", ... }
 *
 * @param int   $order_id
 * @param array $ticket_qtys  { "GENERAL": 5, "VIP": 3 }
 * @return array  { "GENERAL-1": "https://.../order-X-GENERAL_1-hash.png", ... }
 */
function ss_generate_ticket_qrs( int $order_id, array $ticket_qtys ): array {
    $tokens = array();
    $urls   = array();

    foreach ( $ticket_qtys as $zone_name => $qty ) {
        $qty = (int) $qty;
        for ( $i = 1; $i <= $qty; $i++ ) {
            $ticket_id = strtoupper( $zone_name ) . '-' . $i;
            $token     = ss_checkin_ticket_token( $order_id, $ticket_id );
            $payload   = ss_checkin_ticket_qr_payload( $token );
            $url       = ss_qr_generate_ticket( $order_id, $ticket_id, $payload, true );

            $tokens[ $ticket_id ] = $token;
            $urls[ $ticket_id ]   = $url;
        }
    }

    update_post_meta( $order_id, '_ss_ticket_tokens', $tokens );

    return $urls;
}

/**
 * Busca un pedido por token de ticket individual de zona.
 *
 * @return array  { order_id: int, ticket_id: string } o vacío.
 */
function ss_find_order_by_ticket_token( string $token ): array {
    if ( empty( $token ) || strlen( $token ) !== 64 ) {
        return array();
    }

    global $wpdb;

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_ss_ticket_tokens' AND meta_value LIKE %s
             LIMIT 5",
            '%' . $wpdb->esc_like( $token ) . '%'
        ),
        ARRAY_A
    );

    foreach ( $rows as $row ) {
        $tokens = maybe_unserialize( $row['meta_value'] );
        if ( ! is_array( $tokens ) ) { continue; }
        foreach ( $tokens as $ticket_id => $stored_token ) {
            if ( hash_equals( $stored_token, $token ) ) {
                return array(
                    'order_id'  => (int) $row['post_id'],
                    'ticket_id' => $ticket_id,
                );
            }
        }
    }

    return array();
}

/**
 * Marca un ticket individual de zona como ingresado.
 * Meta: _ss_ticket_checkins = { "GENERAL-1": "2026-03-24 14:30:00", ... }
 *
 * @return string 'valid' | 'already_used'
 */
function ss_checkin_mark_ticket( int $order_id, string $ticket_id ): string {
    $checkins = get_post_meta( $order_id, '_ss_ticket_checkins', true );
    if ( ! is_array( $checkins ) ) { $checkins = array(); }

    if ( ! empty( $checkins[ $ticket_id ] ) ) {
        return 'already_used';
    }

    $checkins[ $ticket_id ] = current_time( 'mysql' );
    update_post_meta( $order_id, '_ss_ticket_checkins', $checkins );

    // Si todos los tickets del pedido ya ingresaron, marcar pedido completo
    $all_tokens = get_post_meta( $order_id, '_ss_ticket_tokens', true );
    if ( is_array( $all_tokens ) ) {
        $all_in = true;
        foreach ( array_keys( $all_tokens ) as $tid ) {
            if ( empty( $checkins[ $tid ] ) ) { $all_in = false; break; }
        }
        if ( $all_in ) {
            // Solo marcar como checked_in completo si tampoco hay seat_tokens pendientes
            $seat_tokens = get_post_meta( $order_id, '_ss_seat_tokens', true );
            if ( empty( $seat_tokens ) || ! is_array( $seat_tokens ) ) {
                update_post_meta( $order_id, '_ss_checked_in', 'yes' );
                update_post_meta( $order_id, '_ss_checked_in_time', current_time( 'mysql' ) );
            }
        }
    }

    $order = wc_get_order( $order_id );
    if ( $order ) {
        $order->add_order_note( sprintf( 'Ingreso registrado para ticket %s vía QR individual.', $ticket_id ) );
    }

    return 'valid';
}

/**
 * Busca un pedido por token de asiento individual.
 * Recorre _ss_seat_tokens de pedidos processing/completed.
 *
 * @return array  { order_id: int, seat_id: string } o vacío si no encontrado.
 */
function ss_find_order_by_seat_token( string $token ): array {
    if ( empty( $token ) || strlen( $token ) !== 64 ) {
        return array();
    }

    global $wpdb;

    // Buscar en postmeta _ss_seat_tokens (serialized array)
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_ss_seat_tokens' AND meta_value LIKE %s
             LIMIT 5",
            '%' . $wpdb->esc_like( $token ) . '%'
        ),
        ARRAY_A
    );

    foreach ( $rows as $row ) {
        $tokens = maybe_unserialize( $row['meta_value'] );
        if ( ! is_array( $tokens ) ) { continue; }
        foreach ( $tokens as $seat_id => $stored_token ) {
            if ( hash_equals( $stored_token, $token ) ) {
                return array(
                    'order_id' => (int) $row['post_id'],
                    'seat_id'  => $seat_id,
                );
            }
        }
    }

    return array();
}

/**
 * Marca un asiento individual como ingresado.
 * Meta: _ss_seat_checkins = { "A1": "2026-03-10 14:30:00", "A2": null, ... }
 *
 * @return string 'valid' | 'already_used'
 */
function ss_checkin_mark_seat( int $order_id, string $seat_id ): string {
    $checkins = get_post_meta( $order_id, '_ss_seat_checkins', true );
    if ( ! is_array( $checkins ) ) { $checkins = array(); }

    if ( ! empty( $checkins[ $seat_id ] ) ) {
        return 'already_used';
    }

    $checkins[ $seat_id ] = current_time( 'mysql' );
    update_post_meta( $order_id, '_ss_seat_checkins', $checkins );

    // Si todos los asientos del pedido ya ingresaron, marcar pedido completo
    $all_tokens = get_post_meta( $order_id, '_ss_seat_tokens', true );
    if ( is_array( $all_tokens ) ) {
        $all_in = true;
        foreach ( array_keys( $all_tokens ) as $sid ) {
            if ( empty( $checkins[ $sid ] ) ) { $all_in = false; break; }
        }
        if ( $all_in ) {
            update_post_meta( $order_id, '_ss_checked_in', 'yes' );
            update_post_meta( $order_id, '_ss_checked_in_time', current_time( 'mysql' ) );
        }
    }

    $order = wc_get_order( $order_id );
    if ( $order ) {
        $order->add_order_note( sprintf( 'Ingreso registrado para asiento %s vía QR individual.', $seat_id ) );
    }

    return 'valid';
}

// ── Generación del token al completar el pedido ───────────────────────────────

// ── Aviso + redirección si el pedido fue cancelado por conflicto de asiento ──
// ── Fix: barras blancas en página de confirmación de pedido ─────────────────

add_action( 'woocommerce_before_thankyou', 'ss_thankyou_inject_styles', 1 );

function ss_thankyou_inject_styles(): void {
    echo '<style>
/* SS Fix: WooCommerce order-received page — dark theme contrast */
body.woocommerce-order-received .woocommerce-order,
body.woocommerce-order-received .woocommerce-order-details,
body.woocommerce-order-received .woocommerce-customer-details,
body.woocommerce-order-received .woocommerce-bacs-bank-details,
body.woocommerce-order-received .woocommerce-order-overview,
body.woocommerce-order-received table.woocommerce-table,
body.woocommerce-order-received table.shop_table {
    background: transparent !important;
    color: inherit !important;
}
body.woocommerce-order-received table.woocommerce-table td,
body.woocommerce-order-received table.woocommerce-table th,
body.woocommerce-order-received table.shop_table td,
body.woocommerce-order-received table.shop_table th {
    background: transparent !important;
    border-color: rgba(255,255,255,0.1) !important;
}
body.woocommerce-order-received address {
    background: transparent !important;
    border: none !important;
}
</style>' . "\n";
}

add_action( 'woocommerce_before_thankyou', 'ss_thankyou_cancelled_notice', 5, 1 );

function ss_thankyou_cancelled_notice( $order_id ): void {
    if ( ! $order_id ) {
        return;
    }
    $order = wc_get_order( $order_id );
    if ( ! $order || $order->get_status() !== 'cancelled' ) {
        return;
    }

    wc_print_notice(
        'Tu pedido fue cancelado automáticamente porque la silla seleccionada ya fue comprada por otro usuario al mismo tiempo. Por favor selecciona otra silla.',
        'error'
    );

    $event_id = $order->get_meta( 'ss_event_id' );
    if ( $event_id ) {
        $event_url = get_permalink( $event_id );
        if ( $event_url ) {
            echo '<p style="text-align:center;font-weight:600;margin:10px 0 20px;">'
                . esc_html__( 'Serás redirigido automáticamente al evento para seleccionar otra silla.', 'ss-seating' )
                . '</p>';
            echo '<script>setTimeout(function(){window.location.href="' . esc_url( $event_url ) . '";},4000);</script>';
        }
    }
}

/**
 * Verifica si un pedido contiene al menos un item de evento (con ss_event_id).
 */
function ss_order_has_event_items( int $order_id ): bool {
    $order = wc_get_order( $order_id );
    if ( ! $order ) { return false; }
    foreach ( $order->get_items() as $item ) {
        if ( $item->get_meta( 'ss_event_id' ) ) {
            return true;
        }
    }
    return false;
}

add_action( 'woocommerce_thankyou', 'ss_generate_checkin_token', 10, 1 );

function ss_generate_checkin_token( int $order_id ): void {
    if ( ! $order_id || ! ss_order_has_event_items( $order_id ) ) {
        return;
    }
    // Generar token si no existe
    $token = get_post_meta( $order_id, '_ss_checkin_token', true );
    if ( ! $token ) {
        $token = ss_checkin_token( $order_id );
        update_post_meta( $order_id, '_ss_checkin_token', $token );
    }
    // Generar QR con payload SS-TICKET:{token} (forzar regeneración si existe versión vieja)
    $payload = ss_checkin_qr_payload( $token );
    ss_qr_generate_local( $order_id, $payload, true );
    // NOTA: QRs por asiento solo se generan desde Box Office cuando el usuario elige "QR individual".
}

// ═══════════════════════════════════════════════════════════════════════════════
// TICKET VISUAL — helper para extraer datos del evento desde un pedido
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Extrae datos del evento asociado a un pedido WooCommerce.
 * Retorna null si el pedido no corresponde a un evento.
 */
function ss_get_event_ticket_data( int $order_id ): ?array {
    $order = wc_get_order( $order_id );
    if ( ! $order ) { return null; }

    // Buscar event_id
    $event_id = (int) $order->get_meta( 'ss_event_id' );
    if ( ! $event_id ) {
        $event_id = ss_get_event_id_from_order( $order );
    }
    if ( ! $event_id || get_post_type( $event_id ) !== 'ss_event' ) {
        return null;
    }

    // Nombre del evento
    $event_name = get_the_title( $event_id );

    // Organizador — desde meta del evento, fallback al nombre del sitio
    $organizer = get_post_meta( $event_id, '_ss_organizer_name', true ) ?: get_bloginfo( 'name' );

    // Fecha y hora del evento
    // Los valores se guardan como hora local de WordPress (America/Bogota, etc.).
    // strtotime() usa la zona del servidor (puede ser UTC), así que usamos
    // DateTime con la zona de WP para obtener el timestamp correcto.
    $event_date = '';
    $event_time = '';
    $wp_tz = wp_timezone();

    $start_dt = get_post_meta( $event_id, '_ss_event_start_datetime', true );
    if ( $start_dt ) {
        try {
            $dt = new DateTime( $start_dt, $wp_tz );
            $ts = $dt->getTimestamp();
            $event_date = wp_date( 'j \d\e F, Y', $ts );
            if ( wp_date( 'H:i', $ts ) !== '00:00' ) {
                $event_time = wp_date( 'g:i A', $ts );
            }
        } catch ( Exception $e ) { /* formato inválido */ }
    }
    if ( ! $event_date ) {
        // Fallback: campos separados _ss_event_date + _ss_event_time
        $raw_date = get_post_meta( $event_id, '_ss_event_date', true );
        $raw_time = get_post_meta( $event_id, '_ss_event_time', true );
        if ( $raw_date && $raw_time ) {
            $raw_date = "$raw_date $raw_time";
        }
        if ( $raw_date ) {
            try {
                $dt = new DateTime( $raw_date, $wp_tz );
                $ts = $dt->getTimestamp();
                $event_date = wp_date( 'j \d\e F, Y', $ts );
                if ( wp_date( 'H:i', $ts ) !== '00:00' ) {
                    $event_time = wp_date( 'g:i A', $ts );
                }
            } catch ( Exception $e ) { /* formato inválido */ }
        }
    }

    // Zona y asientos desde los items del pedido
    $seats_list = array();
    $zones_list = array();
    foreach ( $order->get_items() as $item_id => $item ) {
        $seats = wc_get_order_item_meta( $item_id, 'ss_seats', true );
        if ( ! empty( $seats ) && is_array( $seats ) ) {
            $seats_list = array_merge( $seats_list, $seats );
        }
        $zone = wc_get_order_item_meta( $item_id, 'ss_zone', true );
        if ( $zone && is_string( $zone ) ) {
            $zones_list[] = $zone;
        }
    }

    // QR URL del pedido
    $token = get_post_meta( $order_id, '_ss_checkin_token', true );
    if ( ! $token ) {
        $token = ss_checkin_token( $order_id );
        update_post_meta( $order_id, '_ss_checkin_token', $token );
    }
    $payload = ss_checkin_qr_payload( $token );
    $qr_url  = ss_qr_generate_local( $order_id, $payload );

    // QRs por asiento (si Box Office los generó)
    $seat_tokens = get_post_meta( $order_id, '_ss_seat_tokens', true );

    // QRs por ticket de zona (si Box Office los generó)
    $ticket_tokens = get_post_meta( $order_id, '_ss_ticket_tokens', true );

    return array(
        'event_id'      => $event_id,
        'organizer'     => $organizer,
        'event_name'    => $event_name,
        'event_date'    => $event_date ?: '—',
        'event_time'    => $event_time ?: '—',
        'zone'          => implode( ', ', array_unique( $zones_list ) ) ?: '—',
        'seats'         => $seats_list,
        'qr_url'        => $qr_url,
        'seat_tokens'   => is_array( $seat_tokens ) ? $seat_tokens : array(),
        'ticket_tokens' => is_array( $ticket_tokens ) ? $ticket_tokens : array(),
        'order_id'      => $order_id,
    );
}

/**
 * Renderiza un ticket visual individual.
 */
function ss_render_ticket_html( array $data, string $qr_url, string $seat_label, bool $show_buttons = false ): string {
    $order_id = $data['order_id'];
    $token    = get_post_meta( $order_id, '_ss_checkin_token', true );

    // Calcular hora de apertura de puertas
    $doors_minutes = (int) SS_Settings::get( 'doors_open_minutes', 30 );
    $doors_time    = '';
    if ( $doors_minutes > 0 && ! empty( $data['event_time'] ) && $data['event_time'] !== '—' ) {
        try {
            $dt = new DateTime( $data['event_time'], wp_timezone() );
            $dt->modify( '-' . $doors_minutes . ' minutes' );
            $doors_time = wp_date( 'g:i A', $dt->getTimestamp() );
        } catch ( Exception $e ) { /* formato inválido */ }
    }

    $html = '<div style="max-width:420px;margin:40px auto;border:2px solid #111;border-radius:12px;font-family:Arial,sans-serif;overflow:hidden;background:#ffffff;box-shadow:0 6px 18px rgba(0,0,0,0.15);text-align:center;">'

         . '<div style="background:#111;color:#fff;padding:14px;text-align:center;font-size:18px;letter-spacing:2px;font-weight:bold;">'
         . esc_html( $data['organizer'] )
         . '</div>'

         . '<div style="text-align:center;padding:8px;font-size:15px;font-weight:bold;border-bottom:1px dashed #ccc;">'
         . esc_html( $data['event_name'] )
         . '</div>'

         . '<div style="padding:20px;font-size:14px;line-height:1.7;text-align:left;display:inline-block;">'
         . '<b>Fecha:</b> ' . esc_html( $data['event_date'] ) . '<br>'
         . '<b>Hora:</b> ' . esc_html( $data['event_time'] ) . '<br>'
         . ( $doors_time ? '<b>Puertas abren:</b> ' . esc_html( $doors_time ) . '<br>' : '' )
         . '<b>Zona:</b> ' . esc_html( $data['zone'] ) . '<br>'
         . '<b>Asiento:</b> ' . esc_html( $seat_label )
         . '</div>'

         . '<div style="text-align:center;padding:15px;border-top:1px dashed #ccc;">'
         . '<img src="' . esc_url( $qr_url ) . '" style="display:block;margin:0 auto;width:240px;height:240px;" alt="QR de ingreso">'
         . '<div style="font-size:11px;color:#888;margin-top:8px;">Pedido #' . esc_html( $order_id ) . ' &mdash; ' . esc_html( $seat_label ) . '</div>'
         . '<div style="font-size:12px;color:#666;margin-top:6px;">Presenta este código en la entrada</div>'
         . '</div>';

    // Botones solo en thank-you page (no en email ni PDF)
    if ( $show_buttons && $token ) {
        $pdf_url    = home_url( '/ss-ticket-pdf/' . $order_id . '/' . $token . '/' );
        $wallet_url = home_url( '/ss-ticket-wallet/' . $order_id . '/' . $token . '/' );

        $btn_style = 'display:inline-block;padding:10px 20px;margin:6px;border-radius:8px;'
                   . 'font-size:13px;font-weight:600;text-decoration:none;cursor:pointer;';

        $html .= '<div style="padding:12px 15px 18px;border-top:1px dashed #ccc;">'
              . '<a href="' . esc_url( $pdf_url ) . '" target="_blank" style="'
              . $btn_style . 'background:#111;color:#fff;">Descargar Ticket PDF</a>'
              . '<a href="' . esc_url( $wallet_url ) . '" style="'
              . $btn_style . 'background:#fff;color:#111;border:2px solid #111;">Agregar al Calendario</a>'
              . '</div>';
    }

    $html .= '</div>';

    return $html;
}

/**
 * Renderiza el bloque completo de tickets para un pedido.
 * Si tiene QRs por asiento → un ticket por asiento.
 * Si no → un ticket con todos los asientos.
 */
function ss_render_ticket_block( int $order_id, bool $show_buttons = false ): string {
    $data = ss_get_event_ticket_data( $order_id );
    if ( ! $data ) { return ''; }

    $html = '';

    // Si hay QRs individuales por asiento (Box Office con modo seat/hybrid)
    if ( ! empty( $data['seat_tokens'] ) && count( $data['seat_tokens'] ) > 1 ) {
        foreach ( $data['seat_tokens'] as $seat_id => $token ) {
            $payload = ss_checkin_seat_qr_payload( $token );
            $qr_url  = ss_qr_generate_seat( $order_id, $seat_id, $payload );
            if ( $qr_url ) {
                $html .= ss_render_ticket_html( $data, $qr_url, $seat_id, $show_buttons );
            }
        }
        return $html;
    }

    // Si hay QRs individuales por ticket de zona (Box Office con modo zone)
    if ( ! empty( $data['ticket_tokens'] ) && count( $data['ticket_tokens'] ) > 1 ) {
        foreach ( $data['ticket_tokens'] as $ticket_id => $token ) {
            $payload = ss_checkin_ticket_qr_payload( $token );
            $qr_url  = ss_qr_generate_ticket( $order_id, $ticket_id, $payload );
            if ( $qr_url ) {
                $html .= ss_render_ticket_html( $data, $qr_url, $ticket_id, $show_buttons );
            }
        }
        return $html;
    }

    // Ticket único con QR del pedido
    $seat_label = ! empty( $data['seats'] ) ? implode( ', ', $data['seats'] ) : '—';
    $html .= ss_render_ticket_html( $data, $data['qr_url'], $seat_label, $show_buttons );

    return $html;
}

// ── Mostrar ticket en la pantalla Thank You ──────────────────────────────────

add_action( 'woocommerce_thankyou', 'ss_thankyou_qr_display', 20, 1 );

function ss_thankyou_qr_display( int $order_id ): void {
    if ( ! $order_id || ! ss_order_has_event_items( $order_id ) ) {
        return;
    }
    $token = get_post_meta( $order_id, '_ss_checkin_token', true );
    if ( ! $token ) {
        return;
    }

    // Ticket visual para pedidos de evento (con botones PDF/Wallet)
    $ticket_html = ss_render_ticket_block( $order_id, true );
    if ( $ticket_html ) {
        echo $ticket_html;
        return;
    }

    // Fallback: QR simple
    $payload = ss_checkin_qr_payload( $token );
    echo ss_render_qr_block( $payload, $order_id );
}

// ── Mostrar ticket en el email de pedido ─────────────────────────────────────

add_action( 'woocommerce_email_after_order_table', 'ss_email_qr_display', 10, 4 );

function ss_email_qr_display( $order, bool $sent_to_admin, bool $plain_text, $email ): void {
    if ( $plain_text ) {
        return;
    }
    $order_id = $order->get_id();
    if ( ! ss_order_has_event_items( $order_id ) ) {
        return;
    }
    $token = get_post_meta( $order_id, '_ss_checkin_token', true );
    if ( ! $token ) {
        $token = ss_checkin_token( $order_id );
        update_post_meta( $order_id, '_ss_checkin_token', $token );
    }

    // Ticket visual para pedidos de evento
    $ticket_html = ss_render_ticket_block( $order_id );
    if ( $ticket_html ) {
        echo $ticket_html;
        return;
    }

    // Fallback: QR simple
    $payload = ss_checkin_qr_payload( $token );
    echo ss_render_qr_block( $payload, $order_id, true );
}

// ── Mostrar QR en el admin del pedido ────────────────────────────────────────

add_action( 'woocommerce_admin_order_data_after_billing_address', 'ss_admin_qr_display', 10, 1 );

function ss_admin_qr_display( $order ): void {
    $order_id = $order->get_id();
    $token    = get_post_meta( $order_id, '_ss_checkin_token', true );
    if ( ! $token ) {
        $token = ss_checkin_token( $order_id );
        update_post_meta( $order_id, '_ss_checkin_token', $token );
    }
    $payload      = ss_checkin_qr_payload( $token );
    $checked_in   = get_post_meta( $order_id, '_ss_checked_in', true );
    $status_label = $checked_in === 'yes'
        ? '<span style="color:#2e7d32;font-weight:700;">✔ Ingreso registrado</span>'
        : '<span style="color:#b71c1c;font-weight:700;">⏳ Pendiente de ingreso</span>';

    echo '<div style="margin:16px 0;padding:12px;border:1px solid #ddd;border-radius:6px;">';
    echo '<p style="margin:0 0 8px;font-weight:600;">QR de Check-in</p>';
    $admin_qr_url = ss_qr_generate_local( $order_id, $payload );
    if ( $admin_qr_url ) {
        echo '<img src="' . esc_url( $admin_qr_url ) . '" width="150" height="150" alt="QR Check-in">';
    }
    echo '<p style="margin:8px 0 0;">' . $status_label . '</p>';
    echo '<p style="margin:4px 0 0;font-size:11px;word-break:break-all;color:#666;">Token: ' . esc_html( substr( $token, 0, 12 ) ) . '…</p>';
    echo '</div>';
}

// ── Renderizador compartido del bloque QR ────────────────────────────────────

/**
 * Devuelve el HTML del bloque QR.
 *
 * @param string $qr_payload Datos que codifica el QR (SS-TICKET:{token}).
 * @param int    $order_id   ID del pedido.
 * @param bool   $is_email   Ajusta estilos para clientes de correo.
 */
function ss_render_qr_block( string $qr_payload, int $order_id, bool $is_email = false ): string {
    // Obtener (o generar) la URL pública del QR local
    $qr_url = ss_qr_generate_local( $order_id, $qr_payload );

    if ( ! $qr_url ) {
        return '<p style="color:#999;font-size:13px;">QR no disponible.</p>';
    }

    $size    = $is_email ? 180 : 200;
    $wrapper = $is_email
        ? 'style="font-family:Arial,sans-serif;padding:16px;border:1px solid #ddd;border-radius:6px;text-align:center;margin:16px 0;"'
        : 'class="ss-qr-block" style="padding:16px;border:1px solid #e0e0e0;border-radius:8px;text-align:center;margin:24px 0;"';

    return '<div ' . $wrapper . '>'
         . '<p style="margin:0 0 10px;font-size:15px;font-weight:700;">🎭 Tu código de ingreso</p>'
         . '<img src="' . esc_url( $qr_url ) . '" width="' . $size . '" height="' . $size . '"'
         . ' alt="QR de ingreso al evento" style="display:block;margin:0 auto;">'
         . '<p style="margin:10px 0 0;font-size:12px;color:#666;">Pedido #' . esc_html( $order_id ) . ' · Presenta este código en la entrada</p>'
         . '</div>';
}

/**
 * Renderiza bloque HTML con QRs individuales por asiento.
 */
function ss_render_seat_qrs_block( int $order_id, array $seat_tokens, bool $is_email = false ): string {
    $size    = $is_email ? 140 : 160;
    $wrapper = $is_email
        ? 'style="font-family:Arial,sans-serif;padding:16px;border:1px solid #ddd;border-radius:6px;text-align:center;margin:16px 0;"'
        : 'class="ss-qr-block" style="padding:16px;border:1px solid #e0e0e0;border-radius:8px;text-align:center;margin:24px 0;"';

    $html  = '<div ' . $wrapper . '>';
    $html .= '<p style="margin:0 0 12px;font-size:15px;font-weight:700;">🎭 Códigos de ingreso por asiento</p>';
    $html .= '<div style="display:flex;flex-wrap:wrap;gap:16px;justify-content:center;">';

    foreach ( $seat_tokens as $seat_id => $token ) {
        $payload = ss_checkin_seat_qr_payload( $token );
        $qr_url  = ss_qr_generate_seat( $order_id, $seat_id, $payload );
        if ( ! $qr_url ) { continue; }

        $html .= '<div style="text-align:center;">';
        $html .= '<div style="font-weight:700;font-size:14px;margin-bottom:4px;">' . esc_html( $seat_id ) . '</div>';
        $html .= '<img src="' . esc_url( $qr_url ) . '" width="' . $size . '" height="' . $size . '"'
               . ' alt="QR asiento ' . esc_attr( $seat_id ) . '" style="display:block;margin:0 auto;">';
        $html .= '</div>';
    }

    $html .= '</div>';
    $html .= '<p style="margin:10px 0 0;font-size:12px;color:#666;">Pedido #' . esc_html( $order_id ) . ' · Cada asiento tiene su propio código</p>';
    $html .= '</div>';

    return $html;
}

// ── Rewrite rule para el endpoint /ss-checkin/{id}/{token}/ ──────────────────

add_action( 'init', 'ss_checkin_rewrite_rule' );

function ss_checkin_rewrite_rule(): void {
    add_rewrite_rule(
        '^ss-checkin/(\d+)/([a-f0-9]{64})/?$',
        'index.php?ss_checkin_order=$matches[1]&ss_checkin_token=$matches[2]',
        'top'
    );
    // Frontend check-in dashboard: /control-ingreso/{event_id}/
    add_rewrite_rule(
        '^control-ingreso/(\d+)/?$',
        'index.php?ss_control_ingreso=$matches[1]',
        'top'
    );
    // Ticket PDF: /ss-ticket-pdf/{order_id}/{token}/
    add_rewrite_rule(
        '^ss-ticket-pdf/(\d+)/([a-f0-9]{64})/?$',
        'index.php?ss_ticket_pdf=$matches[1]&ss_ticket_token=$matches[2]',
        'top'
    );
    // Ticket Wallet: /ss-ticket-wallet/{order_id}/{token}/
    add_rewrite_rule(
        '^ss-ticket-wallet/(\d+)/([a-f0-9]{64})/?$',
        'index.php?ss_ticket_wallet=$matches[1]&ss_ticket_token=$matches[2]',
        'top'
    );
}

add_filter( 'query_vars', 'ss_checkin_query_vars' );

function ss_checkin_query_vars( array $vars ): array {
    $vars[] = 'ss_checkin_order';
    $vars[] = 'ss_checkin_token';
    $vars[] = 'ss_control_ingreso';
    $vars[] = 'ss_ticket_pdf';
    $vars[] = 'ss_ticket_wallet';
    $vars[] = 'ss_ticket_token';
    return $vars;
}

// IMPORTANTE: visita Ajustes → Enlaces permanentes tras activar el plugin
// para que WordPress regenere las reglas de rewrite.

// ── Controlador del endpoint de check-in ─────────────────────────────────────

add_action( 'template_redirect', 'ss_checkin_handle_request' );

function ss_checkin_handle_request(): void {
    $order_id = (int) get_query_var( 'ss_checkin_order' );
    $token    = sanitize_text_field( get_query_var( 'ss_checkin_token' ) );

    if ( ! $order_id || ! $token ) {
        return; // No es nuestra ruta
    }

    // ── Validación ──────────────────────────────────────────────────────────

    $order = wc_get_order( $order_id );

    if ( ! $order ) {
        ss_checkin_render_page( 'error', 'Pedido no encontrado.' );
        exit;
    }

    $saved_token = get_post_meta( $order_id, '_ss_checkin_token', true );

    if ( ! hash_equals( $saved_token, $token ) ) {
        ss_checkin_render_page( 'error', 'Código de verificación inválido.' );
        exit;
    }

    // ── Verificar estado del pedido ──────────────────────────────────────────
    $status = $order->get_status();
    if ( ! in_array( $status, array( 'processing', 'completed' ), true ) ) {
        ss_checkin_render_page( 'error', 'Acceso no autorizado. El enlace no es válido o no tiene permisos para acceder al control de ingreso.' );
        exit;
    }

    // ── Acción: marcar ingreso ───────────────────────────────────────────────

    if (
        isset( $_POST['ss_action'] ) &&
        $_POST['ss_action'] === 'mark_checkin' &&
        isset( $_POST['ss_nonce'] ) &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ss_nonce'] ) ), 'ss_checkin_' . $order_id )
    ) {
        update_post_meta( $order_id, '_ss_checked_in', 'yes' );
        update_post_meta( $order_id, '_ss_checked_in_time', current_time( 'mysql' ) );
        $order->add_order_note( __( 'Ingreso registrado mediante escáner QR.', 'ss-seating' ) );
    }

    // ── Recopilar datos para la vista ────────────────────────────────────────

    $buyer      = $order->get_formatted_billing_full_name();
    $checked_in = get_post_meta( $order_id, '_ss_checked_in', true );
    $checkin_time = get_post_meta( $order_id, '_ss_checked_in_time', true );

    // Sillas + info de boletas desde los items del pedido
    $seats_list    = array();
    $tickets_info  = array(); // [ "VIP x 2", "GENERAL x 1" ]
    $zones_list    = array();
    $event_name    = '';
    $event_id      = 0;
    $ticket_qty    = 0;

    foreach ( $order->get_items() as $item_id => $item ) {
        // Sillas — usar wc_get_order_item_meta para lectura fiable
        $seats = wc_get_order_item_meta( $item_id, 'ss_seats', true );

        if ( ! empty( $seats ) && is_array( $seats ) ) {
            $seats_list = array_merge( $seats_list, $seats );
            $ticket_qty += count( $seats );
        } else {
            // Leer qty real desde nuestro meta (MPWEM siempre reporta qty=1)
            $saved_qty = (int) wc_get_order_item_meta( $item_id, 'ss_ticket_qty', true );
            $ticket_qty += $saved_qty > 0 ? $saved_qty : $item->get_quantity();
        }

        // Zona directa desde ss_zone (fuente principal)
        $item_zone = wc_get_order_item_meta( $item_id, 'ss_zone', true );
        if ( $item_zone && is_string( $item_zone ) ) {
            $zones_list[] = $item_zone;
        }
        // Fallback: zonas desde ss_seat_data
        if ( ! $item_zone ) {
            $sd = wc_get_order_item_meta( $item_id, 'ss_seat_data', true );
            if ( is_array( $sd ) ) {
                foreach ( $sd as $entry ) {
                    if ( ! empty( $entry['zone'] ) ) {
                        $zones_list[] = $entry['zone'];
                    }
                }
            }
        }

        // Evento
        if ( ! $event_name ) {
            $product = $item->get_product();
            if ( $product ) {
                $event_name = $product->get_name();
            }
        }

        // Event ID
        $item_event = (int) wc_get_order_item_meta( $item_id, 'ss_event_id', true );
        if ( $item_event > 0 ) {
            $event_id = $item_event;
        }

        // Ticket info — usar ss_ticket_qty para qty real
        $real_qty = (int) wc_get_order_item_meta( $item_id, 'ss_ticket_qty', true );
        $qty  = $real_qty > 0 ? $real_qty : $item->get_quantity();
        $name = $item->get_name();
        if ( $qty > 0 && $name ) {
            $tickets_info[] = $name . ' x ' . $qty;
        }
    }

    // Fallback event_id
    if ( ! $event_id ) {
        $event_id = ss_get_event_id_from_order( $order );
    }

    // Siempre usar el título real del post ss_event (puede diferir del producto tras un traslado)
    if ( $event_id ) {
        $real_title = get_the_title( $event_id );
        if ( $real_title ) {
            $event_name = $real_title;
        }
    }

    // Sale mode del evento
    $sale_mode = $event_id ? SS_Event_Service::instance()->get_sale_mode( $event_id ) : 'seat';

    // Fallback zonas desde order-level meta
    if ( empty( $zones_list ) ) {
        $sd = $order->get_meta( 'ss_seat_data' );
        if ( is_array( $sd ) ) {
            foreach ( $sd as $entry ) {
                if ( ! empty( $entry['zone'] ) ) {
                    $zones_list[] = $entry['zone'];
                }
            }
        }
    }
    $zones_unique = array_values( array_unique( $zones_list ) );

    // Determinar ticket_type
    $ticket_type = ! empty( $seats_list ) ? 'seated' : 'general';

    ss_checkin_render_page( 'valid', '', array(
        'order_id'     => $order_id,
        'buyer'        => $buyer,
        'event'        => $event_name,
        'ticket_type'  => $ticket_type,
        'ticket_qty'   => $ticket_qty,
        'seats'        => $seats_list,
        'zones'        => $zones_unique,
        'tickets'      => $tickets_info,
        'sale_mode'    => $sale_mode,
        'checked_in'   => $checked_in,
        'checkin_time' => $checkin_time,
        'token'        => $token,
    ) );
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// TICKET PDF — endpoint /ss-ticket-pdf/{order_id}/{token}/
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'template_redirect', 'ss_ticket_pdf_handle' );

function ss_ticket_pdf_handle(): void {
    $order_id = (int) get_query_var( 'ss_ticket_pdf' );
    $token    = sanitize_text_field( get_query_var( 'ss_ticket_token' ) );
    if ( ! $order_id || ! $token ) { return; }

    // Validar token
    $saved = get_post_meta( $order_id, '_ss_checkin_token', true );
    if ( ! $saved || ! hash_equals( $saved, $token ) ) {
        wp_die( 'Token inválido.', 'Error', array( 'response' => 403 ) );
    }

    $data = ss_get_event_ticket_data( $order_id );
    if ( ! $data ) {
        wp_die( 'Este pedido no corresponde a un evento.', 'Error', array( 'response' => 404 ) );
    }

    // Renderizar tickets
    $tickets_html = '';
    if ( ! empty( $data['seat_tokens'] ) && count( $data['seat_tokens'] ) > 1 ) {
        foreach ( $data['seat_tokens'] as $seat_id => $stk ) {
            $payload = ss_checkin_seat_qr_payload( $stk );
            $qr_url  = ss_qr_generate_seat( $order_id, $seat_id, $payload );
            if ( $qr_url ) {
                $tickets_html .= ss_render_ticket_html( $data, $qr_url, $seat_id, false );
            }
        }
    } elseif ( ! empty( $data['ticket_tokens'] ) && count( $data['ticket_tokens'] ) > 1 ) {
        foreach ( $data['ticket_tokens'] as $ticket_id => $stk ) {
            $payload = ss_checkin_ticket_qr_payload( $stk );
            $qr_url  = ss_qr_generate_ticket( $order_id, $ticket_id, $payload );
            if ( $qr_url ) {
                $tickets_html .= ss_render_ticket_html( $data, $qr_url, $ticket_id, false );
            }
        }
    } else {
        $seat_label    = ! empty( $data['seats'] ) ? implode( ', ', $data['seats'] ) : '—';
        $tickets_html .= ss_render_ticket_html( $data, $data['qr_url'], $seat_label, false );
    }

    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Ticket — Pedido #<?php echo esc_html( $order_id ); ?></title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { background: #f5f5f5; font-family: Arial, sans-serif; }
            .ss-pdf-wrap { max-width: 500px; margin: 0 auto; padding: 20px; }
            .ss-pdf-actions {
                text-align: center; padding: 20px 0;
            }
            .ss-pdf-actions button {
                background: #111; color: #fff; border: none; padding: 12px 32px;
                border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer;
            }
            .ss-pdf-actions button:hover { background: #333; }
            @media print {
                body { background: #fff; }
                .ss-pdf-actions { display: none; }
                .ss-pdf-wrap { padding: 0; max-width: 100%; }
                div[style*="box-shadow"] { box-shadow: none !important; }
            }
        </style>
    </head>
    <body>
        <div class="ss-pdf-wrap">
            <div class="ss-pdf-actions">
                <button onclick="window.print()">Imprimir / Guardar como PDF</button>
            </div>
            <?php echo $tickets_html; ?>
        </div>
        <script>
            // Auto-trigger print on load for direct download experience
            window.addEventListener('load', function() {
                setTimeout(function() { window.print(); }, 600);
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// TICKET WALLET — endpoint /ss-ticket-wallet/{order_id}/{token}/
// Genera un archivo .pkpass (Apple Wallet) con los datos del evento.
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'template_redirect', 'ss_ticket_wallet_handle' );

function ss_ticket_wallet_handle(): void {
    $order_id = (int) get_query_var( 'ss_ticket_wallet' );
    $token    = sanitize_text_field( get_query_var( 'ss_ticket_token' ) );
    if ( ! $order_id || ! $token ) { return; }

    // Validar token
    $saved = get_post_meta( $order_id, '_ss_checkin_token', true );
    if ( ! $saved || ! hash_equals( $saved, $token ) ) {
        wp_die( 'Token inválido.', 'Error', array( 'response' => 403 ) );
    }

    $data = ss_get_event_ticket_data( $order_id );
    if ( ! $data ) {
        wp_die( 'Este pedido no corresponde a un evento.', 'Error', array( 'response' => 404 ) );
    }

    $seat_label = ! empty( $data['seats'] ) ? implode( ', ', $data['seats'] ) : 'General';

    // ── Generar .pkpass (ZIP con estructura Apple Wallet) ─────────────────
    // Nota: sin certificado Apple, el .pkpass no se abrirá en Apple Wallet.
    // Para Google Wallet, se puede usar la URL del QR directamente.

    // Alternativa universal: generar archivo .ics (calendario) como fallback
    // que funciona en iOS, Android, y escritorio.
    $event_date_raw = get_post_meta( $data['event_id'], 'event_start_datetime', true );
    if ( ! $event_date_raw ) {
        $event_date_raw = get_post_meta( $data['event_id'], 'event_start_date', true );
    }
    $start_time = get_post_meta( $data['event_id'], 'event_start_time', true );

    // Construir datetime para ICS
    $dt_start = '';
    $dt_end   = '';
    if ( $event_date_raw ) {
        $ts = strtotime( $event_date_raw );
        if ( $ts ) {
            // Si tenemos hora separada, combinar
            if ( $start_time ) {
                $ts_combined = strtotime( wp_date( 'Y-m-d', $ts ) . ' ' . $start_time );
                if ( $ts_combined ) { $ts = $ts_combined; }
            }
            $dt_start = gmdate( 'Ymd\THis\Z', $ts );
            $dt_end   = gmdate( 'Ymd\THis\Z', $ts + 10800 ); // +3 horas default
        }
    }
    if ( ! $dt_start ) {
        $dt_start = gmdate( 'Ymd\THis\Z' );
        $dt_end   = gmdate( 'Ymd\THis\Z', time() + 10800 );
    }

    $uid          = 'ss-ticket-' . $order_id . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
    $summary      = $data['event_name'];
    $description  = 'Zona: ' . $data['zone'] . '\\nAsiento: ' . $seat_label . '\\nPedido: #' . $order_id;
    $organizer_nm = $data['organizer'];
    $location     = $data['organizer']; // Usar organizador como ubicación por ahora

    $ics  = "BEGIN:VCALENDAR\r\n";
    $ics .= "VERSION:2.0\r\n";
    $ics .= "PRODID:-//SS Seating//Ticket//ES\r\n";
    $ics .= "BEGIN:VEVENT\r\n";
    $ics .= "UID:" . $uid . "\r\n";
    $ics .= "DTSTART:" . $dt_start . "\r\n";
    $ics .= "DTEND:" . $dt_end . "\r\n";
    $ics .= "SUMMARY:" . $summary . "\r\n";
    $ics .= "DESCRIPTION:" . $description . "\r\n";
    $ics .= "LOCATION:" . $location . "\r\n";
    $ics .= "ORGANIZER;CN=" . $organizer_nm . ":MAILTO:noreply@" . wp_parse_url( home_url(), PHP_URL_HOST ) . "\r\n";
    $ics .= "STATUS:CONFIRMED\r\n";
    $ics .= "END:VEVENT\r\n";
    $ics .= "END:VCALENDAR\r\n";

    $filename = sanitize_file_name( 'ticket-' . $order_id . '-' . $data['event_name'] . '.ics' );

    header( 'Content-Type: text/calendar; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Content-Length: ' . strlen( $ics ) );
    echo $ics;
    exit;
}

// ── Vista del endpoint de check-in ───────────────────────────────────────────

function ss_checkin_render_page( string $state, string $error_msg = '', array $data = [] ): void {
    // Cabecera HTML mínima, sin depender del tema
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Check-in · <?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                background: #0d0d0d;
                color: #f0f0f0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 24px;
            }
            .ss-card {
                background: #1a1a1a;
                border: 1px solid #333;
                border-radius: 16px;
                padding: 32px;
                max-width: 480px;
                width: 100%;
                text-align: center;
            }
            .ss-logo { font-size: 36px; margin-bottom: 12px; }
            .ss-title { font-size: 22px; font-weight: 700; margin-bottom: 24px; }
            .ss-badge {
                display: inline-block;
                padding: 8px 20px;
                border-radius: 999px;
                font-size: 15px;
                font-weight: 700;
                margin-bottom: 24px;
            }
            .ss-badge--ok   { background: #1b5e20; color: #a5d6a7; }
            .ss-badge--used { background: #e65100; color: #ffe0b2; }
            .ss-badge--err  { background: #b71c1c; color: #ffcdd2; }
            .ss-field { text-align: left; margin-bottom: 14px; }
            .ss-field label { font-size: 11px; text-transform: uppercase;
                              letter-spacing: .08em; color: #888; display: block; margin-bottom: 3px; }
            .ss-field span  { font-size: 16px; font-weight: 600; }
            .ss-seats { font-size: 14px; color: #ccc; margin-top: 4px; }
            .ss-btn {
                display: block; width: 100%; margin-top: 28px;
                padding: 14px; border: none; border-radius: 10px;
                font-size: 16px; font-weight: 700; cursor: pointer;
                background: #2e7d32; color: #fff; letter-spacing: .04em;
            }
            .ss-btn:hover { background: #388e3c; }
            .ss-time { font-size: 12px; color: #888; margin-top: 16px; }
            hr { border: none; border-top: 1px solid #333; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class="ss-card">
            <div class="ss-logo">🎭</div>
            <div class="ss-title"><?php echo esc_html( get_bloginfo( 'name' ) ); ?> · Check-in</div>
            <?php if ( $state === 'error' ) : ?>
                <span class="ss-badge ss-badge--err">⛔ Error</span>
                <p style="color:#ef9a9a;"><?php echo esc_html( $error_msg ); ?></p>
            <?php elseif ( $state === 'valid' ) :
                $checked_in = $data['checked_in'] === 'yes';
            ?>
                <span class="ss-badge <?php echo $checked_in ? 'ss-badge--used' : 'ss-badge--ok'; ?>">
                    <?php echo $checked_in ? '⚠️ Ya ingresó' : '✅ Válido'; ?>
                </span>
                <hr>
                <div class="ss-field">
                    <label>Comprador</label>
                    <span><?php echo esc_html( $data['buyer'] ?: '—' ); ?></span>
                </div>
                <div class="ss-field">
                    <label>Evento</label>
                    <span><?php echo esc_html( $data['event'] ?: '—' ); ?></span>
                </div>
                <div class="ss-field">
                    <label>Pedido</label>
                    <span>#<?php echo esc_html( $data['order_id'] ); ?></span>
                </div>
                <?php $tt = isset( $data['ticket_type'] ) ? $data['ticket_type'] : 'seated'; ?>
                <?php if ( $tt === 'seated' ) : ?>
                    <?php if ( ! empty( $data['zones'] ) ) : ?>
                    <div class="ss-field">
                        <label>Zona</label>
                        <span><?php echo esc_html( implode( ', ', (array) $data['zones'] ) ); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="ss-field">
                        <label>Sillas</label>
                        <div class="ss-seats"><?php echo esc_html( implode( ', ', (array) $data['seats'] ) ); ?></div>
                    </div>
                <?php else : ?>
                    <div class="ss-field">
                        <label>Tipo</label>
                        <span>Entrada General</span>
                    </div>
                    <?php if ( ! empty( $data['zones'] ) ) : ?>
                    <div class="ss-field">
                        <label>Zona</label>
                        <span><?php echo esc_html( implode( ', ', (array) $data['zones'] ) ); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="ss-field">
                        <label>Asiento</label>
                        <span>Sin asignar (orden de llegada)</span>
                    </div>
                <?php endif; ?>
                <?php if ( ! empty( $data['ticket_qty'] ) ) : ?>
                <div class="ss-field">
                    <label>Cantidad</label>
                    <span><?php echo (int) $data['ticket_qty']; ?></span>
                </div>
                <?php endif; ?>

                <?php if ( $checked_in ) : ?>
                    <p class="ss-time">
                        Ingreso registrado el <?php echo esc_html( $data['checkin_time'] ); ?>
                    </p>
                <?php else : ?>
                    <form method="post">
                        <?php wp_nonce_field( 'ss_checkin_' . $data['order_id'], 'ss_nonce' ); ?>
                        <input type="hidden" name="ss_action" value="mark_checkin">
                        <button type="submit" class="ss-btn">Marcar ingreso</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// CONTROL DE INGRESO — Página frontend pública (protegida por token de evento)
// URL: /control-ingreso/{event_id}/?token=XXXXX
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'template_redirect', 'ss_control_ingreso_handle' );

function ss_control_ingreso_handle(): void {
    $event_id = (int) get_query_var( 'ss_control_ingreso' );
    if ( ! $event_id ) {
        return; // No es nuestra ruta
    }

    // Verificar que el evento existe
    $event = get_post( $event_id );
    if ( ! $event || $event->post_type !== 'ss_event' ) {
        ss_control_ingreso_render( 'denied', $event_id );
        exit;
    }

    // Verificar token del query string
    $token       = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
    $saved_token = get_post_meta( $event_id, '_ss_event_checkin_token', true );

    // Auto-generar si no existe aún (evento creado antes de esta feature)
    if ( ! $saved_token ) {
        $saved_token = wp_generate_password( 32, false );
        update_post_meta( $event_id, '_ss_event_checkin_token', $saved_token );
    }

    if ( ! $token || ! hash_equals( $saved_token, $token ) ) {
        ss_control_ingreso_render( 'denied', $event_id );
        exit;
    }

    // Token válido → mostrar dashboard
    ss_control_ingreso_render( 'ok', $event_id, $token );
    exit;
}

/**
 * Renderiza la página de control de ingreso (HTML completo, sin tema WP).
 */
function ss_control_ingreso_render( string $state, int $event_id, string $token = '' ): void {
    $event_title = get_the_title( $event_id ) ?: 'Evento #' . $event_id;
    $nonce       = wp_create_nonce( 'ss_control_ingreso_' . $event_id );
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Control de Ingreso · <?php echo esc_html( $event_title ); ?></title>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, sans-serif;
                background: #1a1a2e;
                color: #eee;
                min-height: 100vh;
            }
            .ci-header {
                background: #16213e;
                padding: 16px 24px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                flex-wrap: wrap;
                border-bottom: 2px solid #0f3460;
            }
            .ci-header h1 { font-size: 18px; font-weight: 700; color: #e94560; }
            .ci-header .ci-event { font-size: 14px; opacity: .7; }
            .ci-back { font-size: 20px; color: #90caf9; line-height: 1; text-decoration: none; opacity: .7; transition: opacity .15s; }
            .ci-back:hover { opacity: 1; color: #fff; }
            .ci-layout {
                display: flex;
                gap: 24px;
                padding: 24px;
                max-width: 1100px;
                margin: 0 auto;
                flex-wrap: wrap;
            }
            .ci-camera-col { flex: 0 0 340px; max-width: 100%; }
            .ci-result-col { flex: 1; min-width: 300px; }
            .ci-camera-status {
                margin-top: 8px;
                font-size: 13px;
                color: #aaa;
                font-style: italic;
            }

            /* Feedback card */
            .ci-feedback {
                padding: 32px 24px;
                border-radius: 16px;
                min-height: 220px;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                text-align: center;
                transition: all .3s ease;
                background: #16213e;
                border: 2px solid #0f3460;
            }
            .ci-feedback.ci-valid   { background: #1b5e20; border-color: #4caf50; }
            .ci-feedback.ci-already { background: #e65100; border-color: #ff9800; }
            .ci-feedback.ci-invalid { background: #b71c1c; border-color: #f44336; }
            .ci-feedback.ci-loading { background: #0d47a1; border-color: #2196f3; }

            .ci-icon { font-size: 72px; line-height: 1; }
            .ci-msg  { font-size: 22px; font-weight: 700; margin-top: 12px; }
            .ci-details {
                display: none;
                margin-top: 16px;
                font-size: 14px;
                width: 100%;
            }
            .ci-details table { margin: 0 auto; text-align: left; line-height: 2; }
            .ci-details td:first-child { padding-right: 12px; font-weight: 600; opacity: .8; }

            /* History */
            .ci-history { margin-top: 24px; }
            .ci-history h3 { font-size: 15px; margin-bottom: 8px; opacity: .7; }
            .ci-history table { width: 100%; border-collapse: collapse; font-size: 13px; }
            .ci-history th { text-align: left; padding: 6px 8px; background: #16213e; color: #aaa; border-bottom: 1px solid #0f3460; }
            .ci-history td { padding: 6px 8px; border-bottom: 1px solid rgba(255,255,255,.06); }
            .ci-history tr:hover td { background: rgba(255,255,255,.03); }

            .ci-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-weight: 600; font-size: 12px; }
            .ci-badge-ok   { background: #4caf50; color: #fff; }
            .ci-badge-used { background: #ff9800; color: #fff; }
            .ci-badge-bad  { background: #f44336; color: #fff; }

            /* Denied page */
            .ci-denied {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                padding: 40px;
                text-align: center;
            }
            .ci-denied .ci-icon { font-size: 80px; }
            .ci-denied h1 { font-size: 24px; margin-top: 16px; color: #f44336; }
            .ci-denied p  { margin-top: 8px; opacity: .6; }

            /* Counter */
            .ci-counter {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                background: #0f3460;
                padding: 6px 14px;
                border-radius: 8px;
                font-size: 14px;
                font-weight: 600;
            }
            .ci-counter-num { color: #4caf50; font-size: 22px; }

            /* Stats panel */
            .ci-stats-panel {
                max-width: 1100px;
                margin: 0 auto;
                padding: 16px 24px 0;
            }
            .ci-stats-grid {
                display: flex;
                gap: 12px;
                flex-wrap: wrap;
            }
            .ci-zone-card {
                background: #16213e;
                border: 1px solid #0f3460;
                border-radius: 10px;
                padding: 14px 18px;
                min-width: 160px;
                flex: 1;
            }
            .ci-zone-name {
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: .5px;
                color: #aaa;
                margin-bottom: 6px;
            }
            .ci-zone-numbers {
                font-size: 28px;
                font-weight: 700;
                color: #4caf50;
            }
            .ci-zone-numbers .ci-zone-cap {
                font-size: 16px;
                color: #777;
                font-weight: 400;
            }
            .ci-zone-bar {
                margin-top: 8px;
                height: 6px;
                background: #0f3460;
                border-radius: 3px;
                overflow: hidden;
            }
            .ci-zone-bar-fill {
                height: 100%;
                background: #4caf50;
                border-radius: 3px;
                transition: width .5s ease;
            }
            .ci-stats-total {
                margin-top: 12px;
                font-size: 15px;
                font-weight: 700;
                color: #e94560;
            }

            @media (max-width: 720px) {
                .ci-layout { flex-direction: column; padding: 16px; }
                .ci-camera-col { flex: none; width: 100%; }
                .ci-stats-panel { padding: 12px 16px 0; }
                .ci-zone-card { min-width: 140px; }
            }
        </style>
    </head>
    <body>

    <?php if ( $state === 'denied' ) : ?>
        <div class="ci-denied">
            <span class="ci-icon">&#128683;</span>
            <h1>Acceso no autorizado</h1>
            <p>El enlace no es válido o no tiene permisos para acceder al control de ingreso.</p>
        </div>
    <?php else : ?>
        <header class="ci-header">
            <div style="display:flex;align-items:center;gap:12px;">
                <a href="<?php echo esc_url( home_url( '/box-office/' . $event_id . '/' ) ); ?>" class="ci-back" title="Volver al Box Office">&#8592;</a>
                <div>
                    <h1>Control de Ingreso</h1>
                    <span class="ci-event"><?php echo esc_html( $event_title ); ?></span>
                </div>
            </div>
            <div class="ci-counter">
                Ingresados: <span class="ci-counter-num" id="ci-count"><?php
                    $ci_stats = ss_get_checkin_counter( $event_id );
                    echo (int) $ci_stats['checked_in'];
                ?></span> / <span id="ci-total"><?php echo (int) $ci_stats['total']; ?></span>
            </div>
        </header>

        <div class="ci-stats-panel">
            <div class="ci-stats-grid" id="ci-stats-grid">
                <div class="ci-zone-card" style="opacity:.5;">
                    <div class="ci-zone-name">Cargando...</div>
                    <div class="ci-zone-numbers">—</div>
                </div>
            </div>
            <div class="ci-stats-total" id="ci-stats-total"></div>
        </div>

        <div class="ci-layout">
            <!-- Cámara -->
            <div class="ci-camera-col">
                <div id="ci-qr-reader" style="width:340px; max-width:100%; border-radius:12px; overflow:hidden;"></div>
                <p class="ci-camera-status" id="ci-camera-status">Iniciando cámara...</p>
            </div>

            <!-- Resultado -->
            <div class="ci-result-col">
                <div class="ci-feedback" id="ci-feedback">
                    <span class="ci-icon" id="ci-icon">&#128247;</span>
                    <p class="ci-msg" id="ci-msg">Esperando escaneo...</p>
                    <div class="ci-details" id="ci-details"></div>
                </div>

                <div class="ci-history">
                    <h3>Últimos escaneos</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Hora</th>
                                <th>Pedido</th>
                                <th>Comprador</th>
                                <th>Tipo</th>
                                <th>Detalle</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody id="ci-history-body"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
        <script>
            var ssCheckinFrontend = {
                ajaxUrl:  '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',
                nonce:    '<?php echo esc_js( $nonce ); ?>',
                eventId:  <?php echo (int) $event_id; ?>
            };
        </script>
        <script src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'assets/js/ss-checkin-frontend.js' ); ?>"></script>
    <?php endif; ?>

    </body>
    </html>
    <?php
}

// ── Flush rewrite rules al activar el plugin ─────────────────────────────────
// Solo se ejecuta una vez en la activación, no en cada carga de página.

register_activation_hook( __FILE__, 'ss_plugin_activate' );

function ss_plugin_activate(): void {
    ss_checkin_rewrite_rule();
    ss_boxoffice_rewrite_rule();
    flush_rewrite_rules();
    ss_leads_create_table();
    ss_boxoffice_create_log_table();
    ss_seat_ledger_create_table();

    // Programar cron de limpieza de reservas temporales
    if ( ! wp_next_scheduled( 'ss_temp_reserved_cleanup' ) ) {
        wp_schedule_event( time(), 'every_minute', 'ss_temp_reserved_cleanup' );
    }

}

/**
 * Asegurar schema del ledger al cargar el plugin (antes de cualquier hook).
 * Usa option para no repetir en cada request.
 */
add_action( 'plugins_loaded', 'ss_ensure_ledger_schema', 0 );
function ss_ensure_ledger_schema(): void {
    $target = '2';
    if ( get_option( 'ss_ledger_schema_version', '0' ) === $target ) { return; }

    global $wpdb;
    $table = $wpdb->prefix . 'ss_seat_ledger';

    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
        ss_seat_ledger_create_table();
    } else {
        $cols = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$table}" ), 'Field' );
        if ( ! in_array( 'session_id', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN session_id VARCHAR(100) NOT NULL DEFAULT '' AFTER status" );
        }
        if ( ! in_array( 'expires_at', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN expires_at DATETIME DEFAULT NULL AFTER created_at" );
        }
    }

    update_option( 'ss_ledger_schema_version', $target );
    if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] LEDGER SCHEMA OK v' . $target ); }
}

/**
 * Migración única: poblar el ledger con sold seats desde pedidos WC existentes.
 * Se ejecuta una sola vez en init, luego se desactiva.
 */
add_action( 'init', 'ss_ledger_migrate_existing_orders', 99 );
function ss_ledger_migrate_existing_orders(): void {
    $status = get_option( 'ss_ledger_migration_done' );
    if ( $status === '1' ) { return; }
    if ( ! function_exists( 'wc_get_orders' ) ) { return; }

    global $wpdb;
    $table = $wpdb->prefix . 'ss_seat_ledger';

    // Obtener todos los eventos que tienen pedidos con sillas
    $orders = wc_get_orders( array(
        'limit'  => -1,
        'status' => array( 'processing', 'completed' ),
    ) );

    foreach ( $orders as $order ) {
        $order_id = $order->get_id();
        $event_id = ss_seats_get_event_id( $order_id );
        if ( ! $event_id ) { continue; }

        foreach ( $order->get_items() as $item ) {
            $seats_raw = $item->get_meta( 'ss_seats' );
            if ( empty( $seats_raw ) ) { continue; }
            $seats = is_array( $seats_raw ) ? $seats_raw : array_map( 'trim', explode( ',', $seats_raw ) );
            foreach ( $seats as $seat ) {
                $seat = trim( $seat );
                if ( $seat === '' ) { continue; }
                // INSERT IGNORE: si ya existe en el ledger, no duplicar
                $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$table} (event_id, seat_id, order_id, status, session_id, created_at, expires_at)
                     VALUES (%d, %s, %d, 'sold', '', %s, NULL)",
                    $event_id, $seat, $order_id, $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : current_time( 'mysql' )
                ) );
            }
        }
    }

    // Marcar como completada
    update_option( 'ss_ledger_migration_done', '1' );
    if ( defined( 'SS_SEATING_DEBUG' ) && SS_SEATING_DEBUG ) {
        error_log( '[ss-seating] LEDGER MIGRATION completed — existing orders imported' );
    }
}

/**
 * Crea la tabla wp_ss_seat_ledger para inventario transaccional de asientos.
 * Índice único (event_id, seat_id) garantiza que un asiento no se venda dos veces.
 */
function ss_seat_ledger_create_table(): void {
    global $wpdb;
    $table   = $wpdb->prefix . 'ss_seat_ledger';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id   BIGINT UNSIGNED NOT NULL,
        seat_id    VARCHAR(20) NOT NULL,
        order_id   BIGINT UNSIGNED NOT NULL,
        status     VARCHAR(20) NOT NULL DEFAULT 'sold',
        session_id VARCHAR(100) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY unique_event_seat (event_id, seat_id)
    ) ENGINE=InnoDB {$charset}";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    // Asegurar que las columnas nuevas existan (upgrade de tablas existentes)
    $cols = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$table}" ), 'Field' );
    if ( ! in_array( 'session_id', $cols, true ) ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN session_id VARCHAR(100) NOT NULL DEFAULT '' AFTER status" );
    }
    if ( ! in_array( 'expires_at', $cols, true ) ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN expires_at DATETIME DEFAULT NULL AFTER created_at" );
    }

    if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] seat ledger table ready' ); }
}

/**
 * Crea la tabla wp_ss_event_leads si no existe.
 */
function ss_leads_create_table(): void {
    global $wpdb;
    $table   = $wpdb->prefix . 'ss_event_leads';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
        event_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
        event_name    VARCHAR(255)    NOT NULL DEFAULT '',
        buyer_name    VARCHAR(255)    NOT NULL DEFAULT '',
        buyer_email   VARCHAR(255)    NOT NULL DEFAULT '',
        buyer_phone   VARCHAR(50)     NOT NULL DEFAULT '',
        zone          VARCHAR(100)    NOT NULL DEFAULT '',
        seat          VARCHAR(50)     DEFAULT NULL,
        ticket_qty    INT UNSIGNED    NOT NULL DEFAULT 1,
        purchase_date DATETIME        NOT NULL DEFAULT '0000-00-00 00:00:00',
        PRIMARY KEY (id),
        KEY idx_event_id (event_id),
        KEY idx_order_id (order_id),
        KEY idx_buyer_email (buyer_email)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

// ═══════════════════════════════════════════════════════════════════════════════
// LIMPIEZA AUTOMÁTICA DE QR ANTIGUOS
// ═══════════════════════════════════════════════════════════════════════════════

// ── Registro y desregistro del cron ──────────────────────────────────────────

add_action( 'wp', 'ss_qr_cleanup_schedule' );

function ss_qr_cleanup_schedule(): void {
    if ( ! wp_next_scheduled( 'ss_qr_cleanup_daily' ) ) {
        wp_schedule_event( time(), 'daily', 'ss_qr_cleanup_daily' );
    }
}

register_deactivation_hook( __FILE__, 'ss_qr_cleanup_unschedule' );

function ss_qr_cleanup_unschedule(): void {
    $timestamp = wp_next_scheduled( 'ss_qr_cleanup_daily' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'ss_qr_cleanup_daily' );
    }
}

// ── Tarea de limpieza ─────────────────────────────────────────────────────────

add_action( 'ss_qr_cleanup_daily', 'ss_qr_cleanup_run' );

function ss_qr_cleanup_run(): void {
    // Fecha límite: hace 7 días a medianoche
    $cutoff = strtotime( '-7 days midnight' );

    // Obtener todos los pedidos que tengan un QR generado.
    // Usar WP_Query sobre shop_order para compatibilidad con HPOS desactivado
    // y con HPOS activado via wc_get_orders().
    $orders = ss_qr_cleanup_get_orders_with_qr();

    foreach ( $orders as $order_id ) {
        $qr_path = get_post_meta( $order_id, '_ss_qr_path', true );
        if ( ! $qr_path ) {
            continue;
        }

        // Obtener la fecha del evento asociado al pedido
        $event_date = ss_qr_cleanup_get_event_date( $order_id );

        if ( ! $event_date ) {
            // Sin fecha resoluble: no borrar — modo silencioso
            continue;
        }

        // Borrar solo si el evento pasó hace más de 7 días
        if ( $event_date >= $cutoff ) {
            continue; // Evento futuro o reciente: no tocar
        }

        // El evento ya pasó hace más de 7 días: eliminar el archivo
        if ( file_exists( $qr_path ) ) {
            if ( ! @unlink( $qr_path ) ) {
                if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] No se pudo eliminar QR: ' . $qr_path ); }
            }
        }
        // Eliminar la meta independientemente de si el archivo existía
        delete_post_meta( $order_id, '_ss_qr_path' );
    }
}

// ── Helpers internos ──────────────────────────────────────────────────────────

/**
 * Devuelve un array de order IDs que tienen la meta _ss_qr_path.
 * Compatible con HPOS activado y desactivado.
 */
function ss_qr_cleanup_get_orders_with_qr(): array {
    // HPOS (High-Performance Order Storage) — WooCommerce ≥ 7.1
    if ( function_exists( 'wc_get_orders' ) ) {
        $results = wc_get_orders( array(
            'limit'      => -1,
            'return'     => 'ids',
            'meta_key'   => '_ss_qr_path',
            'meta_compare' => 'EXISTS',
        ) );
        if ( ! empty( $results ) ) {
            return $results;
        }
    }

    // Fallback: WP_Query sobre el post_type shop_order
    $query = new WP_Query( array(
        'post_type'      => 'shop_order',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'     => '_ss_qr_path',
                'compare' => 'EXISTS',
            ),
        ),
    ) );
    return $query->posts ?: [];
}

/**
 * Obtiene la fecha del evento (timestamp Unix, medianoche) asociado a un pedido.
 *
 * Estrategia:
 *  1. Meta del post ss_event: '_ss_event_date' (formato Y-m-d)
 *
 * Retorna timestamp Unix o null si no se puede determinar.
 */
function ss_qr_cleanup_get_event_date( int $order_id ): ?int {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return null;
    }

    $event_post_id = ss_get_event_id_from_order( $order );
    if ( ! $event_post_id ) {
        return null;
    }

    $date_str = get_post_meta( $event_post_id, '_ss_event_date', true );
    if ( $date_str ) {
        $ts = strtotime( $date_str );
        if ( $ts ) {
            return (int) strtotime( 'midnight', $ts );
        }
    }

    return null;
}

// ═══════════════════════════════════════════════════════════════════════════════
// LIMPIEZA AUTOMÁTICA DE RESERVAS TEMPORALES (SEAT LEDGER)
// ═══════════════════════════════════════════════════════════════════════════════

// Registrar intervalo personalizado de 1 minuto
add_filter( 'cron_schedules', 'ss_cron_add_minute_interval' );

function ss_cron_add_minute_interval( array $schedules ): array {
    if ( ! isset( $schedules['every_minute'] ) ) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display'  => 'Cada minuto',
        );
    }
    return $schedules;
}

add_action( 'wp', 'ss_temp_reserved_cleanup_schedule' );

function ss_temp_reserved_cleanup_schedule(): void {
    if ( ! wp_next_scheduled( 'ss_temp_reserved_cleanup' ) ) {
        wp_schedule_event( time(), 'every_minute', 'ss_temp_reserved_cleanup' );
    }
}

register_deactivation_hook( __FILE__, 'ss_temp_reserved_cleanup_unschedule' );

function ss_temp_reserved_cleanup_unschedule(): void {
    $timestamp = wp_next_scheduled( 'ss_temp_reserved_cleanup' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'ss_temp_reserved_cleanup' );
    }
}

add_action( 'ss_temp_reserved_cleanup', 'ss_temp_reserved_cleanup_run' );

/**
 * Elimina del seat ledger las entradas temp_reserved con más de 10 minutos.
 * También limpia las reservas expiradas del post meta de todos los eventos con reservas activas.
 */
function ss_temp_reserved_cleanup_run(): void {
    global $wpdb;
    $table = $wpdb->prefix . 'ss_seat_ledger';

    // No ejecutar si la tabla no existe o le faltan columnas
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) { return; }
    $cols = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$table}" ), 'Field' );
    if ( ! in_array( 'expires_at', $cols, true ) ) { return; }

    $deleted = $wpdb->query(
        "DELETE FROM {$table} WHERE status = 'temp_reserved' AND expires_at IS NOT NULL AND expires_at < NOW()"
    );

    if ( $deleted > 0 && SS_SEATING_DEBUG ) {
        error_log( '[ss-seating] CRON: eliminadas ' . $deleted . ' reservas temp_reserved expiradas del ledger.' );
    }

    // También limpiar post meta de reservas expiradas
    $events_with_reservations = $wpdb->get_col(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ss_reserved_seats' AND meta_value != '' AND meta_value != 'a:0:{}'"
    );
    foreach ( $events_with_reservations as $event_id ) {
        ss_cleanup_expired_reservations( (int) $event_id );
    }
}

/**
 * Obtiene el ID del ss_event asociado a un pedido de WooCommerce.
 */
function ss_get_event_id_from_order( $order ): int {

    foreach ( $order->get_items() as $item ) {

        // Opción a: ss_event_id en el item meta
        $event_id = (int) $item->get_meta( 'ss_event_id' );
        if ( $event_id && get_post_type( $event_id ) === 'ss_event' ) {
            return $event_id;
        }

        // Opción b: _ss_event_id en el producto WC
        $product_id = $item->get_product_id();
        if ( $product_id ) {
            $event_id = (int) get_post_meta( $product_id, '_ss_event_id', true );
            if ( $event_id && get_post_type( $event_id ) === 'ss_event' ) {
                return $event_id;
            }
        }
    }
    return 0;
}

// ═══════════════════════════════════════════════════════════════════════════════
// PERSISTENCIA DE SILLAS VENDIDAS
// ═══════════════════════════════════════════════════════════════════════════════
//
// Fuente de verdad: tabla ss_seat_ledger (UNIQUE index event_id + seat_id).
// Las sillas se insertan en el ledger cuando el pedido llega a processing/completed.
// Se eliminan del ledger cuando el pedido pasa a cancelled/refunded/failed.
// Todos los hooks son idempotentes: operar dos veces produce el mismo resultado.

// ── Hooks de estado de pedido ─────────────────────────────────────────────────

// Guard: prioridad 1 — valida y escribe sold ANTES de que on_order_confirmed (prioridad 10) corra.
// Este hook se dispara SIEMPRE, independientemente de cómo se creó el pedido
// (classic checkout, block checkout, store API, webhook de gateway, REST API, etc.)
add_action( 'woocommerce_order_status_processing', 'ss_seats_guard_on_status_change', 1, 2 );
add_action( 'woocommerce_order_status_completed',  'ss_seats_guard_on_status_change', 1, 2 );

add_action( 'woocommerce_order_status_processing', 'ss_seats_on_order_confirmed', 10, 1 );
add_action( 'woocommerce_order_status_completed',  'ss_seats_on_order_confirmed', 10, 1 );

// Registrar redención de loyalty cuando el pago se confirma
add_action( 'woocommerce_order_status_processing', 'ss_loyalty_register_redemption', 15, 1 );
add_action( 'woocommerce_order_status_completed',  'ss_loyalty_register_redemption', 15, 1 );

function ss_loyalty_register_redemption( int $order_id ): void {
    if ( ! class_exists( 'SS_Loyalty' ) ) {
        return;
    }
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }
    // Solo actuar si el pedido tuvo un fee de descuento de fidelización
    $had_loyalty = false;
    foreach ( $order->get_fees() as $fee ) {
        if ( strpos( $fee->get_name(), 'fidelización' ) !== false ) {
            $had_loyalty = true;
            break;
        }
    }
    if ( ! $had_loyalty ) {
        return;
    }
    $email = strtolower( trim( $order->get_billing_email() ) );
    if ( empty( $email ) ) {
        return;
    }
    foreach ( $order->get_items() as $item ) {
        $event_id = (int) $item->get_meta( 'ss_event_id' );
        if ( $event_id ) {
            SS_Loyalty::mark_redeemed( $email, $event_id );
        }
    }
}

add_action( 'woocommerce_order_status_cancelled', 'ss_seats_on_order_released', 10, 1 );
add_action( 'woocommerce_order_status_refunded',  'ss_seats_on_order_released', 10, 1 );

// Auto-completar pedidos que contengan productos de eventos ss_event
add_action( 'woocommerce_payment_complete', 'ss_auto_complete_event_orders', 20, 1 );
add_action( 'woocommerce_order_status_failed',    'ss_seats_on_order_released', 10, 1 );

// Hooks de checkout (pueden no dispararse con algunos gateways/Block Checkout).
add_action( 'woocommerce_checkout_process', 'ss_seating_validate_seats_at_checkout' );
add_action( 'woocommerce_checkout_create_order', 'ss_seating_validate_on_order_create', 1, 2 );
add_action( 'woocommerce_store_api_checkout_process', 'ss_seating_validate_seats_at_checkout' );

// ── Guard universal: protección contra doble venta en CUALQUIER flujo ────────
//
// Se ejecuta con prioridad 1 en order_status_processing/completed.
// El pedido YA existe, así que leemos seats del order (no del cart).
// Adquiere GET_LOCK → valida contra sold (uncached) → si OK marca el order
// con _ss_guard_passed para que on_order_confirmed lo procese.
// Si hay conflicto → cancela el pedido inmediatamente.

function ss_seats_guard_on_status_change( int $order_id, $order = null ): void {
    // 1️⃣ inicio
    if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] SEAT GUARD START ' . microtime( true ) . ' order #' . $order_id ); }

    if ( ! $order ) {
        $order = wc_get_order( $order_id );
    }
    if ( ! $order ) {
        if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] SEAT GUARD ABORTED: no order' ); }
        return;
    }

    // Evitar re-ejecución si el guard ya pasó para este pedido
    if ( $order->get_meta( '_ss_guard_passed' ) === 'yes' ) {
        if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] SEAT GUARD SKIP: already passed for #' . $order_id ); }
        return;
    }

    $seats    = ss_seats_get_from_order( $order_id );
    $event_id = ss_seats_get_event_id( $order_id );

    if ( empty( $seats ) || ! $event_id ) {
        if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] SEAT GUARD SKIP: no seats or no event for #' . $order_id ); }
        return;
    }

    // 2️⃣ evento y asientos
    if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] EVENT ' . $event_id . ' SEATS ' . implode( ',', $seats ) ); }

    global $wpdb;
    $lock_name = 'ss_seat_checkout_' . $event_id;

    // 3️⃣ antes del lock
    if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] TRY LOCK event ' . $event_id ); }

    $lock_result = $wpdb->get_var(
        $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name )
    );

    // 4️⃣ resultado del lock
    if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] LOCK RESULT ' . $lock_result . ' event ' . $event_id ); }

    if ( (int) $lock_result !== 1 ) {
        if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] LOCK FAILED event ' . $event_id ); }
        $order->update_status( 'cancelled', 'Checkout simultáneo: no se pudo adquirir lock.' );
        return;
    }

    try {
        // Obtener session_id del comprador (guardado en checkout o desde WC session)
        $buyer_session = $order->get_meta( '_ss_checkout_session_id' );
        if ( ! $buyer_session && WC()->session ) {
            $buyer_session = (string) WC()->session->get_customer_id();
        }

        // Leer estado de los asientos del ledger
        $ledger_table = $wpdb->prefix . 'ss_seat_ledger';
        $seat_placeholders = implode( ',', array_fill( 0, count( $seats ), '%s' ) );
        $query_args = array_merge( array( $event_id ), array_map( 'strval', $seats ) );
        $ledger_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT seat_id, status, session_id FROM {$ledger_table} WHERE event_id = %d AND seat_id IN ({$seat_placeholders})",
            ...$query_args
        ) );

        $ledger_map = array();
        foreach ( $ledger_rows as $row ) {
            $ledger_map[ $row->seat_id ] = $row;
        }

        // 5️⃣ lectura de ledger
        if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] LEDGER GUARD ' . print_r( $ledger_map, true ) ); }

        $conflicts = array();
        foreach ( $seats as $seat ) {
            $seat = (string) $seat;
            if ( ! isset( $ledger_map[ $seat ] ) ) {
                continue; // No está en el ledger → disponible
            }
            $entry = $ledger_map[ $seat ];

            if ( $entry->status === 'sold' ) {
                $conflicts[] = $seat;
            } elseif ( $entry->status === 'manual_reserved' ) {
                $conflicts[] = $seat;
            } elseif ( $entry->status === 'temp_reserved' ) {
                // El lock (GET_LOCK) ya serializa los checkouts.
                // Si el asiento es temp_reserved, el PRIMER pedido que llega
                // con el lock lo toma. No validamos session porque puede
                // no coincidir (Store API, múltiples pestañas, etc.)
                continue;
            }
        }

        if ( ! empty( $conflicts ) ) {
            if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] CONFLICT SEATS ' . implode( ',', $conflicts ) . ' order #' . $order_id ); }

            $order->add_order_note(
                sprintf(
                    'Pedido cancelado automáticamente: las sillas %s ya fueron vendidas.',
                    implode( ', ', $conflicts )
                ),
                true
            );
            $order->update_status( 'cancelled', 'Doble venta detectada por seat guard.' );
            return;
        }

        // Guard validó OK — el ledger en ss_seats_on_order_confirmed() hace el INSERT/UPDATE real.
        if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] GUARD PASSED event ' . $event_id . ' order #' . $order_id ); }
        $order->update_meta_data( '_ss_guard_passed', 'yes' );
        $order->save();

    } finally {
        // 8️⃣ liberar lock
        if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] RELEASE LOCK ' . $event_id ); }
        $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
    }
}

// ── Confirmar: añadir sillas al evento ───────────────────────────────────────

function ss_seats_on_order_confirmed( int $order_id ): void {
    $seats    = ss_seats_get_from_order( $order_id );
    $event_id = ss_seats_get_event_id( $order_id );
    $order    = wc_get_order( $order_id );

    if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] ORDER CONFIRMED #' . $order_id
        . ' | ss_seats: ' . ( empty( $seats ) ? '(ninguna)' : implode( ', ', $seats ) )
        . ' | event_id: ' . ( $event_id ?: '(no detectado)' )
        . ' | payment_method: ' . ( $order ? $order->get_payment_method() : '?' )
        . ' | created_via: ' . ( $order ? $order->get_created_via() : '?' )
        . ' | status: ' . ( $order ? $order->get_status() : '?' ) ); }

    // No promover sillas para pedidos ya cancelados/fallidos (evita loop de hooks)
    if ( $order && in_array( $order->get_status(), array( 'cancelled', 'failed', 'refunded' ), true ) ) {
        if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] ORDER CONFIRMED SKIP: status=' . $order->get_status() . ' order #' . $order_id ); }
        return;
    }

    if ( empty( $seats ) || ! $event_id ) {
        // Igual debemos acumular tickets sin silla en hybrid.
        if ( $event_id && $order && $order->get_meta( '_ss_nonseat_accounted' ) !== 'yes' ) {
            $zone_nonseat = ss_extract_nonseat_zone_qtys_from_order( $order, $event_id );
            if ( ! empty( $zone_nonseat ) ) {
                ss_add_zone_nonseat_sold_meta( $event_id, $zone_nonseat );
                $order->update_meta_data( '_ss_nonseat_accounted', 'yes' );
                $order->save();
                ss_litespeed_purge_event( $event_id );
            }
        }
        return; // Pedido sin sillas o sin evento: nada más que hacer
    }

    // ── Seat Ledger: promover temp_reserved → sold ─────────────────────────
    // La validación de conflictos ya ocurrió ANTES del pago (guard_checkout / guard_on_status_change).
    // Aquí solo promovemos. Sin SELECTs, sin cancelaciones.
    global $wpdb;
    $ledger_table = $wpdb->prefix . 'ss_seat_ledger';

    foreach ( $seats as $seat ) {
        $seat = (string) $seat;

        // Promover temp_reserved → sold (sin filtrar por session — el guard ya validó)
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$ledger_table} SET status = 'sold', order_id = %d, session_id = '', expires_at = NULL, created_at = %s
             WHERE event_id = %d AND seat_id = %s AND status = 'temp_reserved'",
            $order_id, current_time( 'mysql' ), $event_id, $seat
        ) );

        if ( $updated > 0 ) {
            if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] LEDGER PROMOTE temp_reserved→sold ' . $seat . ' event ' . $event_id . ' order #' . $order_id ); }
        } else {
            // No temp_reserved row found — INSERT as sold, or promote manual_reserved if exists
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$ledger_table} (event_id, seat_id, order_id, status, session_id, created_at, expires_at)
                 VALUES (%d, %s, %d, 'sold', '', %s, NULL)
                 ON DUPLICATE KEY UPDATE
                     status     = IF(status = 'manual_reserved', 'sold', status),
                     order_id   = IF(status = 'manual_reserved', VALUES(order_id), order_id),
                     session_id = IF(status = 'manual_reserved', '', session_id),
                     expires_at = IF(status = 'manual_reserved', NULL, expires_at)",
                $event_id, $seat, $order_id, current_time( 'mysql' )
            ) );
            if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] LEDGER INSERT FALLBACK sold ' . $seat . ' event ' . $event_id . ' order #' . $order_id ); }
        }
    }
    // ─────────────────────────────────────────────────────────────────────────

    if ( $order && $order->get_meta( '_ss_nonseat_accounted' ) !== 'yes' ) {
        $zone_nonseat = ss_extract_nonseat_zone_qtys_from_order( $order, $event_id );
        if ( ! empty( $zone_nonseat ) ) {
            ss_add_zone_nonseat_sold_meta( $event_id, $zone_nonseat );
        }
        $order->update_meta_data( '_ss_nonseat_accounted', 'yes' );
        $order->save();
    }

    // Limpiar reservas que cubrieran estas sillas y purgar caché
    $reserved = get_post_meta( $event_id, '_ss_reserved_seats', true );
    if ( is_array( $reserved ) ) {
        foreach ( $reserved as $seat_code => $data ) {
            if ( in_array( $seat_code, $seats, true ) ) {
                unset( $reserved[ $seat_code ] );
            }
        }
        update_post_meta( $event_id, '_ss_reserved_seats', $reserved );
    }
    ss_litespeed_purge_event( $event_id );
}

// ── Liberar: quitar sillas del evento ────────────────────────────────────────

function ss_seats_on_order_released( int $order_id ): void {
    $seats    = ss_seats_get_from_order( $order_id );
    $event_id = ss_seats_get_event_id( $order_id );
    $order    = wc_get_order( $order_id );

    if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] ORDER RELEASED #' . $order_id
        . ' | ss_seats: ' . ( empty( $seats ) ? '(ninguna)' : implode( ', ', $seats ) )
        . ' | event_id: ' . ( $event_id ?: '(no detectado)' ) ); }

    if ( empty( $seats ) || ! $event_id ) {
        if ( $event_id && $order && $order->get_meta( '_ss_nonseat_accounted' ) === 'yes' ) {
            $zone_nonseat = ss_extract_nonseat_zone_qtys_from_order( $order, $event_id );
            if ( ! empty( $zone_nonseat ) ) {
                ss_sub_zone_nonseat_sold_meta( $event_id, $zone_nonseat );
            }
            $order->update_meta_data( '_ss_nonseat_accounted', 'no' );
            $order->save();
            ss_litespeed_purge_event( $event_id );
        }
        return;
    }

    // Eliminar asientos de este pedido del ledger (sold con order_id)
    global $wpdb;
    $ledger_table = $wpdb->prefix . 'ss_seat_ledger';
    $wpdb->delete( $ledger_table, array( 'order_id' => $order_id ), array( '%d' ) );

    // También limpiar temp_reserved de estos asientos para este evento (order_id=0)
    $seat_placeholders = implode( ',', array_fill( 0, count( $seats ), '%s' ) );
    $del_args = array_merge( array( $event_id ), array_map( 'strval', $seats ) );
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$ledger_table} WHERE event_id = %d AND seat_id IN ({$seat_placeholders}) AND status = 'temp_reserved'",
        ...$del_args
    ) );

    if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] LEDGER CLEANUP order #' . $order_id . ' event ' . $event_id . ' seats ' . implode( ',', $seats ) ); }

    if ( $order && $order->get_meta( '_ss_nonseat_accounted' ) === 'yes' ) {
        $zone_nonseat = ss_extract_nonseat_zone_qtys_from_order( $order, $event_id );
        if ( ! empty( $zone_nonseat ) ) {
            ss_sub_zone_nonseat_sold_meta( $event_id, $zone_nonseat );
        }
        $order->update_meta_data( '_ss_nonseat_accounted', 'no' );
        $order->save();
    }
    ss_litespeed_purge_event( $event_id );
}

// ── Auto-completar pedidos de eventos ─────────────────────────────────────────

/**
 * Si el pedido contiene al menos un producto vinculado a ss_event,
 * lo pasa a 'completed' automáticamente al recibir pago.
 */
function ss_auto_complete_event_orders( int $order_id ): void {
    $order = wc_get_order( $order_id );
    if ( ! $order || $order->get_status() === 'completed' ) {
        return;
    }

    $has_event = false;
    foreach ( $order->get_items() as $item ) {
        $product_id = $item->get_product_id();
        if ( $product_id && (int) get_post_meta( $product_id, '_ss_event_id', true ) > 0 ) {
            $has_event = true;
            break;
        }
    }

    if ( $has_event ) {
        $order->set_status( 'completed', 'Auto-completado: pedido de evento SS.' );
        $order->save();
        if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] AUTO-COMPLETE order #' . $order_id ); }
    }
}

// ── Validación transaccional en checkout ──────────────────────────────────────

/**
 * Se ejecuta en woocommerce_checkout_create_order prioridad 1 (antes de guardar el pedido).
 * Lanza Exception si hay conflicto → WC aborta la creación completamente.
 * Es el hook que SÍ dispara con MPWEM (confirmado en logs).
 */
/**
 * Lee sillas vendidas directamente del ledger (sin caché).
 * Garantiza ver el estado real en contextos de concurrencia (bajo GET_LOCK).
 */
function ss_seats_read_uncached( int $event_id ): array {
    global $wpdb;
    $table = $wpdb->prefix . 'ss_seat_ledger';

    $sold_seats = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT seat_id FROM {$table} WHERE event_id = %d AND status = 'sold'",
            $event_id
        )
    );

    if ( ! is_array( $sold_seats ) ) {
        return array();
    }

    return array_values( array_filter( $sold_seats ) );
}

/**
 * Lógica centralizada de protección de checkout para asientos.
 * Usada por Classic Checkout y Block Checkout (Store API).
 *
 * - GET_LOCK por event_id (serializa checkouts concurrentes)
 * - Lecturas directas de DB (sin caché WP)
 * - Doble verificación antes de escribir
 * - Validación atómica bajo lock (escritura via ledger en on_order_confirmed)
 *
 * @throws Exception Si hay conflicto de asientos o datos faltantes.
 */
function ss_seating_guard_checkout(): void {
    // 1️⃣ inicio del guard
    if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] SEAT GUARD START ' . microtime( true ) ); }

    if ( ! WC()->cart ) {
        if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] SEAT GUARD ABORTED: no cart' ); }
        return;
    }

    global $wpdb;
    $current_session = WC()->session ? (string) WC()->session->get_customer_id() : '';
    $locked_events   = array();

    try {
        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {

            if ( empty( $cart_item['ss_event_id'] ) ) {
                continue;
            }
            $event_id = (int) $cart_item['ss_event_id'];

            // Si el evento tiene layout activo en modo seat, ss_seats es obligatorio
            if ( empty( $cart_item['ss_seats'] ) ) {
                $layout    = SS_Event_Service::instance()->get_layout_raw( $event_id );
                $sale_mode = SS_Event_Service::instance()->get_sale_mode( $event_id );
                if ( ! empty( $layout ) && $sale_mode === 'seat' ) {
                    if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] CHECKOUT BLOQUEADO: ss_seats vacío para evento ' . $event_id . ' con layout activo (modo seat)' ); }
                    throw new Exception(
                        __( 'Debes seleccionar un asiento antes de completar la compra. Por favor vuelve al evento y elige una silla.', 'ss-seating' )
                    );
                }
                continue;
            }
            $seats = (array) $cart_item['ss_seats'];

            // 2️⃣ evento y asientos
            if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] EVENT ' . $event_id . ' SEATS ' . implode( ',', $seats ) ); }

            // ── Adquirir lock MySQL por evento ──
            if ( ! in_array( $event_id, $locked_events, true ) ) {
                $lock_name = 'ss_seat_checkout_' . $event_id;

                // 3️⃣ antes del lock
                if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] TRY LOCK event ' . $event_id ); }

                $lock_result = $wpdb->get_var(
                    $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name )
                );

                // 4️⃣ resultado del lock
                if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] LOCK RESULT ' . $lock_result . ' event ' . $event_id ); }

                if ( (int) $lock_result !== 1 ) {
                    if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] LOCK FAILED event ' . $event_id ); }
                    throw new Exception(
                        __( 'Hay demasiados checkouts simultáneos para este evento. Por favor intenta nuevamente.', 'ss-seating' )
                    );
                }
                $locked_events[] = $event_id;
            }

            // ── Validación protegida por lock — lectura directa de DB ──
            ss_cleanup_expired_reservations( $event_id );

            // Leer TODOS los registros del ledger para estos asientos (sold, temp_reserved, manual_reserved)
            $ledger_table = $wpdb->prefix . 'ss_seat_ledger';
            $seat_placeholders = implode( ',', array_fill( 0, count( $seats ), '%s' ) );
            $query_args = array_merge( array( $event_id ), array_map( 'strval', $seats ) );
            $ledger_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT seat_id, status, session_id FROM {$ledger_table} WHERE event_id = %d AND seat_id IN ({$seat_placeholders})",
                ...$query_args
            ) );

            $ledger_map = array();
            foreach ( $ledger_rows as $row ) {
                $ledger_map[ $row->seat_id ] = $row;
            }

            // 5️⃣ lectura de ledger
            if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] LEDGER CHECK ' . print_r( $ledger_map, true ) ); }

            foreach ( $seats as $seat ) {
                $seat = (string) $seat;

                if ( ! isset( $ledger_map[ $seat ] ) ) {
                    continue; // No está en el ledger → disponible
                }

                $entry = $ledger_map[ $seat ];

                if ( $entry->status === 'sold' ) {
                    if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] CONFLICT SEAT ' . $seat . ' (vendida)' ); }
                    throw new Exception(
                        sprintf(
                            __( 'La silla %s ya no está disponible. Por favor selecciona otra.', 'ss-seating' ),
                            esc_html( $seat )
                        )
                    );
                }

                if ( $entry->status === 'manual_reserved' ) {
                    if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] CONFLICT SEAT ' . $seat . ' (reserva manual)' ); }
                    throw new Exception(
                        sprintf(
                            __( 'La silla %s ya no está disponible. Por favor selecciona otra.', 'ss-seating' ),
                            esc_html( $seat )
                        )
                    );
                }

                if ( $entry->status === 'temp_reserved' ) {
                    // Si es la misma sesión → permitir (es SU reserva)
                    if ( $entry->session_id === $current_session ) {
                        if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] SEAT ' . $seat . ' temp_reserved by current session — OK' ); }
                        continue;
                    }
                    // Otra sesión → bloquear
                    if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] CONFLICT SEAT ' . $seat . ' (temp_reserved por ' . $entry->session_id . ')' ); }
                    throw new Exception(
                        sprintf(
                            __( 'La silla %s ya no está disponible. Por favor selecciona otra.', 'ss-seating' ),
                            esc_html( $seat )
                        )
                    );
                }
            }

            // Validación OK — el ledger UPDATE/INSERT ocurre en ss_seats_on_order_confirmed()
            if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] GUARD CHECKOUT VALIDATED event ' . $event_id . ' seats ' . implode( ',', $seats ) ); }
        }
    } finally {
        // 8️⃣ liberar locks
        foreach ( $locked_events as $eid ) {
            if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] RELEASE LOCK ' . $eid ); }
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'ss_seat_checkout_' . $eid ) );
        }
    }
}

// ── Classic Checkout ─────────────────────────────────────────────────────────
function ss_seating_validate_on_order_create( $order, $data ): void {
    if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] validate_on_order_create START (classic checkout)' ); }
    ss_seating_guard_checkout();
}

// ── Block Checkout (Store API) ───────────────────────────────────────────────
function ss_seating_validate_seats_at_checkout(): void {
    if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] validate_seats_at_checkout START (block checkout / store API)' ); }
    ss_seating_guard_checkout();
}

// ── Helpers de persistencia ───────────────────────────────────────────────────

/**
 * Lee el array de sillas vendidas de un evento.
 * Siempre devuelve array limpio de strings, nunca null ni false.
 */
function ss_seats_read( int $event_id ): array {
    global $wpdb;
    $table = $wpdb->prefix . 'ss_seat_ledger';

    // Verificar que la tabla y columnas existen antes de consultar
    $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
    if ( ! $table_exists ) {
        if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] ss_seats_read: tabla no existe, usando fallback WC orders' ); }
        return ss_seats_read_from_orders( $event_id );
    }

    $sold_seats = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT seat_id FROM {$table} WHERE event_id = %d AND status = 'sold'",
            $event_id
        )
    );

    if ( ! is_array( $sold_seats ) || empty( $sold_seats ) ) {
        // Fallback a WC orders si el ledger está vacío
        $from_orders = ss_seats_read_from_orders( $event_id );
        if ( ! empty( $from_orders ) ) {
            if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] ss_seats_read: ledger vacío, fallback WC orders devolvió ' . count( $from_orders ) . ' sillas' ); }
            return $from_orders;
        }
        return array();
    }

    return array_values( array_filter( $sold_seats ) );
}

/**
 * Fallback: leer sillas vendidas directamente de los pedidos WC.
 * No depende del ledger. Busca en order item meta 'ss_seats'.
 */
function ss_seats_read_from_orders( int $event_id ): array {
    if ( ! function_exists( 'wc_get_orders' ) ) { return array(); }

    $sold = array();
    $orders = wc_get_orders( array(
        'limit'  => -1,
        'status' => array( 'processing', 'completed' ),
    ) );

    foreach ( $orders as $order ) {
        $order_event = ss_seats_get_event_id( $order->get_id() );
        if ( (int) $order_event !== (int) $event_id ) { continue; }

        foreach ( $order->get_items() as $item ) {
            $seats_raw = $item->get_meta( 'ss_seats' );
            if ( empty( $seats_raw ) ) { continue; }
            $seats = is_array( $seats_raw ) ? $seats_raw : array_map( 'trim', explode( ',', $seats_raw ) );
            foreach ( $seats as $s ) {
                $s = trim( $s );
                if ( $s !== '' ) { $sold[] = $s; }
            }
        }
    }

    return array_values( array_unique( $sold ) );
}

/**
 * Construye un mapa seat_code → zone_name a partir del layout JSON de un evento.
 * Ejemplo: [ 'A1' => 'VIP', 'A2' => 'VIP', 'B1' => 'GENERAL', ... ]
 */
function ss_seats_zone_map( int $event_id ): array {
    $layout_raw = SS_Event_Service::instance()->get_layout_raw( $event_id );
    if ( empty( $layout_raw ) ) {
        return array();
    }
    $layout = json_decode( $layout_raw, true );
    $rows = ss_layout_get_rows( $layout ?: array() );
    if ( ! is_array( $layout ) || empty( $rows ) ) {
        return array();
    }
    $map = array();
    foreach ( $rows as $row ) {
        if ( isset( $row['type'] ) && $row['type'] === 'empty' ) {
            continue;
        }
        $zone  = isset( $row['zone'] )  ? trim( $row['zone'] )  : 'GENERAL';
        $label = isset( $row['label'] ) ? $row['label'] : '';
        $count = isset( $row['count'] ) ? (int) $row['count'] : 0;
        if ( $zone === '' ) {
            $zone = 'GENERAL';
        }
        $removed = array();
        if ( ! empty( $row['removedSeats'] ) && is_array( $row['removedSeats'] ) ) {
            foreach ( $row['removedSeats'] as $rs ) {
                $removed[ (int) $rs ] = true;
            }
        }
        for ( $s = 1; $s <= $count; $s++ ) {
            if ( isset( $removed[ $s ] ) ) {
                continue;
            }
            $seat_id = $label . $s;
            $map[ $seat_id ] = $zone;
        }
    }
    return $map;
}

/**
 * Dado un array de seat codes y un event_id, devuelve ss_seat_data:
 * [ ['seat' => 'C3', 'zone' => 'VIP'], ['seat' => 'C4', 'zone' => 'VIP'] ]
 */
function ss_build_seat_data( array $seats, int $event_id ): array {
    $zone_map  = ss_seats_zone_map( $event_id );
    $seat_data = array();
    foreach ( $seats as $seat ) {
        $seat_data[] = array(
            'seat' => $seat,
            'zone' => isset( $zone_map[ $seat ] ) ? $zone_map[ $seat ] : '',
        );
    }
    return $seat_data;
}

// ── Reservas temporales (sillas en carrito, aún no confirmadas) ───────────────
// Meta: _ss_reserved_seats en el post ss_event
// Estructura: [ seat_code => [ 'session_id' => '...', 'expires' => timestamp ], ... ]
// TTL configurable desde Configuración → SS Seating (SS_Settings: reservation_ttl, en minutos).

/**
 * Elimina todas las entradas expiradas de _ss_reserved_seats.
 * Guarda array vacío (nunca null) si no quedan reservas activas.
 * Si limpió algo, purga la caché de LiteSpeed para que el grid refleje
 * las sillas liberadas.
 */
function ss_cleanup_expired_reservations( int $event_id ): void {
    if ( ! $event_id ) {
        return;
    }
    $reserved = get_post_meta( $event_id, '_ss_reserved_seats', true );
    if ( ! is_array( $reserved ) ) {
        update_post_meta( $event_id, '_ss_reserved_seats', array() );
        return;
    }
    $now     = time();
    $cleaned = array();
    foreach ( $reserved as $seat => $data ) {
        if ( isset( $data['expires'] ) && $data['expires'] >= $now ) {
            $cleaned[ $seat ] = $data;
        }
    }
    if ( count( $cleaned ) !== count( $reserved ) ) {
        update_post_meta( $event_id, '_ss_reserved_seats', $cleaned );
        ss_litespeed_purge_event( $event_id );
    }
}

function ss_seats_reserve( int $event_id, array $seats, string $session_id, int $ttl = 0 ): void {
    if ( $ttl <= 0 ) {
        $ttl = max( 1, (int) SS_Settings::get( 'reservation_ttl', 15 ) ) * 60;
    }
    if ( ! $event_id || empty( $seats ) || ! $session_id ) {
        return;
    }
    // Limpiar expiradas antes de escribir
    ss_cleanup_expired_reservations( $event_id );

    $reserved = get_post_meta( $event_id, '_ss_reserved_seats', true );
    if ( ! is_array( $reserved ) ) {
        $reserved = array();
    }
    $expires = time() + $ttl;
    foreach ( $seats as $seat ) {
        $reserved[ (string) $seat ] = array(
            'session_id' => $session_id,
            'expires'    => $expires,
        );
    }
    update_post_meta( $event_id, '_ss_reserved_seats', $reserved );
}

function ss_seats_release( int $event_id, string $session_id ): void {
    if ( ! $event_id || ! $session_id ) {
        return;
    }

    // 1) Limpiar post meta legacy (si existe)
    $reserved = get_post_meta( $event_id, '_ss_reserved_seats', true );
    if ( is_array( $reserved ) ) {
        $changed = false;
        foreach ( $reserved as $seat => $data ) {
            if ( isset( $data['session_id'] ) && $data['session_id'] === $session_id ) {
                unset( $reserved[ $seat ] );
                $changed = true;
            }
        }
        if ( $changed ) {
            update_post_meta( $event_id, '_ss_reserved_seats', $reserved ?: array() );
        }
    }

    // 2) Limpiar directamente del ledger — fuente de verdad
    global $wpdb;
    $table = $wpdb->prefix . 'ss_seat_ledger';
    $deleted = $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$table} WHERE event_id = %d AND session_id = %s AND status = 'temp_reserved'",
        $event_id, $session_id
    ) );

    if ( SS_SEATING_DEBUG && $deleted > 0 ) {
        error_log( '[ss-seating] SEATS_RELEASE event ' . $event_id . ' session ' . $session_id . ' — ' . $deleted . ' ledger rows deleted' );
    }
}

/**
 * Escribe reservas temporales en el seat ledger.
 * Retorna array con 'reserved' (asientos bloqueados OK) y 'rejected' (ya tomados por otro).
 */
function ss_ledger_temp_reserve( int $event_id, array $seats, string $session_id ): array {
    global $wpdb;
    $table = $wpdb->prefix . 'ss_seat_ledger';

    $ttl_minutes = max( 1, (int) SS_Settings::get( 'reservation_ttl', 15 ) );
    $reserved = array();
    $rejected = array();

    // Limpiar reservas expiradas de estos asientos antes de intentar reservar
    if ( ! empty( $seats ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $seats ), '%s' ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE event_id = %d AND seat_id IN ({$placeholders}) AND status = 'temp_reserved' AND expires_at < NOW()",
            array_merge( array( $event_id ), $seats )
        ) );
    }

    foreach ( $seats as $seat ) {
        $seat = (string) $seat;

        // Intentar INSERT
        $inserted = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$table} (event_id, seat_id, order_id, status, session_id, created_at, expires_at)
             VALUES (%d, %s, 0, 'temp_reserved', %s, NOW(), DATE_ADD(NOW(), INTERVAL %d MINUTE))",
            $event_id,
            $seat,
            $session_id,
            $ttl_minutes
        ) );

        if ( $inserted > 0 ) {
            // INSERT exitoso — asiento reservado
            $reserved[] = $seat;
            continue;
        }

        // INSERT falló (UNIQUE duplicate) — verificar quién lo tiene
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT status, session_id FROM {$table} WHERE event_id = %d AND seat_id = %s",
            $event_id,
            $seat
        ) );

        if ( ! $existing ) {
            // Raro: no existe pero INSERT falló — reintentar
            $reserved[] = $seat;
            continue;
        }

        if ( $existing->status === 'sold' || $existing->status === 'manual_reserved' ) {
            // Vendida o reservada manualmente — bloqueo permanente
            $rejected[] = $seat;
            if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] LEDGER REJECT seat ' . $seat . ' status=' . $existing->status ); }
        } else {
            // temp_reserved (misma sesión u otra) — tomar posesión y renovar TTL.
            // Esto cubre el caso de móvil donde la cookie de sesión WC se pierde
            // entre cargas de página y el usuario queda con un session_id diferente.
            $wpdb->update(
                $table,
                array(
                    'session_id' => $session_id,
                    'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl_minutes * 60 ),
                ),
                array( 'event_id' => $event_id, 'seat_id' => $seat ),
                array( '%s', '%s' ),
                array( '%d', '%s' )
            );
            $reserved[] = $seat;
            if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] LEDGER TAKEOVER seat ' . $seat . ' old_session=' . $existing->session_id . ' new_session=' . $session_id ); }
        }
    }

    if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] LEDGER TEMP_RESERVE event ' . $event_id . ' reserved: ' . implode( ',', $reserved ) . ' rejected: ' . implode( ',', $rejected ) . ' session: ' . $session_id ); }

    return array( 'reserved' => $reserved, 'rejected' => $rejected );
}

/**
 * Elimina reservas temporales del ledger para asientos específicos.
 */
function ss_ledger_release_temp( int $event_id, array $seats ): void {
    if ( empty( $seats ) ) {
        return;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'ss_seat_ledger';

    $placeholders = implode( ',', array_fill( 0, count( $seats ), '%s' ) );
    $args = array_merge( array( $event_id ), $seats );

    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$table} WHERE event_id = %d AND seat_id IN ({$placeholders}) AND status = 'temp_reserved'",
        ...$args
    ) );

    if ( SS_SEATING_DEBUG ) { error_log( '[ss-seating] LEDGER RELEASE_TEMP event ' . $event_id . ' seats: ' . implode( ',', $seats ) ); }
}

/**
 * Devuelve los códigos de sillas con reserva activa para un evento,
 * excluyendo las de la sesión actual (para no bloquearle sus propias sillas).
 */
function ss_seats_get_reserved( int $event_id, string $exclude_session = '' ): array {
    $reserved = get_post_meta( $event_id, '_ss_reserved_seats', true );
    if ( ! is_array( $reserved ) ) {
        return array();
    }
    $now   = time();
    $seats = array();
    foreach ( $reserved as $seat => $data ) {
        if ( ! isset( $data['expires'] ) || $data['expires'] < $now ) {
            continue;
        }
        if ( $exclude_session && isset( $data['session_id'] ) && $data['session_id'] === $exclude_session ) {
            continue;
        }
        $seats[] = (string) $seat;
    }
    return array_values( array_unique( $seats ) );
}

/**
 * Lee asientos bloqueados del seat ledger (temp_reserved + manual_reserved).
 * Excluye los temp_reserved de la sesión actual para que el usuario pueda ver sus propias sillas.
 * Excluye temp_reserved expirados.
 */
function ss_ledger_get_blocked_seats( int $event_id, string $exclude_session = '' ): array {
    global $wpdb;
    $table = $wpdb->prefix . 'ss_seat_ledger';

    // Verificar que la tabla existe
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
        return array();
    }

    // Verificar qué columnas tiene la tabla
    $cols = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$table}" ), 'Field' );
    $has_session  = in_array( 'session_id', $cols, true );
    $has_expires  = in_array( 'expires_at', $cols, true );

    // Construir query según columnas disponibles
    $where = "event_id = %d AND status IN ('temp_reserved', 'manual_reserved')";
    $args  = array( $event_id );

    if ( $has_session && $exclude_session ) {
        $where .= " AND NOT (status = 'temp_reserved' AND session_id = %s)";
        $args[] = $exclude_session;
    }

    if ( $has_expires ) {
        $where .= " AND (expires_at IS NULL OR expires_at >= NOW())";
    }

    $results = $wpdb->get_col( $wpdb->prepare(
        "SELECT seat_id FROM {$table} WHERE {$where}",
        ...$args
    ) );

    return is_array( $results ) ? array_values( array_filter( $results ) ) : array();
}

// ── Purge de LiteSpeed Cache ──────────────────────────────────────────────────

function ss_litespeed_purge_event( int $event_id ): void {
    if ( ! $event_id ) {
        return;
    }
    // LiteSpeed Cache 3.x+ (do_action API)
    do_action( 'litespeed_purge_post', $event_id );
    // LiteSpeed Cache legacy class API
    if ( class_exists( 'LiteSpeed_Cache_API' ) ) {
        LiteSpeed_Cache_API::purge_post( $event_id );
    }
}

/**
 * Extrae las sillas reservadas de todos los items de un pedido.
 * ss_seats se guarda como array en el item via woocommerce_add_cart_item_data.
 */
function ss_seats_get_from_order( int $order_id ): array {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return array();
    }

    $seats = array();

    // Fuente 1: meta directa en el pedido (guardada por ss_seating_save_seats_to_order)
    $order_seats = $order->get_meta( 'ss_seats' );
    if ( is_array( $order_seats ) && ! empty( $order_seats ) ) {
        foreach ( $order_seats as $s ) {
            $s = trim( (string) $s );
            if ( $s !== '' ) { $seats[] = $s; }
        }
    }

    // Fuente 2: meta en cada item de línea (flujo clásico via cart_item_data)
    foreach ( $order->get_items() as $item ) {
        $item_seats = $item->get_meta( 'ss_seats' );

        if ( is_array( $item_seats ) ) {
            // Guardado como array (flujo normal desde add_cart_item_data)
            foreach ( $item_seats as $s ) {
                $s = trim( (string) $s );
                if ( $s !== '' ) {
                    $seats[] = $s;
                }
            }
        } elseif ( is_string( $item_seats ) && $item_seats !== '' ) {
            // Guardado como string CSV (compatibilidad con pedidos antiguos)
            foreach ( array_map( 'trim', explode( ',', $item_seats ) ) as $s ) {
                if ( $s !== '' ) {
                    $seats[] = $s;
                }
            }
        }
    }

    return array_values( array_unique( $seats ) );
}

/**
 * Obtiene el event_id desde un order_id.
 * Reutiliza la función compartida de lookup.
 */
function ss_seats_get_event_id( int $order_id ): int {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return 0;
    }
    return ss_get_event_id_from_order( $order );
}

// ── Comprobación en frontend: marca sillas ya vendidas como no disponibles ────
// Se aplica al shortcode para que las sillas vendidas aparezcan bloqueadas.

add_filter( 'ss_seating_seat_classes', 'ss_seating_mark_sold_seats', 10, 3 );

/**
 * Añade la clase 'ss-sold' a los botones de sillas ya vendidas.
 * El shortcode llama apply_filters('ss_seating_seat_classes', $classes, $seat_code, $event_id).
 */
function ss_seating_mark_sold_seats( array $classes, string $seat_code, int $event_id ): array {
    static $sold_cache = array();
    if ( ! isset( $sold_cache[ $event_id ] ) ) {
        $sold_cache[ $event_id ] = ss_seats_read( $event_id );
    }
    if ( in_array( $seat_code, $sold_cache[ $event_id ], true ) ) {
        $classes[] = 'ss-sold';
    }
    return $classes;
}

// ═══════════════════════════════════════════════════════════════════════════════
// ESTADÍSTICAS SEAT-FIRST POR EVENTO
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Inventario central por zona basado en el layout (_ss_layout).
 *
 * Cuando existe _ss_layout, esta función es la fuente de verdad para
 * capacidad por zona, ignorando option_qty_t de MPWEM.
 *
 * Calcula por zona: total_seats (del layout, menos removedSeats),
 * sold (de ss_seat_ledger), reserved (temporal + manual), available.
 *
 * @param int $event_id ID del evento (ss_event).
 * @return array [ 'ZONE_NAME' => [ 'zone'=>, 'total'=>, 'sold'=>, 'reserved'=>, 'available'=> ], ... ]
 *               Array vacío si no hay _ss_layout.
 */
function ss_get_zone_inventory( int $event_id ): array {
    if ( ! $event_id ) {
        return array();
    }

    // Aplica en todos los modos con zonas.
    $sale_mode = SS_Event_Service::instance()->get_sale_mode( $event_id );
    if ( ! in_array( $sale_mode, array( 'seat', 'hybrid', 'general' ), true ) ) {
        return array();
    }

    $layout_raw = SS_Event_Service::instance()->get_layout_raw( $event_id );
    if ( empty( $layout_raw ) ) {
        return array();
    }
    $layout = json_decode( $layout_raw, true );
    $rows = ss_layout_get_rows( $layout ?: array() );
    if ( ! is_array( $layout ) || empty( $rows ) ) {
        return array();
    }

    // 1) Contar total de asientos por zona desde el layout
    $zone_totals  = array();
    $seat_to_zone = array();

    foreach ( $rows as $row ) {
        if ( isset( $row['type'] ) && $row['type'] === 'empty' ) {
            continue;
        }
        $zone  = isset( $row['zone'] ) ? trim( $row['zone'] ) : 'GENERAL';
        $label = isset( $row['label'] ) ? $row['label'] : '';
        $count = isset( $row['count'] ) ? (int) $row['count'] : 0;
        if ( $zone === '' ) {
            $zone = 'GENERAL';
        }

        // removedSeats son números de asiento dentro de la fila (integers)
        $removed_nums = array();
        if ( ! empty( $row['removedSeats'] ) && is_array( $row['removedSeats'] ) ) {
            foreach ( $row['removedSeats'] as $rs ) {
                $removed_nums[ (int) $rs ] = true;
            }
        }

        if ( ! isset( $zone_totals[ $zone ] ) ) {
            $zone_totals[ $zone ] = 0;
        }

        for ( $s = 1; $s <= $count; $s++ ) {
            if ( isset( $removed_nums[ $s ] ) ) {
                continue;
            }
            $seat_id = $label . $s;
            $zone_totals[ $zone ]++;
            $seat_to_zone[ $seat_id ] = $zone;
        }
    }

    if ( empty( $zone_totals ) ) {
        return array();
    }

    // 2) Obtener asientos vendidos (con silla)
    $sold_seats = ss_seats_read( $event_id );
    $zone_sold  = array();
    foreach ( $sold_seats as $seat ) {
        $z = isset( $seat_to_zone[ $seat ] ) ? $seat_to_zone[ $seat ] : '';
        if ( $z !== '' ) {
            $zone_sold[ $z ] = ( $zone_sold[ $z ] ?? 0 ) + 1;
        }
    }

    // 2b) Tickets vendidos SIN silla por zona (modo hybrid).
    // Fuente principal: acumulado persistente por evento.
    // Fallback: reconstrucción desde pedidos confirmados.
    $zone_sold_no_seat = ss_get_zone_nonseat_sold_meta( $event_id );
    if ( empty( $zone_sold_no_seat ) ) {
        $zone_sold_no_seat = ss_get_zone_sold_ticket_qtys_from_orders( $event_id );
    }

    // 3) Obtener asientos reservados (temp_reserved + manual_reserved del ledger)
    $reserved_seats = function_exists( 'ss_ledger_get_blocked_seats' )
        ? ss_ledger_get_blocked_seats( $event_id )
        : array_unique( array_merge( ss_seats_get_reserved( $event_id ), ss_seats_get_manual_reserved( $event_id ) ) );
    $zone_reserved = array();
    foreach ( $reserved_seats as $seat ) {
        $z = isset( $seat_to_zone[ $seat ] ) ? $seat_to_zone[ $seat ] : '';
        if ( $z !== '' ) {
            $zone_reserved[ $z ] = ( $zone_reserved[ $z ] ?? 0 ) + 1;
        }
    }

    // 4) Construir resultado
    $result = array();
    foreach ( $zone_totals as $zone => $total ) {
        $sold_seat_count = $zone_sold[ $zone ] ?? 0;
        $sold_no_seat    = $zone_sold_no_seat[ strtoupper( $zone ) ] ?? 0;
        $sold            = $sold_seat_count + $sold_no_seat;
        $reserved        = $zone_reserved[ $zone ] ?? 0;
        $result[ $zone ] = array(
            'zone'      => $zone,
            'total'     => $total,
            'sold'      => $sold,
            'reserved'  => $reserved,
            'available' => max( 0, $total - $sold - $reserved ),
        );
    }

    return $result;
}

// ── Non-seat sold tracking (hybrid mode) ──────────────────────────────────────

/**
 * Suma tickets vendidos por zona que no consumen silla explícita.
 * Reconstrucción desde pedidos confirmados (fallback cuando no hay meta acumulado).
 */
function ss_get_zone_sold_ticket_qtys_from_orders( int $event_id ): array {
    if ( ! $event_id || ! function_exists( 'wc_get_orders' ) ) {
        return array();
    }

    $zone_sold = array();
    $order_ids = wc_get_orders( array(
        'status' => array( 'processing', 'completed' ),
        'limit'  => -1,
        'return' => 'ids',
    ) );

    foreach ( $order_ids as $oid ) {
        $order = wc_get_order( $oid );
        if ( ! $order ) { continue; }
        if ( ss_get_event_id_from_order( $order ) !== $event_id ) { continue; }

        foreach ( $order->get_items() as $item ) {
            // Fuente preferida: detalle por zona (Box Office).
            $ticket_qtys = $item->get_meta( 'ss_ticket_qtys' );
            if ( is_array( $ticket_qtys ) && ! empty( $ticket_qtys ) ) {
                foreach ( $ticket_qtys as $zone_name => $qty ) {
                    $zone_key = strtoupper( trim( (string) $zone_name ) );
                    $q        = max( 0, (int) $qty );
                    if ( $zone_key !== '' && $q > 0 ) {
                        $zone_sold[ $zone_key ] = ( $zone_sold[ $zone_key ] ?? 0 ) + $q;
                    }
                }
                continue;
            }

            // Fallback: qty real del item menos sillas asociadas al item.
            $saved_qty = (int) $item->get_meta( 'ss_ticket_qty' );
            if ( $saved_qty <= 0 ) {
                $saved_qty = (int) $item->get_quantity();
            }

            $item_seats = $item->get_meta( 'ss_seats' );
            if ( ! is_array( $item_seats ) ) { $item_seats = array(); }
            $seat_count   = count( array_filter( array_map( 'strval', $item_seats ) ) );
            $non_seat_qty = max( 0, $saved_qty - $seat_count );
            if ( $non_seat_qty <= 0 ) { continue; }

            $item_zone = strtoupper( trim( (string) $item->get_meta( 'ss_zone' ) ) );
            if ( $item_zone === '' ) { continue; }

            $zones = array_values( array_filter( array_map( 'trim', explode( ',', $item_zone ) ) ) );
            if ( count( $zones ) === 1 ) {
                $zone_sold[ $zones[0] ] = ( $zone_sold[ $zones[0] ] ?? 0 ) + $non_seat_qty;
            }
        }
    }

    return $zone_sold;
}

/**
 * Lee acumulado persistente de tickets vendidos sin silla por zona.
 * Meta en evento: _ss_zone_sold_qty (array: ZONE => qty).
 */
function ss_get_zone_nonseat_sold_meta( int $event_id ): array {
    $raw = get_post_meta( $event_id, '_ss_zone_sold_qty', true );
    if ( ! is_array( $raw ) ) { return array(); }
    $out = array();
    foreach ( $raw as $zone => $qty ) {
        $key = strtoupper( trim( (string) $zone ) );
        $q   = max( 0, (int) $qty );
        if ( $key !== '' && $q > 0 ) { $out[ $key ] = $q; }
    }
    return $out;
}

/** Suma tickets sin silla por zona al acumulado persistente. */
function ss_add_zone_nonseat_sold_meta( int $event_id, array $zone_qtys ): void {
    if ( ! $event_id || empty( $zone_qtys ) ) { return; }
    $current = ss_get_zone_nonseat_sold_meta( $event_id );
    foreach ( $zone_qtys as $zone => $qty ) {
        $key = strtoupper( trim( (string) $zone ) );
        $q   = max( 0, (int) $qty );
        if ( $key === '' || $q <= 0 ) { continue; }
        $current[ $key ] = ( $current[ $key ] ?? 0 ) + $q;
    }
    update_post_meta( $event_id, '_ss_zone_sold_qty', $current );
}

/** Resta tickets sin silla por zona del acumulado persistente. */
function ss_sub_zone_nonseat_sold_meta( int $event_id, array $zone_qtys ): void {
    if ( ! $event_id || empty( $zone_qtys ) ) { return; }
    $current = ss_get_zone_nonseat_sold_meta( $event_id );
    foreach ( $zone_qtys as $zone => $qty ) {
        $key = strtoupper( trim( (string) $zone ) );
        $q   = max( 0, (int) $qty );
        if ( $key === '' || $q <= 0 ) { continue; }
        $current[ $key ] = max( 0, ( $current[ $key ] ?? 0 ) - $q );
    }
    update_post_meta( $event_id, '_ss_zone_sold_qty', $current );
}

/** Extrae tickets sin silla por zona desde un pedido. */
function ss_extract_nonseat_zone_qtys_from_order( $order, int $event_id ): array {
    $zone_qtys = array();
    if ( ! $order || ! $event_id ) { return $zone_qtys; }
    foreach ( $order->get_items() as $item ) {
        $item_event_id = (int) $item->get_meta( 'ss_event_id' );
        if ( $item_event_id && $item_event_id !== $event_id ) { continue; }

        $ticket_qtys = $item->get_meta( 'ss_ticket_qtys' );
        if ( is_array( $ticket_qtys ) && ! empty( $ticket_qtys ) ) {
            foreach ( $ticket_qtys as $z => $q ) {
                $key = strtoupper( trim( (string) $z ) );
                $qty = max( 0, (int) $q );
                if ( $key !== '' && $qty > 0 ) {
                    $zone_qtys[ $key ] = ( $zone_qtys[ $key ] ?? 0 ) + $qty;
                }
            }
            continue;
        }

        $saved_qty = (int) $item->get_meta( 'ss_ticket_qty' );
        if ( $saved_qty <= 0 ) { $saved_qty = (int) $item->get_quantity(); }

        $item_seats = $item->get_meta( 'ss_seats' );
        if ( ! is_array( $item_seats ) ) { $item_seats = array(); }
        $seat_count   = count( array_filter( array_map( 'strval', $item_seats ) ) );
        $non_seat_qty = max( 0, $saved_qty - $seat_count );
        if ( $non_seat_qty <= 0 ) { continue; }

        $item_zone = strtoupper( trim( (string) $item->get_meta( 'ss_zone' ) ) );
        if ( $item_zone === '' ) { continue; }
        $zones = array_values( array_filter( array_map( 'trim', explode( ',', $item_zone ) ) ) );
        if ( count( $zones ) === 1 ) {
            $zone_qtys[ $zones[0] ] = ( $zone_qtys[ $zones[0] ] ?? 0 ) + $non_seat_qty;
        }
    }
    return $zone_qtys;
}

/**
 * Calcula estadísticas de asientos para un evento basado en _ss_layout.
 *
 * @param int $event_id ID del evento (ss_event).
 * @return array { total, sold, checked_in, available, percentage }
 */
function ss_get_event_seating_stats( int $event_id ): array {
    $stats = array(
        'total'      => 0,
        'sold'       => 0,
        'checked_in' => 0,
        'available'  => 0,
        'percentage' => 0,
    );

    // A) Leer _ss_layout y contar asientos totales
    $layout_raw = get_post_meta( $event_id, '_ss_layout', true );
    if ( empty( $layout_raw ) ) {
        return $stats;
    }
    $layout = json_decode( $layout_raw, true );
    $rows = ss_layout_get_rows( $layout ?: array() );
    if ( ! is_array( $layout ) || empty( $rows ) ) {
        return $stats;
    }

    $total = 0;
    foreach ( $rows as $row ) {
        if ( isset( $row['type'] ) && $row['type'] === 'empty' ) {
            continue;
        }
        $count = isset( $row['count'] ) ? (int) $row['count'] : 0;
        $removed = 0;
        if ( ! empty( $row['removedSeats'] ) && is_array( $row['removedSeats'] ) ) {
            $removed = count( $row['removedSeats'] );
        }
        $total += max( 0, $count - $removed );
    }
    $stats['total'] = $total;

    if ( $total === 0 ) {
        return $stats;
    }

    // B) Obtener pedidos WC relacionados con este evento (processing/completed)
    $sold_count      = 0;
    $checkedin_count = 0;

    $order_ids = wc_get_orders( array(
        'status'     => array( 'processing', 'completed' ),
        'limit'      => -1,
        'return'     => 'ids',
        'meta_query' => array(), // se filtrará por items abajo
    ) );

    foreach ( $order_ids as $oid ) {
        $order = wc_get_order( $oid );
        if ( ! $order ) {
            continue;
        }

        // Verificar que este pedido pertenece a este evento
        $order_event_id = ss_get_event_id_from_order( $order );
        if ( $order_event_id !== $event_id ) {
            continue;
        }

        // Contar sillas vendidas en este pedido
        $seats = ss_seats_get_from_order( $oid );
        $seat_count = count( $seats );
        if ( $seat_count === 0 ) {
            continue;
        }

        $sold_count += $seat_count;

        // Check-in: si el pedido tiene _ss_checked_in = 'yes', todas sus sillas cuentan
        $checked = get_post_meta( $oid, '_ss_checked_in', true );
        if ( $checked === 'yes' ) {
            $checkedin_count += $seat_count;
        }
    }

    $stats['sold']       = $sold_count;
    $stats['checked_in'] = $checkedin_count;
    $stats['available']  = max( 0, $total - $sold_count );
    $stats['percentage'] = $total > 0 ? (int) round( $sold_count / $total * 100 ) : 0;

    return $stats;
}

/**
 * Estadísticas de tickets zone (sin silla) para un evento.
 * Cuenta desde order items con ss_ticket_qty en pedidos processing/completed.
 */
function ss_get_event_zone_stats( int $event_id ): array {
    $stats = array(
        'total'      => 0,
        'sold'       => 0,
        'checked_in' => 0,
        'available'  => 0,
        'percentage' => 0,
    );

    $inventory = ss_get_zone_inventory( $event_id );
    if ( empty( $inventory ) ) { return $stats; }

    foreach ( $inventory as $data ) {
        $stats['total'] += $data['total'];
        $stats['sold']  += $data['sold'];
    }

    // Check-in desde pedidos
    $order_ids = wc_get_orders( array(
        'status' => array( 'processing', 'completed' ),
        'limit'  => -1,
        'return' => 'ids',
    ) );
    foreach ( $order_ids as $oid ) {
        $order = wc_get_order( $oid );
        if ( ! $order ) { continue; }
        if ( ss_get_event_id_from_order( $order ) !== $event_id ) { continue; }
        if ( get_post_meta( $oid, '_ss_checked_in', true ) !== 'yes' ) { continue; }
        foreach ( $order->get_items() as $item ) {
            $qty = (int) $item->get_meta( 'ss_ticket_qty' );
            if ( $qty > 0 ) { $stats['checked_in'] += $qty; }
        }
    }

    $stats['available']  = max( 0, $stats['total'] - $stats['sold'] );
    $stats['percentage'] = $stats['total'] > 0 ? (int) round( $stats['sold'] / $stats['total'] * 100 ) : 0;

    return $stats;
}

/**
 * Estadísticas combinadas: usa seat stats o zone stats según el modo de venta.
 */
function ss_get_event_combined_stats( int $event_id ): array {
    $sale_mode = SS_Event_Service::instance()->get_sale_mode( $event_id );

    if ( $sale_mode === 'general' ) {
        return ss_get_event_zone_stats( $event_id );
    }

    $seat_stats = ss_get_event_seating_stats( $event_id );

    if ( $sale_mode === 'hybrid' ) {
        $zone_inv = ss_get_zone_inventory( $event_id );
        $zone_sold = 0;
        foreach ( $zone_inv as $data ) {
            $zone_sold += $data['sold'];
        }
        $seat_stats['sold']      += max( 0, $zone_sold - $seat_stats['sold'] );
        $seat_stats['available']  = max( 0, $seat_stats['total'] - $seat_stats['sold'] );
        $seat_stats['percentage'] = $seat_stats['total'] > 0
            ? (int) round( $seat_stats['sold'] / $seat_stats['total'] * 100 ) : 0;
    }

    return $seat_stats;
}

/**
 * Contador ligero de check-ins para un evento.
 * Devuelve: [ 'checked_in' => int, 'total' => int ]
 * total = suma de cantidades de todos los ticket types.
 */
function ss_get_checkin_counter( int $event_id ): array {
    $result = array( 'checked_in' => 0, 'total' => 0 );
    if ( ! $event_id ) {
        return $result;
    }

    // Capacidad total: inventario central (layout) como fuente de verdad
    $inventory = ss_get_zone_inventory( $event_id );
    if ( ! empty( $inventory ) ) {
        foreach ( $inventory as $data ) {
            $result['total'] += $data['total'];
        }
    } else {
        // Fallback: sumar capacity de _ss_ticket_types
        $ticket_types = get_post_meta( $event_id, '_ss_ticket_types', true );
        if ( is_array( $ticket_types ) ) {
            foreach ( $ticket_types as $tt ) {
                $qty = isset( $tt['capacity'] ) ? (int) $tt['capacity'] : 0;
                $result['total'] += $qty;
            }
        }
    }

    // Contar pedidos con _ss_checked_in = 'yes' para este evento
    $order_ids = wc_get_orders( array(
        'status' => array( 'processing', 'completed' ),
        'limit'  => -1,
        'return' => 'ids',
    ) );

    foreach ( $order_ids as $oid ) {
        $order = wc_get_order( $oid );
        if ( ! $order ) {
            continue;
        }
        $order_event_id = ss_get_event_id_from_order( $order );
        if ( $order_event_id !== $event_id ) {
            continue;
        }
        $checked = get_post_meta( $oid, '_ss_checked_in', true );
        if ( $checked === 'yes' ) {
            // Contar qty total de items en este pedido
            foreach ( $order->get_items() as $item ) {
                $result['checked_in'] += $item->get_quantity();
            }
        }
    }

    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════════
// PANEL DE ESTADÍSTICAS: RESUMEN POR EVENTO (WP-ADMIN)
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'add_meta_boxes', 'ss_sold_seats_add_metabox' );

function ss_sold_seats_add_metabox(): void {
    add_meta_box(
        'ss_sold_seats_inspector',
        __( 'Estadísticas de Sillas', 'ss-seating' ),
        'ss_sold_seats_metabox_render',
        'ss_event',
        'normal',
        'default'
    );
}

function ss_sold_seats_metabox_render( WP_Post $post ): void {
    $event_id = $post->ID;
    $stats    = ss_get_event_combined_stats( $event_id );
    ?>
    <style>
        #ss_sold_seats_inspector .inside { padding: 0; }
        .ss-stats-grid {
            display: flex; gap: 0; border: 1px solid #e0e0e0; border-radius: 6px;
            overflow: hidden; background: #fff;
        }
        .ss-stats-grid .ss-stat {
            flex: 1; text-align: center; padding: 16px 12px;
            border-right: 1px solid #e0e0e0;
        }
        .ss-stats-grid .ss-stat:last-child { border-right: none; }
        .ss-stat__num {
            display: block; font-size: 30px; font-weight: 700; line-height: 1;
        }
        .ss-stat__label {
            display: block; font-size: 11px; color: #666;
            text-transform: uppercase; letter-spacing: .05em; margin-top: 6px;
        }
        .ss-stat--total .ss-stat__num   { color: #2c3e50; }
        .ss-stat--sold .ss-stat__num    { color: #c0392b; }
        .ss-stat--checkin .ss-stat__num { color: #2980b9; }
        .ss-stat--avail .ss-stat__num   { color: #27ae60; }
        .ss-stat--pct .ss-stat__num     { color: #7f8c8d; }
        .ss-stats-bar {
            height: 8px; border-radius: 4px; background: #ecf0f1;
            margin-top: 14px; overflow: hidden;
        }
        .ss-stats-bar__fill {
            height: 100%; border-radius: 4px;
            background: linear-gradient(90deg, #e74c3c, #c0392b);
            transition: width .3s ease;
        }
        .ss-stats-empty {
            color: #888; font-style: italic; font-size: 13px; padding: 16px;
        }
    </style>

    <?php if ( $stats['total'] === 0 ) : ?>
        <p class="ss-stats-empty">
            <?php esc_html_e( 'No hay layout configurado para este evento.', 'ss-seating' ); ?>
        </p>
    <?php else : ?>
        <div class="ss-stats-grid">
            <div class="ss-stat ss-stat--total">
                <span class="ss-stat__num"><?php echo esc_html( $stats['total'] ); ?></span>
                <span class="ss-stat__label"><?php esc_html_e( 'Total', 'ss-seating' ); ?></span>
            </div>
            <div class="ss-stat ss-stat--sold">
                <span class="ss-stat__num"><?php echo esc_html( $stats['sold'] ); ?></span>
                <span class="ss-stat__label"><?php esc_html_e( 'Vendidos', 'ss-seating' ); ?></span>
            </div>
            <div class="ss-stat ss-stat--checkin">
                <span class="ss-stat__num"><?php echo esc_html( $stats['checked_in'] ); ?></span>
                <span class="ss-stat__label"><?php esc_html_e( 'Ingresados', 'ss-seating' ); ?></span>
            </div>
            <div class="ss-stat ss-stat--avail">
                <span class="ss-stat__num"><?php echo esc_html( $stats['available'] ); ?></span>
                <span class="ss-stat__label"><?php esc_html_e( 'Disponibles', 'ss-seating' ); ?></span>
            </div>
            <div class="ss-stat ss-stat--pct">
                <span class="ss-stat__num"><?php echo esc_html( $stats['percentage'] ); ?>%</span>
                <span class="ss-stat__label"><?php esc_html_e( 'Ocupación', 'ss-seating' ); ?></span>
            </div>
        </div>
        <div class="ss-stats-bar">
            <div class="ss-stats-bar__fill" style="width: <?php echo esc_attr( $stats['percentage'] ); ?>%;"></div>
        </div>
    <?php endif; ?>

    <?php
    // Botón Exportar compradores
    $export_url = wp_nonce_url(
        add_query_arg( array(
            'action'   => 'ss_export_event_leads',
            'event_id' => $event_id,
        ), admin_url( 'admin-ajax.php' ) ),
        'ss_export_event_leads_' . $event_id
    );
    ?>
    <div style="padding:12px 0 4px; text-align:right;">
        <a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary">
            <?php esc_html_e( 'Exportar compradores', 'ss-seating' ); ?>
        </a>
    </div>
    <?php
}

// ── Actualizar shortcode para pasar clases al filter ─────────────────────────
// El filter ss_seating_seat_classes ya está registrado arriba.
// Aquí añadimos el hook que persiste ss_seats en el item del pedido
// (WooCommerce lo hace automáticamente desde cart_item_data si se añade en checkout).

add_action( 'woocommerce_checkout_create_order_line_item', 'ss_seating_persist_seats_to_order_item', 10, 4 );

function ss_seating_persist_seats_to_order_item( $item, $cart_item_key, $values, $order ): void {
    $seats = null;

    // Fuente 1: cart item data (flujo normal desde ss_seating_add_cart_item_data)
    if ( ! empty( $values['ss_seats'] ) && is_array( $values['ss_seats'] ) ) {
        $seats = array_values( array_filter( array_map( 'sanitize_text_field', $values['ss_seats'] ) ) );
    }

    // Fuente 2: fallback desde $_POST (si cart item data se perdió entre sesiones)
    if ( empty( $seats ) && ! empty( $_POST['ss_seats'] ) ) {
        $raw   = wc_clean( wp_unslash( $_POST['ss_seats'] ) );
        $parsed = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
        if ( ! empty( $parsed ) ) {
            // Si hay event_id y ticket_name, filtrar por zona de este item
            $event_id    = ! empty( $values['ss_event_id'] ) ? (int) $values['ss_event_id'] : 0;
            $ticket_name = ! empty( $values['ss_zone'] ) ? strtoupper( $values['ss_zone'] ) : '';
            if ( $event_id > 0 && $ticket_name !== '' ) {
                $zone_map = ss_seats_zone_map( $event_id );
                $filtered = array();
                foreach ( $parsed as $s ) {
                    $sz = isset( $zone_map[ $s ] ) ? strtoupper( $zone_map[ $s ] ) : '';
                    if ( $sz === $ticket_name ) {
                        $filtered[] = $s;
                    }
                }
                $seats = $filtered;
            } else {
                $seats = $parsed;
            }
        }
    }

    // Fuente 3: fallback desde WC session
    if ( empty( $seats ) && WC()->session ) {
        $session_val = WC()->session->get( 'ss_pending_seats' );
        if ( $session_val ) {
            $parsed = array_values( array_filter( array_map( 'trim', explode( ',', wc_clean( $session_val ) ) ) ) );
            if ( ! empty( $parsed ) ) {
                $event_id    = ! empty( $values['ss_event_id'] ) ? (int) $values['ss_event_id'] : 0;
                $ticket_name = ! empty( $values['ss_zone'] ) ? strtoupper( $values['ss_zone'] ) : '';
                if ( $event_id > 0 && $ticket_name !== '' ) {
                    $zone_map = ss_seats_zone_map( $event_id );
                    $filtered = array();
                    foreach ( $parsed as $s ) {
                        $sz = isset( $zone_map[ $s ] ) ? strtoupper( $zone_map[ $s ] ) : '';
                        if ( $sz === $ticket_name ) {
                            $filtered[] = $s;
                        }
                    }
                    $seats = $filtered;
                } else {
                    $seats = $parsed;
                }
            }
        }
    }

    if ( ! empty( $seats ) ) {
        $item->update_meta_data( 'ss_seats', $seats );
        // Enriquecer con seat_data si no viene del cart
        if ( empty( $values['ss_seat_data'] ) ) {
            $eid = ! empty( $values['ss_event_id'] ) ? (int) $values['ss_event_id'] : 0;
            if ( $eid > 0 ) {
                $item->update_meta_data( 'ss_seat_data', ss_build_seat_data( $seats, $eid ) );
            }
        }
    }

    if ( ! empty( $values['ss_event_id'] ) ) {
        $item->update_meta_data( 'ss_event_id', (int) $values['ss_event_id'] );
    }
    if ( ! empty( $values['ss_seat_data'] ) && is_array( $values['ss_seat_data'] ) ) {
        $item->update_meta_data( 'ss_seat_data', $values['ss_seat_data'] );
    }
    if ( ! empty( $values['ss_zone'] ) ) {
        $item->update_meta_data( 'ss_zone', sanitize_text_field( $values['ss_zone'] ) );
    }
    if ( ! empty( $values['ss_ticket_qty'] ) ) {
        $item->update_meta_data( 'ss_ticket_qty', (int) $values['ss_ticket_qty'] );
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// PÁGINA DE AJUSTES DEL PLUGIN
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'admin_menu', 'ss_seating_admin_menu' );

function ss_seating_admin_menu(): void {
    // ── Menú raíz → Estadísticas ────────────────────────────────────────────
    add_menu_page(
        __( 'SS Seating', 'ss-seating' ),
        __( 'SS Seating', 'ss-seating' ),
        'manage_woocommerce',
        'ss-seating-dashboard',
        'ss_seating_stats_page',
        'dashicons-tickets-alt',
        26
    );

    // 1. Estadísticas (primera entrada = misma que root para no duplicar)
    add_submenu_page(
        'ss-seating-dashboard',
        __( 'Estadísticas', 'ss-seating' ),
        __( 'Estadísticas', 'ss-seating' ),
        'manage_woocommerce',
        'ss-seating-dashboard',
        'ss_seating_stats_page'
    );

    // 2. Eventos (apunta al listado de ss_event)
    add_submenu_page(
        'ss-seating-dashboard',
        __( 'Eventos', 'ss-seating' ),
        __( 'Eventos', 'ss-seating' ),
        'edit_posts',
        'edit.php?post_type=ss_event'
    );

    // 3. Box Office (incluye Informe BO como tab interno)
    add_submenu_page(
        'ss-seating-dashboard',
        __( 'Box Office', 'ss-seating' ),
        __( 'Box Office', 'ss-seating' ),
        'manage_options',
        'ss-boxoffice-settings',
        'ss_boxoffice_settings_page'
    );

    // 4. Leads
    add_submenu_page(
        'ss-seating-dashboard',
        __( 'Leads', 'ss-seating' ),
        __( 'Leads', 'ss-seating' ),
        'manage_woocommerce',
        'ss-leads',
        'ss_leads_admin_page'
    );

    // 5. Control de Ingreso
    add_submenu_page(
        'ss-seating-dashboard',
        __( 'Control de Ingreso', 'ss-seating' ),
        __( 'Control de Ingreso', 'ss-seating' ),
        'manage_woocommerce',
        'ss-checkin-dashboard',
        'ss_checkin_dashboard_page'
    );

    // 6. Cierre Contable
    add_submenu_page(
        'ss-seating-dashboard',
        __( 'Cierre Contable', 'ss-seating' ),
        __( 'Cierre Contable', 'ss-seating' ),
        'manage_woocommerce',
        'ss-cierre-contable',
        'ss_cierre_contable_page'
    );

    // Fidelización y Configuración se registran desde sus propias clases (priority 20/25)

    // Páginas ocultas (no aparecen en el menú, pero accesibles por URL)
    add_submenu_page( null, 'Cierre Contable (legacy)', 'Cierre Contable', 'manage_woocommerce', 'ss-bo-report', 'ss_cierre_contable_page' );
    add_submenu_page( null, 'Estadísticas',  'Estadísticas',  'manage_woocommerce', 'ss-seating-stats', 'ss_seating_stats_page' );

}

// ── Dashboard placeholder ────────────────────────────────────────────────────
function ss_seating_dashboard_page(): void {
    // ── Ocultar admin_notices de otros plugins en esta página ─────────────────
    remove_all_actions( 'admin_notices' );
    remove_all_actions( 'all_admin_notices' );

    global $wpdb;

    // ── Zona horaria de WordPress ───────────────────────────────────────────
    $today    = wp_date( 'Y-m-d' );
    $now_local = wp_date( 'Y-m-d H:i:s' );
    $utc_offset_sec = (int) ( get_option( 'gmt_offset', 0 ) * 3600 );
    $utc_offset_sql = sprintf( '%+d', $utc_offset_sec ) . ' SECOND';

    // ── 1. Eventos activos ───────────────────────────────────────────────────
    $active_events = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'ss_event' AND post_status = 'publish'"
    );

    // ── 2. Tickets vendidos hoy (created_at UTC → hora local) ────────────────
    $ledger_table  = $wpdb->prefix . 'ss_seat_ledger';
    $tickets_today = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$ledger_table} WHERE status = 'sold' AND DATE(created_at + INTERVAL {$utc_offset_sql}) = %s",
            $today
        )
    );

    // ── 3. Check-ins hoy ─────────────────────────────────────────────────────
    // Suma todos los tickets con check-in en eventos cuya fecha sea hoy
    $checkins_today = 0;
    $today_events = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_ss_event_date'
             WHERE p.post_type = 'ss_event' AND p.post_status = 'publish' AND pm.meta_value = %s",
            $today
        )
    );
    foreach ( $today_events as $today_eid ) {
        $ci = ss_get_checkin_counter( (int) $today_eid );
        $checkins_today += $ci['checked_in'];
    }

    // ── 4. Próximo evento + métricas ─────────────────────────────────────────
    $next_event_row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT p.ID, p.post_title, pm.meta_value AS start_date
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_ss_event_date'
             WHERE p.post_type = 'ss_event' AND p.post_status = 'publish'
             AND pm.meta_value >= %s
             ORDER BY pm.meta_value ASC
             LIMIT 1",
            $today
        )
    );

    $next_event_html = '';
    $revenue_html    = '';

    if ( $next_event_row ) {
        $eid        = (int) $next_event_row->ID;
        $ename      = esc_html( $next_event_row->post_title );
        $edate      = $next_event_row->start_date ? date_i18n( 'j M Y — H:i', strtotime( $next_event_row->start_date ) ) : '—';
        $sold       = ss_seats_read( $eid );
        $sold_count = count( $sold );
        $inventory  = ss_get_zone_inventory( $eid );
        $total      = 0;
        $available  = 0;
        foreach ( $inventory as $zone ) {
            $total     += $zone['total'] ?? 0;
            $available += $zone['available'] ?? 0;
        }
        $occ_pct = $total > 0 ? round( ( $sold_count / $total ) * 100 ) : 0;

        // Check-ins de este evento — usa la función centralizada
        $ci_counter     = ss_get_checkin_counter( $eid );
        $event_checkins = $ci_counter['checked_in'];

        // Ingresos: sumar totales de pedidos processing/completed
        $event_orders  = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'ss_event_id' AND meta_value = %s",
                (string) $eid
            )
        );
        $event_revenue = 0.0;
        foreach ( $event_orders as $oid ) {
            $o = wc_get_order( (int) $oid );
            if ( $o && in_array( $o->get_status(), array( 'processing', 'completed' ), true ) ) {
                $event_revenue += (float) $o->get_total();
            }
        }

        // No-shows y tasa de asistencia
        $no_shows       = max( 0, $sold_count - $event_checkins );
        $attendance_pct = $sold_count > 0 ? round( ( $event_checkins / $sold_count ) * 100 ) : 0;

        // Barra de ocupación
        $bar_filled = (int) round( $occ_pct / 5 ); // 20 bloques max
        $bar_empty  = 20 - $bar_filled;
        $bar_html   = '<span style="color:#2271b1;">' . str_repeat( '█', $bar_filled ) . '</span>'
                    . '<span style="color:#ddd;">' . str_repeat( '░', $bar_empty ) . '</span>';

        $next_event_html = "
            <strong style='font-size:14px;'>{$ename}</strong><br>
            <span style='color:#888;font-size:13px;'>{$edate}</span>
            <table style='width:100%;margin-top:14px;border-collapse:collapse;font-size:14px;'>
                <tr><td style='padding:5px 0;'>Vendidos</td><td style='text-align:right;font-weight:600;'>{$sold_count}</td></tr>
                <tr><td style='padding:5px 0;'>Disponibles</td><td style='text-align:right;font-weight:600;'>{$available}</td></tr>
                <tr><td style='padding:5px 0;'>Ingresados</td><td style='text-align:right;font-weight:600;color:#2271b1;'>{$event_checkins}</td></tr>
                <tr><td style='padding:5px 0;'>No-shows</td><td style='text-align:right;font-weight:600;color:#d63638;'>{$no_shows}</td></tr>
                <tr><td style='padding:5px 0;'>Asistencia</td><td style='text-align:right;font-weight:600;'>{$attendance_pct}%</td></tr>
                <tr style='border-top:1px solid #e0e0e0;'>
                    <td style='padding:8px 0;font-weight:600;'>Ocupación</td>
                    <td style='text-align:right;font-weight:700;font-size:18px;'>{$occ_pct}%</td>
                </tr>
                <tr><td colspan='2' style='padding:2px 0;font-family:monospace;font-size:16px;letter-spacing:1px;'>{$bar_html}</td></tr>
            </table>
        ";

        // Tarjeta de ingresos
        $revenue_formatted = '$' . number_format( $event_revenue, 0, ',', '.' );
        $revenue_html = "
            <div class='ss-dash-card'>
                <h3>Ingresos del evento</h3>
                <div class='ss-dash-number' style='color:#00a32a;'>{$revenue_formatted}</div>
                <div style='margin-top:6px;color:#888;font-size:12px;'>" . esc_html( $next_event_row->post_title ) . "</div>
            </div>
        ";
    } else {
        $next_event_html = '<span style="color:#888;">No hay eventos próximos.</span>';
    }

    ?>
    <div class="wrap">
        <h1 style="margin-bottom:20px;">SS Seating — Dashboard</h1>

        <style>
            .ss-dash-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 16px;
                max-width: 1200px;
            }
            .ss-dash-card {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 20px 24px;
                box-shadow: 0 1px 3px rgba(0,0,0,.04);
            }
            .ss-dash-card h3 {
                margin: 0 0 8px 0;
                font-size: 12px;
                text-transform: uppercase;
                color: #888;
                letter-spacing: .6px;
                font-weight: 600;
            }
            .ss-dash-number {
                font-size: 36px;
                font-weight: 700;
                line-height: 1.1;
                color: #1d2327;
            }
            .ss-dash-event-card {
                grid-column: span 2;
            }
            @media (max-width: 780px) {
                .ss-dash-event-card { grid-column: span 1; }
            }
        </style>

        <div class="ss-dash-grid">
            <div class="ss-dash-card">
                <h3>Eventos activos</h3>
                <div class="ss-dash-number"><?php echo esc_html( $active_events ); ?></div>
            </div>
            <div class="ss-dash-card">
                <h3>Tickets vendidos hoy</h3>
                <div class="ss-dash-number"><?php echo esc_html( $tickets_today ); ?></div>
            </div>
            <div class="ss-dash-card">
                <h3>Check-ins hoy</h3>
                <div class="ss-dash-number"><?php echo esc_html( $checkins_today ); ?></div>
            </div>
            <?php echo $revenue_html; ?>
            <div class="ss-dash-card ss-dash-event-card">
                <h3>Próximo evento</h3>
                <div style="margin-top:4px;"><?php echo $next_event_html; ?></div>
            </div>
        </div>
    </div>
    <?php
}

// ── Estadísticas ─────────────────────────────────────────────────────────────
function ss_seating_stats_page(): void {
    remove_all_actions( 'admin_notices' );
    remove_all_actions( 'all_admin_notices' );

    global $wpdb;

    // ── Eventos disponibles ──────────────────────────────────────────────────
    $events = $wpdb->get_results(
        "SELECT ID, post_title FROM {$wpdb->posts}
         WHERE post_type = 'ss_event' AND post_status = 'publish'
         ORDER BY post_date DESC LIMIT 50"
    );

    $selected_event = isset( $_GET['ss_event'] ) ? (int) $_GET['ss_event'] : 0;
    if ( ! $selected_event && ! empty( $events ) ) {
        $selected_event = (int) $events[0]->ID;
    }

    $ledger_table = $wpdb->prefix . 'ss_seat_ledger';

    // ── KPI globals ──────────────────────────────────────────────────────────
    $today              = wp_date( 'Y-m-d' );
    $utc_offset_sec     = (int) ( get_option( 'gmt_offset', 0 ) * 3600 );
    $utc_offset_sql     = sprintf( '%+d', $utc_offset_sec ) . ' SECOND';
    $kpi_active         = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'ss_event' AND post_status = 'publish'"
    );
    $kpi_tickets_today  = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$ledger_table} WHERE status = 'sold' AND DATE(created_at + INTERVAL {$utc_offset_sql}) = %s",
            $today
        )
    );
    $kpi_checkins_today = 0;
    $today_eids         = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_ss_event_date'
             WHERE p.post_type = 'ss_event' AND p.post_status = 'publish' AND pm.meta_value = %s",
            $today
        )
    );
    foreach ( $today_eids as $te ) {
        $ci = ss_get_checkin_counter( (int) $te );
        $kpi_checkins_today += $ci['checked_in'];
    }
    $kpi_next_row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT p.ID, p.post_title FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_ss_event_date'
             WHERE p.post_type = 'ss_event' AND p.post_status = 'publish' AND pm.meta_value >= %s
             ORDER BY pm.meta_value ASC LIMIT 1",
            $today
        )
    );
    $kpi_revenue_html = '';
    if ( $kpi_next_row ) {
        $ne_orders  = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'ss_event_id' AND meta_value = %s",
                (string) $kpi_next_row->ID
            )
        );
        $ne_revenue = 0.0;
        foreach ( $ne_orders as $oid ) {
            $o = wc_get_order( (int) $oid );
            if ( $o && in_array( $o->get_status(), array( 'processing', 'completed' ), true ) ) {
                $ne_revenue += (float) $o->get_total();
            }
        }
        $ne_name          = esc_html( $kpi_next_row->post_title );
        $ne_revenue_fmt   = '$' . number_format( $ne_revenue, 0, ',', '.' );
        $kpi_revenue_html = "
            <div class='ss-dash-card'>
                <h3>Ingresos — próximo evento</h3>
                <div class='ss-dash-number' style='color:#00a32a;'>{$ne_revenue_fmt}</div>
                <div style='margin-top:6px;color:#888;font-size:12px;'>{$ne_name}</div>
            </div>
        ";
    }

    ?>
    <div class="wrap">
        <h1 style="margin-bottom:20px;">SS Seating — Estadísticas</h1>

        <style>
            .ss-dash-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; max-width:1200px; margin-bottom:28px; }
            .ss-dash-card { background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:18px 22px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
            .ss-dash-card h3 { margin:0 0 8px; font-size:11px; text-transform:uppercase; color:#888; letter-spacing:.6px; font-weight:600; }
            .ss-dash-number { font-size:34px; font-weight:700; line-height:1.1; color:#1d2327; }
        </style>

        <div class="ss-dash-grid">
            <div class="ss-dash-card">
                <h3>Eventos activos</h3>
                <div class="ss-dash-number"><?php echo esc_html( $kpi_active ); ?></div>
            </div>
            <div class="ss-dash-card">
                <h3>Tickets vendidos hoy</h3>
                <div class="ss-dash-number"><?php echo esc_html( $kpi_tickets_today ); ?></div>
            </div>
            <div class="ss-dash-card">
                <h3>Check-ins hoy</h3>
                <div class="ss-dash-number"><?php echo esc_html( $kpi_checkins_today ); ?></div>
            </div>
            <?php echo $kpi_revenue_html; ?>
        </div>

        <form method="get" style="margin-bottom:20px;">
            <input type="hidden" name="page" value="ss-seating-dashboard">
            <label for="ss_event" style="font-weight:600;margin-right:8px;">Evento:</label>
            <select name="ss_event" id="ss_event" onchange="this.form.submit()" style="min-width:300px;padding:4px 8px;">
                <?php foreach ( $events as $ev ) : ?>
                    <option value="<?php echo esc_attr( $ev->ID ); ?>" <?php selected( $selected_event, (int) $ev->ID ); ?>>
                        <?php echo esc_html( $ev->post_title ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ( ! $selected_event ) : ?>
            <p>No hay eventos disponibles.</p>
        <?php else :
            // ── Datos del evento ─────────────────────────────────────────────
            $eid        = $selected_event;
            $sold       = ss_seats_read( $eid );
            $sold_count = count( $sold );
            $inventory  = ss_get_zone_inventory( $eid );
            $capacity   = 0;
            $avail      = 0;
            foreach ( $inventory as $z ) {
                $capacity += $z['total'] ?? 0;
                $avail    += $z['available'] ?? 0;
            }
            $occ_pct = $capacity > 0 ? round( ( $sold_count / $capacity ) * 100 ) : 0;

            // Check-ins + revenue — usar ledger como fuente (HPOS-safe)
            $ledger_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT seat_id, order_id FROM {$ledger_table}
                     WHERE event_id = %d AND status = 'sold' AND order_id > 0
                     ORDER BY seat_id ASC",
                    $eid
                )
            );
            $ledger_order_ids = array_values( array_unique( array_column( $ledger_rows, 'order_id' ) ) );

            $checkins    = 0;
            $revenue     = 0.0;
            $order_cache = array();

            // Construir caché de pedidos y sumar ingresos
            foreach ( $ledger_order_ids as $oid ) {
                $o = wc_get_order( (int) $oid );
                if ( ! $o ) { continue; }
                $order_cache[ (int) $oid ] = $o;
                if ( in_array( $o->get_status(), array( 'processing', 'completed' ), true ) ) {
                    $revenue += (float) $o->get_total();
                }
            }

            // Contar check-ins por ASIENTO (no por pedido)
            foreach ( $ledger_rows as $row ) {
                $oid     = (int) $row->order_id;
                $seat_id = $row->seat_id;
                $seat_ci = get_post_meta( $oid, '_ss_seat_checkins', true );
                if ( is_array( $seat_ci ) && ! empty( $seat_ci ) ) {
                    // Datos por asiento disponibles — usar directamente
                    if ( ! empty( $seat_ci[ $seat_id ] ) ) { $checkins++; }
                } elseif ( get_post_meta( $oid, '_ss_checked_in', true ) === 'yes' ) {
                    // Sin datos por asiento — fallback a flag de pedido completo
                    $checkins++;
                }
            }

            $no_shows       = max( 0, $sold_count - $checkins );
            $attend_pct     = $sold_count > 0 ? round( ( $checkins / $sold_count ) * 100 ) : 0;
            $bar_filled     = (int) round( $occ_pct / 5 );
            $bar_empty      = 20 - $bar_filled;
            $occ_bar        = '<span style="color:#2271b1;">' . str_repeat( '█', $bar_filled ) . '</span>'
                            . '<span style="color:#ddd;">' . str_repeat( '░', $bar_empty ) . '</span>';
            $att_bar_filled = (int) round( $attend_pct / 5 );
            $att_bar_empty  = 20 - $att_bar_filled;
            $att_bar        = '<span style="color:#00a32a;">' . str_repeat( '█', $att_bar_filled ) . '</span>'
                            . '<span style="color:#ddd;">' . str_repeat( '░', $att_bar_empty ) . '</span>';

            // Ventas por día (created_at UTC → hora local WP)
            $utc_offset_sec = (int) ( get_option( 'gmt_offset', 0 ) * 3600 );
            $utc_offset_sql = sprintf( '%+d', $utc_offset_sec ) . ' SECOND';
            $sales_by_day = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DATE(created_at + INTERVAL {$utc_offset_sql}) AS day, COUNT(*) AS qty
                     FROM {$ledger_table}
                     WHERE event_id = %d AND status = 'sold'
                     GROUP BY DATE(created_at + INTERVAL {$utc_offset_sql})
                     ORDER BY day DESC",
                    $eid
                )
            );

            // Compradores: reusar $ledger_rows (misma query, ya ejecutada arriba)
            $buyers = $ledger_rows;
        ?>

        <style>
            .ss-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 14px;
                max-width: 1100px;
                margin-bottom: 24px;
            }
            .ss-stat-card {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 16px 20px;
                box-shadow: 0 1px 3px rgba(0,0,0,.04);
            }
            .ss-stat-card h4 {
                margin: 0 0 6px 0;
                font-size: 11px;
                text-transform: uppercase;
                color: #888;
                letter-spacing: .5px;
                font-weight: 600;
            }
            .ss-stat-val {
                font-size: 28px;
                font-weight: 700;
                color: #1d2327;
                line-height: 1.1;
            }
            .ss-stat-bar {
                font-family: monospace;
                font-size: 14px;
                letter-spacing: 1px;
                margin-top: 6px;
            }
            .ss-stats-section {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 20px 24px;
                margin-bottom: 20px;
                max-width: 1100px;
                box-shadow: 0 1px 3px rgba(0,0,0,.04);
            }
            .ss-stats-section h3 {
                margin: 0 0 14px 0;
                font-size: 15px;
                font-weight: 600;
            }
            .ss-stats-table {
                width: 100%;
                border-collapse: collapse;
            }
            .ss-stats-table th {
                text-align: left;
                padding: 8px 10px;
                font-size: 12px;
                text-transform: uppercase;
                color: #666;
                border-bottom: 2px solid #e0e0e0;
                font-weight: 600;
            }
            .ss-stats-table td {
                padding: 7px 10px;
                border-bottom: 1px solid #f0f0f0;
                font-size: 13px;
            }
            .ss-stats-table tr:hover td {
                background: #f9f9f9;
            }
        </style>

        <!-- Resumen -->
        <div class="ss-stats-grid">
            <div class="ss-stat-card">
                <h4>Vendidos</h4>
                <div class="ss-stat-val"><?php echo esc_html( $sold_count ); ?></div>
            </div>
            <div class="ss-stat-card">
                <h4>Capacidad</h4>
                <div class="ss-stat-val"><?php echo esc_html( $capacity ); ?></div>
            </div>
            <div class="ss-stat-card">
                <h4>Ingresados</h4>
                <div class="ss-stat-val" style="color:#2271b1;"><?php echo esc_html( $checkins ); ?></div>
            </div>
            <div class="ss-stat-card">
                <h4>No-shows</h4>
                <div class="ss-stat-val" style="color:#d63638;"><?php echo esc_html( $no_shows ); ?></div>
            </div>
            <div class="ss-stat-card">
                <h4>Ingresos</h4>
                <div class="ss-stat-val" style="color:#00a32a;">$<?php echo esc_html( number_format( $revenue, 0, ',', '.' ) ); ?></div>
            </div>
        </div>

        <!-- Ocupación y Asistencia -->
        <div class="ss-stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">
            <div class="ss-stat-card">
                <h4>Ocupación</h4>
                <div class="ss-stat-val"><?php echo esc_html( $occ_pct ); ?>%</div>
                <div class="ss-stat-bar"><?php echo $occ_bar; ?></div>
            </div>
            <div class="ss-stat-card">
                <h4>Asistencia</h4>
                <div class="ss-stat-val"><?php echo esc_html( $attend_pct ); ?>%</div>
                <div class="ss-stat-bar"><?php echo $att_bar; ?></div>
            </div>
        </div>

        <!-- Ventas por día -->
        <div class="ss-stats-section">
            <h3>Ventas por día</h3>
            <?php if ( empty( $sales_by_day ) ) : ?>
                <p style="color:#888;">Sin ventas registradas en el ledger.</p>
            <?php else : ?>
                <table class="ss-stats-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th style="text-align:right;">Tickets</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $sales_by_day as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( date_i18n( 'j M Y', strtotime( $row->day ) ) ); ?></td>
                                <td style="text-align:right;font-weight:600;"><?php echo esc_html( $row->qty ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Compradores -->
        <div class="ss-stats-section">
            <h3>Compradores</h3>
            <?php if ( empty( $buyers ) ) : ?>
                <p style="color:#888;">Sin registros en el ledger.</p>
            <?php else : ?>
                <table class="ss-stats-table">
                    <thead>
                        <tr>
                            <th>Asiento</th>
                            <th>Nombre</th>
                            <th>Email</th>
                            <th>Pedido</th>
                            <th>Check-in</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $buyers as $b ) :
                            $oid = (int) $b->order_id;
                            if ( ! isset( $order_cache[ $oid ] ) ) {
                                $order_cache[ $oid ] = wc_get_order( $oid );
                            }
                            $o = $order_cache[ $oid ];
                            $name  = $o ? trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ) : '—';
                            $email = $o ? $o->get_billing_email() : '—';
                            $ci    = $o ? get_post_meta( $oid, '_ss_checked_in', true ) : '';
                            $ci_label = $ci === 'yes'
                                ? '<span style="color:#00a32a;font-weight:600;">✓</span>'
                                : '<span style="color:#999;">—</span>';
                            $order_link = $o
                                ? '<a href="' . esc_url( admin_url( 'post.php?post=' . $oid . '&action=edit' ) ) . '">#' . $oid . '</a>'
                                : '#' . $oid;
                        ?>
                            <tr>
                                <td style="font-weight:600;"><?php echo esc_html( $b->seat_id ); ?></td>
                                <td><?php echo esc_html( $name ); ?></td>
                                <td><?php echo esc_html( $email ); ?></td>
                                <td><?php echo $order_link; ?></td>
                                <td style="text-align:center;"><?php echo $ci_label; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <?php endif; ?>
    </div>
    <?php
}

// Settings page moved to SS_Settings class (includes/admin/class-ss-settings.php)

// ═══════════════════════════════════════════════════════════════════════════════
// CHECK-IN DASHBOARD — Escaneo QR + validación AJAX
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Encola scripts solo en la página del dashboard de check-in.
 */
add_action( 'admin_enqueue_scripts', 'ss_checkin_dashboard_enqueue' );

function ss_checkin_dashboard_enqueue( string $hook ): void {
    if ( strpos( $hook, 'ss-checkin-dashboard' ) === false ) {
        return;
    }

    // html5-qrcode desde CDN
    wp_enqueue_script(
        'html5-qrcode',
        'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js',
        array(),
        '2.3.8',
        true
    );

    wp_enqueue_script(
        'ss-checkin-dashboard',
        plugin_dir_url( __FILE__ ) . 'assets/js/ss-checkin-dashboard.js',
        array( 'html5-qrcode' ),
        filemtime( plugin_dir_path( __FILE__ ) . 'assets/js/ss-checkin-dashboard.js' ),
        true
    );

    wp_localize_script( 'ss-checkin-dashboard', 'ssCheckin', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'ss_checkin_dashboard' ),
    ) );
}

// ═══════════════════════════════════════════════════════════════════
//  ADMIN: Encolar builder Konva en post.php / post-new.php (ss_event)
// ═══════════════════════════════════════════════════════════════════

add_action( 'admin_enqueue_scripts', 'ss_admin_builder_enqueue' );

function ss_admin_builder_enqueue( $hook ) {
    if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
        return;
    }

    $screen = get_current_screen();
    if ( ! $screen || $screen->post_type !== 'ss_event' ) {
        return;
    }

    $plugin_url = plugin_dir_url( __FILE__ );
    $ver        = filemtime( plugin_dir_path( __FILE__ ) . 'assets/js/ss-builder-main.js' ) ?: '1.1.0';

    // Konva
    wp_enqueue_script(
        'konva',
        $plugin_url . 'assets/js/konva.min.js',
        array(),
        '9.3.6',
        true
    );

    // SeatEngine
    wp_enqueue_script(
        'ss-seat-engine',
        $plugin_url . 'assets/js/seat-engine.js',
        array(),
        $ver,
        true
    );

    // Builder main.js (original)
    wp_enqueue_script(
        'ss-builder-main',
        $plugin_url . 'assets/js/ss-builder-main.js',
        array( 'konva', 'ss-seat-engine' ),
        $ver,
        true
    );

    // Adapter: loads saved layout, patches export, auto-save on submit
    wp_enqueue_script(
        'ss-admin-builder-init',
        $plugin_url . 'assets/js/ss-admin-builder-init.js',
        array( 'ss-builder-main' ),
        $ver,
        true
    );

    // Layout editor CSS (flex layout for canvas + sidebar)
    wp_enqueue_style(
        'ss-layout-editor',
        $plugin_url . 'assets/css/ss-event-admin.css',
        array(),
        filemtime( plugin_dir_path( __FILE__ ) . 'assets/css/ss-event-admin.css' )
    );

    // Pass remap context to init script
    global $post;
    $event_id = $post ? $post->ID : 0;
    wp_localize_script( 'ss-admin-builder-init', 'ssBuilderData', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'ss_remap_seats' ),
        'eventId' => $event_id,
        'locked'  => $event_id && ss_event_has_seat_activity( $event_id ) ? true : false,
    ) );
}

// ═══════════════════════════════════════════════════════════════════════════════
// AJAX: ESTADÍSTICAS DE CHECK-IN POR ZONA
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_ss_get_event_checkin_stats',        'ss_ajax_event_checkin_stats' );
add_action( 'wp_ajax_nopriv_ss_get_event_checkin_stats', 'ss_ajax_event_checkin_stats' );

function ss_ajax_event_checkin_stats(): void {
    $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;

    // Verificar nonce (admin o frontend)
    $nonce_valid = false;
    $nonce_raw   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    if ( wp_verify_nonce( $nonce_raw, 'ss_checkin_dashboard' ) ) {
        if ( current_user_can( 'manage_woocommerce' ) ) {
            $nonce_valid = true;
        }
    }
    if ( ! $nonce_valid && $event_id > 0 ) {
        if ( wp_verify_nonce( $nonce_raw, 'ss_control_ingreso_' . $event_id ) ) {
            $nonce_valid = true;
        }
    }
    if ( ! $nonce_valid ) {
        wp_send_json_error( array( 'message' => 'Sesión expirada.' ), 403 );
    }

    if ( ! $event_id ) {
        wp_send_json_error( array( 'message' => 'Evento no especificado.' ) );
    }

    // Capacidad por zona desde inventario central (layout como fuente de verdad)
    $zone_capacity = array();
    $inventory = ss_get_zone_inventory( $event_id );
    if ( ! empty( $inventory ) ) {
        foreach ( $inventory as $zone => $data ) {
            $zone_capacity[ strtoupper( $zone ) ] = $data['total'];
        }
    } else {
        // Fallback: ticket types de _ss_ticket_types cuando no hay layout
        $ticket_types = get_post_meta( $event_id, '_ss_ticket_types', true );
        if ( is_array( $ticket_types ) ) {
            foreach ( $ticket_types as $tt ) {
                $name = isset( $tt['zone'] ) ? strtoupper( trim( $tt['zone'] ) ) : '';
                $qty  = isset( $tt['capacity'] ) ? (int) $tt['capacity'] : 0;
                if ( $name !== '' ) {
                    $zone_capacity[ $name ] = $qty;
                }
            }
        }
    }

    // Ingresados por zona
    $zone_entered = array();
    $total_entered = 0;

    $order_ids = wc_get_orders( array(
        'status' => array( 'processing', 'completed' ),
        'limit'  => -1,
        'return' => 'ids',
    ) );

    foreach ( $order_ids as $oid ) {
        // Solo pedidos con check-in
        $checked = get_post_meta( $oid, '_ss_checked_in', true );
        if ( $checked !== 'yes' ) continue;

        $order = wc_get_order( $oid );
        if ( ! $order ) continue;

        // Verificar que pertenece a este evento
        $order_event = ss_get_event_id_from_order( $order );
        if ( $order_event !== $event_id ) continue;

        // Recorrer items del pedido
        foreach ( $order->get_items() as $item_id => $item ) {
            $zone = strtoupper( trim( (string) wc_get_order_item_meta( $item_id, 'ss_zone', true ) ) );
            if ( $zone === '' ) $zone = 'GENERAL';

            $seats = wc_get_order_item_meta( $item_id, 'ss_seats', true );
            if ( ! empty( $seats ) && is_array( $seats ) ) {
                $qty = count( $seats );
            } else {
                $saved_qty = (int) wc_get_order_item_meta( $item_id, 'ss_ticket_qty', true );
                $qty = $saved_qty > 0 ? $saved_qty : $item->get_quantity();
            }

            if ( ! isset( $zone_entered[ $zone ] ) ) {
                $zone_entered[ $zone ] = 0;
            }
            $zone_entered[ $zone ] += $qty;
            $total_entered += $qty;
        }
    }

    // Construir respuesta
    $zones = array();
    $all_zone_names = array_unique( array_merge( array_keys( $zone_capacity ), array_keys( $zone_entered ) ) );
    foreach ( $all_zone_names as $zname ) {
        $zones[ $zname ] = array(
            'entered'  => isset( $zone_entered[ $zname ] ) ? $zone_entered[ $zname ] : 0,
            'capacity' => isset( $zone_capacity[ $zname ] ) ? $zone_capacity[ $zname ] : 0,
        );
    }

    wp_send_json_success( array(
        'zones'         => $zones,
        'total_entered' => $total_entered,
    ) );
}

/**
 * AJAX handler: valida ticket, marca ingreso, devuelve JSON.
 */
add_action( 'wp_ajax_ss_validate_ticket_ajax',        'ss_validate_ticket_ajax' );
add_action( 'wp_ajax_nopriv_ss_validate_ticket_ajax', 'ss_validate_ticket_ajax' );

function ss_validate_ticket_ajax(): void {
    // ── Rate limiting: máx. validaciones por minuto por IP ──
    $ss_rl_max = (int) get_option( 'ss_checkin_rate_limit', 30 );
    $ss_rl_key = 'ss_rl_' . md5( isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'unknown' );
    $ss_rl_cnt = (int) get_transient( $ss_rl_key );
    if ( $ss_rl_cnt >= $ss_rl_max ) {
        wp_send_json_error( array( 'status' => 'invalid', 'message' => 'Demasiados intentos. Espera un momento.' ), 429 );
    }
    set_transient( $ss_rl_key, $ss_rl_cnt + 1, 60 );

    // Autenticación: admin dashboard nonce OR frontend event nonce
    $event_id_for_nonce = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
    $nonce_valid        = false;

    // 1) Admin dashboard nonce
    if ( wp_verify_nonce( isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '', 'ss_checkin_dashboard' ) ) {
        if ( current_user_can( 'manage_woocommerce' ) ) {
            $nonce_valid = true;
        }
    }

    // 2) Frontend event-specific nonce (no requiere login)
    if ( ! $nonce_valid && $event_id_for_nonce > 0 ) {
        if ( wp_verify_nonce( isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '', 'ss_control_ingreso_' . $event_id_for_nonce ) ) {
            $nonce_valid = true;
        }
    }

    if ( ! $nonce_valid ) {
        wp_send_json_error( array( 'status' => 'invalid', 'message' => 'Sesión expirada. Recarga la página.' ), 403 );
    }

    $token    = isset( $_POST['token'] )    ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
    // Compatibilidad: si envían order_id (QR legacy con URL), también aceptarlo
    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    // QR individual por asiento o ticket: el JS envía is_seat_qr=1 o is_ticket_qr=1
    $is_seat_qr   = ! empty( $_POST['is_seat_qr'] );
    $is_ticket_qr = ! empty( $_POST['is_ticket_qr'] );

    if ( ! $token ) {
        wp_send_json_success( array(
            'status'  => 'invalid',
            'message' => 'Datos incompletos en el QR.',
        ) );
    }

    // ── QR individual por ticket de zona ──────────────────────────
    if ( $is_ticket_qr ) {
        $found = ss_find_order_by_ticket_token( $token );
        if ( empty( $found ) ) {
            wp_send_json_success( array(
                'status'  => 'invalid',
                'message' => 'Ticket no encontrado.',
            ) );
        }

        $order_id  = $found['order_id'];
        $ticket_id = $found['ticket_id'];
        $order     = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_success( array( 'status' => 'invalid', 'message' => 'Pedido no encontrado.' ) );
        }

        $order_status = $order->get_status();
        if ( ! in_array( $order_status, array( 'processing', 'completed' ), true ) ) {
            wp_send_json_success( array( 'status' => 'invalid', 'message' => 'Pedido no pagado (estado: ' . $order_status . ').' ) );
        }

        $buyer      = $order->get_formatted_billing_full_name();
        $event_id   = (int) $order->get_meta( 'ss_event_id' );
        $event_name = $event_id ? get_the_title( $event_id ) : '';

        // Extraer nombre de zona del ticket_id (formato "GENERAL-3")
        $ticket_zone = preg_replace( '/-\d+$/', '', $ticket_id );

        $checkin_stats = ss_get_checkin_counter( $event_id );

        $base = array(
            'order_id'       => $order_id,
            'buyer'          => $buyer,
            'event'          => $event_name,
            'ticket_type'    => 'zone',
            'ticket_qty'     => 1,
            'seats'          => array(),
            'zone'           => $ticket_zone,
            'ticket_id'      => $ticket_id,
            'checkin_count'  => $checkin_stats['checked_in'],
            'total_capacity' => $checkin_stats['total'],
            'is_ticket_qr'   => true,
        );

        $result = ss_checkin_mark_ticket( $order_id, $ticket_id );

        if ( $result === 'already_used' ) {
            $checkins = get_post_meta( $order_id, '_ss_ticket_checkins', true );
            $time     = is_array( $checkins ) && ! empty( $checkins[ $ticket_id ] ) ? $checkins[ $ticket_id ] : '';
            wp_send_json_success( array_merge( $base, array(
                'status'       => 'already_used',
                'message'      => 'Ticket ' . $ticket_id . ' ya fue ingresado.',
                'checkin_time' => $time,
            ) ) );
        }

        $base['checkin_count'] = $checkin_stats['checked_in'] + 1;
        wp_send_json_success( array_merge( $base, array(
            'status'       => 'valid',
            'message'      => 'Ingreso registrado — ticket ' . $ticket_id,
            'checkin_time' => current_time( 'mysql' ),
        ) ) );
    }

    // ── QR individual por asiento ─────────────────────────────────
    if ( $is_seat_qr ) {
        $found = ss_find_order_by_seat_token( $token );
        if ( empty( $found ) ) {
            wp_send_json_success( array(
                'status'  => 'invalid',
                'message' => 'Ticket de asiento no encontrado.',
            ) );
        }

        $order_id = $found['order_id'];
        $seat_id  = $found['seat_id'];
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_success( array( 'status' => 'invalid', 'message' => 'Pedido no encontrado.' ) );
        }

        $order_status = $order->get_status();
        if ( ! in_array( $order_status, array( 'processing', 'completed' ), true ) ) {
            wp_send_json_success( array( 'status' => 'invalid', 'message' => 'Pedido no pagado (estado: ' . $order_status . ').' ) );
        }

        $buyer      = $order->get_formatted_billing_full_name();
        $event_id   = (int) $order->get_meta( 'ss_event_id' );
        $event_name = $event_id ? get_the_title( $event_id ) : '';

        // Buscar zona del asiento
        $seat_zone = '';
        foreach ( $order->get_items() as $item_id => $item ) {
            $item_seats = wc_get_order_item_meta( $item_id, 'ss_seats', true );
            if ( is_array( $item_seats ) && in_array( $seat_id, $item_seats, true ) ) {
                $seat_zone = wc_get_order_item_meta( $item_id, 'ss_zone', true );
                break;
            }
        }

        $checkin_stats = ss_get_checkin_counter( $event_id );

        $base = array(
            'order_id'       => $order_id,
            'buyer'          => $buyer,
            'event'          => $event_name,
            'ticket_type'    => 'seated',
            'ticket_qty'     => 1,
            'seats'          => array( $seat_id ),
            'zone'           => $seat_zone,
            'checkin_count'  => $checkin_stats['checked_in'],
            'total_capacity' => $checkin_stats['total'],
            'is_seat_qr'    => true,
        );

        $result = ss_checkin_mark_seat( $order_id, $seat_id );

        if ( $result === 'already_used' ) {
            $checkins = get_post_meta( $order_id, '_ss_seat_checkins', true );
            $time     = is_array( $checkins ) && ! empty( $checkins[ $seat_id ] ) ? $checkins[ $seat_id ] : '';
            wp_send_json_success( array_merge( $base, array(
                'status'       => 'already_used',
                'message'      => 'Asiento ' . $seat_id . ' ya fue ingresado.',
                'checkin_time' => $time,
            ) ) );
        }

        $base['checkin_count'] = $checkin_stats['checked_in'] + 1;
        wp_send_json_success( array_merge( $base, array(
            'status'       => 'valid',
            'message'      => 'Ingreso registrado — asiento ' . $seat_id,
            'checkin_time' => current_time( 'mysql' ),
        ) ) );
    }

    // ── Buscar pedido por token (nuevo) o por order_id (legacy) ─────
    if ( ! $order_id ) {
        $order_id = ss_find_order_by_token( $token );
    }

    if ( ! $order_id ) {
        wp_send_json_success( array(
            'status'  => 'invalid',
            'message' => 'Ticket no encontrado.',
        ) );
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        wp_send_json_success( array(
            'status'  => 'invalid',
            'message' => 'Pedido #' . $order_id . ' no encontrado.',
        ) );
    }

    // ── Verificar token del QR ──────────────────────────────────────
    $saved_token = get_post_meta( $order_id, '_ss_checkin_token', true );
    if ( ! $saved_token || ! hash_equals( $saved_token, $token ) ) {
        wp_send_json_success( array(
            'status'  => 'invalid',
            'message' => 'Código de verificación inválido.',
        ) );
    }

    // ── Verificar estado del pedido ─────────────────────────────────
    $order_status = $order->get_status();
    if ( ! in_array( $order_status, array( 'processing', 'completed' ), true ) ) {
        wp_send_json_success( array(
            'status'  => 'invalid',
            'message' => 'Pedido no pagado (estado: ' . $order_status . ').',
        ) );
    }

    // ── Recopilar datos ─────────────────────────────────────────────
    $buyer = $order->get_formatted_billing_full_name();

    $seats_list    = array();
    $zones_list    = array();
    $tickets_info  = array(); // [ "VIP x 2", "GENERAL x 1" ]
    $event_name    = '';
    $event_id      = 0;
    $ticket_qty    = 0;

    foreach ( $order->get_items() as $item_id => $item ) {
        // Sillas — usar wc_get_order_item_meta para lectura fiable
        $seats = wc_get_order_item_meta( $item_id, 'ss_seats', true );

        if ( ! empty( $seats ) && is_array( $seats ) ) {
            $seats_list = array_merge( $seats_list, $seats );
            $ticket_qty += count( $seats );
        } else {
            // Leer qty real desde nuestro meta (MPWEM siempre reporta qty=1)
            $saved_qty = (int) wc_get_order_item_meta( $item_id, 'ss_ticket_qty', true );
            $ticket_qty += $saved_qty > 0 ? $saved_qty : $item->get_quantity();
        }

        // Zona directa desde ss_zone (fuente principal)
        $item_zone = wc_get_order_item_meta( $item_id, 'ss_zone', true );
        if ( $item_zone && is_string( $item_zone ) ) {
            $zones_list[] = $item_zone;
        }
        // Fallback: zonas desde ss_seat_data
        if ( ! $item_zone ) {
            $sd = wc_get_order_item_meta( $item_id, 'ss_seat_data', true );
            if ( is_array( $sd ) ) {
                foreach ( $sd as $entry ) {
                    if ( ! empty( $entry['zone'] ) ) {
                        $zones_list[] = $entry['zone'];
                    }
                }
            }
        }
        // Event ID
        $item_event = (int) wc_get_order_item_meta( $item_id, 'ss_event_id', true );
        if ( $item_event > 0 ) {
            $event_id = $item_event;
        }
        // Evento + tickets info — usar ss_ticket_qty para qty real
        if ( ! $event_name ) {
            $product = $item->get_product();
            if ( $product ) {
                $event_name = $product->get_name();
            }
        }
        $real_qty = (int) wc_get_order_item_meta( $item_id, 'ss_ticket_qty', true );
        $qty  = $real_qty > 0 ? $real_qty : $item->get_quantity();
        $name = $item->get_name();
        if ( $qty > 0 && $name ) {
            $tickets_info[] = $name . ' x ' . $qty;
        }
    }
    // Fallback event_id
    if ( ! $event_id ) {
        $event_id = ss_get_event_id_from_order( $order );
    }
    // Fallback zonas desde order-level meta
    if ( empty( $zones_list ) ) {
        $sd = $order->get_meta( 'ss_seat_data' );
        if ( is_array( $sd ) ) {
            foreach ( $sd as $entry ) {
                if ( ! empty( $entry['zone'] ) ) {
                    $zones_list[] = $entry['zone'];
                }
            }
        }
    }
    $zone = implode( ', ', array_unique( $zones_list ) );

    // Determinar ticket_type
    $ticket_type = ! empty( $seats_list ) ? 'seated' : 'general';

    // Checkin stats del evento
    $checkin_stats = ss_get_checkin_counter( $event_id );

    // ── Base response ───────────────────────────────────────────────
    $base_response = array(
        'order_id'       => $order_id,
        'buyer'          => $buyer,
        'event'          => $event_name,
        'ticket_type'    => $ticket_type,
        'ticket_qty'     => $ticket_qty,
        'seats'          => ! empty( $seats_list ) ? $seats_list : null,
        'zone'           => $zone,
        'tickets'        => $tickets_info,
        'checkin_count'  => $checkin_stats['checked_in'],
        'total_capacity' => $checkin_stats['total'],
    );

    // ── Check-in ya registrado? ─────────────────────────────────────
    $already_checked = get_post_meta( $order_id, '_ss_checked_in', true );

    if ( $already_checked === 'yes' ) {
        $checkin_time = get_post_meta( $order_id, '_ss_checked_in_time', true );
        wp_send_json_success( array_merge( $base_response, array(
            'status'       => 'already_used',
            'message'      => 'Este ticket ya fue usado.',
            'checkin_time' => $checkin_time,
        ) ) );
    }

    // ── Marcar ingreso ──────────────────────────────────────────────
    update_post_meta( $order_id, '_ss_checked_in', 'yes' );
    update_post_meta( $order_id, '_ss_checked_in_time', current_time( 'mysql' ) );
    $order->add_order_note( __( 'Ingreso registrado vía control de ingreso.', 'ss-seating' ) );

    // Actualizar counter (el que acabamos de marcar)
    $base_response['checkin_count'] = $checkin_stats['checked_in'] + 1;

    wp_send_json_success( array_merge( $base_response, array(
        'status'       => 'valid',
        'message'      => 'Ingreso registrado.',
        'checkin_time' => current_time( 'mysql' ),
    ) ) );
}

/**
 * Renderiza la página del dashboard de check-in.
 */
function ss_checkin_dashboard_page(): void {
    ?>
    <div class="wrap" id="ss-checkin-app">
        <h1><?php esc_html_e( 'Control de Ingreso', 'ss-seating' ); ?></h1>
        <p class="description"><?php esc_html_e( 'Escanea el código QR del ticket para registrar el ingreso.', 'ss-seating' ); ?></p>

        <div id="ss-checkin-layout" style="display:flex; gap:32px; margin-top:20px; flex-wrap:wrap;">

            <!-- Cámara -->
            <div id="ss-checkin-camera-wrap" style="flex:0 0 340px; max-width:100%;">
                <div id="ss-qr-reader" style="width:340px; max-width:100%;"></div>
                <p id="ss-checkin-camera-status" style="margin-top:8px; font-style:italic; color:#666;">
                    <?php esc_html_e( 'Iniciando cámara...', 'ss-seating' ); ?>
                </p>
            </div>

            <!-- Resultado -->
            <div id="ss-checkin-result" style="flex:1; min-width:280px;">
                <div id="ss-checkin-feedback" style="
                    padding:24px;
                    border-radius:12px;
                    background:#f0f0f0;
                    border:2px solid #ddd;
                    min-height:200px;
                    display:flex;
                    flex-direction:column;
                    align-items:center;
                    justify-content:center;
                    text-align:center;
                    transition: all 0.3s ease;
                ">
                    <span id="ss-checkin-icon" style="font-size:64px; line-height:1;">&#128247;</span>
                    <p id="ss-checkin-message" style="font-size:18px; font-weight:600; margin:12px 0 0;">
                        <?php esc_html_e( 'Esperando escaneo...', 'ss-seating' ); ?>
                    </p>
                    <div id="ss-checkin-details" style="display:none; margin-top:16px; font-size:14px; width:100%;"></div>
                </div>

                <!-- Historial -->
                <h3 style="margin-top:24px;"><?php esc_html_e( 'Últimos escaneos', 'ss-seating' ); ?></h3>
                <table id="ss-checkin-history" class="widefat striped" style="max-width:700px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Hora', 'ss-seating' ); ?></th>
                            <th><?php esc_html_e( 'Pedido', 'ss-seating' ); ?></th>
                            <th><?php esc_html_e( 'Comprador', 'ss-seating' ); ?></th>
                            <th><?php esc_html_e( 'Tipo', 'ss-seating' ); ?></th>
                            <th><?php esc_html_e( 'Detalle', 'ss-seating' ); ?></th>
                            <th><?php esc_html_e( 'Estado', 'ss-seating' ); ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// SISTEMA DE LEADS — Recopilación de compradores
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'woocommerce_order_status_completed', 'ss_leads_capture_from_order', 20, 1 );

function ss_leads_capture_from_order( int $order_id ): void {
    global $wpdb;
    $table = $wpdb->prefix . 'ss_event_leads';

    // Verificar que la tabla existe
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
        ss_leads_create_table();
    }

    // Evitar duplicados: si ya hay leads para este order_id, no reinsertar
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE order_id = %d", $order_id
    ) );
    if ( $exists > 0 ) {
        return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    $buyer_name  = $order->get_formatted_billing_full_name();
    $buyer_email = $order->get_billing_email();
    $buyer_phone = $order->get_billing_phone();
    $purchase_date = $order->get_date_created()
        ? $order->get_date_created()->date( 'Y-m-d H:i:s' )
        : current_time( 'mysql' );

    foreach ( $order->get_items() as $item_id => $item ) {
        // Determinar event_id
        $event_id = (int) wc_get_order_item_meta( $item_id, 'ss_event_id', true );
        if ( ! $event_id ) {
            $event_id = (int) ss_get_event_id_from_order( $order );
        }

        $event_name = '';
        if ( $event_id > 0 ) {
            $event_name = get_the_title( $event_id ) ?: '';
        }
        if ( ! $event_name ) {
            $product = $item->get_product();
            $event_name = $product ? $product->get_name() : '';
        }

        $zone = strtoupper( trim( (string) wc_get_order_item_meta( $item_id, 'ss_zone', true ) ) );

        // Leer asientos
        $seats = wc_get_order_item_meta( $item_id, 'ss_seats', true );

        if ( ! empty( $seats ) && is_array( $seats ) ) {
            // Un registro por asiento
            foreach ( $seats as $seat ) {
                $wpdb->insert( $table, array(
                    'order_id'      => $order_id,
                    'event_id'      => $event_id,
                    'event_name'    => $event_name,
                    'buyer_name'    => $buyer_name,
                    'buyer_email'   => $buyer_email,
                    'buyer_phone'   => $buyer_phone,
                    'zone'          => $zone,
                    'seat'          => sanitize_text_field( $seat ),
                    'ticket_qty'    => 1,
                    'purchase_date' => $purchase_date,
                ), array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ) );
            }
        } else {
            // Ticket sin asiento: un registro con la qty
            $saved_qty = (int) wc_get_order_item_meta( $item_id, 'ss_ticket_qty', true );
            $qty = $saved_qty > 0 ? $saved_qty : $item->get_quantity();
            if ( $qty > 0 ) {
                $wpdb->insert( $table, array(
                    'order_id'      => $order_id,
                    'event_id'      => $event_id,
                    'event_name'    => $event_name,
                    'buyer_name'    => $buyer_name,
                    'buyer_email'   => $buyer_email,
                    'buyer_phone'   => $buyer_phone,
                    'zone'          => $zone,
                    'seat'          => null,
                    'ticket_qty'    => $qty,
                    'purchase_date' => $purchase_date,
                ), array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', null, '%d', '%s' ) );
            }
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ADMIN: PÁGINA SS LEADS
// ═══════════════════════════════════════════════════════════════════════════════

// ── Copiar mapa de asientos entre eventos ─────────────────────────────────────

add_action( 'wp_ajax_ss_copy_layout', 'ss_ajax_copy_layout' );

function ss_ajax_copy_layout(): void {
    check_ajax_referer( 'ss_copy_layout', 'nonce' );
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( 'Sin permisos.' );
    }

    $source_id = absint( $_POST['source_id'] ?? 0 );
    $dest_id   = absint( $_POST['dest_id']   ?? 0 );

    if ( ! $source_id || ! $dest_id || $source_id === $dest_id ) {
        wp_send_json_error( 'IDs inválidos.' );
    }
    if ( ss_event_has_seat_activity( $dest_id ) ) {
        wp_send_json_error( 'Este evento ya tiene ventas — no se puede reemplazar el mapa.' );
    }

    $layout = get_post_meta( $source_id, '_ss_layout', true );
    if ( empty( $layout ) ) {
        wp_send_json_error( 'El evento origen no tiene mapa guardado.' );
    }

    update_post_meta( $dest_id, '_ss_layout',         $layout );
    update_post_meta( $dest_id, '_ss_sale_mode',      get_post_meta( $source_id, '_ss_sale_mode',      true ) );
    update_post_meta( $dest_id, 'ss_rows',            get_post_meta( $source_id, 'ss_rows',            true ) );
    update_post_meta( $dest_id, 'ss_columns',         get_post_meta( $source_id, 'ss_columns',         true ) );
    update_post_meta( $dest_id, '_ss_seating_config', get_post_meta( $source_id, '_ss_seating_config', true ) );

    // Sincronizar zonas → ticket types con las capacidades del nuevo mapa
    if ( function_exists( 'ss_sync_zones_to_ticket_types' ) ) {
        ss_sync_zones_to_ticket_types( $dest_id );
    }

    wp_send_json_success( array( 'message' => 'Mapa importado correctamente.' ) );
}

// ── Exportar compradores desde la pantalla del evento ─────────────────────────

add_action( 'wp_ajax_ss_export_event_leads', 'ss_export_event_leads' );

function ss_export_event_leads(): void {
    $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
    if ( ! $event_id ) {
        wp_die( 'Evento no especificado.' );
    }
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( 'Sin permisos.' );
    }
    check_admin_referer( 'ss_export_event_leads_' . $event_id );

    global $wpdb;
    $table = $wpdb->prefix . 'ss_event_leads';

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE event_id = %d ORDER BY purchase_date DESC",
        $event_id
    ), ARRAY_A );

    $filename = 'evento-' . $event_id . '-leads.csv';

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=' . $filename );

    $fp = fopen( 'php://output', 'w' );
    fprintf( $fp, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

    fputcsv( $fp, array( 'Nombre', 'Email', 'Teléfono', 'Evento', 'Zona', 'Asiento', 'Cantidad', 'Fecha', 'Pedido' ) );

    foreach ( $rows as $row ) {
        fputcsv( $fp, array(
            $row['buyer_name'],
            $row['buyer_email'],
            $row['buyer_phone'],
            $row['event_name'],
            $row['zone'] ?: '—',
            $row['seat'] ?: '—',
            $row['ticket_qty'],
            $row['purchase_date'],
            '#' . $row['order_id'],
        ) );
    }

    fclose( $fp );
    exit;
}

// Leads menu registrado en ss_seating_admin_menu() (menú unificado SS Seating).

/**
 * Exportar CSV: se ejecuta antes de cualquier output.
 */
add_action( 'admin_init', 'ss_leads_handle_csv_export' );

function ss_leads_handle_csv_export(): void {
    if ( ! isset( $_GET['page'], $_GET['ss_export_csv'] ) ) {
        return;
    }
    if ( $_GET['page'] !== 'ss-leads' || $_GET['ss_export_csv'] !== '1' ) {
        return;
    }
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( 'Sin permisos.' );
    }
    check_admin_referer( 'ss_leads_export' );

    global $wpdb;
    $table = $wpdb->prefix . 'ss_event_leads';

    // Filtro por evento
    $event_id = isset( $_GET['filter_event'] ) ? absint( $_GET['filter_event'] ) : 0;
    $where = '';
    if ( $event_id > 0 ) {
        $where = $wpdb->prepare( ' WHERE event_id = %d', $event_id );
    }

    $rows = $wpdb->get_results(
        "SELECT * FROM {$table}{$where} ORDER BY purchase_date DESC",
        ARRAY_A
    );

    $filename = 'ss-leads-' . date( 'Y-m-d-His' ) . '.csv';

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=' . $filename );

    $fp = fopen( 'php://output', 'w' );
    // BOM para Excel
    fprintf( $fp, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

    // Headers
    fputcsv( $fp, array( 'ID', 'Pedido', 'Evento ID', 'Evento', 'Nombre', 'Email', 'Teléfono', 'Zona', 'Asiento', 'Cantidad', 'Fecha compra' ) );

    foreach ( $rows as $row ) {
        fputcsv( $fp, array(
            $row['id'],
            $row['order_id'],
            $row['event_id'],
            $row['event_name'],
            $row['buyer_name'],
            $row['buyer_email'],
            $row['buyer_phone'],
            $row['zone'],
            $row['seat'] ?: '—',
            $row['ticket_qty'],
            $row['purchase_date'],
        ) );
    }

    fclose( $fp );
    exit;
}

/**
 * Página de administración de leads.
 */
function ss_leads_admin_page(): void {
    global $wpdb;
    $table = $wpdb->prefix . 'ss_event_leads';

    // Verificar tabla
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
        ss_leads_create_table();
    }

    // Filtro por evento
    $filter_event = isset( $_GET['filter_event'] ) ? absint( $_GET['filter_event'] ) : 0;

    // Paginación
    $per_page    = 50;
    $current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
    $offset       = ( $current_page - 1 ) * $per_page;

    $where = '';
    if ( $filter_event > 0 ) {
        $where = $wpdb->prepare( ' WHERE event_id = %d', $filter_event );
    }

    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}{$where}" );
    $rows  = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table}{$where} ORDER BY purchase_date DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ),
        ARRAY_A
    );
    $total_pages = ceil( $total / $per_page );

    // Lista de eventos para el filtro
    $events = $wpdb->get_results(
        "SELECT DISTINCT event_id, event_name FROM {$table} ORDER BY event_name ASC",
        ARRAY_A
    );

    // URL de exportación CSV
    $export_url = wp_nonce_url(
        add_query_arg( array(
            'page'          => 'ss-leads',
            'ss_export_csv' => '1',
            'filter_event'  => $filter_event,
        ), admin_url( 'admin.php' ) ),
        'ss_leads_export'
    );

    ?>
    <div class="wrap">
        <h1 style="display:flex; align-items:center; gap:12px;">
            <?php esc_html_e( 'SS Leads — Compradores', 'ss-seating' ); ?>
            <a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary" style="margin-left:auto;">
                <?php esc_html_e( 'Exportar CSV', 'ss-seating' ); ?>
            </a>
        </h1>

        <p class="description"><?php printf( __( '%s registros en total.', 'ss-seating' ), '<strong>' . number_format_i18n( $total ) . '</strong>' ); ?></p>

        <!-- Filtro por evento -->
        <form method="get" style="margin:12px 0;">
            <input type="hidden" name="page" value="ss-leads">
            <select name="filter_event" onchange="this.form.submit();">
                <option value="0"><?php esc_html_e( 'Todos los eventos', 'ss-seating' ); ?></option>
                <?php foreach ( $events as $ev ) : ?>
                    <option value="<?php echo esc_attr( $ev['event_id'] ); ?>"
                        <?php selected( $filter_event, (int) $ev['event_id'] ); ?>>
                        <?php echo esc_html( $ev['event_name'] ?: 'Evento #' . $ev['event_id'] ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <table class="widefat striped" style="max-width:1200px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Nombre', 'ss-seating' ); ?></th>
                    <th><?php esc_html_e( 'Email', 'ss-seating' ); ?></th>
                    <th><?php esc_html_e( 'Teléfono', 'ss-seating' ); ?></th>
                    <th><?php esc_html_e( 'Evento', 'ss-seating' ); ?></th>
                    <th><?php esc_html_e( 'Zona', 'ss-seating' ); ?></th>
                    <th><?php esc_html_e( 'Asiento', 'ss-seating' ); ?></th>
                    <th><?php esc_html_e( 'Cantidad', 'ss-seating' ); ?></th>
                    <th><?php esc_html_e( 'Fecha', 'ss-seating' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="8" style="text-align:center; padding:24px; color:#999;">
                        <?php esc_html_e( 'No hay leads registrados aún.', 'ss-seating' ); ?>
                    </td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $row['buyer_name'] ); ?></strong></td>
                            <td><a href="mailto:<?php echo esc_attr( $row['buyer_email'] ); ?>"><?php echo esc_html( $row['buyer_email'] ); ?></a></td>
                            <td><?php echo esc_html( $row['buyer_phone'] ); ?></td>
                            <td><?php echo esc_html( $row['event_name'] ?: 'Evento #' . $row['event_id'] ); ?></td>
                            <td><?php echo esc_html( $row['zone'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $row['seat'] ?: '—' ); ?></td>
                            <td><?php echo (int) $row['ticket_qty']; ?></td>
                            <td><?php echo esc_html( date_i18n( 'Y-m-d H:i', strtotime( $row['purchase_date'] ) ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav" style="margin-top:12px;">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links( array(
                        'base'      => add_query_arg( 'paged', '%#%' ),
                        'format'    => '',
                        'current'   => $current_page,
                        'total'     => $total_pages,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    ) );
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// BOX OFFICE — Módulo de reservas y ventas manuales
// ═══════════════════════════════════════════════════════════════════════════════

// ── Tabla de log ─────────────────────────────────────────────────────────────

function ss_boxoffice_create_log_table(): void {
    global $wpdb;
    $table   = $wpdb->prefix . 'ss_boxoffice_log';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
        usuario    VARCHAR(100)    NOT NULL DEFAULT '',
        accion     VARCHAR(50)     NOT NULL DEFAULT '',
        asientos   TEXT            NOT NULL,
        order_id   BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_event_id (event_id),
        KEY idx_created_at (created_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

function ss_boxoffice_log( int $event_id, string $usuario, string $accion, array $asientos, ?int $order_id = null ): void {
    global $wpdb;
    $table = $wpdb->prefix . 'ss_boxoffice_log';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
        ss_boxoffice_create_log_table();
    }
    $wpdb->insert( $table, array(
        'event_id'   => $event_id,
        'usuario'    => sanitize_text_field( $usuario ),
        'accion'     => sanitize_text_field( $accion ),
        'asientos'   => implode( ', ', array_map( 'sanitize_text_field', $asientos ) ),
        'order_id'   => $order_id,
        'created_at' => current_time( 'mysql' ),
    ), array( '%d', '%s', '%s', '%s', '%d', '%s' ) );
}

// ── Reservas manuales (estado reservado_manual) ──────────────────────────────

function ss_seats_manual_reserve( int $event_id, array $seats, string $user, string $nombre = '', string $telefono = '' ): void {
    global $wpdb;
    $manual = get_post_meta( $event_id, '_ss_manual_reserved_seats', true );
    if ( ! is_array( $manual ) ) { $manual = array(); }
    $now = time();
    foreach ( $seats as $seat ) {
        $manual[ (string) $seat ] = array( 'user' => $user, 'nombre' => $nombre, 'telefono' => $telefono, 'timestamp' => $now );
    }
    update_post_meta( $event_id, '_ss_manual_reserved_seats', $manual );

    // Sincronizar al ledger para que el mapa del frontend refleje la reserva
    $table = $wpdb->prefix . 'ss_seat_ledger';
    foreach ( $seats as $seat ) {
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table} (event_id, seat_id, order_id, status, session_id, created_at, expires_at)
             VALUES (%d, %s, 0, 'manual_reserved', %s, NOW(), NULL)
             ON DUPLICATE KEY UPDATE status = 'manual_reserved', session_id = %s, expires_at = NULL",
            $event_id, $seat, $user, $user
        ) );
    }
}

function ss_seats_manual_release( int $event_id, array $seats ): void {
    global $wpdb;
    $manual = get_post_meta( $event_id, '_ss_manual_reserved_seats', true );
    if ( ! is_array( $manual ) ) { return; }
    foreach ( $seats as $seat ) {
        unset( $manual[ (string) $seat ] );
    }
    update_post_meta( $event_id, '_ss_manual_reserved_seats', $manual );

    // Borrar del ledger al liberar
    if ( ! empty( $seats ) ) {
        $table        = $wpdb->prefix . 'ss_seat_ledger';
        $placeholders = implode( ',', array_fill( 0, count( $seats ), '%s' ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE event_id = %d AND seat_id IN ({$placeholders}) AND status = 'manual_reserved'",
            array_merge( array( $event_id ), $seats )
        ) );
    }
}

function ss_seats_get_manual_reserved( int $event_id ): array {
    $manual = get_post_meta( $event_id, '_ss_manual_reserved_seats', true );
    if ( ! is_array( $manual ) ) { return array(); }
    return array_keys( $manual );
}

// ── Autenticación Box Office (usuarios en wp_options) ────────────────────────

function ss_boxoffice_get_users(): array {
    $users = get_option( 'ss_boxoffice_users', array() );
    if ( ! is_array( $users ) || empty( $users ) ) {
        // Crear usuarios default con contraseñas aleatorias
        $default_names = array( 'Julian', 'Marcos', 'Inti' );
        $users = array();
        foreach ( $default_names as $name ) {
            $users[] = array( 'name' => $name, 'pass' => wp_hash_password( wp_generate_password( 12 ) ) );
        }
        update_option( 'ss_boxoffice_users', $users );
    }
    return $users;
}

function ss_boxoffice_check_auth(): ?string {
    // Autenticación exclusivamente vía sesión PHP (establecida en login)
    if ( session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
        session_start();
    }
    if ( ! empty( $_SESSION['ss_bo_user'] ) ) {
        // Verificar que el usuario aún existe en la lista registrada
        $users = ss_boxoffice_get_users();
        foreach ( $users as $u ) {
            if ( $u['name'] === $_SESSION['ss_bo_user'] ) {
                return $u['name'];
            }
        }
        // Usuario fue eliminado de la configuración — cerrar sesión
        unset( $_SESSION['ss_bo_user'] );
    }

    return null;
}

function ss_boxoffice_login( string $user, string $pass ): bool {
    $users = ss_boxoffice_get_users();
    foreach ( $users as $u ) {
        if ( strtolower( trim( $u['name'] ) ) !== strtolower( trim( $user ) ) ) {
            continue;
        }
        // Intentar wp_check_password primero (soporta $P$, $2y$, y futuros formatos)
        $valid = false;
        if ( $u['pass'] !== '' && wp_check_password( $pass, $u['pass'] ) ) {
            $valid = true;
        } elseif ( $u['pass'] === $pass ) {
            // Legacy: texto plano — validar y migrar a hash
            $valid = true;
            if ( $pass !== '' ) {
                ss_boxoffice_rehash_user( $u['name'], $pass );
            }
        }
        if ( $valid ) {
            if ( session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
                session_start();
            }
            session_regenerate_id( true );
            $_SESSION['ss_bo_user']            = $u['name'];
            $_SESSION['ss_bo_last_activity']   = time();
            return true;
        }
    }
    return false;
}

/**
 * Migra contraseña de texto plano a hash para un usuario Box Office.
 */
function ss_boxoffice_rehash_user( string $name, string $plain_pass ): void {
    $users = get_option( 'ss_boxoffice_users', array() );
    foreach ( $users as &$u ) {
        if ( $u['name'] === $name ) {
            $u['pass'] = wp_hash_password( $plain_pass );
            break;
        }
    }
    update_option( 'ss_boxoffice_users', $users );
}

// ── Página admin para gestionar usuarios Box Office ──────────────────────────

// ── Página de colores del mapa ────────────────────────────────────────────────

function ss_seating_colors_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) { return; }

    $defaults = array(
        'sold'     => '#ef5350',
        'reserved' => '#fff3cd',
        'manual'   => '#90caf9',
        'selected' => '#e94560',
    );

    if ( isset( $_POST['ss_colors_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ss_colors_nonce'] ) ), 'ss_save_colors' ) ) {
        foreach ( $defaults as $key => $default ) {
            $val = sanitize_hex_color( wp_unslash( $_POST[ 'ss_color_' . $key ] ?? $default ) );
            update_option( 'ss_color_' . $key, $val ?: $default );
        }
        echo '<div class="notice notice-success"><p>Colores guardados.</p></div>';
    }

    $colors = array();
    foreach ( $defaults as $key => $default ) {
        $colors[ $key ] = get_option( 'ss_color_' . $key, $default );
    }

    $labels = array(
        'sold'     => 'Vendido',
        'reserved' => 'En carrito / temp.',
        'manual'   => 'Reservado (manual)',
        'selected' => 'Seleccionado',
    );
    ?>
    <div class="wrap">
        <h1>Colores del mapa de sillas</h1>
        <p>Estos colores se aplican al mapa del Box Office y a la leyenda. Los colores de zona (asientos disponibles) se configuran en cada evento.</p>
        <form method="post">
            <?php wp_nonce_field( 'ss_save_colors', 'ss_colors_nonce' ); ?>
            <table class="form-table">
                <?php foreach ( $labels as $key => $label ) : ?>
                <tr>
                    <th><?php echo esc_html( $label ); ?></th>
                    <td>
                        <input type="color" name="ss_color_<?php echo esc_attr( $key ); ?>"
                               value="<?php echo esc_attr( $colors[ $key ] ); ?>">
                        <span style="margin-left:8px;font-family:monospace"><?php echo esc_html( $colors[ $key ] ); ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php submit_button( 'Guardar colores' ); ?>
        </form>
    </div>
    <?php
}

// ── Informe financiero Box Office ─────────────────────────────────────────────

add_action( 'admin_init', 'ss_bo_report_csv_export' );
function ss_bo_report_csv_export(): void {
    if ( ! isset( $_GET['page'], $_GET['ss_bo_export_csv'] ) ) { return; }
    if ( $_GET['page'] !== 'ss-cierre-contable' ) { return; }
    if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }

    global $wpdb;
    $log_table = $wpdb->prefix . 'ss_boxoffice_log';

    $event_id  = isset( $_GET['ss_event_id'] ) ? absint( $_GET['ss_event_id'] ) : 0;
    $date_from = isset( $_GET['ss_from'] ) ? sanitize_text_field( wp_unslash( $_GET['ss_from'] ) ) : '';
    $date_to   = isset( $_GET['ss_to'] )   ? sanitize_text_field( wp_unslash( $_GET['ss_to'] ) )   : '';

    $where  = array( "l.accion LIKE 'vender%%'" );
    $params = array();
    if ( $event_id )  { $where[] = 'l.event_id = %d';          $params[] = $event_id; }
    if ( $date_from ) { $where[] = 'DATE(l.created_at) >= %s'; $params[] = $date_from; }
    if ( $date_to )   { $where[] = 'DATE(l.created_at) <= %s'; $params[] = $date_to; }

    $where_sql = implode( ' AND ', $where );
    $sql       = "SELECT * FROM {$log_table} l WHERE {$where_sql} ORDER BY l.created_at DESC";
    $log_rows  = $params
        ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A )
        : $wpdb->get_results( $sql, ARRAY_A );

    $order_ids = array_filter( array_unique( array_column( $log_rows ?? array(), 'order_id' ) ) );
    $orders    = array();
    foreach ( $order_ids as $oid ) {
        $o = wc_get_order( (int) $oid );
        if ( $o ) { $orders[ (int) $oid ] = $o; }
    }

    $filename = 'informe-bo-' . gmdate( 'Y-m-d' ) . '.csv';
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Pragma: no-cache' );

    // Ventas Web para el CSV (solo si hay evento filtrado)
    $web_orders_csv = array();
    if ( $event_id ) {
        $web_ids = wc_get_orders( array(
            'meta_query' => array(
                array( 'key' => 'ss_event_id', 'value' => $event_id, 'compare' => '=', 'type' => 'NUMERIC' ),
            ),
            'status'     => array( 'wc-processing', 'wc-completed' ),
            'limit'      => -1,
            'return'     => 'ids',
        ) );
        foreach ( $web_ids as $woid ) {
            $wo = wc_get_order( (int) $woid );
            if ( ! $wo || $wo->get_meta( '_ss_boxoffice_sale' ) === 'yes' ) { continue; }
            $w_seats  = (array) $wo->get_meta( 'ss_seats' );
            $w_tkt    = $wo->get_meta( 'ss_ticket_qtys' );
            $w_ct     = count( array_filter( $w_seats ) );
            if ( is_array( $w_tkt ) ) { $w_ct += (int) array_sum( $w_tkt ); }
            $web_orders_csv[] = array(
                'oid'    => (int) $woid,
                'nombre' => trim( $wo->get_billing_first_name() . ' ' . $wo->get_billing_last_name() ),
                'asientos'=> implode( ', ', array_filter( $w_seats ) ),
                'ct'     => $w_ct,
                'valor'  => (float) $wo->get_total(),
                'metodo' => $wo->get_payment_method_title(),
                'nota'   => '',
                'cajero' => 'Web',
                'evento' => get_the_title( $event_id ),
                'fecha'  => $wo->get_date_created() ? $wo->get_date_created()->format( 'Y-m-d H:i:s' ) : '',
            );
        }
    }

    $out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
    // BOM UTF-8 para que Excel lo abra correctamente
    fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
    fputcsv( $out, array( 'Pedido', 'Persona', 'Asientos', 'CT', 'Valor cobrado', 'Método pago', 'Nota', 'Cajero', 'Evento', 'Fecha', 'Fuente' ) );

    foreach ( $log_rows as $r ) {
        $oid   = (int) $r['order_id'];
        $order = $orders[ $oid ] ?? null;

        $asientos      = array_filter( array_map( 'trim', explode( ',', $r['asientos'] ) ) );
        $ct            = count( $asientos );
        $nombre        = '';
        $valor_cobrado = 0;
        $metodo_pago   = '';
        $nota          = '';
        $evento        = get_the_title( (int) $r['event_id'] );

        if ( $order ) {
            $nombre        = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
            $valor_cobrado = (int) $order->get_meta( '_ss_valor_cobrado' );
            $metodo_pago   = $order->get_payment_method_title();
            $nota          = (string) $order->get_meta( '_ss_nota_bo' );
        }

        fputcsv( $out, array(
            $oid ?: '',
            $nombre,
            implode( ', ', $asientos ),
            $ct,
            $valor_cobrado ?: '',
            $metodo_pago,
            $nota,
            $r['usuario'],
            $evento,
            $r['created_at'],
            'BO',
        ) );
    }

    foreach ( $web_orders_csv as $wr ) {
        fputcsv( $out, array(
            $wr['oid'],
            $wr['nombre'],
            $wr['asientos'],
            $wr['ct'],
            $wr['valor'],
            $wr['metodo'],
            $wr['nota'],
            $wr['cajero'],
            $wr['evento'],
            $wr['fecha'],
            'Web',
        ) );
    }

    fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
    exit;
}

function ss_cierre_contable_page(): void {
    if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }

    global $wpdb;
    $log_table = $wpdb->prefix . 'ss_boxoffice_log';

    $event_id  = isset( $_GET['ss_event_id'] ) ? absint( $_GET['ss_event_id'] ) : 0;
    $date_from = isset( $_GET['ss_from'] ) ? sanitize_text_field( wp_unslash( $_GET['ss_from'] ) ) : '';
    $date_to   = isset( $_GET['ss_to'] )   ? sanitize_text_field( wp_unslash( $_GET['ss_to'] ) )   : '';

    $events = get_posts( array(
        'post_type'      => 'ss_event',
        'post_status'    => array( 'publish', 'private' ),
        'posts_per_page' => 300,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'fields'         => 'ids',
    ) );

    $where  = array( "l.accion LIKE 'vender%%'" );
    $params = array();
    if ( $event_id )  { $where[] = 'l.event_id = %d';          $params[] = $event_id; }
    if ( $date_from ) { $where[] = 'DATE(l.created_at) >= %s'; $params[] = $date_from; }
    if ( $date_to )   { $where[] = 'DATE(l.created_at) <= %s'; $params[] = $date_to; }

    $where_sql = implode( ' AND ', $where );
    $sql       = "SELECT * FROM {$log_table} l WHERE {$where_sql} ORDER BY l.created_at DESC";
    $log_rows  = $params
        ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A )
        : $wpdb->get_results( $sql, ARRAY_A );

    $order_ids = array_filter( array_unique( array_column( $log_rows ?? array(), 'order_id' ) ) );
    $orders    = array();
    foreach ( $order_ids as $oid ) {
        $o = wc_get_order( (int) $oid );
        if ( $o ) { $orders[ (int) $oid ] = $o; }
    }

    $rows           = array();
    $total_ct       = 0;
    $total_valor    = 0;
    $totales_metodo = array( 'efectivo' => 0, 'nequi' => 0, 'transferencia' => 0, 'cortesia' => 0 );

    foreach ( $log_rows as $r ) {
        $oid   = (int) $r['order_id'];
        $order = $orders[ $oid ] ?? null;

        $asientos      = array_filter( array_map( 'trim', explode( ',', $r['asientos'] ) ) );
        $ct            = count( $asientos );
        $nombre        = '';
        $valor_cobrado = 0;
        $metodo_pago   = '';
        $metodo_key    = '';
        $nota          = '';
        $evento        = get_the_title( (int) $r['event_id'] );

        if ( $order ) {
            $nombre        = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
            $valor_cobrado = (int) $order->get_meta( '_ss_valor_cobrado' );
            $metodo_pago   = $order->get_payment_method_title();
            $nota          = (string) $order->get_meta( '_ss_nota_bo' );
            $metodo_key    = str_replace( 'boxoffice_', '', $order->get_payment_method() );
        }

        $rows[] = array(
            'oid'          => $oid,
            'nombre'       => $nombre,
            'asientos'     => $asientos,
            'ct'           => $ct,
            'valor_cobrado'=> $valor_cobrado,
            'metodo_pago'  => $metodo_pago,
            'metodo_key'   => $metodo_key,
            'nota'         => $nota,
            'cajero'       => $r['usuario'],
            'evento'       => $evento,
            'fecha'        => $r['created_at'],
        );
        $total_ct    += $ct;
        $total_valor += $valor_cobrado;
        if ( isset( $totales_metodo[ $metodo_key ] ) ) {
            $totales_metodo[ $metodo_key ] += $valor_cobrado;
        }
    }

    // Ventas Web
    $web_rows        = array();
    $total_web_ct    = 0;
    $total_web_bruto = 0;

    if ( $event_id ) {
        // Busca via order items (ss_event_id se guarda en item meta, no siempre en order meta)
        $item_order_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT oi.order_id
             FROM {$wpdb->prefix}woocommerce_order_items AS oi
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim
                 ON oi.order_item_id = oim.order_item_id
             WHERE oim.meta_key = 'ss_event_id' AND oim.meta_value = %s",
            (string) $event_id
        ) );
        foreach ( $item_order_ids as $woid ) {
            $wo = wc_get_order( (int) $woid );
            if ( ! $wo ) { continue; }
            if ( $wo->get_meta( '_ss_boxoffice_sale' ) === 'yes' ) { continue; }
            if ( ! in_array( $wo->get_status(), array( 'processing', 'completed' ), true ) ) { continue; }
            $w_seats     = (array) $wo->get_meta( 'ss_seats' );
            $w_tkt       = $wo->get_meta( 'ss_ticket_qtys' );
            $w_ct        = count( array_filter( $w_seats ) );
            if ( is_array( $w_tkt ) ) { $w_ct += (int) array_sum( $w_tkt ); }
            $w_bruto     = (float) $wo->get_total();
            $w_nombre    = trim( $wo->get_billing_first_name() . ' ' . $wo->get_billing_last_name() );
            $w_fecha     = $wo->get_date_created() ? $wo->get_date_created()->format( 'Y-m-d H:i' ) : '';
            $web_rows[]  = array(
                'oid'     => (int) $woid,
                'nombre'  => $w_nombre,
                'asientos'=> $w_seats,
                'ct'      => $w_ct,
                'bruto'   => $w_bruto,
                'estado'  => $wo->get_status(),
                'fecha'   => $w_fecha,
            );
            $total_web_ct    += $w_ct;
            $total_web_bruto += $w_bruto;
        }
    }

    // Extras guardados para el evento
    $extras       = $event_id ? (array) get_post_meta( $event_id, '_ss_cierre_extras', true ) : array();
    $total_extras = (int) array_sum( array_column( $extras, 'valor' ) );

    $export_url = add_query_arg( array(
        'page'             => 'ss-cierre-contable',
        'ss_bo_export_csv' => '1',
        'ss_event_id'      => $event_id,
        'ss_from'          => $date_from,
        'ss_to'            => $date_to,
    ), admin_url( 'admin.php' ) );
    ?>
    <div class="wrap">
        <h1>Cierre Contable</h1>

        <form method="get" style="margin:16px 0;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="page" value="ss-cierre-contable">
            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px">Evento</label>
                <select name="ss_event_id" style="min-width:220px">
                    <option value="">— Todos —</option>
                    <?php foreach ( $events as $eid ) : ?>
                    <option value="<?php echo esc_attr( $eid ); ?>" <?php selected( $event_id, $eid ); ?>>
                        <?php echo esc_html( get_the_title( $eid ) ); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px">Desde</label>
                <input type="date" name="ss_from" value="<?php echo esc_attr( $date_from ); ?>">
            </div>
            <div>
                <label style="display:block;font-weight:600;margin-bottom:4px">Hasta</label>
                <input type="date" name="ss_to" value="<?php echo esc_attr( $date_to ); ?>">
            </div>
            <?php submit_button( 'Filtrar', 'secondary', '', false ); ?>
            <?php if ( ! empty( $rows ) || ! empty( $web_rows ) ) : ?>
            <a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary">⬇ Descargar CSV</a>
            <?php endif; ?>
        </form>

        <?php /* ── Ventas Box Office ── */ ?>
        <h2 style="font-size:15px;border-bottom:2px solid #2271b1;padding-bottom:4px;margin-bottom:12px">Ventas Box Office</h2>
        <?php if ( empty( $rows ) ) : ?>
        <p style="color:#555">No hay ventas Box Office para los filtros seleccionados.</p>
        <?php else : ?>
        <table class="widefat striped" style="font-size:13px;margin-top:8px">
            <thead>
                <tr>
                    <th>#Pedido</th>
                    <th>Persona</th>
                    <th>Asientos</th>
                    <th style="text-align:center">CT</th>
                    <th style="text-align:right">Valor cobrado</th>
                    <th>Método pago</th>
                    <th>Nota</th>
                    <th>Cajero</th>
                    <th>Evento</th>
                    <th>Fecha</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $row ) : ?>
                <tr>
                    <td><?php echo $row['oid'] ? '<a href="' . esc_url( get_edit_post_link( $row['oid'] ) ) . '" target="_blank">#' . esc_html( $row['oid'] ) . '</a>' : '—'; ?></td>
                    <td><?php echo esc_html( $row['nombre'] ?: '—' ); ?></td>
                    <td style="font-family:monospace;font-size:12px"><?php echo esc_html( implode( ', ', $row['asientos'] ) ); ?></td>
                    <td style="text-align:center"><?php echo esc_html( $row['ct'] ); ?></td>
                    <td style="text-align:right"><?php echo $row['valor_cobrado'] ? '<strong>$' . number_format( $row['valor_cobrado'], 0, ',', '.' ) . '</strong>' : '—'; ?></td>
                    <td><?php echo esc_html( $row['metodo_pago'] ?: '—' ); ?></td>
                    <td style="color:#9ca3af;font-size:12px"><?php echo esc_html( $row['nota'] ); ?></td>
                    <td><?php echo esc_html( $row['cajero'] ); ?></td>
                    <td><?php echo esc_html( $row['evento'] ); ?></td>
                    <td style="white-space:nowrap;color:#666;font-size:12px"><?php echo esc_html( $row['fecha'] ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:700;background:#f0f0f0">
                    <td colspan="3" style="padding:8px 10px">Totales</td>
                    <td style="text-align:center;padding:8px 10px"><?php echo esc_html( $total_ct ); ?></td>
                    <td style="text-align:right;padding:8px 10px"><?php echo $total_valor ? '$' . number_format( $total_valor, 0, ',', '.' ) : '—'; ?></td>
                    <td colspan="5"></td>
                </tr>
            </tfoot>
        </table>
        <div style="margin-top:8px;display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:#555">
            <?php
            $metodo_nombres = array(
                'efectivo'      => 'Efectivo',
                'nequi'         => 'Nequi',
                'transferencia' => 'Transferencia',
                'cortesia'      => 'Cortesía',
            );
            foreach ( $totales_metodo as $mk => $mv ) :
                if ( $mv <= 0 ) { continue; }
            ?>
            <span><strong><?php echo esc_html( $metodo_nombres[ $mk ] ?? $mk ); ?>:</strong>
            $<?php echo number_format( $mv, 0, ',', '.' ); ?></span>
            <?php endforeach; ?>
            <span style="margin-left:auto;color:#374151;font-weight:600">
                Total BO: $<?php echo number_format( $total_valor, 0, ',', '.' ); ?>
                (<?php echo esc_html( $total_ct ); ?> boletas)
            </span>
        </div>
        <?php endif; ?>

        <?php if ( $event_id ) : ?>

        <?php /* ── Ventas Web ── */ ?>
        <h2 style="font-size:15px;border-bottom:2px solid #2271b1;padding-bottom:4px;margin:24px 0 12px">Ventas Web</h2>
        <?php if ( empty( $web_rows ) ) : ?>
        <p style="color:#555;font-size:13px">No hay pedidos web para este evento.</p>
        <?php else : ?>
        <table class="widefat striped" style="font-size:13px;margin-top:8px">
            <thead>
                <tr>
                    <th>#Pedido</th><th>Persona</th><th>Asientos</th>
                    <th style="text-align:center">CT</th>
                    <th style="text-align:right">Total WC (bruto)</th>
                    <th>Estado</th><th>Fecha</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $web_rows as $wr ) : ?>
                <tr>
                    <td><a href="<?php echo esc_url( get_edit_post_link( $wr['oid'] ) ); ?>" target="_blank">#<?php echo esc_html( $wr['oid'] ); ?></a></td>
                    <td><?php echo esc_html( $wr['nombre'] ?: '—' ); ?></td>
                    <td style="font-family:monospace;font-size:12px"><?php echo esc_html( implode( ', ', array_filter( $wr['asientos'] ) ) ); ?></td>
                    <td style="text-align:center"><?php echo esc_html( $wr['ct'] ); ?></td>
                    <td style="text-align:right"><strong>$<?php echo number_format( $wr['bruto'], 0, ',', '.' ); ?></strong></td>
                    <td><?php echo esc_html( wc_get_order_status_name( $wr['estado'] ) ); ?></td>
                    <td style="white-space:nowrap;color:#666;font-size:12px"><?php echo esc_html( $wr['fecha'] ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:700;background:#f0f0f0">
                    <td colspan="3" style="padding:8px 10px">Totales web</td>
                    <td style="text-align:center;padding:8px 10px"><?php echo esc_html( $total_web_ct ); ?></td>
                    <td style="text-align:right;padding:8px 10px">$<?php echo number_format( $total_web_bruto, 0, ',', '.' ); ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
        <p style="color:#888;font-size:12px;margin-top:4px">
            <?php echo count( $web_rows ); ?> pedidos · <?php echo esc_html( $total_web_ct ); ?> boletas · $<?php echo number_format( $total_web_bruto, 0, ',', '.' ); ?> bruto WC
        </p>
        <?php endif; ?>

        <?php /* ── Consolidado ── */ ?>
        <?php $total_general = $total_valor + $total_web_bruto + $total_extras; ?>
        <h2 style="font-size:15px;border-bottom:2px solid #2271b1;padding-bottom:4px;margin:24px 0 12px">Consolidado</h2>
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:14px 16px;font-size:13px;margin-bottom:16px">
            <div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:10px">
                <div>Total Box Office: <strong>$<?php echo number_format( $total_valor, 0, ',', '.' ); ?></strong></div>
                <div>Total Web (WC): <strong>$<?php echo number_format( $total_web_bruto, 0, ',', '.' ); ?></strong></div>
                <?php if ( $total_extras > 0 ) : ?>
                <div>Extras / Taquilla: <strong>$<?php echo number_format( $total_extras, 0, ',', '.' ); ?></strong></div>
                <?php endif; ?>
            </div>
            <div style="font-size:15px;font-weight:700;color:#065f46;border-top:1px solid #e5e7eb;padding-top:10px;margin-top:2px">
                TOTAL RECAUDADO: <span style="font-size:17px">$<?php echo number_format( $total_general, 0, ',', '.' ); ?></span>
            </div>
        </div>

        <?php /* ── Extras / Taquilla ── */ ?>
        <h2 style="font-size:15px;border-bottom:2px solid #2271b1;padding-bottom:4px;margin:24px 0 12px">Ingresos Adicionales (Taquilla / Extras)</h2>
        <p style="color:#6b7280;font-size:12px;margin:0 0 10px">Ventas en puerta o ingresos manuales no registrados en Box Office ni online. Se guardan por evento y se suman al total.</p>

        <table id="ss-extras-table" class="widefat" style="font-size:12px;margin-bottom:8px">
            <thead>
                <tr>
                    <th>Descripción</th>
                    <th style="width:130px">Valor ($)</th>
                    <th style="width:140px">Método</th>
                    <th style="width:120px">Recibido por</th>
                    <th style="width:36px"></th>
                </tr>
            </thead>
            <tbody id="ss-extras-tbody">
                <?php foreach ( $extras as $idx => $ex ) : ?>
                <tr class="ss-extra-row">
                    <td><input type="text" class="regular-text ss-extra-desc" value="<?php echo esc_attr( $ex['desc'] ?? '' ); ?>" placeholder="Descripción" style="width:100%"></td>
                    <td><input type="number" class="ss-extra-valor" value="<?php echo esc_attr( $ex['valor'] ?? 0 ); ?>" min="0" step="1000" style="width:100%"></td>
                    <td>
                        <select class="ss-extra-metodo" style="width:100%">
                            <?php foreach ( array('efectivo'=>'Efectivo','nequi'=>'Nequi','transferencia'=>'Transferencia','otro'=>'Otro') as $mk=>$mv ) : ?>
                            <option value="<?php echo esc_attr($mk); ?>" <?php selected( $ex['metodo'] ?? '', $mk ); ?>><?php echo esc_html($mv); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="text" class="ss-extra-quien" value="<?php echo esc_attr( $ex['quien'] ?? '' ); ?>" placeholder="Nombre" style="width:100%"></td>
                    <td style="text-align:center"><button type="button" class="button ss-extra-delete" title="Eliminar" style="color:#dc2626;padding:2px 6px">&times;</button></td>
                </tr>
                <?php endforeach; ?>
                <?php if ( empty( $extras ) ) : ?>
                <tr class="ss-extra-row">
                    <td><input type="text" class="regular-text ss-extra-desc" value="" placeholder="Descripción" style="width:100%"></td>
                    <td><input type="number" class="ss-extra-valor" value="" min="0" step="1000" style="width:100%"></td>
                    <td>
                        <select class="ss-extra-metodo" style="width:100%">
                            <option value="efectivo">Efectivo</option>
                            <option value="nequi">Nequi</option>
                            <option value="transferencia">Transferencia</option>
                            <option value="otro">Otro</option>
                        </select>
                    </td>
                    <td><input type="text" class="ss-extra-quien" value="" placeholder="Nombre" style="width:100%"></td>
                    <td style="text-align:center"><button type="button" class="button ss-extra-delete" title="Eliminar" style="color:#dc2626;padding:2px 6px">&times;</button></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
            <button type="button" id="ss-extras-add" class="button">+ Agregar fila</button>
            <button type="button" id="ss-extras-save" class="button button-primary">Guardar extras</button>
            <span id="ss-extras-msg" style="font-size:12px;color:#059669"></span>
        </div>
        <div id="ss-extras-total" style="font-size:13px;font-weight:600;color:#374151">
            Total extras: $<span id="ss-extras-sum"><?php echo number_format( $total_extras, 0, ',', '.' ); ?></span>
        </div>

        <script>
        (function(){
            var nonce2   = '<?php echo esc_js( wp_create_nonce( 'ss_bo_report' ) ); ?>';
            var ajaxUrl2 = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
            var eventId2 = <?php echo (int) $event_id; ?>;

            function newRow() {
                var tr = document.createElement('tr');
                tr.className = 'ss-extra-row';
                tr.innerHTML = '<td><input type="text" class="regular-text ss-extra-desc" placeholder="Descripción" style="width:100%"></td>' +
                    '<td><input type="number" class="ss-extra-valor" value="" min="0" step="1000" style="width:100%"></td>' +
                    '<td><select class="ss-extra-metodo" style="width:100%"><option value="efectivo">Efectivo</option><option value="nequi">Nequi</option><option value="transferencia">Transferencia</option><option value="otro">Otro</option></select></td>' +
                    '<td><input type="text" class="ss-extra-quien" placeholder="Nombre" style="width:100%"></td>' +
                    '<td style="text-align:center"><button type="button" class="button ss-extra-delete" title="Eliminar" style="color:#dc2626;padding:2px 6px">&times;</button></td>';
                return tr;
            }

            function updateSum() {
                var total = 0;
                document.querySelectorAll('.ss-extra-valor').forEach(function(inp){
                    var v = parseFloat(inp.value);
                    if (!isNaN(v)) total += v;
                });
                document.getElementById('ss-extras-sum').textContent = Math.round(total).toLocaleString('es-CO');
            }

            document.getElementById('ss-extras-add').addEventListener('click', function(){
                document.getElementById('ss-extras-tbody').appendChild(newRow());
            });

            document.getElementById('ss-extras-table').addEventListener('click', function(e){
                if (e.target.classList.contains('ss-extra-delete')) {
                    var rows = document.querySelectorAll('.ss-extra-row');
                    if (rows.length > 1) {
                        e.target.closest('tr').remove();
                        updateSum();
                    } else {
                        e.target.closest('tr').querySelectorAll('input').forEach(function(i){ i.value = ''; });
                        e.target.closest('tr').querySelector('select').selectedIndex = 0;
                        updateSum();
                    }
                }
            });

            document.getElementById('ss-extras-table').addEventListener('input', updateSum);

            document.getElementById('ss-extras-save').addEventListener('click', function(){
                var btn = this;
                var msg = document.getElementById('ss-extras-msg');
                var items = [];
                document.querySelectorAll('.ss-extra-row').forEach(function(row){
                    var desc  = row.querySelector('.ss-extra-desc').value.trim();
                    var valor = parseFloat(row.querySelector('.ss-extra-valor').value) || 0;
                    var met   = row.querySelector('.ss-extra-metodo').value;
                    var quien = row.querySelector('.ss-extra-quien').value.trim();
                    if (desc || valor > 0) {
                        items.push({ desc: desc, valor: valor, metodo: met, quien: quien });
                    }
                });
                btn.disabled = true;
                msg.style.color = '#6b7280';
                msg.textContent = 'Guardando...';
                var fd = new FormData();
                fd.append('action',   'ss_extras_save');
                fd.append('nonce',    nonce2);
                fd.append('event_id', eventId2);
                fd.append('items',    JSON.stringify(items));
                fetch(ajaxUrl2, { method: 'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(resp){
                        btn.disabled = false;
                        if (resp.success) {
                            msg.style.color = '#059669';
                            msg.textContent = 'Guardado ✓ (' + resp.data.saved + ' ítems)';
                            updateSum();
                        } else {
                            msg.style.color = '#dc2626';
                            msg.textContent = 'Error: ' + resp.data;
                        }
                    })
                    .catch(function(){
                        btn.disabled = false;
                        msg.style.color = '#dc2626';
                        msg.textContent = 'Error de red.';
                    });
            });
        })();
        </script>

        <?php endif; ?>
    </div>
    <?php
}

// Box Office menu registrado en ss_seating_admin_menu() (menú unificado SS Seating).

function ss_boxoffice_settings_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) { return; }

    $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'usuarios';

    // ── Guardar (tab Usuarios) ───────────────────────────────────────────────
    if ( $active_tab === 'usuarios' && isset( $_POST['ss_bo_save'] ) && check_admin_referer( 'ss_boxoffice_users_save' ) ) {
        $names        = isset( $_POST['ss_bo_name'] )       ? (array) $_POST['ss_bo_name']       : array();
        $passes       = isset( $_POST['ss_bo_pass'] )       ? (array) $_POST['ss_bo_pass']       : array();
        $events_strs  = isset( $_POST['ss_bo_events_str'] ) ? (array) $_POST['ss_bo_events_str'] : array();
        $old_users    = get_option( 'ss_boxoffice_users', array() );
        $old_by_name  = array();
        foreach ( $old_users as $ou ) {
            $old_by_name[ $ou['name'] ] = $ou;
        }
        $users = array();
        foreach ( $names as $i => $name ) {
            $name = sanitize_text_field( trim( $name ) );
            if ( $name === '' ) { continue; }
            $new_pass = isset( $passes[ $i ] ) ? wp_unslash( $passes[ $i ] ) : '';
            if ( $new_pass !== '' ) {
                $hashed = wp_hash_password( $new_pass );
            } elseif ( isset( $old_by_name[ $name ]['pass'] ) ) {
                $hashed = $old_by_name[ $name ]['pass'];
            } else {
                $hashed = '';
            }
            $events_raw = isset( $events_strs[ $i ] ) ? sanitize_text_field( wp_unslash( $events_strs[ $i ] ) ) : '';
            $event_ids  = array_values( array_filter( array_map( 'intval', explode( ',', $events_raw ) ) ) );
            $users[] = array( 'name' => $name, 'pass' => $hashed, 'events' => $event_ids );
        }
        update_option( 'ss_boxoffice_users', $users );
        echo '<div class="notice notice-success"><p>Usuarios guardados.</p></div>';
    }

    $users    = ss_boxoffice_get_users();
    $base_url = admin_url( 'admin.php?page=ss-boxoffice-settings' );

    // Eventos disponibles para asignar
    $all_events = get_posts( array(
        'post_type'      => 'ss_event',
        'post_status'    => array( 'publish', 'private' ),
        'posts_per_page' => 100,
        'orderby'        => 'meta_value',
        'meta_key'       => '_ss_event_date',
        'order'          => 'DESC',
        'fields'         => 'ids',
    ) );
    ?>
    <div class="wrap">
        <h1>Box Office</h1>

        <?php if ( $active_tab === 'usuarios' ) : ?>

        <p>Configura los usuarios que pueden acceder al Box Office. Vacío en "Eventos asignados" = acceso a todos.</p>
        <form method="post">
            <?php wp_nonce_field( 'ss_boxoffice_users_save' ); ?>
            <table class="widefat" id="ss-bo-users-table">
                <thead>
                    <tr><th>Nombre</th><th>Contraseña</th><th>Eventos asignados</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ( $users as $i => $u ) :
                        $user_events = isset( $u['events'] ) ? array_map( 'intval', (array) $u['events'] ) : array();
                        $events_str  = implode( ',', $user_events );
                    ?>
                    <tr class="ss-bo-user-row">
                        <td><input type="text" name="ss_bo_name[]" value="<?php echo esc_attr( $u['name'] ); ?>" required /></td>
                        <td>
                            <div class="ss-password-field">
                                <input type="password" name="ss_bo_pass[]" value="" placeholder="Dejar vacío para mantener" autocomplete="new-password" />
                                <button type="button" class="button ss-toggle-password" title="Mostrar/ocultar contraseña">&#128065;</button>
                            </div>
                        </td>
                        <td>
                            <input type="hidden" name="ss_bo_events_str[]" value="<?php echo esc_attr( $events_str ); ?>" class="ss-bo-events-str">
                            <div class="ss-bo-event-chks" style="max-height:140px;overflow-y:auto;border:1px solid #ddd;padding:6px 8px;border-radius:4px;min-width:220px">
                                <?php foreach ( $all_events as $eid ) : ?>
                                <label style="display:block;font-size:12px;margin-bottom:3px;cursor:pointer">
                                    <input type="checkbox" class="ss-bo-event-chk" data-eid="<?php echo esc_attr( $eid ); ?>"
                                        <?php checked( in_array( (int) $eid, $user_events, true ) ); ?>>
                                    <?php echo esc_html( get_the_title( $eid ) ); ?>
                                </label>
                                <?php endforeach; ?>
                                <?php if ( empty( $all_events ) ) : ?>
                                <span style="color:#888;font-size:12px;">Sin eventos publicados</span>
                                <?php endif; ?>
                            </div>
                            <p style="margin:4px 0 0;font-size:11px;color:#888;">Sin marcar = acceso a todos</p>
                        </td>
                        <td><button type="button" class="button ss-bo-remove-user">Eliminar</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                <button type="button" class="button" id="ss-bo-add-user">+ Agregar usuario</button>
            </p>
            <p><input type="submit" name="ss_bo_save" class="button-primary" value="Guardar usuarios" /></p>
        </form>

        <?php endif; ?>

    </div>
    <style>
        .ss-password-field { display: flex; gap: 6px; align-items: center; }
        .ss-password-field input { flex: 1; }
        .ss-toggle-password { cursor: pointer; min-width: 36px; text-align: center; }
    </style>
    <script>
    (function() {
        // Toggle password visibility
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('ss-toggle-password')) {
                var input = e.target.parentElement.querySelector('input');
                input.type = input.type === 'password' ? 'text' : 'password';
            }
            // Remove user row
            if (e.target.classList.contains('ss-bo-remove-user')) {
                e.target.closest('tr').remove();
            }
        });

        // Sync checkboxes → hidden input
        document.addEventListener('change', function(e) {
            if (!e.target.classList.contains('ss-bo-event-chk')) return;
            var row = e.target.closest('.ss-bo-user-row');
            if (!row) return;
            var hidden = row.querySelector('.ss-bo-events-str');
            var chks   = row.querySelectorAll('.ss-bo-event-chk:checked');
            var ids    = Array.from(chks).map(function(c) { return c.dataset.eid; });
            hidden.value = ids.join(',');
        });

        // Add user row (no events checkboxes in new row — admin can save and re-open to assign)
        <?php
        // Build events checkboxes HTML for new rows (PHP-generated, JS-embedded)
        $new_row_chks = '';
        foreach ( $all_events as $eid ) {
            $title = esc_html( get_the_title( $eid ) );
            $new_row_chks .= '<label style=\"display:block;font-size:12px;margin-bottom:3px;cursor:pointer\">'
                . '<input type=\"checkbox\" class=\"ss-bo-event-chk\" data-eid=\"' . esc_attr( $eid ) . '\"> '
                . $title . '</label>';
        }
        if ( empty( $all_events ) ) {
            $new_row_chks = '<span style=\"color:#888;font-size:12px;\">Sin eventos publicados</span>';
        }
        ?>
        var eventsChksHtml = <?php echo wp_json_encode( $new_row_chks ); ?>;

        var addUserBtn = document.getElementById('ss-bo-add-user');
        if (addUserBtn) {
            addUserBtn.addEventListener('click', function() {
                var tbody = document.querySelector('#ss-bo-users-table tbody');
                var tr = document.createElement('tr');
                tr.className = 'ss-bo-user-row';
                tr.innerHTML = '<td><input type="text" name="ss_bo_name[]" required /></td>'
                    + '<td><div class="ss-password-field"><input type="password" name="ss_bo_pass[]" placeholder="Dejar vacío para mantener" autocomplete="new-password" />'
                    + '<button type="button" class="button ss-toggle-password" title="Mostrar/ocultar contraseña">&#128065;</button></div></td>'
                    + '<td><input type="hidden" name="ss_bo_events_str[]" value="" class="ss-bo-events-str">'
                    + '<div class="ss-bo-event-chks" style="max-height:140px;overflow-y:auto;border:1px solid #ddd;padding:6px 8px;border-radius:4px;min-width:220px">'
                    + eventsChksHtml + '</div>'
                    + '<p style="margin:4px 0 0;font-size:11px;color:#888;">Sin marcar = acceso a todos</p></td>'
                    + '<td><button type="button" class="button ss-bo-remove-user">Eliminar</button></td>';
                tbody.appendChild(tr);
            });
        }
    })();
    </script>
    <?php
}

// — ss_bo_report_inline() eliminada (tab Informe BO removido, reemplazado por Cierre Contable) —

// ── Rewrite rule + query vars ────────────────────────────────────────────────

add_action( 'init', 'ss_boxoffice_rewrite_rule' );

function ss_boxoffice_rewrite_rule(): void {
    add_rewrite_rule(
        '^box-office/(\d+)/?$',
        'index.php?ss_boxoffice_event=$matches[1]',
        'top'
    );
    add_rewrite_rule(
        '^box-office/?$',
        'index.php?ss_boxoffice_desktop=1',
        'top'
    );
}

add_filter( 'query_vars', 'ss_boxoffice_query_vars' );

function ss_boxoffice_query_vars( array $vars ): array {
    $vars[] = 'ss_boxoffice_event';
    $vars[] = 'ss_boxoffice_desktop';
    return $vars;
}

// ── AJAX endpoints ───────────────────────────────────────────────────────────

add_action( 'wp_ajax_ss_boxoffice_get_state',       'ss_boxoffice_ajax_get_state' );
add_action( 'wp_ajax_nopriv_ss_boxoffice_get_state', 'ss_boxoffice_ajax_get_state' );

function ss_boxoffice_ajax_get_state(): void {
    $event_id = (int) ( $_POST['event_id'] ?? 0 );
    if ( ! $event_id ) { wp_send_json_error( 'Evento no especificado' ); }

    check_ajax_referer( 'ss_boxoffice_nonce', 'nonce' );

    $user = ss_boxoffice_check_auth();
    if ( ! $user ) { wp_send_json_error( 'No autorizado', 401 ); }

    ss_cleanup_expired_reservations( $event_id );

    global $wpdb;
    $ledger_table = $wpdb->prefix . 'ss_seat_ledger';

    // Armar mapa de silla → nombre de reserva para el Box Office
    $manual_raw  = get_post_meta( $event_id, '_ss_manual_reserved_seats', true );
    $manual_info = array();
    if ( is_array( $manual_raw ) ) {
        foreach ( $manual_raw as $seat => $data ) {
            $manual_info[ $seat ] = $data['nombre'] ?? '';
        }
    }

    // Mapa silla → nombre del comprador para sillas vendidas (batch query a postmeta)
    $sold_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT seat_id, order_id FROM {$ledger_table}
         WHERE event_id = %d AND status = 'sold' AND order_id > 0",
        $event_id
    ), ARRAY_A ) ?: array();

    $sold_info = array();
    if ( ! empty( $sold_rows ) ) {
        $order_ids = array_unique( array_column( $sold_rows, 'order_id' ) );
        $names     = array();
        foreach ( $order_ids as $oid ) {
            $o = wc_get_order( (int) $oid );
            if ( $o ) {
                $names[ (int) $oid ] = trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() );
            }
        }
        foreach ( $sold_rows as $row ) {
            $full = $names[ (int) $row['order_id'] ] ?? '';
            if ( $full ) {
                $sold_info[ $row['seat_id'] ] = $full;
            }
        }
    }

    // Incluir temp_reserved del ledger (de otros cajeros) en la lista de reservadas
    $bo_session = 'bo_' . $user;
    $ledger_temp  = $wpdb->get_col( $wpdb->prepare(
        "SELECT seat_id FROM {$ledger_table}
         WHERE event_id = %d AND status = 'temp_reserved'
           AND session_id != %s
           AND (expires_at IS NULL OR expires_at >= NOW())",
        $event_id, $bo_session
    ) ) ?: array();

    $reserved_all = array_values( array_unique( array_merge(
        ss_seats_get_reserved( $event_id ),
        $ledger_temp
    ) ) );

    wp_send_json_success( array(
        'sold'            => array_values( ss_seats_read( $event_id ) ),
        'reserved'        => $reserved_all,
        'manual_reserved' => array_values( ss_seats_get_manual_reserved( $event_id ) ),
        'manual_info'     => $manual_info,
        'sold_info'       => $sold_info,
        'zone_inventory'  => ss_get_zone_inventory( $event_id ),
    ) );
}

add_action( 'wp_ajax_ss_boxoffice_reserve',       'ss_boxoffice_ajax_reserve' );
add_action( 'wp_ajax_nopriv_ss_boxoffice_reserve', 'ss_boxoffice_ajax_reserve' );

add_action( 'wp_ajax_ss_boxoffice_temp_reserve',       'ss_boxoffice_ajax_temp_reserve' );
add_action( 'wp_ajax_nopriv_ss_boxoffice_temp_reserve', 'ss_boxoffice_ajax_temp_reserve' );

function ss_boxoffice_ajax_temp_reserve(): void {
    $event_id    = (int) ( $_POST['event_id'] ?? 0 );
    $raw         = isset( $_POST['seats'] ) ? sanitize_text_field( wp_unslash( $_POST['seats'] ) ) : '';
    $seats       = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
    $lock_action = sanitize_text_field( wp_unslash( $_POST['lock_action'] ?? 'lock' ) );

    check_ajax_referer( 'ss_boxoffice_nonce', 'nonce' );

    $user = ss_boxoffice_check_auth();
    if ( ! $user ) { wp_send_json_error( 'No autorizado', 401 ); }

    if ( ! $event_id || empty( $seats ) ) {
        wp_send_json_success();
        return;
    }

    global $wpdb;
    $table      = $wpdb->prefix . 'ss_seat_ledger';
    $session_id = 'bo_' . $user;

    if ( $lock_action === 'lock' ) {
        foreach ( $seats as $seat ) {
            // INSERT IGNORE: si la silla ya está ocupada por alguien más, no sobreescribir
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$table} (event_id, seat_id, order_id, status, session_id, created_at, expires_at)
                 VALUES (%d, %s, 0, 'temp_reserved', %s, %s, DATE_ADD(NOW(), INTERVAL 5 MINUTE))",
                $event_id, $seat, $session_id, current_time( 'mysql' )
            ) );
        }
    } elseif ( $lock_action === 'unlock' ) {
        foreach ( $seats as $seat ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table}
                 WHERE event_id = %d AND seat_id = %s AND status = 'temp_reserved' AND session_id = %s",
                $event_id, $seat, $session_id
            ) );
        }
    }

    wp_send_json_success();
}

function ss_boxoffice_ajax_reserve(): void {
    $event_id = (int) ( $_POST['event_id'] ?? 0 );
    $raw      = isset( $_POST['seats'] ) ? sanitize_text_field( wp_unslash( $_POST['seats'] ) ) : '';
    $seats    = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );

    check_ajax_referer( 'ss_boxoffice_nonce', 'nonce' );

    $user = ss_boxoffice_check_auth();
    if ( ! $user ) { wp_send_json_error( 'No autorizado', 401 ); }

    if ( ! $event_id || empty( $seats ) ) { wp_send_json_error( 'Datos incompletos' ); }

    $sold   = ss_seats_read( $event_id );
    $manual = ss_seats_get_manual_reserved( $event_id );
    $conflict = array_intersect( $seats, array_merge( $sold, $manual ) );
    if ( ! empty( $conflict ) ) {
        wp_send_json_error( 'Sillas no disponibles: ' . implode( ', ', $conflict ) );
    }

    $nombre_reserva   = sanitize_text_field( wp_unslash( $_POST['reserve_nombre'] ?? '' ) );
    $telefono_reserva = sanitize_text_field( wp_unslash( $_POST['reserve_telefono'] ?? '' ) );

    ss_seats_manual_reserve( $event_id, $seats, $user, $nombre_reserva, $telefono_reserva );
    $accion_log = $nombre_reserva ? 'reservar a nombre de ' . $nombre_reserva : 'reservar';
    ss_boxoffice_log( $event_id, $user, $accion_log, $seats );
    ss_litespeed_purge_event( $event_id );

    wp_send_json_success( array( 'message' => 'Reservadas: ' . implode( ', ', $seats ) ) );
}

add_action( 'wp_ajax_ss_boxoffice_sell',       'ss_boxoffice_ajax_sell' );
add_action( 'wp_ajax_nopriv_ss_boxoffice_sell', 'ss_boxoffice_ajax_sell' );

function ss_boxoffice_ajax_sell(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        wp_send_json_error( 'WooCommerce no disponible' );
    }

    $event_id     = (int) ( $_POST['event_id'] ?? 0 );
    $raw          = isset( $_POST['seats'] ) ? sanitize_text_field( wp_unslash( $_POST['seats'] ) ) : '';
    $seats        = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
    $nombre        = sanitize_text_field( wp_unslash( $_POST['nombre'] ?? '' ) );
    $correo        = sanitize_email( wp_unslash( $_POST['correo'] ?? '' ) );
    $telefono      = sanitize_text_field( wp_unslash( $_POST['telefono'] ?? '' ) );
    $metodo_pago   = sanitize_text_field( wp_unslash( $_POST['metodo_pago'] ?? 'efectivo' ) );
    $valor_cobrado = (int) ( $_POST['valor_cobrado'] ?? 0 );
    $nota_bo       = sanitize_text_field( wp_unslash( $_POST['nota_bo'] ?? '' ) );
    $origen_venta  = sanitize_text_field( wp_unslash( $_POST['origen_venta'] ?? '' ) );

    // ticket_qtys: JSON string like {"VIP":2,"GENERAL":1} — used in zone/hybrid mode
    $ticket_qtys_raw = isset( $_POST['ticket_qtys'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_qtys'] ) ) : '';
    $ticket_qtys     = $ticket_qtys_raw ? json_decode( $ticket_qtys_raw, true ) : array();
    if ( ! is_array( $ticket_qtys ) ) { $ticket_qtys = array(); }

    check_ajax_referer( 'ss_boxoffice_nonce', 'nonce' );

    $user = ss_boxoffice_check_auth();
    if ( ! $user ) { wp_send_json_error( 'No autorizado', 401 ); }

    if ( ! $event_id || ! $nombre ) {
        wp_send_json_error( 'Datos incompletos' );
    }

    $sale_mode = get_post_meta( $event_id, '_ss_sale_mode', true );
    if ( ! $sale_mode ) { $sale_mode = 'seat'; }

    // Determine what we're selling
    $has_seats   = ! empty( $seats );
    $has_tickets = ! empty( $ticket_qtys ) && array_sum( $ticket_qtys ) > 0;

    if ( ! $has_seats && ! $has_tickets ) {
        wp_send_json_error( 'Seleccione sillas o tickets' );
    }

    // Obtener producto WooCommerce asociado al evento
    $product_id = get_post_meta( $event_id, '_ss_product_id', true );
    $product    = $product_id ? wc_get_product( $product_id ) : false;
    if ( ! $product ) {
        wp_send_json_error( 'Producto del evento no encontrado' );
    }

    // Validar sillas si hay (seat/hybrid mode)
    if ( $has_seats ) {
        $sold     = ss_seats_read( $event_id );
        $reserved = ss_seats_get_reserved( $event_id );
        $conflict = array_intersect( $seats, array_merge( $sold, $reserved ) );
        if ( ! empty( $conflict ) ) {
            wp_send_json_error( 'Sillas no disponibles: ' . implode( ', ', $conflict ) );
        }
    }

    // Si alguna silla está en reserva manual, liberarla antes de crear el pedido
    // (evita que ss_seats_guard_on_status_change detecte manual_reserved y cancele el pedido)
    $released_from_manual = array();
    if ( $has_seats ) {
        $manual_now = ss_seats_get_manual_reserved( $event_id );
        $to_release = array_intersect( $seats, $manual_now );
        if ( ! empty( $to_release ) ) {
            ss_seats_manual_release( $event_id, $to_release );
            $released_from_manual = array_values( $to_release );
        }
    }

    // Crear pedido WooCommerce
    $order = wc_create_order();
    if ( is_wp_error( $order ) ) {
        wp_send_json_error( 'Error al crear pedido: ' . $order->get_error_message() );
    }

    // Billing
    $name_parts = explode( ' ', $nombre, 2 );
    $order->set_billing_first_name( $name_parts[0] );
    $order->set_billing_last_name( isset( $name_parts[1] ) ? $name_parts[1] : '' );
    if ( $correo ) { $order->set_billing_email( $correo ); }
    if ( $telefono ) { $order->set_billing_phone( $telefono ); }

    // Calcular total de tickets (seats + zone qty)
    $total_qty = $has_seats ? count( $seats ) : 0;
    if ( $has_tickets ) {
        $total_qty += array_sum( $ticket_qtys );
    }

    // Crear item
    $item = new WC_Order_Item_Product();
    $item->set_product( $product );
    $item->set_name( $product->get_name() );
    $item->set_quantity( max( 1, $total_qty ) );
    if ( $valor_cobrado > 0 ) {
        $item->set_subtotal( (string) $valor_cobrado );
        $item->set_total( (string) $valor_cobrado );
    }
    $order->add_item( $item );
    $item->save();
    $item_id = $item->get_id();

    // Meta del item
    $zones = array();
    if ( $has_seats ) {
        $seat_data = ss_build_seat_data( $seats, $event_id );
        $zones     = array_unique( array_filter( array_column( $seat_data, 'zone' ) ) );
        wc_add_order_item_meta( $item_id, 'ss_seats', $seats );
        wc_add_order_item_meta( $item_id, 'ss_seat_data', $seat_data );
    }
    if ( $has_tickets ) {
        wc_add_order_item_meta( $item_id, 'ss_ticket_qtys', $ticket_qtys );
        $zones = array_merge( $zones, array_keys( $ticket_qtys ) );
    }

    wc_add_order_item_meta( $item_id, 'ss_event_id', $event_id );
    if ( ! empty( $zones ) ) {
        wc_add_order_item_meta( $item_id, 'ss_zone', implode( ', ', array_unique( $zones ) ) );
    }
    wc_add_order_item_meta( $item_id, 'ss_ticket_qty', $total_qty );

    // Método de pago
    $metodo_labels = array(
        'efectivo'      => 'Efectivo (Box Office)',
        'nequi'         => 'Nequi (Box Office)',
        'transferencia' => 'Transferencia (Box Office)',
        'cortesia'      => 'Cortesía (Box Office)',
    );
    $order->set_payment_method( 'boxoffice_' . $metodo_pago );
    $order->set_payment_method_title( isset( $metodo_labels[ $metodo_pago ] ) ? $metodo_labels[ $metodo_pago ] : 'Box Office' );

    // Order meta
    if ( $has_seats ) {
        $order->update_meta_data( 'ss_seats', $seats );
    }
    if ( $has_tickets ) {
        $order->update_meta_data( 'ss_ticket_qtys', $ticket_qtys );
    }
    $order->update_meta_data( 'ss_event_id', $event_id );
    $order->update_meta_data( '_ss_boxoffice_sale', 'yes' );
    $order->update_meta_data( '_ss_boxoffice_user', $user );
    if ( $valor_cobrado > 0 ) {
        $order->update_meta_data( '_ss_valor_cobrado', $valor_cobrado );
    }
    if ( $nota_bo !== '' ) {
        $order->update_meta_data( '_ss_nota_bo', $nota_bo );
    }
    $origenes_validos = array( 'meta_ads', 'whatsapp', 'instagram', 'referido', 'organico' );
    if ( in_array( $origen_venta, $origenes_validos, true ) ) {
        $order->update_meta_data( '_ss_bo_sale_origin', $origen_venta );
    }

    // Build note
    $note_parts = array();
    if ( $has_seats ) { $note_parts[] = 'Sillas: ' . implode( ', ', $seats ); }
    if ( $has_tickets ) {
        $tkt_str = array();
        foreach ( $ticket_qtys as $tname => $tqty ) { $tkt_str[] = $tname . ' x' . $tqty; }
        $note_parts[] = 'Tickets: ' . implode( ', ', $tkt_str );
    }
    $order->add_order_note( sprintf(
        'Venta en Box Office por %s. Método: %s. %s',
        $user, $metodo_pago, implode( ' | ', $note_parts )
    ) );

    // Set status → completed (marca como pagado, triggers ss_seats_on_order_confirmed)
    $order->calculate_totals();
    $order->set_status( 'completed' );
    $order->save();

    $order_id = $order->get_id();

    // Limpiar reservas manuales de asientos vendidos
    if ( $has_seats ) {
        ss_seats_manual_release( $event_id, $seats );
    }

    // Generar QR del pedido
    $token = ss_checkin_token( $order_id );
    update_post_meta( $order_id, '_ss_checkin_token', $token );
    $payload = ss_checkin_qr_payload( $token );
    $qr_url  = ss_qr_generate_local( $order_id, $payload, true );

    // Generar QRs individuales solo si el usuario lo pidió
    $qr_mode  = sanitize_text_field( wp_unslash( $_POST['qr_mode'] ?? 'order' ) );
    $individual_qrs = array();

    if ( $qr_mode === 'individual' ) {
        // QRs individuales por asiento (modo seat/hybrid)
        if ( $has_seats && count( $seats ) > 0 ) {
            $individual_qrs = ss_generate_seat_qrs( $order_id, $seats );
        }
        // QRs individuales por ticket de zona
        if ( $has_tickets && ! empty( $ticket_qtys ) ) {
            $ticket_qrs     = ss_generate_ticket_qrs( $order_id, $ticket_qtys );
            $individual_qrs = array_merge( $individual_qrs, $ticket_qrs );
        }
    }

    // Enviar email WC si hay correo
    if ( $correo ) {
        $mailer = WC()->mailer();
        $emails = $mailer->get_emails();
        if ( isset( $emails['WC_Email_Customer_Processing_Order'] ) ) {
            $emails['WC_Email_Customer_Processing_Order']->trigger( $order_id );
        }
    }

    // Log — combinar seats + ticket names
    $log_items  = $seats;
    if ( $has_tickets ) {
        foreach ( $ticket_qtys as $tname => $tqty ) { $log_items[] = $tname . ' x' . $tqty; }
    }
    $accion_vender = 'vender';
    if ( $valor_cobrado > 0 ) {
        $accion_vender .= ' · $' . number_format( $valor_cobrado, 0, ',', '.' );
    }
    if ( ! empty( $released_from_manual ) ) {
        $accion_vender .= ' (antes reservadas: ' . implode( ', ', $released_from_manual ) . ')';
    }
    ss_boxoffice_log( $event_id, $user, $accion_vender, $log_items, $order_id );
    ss_litespeed_purge_event( $event_id );

    wp_send_json_success( array(
        'message'     => 'Venta registrada. Pedido #' . $order_id,
        'order_id'    => $order_id,
        'qr_url'      => $qr_url ? $qr_url : '',
        'seat_qrs'    => ! empty( $individual_qrs ) ? $individual_qrs : new stdClass(),
        'checkin_url'  => home_url( '/ss-checkin/' . $order_id . '/' . $token . '/' ),
        'nombre'      => $nombre,
        'seats'       => $seats,
        'ticket_qtys' => $has_tickets ? $ticket_qtys : new stdClass(),
        'zones'       => array_values( array_unique( $zones ) ),
    ) );
}

add_action( 'wp_ajax_ss_boxoffice_release',       'ss_boxoffice_ajax_release' );
add_action( 'wp_ajax_nopriv_ss_boxoffice_release', 'ss_boxoffice_ajax_release' );

function ss_boxoffice_ajax_release(): void {
    $event_id = (int) ( $_POST['event_id'] ?? 0 );
    $raw      = isset( $_POST['seats'] ) ? sanitize_text_field( wp_unslash( $_POST['seats'] ) ) : '';
    $seats    = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );

    check_ajax_referer( 'ss_boxoffice_nonce', 'nonce' );

    $user = ss_boxoffice_check_auth();
    if ( ! $user ) { wp_send_json_error( 'No autorizado', 401 ); }

    if ( ! $event_id || empty( $seats ) ) { wp_send_json_error( 'Datos incompletos' ); }

    $manual  = ss_seats_get_manual_reserved( $event_id );
    $invalid = array_diff( $seats, $manual );
    if ( ! empty( $invalid ) ) {
        wp_send_json_error( 'Solo se pueden liberar sillas reservadas manualmente. No válidas: ' . implode( ', ', $invalid ) );
    }

    ss_seats_manual_release( $event_id, $seats );
    ss_boxoffice_log( $event_id, $user, 'liberar', $seats );
    ss_litespeed_purge_event( $event_id );

    wp_send_json_success( array( 'message' => 'Liberadas: ' . implode( ', ', $seats ) ) );
}

add_action( 'wp_ajax_ss_boxoffice_get_log',       'ss_boxoffice_ajax_get_log' );
add_action( 'wp_ajax_nopriv_ss_boxoffice_get_log', 'ss_boxoffice_ajax_get_log' );

function ss_boxoffice_ajax_get_log(): void {
    $event_id = (int) ( $_POST['event_id'] ?? 0 );
    check_ajax_referer( 'ss_boxoffice_nonce', 'nonce' );

    $user = ss_boxoffice_check_auth();
    if ( ! $user ) { wp_send_json_error( 'No autorizado', 401 ); }

    global $wpdb;
    $table = $wpdb->prefix . 'ss_boxoffice_log';
    $rows  = $wpdb->get_results( $wpdb->prepare(
        "SELECT usuario, accion, asientos, order_id, created_at FROM {$table} WHERE event_id = %d ORDER BY created_at DESC LIMIT 50",
        $event_id
    ), ARRAY_A );

    wp_send_json_success( array( 'log' => $rows ? $rows : array() ) );
}

// ── Box Office: obtener reservas manuales agrupadas ──────────────────────────
add_action( 'wp_ajax_ss_boxoffice_get_reservations',       'ss_boxoffice_ajax_get_reservations' );
add_action( 'wp_ajax_nopriv_ss_boxoffice_get_reservations', 'ss_boxoffice_ajax_get_reservations' );

function ss_boxoffice_ajax_get_reservations(): void {
    $event_id = (int) ( $_POST['event_id'] ?? 0 );
    check_ajax_referer( 'ss_boxoffice_nonce', 'nonce' );

    $user = ss_boxoffice_check_auth();
    if ( ! $user ) { wp_send_json_error( 'No autorizado', 401 ); }
    if ( ! $event_id ) { wp_send_json_error( 'Evento no válido' ); }

    $manual = get_post_meta( $event_id, '_ss_manual_reserved_seats', true );
    if ( ! is_array( $manual ) ) { $manual = array(); }

    // Agrupar por nombre + teléfono (una misma persona puede reservar en llamadas distintas)
    $groups = array();
    foreach ( $manual as $seat_id => $info ) {
        $nombre   = $info['nombre']   ?? '';
        $telefono = $info['telefono'] ?? '';
        $key      = $nombre . '|' . $telefono;
        if ( ! isset( $groups[ $key ] ) ) {
            $groups[ $key ] = array(
                'nombre'    => $nombre,
                'telefono'  => $telefono,
                'seats'     => array(),
                'timestamp' => $info['timestamp'] ?? 0,
            );
        }
        $groups[ $key ]['seats'][]  = $seat_id;
        $groups[ $key ]['timestamp'] = min( $groups[ $key ]['timestamp'], $info['timestamp'] ?? 0 );
    }

    $reservations = array_values( $groups );
    usort( $reservations, function ( $a, $b ) { return $b['timestamp'] <=> $a['timestamp']; } );

    wp_send_json_success( array( 'reservations' => $reservations ) );
}

// ── Box Office: obtener pedidos activos del evento ───────────────────────────
add_action( 'wp_ajax_ss_boxoffice_get_orders',       'ss_boxoffice_ajax_get_orders' );
add_action( 'wp_ajax_nopriv_ss_boxoffice_get_orders', 'ss_boxoffice_ajax_get_orders' );

function ss_boxoffice_ajax_get_orders(): void {
    $event_id = (int) ( $_POST['event_id'] ?? 0 );
    check_ajax_referer( 'ss_boxoffice_nonce', 'nonce' );

    $user = ss_boxoffice_check_auth();
    if ( ! $user ) { wp_send_json_error( 'No autorizado', 401 ); }
    if ( ! $event_id ) { wp_send_json_error( 'Evento no válido' ); }

    $order_ids = wc_get_orders( array(
        'status'   => array( 'processing', 'completed' ),
        'limit'    => 50,
        'orderby'  => 'date',
        'order'    => 'DESC',
        'return'   => 'ids',
    ) );

    $orders = array();
    foreach ( $order_ids as $oid ) {
        $order = wc_get_order( $oid );
        if ( ! $order ) { continue; }
        if ( ss_get_event_id_from_order( $order ) !== $event_id ) { continue; }

        $seats = ss_seats_get_from_order( $oid );
        $zones = array();
        foreach ( $order->get_items() as $item ) {
            $z = $item->get_meta( 'ss_zone' );
            $q = (int) $item->get_meta( 'ss_ticket_qty' );
            if ( $z ) {
                $zones[] = $z . ( $q > 1 ? ' x' . $q : '' );
            }
        }

        $qr_url = $order->get_meta( '_ss_qr_url' );
        if ( ! $qr_url ) {
            $token  = ss_checkin_token( $oid );
            $url    = home_url( '/ss-checkin/' . $oid . '/' . $token . '/' );
            $qr_url = ss_qr_generate_local( $oid, $url );
        }

        $orders[] = array(
            'id'       => $oid,
            'status'   => $order->get_status(),
            'customer' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'email'    => $order->get_billing_email(),
            'total'    => $order->get_total(),
            'currency' => $order->get_currency(),
            'date'     => $order->get_date_created() ? $order->get_date_created()->date( 'd/m/Y H:i' ) : '',
            'seats'    => $seats,
            'zones'    => $zones,
            'method'   => $order->get_payment_method_title(),
            'qr_url'   => $qr_url ?: '',
        );
    }

    wp_send_json_success( array( 'orders' => $orders ) );
}

// ── Box Office: cancelar pedido + reembolso ──────────────────────────────────
add_action( 'wp_ajax_ss_boxoffice_cancel_order',       'ss_boxoffice_ajax_cancel_order' );
add_action( 'wp_ajax_nopriv_ss_boxoffice_cancel_order', 'ss_boxoffice_ajax_cancel_order' );

function ss_boxoffice_ajax_cancel_order(): void {
    $event_id = (int) ( $_POST['event_id'] ?? 0 );
    $order_id = (int) ( $_POST['order_id'] ?? 0 );
    check_ajax_referer( 'ss_boxoffice_nonce', 'nonce' );

    $user = ss_boxoffice_check_auth();
    if ( ! $user ) { wp_send_json_error( 'No autorizado', 401 ); }
    if ( ! $event_id || ! $order_id ) { wp_send_json_error( 'Datos incompletos' ); }

    $order = wc_get_order( $order_id );
    if ( ! $order ) { wp_send_json_error( 'Pedido no encontrado' ); }

    // Verificar que el pedido pertenece a este evento
    if ( ss_get_event_id_from_order( $order ) !== $event_id ) {
        wp_send_json_error( 'El pedido no pertenece a este evento' );
    }

    // No cancelar pedidos ya cancelados
    if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) {
        wp_send_json_error( 'El pedido ya está cancelado' );
    }

    // Crear reembolso si el total > 0
    $refund_id = 0;
    $total = (float) $order->get_total();
    if ( $total > 0 ) {
        $refund = wc_create_refund( array(
            'amount'         => $total,
            'reason'         => 'Cancelado desde Box Office por ' . $user,
            'order_id'       => $order_id,
            'refund_payment' => false, // No intentar reembolso por gateway
        ) );
        if ( is_wp_error( $refund ) ) {
            wp_send_json_error( 'Error al crear reembolso: ' . $refund->get_error_message() );
        }
        $refund_id = $refund->get_id();
    }

    // Cambiar status → dispara ss_seats_on_order_released automáticamente
    $order->update_status( 'cancelled', 'Cancelado desde Box Office por ' . $user );

    // Notificar al cliente por email
    WC()->mailer()->get_emails()['WC_Email_Cancelled_Order']->trigger( $order_id, $order );

    // Log
    $seats = ss_seats_get_from_order( $order_id );
    ss_boxoffice_log( $event_id, $user, 'cancelar', $seats, $order_id );
    ss_litespeed_purge_event( $event_id );

    wp_send_json_success( array(
        'message'   => 'Pedido #' . $order_id . ' cancelado',
        'refund_id' => $refund_id,
    ) );
}

// ── Box Office: buscar pedido por ID ─────────────────────────────────────────
add_action( 'wp_ajax_ss_boxoffice_get_order',       'ss_boxoffice_ajax_get_order' );
add_action( 'wp_ajax_nopriv_ss_boxoffice_get_order', 'ss_boxoffice_ajax_get_order' );

function ss_boxoffice_ajax_get_order(): void {
    check_ajax_referer( 'ss_boxoffice_nonce', 'nonce' );
    $user = ss_boxoffice_check_auth();
    if ( ! $user ) { wp_send_json_error( 'No autorizado', 401 ); }

    $order_id = absint( $_POST['order_id'] ?? 0 );
    if ( ! $order_id ) { wp_send_json_error( 'Número de pedido inválido' ); }

    $order = wc_get_order( $order_id );
    if ( ! $order ) { wp_send_json_error( 'Pedido #' . $order_id . ' no encontrado' ); }

    $event_id = (int) $order->get_meta( 'ss_event_id' );
    if ( ! $event_id ) {
        // Fallback: buscar en items
        foreach ( $order->get_items() as $item ) {
            $eid = (int) $item->get_meta( 'ss_event_id' );
            if ( $eid ) { $event_id = $eid; break; }
        }
    }
    $seats = (array) $order->get_meta( 'ss_seats' );
    if ( empty( $seats ) ) {
        foreach ( $order->get_items() as $item ) {
            $s = $item->get_meta( 'ss_seats' );
            if ( ! empty( $s ) ) { $seats = (array) $s; break; }
        }
    }

    $event_title = $event_id ? get_the_title( $event_id ) : '';

    // Obtener lista de todos los eventos del mismo producto para el dropdown de traslado
    // Buscar todos los eventos disponibles como destino de traslado
    $sibling_events = array();
    $all_events = get_posts( array(
        'post_type'      => 'ss_event',
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ) );
    foreach ( $all_events as $ev ) {
        if ( (int) $ev->ID === $event_id ) { continue; } // excluir evento del pedido
        $sibling_events[] = array(
            'id'    => $ev->ID,
            'title' => $ev->post_title,
        );
    }

    wp_send_json_success( array(
        'order_id'       => $order_id,
        'nombre'         => $order->get_formatted_billing_full_name(),
        'email'          => $order->get_billing_email(),
        'event_id'       => $event_id,
        'event_title'    => $event_title,
        'seats'          => array_values( array_filter( $seats ) ),
        'status'         => $order->get_status(),
        'sibling_events' => $sibling_events,
    ) );
}

// ── Box Office: trasladar boletas a otro evento ───────────────────────────────
add_action( 'wp_ajax_ss_boxoffice_transfer',       'ss_boxoffice_ajax_transfer' );
add_action( 'wp_ajax_nopriv_ss_boxoffice_transfer', 'ss_boxoffice_ajax_transfer' );

function ss_boxoffice_ajax_transfer(): void {
    check_ajax_referer( 'ss_boxoffice_nonce', 'nonce' );
    $user = ss_boxoffice_check_auth();
    if ( ! $user ) { wp_send_json_error( 'No autorizado', 401 ); }

    global $wpdb;

    $order_id      = absint( $_POST['order_id'] ?? 0 );
    $dest_event_id = absint( $_POST['dest_event_id'] ?? 0 );
    $dest_seats    = array_values( array_filter( array_map( 'trim', explode( ',', wp_unslash( $_POST['dest_seats'] ?? '' ) ) ) ) );

    if ( ! $order_id || ! $dest_event_id || empty( $dest_seats ) ) {
        wp_send_json_error( 'Datos incompletos' );
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) { wp_send_json_error( 'Pedido no encontrado' ); }

    // Leer evento y sillas origen desde la orden
    $src_event_id = (int) $order->get_meta( 'ss_event_id' );
    $src_seats    = (array) $order->get_meta( 'ss_seats' );
    if ( ! $src_event_id ) {
        foreach ( $order->get_items() as $item ) {
            $eid = (int) $item->get_meta( 'ss_event_id' );
            if ( $eid ) { $src_event_id = $eid; break; }
        }
    }
    if ( empty( $src_seats ) ) {
        foreach ( $order->get_items() as $item ) {
            $s = $item->get_meta( 'ss_seats' );
            if ( ! empty( $s ) ) { $src_seats = (array) $s; break; }
        }
    }
    if ( ! $src_event_id || empty( $src_seats ) ) {
        wp_send_json_error( 'El pedido no tiene datos de evento/sillas' );
    }
    if ( $src_event_id === $dest_event_id ) {
        wp_send_json_error( 'El evento destino es el mismo que el origen' );
    }

    // 1. Verificar que las sillas destino están libres
    $sold_dest     = ss_seats_read( $dest_event_id );
    $reserved_dest = ss_seats_get_reserved( $dest_event_id );
    $manual_dest   = ss_seats_get_manual_reserved( $dest_event_id );
    $blocked       = array_intersect( $dest_seats, array_merge( $sold_dest, $reserved_dest, $manual_dest ) );
    if ( ! empty( $blocked ) ) {
        wp_send_json_error( 'Sillas no disponibles en destino: ' . implode( ', ', $blocked ) );
    }

    $table = $wpdb->prefix . 'ss_seat_ledger';

    // 2. Liberar sillas en evento origen del ledger
    if ( ! empty( $src_seats ) ) {
        $ph = implode( ',', array_fill( 0, count( $src_seats ), '%s' ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE event_id = %d AND seat_id IN ({$ph})",
            array_merge( array( $src_event_id ), $src_seats )
        ) );
    }

    // 3. Insertar sillas en evento destino del ledger
    foreach ( $dest_seats as $seat ) {
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$table} (event_id, seat_id, order_id, status, session_id, created_at, expires_at)
             VALUES (%d, %s, %d, 'sold', '', NOW(), NULL)",
            $dest_event_id, $seat, $order_id
        ) );
    }

    // 4. Actualizar metas del pedido
    $order->update_meta_data( 'ss_event_id', $dest_event_id );
    $order->update_meta_data( 'ss_seats',    $dest_seats );
    // Invalidar QRs individuales si las sillas cambian
    $src_sorted  = $src_seats;  sort( $src_sorted );
    $dest_sorted = $dest_seats; sort( $dest_sorted );
    if ( $src_sorted !== $dest_sorted ) {
        $order->delete_meta_data( '_ss_seat_tokens' );
    }
    // Actualizar item metas
    foreach ( $order->get_items() as $item ) {
        $item->update_meta_data( 'ss_event_id', $dest_event_id );
        $item->update_meta_data( 'ss_seats',    $dest_seats );
        $item->save();
    }
    $order->add_order_note( sprintf(
        'Traslado por %s: de evento #%d [%s] → evento #%d [%s]',
        esc_html( $user ),
        $src_event_id,
        implode( ', ', $src_seats ),
        $dest_event_id,
        implode( ', ', $dest_seats )
    ) );
    $order->save();

    // 5. Actualizar redeemed_events de fidelización si el pedido tuvo descuento loyalty
    $loyalty_pct = (int) $order->get_meta( '_ss_loyalty_discount_pct' );
    if ( $loyalty_pct > 0 && class_exists( 'SS_Loyalty' ) ) {
        $email    = $order->get_billing_email();
        $record   = SS_Loyalty::get( $email );
        $redeemed = json_decode( $record['redeemed_events'] ?? '[]', true ) ?: array();
        $redeemed = array_values( array_diff( $redeemed, array( $src_event_id ) ) );
        if ( ! in_array( $dest_event_id, $redeemed, true ) ) {
            $redeemed[] = $dest_event_id;
        }
        SS_Loyalty::upsert( $email, array( 'redeemed_events' => wp_json_encode( $redeemed ) ) );
    }

    // 6. Log + purge
    ss_boxoffice_log( $dest_event_id, $user, 'traslado desde evento #' . $src_event_id, $dest_seats, $order_id );
    ss_litespeed_purge_event( $src_event_id );
    ss_litespeed_purge_event( $dest_event_id );

    wp_send_json_success( array(
        'message' => sprintf(
            'Pedido #%d trasladado al evento "%s" (sillas: %s)',
            $order_id,
            get_the_title( $dest_event_id ),
            implode( ', ', $dest_seats )
        ),
    ) );
}

// ── Template redirect handler ────────────────────────────────────────────────

add_action( 'template_redirect', 'ss_boxoffice_handle' );

function ss_boxoffice_handle(): void {
    $event_id   = (int) get_query_var( 'ss_boxoffice_event' );
    $is_desktop = (bool) get_query_var( 'ss_boxoffice_desktop' );

    if ( ! $event_id && ! $is_desktop ) { return; }

    // Desktop mode: generic /box-office/ without event ID
    if ( $is_desktop ) {
        ss_boxoffice_handle_desktop();
        return;
    }

    $event = get_post( $event_id );
    if ( ! $event || $event->post_type !== 'ss_event' ) {
        ss_boxoffice_render( 'error', $event_id, 'Evento no encontrado.' );
        exit;
    }

    // Login POST
    if ( isset( $_POST['ss_bo_login'] ) ) {
        if ( ! wp_verify_nonce( isset( $_POST['_ss_bo_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_ss_bo_nonce'] ) ) : '', 'ss_boxoffice_login_' . $event_id ) ) {
            ss_boxoffice_render( 'login', $event_id, 'Sesión expirada. Intenta de nuevo.' );
            exit;
        }
        $login_user = sanitize_text_field( wp_unslash( $_POST['ss_bo_user'] ?? '' ) );
        $login_pass = isset( $_POST['ss_bo_pass'] ) ? wp_unslash( $_POST['ss_bo_pass'] ) : '';
        if ( ! ss_boxoffice_login( $login_user, $login_pass ) ) {
            ss_boxoffice_render( 'login', $event_id, 'Credenciales incorrectas.' );
            exit;
        }
    }

    // Logout
    if ( isset( $_GET['logout'] ) ) {
        if ( session_status() === PHP_SESSION_NONE && ! headers_sent() ) { session_start(); }
        $_SESSION = array();
        session_destroy();
        wp_safe_redirect( remove_query_arg( 'logout' ) );
        exit;
    }

    // Check auth
    $user = ss_boxoffice_check_auth();
    if ( ! $user ) {
        $login_msg = '';
        if ( isset( $_GET['session_expired'] ) ) {
            $login_msg = 'Tu sesión expiró por inactividad.';
        }
        ss_boxoffice_render( 'login', $event_id, $login_msg );
        exit;
    }

    // Verify cajero has access to this event (empty list = all events)
    $bo_users = ss_boxoffice_get_users();
    foreach ( $bo_users as $bu ) {
        if ( $bu['name'] === $user ) {
            $allowed = isset( $bu['events'] ) ? array_map( 'intval', (array) $bu['events'] ) : array();
            if ( ! empty( $allowed ) && ! in_array( $event_id, $allowed, true ) ) {
                ss_boxoffice_render( 'error', $event_id, 'No tienes acceso a este evento.' );
                exit;
            }
            break;
        }
    }

    // Timeout por inactividad (30 minutos)
    if ( session_status() === PHP_SESSION_NONE && ! headers_sent() ) { session_start(); }
    if ( ! empty( $_SESSION['ss_bo_last_activity'] ) ) {
        $inactive = time() - (int) $_SESSION['ss_bo_last_activity'];
        if ( $inactive > 1800 ) {
            $_SESSION = array();
            session_destroy();
            wp_safe_redirect( add_query_arg( 'session_expired', '1', remove_query_arg( array( 'logout', 'session_expired' ) ) ) );
            exit;
        }
    }
    $_SESSION['ss_bo_last_activity'] = time();

    ss_boxoffice_render( 'app', $event_id, '', $user );
    exit;
}

function ss_boxoffice_handle_desktop(): void {
    $desktop_url = home_url( '/box-office/' );

    // Login POST
    if ( isset( $_POST['ss_bo_login'] ) ) {
        if ( ! wp_verify_nonce( isset( $_POST['_ss_bo_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_ss_bo_nonce'] ) ) : '', 'ss_boxoffice_login_desktop' ) ) {
            ss_boxoffice_render( 'login', 0, 'Sesión expirada. Intenta de nuevo.' );
            exit;
        }
        $login_user = sanitize_text_field( wp_unslash( $_POST['ss_bo_user'] ?? '' ) );
        $login_pass = isset( $_POST['ss_bo_pass'] ) ? wp_unslash( $_POST['ss_bo_pass'] ) : '';
        if ( ! ss_boxoffice_login( $login_user, $login_pass ) ) {
            ss_boxoffice_render( 'login', 0, 'Credenciales incorrectas.' );
            exit;
        }
    }

    // Logout
    if ( isset( $_GET['logout'] ) ) {
        if ( session_status() === PHP_SESSION_NONE && ! headers_sent() ) { session_start(); }
        $_SESSION = array();
        session_destroy();
        wp_safe_redirect( $desktop_url );
        exit;
    }

    // Check auth
    $user = ss_boxoffice_check_auth();
    if ( ! $user ) {
        $login_msg = '';
        if ( isset( $_GET['session_expired'] ) ) {
            $login_msg = 'Tu sesión expiró por inactividad.';
        }
        ss_boxoffice_render( 'login', 0, $login_msg );
        exit;
    }

    // Timeout
    if ( session_status() === PHP_SESSION_NONE && ! headers_sent() ) { session_start(); }
    if ( ! empty( $_SESSION['ss_bo_last_activity'] ) ) {
        $inactive = time() - (int) $_SESSION['ss_bo_last_activity'];
        if ( $inactive > 1800 ) {
            $_SESSION = array();
            session_destroy();
            wp_safe_redirect( add_query_arg( 'session_expired', '1', $desktop_url ) );
            exit;
        }
    }
    $_SESSION['ss_bo_last_activity'] = time();

    ss_boxoffice_render( 'desktop', 0, '', $user );
    exit;
}

// ── Render function ──────────────────────────────────────────────────────────

function ss_boxoffice_render( string $view, int $event_id, string $message = '', string $user = '' ): void {
    $event_title = get_the_title( $event_id ) ?: 'Evento #' . $event_id;
    $plugin_url  = plugin_dir_url( __FILE__ );
    $plugin_path = plugin_dir_path( __FILE__ );

    // Layout data para la vista app
    $layout_json = '{}';
    $state_json  = '{}';
    $bo_config   = '{}';

    $sale_mode    = 'seat';
    $ticket_types_data = array();
    $checkin_url  = '';

    if ( $view === 'app' ) {
        $checkin_token = get_post_meta( $event_id, '_ss_event_checkin_token', true );
        if ( ! $checkin_token ) {
            $checkin_token = wp_generate_password( 32, false );
            update_post_meta( $event_id, '_ss_event_checkin_token', $checkin_token );
        }
        $checkin_url = home_url( '/control-ingreso/' . $event_id . '/?token=' . $checkin_token );
        $layout_raw = get_post_meta( $event_id, '_ss_layout', true );
        $layout_decoded = is_array( $layout_raw ) ? $layout_raw : ( is_string( $layout_raw ) ? json_decode( $layout_raw, true ) : array() );
        if ( ! is_array( $layout_decoded ) ) { $layout_decoded = array(); }

        ss_cleanup_expired_reservations( $event_id );

        $sale_mode = get_post_meta( $event_id, '_ss_sale_mode', true ) ?: 'seat';

        // Ticket types: usar inventario central (layout) como fuente de verdad
        $zone_inventory   = ss_get_zone_inventory( $event_id );
        $raw_ticket_types = get_post_meta( $event_id, '_ss_ticket_types', true );
        if ( is_array( $raw_ticket_types ) ) {
            foreach ( $raw_ticket_types as $tt ) {
                $name = isset( $tt['zone'] ) ? trim( $tt['zone'] ) : '';
                if ( $name === '' ) continue;
                $price = isset( $tt['price'] ) ? floatval( $tt['price'] ) : 0;

                // Si hay layout, la capacidad viene del inventario central
                $inv = $zone_inventory[ $name ] ?? ( $zone_inventory[ strtoupper( $name ) ] ?? null );
                if ( $inv ) {
                    $capacity  = $inv['available'];
                    $total     = $inv['total'];
                    $sold      = $inv['sold'];
                    $reserved  = $inv['reserved'];
                } else {
                    $capacity  = isset( $tt['capacity'] ) ? intval( $tt['capacity'] ) : 0;
                    $total     = $capacity;
                    $sold      = 0;
                    $reserved  = 0;
                }

                $ticket_types_data[] = array(
                    'name'      => $name,
                    'price'     => $price,
                    'capacity'  => $capacity,
                    'total'     => $total,
                    'sold'      => $sold,
                    'reserved'  => $reserved,
                );
            }
        }

        $layout_json = wp_json_encode( $layout_decoded );
        $state_json  = wp_json_encode( array(
            'sold'            => array_values( ss_seats_read( $event_id ) ),
            'reserved'        => array_values( ss_seats_get_reserved( $event_id ) ),
            'manual_reserved' => array_values( ss_seats_get_manual_reserved( $event_id ) ),
        ) );
        $bo_config = wp_json_encode( array(
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'ss_boxoffice_nonce' ),
            'eventId'       => $event_id,
            'user'          => $user,
            'saleMode'      => $sale_mode,
            'ticketTypes'   => $ticket_types_data,
            'groupDiscount' => class_exists( 'SS_Group_Discount' )
                ? SS_Group_Discount::get_for_event( $event_id )
                : array( 'enabled' => false, 'min_qty' => 5, 'pct' => 0 ),
            'colors'        => array(
                'sold'     => get_option( 'ss_color_sold',     '#ef5350' ),
                'reserved' => get_option( 'ss_color_reserved', '#fff3cd' ),
                'manual'   => get_option( 'ss_color_manual',   '#90caf9' ),
                'selected' => get_option( 'ss_color_selected', '#e94560' ),
            ),
        ) );
    }

    // Desktop view data
    $upcoming_events = array();
    $past_events     = array();
    if ( $view === 'desktop' ) {
        $bo_users = ss_boxoffice_get_users();
        $allowed_ids = array();
        foreach ( $bo_users as $bu ) {
            if ( $bu['name'] === $user ) {
                $allowed_ids = isset( $bu['events'] ) ? array_map( 'intval', (array) $bu['events'] ) : array();
                break;
            }
        }
        $today = current_time( 'Y-m-d' );
        $base_args = array(
            'post_type'   => 'ss_event',
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'orderby'     => 'meta_value',
            'meta_key'    => '_ss_event_date',
        );
        if ( ! empty( $allowed_ids ) ) {
            $base_args['post__in'] = $allowed_ids;
        }

        $upcoming_events = get_posts( array_merge( $base_args, array(
            'order'      => 'ASC',
            'meta_query' => array( array(
                'key'     => '_ss_event_date',
                'value'   => $today,
                'compare' => '>=',
                'type'    => 'DATE',
            ) ),
        ) ) );

        $past_events = get_posts( array_merge( $base_args, array(
            'order'      => 'DESC',
            'meta_query' => array( array(
                'key'     => '_ss_event_date',
                'value'   => $today,
                'compare' => '<',
                'type'    => 'DATE',
            ) ),
        ) ) );
    }

    header( 'Content-Type: text/html; charset=utf-8' );
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Box Office<?php echo $event_id ? ' — ' . esc_html( $event_title ) : ''; ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0f0f1a;color:#e0e0e0;min-height:100vh}
a{color:#64b5f6;text-decoration:none}

/* ── Login ─────────────────────────────────────── */
.bo-login{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.bo-login__card{background:#1a1a2e;border:1px solid #2a2a4a;border-radius:16px;padding:40px;width:100%;max-width:380px;text-align:center}
.bo-login__card h1{font-size:22px;color:#fff;margin-bottom:6px}
.bo-login__card .bo-event{font-size:14px;color:#90caf9;margin-bottom:24px}
.bo-login__card label{display:block;text-align:left;font-size:13px;color:#aaa;margin-bottom:4px;margin-top:14px}
.bo-login__card select,.bo-login__card input[type="password"]{width:100%;padding:10px 14px;border:1px solid #333;border-radius:8px;background:#16213e;color:#fff;font-size:15px;outline:none}
.bo-login__card select:focus,.bo-login__card input:focus{border-color:#64b5f6}
.bo-login__card button{margin-top:24px;width:100%;padding:12px;border:none;border-radius:8px;background:#1976d2;color:#fff;font-size:16px;font-weight:600;cursor:pointer;transition:background .2s}
.bo-login__card button:hover{background:#1565c0}
.bo-login__error{color:#ef5350;font-size:13px;margin-top:12px}
.bo-error{text-align:center;padding:60px 20px;font-size:18px;color:#ef5350}

/* ── App layout ────────────────────────────────── */
.bo-header{display:flex;align-items:center;justify-content:space-between;padding:12px 20px;background:#1a1a2e;border-bottom:1px solid #2a2a4a;flex-wrap:wrap;gap:8px}
.bo-header__title{font-size:16px;font-weight:700;color:#fff}
.bo-header__event{font-size:13px;color:#90caf9}
.bo-header__right{display:flex;align-items:center;gap:12px;font-size:13px}
.bo-header__user{color:#81c784}
.bo-header__logout{color:#ef9a9a;cursor:pointer;font-size:12px}
.bo-header__logout:hover{color:#ef5350}
.bo-header__back{font-size:20px;color:#90caf9;line-height:1;text-decoration:none;opacity:.7;transition:opacity .15s}
.bo-header__back:hover{opacity:1;color:#fff}
.bo-header__scan{display:inline-flex;align-items:center;gap:4px;padding:6px 12px;border-radius:8px;background:#7c3aed;color:#fff;font-size:12px;font-weight:600;text-decoration:none;transition:background .15s,transform .1s}
.bo-header__scan:hover{background:#6d28d9;color:#fff}
.bo-header__scan:active{transform:scale(.95)}

.bo-body{display:flex;height:calc(100vh - 54px);overflow:hidden}
.bo-map{flex:1;position:relative;display:flex;flex-direction:column}
.bo-map__canvas{flex:1;border:1px solid #2a2a4a;border-radius:10px;margin:12px;background:#16213e;overflow:hidden;min-height:300px}
.bo-toolbar{display:flex;align-items:center;gap:8px;padding:8px 12px;background:#1a1a2e;border-top:1px solid #2a2a4a;flex-wrap:wrap}
.bo-toolbar__mode{padding:8px 16px;border:1px solid #333;border-radius:8px;background:transparent;color:#ccc;font-size:13px;font-weight:600;cursor:pointer;transition:all .2s}
.bo-toolbar__mode.active{border-color:#1976d2;background:#1976d2;color:#fff}
.bo-toolbar__mode:hover:not(.active){border-color:#64b5f6;color:#64b5f6}
.bo-toolbar__spacer{flex:1}
.bo-toolbar__selection{font-size:12px;color:#aaa}
.bo-toolbar__action{padding:8px 20px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s;display:none}
.bo-toolbar__action.visible{display:inline-block}
.bo-toolbar__action--reserve{background:#1976d2;color:#fff}
.bo-toolbar__action--reserve:hover{background:#1565c0}
.bo-toolbar__action--sell{background:#2e7d32;color:#fff}
.bo-toolbar__action--sell:hover{background:#1b5e20}
.bo-toolbar__action--release{background:#e65100;color:#fff}
.bo-toolbar__action--release:hover{background:#bf360c}
.bo-toolbar__mode:active,.bo-toolbar__action:active{transform:scale(.95)}

/* ── Zone qty controls ─────────────────────────── */
.bo-zone-tickets{padding:12px;display:flex;flex-direction:column;gap:10px}
.bo-zone-tickets__title{font-size:14px;font-weight:700;color:#fff;margin-bottom:4px}
.bo-zone-ticket{display:flex;align-items:center;justify-content:space-between;background:#16213e;border:1px solid #2a2a4a;border-radius:8px;padding:10px 14px}
.bo-zone-ticket__name{font-size:14px;font-weight:600;color:#e0e0e0}
.bo-zone-ticket__price{font-size:12px;color:#90caf9}
.bo-zone-ticket__controls{display:flex;align-items:center;gap:8px}
.bo-zone-ticket__btn{width:32px;height:32px;border:1px solid #444;border-radius:6px;background:#1a1a2e;color:#fff;font-size:18px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s}
.bo-zone-ticket__btn:hover{background:#1976d2;border-color:#1976d2}
.bo-zone-ticket__qty{width:40px;text-align:center;font-size:16px;font-weight:700;color:#fff;background:transparent;border:none;outline:none}
.bo-map--hidden{display:none !important}
.bo-toolbar__action:disabled{opacity:.5;cursor:not-allowed}

/* ── Sidebar log ───────────────────────────────── */
.bo-sidebar{width:300px;background:#1a1a2e;border-left:1px solid #2a2a4a;display:flex;flex-direction:column}
.bo-sidebar__title{padding:14px 16px;font-size:14px;font-weight:700;color:#fff;border-bottom:1px solid #2a2a4a}
.bo-sidebar__tabs{display:flex;border-bottom:1px solid #2a2a4a}
.bo-sidebar__tab{flex:1;padding:10px 8px;font-size:13px;font-weight:600;color:#888;background:none;border:none;cursor:pointer;border-bottom:2px solid transparent;transition:all .2s}
.bo-sidebar__tab.active{color:#fff;border-bottom-color:#7c3aed}
.bo-sidebar__tab:active{transform:scale(.96)}
.bo-sidebar__tab:hover:not(.active){color:#bbb}
.bo-order{padding:10px 12px;border-radius:8px;margin-bottom:6px;background:rgba(255,255,255,.03);border:1px solid #2a2a4a}
.bo-order__header{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px}
.bo-order__id{font-size:13px;font-weight:700;color:#fff}
.bo-order__status{font-size:10px;padding:2px 8px;border-radius:10px;background:rgba(46,125,50,.2);color:#66bb6a;text-transform:uppercase;letter-spacing:.05em}
.bo-order__customer{font-size:12px;color:#ccc;margin-bottom:2px}
.bo-order__detail{font-size:11px;color:#888;margin-bottom:2px}
.bo-order__seats{font-size:11px;color:#90caf9}
.bo-order__zones{font-size:11px;color:#ce93d8}
.bo-order__actions{margin-top:8px;display:flex;gap:6px}
.bo-order__cancel{padding:5px 12px;border-radius:6px;border:1px solid #c62828;background:rgba(198,40,40,.15);color:#ef5350;font-size:11px;font-weight:600;cursor:pointer;transition:all .15s}
.bo-order__cancel:hover{background:#c62828;color:#fff}
.bo-order__cancel:disabled{opacity:.5;cursor:not-allowed}
.bo-order__qr-btn{padding:5px 12px;border-radius:6px;border:1px solid #1565c0;background:rgba(21,101,192,.15);color:#90caf9;font-size:11px;font-weight:600;cursor:pointer;transition:all .15s}
.bo-order__qr-btn:hover{background:#1565c0;color:#fff}
.bo-order__cancel:active,.bo-order__qr-btn:active{transform:scale(.95)}

/* ── Reservas ───────────────────────────────────── */
.bo-reservation{padding:10px 12px;border-radius:8px;margin-bottom:6px;background:rgba(144,202,249,.06);border:1px solid #2a2a4a}
.bo-reservation__name{font-size:13px;font-weight:700;color:#fff}
.bo-reservation__phone{font-size:11px;color:#90caf9;margin-bottom:2px}
.bo-reservation__seats{font-size:11px;color:#90caf9;margin-bottom:2px}
.bo-reservation__actions{margin-top:8px;display:flex;gap:6px}
.bo-reservation__sell{padding:5px 12px;border-radius:6px;border:1px solid #2e7d32;background:rgba(46,125,50,.15);color:#81c784;font-size:11px;font-weight:600;cursor:pointer;transition:all .15s}
.bo-reservation__sell:hover{background:#2e7d32;color:#fff}
.bo-reservation__release{padding:5px 12px;border-radius:6px;border:1px solid #e65100;background:rgba(230,81,0,.15);color:#ffb74d;font-size:11px;font-weight:600;cursor:pointer;transition:all .15s}
.bo-reservation__release:hover{background:#e65100;color:#fff}
.bo-reservation{transition:all .15s}
.bo-reservation__sell:active,.bo-reservation__release:active{transform:scale(.95)}
.bo-sidebar__list{flex:1;overflow-y:auto;padding:8px;animation:boFadeIn .18s ease}
.bo-sidebar__item{padding:8px 10px;border-radius:6px;margin-bottom:4px;font-size:12px;line-height:1.5}
.bo-sidebar__item--reservar{background:rgba(25,118,210,.15);border-left:3px solid #1976d2}
.bo-sidebar__item--vender{background:rgba(46,125,50,.15);border-left:3px solid #2e7d32}
.bo-sidebar__item--liberar{background:rgba(230,81,0,.15);border-left:3px solid #e65100}
.bo-sidebar__item--cancelar{background:rgba(198,40,40,.15);border-left:3px solid #c62828}
.bo-sidebar__time{color:#888;font-size:11px}
.bo-sidebar__user{font-weight:600;color:#fff}
.bo-sidebar__seats{color:#90caf9}

/* ── Legend ─────────────────────────────────────── */
.bo-legend{display:flex;gap:14px;padding:6px 12px;font-size:11px;color:#aaa;flex-wrap:wrap}
.bo-legend__item{display:flex;align-items:center;gap:4px}
.bo-legend__dot{width:12px;height:12px;border-radius:50%;border:1px solid rgba(255,255,255,.2)}

/* ── Stats panel ────────────────────────────────── */
.bo-stats{padding:16px;display:flex;flex-direction:column;gap:10px}
.bo-stat{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-radius:8px;background:rgba(255,255,255,.04);border:1px solid #2a2a4a}
.bo-stat--sold{border-color:#c62828}
.bo-stat--reserved{border-color:#f9a825}
.bo-stat--available{border-color:#2e7d32;background:rgba(46,125,50,.08)}
.bo-stat__label{font-size:13px;color:#aaa}
.bo-stat__val{font-size:18px;font-weight:700;color:#fff}
.bo-stat--sold .bo-stat__val{color:#ef5350}
.bo-stat--reserved .bo-stat__val{color:#ffc107}
.bo-stat--available .bo-stat__val{color:#66bb6a}
.bo-stats__bar-wrap{background:#1a1a2e;border-radius:6px;height:10px;overflow:hidden;margin-top:4px}
#bo-stat-bar{height:10px;background:linear-gradient(90deg,#ef5350,#ff7043);border-radius:6px;transition:width .5s}
.bo-stats__pct{text-align:center;font-size:12px;color:#666;margin-top:2px}

/* ── Transfer panel ─────────────────────────────── */
.bo-transfer{padding:12px}
.bo-transfer__search{display:flex;gap:6px;margin-bottom:12px}
.bo-transfer__search input{flex:1;padding:8px 10px;background:#16213e;border:1px solid #333;border-radius:6px;color:#fff;font-size:13px}
.bo-transfer__search button{padding:8px 12px;background:#1976d2;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;white-space:nowrap}
.bo-transfer__search button:hover{background:#1565c0}
.bo-transfer__info-row{margin-bottom:6px;font-size:13px}
.bo-transfer__label{display:block;font-size:12px;color:#aaa;margin:10px 0 4px}
.bo-transfer select,.bo-transfer input[type=text]{width:100%;padding:8px 10px;background:#16213e;border:1px solid #333;border-radius:6px;color:#fff;font-size:13px;box-sizing:border-box}
.bo-transfer__confirm-btn{width:100%;margin-top:14px;padding:10px;background:#7c3aed;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:all .15s}
.bo-transfer__confirm-btn:hover{background:#6d28d9}
.bo-transfer__confirm-btn:active,.bo-transfer__search button:active{transform:scale(.96)}

/* ── Seat tooltip ───────────────────────────────── */
.bo-seat-tooltip{position:fixed;z-index:9999;background:#1a1a2e;color:#90caf9;border:1px solid #1976d2;border-radius:6px;padding:4px 10px;font-size:12px;pointer-events:none;white-space:nowrap;box-shadow:0 2px 8px rgba(0,0,0,.5)}
.bo-seat-tooltip--tap{animation:boSeatInfoIn .15s ease}
@keyframes boSeatInfoIn{from{opacity:0;transform:scale(.9)}to{opacity:1;transform:scale(1)}}

/* ── Sell modal ─────────────────────────────────── */
.bo-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto}
.bo-modal-overlay.open{display:flex;animation:boOverlayIn .18s ease}
.bo-modal{background:#1a1a2e;border:1px solid #2a2a4a;border-radius:16px;padding:30px;width:100%;max-width:440px;max-height:calc(100vh - 40px);overflow-y:auto;margin:auto 0;animation:boModalIn .2s cubic-bezier(.2,.9,.3,1.2)}
@keyframes boOverlayIn{from{opacity:0}to{opacity:1}}
@keyframes boModalIn{from{opacity:0;transform:scale(.94) translateY(6px)}to{opacity:1;transform:scale(1) translateY(0)}}
.bo-modal h2{font-size:18px;color:#fff;margin-bottom:16px}
.bo-modal label{display:block;font-size:13px;color:#aaa;margin-bottom:4px;margin-top:12px}
.bo-modal input,.bo-modal select{width:100%;padding:10px 14px;border:1px solid #333;border-radius:8px;background:#16213e;color:#fff;font-size:14px;outline:none}
.bo-modal input:focus,.bo-modal select:focus{border-color:#64b5f6}
.bo-modal__seats{font-size:13px;color:#90caf9;margin-bottom:8px}
.bo-modal__actions{display:flex;gap:10px;margin-top:20px}
.bo-modal__actions button{flex:1;padding:10px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:all .15s}
.bo-modal__cancel{background:#333;color:#ccc}
.bo-modal__cancel:hover{background:#444}
.bo-modal__confirm{background:#2e7d32;color:#fff}
.bo-modal__confirm:hover{background:#1b5e20}
.bo-modal__actions button:active{transform:scale(.96)}

/* ── Valor cobrado ──────────────────────────────── */
.bo-valor-cobrado{background:#111827;border:1px solid #2a2a4a;border-radius:10px;padding:14px 16px;margin-top:16px}
.bo-valor__label{display:block;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6b7280;margin-bottom:10px}
.bo-valor__wrap{display:flex;align-items:center;gap:6px}
.bo-valor__prefix{font-size:18px;font-weight:700;color:#9ca3af}
.bo-valor-cobrado input{flex:1;padding:8px 10px;background:#16213e;border:1px solid #333;border-radius:8px;color:#fff;font-size:20px;font-weight:700;outline:none;width:100%;box-sizing:border-box}
.bo-valor-cobrado input:focus{border-color:#7c3aed}
.bo-valor__ref{display:block;color:#6b7280;font-size:11px;margin-top:6px}

/* ── Success modal ──────────────────────────────── */
.bo-success-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:1000;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto}
.bo-success-overlay.open{display:flex;animation:boOverlayIn .18s ease}
.bo-success{background:#1a1a2e;border:1px solid #2a2a4a;border-radius:16px;padding:30px;width:100%;max-width:420px;max-height:calc(100vh - 40px);overflow-y:auto;margin:auto 0;text-align:center;animation:boModalIn .2s cubic-bezier(.2,.9,.3,1.2)}
.bo-success h2{color:#81c784;font-size:20px;margin-bottom:16px}
.bo-success__detail{font-size:13px;color:#ccc;line-height:1.8;margin-bottom:16px;text-align:left}
.bo-success__detail strong{color:#fff}
.bo-success__qr{margin:16px auto;background:#fff;padding:12px;border-radius:10px;display:inline-block}
.bo-success__actions{display:flex;gap:10px;margin-top:20px}
.bo-success__actions button{flex:1;padding:10px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:all .15s}
.bo-success__download{background:#1976d2;color:#fff}
.bo-success__download:hover{background:#1565c0}
.bo-success__close{background:#333;color:#ccc}
.bo-success__close:hover{background:#444}
.bo-success__actions button:active{transform:scale(.96)}

/* ── Toast ──────────────────────────────────────── */
.bo-toast{position:fixed;top:20px;right:20px;z-index:2000;max-width:400px}
.bo-toast__msg{padding:12px 18px;border-radius:8px;margin-bottom:8px;font-size:14px;font-weight:600;animation:boFadeIn .3s}
.bo-toast__msg--success{background:#2e7d32;color:#fff}
.bo-toast__msg--error{background:#c62828;color:#fff}
@keyframes boFadeIn{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}

/* ── Responsive ─────────────────────────────────── */
@media(max-width:900px){
  .bo-sidebar{width:240px}
}
@media(max-width:680px){
  .bo-header{padding:10px 12px}
  .bo-header__title{font-size:14px}
  .bo-header__event{font-size:12px}
  .bo-body{flex-direction:column;height:calc(100vh - 54px)}
  .bo-map{flex:1 1 auto;min-height:0;overflow:hidden}
  .bo-sidebar{width:100%;flex:0 0 42vh;max-height:42vh;border-left:none;border-top:1px solid #2a2a4a}
  .bo-legend{padding:4px 8px;gap:8px;font-size:10px}
  #bo-floor-tabs{margin-bottom:2px !important}
  .bo-map__canvas{margin:6px;min-height:0}
  .bo-zone-tickets{padding:6px}
  .bo-toolbar{justify-content:center;padding:8px;flex-shrink:0}
  .bo-toolbar__mode{padding:10px 14px;min-height:44px}
  .bo-toolbar__action{padding:10px 16px;min-height:44px}
  .bo-sidebar__tab{padding:12px 6px;min-height:44px}
  .bo-seat-tooltip{max-width:80vw;white-space:normal}
}
</style>
</head>
<body>

<?php if ( $view === 'error' ) : ?>
<div class="bo-error"><?php echo esc_html( $message ); ?></div>

<?php elseif ( $view === 'login' ) : ?>
<div class="bo-login">
    <div class="bo-login__card">
        <h1>Box Office</h1>
        <?php if ( $event_id ) : ?>
        <div class="bo-event"><?php echo esc_html( $event_title ); ?></div>
        <?php endif; ?>
        <form method="post">
            <?php
            $nonce_action = $event_id ? 'ss_boxoffice_login_' . $event_id : 'ss_boxoffice_login_desktop';
            wp_nonce_field( $nonce_action, '_ss_bo_nonce' );
            ?>
            <label for="ss_bo_user">Usuario</label>
            <select name="ss_bo_user" id="ss_bo_user">
                <?php foreach ( ss_boxoffice_get_users() as $u ) : ?>
                <option value="<?php echo esc_attr( $u['name'] ); ?>"><?php echo esc_html( $u['name'] ); ?></option>
                <?php endforeach; ?>
            </select>

            <label for="ss_bo_pass">Contraseña</label>
            <input type="password" name="ss_bo_pass" id="ss_bo_pass" autocomplete="current-password">

            <button type="submit" name="ss_bo_login" value="1">Ingresar</button>
            <?php if ( $message ) : ?>
            <div class="bo-login__error"><?php echo esc_html( $message ); ?></div>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php elseif ( $view === 'desktop' ) : ?>
<div class="bo-header">
    <div>
        <span class="bo-header__title">Box Office</span>
    </div>
    <div class="bo-header__right">
        <span class="bo-header__user"><?php echo esc_html( $user ); ?></span>
        <a href="?logout=1" class="bo-header__logout">Cerrar sesión</a>
    </div>
</div>
<style>
.bo-desktop{padding:32px 24px;max-width:900px;margin:0 auto}
.bo-desktop h2{font-size:20px;color:#fff;margin-bottom:20px}
.bo-desktop__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px}
.bo-desktop__card{background:#1a1a2e;border:1px solid #2a2a4a;border-radius:12px;padding:20px 22px}
.bo-desktop__card--past{opacity:.65}
.bo-desktop__card-title{font-size:16px;font-weight:700;color:#fff;margin-bottom:6px}
.bo-desktop__card-date{font-size:13px;color:#90caf9;margin-bottom:16px}
.bo-desktop__card-inv{font-size:12px;color:#aaa;margin-bottom:16px}
.bo-desktop__card-btn{display:inline-block;padding:10px 22px;background:#1976d2;color:#fff;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;transition:background .2s}
.bo-desktop__card-btn:hover{background:#1565c0;color:#fff}
.bo-desktop__card-btn--view{background:#37474f}
.bo-desktop__card-btn--view:hover{background:#455a64;color:#fff}
.bo-desktop__empty{color:#aaa;font-size:15px;padding:40px 0}
.bo-desktop__past-section{margin-top:32px}
.bo-desktop__past-section summary{cursor:pointer;font-size:14px;color:#aaa;padding:10px 0;user-select:none;list-style:none;display:flex;align-items:center;gap:8px}
.bo-desktop__past-section summary::before{content:'▸';font-size:12px;transition:transform .2s}
.bo-desktop__past-section[open] summary::before{transform:rotate(90deg)}
.bo-desktop__past-section summary:hover{color:#ccc}
.bo-desktop__past-section .bo-desktop__grid{margin-top:16px}
</style>
<div class="bo-desktop">
    <h2>Seleccionar evento</h2>
    <?php if ( empty( $upcoming_events ) && empty( $past_events ) ) : ?>
    <p class="bo-desktop__empty">No hay eventos disponibles.</p>
    <?php else : ?>
    <?php if ( ! empty( $upcoming_events ) ) : ?>
    <div class="bo-desktop__grid">
        <?php foreach ( $upcoming_events as $ev ) :
            $ev_date_raw = get_post_meta( $ev->ID, '_ss_event_date', true );
            $ev_time_raw = get_post_meta( $ev->ID, '_ss_event_time', true );
            $ev_dt_str   = $ev_date_raw ? $ev_date_raw . ( $ev_time_raw ? ' ' . $ev_time_raw : '' ) : '';
            $ev_date     = $ev_dt_str ? date_i18n( $ev_time_raw ? 'j M Y — H:i' : 'j M Y', strtotime( $ev_dt_str ) ) : '';
            $inv         = ss_get_zone_inventory( $ev->ID );
            $inv_total   = 0;
            $inv_avail   = 0;
            foreach ( $inv as $z ) {
                $inv_total += $z['total']     ?? 0;
                $inv_avail += $z['available'] ?? 0;
            }
            $ev_url = home_url( '/box-office/' . $ev->ID . '/' );
        ?>
        <div class="bo-desktop__card">
            <div class="bo-desktop__card-title"><?php echo esc_html( $ev->post_title ); ?></div>
            <?php if ( $ev_date ) : ?>
            <div class="bo-desktop__card-date"><?php echo esc_html( $ev_date ); ?></div>
            <?php endif; ?>
            <?php if ( $inv_total > 0 ) : ?>
            <div class="bo-desktop__card-inv"><?php echo esc_html( $inv_avail ); ?> disponibles de <?php echo esc_html( $inv_total ); ?></div>
            <?php endif; ?>
            <a href="<?php echo esc_url( $ev_url ); ?>" class="bo-desktop__card-btn">Entrar</a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php elseif ( ! empty( $past_events ) ) : ?>
    <p class="bo-desktop__empty">No hay eventos próximos.</p>
    <?php endif; ?>
    <?php if ( ! empty( $past_events ) ) : ?>
    <details class="bo-desktop__past-section">
        <summary>Eventos pasados (<?php echo count( $past_events ); ?>)</summary>
        <div class="bo-desktop__grid">
            <?php foreach ( $past_events as $ev ) :
                $ev_date_raw = get_post_meta( $ev->ID, '_ss_event_date', true );
                $ev_time_raw = get_post_meta( $ev->ID, '_ss_event_time', true );
                $ev_dt_str   = $ev_date_raw ? $ev_date_raw . ( $ev_time_raw ? ' ' . $ev_time_raw : '' ) : '';
                $ev_date     = $ev_dt_str ? date_i18n( $ev_time_raw ? 'j M Y — H:i' : 'j M Y', strtotime( $ev_dt_str ) ) : '';
                $inv         = ss_get_zone_inventory( $ev->ID );
                $inv_total   = 0;
                $inv_avail   = 0;
                foreach ( $inv as $z ) {
                    $inv_total += $z['total']     ?? 0;
                    $inv_avail += $z['available'] ?? 0;
                }
                $ev_url = home_url( '/box-office/' . $ev->ID . '/' );
            ?>
            <div class="bo-desktop__card bo-desktop__card--past">
                <div class="bo-desktop__card-title"><?php echo esc_html( $ev->post_title ); ?></div>
                <?php if ( $ev_date ) : ?>
                <div class="bo-desktop__card-date"><?php echo esc_html( $ev_date ); ?></div>
                <?php endif; ?>
                <?php if ( $inv_total > 0 ) : ?>
                <div class="bo-desktop__card-inv"><?php echo esc_html( $inv_total - $inv_avail ); ?> vendidos de <?php echo esc_html( $inv_total ); ?></div>
                <?php endif; ?>
                <a href="<?php echo esc_url( $ev_url ); ?>" class="bo-desktop__card-btn bo-desktop__card-btn--view">Ver</a>
            </div>
            <?php endforeach; ?>
        </div>
    </details>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php elseif ( $view === 'app' ) : ?>
<!-- Header -->
<div class="bo-header">
    <div style="display:flex;align-items:center;gap:14px;">
        <a href="<?php echo esc_url( home_url( '/box-office/' ) ); ?>" class="bo-header__back" title="Volver al escritorio">&#8592;</a>
        <div>
            <span class="bo-header__title">Box Office</span>
            <span class="bo-header__event"><?php echo esc_html( $event_title ); ?></span>
        </div>
    </div>
    <div class="bo-header__right">
        <?php if ( ! empty( $checkin_url ) ) : ?>
        <a href="<?php echo esc_url( $checkin_url ); ?>" class="bo-header__scan" title="Escanear entradas de este evento">&#128247; Escanear</a>
        <?php endif; ?>
        <span class="bo-header__user"><?php echo esc_html( $user ); ?></span>
        <a href="?logout=1" class="bo-header__logout">Cerrar sesión</a>
    </div>
</div>

<!-- Body -->
<div class="bo-body">
    <div class="bo-map">
        <div class="bo-legend" id="bo-legend">
            <span class="bo-legend__item"><span class="bo-legend__dot" style="background:#888"></span> Disponible</span>
            <span class="bo-legend__item"><span class="bo-legend__dot" style="background:<?php echo esc_attr( get_option('ss_color_reserved','#fff3cd') ); ?>"></span> En carrito</span>
            <span class="bo-legend__item"><span class="bo-legend__dot" style="background:<?php echo esc_attr( get_option('ss_color_manual','#90caf9') ); ?>"></span> Reservado</span>
            <span class="bo-legend__item"><span class="bo-legend__dot" style="background:<?php echo esc_attr( get_option('ss_color_sold','#ef5350') ); ?>"></span> Vendido</span>
        </div>
        <div id="bo-floor-tabs" style="display:flex;gap:4px;margin-bottom:6px;flex-wrap:wrap;align-items:center;min-height:24px;"></div>
        <div class="bo-map__canvas" id="bo-konva-container"></div>

        <!-- Zone ticket qty controls (zone + hybrid mode) -->
        <div class="bo-zone-tickets" id="bo-zone-tickets" style="display:none">
            <div class="bo-zone-tickets__title">Tickets por zona</div>
            <?php if ( ! empty( $ticket_types_data ) ) : ?>
                <?php foreach ( $ticket_types_data as $tt ) : ?>
                <div class="bo-zone-ticket" data-ticket="<?php echo esc_attr( $tt['name'] ); ?>" data-price="<?php echo esc_attr( $tt['price'] ); ?>" data-max="<?php echo esc_attr( $tt['capacity'] ); ?>">
                    <div>
                        <div class="bo-zone-ticket__name"><?php echo esc_html( $tt['name'] ); ?></div>
                        <div class="bo-zone-ticket__price">$<?php echo esc_html( number_format( $tt['price'], 0, ',', '.' ) ); ?> — Disp: <span class="bo-zone-ticket__avail"><?php echo esc_html( $tt['capacity'] ); ?></span> / <?php echo esc_html( $tt['total'] ); ?></div>
                    </div>
                    <div class="bo-zone-ticket__controls">
                        <button class="bo-zone-ticket__btn" data-dir="-1">−</button>
                        <input class="bo-zone-ticket__qty" type="text" value="0" readonly>
                        <button class="bo-zone-ticket__btn" data-dir="1">+</button>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="bo-toolbar">
            <button class="bo-toolbar__mode active" data-mode="reservar">Reservar</button>
            <button class="bo-toolbar__mode" data-mode="vender">Vender</button>
            <button class="bo-toolbar__mode" data-mode="liberar">Liberar</button>
            <span class="bo-toolbar__spacer"></span>
            <span class="bo-toolbar__selection" id="bo-selection-info"></span>
            <button class="bo-toolbar__action bo-toolbar__action--reserve" id="bo-action-btn" disabled>Reservar selección</button>
        </div>
    </div>

    <div class="bo-sidebar">
        <div class="bo-sidebar__tabs">
            <button type="button" class="bo-sidebar__tab active" data-panel="bo-log-list">Actividad</button>
            <button type="button" class="bo-sidebar__tab" data-panel="bo-orders-list">Pedidos</button>
            <button type="button" class="bo-sidebar__tab" data-panel="bo-reservations-list">Reservas</button>
            <button type="button" class="bo-sidebar__tab" data-panel="bo-transfer-panel">Traslado</button>
            <button type="button" class="bo-sidebar__tab" data-panel="bo-stats-panel">Capacidad</button>
        </div>
        <div class="bo-sidebar__list" id="bo-stats-panel" style="display:none">
            <div class="bo-stats">
                <div class="bo-stat"><span class="bo-stat__label">Total asientos</span><span class="bo-stat__val" id="bo-stat-total">—</span></div>
                <div class="bo-stat bo-stat--sold"><span class="bo-stat__label">Vendidos</span><span class="bo-stat__val" id="bo-stat-sold">—</span></div>
                <div class="bo-stat bo-stat--reserved"><span class="bo-stat__label">Reservados</span><span class="bo-stat__val" id="bo-stat-reserved">—</span></div>
                <div class="bo-stat bo-stat--available"><span class="bo-stat__label">Disponibles</span><span class="bo-stat__val" id="bo-stat-available">—</span></div>
                <div class="bo-stats__bar-wrap"><div id="bo-stat-bar" style="width:0%"></div></div>
                <div class="bo-stats__pct" id="bo-stat-pct">0% ocupado</div>
            </div>
        </div>
        <div class="bo-sidebar__list" id="bo-log-list">
            <div style="padding:20px;text-align:center;color:#666;font-size:13px">Cargando...</div>
        </div>
        <div class="bo-sidebar__list" id="bo-orders-list" style="display:none">
            <div style="padding:20px;text-align:center;color:#666;font-size:13px">Cargando pedidos...</div>
        </div>
        <div class="bo-sidebar__list" id="bo-reservations-list" style="display:none">
            <div style="padding:20px;text-align:center;color:#666;font-size:13px">Cargando reservas...</div>
        </div>
        <div class="bo-sidebar__list bo-transfer-panel" id="bo-transfer-panel" style="display:none">
            <div class="bo-transfer">
                <div class="bo-transfer__search">
                    <input type="number" id="bo-transfer-order-input" placeholder="# Pedido" min="1">
                    <button type="button" id="bo-transfer-search-btn">Buscar</button>
                </div>
                <div id="bo-transfer-info" style="display:none">
                    <div class="bo-transfer__info-row"><strong id="bo-transfer-nombre"></strong></div>
                    <div class="bo-transfer__info-row" style="color:#90caf9" id="bo-transfer-seats-row"></div>
                    <div class="bo-transfer__info-row" style="color:#aaa;font-size:11px" id="bo-transfer-event-row"></div>
                    <label class="bo-transfer__label">Evento destino</label>
                    <select id="bo-transfer-dest-event">
                        <option value="">— Seleccionar —</option>
                    </select>
                    <label class="bo-transfer__label">Sillas destino <small>(separadas por coma)</small></label>
                    <input type="text" id="bo-transfer-dest-seats" placeholder="Ej: A1, A2, A3">
                    <button type="button" id="bo-transfer-confirm-btn" class="bo-transfer__confirm-btn">Confirmar traslado</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Sell Modal -->
<div class="bo-modal-overlay" id="bo-sell-modal">
    <div class="bo-modal">
        <h2>Crear pedido</h2>
        <div class="bo-modal__seats" id="bo-sell-seats"></div>
        <div class="bo-modal__tickets" id="bo-sell-tickets"></div>
        <label for="bo-sell-nombre">Nombre *</label>
        <input type="text" id="bo-sell-nombre" required>
        <label for="bo-sell-correo">Correo</label>
        <input type="email" id="bo-sell-correo" autocomplete="off">
        <div id="bo-loyalty-badge" style="margin-top:5px;font-size:12px;display:none;padding:5px 10px;border-radius:6px;"></div>
        <label for="bo-sell-telefono">Teléfono</label>
        <input type="tel" id="bo-sell-telefono">
        <label for="bo-sell-metodo">Método de pago</label>
        <select id="bo-sell-metodo">
            <option value="efectivo">Efectivo</option>
            <option value="nequi">Nequi</option>
            <option value="transferencia">Transferencia</option>
            <option value="cortesia">Cortesía</option>
        </select>
        <label for="bo-sell-qrmode">Modo de QR</label>
        <select id="bo-sell-qrmode">
            <option value="order">QR único por pedido</option>
            <option value="individual">QR individual por entrada</option>
        </select>
        <!-- Valor cobrado -->
        <div class="bo-valor-cobrado" id="bo-valor-cobrado" style="display:none">
            <label class="bo-valor__label" for="bo-valor-input">Valor cobrado</label>
            <div class="bo-valor__wrap">
                <span class="bo-valor__prefix">$</span>
                <input type="number" id="bo-valor-input" placeholder="0" min="0" step="1000">
            </div>
            <small class="bo-valor__ref" id="bo-valor-ref"></small>
        </div>
        <!-- Nota -->
        <div class="bo-valor-cobrado" style="margin-top:10px">
            <label class="bo-valor__label" for="bo-sell-nota">Nota</label>
            <input type="text" id="bo-sell-nota" placeholder="Ej: pago en efectivo, cortesía prensa…" style="width:100%;padding:8px 10px;background:#16213e;border:1px solid #333;border-radius:8px;color:#fff;font-size:14px;outline:none;box-sizing:border-box">
        </div>
        <!-- Origen de la venta (opcional) -->
        <label for="bo-sell-origen">Origen de la venta (opcional)</label>
        <select id="bo-sell-origen">
            <option value="">— Sin especificar —</option>
            <option value="meta_ads">Meta Ads</option>
            <option value="whatsapp">WhatsApp</option>
            <option value="instagram">Instagram</option>
            <option value="referido">Referido</option>
            <option value="organico">Orgánico</option>
        </select>

        <div class="bo-modal__actions">
            <button class="bo-modal__cancel" id="bo-sell-cancel">Cancelar</button>
            <button class="bo-modal__confirm" id="bo-sell-confirm">Crear pedido</button>
        </div>
    </div>
</div>

<!-- Reserve Modal -->
<div class="bo-modal-overlay" id="bo-reserve-modal">
    <div class="bo-modal">
        <h2>Reservar sillas</h2>
        <div class="bo-modal__seats" id="bo-reserve-seats"></div>
        <label for="bo-reserve-nombre">Nombre de reserva *</label>
        <input type="text" id="bo-reserve-nombre" placeholder="Ej: Juan Pérez" autocomplete="off">
        <label for="bo-reserve-telefono">Teléfono</label>
        <input type="text" id="bo-reserve-telefono" placeholder="Ej: 300 123 4567" autocomplete="off">
        <div class="bo-modal__actions">
            <button class="bo-modal__cancel" id="bo-reserve-cancel">Cancelar</button>
            <button class="bo-modal__confirm" id="bo-reserve-confirm">Reservar</button>
        </div>
    </div>
</div>

<!-- Success Modal -->
<div class="bo-success-overlay" id="bo-success-modal">
    <div class="bo-success">
        <h2>VENTA COMPLETADA</h2>
        <div class="bo-success__detail" id="bo-success-detail"></div>
        <div class="bo-success__qr" id="bo-success-qr"></div>
        <div class="bo-success__actions">
            <button class="bo-success__download" id="bo-success-download">Descargar QR</button>
            <button class="bo-success__close" id="bo-success-close">Cerrar</button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="bo-toast" id="bo-toast"></div>

<!-- Scripts -->
<script src="<?php echo esc_url( $plugin_url . 'assets/js/konva.min.js?v=' . filemtime( $plugin_path . 'assets/js/konva.min.js' ) ); ?>"></script>
<script src="<?php echo esc_url( $plugin_url . 'assets/js/seat-engine.js?v=' . filemtime( $plugin_path . 'assets/js/seat-engine.js' ) ); ?>"></script>
<script>
window.ssLayoutData     = <?php echo $layout_json; ?>;
window.ssBoxOfficeState = <?php echo $state_json; ?>;
window.ssBoxOffice      = <?php echo $bo_config; ?>;
var SS_BoxOffice = {
    ajax_url: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
    nonce:    <?php echo wp_json_encode( wp_create_nonce( 'ss_boxoffice_nonce' ) ); ?>
};
</script>
<script src="<?php echo esc_url( $plugin_url . 'assets/js/ss-boxoffice.js?v=' . filemtime( $plugin_path . 'assets/js/ss-boxoffice.js' ) ); ?>"></script>
<script>
(function(){
    var correoInput = document.getElementById('bo-sell-correo');
    var badge       = document.getElementById('bo-loyalty-badge');
    if (!correoInput || !badge) return;
    var timer = null;
    correoInput.addEventListener('input', function(){
        clearTimeout(timer);
        var email = correoInput.value.trim();
        if (!email || email.indexOf('@') < 1) { badge.style.display = 'none'; return; }
        timer = setTimeout(function(){
            var fd = new FormData();
            fd.append('action', 'ss_bo_loyalty_lookup');
            fd.append('email', email);
            fetch(SS_BoxOffice.ajax_url, {method:'POST', body:fd})
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (!data.success) { badge.style.display='none'; return; }
                    var tier = data.data.tier, shows = data.data.shows;
                    if (tier > 0) {
                        badge.textContent = '🟢 Fidelización activa — ' + tier + '% dto. · ' + shows + ' ' + (shows === 1 ? 'show' : 'shows');
                        badge.style.background = 'rgba(46,125,50,.2)';
                        badge.style.color = '#81c784';
                        badge.style.border = '1px solid rgba(46,125,50,.4)';
                    } else {
                        badge.textContent = '⚪ Sin fidelización';
                        badge.style.background = 'rgba(255,255,255,.05)';
                        badge.style.color = '#aaa';
                        badge.style.border = '1px solid #333';
                    }
                    badge.style.display = 'block';
                })
                .catch(function(){ badge.style.display='none'; });
        }, 600);
    });
})();
</script>

<?php endif; ?>

</body>
</html>
    <?php
}
