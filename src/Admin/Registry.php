<?php
namespace WZ\Admin;

use WZ\Support\Ids;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Technische Registry aller Wettbewerbe.
 *
 * Die eigentlichen Wettbewerbsdaten liegen in ACF Options. Die Registry hält
 * ausschließlich stabile IDs sowie den reversiblen Archivstatus.
 */
final class Registry {
    const OPTION          = 'wz_competition_registry';
    const ARCHIVED_OPTION = 'wz_competition_archived';

    public function all() {
        $ids = get_option( self::OPTION, array() );
        if ( ! is_array( $ids ) ) {
            return array();
        }

        return array_values( array_unique( array_filter( array_map( 'sanitize_key', $ids ) ) ) );
    }

    public function active() {
        return array_values(
            array_filter(
                $this->all(),
                function ( $id ) {
                    return ! $this->isArchived( $id );
                }
            )
        );
    }

    public function archived() {
        return array_values(
            array_filter(
                $this->all(),
                function ( $id ) {
                    return $this->isArchived( $id );
                }
            )
        );
    }

    public function create() {
        $ids = $this->all();
        do {
            $id = Ids::make( 'cmp' );
        } while ( in_array( $id, $ids, true ) );

        $ids[] = $id;
        update_option( self::OPTION, $ids, false );
        $this->restore( $id );
        return $id;
    }

    public function add( $id ) {
        $id = sanitize_key( $id );
        if ( ! $id ) {
            return false;
        }
        $ids = $this->all();
        if ( ! in_array( $id, $ids, true ) ) {
            $ids[] = $id;
            update_option( self::OPTION, $ids, false );
        }
        return true;
    }

    public function remove( $id ) {
        $id = sanitize_key( $id );
        if ( ! $id ) {
            return false;
        }

        $ids     = $this->all();
        $new_ids = array_values( array_diff( $ids, array( $id ) ) );
        if ( count( $new_ids ) !== count( $ids ) ) {
            update_option( self::OPTION, $new_ids, false );
        }

        $archived = $this->archivedMap();
        if ( isset( $archived[ $id ] ) ) {
            unset( $archived[ $id ] );
            update_option( self::ARCHIVED_OPTION, $archived, false );
        }

        return true;
    }

    public function contains( $id ) {
        return in_array( sanitize_key( $id ), $this->all(), true );
    }

    public function archive( $id ) {
        $id = sanitize_key( $id );
        if ( ! $id || ! $this->contains( $id ) ) {
            return false;
        }

        $archived        = $this->archivedMap();
        $archived[ $id ] = current_time( 'mysql' );
        update_option( self::ARCHIVED_OPTION, $archived, false );
        return true;
    }

    public function restore( $id ) {
        $id = sanitize_key( $id );
        if ( ! $id ) {
            return false;
        }

        $archived = $this->archivedMap();
        if ( isset( $archived[ $id ] ) ) {
            unset( $archived[ $id ] );
            update_option( self::ARCHIVED_OPTION, $archived, false );
        }
        return true;
    }

    public function isArchived( $id ) {
        $id       = sanitize_key( $id );
        $archived = $this->archivedMap();
        return $id && isset( $archived[ $id ] );
    }

    public function archivedAt( $id ) {
        $id       = sanitize_key( $id );
        $archived = $this->archivedMap();
        return isset( $archived[ $id ] ) ? (string) $archived[ $id ] : null;
    }

    public function post_id( $id ) {
        return 'wz_' . sanitize_key( $id );
    }

    public function menu_slug( $id ) {
        return 'wz-competition-' . sanitize_key( $id );
    }

    private function archivedMap() {
        $archived = get_option( self::ARCHIVED_OPTION, array() );
        if ( ! is_array( $archived ) ) {
            return array();
        }

        $out = array();
        foreach ( $archived as $id => $date ) {
            $id = sanitize_key( $id );
            if ( $id ) {
                $out[ $id ] = sanitize_text_field( (string) $date );
            }
        }
        return $out;
    }
}
