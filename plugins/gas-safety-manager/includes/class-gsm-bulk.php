<?php
/**
 * Bulk-import mode.
 *
 * While active, per-row side effects of the service layer are muted:
 *   - outgoing webhooks (one HTTP call per created row),
 *   - urgent-fault alerts (email / WhatsApp to managers for every old
 *     urgent fault being imported),
 *   - one audit entry per row.
 * The importer writes a single summary audit entry instead. Validation,
 * sanitization and data rules are NOT affected.
 *
 * Nested start/end pairs are counted, so a bulk import inside another one
 * stays muted until the outermost one ends.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Bulk
 */
class GSM_Bulk {

	/**
	 * Nesting depth.
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Enter bulk mode.
	 */
	public static function start() {
		self::$depth++;
	}

	/**
	 * Leave bulk mode.
	 */
	public static function end() {
		self::$depth = max( 0, self::$depth - 1 );
	}

	/**
	 * Is a bulk import running?
	 *
	 * @return bool
	 */
	public static function active() {
		return self::$depth > 0;
	}
}
