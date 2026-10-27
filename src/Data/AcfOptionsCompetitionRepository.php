<?php
namespace WZ\Data;

use WZ\Admin\Registry;
use WZ\Service\SchoolYearService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class AcfOptionsCompetitionRepository implements CompetitionRepositoryInterface {
    private $registry;
    private $store;
    private $years;

    public function __construct( Registry $registry, AcfOptionsStore $store, SchoolYearService $years ) {
        $this->registry = $registry;
        $this->store    = $store;
        $this->years    = $years;
    }

    public function getCompetition( $id ) {
        $id = sanitize_key( $id );
        if ( ! $this->registry->contains( $id ) ) {
            return null;
        }

        $post_id = $this->registry->post_id( $id );
        $data    = array(
            'id'               => $id,
            'name'             => (string) $this->store->get( 'name', $post_id, '' ),
            'short_name'       => (string) $this->store->get( 'short_name', $post_id, '' ),
            'subject_ids'      => $this->stringArray( $this->store->get( 'subjects', $post_id, array() ) ),
            'grades'           => $this->gradeArray( $this->store->get( 'grades', $post_id, array() ) ),
            'description'      => (string) $this->store->get( 'description', $post_id, '' ),
            'organizer'        => (string) $this->store->get( 'organizer', $post_id, '' ),
            'logo_id'          => (int) $this->store->get( 'logo', $post_id, 0 ),
            'internal_page_id' => (int) $this->store->get( 'internal_page', $post_id, 0 ),
            'external_url'     => esc_url_raw( (string) $this->store->get( 'external_url', $post_id, '' ) ),
            'archived'         => $this->registry->isArchived( $id ),
            'archived_at'      => $this->registry->archivedAt( $id ),
            'school_years'     => array(),
        );

        $rows = $this->store->get( 'school_years', $post_id, array() );
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $start_year = isset( $row['start_year'] ) ? (int) $row['start_year'] : 0;
                if ( ! $start_year ) {
                    continue;
                }
                $data['school_years'][] = array(
                    'start_year' => $start_year,
                    'label'      => $this->years->label( $start_year ),
                    'events'     => $this->normalizeEvents( isset( $row['events'] ) ? $row['events'] : array(), $data, $start_year ),
                );
            }
        }

        usort(
            $data['school_years'],
            static function ( $a, $b ) {
                return $b['start_year'] <=> $a['start_year'];
            }
        );

        return $data;
    }

    public function getCompetitions( $include_archived = false ) {
        $items = array();
        foreach ( $this->registry->all() as $id ) {
            $competition = $this->getCompetition( $id );
            if ( $competition && ( $include_archived || empty( $competition['archived'] ) ) ) {
                $items[] = $competition;
            }
        }

        usort(
            $items,
            static function ( $a, $b ) {
                return strcasecmp( $a['name'], $b['name'] );
            }
        );

        return $items;
    }

    public function getSubjects() {
        $rows = $this->store->get( 'subjects', 'wz_subjects', array() );
        $out  = array();
        if ( ! is_array( $rows ) ) {
            return $out;
        }
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || empty( $row['subject_id'] ) || empty( $row['name'] ) ) {
                continue;
            }
            $out[] = array(
                'id'   => sanitize_key( $row['subject_id'] ),
                'name' => sanitize_text_field( $row['name'] ),
            );
        }
        return $out;
    }

    public function getEventTypes() {
        $rows = $this->store->get( 'event_types', 'wz_event_types', array() );
        $out  = array();
        if ( ! is_array( $rows ) ) {
            return $out;
        }
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || empty( $row['event_type_id'] ) || empty( $row['name'] ) ) {
                continue;
            }
            $out[] = array(
                'id'            => sanitize_key( $row['event_type_id'] ),
                'name'          => sanitize_text_field( $row['name'] ),
                'icon'          => isset( $row['icon'] ) ? sanitize_key( $row['icon'] ) : 'calendar',
                'display_style' => isset( $row['display_style'] ) ? sanitize_key( $row['display_style'] ) : 'standard',
            );
        }
        return $out;
    }

    public function getEvents( $school_year = null, array $filters = array() ) {
        $school_year = null === $school_year ? $this->years->currentStartYear() : (int) $school_year;
        $events      = array();

        foreach ( $this->getCompetitions() as $competition ) {
            foreach ( $competition['school_years'] as $year ) {
                if ( (int) $year['start_year'] !== $school_year ) {
                    continue;
                }
                foreach ( $year['events'] as $event ) {
                    if ( $this->matchesFilters( $event, $competition, $filters ) ) {
                        $events[] = $event;
                    }
                }
            }
        }

        usort(
            $events,
            static function ( $a, $b ) {
                $cmp = strcmp( $a['start_date'], $b['start_date'] );
                return 0 !== $cmp ? $cmp : strcasecmp( $a['title'], $b['title'] );
            }
        );

        return $events;
    }

    public function setCompetitionName( $id, $name ) {
        return $this->store->update( 'field_wz_comp_name', sanitize_text_field( $name ), $this->registry->post_id( $id ) );
    }

    public function archiveCompetition( $id ) {
        return $this->registry->archive( $id );
    }

    public function restoreCompetition( $id ) {
        return $this->registry->restore( $id );
    }

    public function deleteCompetition( $id ) {
        $id = sanitize_key( $id );
        if ( ! $id || ! $this->registry->contains( $id ) ) {
            return false;
        }

        if ( ! $this->store->deletePostId( $this->registry->post_id( $id ) ) ) {
            return false;
        }

        return $this->registry->remove( $id );
    }

    public function replaceSubjects( array $subjects ) {
        $rows = array();
        foreach ( $subjects as $subject ) {
            if ( ! is_array( $subject ) || empty( $subject['name'] ) ) {
                continue;
            }
            $rows[] = array(
                'field_wz_subject_id'   => ! empty( $subject['id'] ) ? sanitize_key( $subject['id'] ) : \WZ\Support\Ids::make( 'subj' ),
                'field_wz_subject_name' => sanitize_text_field( $subject['name'] ),
            );
        }
        return $this->store->update( 'field_wz_subjects_repeater', $rows, 'wz_subjects' );
    }

    public function replaceEventTypes( array $event_types ) {
        $rows = array();
        $allowed_icons  = array( 'calendar', 'clock', 'flag', 'registration', 'deadline', 'trophy', 'star', 'info' );
        $allowed_styles = array( 'standard', 'accent', 'outline', 'muted' );
        foreach ( $event_types as $type ) {
            if ( ! is_array( $type ) || empty( $type['name'] ) ) {
                continue;
            }
            $icon  = isset( $type['icon'] ) ? sanitize_key( $type['icon'] ) : 'calendar';
            $style = isset( $type['display_style'] ) ? sanitize_key( $type['display_style'] ) : 'standard';
            $rows[] = array(
                'field_wz_event_type_id'    => ! empty( $type['id'] ) ? sanitize_key( $type['id'] ) : \WZ\Support\Ids::make( 'etype' ),
                'field_wz_event_type_name'  => sanitize_text_field( $type['name'] ),
                'field_wz_event_type_icon'  => in_array( $icon, $allowed_icons, true ) ? $icon : 'calendar',
                'field_wz_event_type_style' => in_array( $style, $allowed_styles, true ) ? $style : 'standard',
            );
        }
        return $this->store->update( 'field_wz_event_types_repeater', $rows, 'wz_event_types' );
    }

    public function upsertCompetition( array $competition ) {
        $id = ! empty( $competition['id'] ) ? sanitize_key( $competition['id'] ) : \WZ\Support\Ids::make( 'cmp' );
        if ( ! $id ) {
            return false;
        }
        $this->registry->add( $id );
        $post_id = $this->registry->post_id( $id );

        $this->store->update( 'field_wz_comp_name', sanitize_text_field( isset( $competition['name'] ) ? $competition['name'] : '' ), $post_id );
        $this->store->update( 'field_wz_comp_short_name', sanitize_text_field( isset( $competition['short_name'] ) ? $competition['short_name'] : '' ), $post_id );
        $this->store->update( 'field_wz_comp_subjects', $this->stringArray( isset( $competition['subject_ids'] ) ? $competition['subject_ids'] : array() ), $post_id );
        $this->store->update( 'field_wz_comp_grades', $this->gradeArray( isset( $competition['grades'] ) ? $competition['grades'] : array() ), $post_id );
        $this->store->update( 'field_wz_comp_description', sanitize_textarea_field( isset( $competition['description'] ) ? $competition['description'] : '' ), $post_id );
        $this->store->update( 'field_wz_comp_organizer', sanitize_text_field( isset( $competition['organizer'] ) ? $competition['organizer'] : '' ), $post_id );
        $logo_id = isset( $competition['logo_id'] ) ? (int) $competition['logo_id'] : 0;
        if ( $logo_id && 'attachment' !== get_post_type( $logo_id ) ) {
            $logo_id = 0;
        }
        $this->store->update( 'field_wz_comp_logo', $logo_id, $post_id );
        $internal_page = isset( $competition['internal_page_id'] ) ? (int) $competition['internal_page_id'] : 0;
        if ( $internal_page && 'page' !== get_post_type( $internal_page ) ) {
            $internal_page = 0;
        }
        $this->store->update( 'field_wz_comp_internal_page', $internal_page, $post_id );
        $this->store->update( 'field_wz_comp_external_url', esc_url_raw( isset( $competition['external_url'] ) ? $competition['external_url'] : '' ), $post_id );

        $year_rows = array();
        if ( ! empty( $competition['school_years'] ) && is_array( $competition['school_years'] ) ) {
            foreach ( $competition['school_years'] as $school_year ) {
                if ( ! is_array( $school_year ) || empty( $school_year['start_year'] ) ) {
                    continue;
                }
                $event_rows = array();
                if ( ! empty( $school_year['events'] ) && is_array( $school_year['events'] ) ) {
                    foreach ( $school_year['events'] as $event ) {
                        if ( ! is_array( $event ) || empty( $event['title'] ) || empty( $event['start_date'] ) ) {
                            continue;
                        }
                        $event_rows[] = array(
                            'field_wz_event_id'     => ! empty( $event['id'] ) ? sanitize_key( $event['id'] ) : \WZ\Support\Ids::make( 'evt' ),
                            'field_wz_event_title'  => sanitize_text_field( $event['title'] ),
                            'field_wz_event_type'   => ! empty( $event['event_type_id'] ) ? sanitize_key( $event['event_type_id'] ) : '',
                            'field_wz_event_start'  => $this->isoDate( $event['start_date'] ),
                            'field_wz_event_end'    => ! empty( $event['end_date'] ) ? $this->isoDate( $event['end_date'] ) : '',
                            'field_wz_event_grades' => ( isset( $event['grade_source'] ) && 'competition' === $event['grade_source'] ) ? array() : $this->gradeArray( isset( $event['grades'] ) ? $event['grades'] : array() ),
                            'field_wz_event_note'   => sanitize_textarea_field( isset( $event['note'] ) ? $event['note'] : '' ),
                            'field_wz_event_url'    => esc_url_raw( isset( $event['url'] ) ? $event['url'] : '' ),
                        );
                    }
                }
                $year_rows[] = array(
                    'field_wz_school_year_start' => (int) $school_year['start_year'],
                    'field_wz_events'            => $event_rows,
                );
            }
        }
        $this->store->update( 'field_wz_school_years', $year_rows, $post_id );

        if ( array_key_exists( 'archived', $competition ) ) {
            if ( $competition['archived'] ) {
                $this->registry->archive( $id );
            } else {
                $this->registry->restore( $id );
            }
        }

        return $id;
    }

    private function normalizeEvents( $rows, array $competition, $school_year ) {
        $out = array();
        if ( ! is_array( $rows ) ) {
            return $out;
        }

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || empty( $row['title'] ) || empty( $row['start_date'] ) ) {
                continue;
            }

            $own_grades = $this->gradeArray( isset( $row['grades'] ) ? $row['grades'] : array() );
            $effective  = $own_grades ? $own_grades : $competition['grades'];
            $end_date   = ! empty( $row['end_date'] ) ? $this->isoDate( $row['end_date'] ) : null;
            $start_date = $this->isoDate( $row['start_date'] );

            $out[] = array(
                'id'              => ! empty( $row['event_id'] ) ? sanitize_key( $row['event_id'] ) : '',
                'competition_id'  => $competition['id'],
                'competition_name'=> $competition['name'],
                'subject_ids'     => $competition['subject_ids'],
                'school_year'     => (int) $school_year,
                'title'           => sanitize_text_field( $row['title'] ),
                'event_type_id'   => ! empty( $row['event_type'] ) ? sanitize_key( $row['event_type'] ) : '',
                'start_date'      => $start_date,
                'end_date'        => $end_date,
                'is_range'        => $end_date && $end_date > $start_date,
                'grades'          => $effective,
                'grade_source'    => $own_grades ? 'event' : 'competition',
                'note'            => isset( $row['note'] ) ? sanitize_textarea_field( $row['note'] ) : '',
                'url'             => isset( $row['url'] ) ? esc_url_raw( $row['url'] ) : '',
                'internal_page_id'=> $competition['internal_page_id'],
                'external_url'    => $competition['external_url'],
            );
        }

        return $out;
    }

    private function isoDate( $value ) {
        $value = trim( (string) $value );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            return $value;
        }
        if ( preg_match( '/^\d{8}$/', $value ) ) {
            return substr( $value, 0, 4 ) . '-' . substr( $value, 4, 2 ) . '-' . substr( $value, 6, 2 );
        }
        $timestamp = strtotime( $value );
        return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : '';
    }

    private function stringArray( $value ) {
        if ( ! is_array( $value ) ) {
            $value = empty( $value ) ? array() : array( $value );
        }
        return array_values( array_unique( array_filter( array_map( 'sanitize_key', $value ) ) ) );
    }

    private function gradeArray( $value ) {
        if ( ! is_array( $value ) ) {
            $value = empty( $value ) ? array() : array( $value );
        }
        $grades = array_map( 'intval', $value );
        $grades = array_values( array_unique( array_filter( $grades, static function ( $g ) { return $g >= 5 && $g <= 12; } ) ) );
        sort( $grades, SORT_NUMERIC );
        return $grades;
    }

    private function matchesFilters( array $event, array $competition, array $filters ) {
        if ( ! empty( $filters['subjects'] ) ) {
            $wanted = $this->stringArray( $filters['subjects'] );
            if ( ! array_intersect( $wanted, $competition['subject_ids'] ) ) {
                return false;
            }
        }
        if ( ! empty( $filters['grades'] ) ) {
            $wanted = $this->gradeArray( $filters['grades'] );
            if ( ! array_intersect( $wanted, $event['grades'] ) ) {
                return false;
            }
        }
        if ( ! empty( $filters['event_types'] ) ) {
            $wanted = $this->stringArray( $filters['event_types'] );
            if ( ! in_array( $event['event_type_id'], $wanted, true ) ) {
                return false;
            }
        }
        return true;
    }
}
