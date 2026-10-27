<?php
/**
 * Plugin Name: Wettbewerbszeitleiste
 * Description: Verwaltet schulische Wettbewerbe mit ACF PRO Options Pages und stellt eine gekapselte Datenbasis für Zeitleisten bereit.
 * Version: 0.2.4
 * Author: Heinrich-Hertz-Gymnasium Berlin
 * Plugin URI: https://github.com/hhgym/Wettbewerbszeitleiste
 * Update URI: https://github.com/hhgym/Wettbewerbszeitleiste
 * Requires at least: 6.8
 * Requires PHP: 7.4
 * Text Domain: wettbewerbszeitleiste
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WZ_VERSION', '0.2.4' );
define( 'WZ_FILE', __FILE__ );
define( 'WZ_DIR', plugin_dir_path( __FILE__ ) );
define( 'WZ_URL', plugin_dir_url( __FILE__ ) );

require_once WZ_DIR . 'src/Support/Autoloader.php';
\WZ\Support\Autoloader::register();

register_activation_hook( __FILE__, array( '\\WZ\\Plugin', 'activate' ) );

add_action(
    'plugins_loaded',
    static function () {
        \WZ\Plugin::instance()->boot();
    }
);
