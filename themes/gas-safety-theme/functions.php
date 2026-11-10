<?php
/**
 * Gas Safety theme — functions.
 *
 * Minimal companion theme. Its job is to load the shared design tokens and
 * Rubik font, declare RTL support, and provide full-bleed page templates for
 * the plugin's UI. All data/logic lives in the plugin, not here.
 *
 * @package GasSafetyTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GST_VERSION', '1.2.3' );
define( 'GST_DIR', get_template_directory() );
define( 'GST_URI', get_template_directory_uri() );

/**
 * Theme setup.
 */
function gst_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );

	// RTL is core to this theme.
	add_theme_support( 'automatic-feed-links' );

	register_nav_menus(
		array(
			'primary' => __( 'Primary Menu', 'gas-safety-theme' ),
		)
	);
}
add_action( 'after_setup_theme', 'gst_setup' );

/**
 * Enqueue styles + fonts.
 */
function gst_assets() {
	// Rubik (Hebrew + Latin), bundled in assets/fonts — no Google Fonts request.
	wp_enqueue_style(
		'gst-rubik',
		GST_URI . '/assets/css/fonts.css',
		array(),
		GST_VERSION
	);

	// Shared design tokens (used by theme + plugin).
	wp_enqueue_style(
		'gst-tokens',
		GST_URI . '/assets/css/tokens.css',
		array( 'gst-rubik' ),
		GST_VERSION
	);

	// Theme base stylesheet.
	wp_enqueue_style(
		'gst-style',
		get_stylesheet_uri(),
		array( 'gst-tokens' ),
		GST_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'gst_assets' );

/**
 * Force RTL direction attribute on <html> for the app.
 *
 * @param string $output The language attributes.
 * @return string
 */
function gst_rtl_lang_attr( $output ) {
	// All theme text is Hebrew: declare it even when the site language is
	// set to something else, so screen readers use a Hebrew voice (WCAG 3.1.1).
	if ( ! preg_match( '/\blang="he\b/', $output ) ) {
		$output = preg_replace( '/\blang="[^"]*"\s*/', '', $output );
		$output = 'lang="he-IL" ' . trim( $output );
	}
	if ( false === strpos( $output, 'dir=' ) ) {
		$output = trim( $output ) . ' dir="rtl"';
	}
	return trim( $output );
}
add_filter( 'language_attributes', 'gst_rtl_lang_attr' );

/**
 * Add a body class on pages that host the plugin app (via a page template).
 *
 * @param array $classes Body classes.
 * @return array
 */
function gst_body_classes( $classes ) {
	if ( is_page_template( 'templates/template-gas-app.php' ) ) {
		$classes[] = 'gs-fullbleed';
	}
	return $classes;
}
add_filter( 'body_class', 'gst_body_classes' );

/* ------------------------------------------------------------------------ *
 * System entry URLs + branded landing
 * ------------------------------------------------------------------------ */

/**
 * URL of the plugin's management app page ([gas_app]).
 * Falls back to /gas-app if the plugin option is not set.
 *
 * @return string
 */
function gst_app_url() {
	$id = (int) get_option( 'gsm_app_page_id', 0 );
	if ( $id && get_post( $id ) ) {
		$url = get_permalink( $id );
		if ( $url ) {
			return $url;
		}
	}
	return home_url( '/gas-app' );
}

/**
 * URL of the public QR bridge page ([gas_bridge]).
 *
 * @return string
 */
function gst_bridge_url() {
	$id = (int) get_option( 'gsm_bridge_page_id', 0 );
	if ( $id && get_post( $id ) ) {
		$url = get_permalink( $id );
		if ( $url ) {
			return $url;
		}
	}
	return home_url( '/bridge' );
}

/**
 * URL of the "הצהרת נגישות" page (the page using template-accessibility.php).
 * Cached briefly; returns '' if no such page exists yet.
 *
 * @return string
 */
function gst_accessibility_url() {
	$cached = get_transient( 'gst_a11y_url' );
	if ( false !== $cached ) {
		return (string) $cached;
	}
	$q = new WP_Query(
		array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => 'templates/template-accessibility.php', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	$url = ( ! empty( $q->posts ) ) ? (string) get_permalink( (int) $q->posts[0] ) : '';
	// Cache a found URL for an hour; cache "not found" only briefly so the
	// link appears soon after the admin creates the page.
	set_transient( 'gst_a11y_url', $url, $url ? HOUR_IN_SECONDS : MINUTE_IN_SECONDS );
	return $url;
}

/**
 * Render a minimal legal footer bar (accessibility statement link).
 * Only outputs the link once an accessibility page exists.
 *
 * @return void
 */
function gst_render_footer_legal() {
	$a11y = gst_accessibility_url();
	if ( ! $a11y ) {
		return;
	}
	echo '<div class="gs-footer-legal" role="contentinfo"><nav aria-label="' . esc_attr__( 'קישורי נגישות ומדיניות', 'gas-safety-theme' ) . '">';
	echo '<a href="' . esc_url( $a11y ) . '">' . esc_html__( 'הצהרת נגישות', 'gas-safety-theme' ) . '</a>';
	echo '</nav></div>';
}

/**
 * Render the branded site-root landing (navy/gold hero + entry button).
 *
 * @return void
 */
function gst_render_landing() {
	// Server-side fallback instead of an inline onerror="" handler (CSP-safe).
	$has_logo = file_exists( GST_DIR . '/assets/img/logo.png' );
	$logo     = GST_URI . '/assets/img/logo.png';
	$app_url  = gst_app_url();
	?>
	<section class="gst-hero">
		<div class="gst-hero__card">
			<div class="gst-hero__logo">
				<?php if ( $has_logo ) : ?>
					<img src="<?php echo esc_url( $logo ); ?>" alt="">
				<?php else : ?>
					<span aria-hidden="true">🛡️</span>
				<?php endif; ?>
			</div>
			<h1 class="gst-hero__title"><?php esc_html_e( 'מערכת ניהול תחזוקה — מערכות גזים', 'gas-safety-theme' ); ?></h1>
			<p class="gst-hero__sub"><?php esc_html_e( 'יחידת הבטיחות · הטכניון', 'gas-safety-theme' ); ?></p>
			<div class="gst-hero__actions">
				<a class="gst-btn gst-btn--primary" href="<?php echo esc_url( $app_url ); ?>"><?php esc_html_e( 'כניסה למערכת', 'gas-safety-theme' ); ?></a>
			</div>
			<p class="gst-hero__note"><?php esc_html_e( 'סריקת קוד QR על ציוד פותחת אוטומטית את פרטי הפריט.', 'gas-safety-theme' ); ?></p>
		</div>
	</section>
	<?php
}

/* ------------------------------------------------------------------------ *
 * One-time: make the site root show the system
 * ------------------------------------------------------------------------ */

/**
 * The first time an admin loads wp-admin after this theme is active, if the
 * site is still on the default "latest posts" home AND the plugin's app page
 * exists, set that page as the static front page — so visiting the site root
 * opens the system instead of an empty "Hello world". Runs once (guarded by
 * an option) and is fully reversible under Settings → Reading.
 *
 * @return void
 */
function gst_maybe_set_front_page() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( get_option( 'gst_frontpage_setup_done' ) ) {
		return;
	}

	$app_id = (int) get_option( 'gsm_app_page_id', 0 );
	// Only act once we actually know the app page, and only if the admin
	// hasn't already chosen a static front page themselves.
	if ( $app_id && get_post( $app_id ) && 'posts' === get_option( 'show_on_front' ) ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $app_id );
		update_option( 'gst_frontpage_setup_done', 1 );
		set_transient( 'gst_frontpage_notice', 1, 60 );
	}
}
add_action( 'admin_init', 'gst_maybe_set_front_page' );

