<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SS_Settings — Página de configuración global del plugin.
 * Almacena ajustes principales en 'ss_settings', colores BO en ss_color_*,
 * ubicaciones en 'ss_locations' y organizadores en 'ss_organizers'.
 */
class SS_Settings {

    private static ?SS_Settings $instance = null;
    private string $option_name = 'ss_settings';
    private string $page_slug   = 'ss-settings';

    private function __construct() {
        add_action( 'admin_menu',  array( $this, 'add_menu' ), 20 );
        add_action( 'admin_init',  array( $this, 'handle_admin_post' ) );
    }

    public static function init(): void {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
    }

    // ── Getters públicos ─────────────────────────────────────────────

    public static function get( string $key, $default = '' ) {
        $settings = get_option( 'ss_settings', array() );
        return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
    }

    public static function defaults(): array {
        return array(
            'color_primary'           => '#6d28d9',
            'color_secondary'         => '#ffffff',
            'seat_available_color'    => '#4CAF50',
            'seat_reserved_color'     => '#FF9800',
            'seat_sold_color'         => '#e0e0e0',
            'show_legend'             => '1',
            'show_inventory'          => '1',
            'show_selection_summary'  => '1',
            'show_event_header'       => '1',
            'show_event_description'  => '1',
            'show_event_date'         => '1',
            'show_event_venue'        => '1',
            'text_select_seat'        => 'Selecciona tu asiento',
            'text_sold_out'           => 'Agotado',
            'text_buy_button'         => 'Comprar entrada',
            'reservation_ttl'         => '15',
            'doors_open_minutes'      => '30',
            'tax_rate'                => '0',
            'commission_rate'         => '0',
        );
    }

    public static function get_locations(): array {
        $locs = get_option( 'ss_locations', array() );
        return is_array( $locs ) ? $locs : array();
    }

    public static function get_organizers(): array {
        $orgs = get_option( 'ss_organizers', array() );
        return is_array( $orgs ) ? $orgs : array();
    }

    // ── Menú admin ───────────────────────────────────────────────────

    public function add_menu(): void {
        add_submenu_page(
            'ss-seating-dashboard',
            'Configuración — SS Seating',
            'Configuración',
            'manage_options',
            $this->page_slug,
            array( $this, 'render_page' )
        );
    }

    // ── Guardar (todos los tabs) ─────────────────────────────────────

