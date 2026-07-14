=== SS Seating ===
Contributors: julianrojasar
Tags: eventos, asientos, boletas, qr, woocommerce
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.3.10
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
* Descuentos grupales y por pareja (porcentaje o precio fijo) automáticos
* Precios de preventa/venta con fecha de corte y contador regresivo en la página del evento
* Modo de venta "sin mapa" para eventos de venta general por cantidad, sin selección de asiento
* Barra de disponibilidad en tiempo real en el listado y la página de cada evento
* Soporte para múltiples zonas con precios diferenciados
* Actualizaciones automáticas vía GitHub Releases

== Installation ==

1. Sube la carpeta `ss-seating-plugin` al directorio `/wp-content/plugins/`
2. Activa el plugin desde el menú **Plugins** en WordPress
3. Ve a **SS Seating → Configuración** para ajustar las opciones generales
4. Crea tu primer evento desde **SS Seating → Eventos**
5. Diseña el mapa de asientos desde la pestaña **Mapa** del evento

== Changelog ==

= 1.3.11 =
* Nuevo: Modo de venta "Sin mapa" — venta por cantidad sin selección de asiento ni mapa visible, aforo controlado por la capacidad definida en cada tipo de boleta
* Nuevo: Precio de preventa por tipo de boleta con fecha de corte por evento — se cobra automáticamente antes/después del corte, con banner y contador regresivo (días/horas/minutos/segundos) en la página del evento
* Nuevo: Descuento por pareja — configurable como porcentaje o como precio fijo por las 2 boletas (independiente del descuento grupal, no se acumulan)
* Nuevo: Barra de disponibilidad en el listado de eventos y en la página de compra
* Nuevo: Control de "Color de texto" en SS Seating → Configuración → Estilo, con vista previa en vivo (botón + textos + colores de sillas) que se actualiza al instante mientras eliges los colores
* Fix: Los descuentos de grupo y pareja ya no dependen del módulo de Fidelización — funcionan aunque esté desactivado
* Fix: Al aplicar descuentos en el carrito, ahora se compara el ahorro real en dinero (no solo el %) entre fidelización, grupo y pareja, para elegir siempre el que más conviene al comprador
* Fix: El mapa de asientos (Konva) construía el mapa completo dos veces en cada carga de página — se eliminó la duplicación, reduciendo el trabajo del navegador a la mitad
* Fix: Se eliminó el loader de pantalla completa de la página de evento y del listado — solo aparecía después de que el contenido ya se había pintado, sumando espera sin evitar ningún parpadeo real
* Fix: El texto de los botones ("Ver evento", "Comprar entrada") ahora ajusta su color automáticamente (blanco/oscuro) según el color primario elegido, para que siempre se lea bien
* Fix: Mejor contraste general de los textos informativos en la página del evento (encabezados de sección, disponibilidad, notas) y en la descripción del evento
* Fix: El ícono de las tarjetas de evento sin imagen ya no queda casi invisible (opacidad muy baja)
* Fix: La primera imagen del listado de eventos ya no se carga en diferido (lazy), priorizando su descarga por ser la más visible
* Fix: Se quitaron del panel de administración dos controles de color que no tenían ningún efecto real ("Color secundario" y el color "Disponible" de sillas, que en realidad lo define cada evento por zona)

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
