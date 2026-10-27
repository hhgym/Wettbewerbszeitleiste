<?php
namespace WZ\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Ids {
    public static function make( $prefix ) {
        try {
            $suffix = bin2hex( random_bytes( 6 ) );
        } catch ( \Exception $e ) {
            $suffix = str_replace( '-', '', wp_generate_uuid4() );
            $suffix = substr( $suffix, 0, 12 );
        }

        return sanitize_key( $prefix . '_' . $suffix );
    }
}
