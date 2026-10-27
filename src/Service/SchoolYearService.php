<?php
namespace WZ\Service;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SchoolYearService {
    public function currentStartYear( $timestamp = null ) {
        $timezone = wp_timezone();
        $date     = $timestamp ? ( new \DateTimeImmutable( '@' . (int) $timestamp ) )->setTimezone( $timezone ) : new \DateTimeImmutable( 'now', $timezone );
        $year     = (int) $date->format( 'Y' );
        $month    = (int) $date->format( 'n' );

        return $month >= 8 ? $year : $year - 1;
    }

    public function label( $start_year ) {
        $start_year = (int) $start_year;
        return sprintf( '%d/%02d', $start_year, ( $start_year + 1 ) % 100 );
    }

    public function bounds( $start_year ) {
        $timezone = wp_timezone();
        $start    = new \DateTimeImmutable( sprintf( '%04d-08-01 00:00:00', (int) $start_year ), $timezone );
        $end      = new \DateTimeImmutable( sprintf( '%04d-07-31 23:59:59', (int) $start_year + 1 ), $timezone );

        return array( 'start' => $start, 'end' => $end );
    }
}