/**
 * Admin pointer: where the system lives, and a one-time note if we set the
 * front page. Shown on the Plugins/Dashboard/Themes screens only.
 *
 * @return void
 */
function gst_admin_entry_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$id     = $screen ? $screen->id : '';
	if ( ! in_array( $id, array( 'themes', 'dashboard', 'plugins' ), true ) ) {
		return;
	}

	$app_url = gst_app_url();

	if ( get_transient( 'gst_frontpage_notice' ) ) {
		delete_transient( 'gst_frontpage_notice' );
		echo '<div class="notice notice-success is-dismissible"><p><strong>Gas Safety</strong> — ';
		echo esc_html__( 'עמוד הבית הוגדר אוטומטית למערכת הגזים. לשינוי: הגדרות → קריאה.', 'gas-safety-theme' );
		echo ' <a href="' . esc_url( $app_url ) . '">' . esc_html__( 'פתח את המערכת', 'gas-safety-theme' ) . '</a></p></div>';
		return;
	}

	// If the plugin isn't active yet, the app page won't exist — hint that.
	if ( ! (int) get_option( 'gsm_app_page_id', 0 ) ) {
		echo '<div class="notice notice-warning"><p><strong>Gas Safety</strong> — ';
		echo esc_html__( 'ערכת הנושא פעילה, אך תוסף Gas Safety Manager אינו פעיל עדיין. הפעילו אותו כדי שעמודי המערכת (/gas-app, /bridge) ייווצרו.', 'gas-safety-theme' );
		echo '</p></div>';
	}
}
add_action( 'admin_notices', 'gst_admin_entry_notice' );
