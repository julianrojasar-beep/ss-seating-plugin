=== SS Seating ===
Contributors: julianrojasar
Tags: eventos, asientos, boletas, qr, woocommerce
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.3.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sistema completo de selección de asientos, venta de boletas con QR y gestión de eventos en vivo para WooCommerce.

== Description ==

**SS Seating** es un plugin de WordPress para la venta de boletas con selección de asientos en eventos en vivo. Integra WooCommerce como sistema de pago y genera códigos QR únicos por pedido para el control de ingreso.

**Características principales:**

* Mapa de asientos interactivo con editor visual (Konva.js) — soporte multi-piso
* Venta online con selección de silla y pago vía WooCommerce
* Box Office para venta presencial desde el panel de administración
* Generación automática de QR por pedido
* Check-in con escáner QR desde dispositivo móvil
* Cierre contable consolidado (Box Office + Web + Taquilla)
* Centro de Difusión con links inteligentes, UTMs por canal y plantillas de WhatsApp
* Descuentos grupales automáticos por cantidad de boletas
* Soporte para múltiples zonas con precios diferenciados
* Actualizaciones automáticas vía GitHub Releases

== Installation ==

1. Sube la carpeta `ss-seating-plugin` al directorio `/wp-content/plugins/`
2. Activa el plugin desde el menú **Plugins** en WordPress
3. Ve a **SS Seating → Configuración** para ajustar las opciones generales
4. Crea tu primer evento desde **SS Seating → Eventos**
5. Diseña el mapa de asientos desde la pestaña **Mapa** del evento

== Changelog ==

= 1.3.7 =
* Fix: Cierre Contable ahora detecta correctamente las ventas web (la query pasó a usar `woocommerce_order_itemmeta` en vez de order meta, recuperando también pedidos históricos)
* Fix: El checkout web ahora guarda `ss_event_id` también en el pedido (además del order item) para consistencia futura

= 1.3.6 =
* Fix: Los links inteligentes del Centro de Difusión ya no requieren ir a Ajustes → Permalinks después de crear una serie — la regeneración de reglas es automática
* Fix: La variable `{link}` en la plantilla de WhatsApp ahora devuelve el link limpio sin parámetros UTM

= 1.3.5 =
* Nuevo: Centro de Difusión — series de shows con links inteligentes por slug, UTMs por canal (WhatsApp, Instagram Bio, QR/Poster) y plantillas de WhatsApp con variables automáticas
* Nuevo: Campo Artistas / Elenco en el evento (`{artistas}` disponible en plantillas)
* Nuevo: Tab "Difusión" en el metabox del evento con preview del mensaje y botones de copia

= 1.3.4 =
* Nuevo: Sistema de fidelización detrás de feature flag (activar en Configuración → Módulos)
* Nuevo: Script de build automático (`build.ps1`) que bumpa versión y genera zip limpio
* Fix: Cierre Contable eliminó dependencia de CSV de Mercado Pago — usa WooCommerce como única fuente de verdad

= 1.3.3 =
* Fix: Renombrada carpeta `plugin-update-checker-master` → `plugin-update-checker` para que el auto-updater cargue correctamente
* Fix: Actualizaciones automáticas vía GitHub Releases ahora funcionan desde el panel de plugins

= 1.3.2 =
* Nuevo: Auto-update integrado con Plugin Update Checker v5 (YahnisElsts) — actualizaciones desde GitHub Releases
* Seguridad: `.vscode/` y credenciales FTP removidos del repo y agregados al `.gitignore`

= 1.3.1 =
* Fix crítico: Mapas de asientos no renderizaban en venues multi-piso — el PHP leía `$layout['rows']` directamente ignorando el formato `floors[]`
* Nuevo: Helper `ss_layout_get_rows()` normaliza ambos formatos de layout (legacy y multi-piso)
