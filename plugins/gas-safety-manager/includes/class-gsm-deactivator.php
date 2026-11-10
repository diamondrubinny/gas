<?php
/**
 * Fired during plugin deactivation.
 *
 * NOTE: We deliberately do NOT drop tables here — deactivation should never
 * lose data. Table removal happens only in uninstall.php (full delete).
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Deactivator
 */
class GSM_Deactivator {

	/**
	 * Run on deactivation.
	 *
	 * We keep the custom roles' capabilities intact as well, so that
	 * re-activating restores the exact prior state. Only transient/scheduled
	 * items would be cleared here (none yet in stage 0).
	 */
	public static function deactivate() {
		// Nothing destructive in stage 0.
		// Placeholder for clearing scheduled events (wp_clear_scheduled_hook)
		// once we add cron jobs in later stages.
		do_action( 'gsm_deactivated' );
	}
}
