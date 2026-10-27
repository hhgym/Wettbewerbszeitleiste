<?php
namespace WZ\Service;

use WZ\Data\CompetitionRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class TimelineService {
    private $repo;
    private $years;

    public function __construct( CompetitionRepositoryInterface $repo, SchoolYearService $years ) {
        $this->repo  = $repo;
        $this->years = $years;
    }

    public function build( $school_year ) {
        $school_year = (int) $school_year;
        $subjects    = $this->repo->getSubjects();
        $types       = $this->repo->getEventTypes();
        $competitions= $this->repo->getCompetitions();
        $events      = $this->repo->getEvents( $school_year );

        $subject_map = array();
        foreach ( $subjects as $subject ) {
            $subject_map[ $subject['id'] ] = array(
                'id'           => $subject['id'],
                'name'         => $subject['name'],
                'competitions' => array(),
            );
        }

        $events_by_comp = array();
        foreach ( $events as $event ) {
            $events_by_comp[ $event['competition_id'] ][] = $event;
        }

        foreach ( $competitions as $competition ) {
            if ( empty( $events_by_comp[ $competition['id'] ] ) ) {
                continue;
            }
            foreach ( $competition['subject_ids'] as $subject_id ) {
                if ( ! isset( $subject_map[ $subject_id ] ) ) {
                    continue;
                }
                $row           = $competition;
                $row['events'] = $events_by_comp[ $competition['id'] ];
                $subject_map[ $subject_id ]['competitions'][] = $row;
            }
        }

        $groups = array_values(
            array_filter(
                $subject_map,
                static function ( $group ) {
                    return ! empty( $group['competitions'] );
                }
            )
        );

        return array(
            'school_year' => $school_year,
            'label'       => $this->years->label( $school_year ),
            'subjects'    => $subjects,
            'event_types' => $types,
            'groups'      => $groups,
            'months'      => $this->months( $school_year ),
            'today_pct'   => $this->todayPercent( $school_year ),
        );
    }

    public function eventPosition( $start_date, $end_date, $school_year ) {
        $bounds = $this->years->bounds( $school_year );
        $start  = new \DateTimeImmutable( $start_date . ' 00:00:00', wp_timezone() );
        $end    = $end_date ? new \DateTimeImmutable( $end_date . ' 23:59:59', wp_timezone() ) : $start;
        $total  = max( 1, $bounds['end']->getTimestamp() - $bounds['start']->getTimestamp() );

        $left = ( $start->getTimestamp() - $bounds['start']->getTimestamp() ) / $total * 100;
        $right= ( $end->getTimestamp() - $bounds['start']->getTimestamp() ) / $total * 100;

        $left  = max( 0, min( 100, $left ) );
        $right = max( $left, min( 100, $right ) );

        return array(
            'left'  => $left,
            'width' => max( 0.45, $right - $left ),
        );
    }

    public function availableSchoolYears() {
        $years = array();
        foreach ( $this->repo->getCompetitions() as $competition ) {
            foreach ( $competition['school_years'] as $year ) {
                $years[ (int) $year['start_year'] ] = $this->years->label( $year['start_year'] );
            }
        }
        krsort( $years, SORT_NUMERIC );
        return $years;
    }

    private function months( $school_year ) {
        $bounds = $this->years->bounds( $school_year );
        $total  = $bounds['end']->getTimestamp() - $bounds['start']->getTimestamp();
        $months = array();
        $cursor = $bounds['start'];
        $names  = array( 1 => 'Jan', 2 => 'Feb', 3 => 'Mär', 4 => 'Apr', 5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Dez' );

        for ( $i = 0; $i < 12; $i++ ) {
            $next  = $cursor->modify( 'first day of next month' );
            $left  = ( $cursor->getTimestamp() - $bounds['start']->getTimestamp() ) / $total * 100;
            $width = ( $next->getTimestamp() - $cursor->getTimestamp() ) / $total * 100;
            $month = (int) $cursor->format( 'n' );
            $months[] = array(
                'label' => $names[ $month ],
                'left'  => $left,
                'width' => $width,
            );
            $cursor = $next;
        }
        return $months;
    }

    private function todayPercent( $school_year ) {
        if ( $this->years->currentStartYear() !== (int) $school_year ) {
            return null;
        }
        $bounds = $this->years->bounds( $school_year );
        $today  = new \DateTimeImmutable( 'today', wp_timezone() );
        $total  = $bounds['end']->getTimestamp() - $bounds['start']->getTimestamp();
        $pct    = ( $today->getTimestamp() - $bounds['start']->getTimestamp() ) / $total * 100;
        return max( 0, min( 100, $pct ) );
    }
}
