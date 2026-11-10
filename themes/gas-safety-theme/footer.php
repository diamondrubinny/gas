<?php
/**
 * Footer.
 *
 * @package GasSafetyTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php
if ( function_exists( 'gst_render_footer_legal' ) ) {
	gst_render_footer_legal();
}
?>
<?php wp_footer(); ?>
</body>
</html>
