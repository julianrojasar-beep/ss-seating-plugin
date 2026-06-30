# Notas de refactoring — ss-seating-plugin.php

Archivo de referencia para la próxima sesión de limpieza. Análisis conservador: solo se listan
candidatos con alta confianza de ser código muerto o problemático. Cada ítem incluye por qué
es candidato y qué verificar antes de tocar.

---

## 1. Funciones duplicadas / superpuestas

### `ss_bo_report_page()` — ¿sigue existiendo?

Buscar si hay una función `ss_bo_report_page` residual. Fue renombrada a `ss_cierre_contable_page()`
pero puede haber quedado un wrapper vacío o alias. Verificar con grep.

### `ss_bo_report_inline()` — ELIMINADA (v1.2.0)

Ya removida. El comentario placeholder queda en ~línea 8780.

---

## 2. Constante / define redundante `SS_SEATING_VERSION`

Hay dos fuentes de versión:
- Header del plugin: `Version: 1.2.0`
- `define( 'SS_SEATING_VERSION', '1.2.0' )`

La constante solo se usa si algo la lee con `SS_SEATING_VERSION`. Verificar si hay usos con grep.
Si no hay usos externos, se puede eliminar o reemplazar por `get_plugin_data()`.

---

## 3. `ss_seating_plugin_data()` / `ss_seating_enqueue_scripts()` — revisar scripts duplicados

El plugin encola scripts vía múltiples hooks. Verificar si `ss-seating.js` y `ss-admin-builder-init.js`
se cargan dos veces en alguna página (dashboard + metabox del post). Síntoma: doble inicialización
de Konva.

---

## 4. `ss_bo_report_csv_export()` — page check redundante

Buscar la línea:
```php
if ( ! in_array( $_GET['page'], array( 'ss-bo-report', 'ss-cierre-contable' ), true ) )
```
El slug `ss-bo-report` es legacy. Cuando se confirme que nadie llega con ese slug, quitar de la lista.

---

## 5. Meta `_mep_event_id` vs `ss_event_id`

El plugin guarda `ss_event_id` (sin underscore) en los pedidos. Hay lógica que también lee
`_mep_event_id` como fallback (`ss_get_event_id_from_order()`). Verificar si el fallback todavía
aplica o si todos los pedidos nuevos ya usan `ss_event_id`. Si el fallback nunca se dispara en
producción, se puede simplificar la función.

---

## 6. `ss_seats_read()` — array vs JSON serializado

La función lee `_ss_sold_seats` de event meta. Hay código en varios lugares que hace
`json_decode` + `get_post_meta` directamente sin pasar por este helper. Consolidar en una
única fuente de verdad.

---

## 7. Tabla `wp_ss_seat_ledger` — limpieza de reservas expiradas

`ss_cleanup_expired_reservations()` se llama dentro de `ss_boxoffice_ajax_get_state()`.
Considerar mover la limpieza a un cron WP (`wp_schedule_event`) para no bloquear la respuesta
AJAX de estado del mapa, especialmente si hay muchas filas expiradas.

---

## 8. `ss_qr_generate_local()` — phpqrcode path hardcodeado

La función busca phpqrcode en un path relativo dentro de `wp-content/uploads/`. Si la instalación
de WP no tiene la librería ahí, falla silenciosamente. Documentar el requisito o agregar
una verificación con `file_exists` + aviso de admin.

---

## 9. Bloque de `ss_seating_admin_menu()` — submenús legados

Los siguientes slugs legacy usan `add_submenu_page( null, ... )` (hidden):
- `ss-bo-report` → redirige a `ss_cierre_contable_page`

Cuando se confirme que no hay bookmarks ni URLs hardcodeadas a esos slugs, se pueden quitar.

---

## 10. `ss_boxoffice_settings_page()` — tab `informe` dead code

El switch de tabs en Box Office Settings todavía tiene la lógica de renderizado del tab `informe`
(la llamada a `ss_bo_report_inline()` fue removida pero pueden quedar condicionales alrededor).
Verificar con grep `$active_tab.*informe` o `informe.*active_tab`.

---

## Prioridad sugerida

| # | Impacto | Riesgo | Acción | Estado |
|---|---------|--------|--------|--------|
| 4 | Bajo | Mínimo | Quitar slug legacy en export check | ✅ Hecho v1.2 |
| 10 | Bajo | Mínimo | Limpiar condicional tab informe | ✅ Hecho v1.2 |
| 5 | Medio | Bajo | Verificar fallback `_mep_event_id` en prod | ✅ No existe en código |
| 7 | Bajo | Bajo | `ss_rebuild_sold_seats` nunca llamada | ✅ Eliminada v1.2 |
| 2 | Bajo | Mínimo | Verificar usos de `SS_SEATING_VERSION` | ✅ Eliminada v1.2 |
| 6 | Medio | Medio | Consolidar lectura de `_ss_sold_seats` | Pendiente |
| 8 | Alto | Bajo | Agregar `file_exists` check phpqrcode | Pendiente |
| 3 | Medio | Medio | Mover cleanup ledger expirado a cron | Pendiente |
