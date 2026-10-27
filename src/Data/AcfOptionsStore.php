<?php
namespace WZ\Data;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Niedrigstufiger ACF-Speicheradapter.
 * Direkte Datenzugriffe auf ACF werden in dieser Klasse gekapselt.
 */
final class AcfOptionsStore {
    public function available() {
        return function_exists( 'get_field' ) && function_exists( 'update_field' );
    }

    public function get( $selector, $post_id, $default = null ) {
        if ( ! $this->available() ) {
            return $default;
        }

        $value = get_field( $selector, $post_id );
        return ( false === $value || null === $value ) ? $default : $value;
    }

    public function update( $selector, $value, $post_id ) {
        if ( ! $this->available() ) {
            return false;
        }

        return (bool) update_field( $selector, $value, $post_id );
    }

    /**
     * Entfernt sämtliche von ACF für einen benutzerdefinierten Options-post_id
     * gespeicherten Werte und Feldreferenzen. Verknüpfte Medien oder Seiten
     * werden bewusst nicht gelöscht.
     */
    public function deletePostId( $post_id ) {
        global $wpdb;

        $post_id = sanitize_key( $post_id );
        if ( ! $post_id || ! isset( $wpdb->options ) ) {
            return false;
        }

        $value_prefix     = $wpdb->esc_like( $post_id . '_' ) . '%';
        $reference_prefix = $wpdb->esc_like( '_' . $post_id . '_' ) . '%';

        $option_names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s OR option_name = %s OR option_name LIKE %s OR option_name LIKE %s",
                $post_id,
                '_' . $post_id,
                $value_prefix,
                $reference_prefix
            )
        );

        if ( ! is_array( $option_names ) ) {
            return false;
        }

        foreach ( $option_names as $option_name ) {
            delete_option( $option_name );
        }

        return true;
    }
}
