<?php
/**
 * Fallback template + site entry point.
 *
 * Three cases:
 *  1. The page hosts [gas_app] / [gas_bridge]  -> render FULL-BLEED; the
 *     plugin SPA owns the screen (its own header + nav).
 *  2. The site root is NOT the app (e.g. still the default posts home) ->
 *     show a branded landing with a clear "enter the system" button, so the
 *     site never looks empty or unstyled after the theme is activated.
 *  3. Any other page/post -> normal, on-brand render.
 *
 * @package GasSafetyTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$obj     = get_queried_object();
$content = ( $obj && isset( $obj->post_content ) ) ? $obj->post_content : '';
$is_app  = $content && ( has_shortcode( $content, 'gas_app' ) || has_shortcode( $content, 'gas_bridge' ) );

if ( $is_app ) :

	// Case 1: full-bleed app host.
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;

elseif ( is_front_page() || is_home() ) :

	// Case 2: branded landing at the site root.
	gst_render_landing();

else :

	// Case 3: ordinary page/post.
	?>
	<main class="gs-page" style="max-width:860px;margin:32px auto;">
		<?php
		if ( have_posts() ) :
			while ( have_posts() ) :
				the_post();
				the_title( '<h1>', '</h1>' );
				the_content();
			endwhile;
		else :
			echo '<p>' . esc_html__( 'אין תוכן להצגה.', 'gas-safety-theme' ) . '</p>';
		endif;
		?>
	</main>
	<?php

endif;

get_footer();
