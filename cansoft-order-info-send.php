<?php
/**
 * Plugin Name:       Cansoft Order Info Send
 * Description:       Exposes REST API endpoints for the Cansoft Report System on the main site to fetch sales and order metrics on demand from WooCommerce or Ecwid.
 * Version:           1.0.1
 * Author:            Fazle Bari
 * Text Domain:       cansoft-order-info-send
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 *
 * @package Cansoft_Order_Info_Send
 */

defined('ABSPATH') || exit;

define('CANSOFT_ORDER_INFO_SEND_VERSION', '1.0.1');
define('CANSOFT_ORDER_INFO_SEND_PATH', plugin_dir_path(__FILE__));
define('CANSOFT_ORDER_INFO_SEND_URL', plugin_dir_url(__FILE__));

require_once CANSOFT_ORDER_INFO_SEND_PATH . 'includes/class-cansoft-order-info-sender.php';
require_once CANSOFT_ORDER_INFO_SEND_PATH . 'includes/class-cansoft-order-info-admin.php';

/**
 * Initialize plugin on plugins_loaded.
 */
function cansoft_order_info_send_init() {
    CANSOFT_Order_Info_Admin::instance();
}
add_action('plugins_loaded', 'cansoft_order_info_send_init');
