<?php
namespace WZ\Frontend;

use WZ\Data\CompetitionRepositoryInterface;
use WZ\Service\SchoolYearService;
use WZ\Service\TimelineService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Shortcodes {
    private $repo;
    private $years;
    private $timeline;

    public function __construct( CompetitionRepositoryInterface $repo, SchoolYearService $years ) {
        $this->repo     = $repo;
        $this->years    = $years;
        $this->timeline = new TimelineService( $repo, $years );
    }

    public function hooks() {
        add_shortcode( 'wettbewerbszeitleiste', array( $this, 'timelineShortcode' ) );
        add_shortcode( 'wettbewerbe_naechste', array( $this, 'upcomingShortcode' ) );
    }

    public function timelineShortcode( $atts ) {
        $atts = shortcode_atts(
            array(
                'schuljahr' => '',
                'fach'      => '',
            ),
            $atts,
            'wettbewerbszeitleiste'
        );

        $year = $this->resolveSchoolYear( $atts['schuljahr'] );
        if ( isset( $_GET['wz_schuljahr'] ) ) {
            $requested = $this->parseSchoolYear( sanitize_text_field( wp_unslash( $_GET['wz_schuljahr'] ) ) );
            if ( $requested ) {
                $year = $requested;
            }
        }

        $view = $this->timeline->build( $year );
        $this->enqueueAssets();

        $allowed_subjects = $this->resolveSubjectFilter( $atts['fach'], $view['subjects'] );
        if ( $allowed_subjects ) {
            $view['groups'] = array_values( array_filter( $view['groups'], static function ( $group ) use ( $allowed_subjects ) {
                return in_array( $group['id'], $allowed_subjects, true );
            } ) );
        }

        ob_start();
        $repo     = $this->repo;
        $timeline = $this->timeline;
        $years    = $this->timeline->availableSchoolYears();
        include WZ_DIR . 'templates/timeline.php';
        return ob_get_clean();
    }

    public function upcomingShortcode( $atts ) {
        $atts = shortcode_atts( array( 'anzahl' => 5 ), $atts, 'wettbewerbe_naechste' );
        $limit = max( 1, min( 50, (int) $atts['anzahl'] ) );
        $today = wp_date( 'Y-m-d' );
        $years = array( $this->years->currentStartYear(), $this->years->currentStartYear() + 1 );
        $events= array();
        foreach ( $years as $year ) {
            foreach ( $this->repo->getEvents( $year ) as $event ) {
                $effective_end = $event['end_date'] ?: $event['start_date'];
                if ( $effective_end >= $today ) {
                    $events[] = $event;
                }
            }
        }
        usort( $events, static function ( $a, $b ) { return strcmp( $a['start_date'], $b['start_date'] ); } );
        $events = array_slice( $events, 0, $limit );

        if ( ! $events ) {
            return '<p class="wz-empty">Derzeit sind keine kommenden Wettbewerbstermine hinterlegt.</p>';
        }

        $this->enqueueAssets();
        $types = array();
        foreach ( $this->repo->getEventTypes() as $type ) {
            $types[ $type['id'] ] = $type;
        }

        ob_start();
        echo '<div class="wz-upcoming">';
        foreach ( $events as $event ) {
            $type_name = isset( $types[ $event['event_type_id'] ] ) ? $types[ $event['event_type_id'] ]['name'] : '';
            echo '<article class="wz-upcoming__item">';
            echo '<time datetime="' . esc_attr( $event['start_date'] ) . '">' . esc_html( $this->formatDateRange( $event['start_date'], $event['end_date'] ) ) . '</time>';
            echo '<div><strong>' . esc_html( $event['title'] ) . '</strong><br><span>' . esc_html( $event['competition_name'] ) . ( $type_name ? ' · ' . esc_html( $type_name ) : '' ) . '</span></div>';
            echo '</article>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    public function iconSvg( $key ) {
        $paths = array(
            'calendar'     => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
            'clock'        => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'flag'         => '<path d="M5 21V4m0 1h11l-2 4 2 4H5"/>',
            'registration' => '<path d="M4 20v-2a4 4 0 0 1 4-4h4"/><circle cx="10" cy="7" r="4"/><path d="M16 19l2 2 4-5"/>',
            'deadline'     => '<circle cx="12" cy="12" r="9"/><path d="M12 6v7l4 2"/>',
            'trophy'       => '<path d="M8 4h8v4a4 4 0 0 1-8 0V4Z"/><path d="M8 6H4v1a4 4 0 0 0 4 4m8-5h4v1a4 4 0 0 1-4 4M12 12v5m-4 3h8"/>',
            'star'         => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3Z"/>',
            'info'         => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10h.01"/>',
        );
        $body = isset( $paths[ $key ] ) ? $paths[ $key ] : $paths['calendar'];
        return '<svg class="wz-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
    }

    public function eventPayload( array $event, array $type_map, array $subject_map ) {
        $subject_names = array();
        foreach ( $event['subject_ids'] as $id ) {
            if ( isset( $subject_map[ $id ] ) ) {
                $subject_names[] = $subject_map[ $id ];
            }
        }
        $type = isset( $type_map[ $event['event_type_id'] ] ) ? $type_map[ $event['event_type_id'] ] : array( 'name' => '', 'icon' => 'calendar', 'display_style' => 'standard' );
        return array(
            'title'          => $event['title'],
            'competition'    => $event['competition_name'],
            'eventType'      => $type['name'],
            'date'           => $this->formatDateRange( $event['start_date'], $event['end_date'] ),
            'subjects'       => implode( ', ', $subject_names ),
            'grades'         => $this->formatGrades( $event['grades'] ),
            'note'           => $event['note'],
            'url'            => esc_url_raw( $event['url'] ),
            'internalUrl'    => $event['internal_page_id'] ? esc_url_raw( get_permalink( $event['internal_page_id'] ) ) : '',
            'externalUrl'    => esc_url_raw( $event['external_url'] ),
            'displayStyle'   => $type['display_style'],
            'icon'           => $type['icon'],
        );
    }

    public function formatDateRange( $start, $end = null ) {
        $start_ts = strtotime( $start . ' 12:00:00' );
        if ( ! $end || $end === $start ) {
            return wp_date( 'd.m.Y', $start_ts );
        }
        return wp_date( 'd.m.Y', $start_ts ) . '–' . wp_date( 'd.m.Y', strtotime( $end . ' 12:00:00' ) );
    }

    public function formatGrades( array $grades ) {
        if ( ! $grades ) {
            return '–';
        }
        sort( $grades, SORT_NUMERIC );
        $ranges = array();
        $start = $prev = array_shift( $grades );
        foreach ( $grades as $grade ) {
            if ( $grade === $prev + 1 ) {
                $prev = $grade;
                continue;
            }
            $ranges[] = $start === $prev ? (string) $start : $start . '–' . $prev;
            $start = $prev = $grade;
        }
        $ranges[] = $start === $prev ? (string) $start : $start . '–' . $prev;
        return implode( ', ', $ranges );
    }

    private function enqueueAssets() {
        wp_enqueue_style(
            'wz-timeline',
            WZ_URL . 'assets/css/timeline.css',
            array(),
            WZ_VERSION
        );
        wp_enqueue_script(
            'wz-timeline',
            WZ_URL . 'assets/js/timeline.js',
            array(),
            WZ_VERSION,
            true
        );
    }

    private function resolveSchoolYear( $raw ) {
        $parsed = $this->parseSchoolYear( $raw );
        return $parsed ?: $this->years->currentStartYear();
    }

    private function parseSchoolYear( $raw ) {
        $raw = trim( (string) $raw );
        if ( preg_match( '/^(20\\d{2})(?:\\/\\d{2,4})?$/', $raw, $m ) ) {
            return (int) $m[1];
        }
        return 0;
    }

    private function resolveSubjectFilter( $raw, array $subjects ) {
        $raw = trim( (string) $raw );
        if ( '' === $raw ) {
            return array();
        }
        $tokens = array_map( 'trim', explode( ',', $raw ) );
        $found  = array();
        foreach ( $subjects as $subject ) {
            foreach ( $tokens as $token ) {
                if ( $token === $subject['id'] || sanitize_title( $token ) === sanitize_title( $subject['name'] ) ) {
                    $found[] = $subject['id'];
                }
            }
        }
        return array_values( array_unique( $found ) );
    }
}