    public function handle_admin_post(): void {
        if ( ! isset( $_POST['_ss_cfg_nonce'] ) ) { return; }
        if ( ! wp_verify_nonce( wp_unslash( $_POST['_ss_cfg_nonce'] ), 'ss_cfg_save' ) ) { return; }
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $action      = sanitize_key( $_POST['ss_action'] ?? '' );
        $redirect_tab = 'general';

        if ( $action === 'save_general' || $action === 'save_estilo' ) {
            $redirect_tab = ( $action === 'save_general' ) ? 'general' : 'estilo';
            $current      = get_option( $this->option_name, self::defaults() );
            if ( ! is_array( $current ) ) { $current = self::defaults(); }
            $input  = $_POST[ $this->option_name ] ?? array();
            $merged = array_merge( $current, $this->sanitize_for_tab( $input, $action ) );
            update_option( $this->option_name, $merged );

        } elseif ( $action === 'save_colores_bo' ) {
            $redirect_tab = 'colores-bo';
            $pairs = array(
                'ss_color_sold'     => '#ef5350',
                'ss_color_reserved' => '#fff3cd',
                'ss_color_manual'   => '#90caf9',
                'ss_color_selected' => '#e94560',
            );
            foreach ( $pairs as $key => $default ) {
                $val = sanitize_hex_color( wp_unslash( $_POST[ $key ] ?? $default ) ) ?: $default;
                update_option( $key, $val );
            }

        } elseif ( $action === 'save_locations' ) {
            $redirect_tab = 'ubicaciones';
            $locations = array();
            $names   = array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST['loc_name']   ?? array() ) ) );
            $streets = array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST['loc_street'] ?? array() ) ) );
            $cities  = array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST['loc_city']   ?? array() ) ) );
            $ids     = array_map( 'intval', (array) ( $_POST['loc_id'] ?? array() ) );
            foreach ( $names as $i => $name ) {
                if ( empty( $name ) ) { continue; }
                $locations[] = array(
                    'id'     => ! empty( $ids[ $i ] ) ? $ids[ $i ] : ( time() + $i ),
                    'name'   => $name,
                    'street' => $streets[ $i ] ?? '',
                    'city'   => $cities[ $i ]  ?? '',
                );
            }
            update_option( 'ss_locations', $locations );

        } elseif ( $action === 'save_organizers' ) {
            $redirect_tab = 'organizadores';
            $organizers = array();
            $names  = array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST['org_name']  ?? array() ) ) );
            $emails = array_map( 'sanitize_email',       array_map( 'wp_unslash', (array) ( $_POST['org_email'] ?? array() ) ) );
            $phones = array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST['org_phone'] ?? array() ) ) );
            $ids    = array_map( 'intval', (array) ( $_POST['org_id'] ?? array() ) );
            foreach ( $names as $i => $name ) {
                if ( empty( $name ) ) { continue; }
                $organizers[] = array(
                    'id'    => ! empty( $ids[ $i ] ) ? $ids[ $i ] : ( time() + $i ),
                    'name'  => $name,
                    'email' => $emails[ $i ] ?? '',
                    'phone' => $phones[ $i ] ?? '',
                );
            }
            update_option( 'ss_organizers', $organizers );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . $this->page_slug . '&tab=' . $redirect_tab . '&saved=1' ) );
        exit;
    }

    private function sanitize_for_tab( array $input, string $action ): array {
        $defaults  = self::defaults();
        $sanitized = array();

        $estilo_keys  = array( 'color_primary', 'color_secondary', 'seat_available_color', 'seat_reserved_color', 'seat_sold_color' );
        $general_keys = array_keys( array_diff_key( $defaults, array_flip( $estilo_keys ) ) );
        $keys = ( $action === 'save_estilo' ) ? $estilo_keys : $general_keys;

        foreach ( $keys as $key ) {
            $val     = $input[ $key ] ?? $defaults[ $key ];
            $default = $defaults[ $key ];
            if ( str_starts_with( $key, 'show_' ) ) {
                $sanitized[ $key ] = ! empty( $val ) ? '1' : '0';
            } elseif ( str_ends_with( $key, '_color' ) ) {
                $sanitized[ $key ] = sanitize_hex_color( $val ) ?: $default;
            } elseif ( $key === 'reservation_ttl' ) {
                $sanitized[ $key ] = (string) max( 1, min( 120, absint( $val ) ) );
            } elseif ( $key === 'doors_open_minutes' ) {
                $sanitized[ $key ] = (string) max( 0, min( 180, absint( $val ) ) );
            } elseif ( str_ends_with( $key, '_rate' ) ) {
                $sanitized[ $key ] = (string) max( 0.0, (float) $val );
            } else {
                $sanitized[ $key ] = sanitize_text_field( $val );
            }
        }
        return $sanitized;
    }

    // ── Render de la página ──────────────────────────────────────────

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
        $saved = ! empty( $_GET['saved'] );

        $tabs = array(
            'general'        => 'General',
            'estilo'         => 'Estilo',
            'colores-bo'     => 'Colores BO',
            'ubicaciones'    => 'Ubicaciones',
            'organizadores'  => 'Organizadores',
        );
        if ( ! array_key_exists( $tab, $tabs ) ) { $tab = 'general'; }

        $nonce = wp_create_nonce( 'ss_cfg_save' );
        ?>
        <div class="wrap">
            <h1>SS Seating — Configuración</h1>
            <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible"><p>Cambios guardados correctamente.</p></div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper" style="margin-bottom:0">
                <?php foreach ( $tabs as $key => $label ) :
                    $url = admin_url( 'admin.php?page=' . $this->page_slug . '&tab=' . $key );
                ?>
                <a href="<?php echo esc_url( $url ); ?>"
                   class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
                <?php endforeach; ?>
            </nav>

            <div style="background:#fff;border:1px solid #ccd0d4;border-top:none;padding:24px;">
            <?php
            if ( $tab === 'general' ) {
                $this->render_tab_general( $nonce );
            } elseif ( $tab === 'estilo' ) {
                $this->render_tab_estilo( $nonce );
            } elseif ( $tab === 'colores-bo' ) {
                $this->render_tab_colores_bo( $nonce );
            } elseif ( $tab === 'ubicaciones' ) {
                $this->render_tab_ubicaciones( $nonce );
            } elseif ( $tab === 'organizadores' ) {
                $this->render_tab_organizadores( $nonce );
            }
            ?>
            </div>
        </div>
        <?php
    }

    // ── Tab: General ─────────────────────────────────────────────────

    private function render_tab_general( string $nonce ): void {
        $s = get_option( $this->option_name, self::defaults() );
        if ( ! is_array( $s ) ) { $s = self::defaults(); }
        $s = array_merge( self::defaults(), $s );
        $on = $this->option_name;
        ?>
        <form method="post" action="">
            <input type="hidden" name="_ss_cfg_nonce" value="<?php echo esc_attr( $nonce ); ?>">
            <input type="hidden" name="ss_action" value="save_general">

            <h2 style="margin-top:0">Mapa de asientos</h2>
            <table class="form-table">
                <?php $this->row_checkbox( $on, 'show_legend',            'Mostrar leyenda',              $s ); ?>
                <?php $this->row_checkbox( $on, 'show_inventory',         'Mostrar inventario por zona',  $s ); ?>
                <?php $this->row_checkbox( $on, 'show_selection_summary', 'Mostrar resumen de selección', $s ); ?>
            </table>

            <h2>Página de evento</h2>
            <table class="form-table">
                <?php $this->row_checkbox( $on, 'show_event_header',      'Mostrar encabezado',       $s ); ?>
                <?php $this->row_checkbox( $on, 'show_event_description', 'Mostrar descripción',      $s ); ?>
                <?php $this->row_checkbox( $on, 'show_event_date',        'Mostrar fecha',            $s ); ?>
                <?php $this->row_checkbox( $on, 'show_event_venue',       'Mostrar lugar del evento', $s ); ?>
            </table>

            <h2>Textos personalizables</h2>
            <table class="form-table">
                <?php $this->row_text( $on, 'text_select_seat', 'Texto seleccionar asiento', $s ); ?>
                <?php $this->row_text( $on, 'text_sold_out',    'Texto agotado',             $s ); ?>
                <?php $this->row_text( $on, 'text_buy_button',  'Texto botón de compra',     $s ); ?>
            </table>

            <h2>Reservas y puertas</h2>
            <table class="form-table">
                <tr><th>Tiempo de reserva (minutos)</th>
                <td><input type="number" name="<?php echo esc_attr( $on ); ?>[reservation_ttl]"
                           value="<?php echo esc_attr( $s['reservation_ttl'] ); ?>"
                           min="1" max="120" style="width:100px">
                    <p class="description">Tiempo que una silla queda bloqueada mientras el cliente compra.</p></td></tr>
                <tr><th>Apertura de puertas (minutos antes)</th>
                <td><input type="number" name="<?php echo esc_attr( $on ); ?>[doors_open_minutes]"
                           value="<?php echo esc_attr( $s['doors_open_minutes'] ); ?>"
                           min="0" max="180" style="width:100px">
                    <p class="description">Minutos antes del evento en que se abren las puertas. 0 = no mostrar.</p></td></tr>
            </table>

            <h2>Impuestos y comisiones</h2>
            <table class="form-table">
                <tr><th>Tasa de impuesto (%)</th>
                <td><input type="number" name="<?php echo esc_attr( $on ); ?>[tax_rate]"
                           value="<?php echo esc_attr( $s['tax_rate'] ); ?>"
                           min="0" max="100" step="0.01" style="width:100px"></td></tr>
                <tr><th>Comisión de servicio (%)</th>
                <td><input type="number" name="<?php echo esc_attr( $on ); ?>[commission_rate]"
                           value="<?php echo esc_attr( $s['commission_rate'] ); ?>"
                           min="0" max="100" step="0.01" style="width:100px"></td></tr>
            </table>

            <?php submit_button( 'Guardar configuración general' ); ?>
        </form>
        <?php
    }

    // ── Tab: Estilo ──────────────────────────────────────────────────

    private function render_tab_estilo( string $nonce ): void {
        $s  = get_option( $this->option_name, self::defaults() );
        if ( ! is_array( $s ) ) { $s = self::defaults(); }
        $s  = array_merge( self::defaults(), $s );
        $on = $this->option_name;
        ?>
        <form method="post" action="">
            <input type="hidden" name="_ss_cfg_nonce" value="<?php echo esc_attr( $nonce ); ?>">
            <input type="hidden" name="ss_action" value="save_estilo">

            <h2 style="margin-top:0">Colores de la interfaz</h2>
            <table class="form-table">
                <?php $this->row_color( $on, 'color_primary',   'Color primario',   $s ); ?>
                <?php $this->row_color( $on, 'color_secondary', 'Color secundario', $s ); ?>
            </table>

            <h2>Colores de sillas (frontend)</h2>
            <table class="form-table">
                <?php $this->row_color( $on, 'seat_available_color', 'Disponible', $s ); ?>
                <?php $this->row_color( $on, 'seat_reserved_color',  'Reservada',  $s ); ?>
                <?php $this->row_color( $on, 'seat_sold_color',      'Vendida',    $s ); ?>
            </table>

            <?php submit_button( 'Guardar estilo' ); ?>
        </form>
        <?php
    }

    // ── Tab: Colores BO ──────────────────────────────────────────────

    private function render_tab_colores_bo( string $nonce ): void {
        $colors = array(
            'ss_color_sold'     => array( 'Vendido',             '#ef5350' ),
            'ss_color_reserved' => array( 'En carrito / temp.',  '#fff3cd' ),
            'ss_color_manual'   => array( 'Reservado (manual)',  '#90caf9' ),
            'ss_color_selected' => array( 'Seleccionado',        '#e94560' ),
        );
        ?>
        <form method="post" action="">
            <input type="hidden" name="_ss_cfg_nonce" value="<?php echo esc_attr( $nonce ); ?>">
            <input type="hidden" name="ss_action" value="save_colores_bo">

            <h2 style="margin-top:0">Colores del Box Office</h2>
            <p class="description" style="margin-bottom:16px">Estos colores se aplican en el mapa del Box Office y en la leyenda.</p>
            <table class="form-table">
            <?php foreach ( $colors as $key => list( $label, $default ) ) :
                $val = get_option( $key, $default );
            ?>
                <tr>
                    <th><?php echo esc_html( $label ); ?></th>
                    <td>
                        <input type="color" name="<?php echo esc_attr( $key ); ?>"
                               value="<?php echo esc_attr( $val ); ?>"
                               style="width:60px;height:36px;padding:2px;cursor:pointer;">
                        <span style="font-family:monospace;margin-left:6px"><?php echo esc_html( $val ); ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </table>

            <?php submit_button( 'Guardar colores BO' ); ?>
        </form>
        <?php
    }

    // ── Tab: Ubicaciones ─────────────────────────────────────────────

    private function render_tab_ubicaciones( string $nonce ): void {
        $locations = self::get_locations();
        ?>
        <form method="post" action="">
            <input type="hidden" name="_ss_cfg_nonce" value="<?php echo esc_attr( $nonce ); ?>">
            <input type="hidden" name="ss_action" value="save_locations">

            <h2 style="margin-top:0">Ubicaciones / Venues</h2>
            <p class="description" style="margin-bottom:16px">
                Guarda los venues frecuentes para seleccionarlos rápidamente al crear un evento.
            </p>

            <table class="wp-list-table widefat fixed" id="ss-locations-table">
                <thead>
                    <tr>
                        <th style="width:35%">Nombre del venue</th>
                        <th style="width:35%">Dirección</th>
                        <th style="width:20%">Ciudad</th>
                        <th style="width:10%"></th>
                    </tr>
                </thead>
                <tbody id="ss-locations-tbody">
                <?php foreach ( $locations as $i => $loc ) : ?>
                    <tr>
                        <td><input type="hidden" name="loc_id[]" value="<?php echo esc_attr( $loc['id'] ); ?>">
                            <input type="text" name="loc_name[]" value="<?php echo esc_attr( $loc['name'] ); ?>" class="widefat" placeholder="Ej: Teatro Central"></td>
                        <td><input type="text" name="loc_street[]" value="<?php echo esc_attr( $loc['street'] ); ?>" class="widefat" placeholder="Cra 5 #10-20"></td>
                        <td><input type="text" name="loc_city[]" value="<?php echo esc_attr( $loc['city'] ); ?>" class="widefat" placeholder="Cali"></td>
                        <td><button type="button" class="button ss-remove-row" style="color:#b32d2e">✕</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4">
                            <button type="button" class="button" id="ss-add-location">+ Agregar ubicación</button>
                        </td>
                    </tr>
                </tfoot>
            </table>

            <?php submit_button( 'Guardar ubicaciones' ); ?>
        </form>

        <script>
        document.getElementById('ss-add-location').addEventListener('click', function() {
            var tbody = document.getElementById('ss-locations-tbody');
            var tr = document.createElement('tr');
            tr.innerHTML = '<td><input type="hidden" name="loc_id[]" value=""><input type="text" name="loc_name[]" value="" class="widefat" placeholder="Ej: Teatro Central"></td>'
                         + '<td><input type="text" name="loc_street[]" value="" class="widefat" placeholder="Cra 5 #10-20"></td>'
                         + '<td><input type="text" name="loc_city[]" value="" class="widefat" placeholder="Cali"></td>'
                         + '<td><button type="button" class="button ss-remove-row" style="color:#b32d2e">✕</button></td>';
            tbody.appendChild(tr);
        });
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('ss-remove-row')) {
                e.target.closest('tr').remove();
            }
        });
        </script>
        <?php
    }

    // ── Tab: Organizadores ───────────────────────────────────────────

    private function render_tab_organizadores( string $nonce ): void {
        $organizers = self::get_organizers();
        ?>
        <form method="post" action="">
            <input type="hidden" name="_ss_cfg_nonce" value="<?php echo esc_attr( $nonce ); ?>">
            <input type="hidden" name="ss_action" value="save_organizers">

            <h2 style="margin-top:0">Organizadores</h2>
            <p class="description" style="margin-bottom:16px">
                Guarda los organizadores frecuentes para seleccionarlos rápidamente al crear un evento.
            </p>

            <table class="wp-list-table widefat fixed" id="ss-organizers-table">
                <thead>
                    <tr>
                        <th style="width:30%">Nombre</th>
                        <th style="width:35%">Email</th>
                        <th style="width:25%">Teléfono</th>
                        <th style="width:10%"></th>
                    </tr>
                </thead>
                <tbody id="ss-organizers-tbody">
                <?php foreach ( $organizers as $i => $org ) : ?>
                    <tr>
                        <td><input type="hidden" name="org_id[]" value="<?php echo esc_attr( $org['id'] ); ?>">
                            <input type="text" name="org_name[]" value="<?php echo esc_attr( $org['name'] ); ?>" class="widefat" placeholder="Ej: Mi Organización"></td>
                        <td><input type="email" name="org_email[]" value="<?php echo esc_attr( $org['email'] ); ?>" class="widefat" placeholder="info@ejemplo.com"></td>
                        <td><input type="text" name="org_phone[]" value="<?php echo esc_attr( $org['phone'] ); ?>" class="widefat" placeholder="+57 300 000 0000"></td>
                        <td><button type="button" class="button ss-remove-row" style="color:#b32d2e">✕</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4">
                            <button type="button" class="button" id="ss-add-organizer">+ Agregar organizador</button>
                        </td>
                    </tr>
                </tfoot>
            </table>

            <?php submit_button( 'Guardar organizadores' ); ?>
        </form>

        <script>
        document.getElementById('ss-add-organizer').addEventListener('click', function() {
            var tbody = document.getElementById('ss-organizers-tbody');
            var tr = document.createElement('tr');
            tr.innerHTML = '<td><input type="hidden" name="org_id[]" value=""><input type="text" name="org_name[]" value="" class="widefat" placeholder="Ej: Mi Organización"></td>'
                         + '<td><input type="email" name="org_email[]" value="" class="widefat" placeholder="info@ejemplo.com"></td>'
                         + '<td><input type="text" name="org_phone[]" value="" class="widefat" placeholder="+57 300 000 0000"></td>'
                         + '<td><button type="button" class="button ss-remove-row" style="color:#b32d2e">✕</button></td>';
            tbody.appendChild(tr);
        });
        </script>
        <?php
    }

    // ── Helpers de fila ──────────────────────────────────────────────

    private function row_color( string $opt, string $key, string $label, array $s ): void {
        $val = $s[ $key ] ?? self::defaults()[ $key ];
        printf(
            '<tr><th>%s</th><td><input type="color" name="%s[%s]" value="%s" style="width:60px;height:36px;padding:2px;cursor:pointer;"> <span style="font-family:monospace">%s</span></td></tr>',
            esc_html( $label ),
            esc_attr( $opt ),
            esc_attr( $key ),
            esc_attr( $val ),
            esc_html( $val )
        );
    }

    private function row_checkbox( string $opt, string $key, string $label, array $s ): void {
        $val = $s[ $key ] ?? self::defaults()[ $key ];
        printf(
            '<tr><th>%s</th><td><label><input type="checkbox" name="%s[%s]" value="1" %s> Activado</label></td></tr>',
            esc_html( $label ),
            esc_attr( $opt ),
            esc_attr( $key ),
            checked( $val, '1', false )
        );
    }

    private function row_text( string $opt, string $key, string $label, array $s ): void {
        $val = $s[ $key ] ?? self::defaults()[ $key ];
        printf(
            '<tr><th>%s</th><td><input type="text" name="%s[%s]" value="%s" class="regular-text"></td></tr>',
            esc_html( $label ),
            esc_attr( $opt ),
            esc_attr( $key ),
            esc_attr( $val )
        );
    }

    // sanitize() se mantiene por compatibilidad con código que la llame directamente
    public function sanitize( $input ): array {
        return $this->sanitize_for_tab( is_array( $input ) ? $input : array(), 'save_general' );
    }
}
