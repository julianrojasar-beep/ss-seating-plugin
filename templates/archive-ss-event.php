<?php
/**
 * Template for the native ss_event post type archive (/ss-evento/).
 *
 * Uses the theme's header/footer, reutiliza el mismo render que el shortcode [ss_events].
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();

echo ss_render_events_archive();

get_footer();
