<?php
/**
 * Template Name: Gas App
 *
 * Optional full-bleed template for hosting the plugin UI. The plugin's SPA
 * renders its OWN header + navigation inside the [gas_app] mount, so this
 * template deliberately adds NO extra header — it only provides a clean,
 * full-width canvas. Assign it to a page that contains [gas_app] (or just use
 * the default template; both render the app full-bleed).
 *
 * @package GasSafetyTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();
	the_content();
endwhile;

get_footer();
