<?php
namespace WZ\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Autoloader {
    public static function register() {
        spl_autoload_register( array( __CLASS__, 'autoload' ) );
    }

    public static function autoload( $class ) {
        $prefix = 'WZ\\';
        if ( 0 !== strpos( $class, $prefix ) ) {
            return;
        }

        $relative = substr( $class, strlen( $prefix ) );
        $file     = WZ_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

        if ( is_readable( $file ) ) {
            require_once $file;
        }
    }
}
