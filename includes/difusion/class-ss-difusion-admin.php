<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * SS_Difusion_Admin — Tab "Difusión" en el metabox del evento.
 */
class SS_Difusion_Admin {

    public static function render_tab( \WP_Post $post ): void {
        $event_id  = $post->ID;
        $series    = SS_Difusion::get_series();
        $serie_id  = get_post_meta( $event_id, '_ss_difusion_serie_id', true );
        $is_active = get_post_meta( $event_id, '_ss_difusion_is_active', true ) === '1';
        $artists   = get_post_meta( $event_id, '_ss_event_artists', true );
        $serie     = $serie_id ? SS_Difusion::get_serie( $serie_id ) : null;
        ?>
        <div class="ss-admin-tabs__panel" data-tab="difusion">

            <?php wp_nonce_field( 'ss_difusion_save', '_ss_difusion_nonce' ); ?>

            <h3 style="margin-top:0">Centro de Difusión</h3>

            <?php if ( empty( $series ) ) : ?>
            <p class="description">
                No hay series configuradas.
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ss-settings&tab=difusion' ) ); ?>">
                    Crear una serie en Configuración → Difusión
                </a>.
            </p>
            <?php else : ?>

            <table class="form-table" style="max-width:720px">
                <tr>
                    <th scope="row">Serie</th>
                    <td>
                        <select name="ss_difusion_serie_id" id="ss_difusion_serie_id" style="min-width:220px">
                            <option value="">— Sin asignar —</option>
                            <?php foreach ( $series as $s ) : ?>
                            <option value="<?php echo esc_attr( $s['id'] ); ?>" <?php selected( $serie_id, $s['id'] ); ?>>
                                <?php echo esc_html( $s['name'] ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Evento activo</th>
                    <td>
                        <label>
                            <input type="checkbox" name="ss_difusion_is_active" value="1" <?php checked( $is_active ); ?>>
                            Marcar como evento activo de esta serie
                        </label>
                        <p class="description">
                            Solo puede haber un evento activo por serie. Al activar este, el anterior se desactiva automáticamente.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Artistas / Elenco</th>
                    <td>
                        <textarea name="ss_event_artists" rows="3" style="width:100%;max-width:400px"
                                  placeholder="Un artista por línea"><?php echo esc_textarea( $artists ); ?></textarea>
                        <p class="description">Usado en <code>{artistas}</code> de la plantilla de WhatsApp.</p>
                    </td>
                </tr>
            </table>

            <?php if ( $serie ) : ?>

            <hr style="margin:20px 0">
            <h4 style="margin:0 0 12px">Links de difusión</h4>
            <?php foreach ( SS_Difusion::get_channels() as $ch ) :
                $url = SS_Difusion::build_utm_url( $event_id, $serie, $ch['source'], $ch['medium'] );
            ?>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                <button type="button" class="button ss-dif-copy"
                        data-copy="<?php echo esc_attr( $url ); ?>"
                        style="min-width:140px">
                    Copiar <?php echo esc_html( $ch['label'] ); ?>
                </button>
                <code style="font-size:11px;color:#6b7280;word-break:break-all"><?php echo esc_html( $url ); ?></code>
            </div>
            <?php endforeach; ?>

            <hr style="margin:20px 0">
            <h4 style="margin:0 0 8px">Mensaje WhatsApp</h4>
            <?php $message = SS_Difusion::render_template( $event_id, $serie ); ?>
            <?php if ( $message ) : ?>
            <textarea readonly rows="10" id="ss-dif-wa-preview"
                      style="width:100%;max-width:560px;font-family:monospace;font-size:12px;background:#f9fafb;color:#374151;resize:vertical"><?php echo esc_textarea( $message ); ?></textarea>
            <div style="margin-top:8px">
                <button type="button" class="button button-primary ss-dif-copy" data-copy-from="#ss-dif-wa-preview">
                    Copiar mensaje completo
                </button>
            </div>
            <?php elseif ( empty( $serie['wa_template'] ) ) : ?>
            <p class="description">
                Sin plantilla configurada para esta serie.
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ss-settings&tab=difusion' ) ); ?>">
                    Configurar plantilla →
                </a>
            </p>
            <?php endif; ?>

            <div style="margin-top:16px;padding:12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:4px;font-size:12px">
                <strong style="color:#166534">Variables disponibles:</strong>
                <code style="color:#166534">{fecha_larga} {artistas} {precio} {hora} {teatro} {link}</code>
            </div>

            <?php endif; // $serie ?>
            <?php endif; // !empty($series) ?>

        </div>
        <script>
        (function(){
            document.querySelectorAll('.ss-dif-copy').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var text, from = btn.dataset.copyFrom;
                    if ( from ) {
                        var el = document.querySelector(from);
                        text = el ? el.value : '';
                    } else {
                        text = btn.dataset.copy || '';
                    }
                    if ( ! text ) { return; }
                    if ( navigator.clipboard ) {
                        var orig = btn.textContent;
                        navigator.clipboard.writeText(text).then(function(){
                            btn.textContent = '✓ Copiado';
                            setTimeout(function(){ btn.textContent = orig; }, 2000);
                        });
                    }
                });
            });
        })();
        </script>
        <?php
    }
}
