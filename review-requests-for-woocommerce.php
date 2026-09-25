<?php
/**
 * Plugin Name: Review Requests for WooCommerce
 * Plugin URI:  https://relit.ca/review-requests-for-woocommerce
 * Description: Asks customers for a Google review after their order is completed. Per-order control, automatic follow-ups, one-click unsubscribe, and a suppression list that outlives any single order.
 * Version:     1.0.0
 * Author:      Relit
 * Author URI:  https://relit.ca
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: review-requests-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 11.0
 */

defined( 'ABSPATH' ) || exit;

define( 'RRFW_VERSION', '1.0.0' );
define( 'RRFW_FILE', __FILE__ );
define( 'RRFW_PATH', plugin_dir_path( __FILE__ ) );

require_once RRFW_PATH . 'includes/class-rrfw-install.php';
require_once RRFW_PATH . 'includes/class-rrfw-migrate.php';
require_once RRFW_PATH . 'includes/class-rrfw-settings.php';
require_once RRFW_PATH . 'includes/class-rrfw-tokens.php';
require_once RRFW_PATH . 'includes/class-rrfw-suppression.php';
require_once RRFW_PATH . 'includes/class-rrfw-scheduler.php';
require_once RRFW_PATH . 'includes/class-rrfw-endpoints.php';
require_once RRFW_PATH . 'includes/class-rrfw-admin.php';
require_once RRFW_PATH . 'includes/class-rrfw-orders-list.php';
require_once RRFW_PATH . 'includes/class-rrfw-report.php';

register_activation_hook( __FILE__, array( 'RRFW_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RRFW_Install', 'deactivate' ) );

/**
 * Declare HPOS compatibility. Both sites run legacy post storage today, but WooCommerce 11
 * pushes hard toward custom order tables and this plugin is written to work either way.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', RRFW_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'review-requests-for-woocommerce', false, dirname( plugin_basename( RRFW_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' .
						esc_html__( 'Review Requests for WooCommerce requires WooCommerce to be active.', 'review-requests-for-woocommerce' ) .
						'</p></div>';
				}
			);
			return;
		}

		RRFW_Install::maybe_upgrade();
		RRFW_Migrate::maybe_run();
		RRFW_Migrate::init();
		RRFW_Scheduler::init();
		RRFW_Endpoints::init();
		RRFW_Admin::init();
		RRFW_Orders_List::init();
	}
);

/**
 * Register the review-request email with WooCommerce so it inherits the standard
 * template wrapper, the Settings > Emails UI, and the child theme override path.
 */
add_filter(
	'woocommerce_email_classes',
	function ( $emails ) {
		require_once RRFW_PATH . 'includes/class-rrfw-email.php';
		$emails['RRFW_Email_Review_Request'] = new RRFW_Email_Review_Request();
		return $emails;
	}
);

/**
 * Let WooCommerce find this plugin's email templates, so child themes can override them
 * at woocommerce/emails/customer-review-request.php exactly like core templates.
 */
add_filter(
	'woocommerce_locate_template',
	function ( $template, $template_name, $template_path ) {
		if ( ! str_contains( $template_name, 'customer-review-request' ) ) {
			return $template;
		}
		if ( ! file_exists( $template ) ) {
			$fallback = RRFW_PATH . 'templates/' . $template_name;
			if ( file_exists( $fallback ) ) {
				return $fallback;
			}
		}
		return $template;
	},
	10,
	3
);
