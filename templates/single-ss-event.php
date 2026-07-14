<?php
/**
 * Template for ss_event single pages.
 *
 * Uses the theme's header/footer so the navigation and cart are always visible.
 * CSS overrides in ss-event-page.css handle any theme container conflicts.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();

while ( have_posts() ) :
    the_post();
    echo do_shortcode( '[ss_ticket_form event_id="' . get_the_ID() . '"]' );
endwhile;

get_footer();
