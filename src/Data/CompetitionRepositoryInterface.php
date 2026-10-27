<?php
namespace WZ\Data;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface CompetitionRepositoryInterface {
    public function getCompetition( $id );
    public function getCompetitions( $include_archived = false );
    public function getSubjects();
    public function getEventTypes();
    public function getEvents( $school_year = null, array $filters = array() );
    public function replaceSubjects( array $subjects );
    public function replaceEventTypes( array $event_types );
    public function upsertCompetition( array $competition );
    public function archiveCompetition( $id );
    public function restoreCompetition( $id );
    public function deleteCompetition( $id );
}
