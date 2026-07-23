<?php
/**
 * Plugin Name:       Cansoft Order Info Send
 * Description:       Sends WooCommerce order data to the main Cansoft site as Earning transactions and exposes REST API endpoint for sales reporting.
 * Version:           1.0.0
 * Author:            Fazle Bari
 * Text Domain:       cansoft-order-info-send
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 5.0
 *
 * @package Cansoft_Order_Info_Send
 */

defined('ABSPATH') || exit;

define('CANSOFT_ORDER_INFO_SEND_VERSION', '1.0.0');
define('CANSOFT_ORDER_INFO_SEND_PATH', plugin_dir_path(__FILE__));
define('CANSOFT_ORDER_INFO_SEND_URL', plugin_dir_url(__FILE__));

require_once CANSOFT_ORDER_INFO_SEND_PATH . 'includes/class-cansoft-order-info-sender.php';
require_once CANSOFT_ORDER_INFO_SEND_PATH . 'includes/class-cansoft-order-info-admin.php';

/**
 * Initialize plugin after WooCommerce is loaded.
 */
function cansoft_order_info_send_init() {
    if (!class_exists('WooCommerce')) {
        return;
    }
    CANSOFT_Order_Info_Admin::instance();
}
add_action('plugins_loaded', 'cansoft_order_info_send_init');
