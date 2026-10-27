<?php
namespace WZ\Admin;

use WZ\Data\AcfOptionsCompetitionRepository;
use WZ\Data\AcfOptionsStore;
use WZ\Support\Ids;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Fields {
    private $registry;
    private $repo;
    private $store;

    public function __construct( Registry $registry, AcfOptionsCompetitionRepository $repo, AcfOptionsStore $store ) {
        $this->registry = $registry;
        $this->repo     = $repo;
        $this->store    = $store;
    }

    public function register() {
        $this->registerSubjects();
        $this->registerEventTypes();
        $this->registerCompetition();
    }

    public function registerValueFilters() {
        add_filter( 'acf/load_field/key=field_wz_comp_subjects', array( $this, 'loadSubjectChoices' ) );
        add_filter( 'acf/load_field/key=field_wz_event_type', array( $this, 'loadEventTypeChoices' ) );
        add_filter( 'acf/update_value/key=field_wz_subjects_repeater', array( $this, 'ensureSubjectIds' ), 10, 3 );
        add_filter( 'acf/update_value/key=field_wz_event_types_repeater', array( $this, 'ensureEventTypeIds' ), 10, 3 );
        add_filter( 'acf/update_value/key=field_wz_school_years', array( $this, 'ensureEventIds' ), 10, 3 );
        add_action( 'acf/validate_save_post', array( $this, 'validateSave' ) );
    }

    public function seedDefaults() {
        if ( false === get_option( 'wz_defaults_seeded', false ) ) {
            if ( ! $this->store->get( 'subjects', 'wz_subjects', array() ) ) {
                $this->store->update(
                    'field_wz_subjects_repeater',
                    array(
                        array( 'subject_id' => 'subj_mathematik', 'name' => 'Mathematik' ),
                        array( 'subject_id' => 'subj_informatik', 'name' => 'Informatik' ),
                        array( 'subject_id' => 'subj_physik', 'name' => 'Physik' ),
                        array( 'subject_id' => 'subj_chemie', 'name' => 'Chemie' ),
                        array( 'subject_id' => 'subj_biologie', 'name' => 'Biologie' ),
                    ),
                    'wz_subjects'
                );
            }

            if ( ! $this->store->get( 'event_types', 'wz_event_types', array() ) ) {
                $this->store->update(
                    'field_wz_event_types_repeater',
                    array(
                        array( 'event_type_id' => 'etype_anmeldung', 'name' => 'Anmeldung', 'icon' => 'registration', 'display_style' => 'outline' ),
                        array( 'event_type_id' => 'etype_abgabe', 'name' => 'Abgabe', 'icon' => 'deadline', 'display_style' => 'accent' ),
                        array( 'event_type_id' => 'etype_runde', 'name' => 'Runde', 'icon' => 'flag', 'display_style' => 'standard' ),
                        array( 'event_type_id' => 'etype_wettbewerb', 'name' => 'Wettbewerb', 'icon' => 'calendar', 'display_style' => 'accent' ),
                        array( 'event_type_id' => 'etype_preisverleihung', 'name' => 'Preisverleihung', 'icon' => 'trophy', 'display_style' => 'accent' ),
                        array( 'event_type_id' => 'etype_sonstiges', 'name' => 'Sonstiges', 'icon' => 'info', 'display_style' => 'muted' ),
                    ),
                    'wz_event_types'
                );
            }
            update_option( 'wz_defaults_seeded', 1, false );
        }
    }


    public function validateSave() {
        if ( ! function_exists( 'acf_add_validation_error' ) || empty( $_POST['acf'] ) || ! is_array( $_POST['acf'] ) ) {
            return;
        }

        $acf = wp_unslash( $_POST['acf'] );

        if ( isset( $acf['field_wz_school_years'] ) && is_array( $acf['field_wz_school_years'] ) ) {
            $seen_years = array();
            foreach ( $acf['field_wz_school_years'] as $year_row_key => $year_row ) {
                if ( ! is_array( $year_row ) ) {
                    continue;
                }
                $start_year = isset( $year_row['field_wz_school_year_start'] ) ? (int) $year_row['field_wz_school_year_start'] : 0;
                $year_input = 'acf[field_wz_school_years][' . $year_row_key . '][field_wz_school_year_start]';
                if ( $start_year && isset( $seen_years[ $start_year ] ) ) {
                    acf_add_validation_error( $year_input, 'Dieses Schuljahr ist bei diesem Wettbewerb bereits vorhanden.' );
                }
                if ( $start_year ) {
                    $seen_years[ $start_year ] = true;
                }

                if ( empty( $year_row['field_wz_events'] ) || ! is_array( $year_row['field_wz_events'] ) ) {
                    continue;
                }
                foreach ( $year_row['field_wz_events'] as $event_row_key => $event_row ) {
                    if ( ! is_array( $event_row ) ) {
                        continue;
                    }
                    $start = isset( $event_row['field_wz_event_start'] ) ? sanitize_text_field( $event_row['field_wz_event_start'] ) : '';
                    $end   = isset( $event_row['field_wz_event_end'] ) ? sanitize_text_field( $event_row['field_wz_event_end'] ) : '';
                    if ( $start && $end && $end < $start ) {
                        $input = 'acf[field_wz_school_years][' . $year_row_key . '][field_wz_events][' . $event_row_key . '][field_wz_event_end]';
                        acf_add_validation_error( $input, 'Das Enddatum darf nicht vor dem Startdatum liegen.' );
                    }
                }
            }
        }

        if ( isset( $acf['field_wz_subjects_repeater'] ) && is_array( $acf['field_wz_subjects_repeater'] ) ) {
            $submitted = $this->submittedIds( $acf['field_wz_subjects_repeater'], 'field_wz_subject_id' );
            $in_use    = array();
            foreach ( $this->repo->getCompetitions( true ) as $competition ) {
                $in_use = array_merge( $in_use, $competition['subject_ids'] );
            }
            $missing = array_diff( array_unique( $in_use ), $submitted );
            if ( $missing ) {
                acf_add_validation_error( 'acf[field_wz_subjects_repeater]', 'Mindestens ein gelöschtes Fach wird noch von einem Wettbewerb verwendet. Bitte zuerst die Zuordnungen entfernen.' );
            }
        }

        if ( isset( $acf['field_wz_event_types_repeater'] ) && is_array( $acf['field_wz_event_types_repeater'] ) ) {
            $submitted = $this->submittedIds( $acf['field_wz_event_types_repeater'], 'field_wz_event_type_id' );
            $in_use    = array();
            foreach ( $this->repo->getCompetitions( true ) as $competition ) {
                foreach ( $competition['school_years'] as $school_year ) {
                    foreach ( $school_year['events'] as $event ) {
                        if ( $event['event_type_id'] ) {
                            $in_use[] = $event['event_type_id'];
                        }
                    }
                }
            }
            $missing = array_diff( array_unique( $in_use ), $submitted );
            if ( $missing ) {
                acf_add_validation_error( 'acf[field_wz_event_types_repeater]', 'Mindestens eine gelöschte Terminart wird noch von einem Termin verwendet. Bitte zuerst die Zuordnungen ändern.' );
            }
        }
    }

    private function submittedIds( array $rows, $field_key ) {
        $ids = array();
        foreach ( $rows as $row ) {
            if ( is_array( $row ) && ! empty( $row[ $field_key ] ) ) {
                $ids[] = sanitize_key( $row[ $field_key ] );
            }
        }
        return array_values( array_unique( $ids ) );
    }

    public function loadSubjectChoices( $field ) {
        $field['choices'] = array();
        foreach ( $this->repo->getSubjects() as $subject ) {
            $field['choices'][ $subject['id'] ] = $subject['name'];
        }
        return $field;
    }

    public function loadEventTypeChoices( $field ) {
        $field['choices'] = array();
        foreach ( $this->repo->getEventTypes() as $type ) {
            $field['choices'][ $type['id'] ] = $type['name'];
        }
        return $field;
    }

    public function ensureSubjectIds( $value, $post_id, $field ) {
        return $this->ensureIds( $value, 'field_wz_subject_id', 'subject_id', 'subj' );
    }

    public function ensureEventTypeIds( $value, $post_id, $field ) {
        return $this->ensureIds( $value, 'field_wz_event_type_id', 'event_type_id', 'etype' );
    }

    public function ensureEventIds( $value, $post_id, $field ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }

        foreach ( $value as &$year ) {
            if ( ! is_array( $year ) ) {
                continue;
            }
            $events_key = array_key_exists( 'field_wz_events', $year ) ? 'field_wz_events' : ( array_key_exists( 'events', $year ) ? 'events' : null );
            if ( ! $events_key || ! is_array( $year[ $events_key ] ) ) {
                continue;
            }
            $year[ $events_key ] = $this->ensureIds( $year[ $events_key ], 'field_wz_event_id', 'event_id', 'evt' );
        }
        unset( $year );
        return $value;
    }

    private function ensureIds( $rows, $field_key, $field_name, $prefix ) {
        if ( ! is_array( $rows ) ) {
            return $rows;
        }
        foreach ( $rows as &$row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $key = array_key_exists( $field_key, $row ) ? $field_key : ( array_key_exists( $field_name, $row ) ? $field_name : $field_key );
            if ( empty( $row[ $key ] ) ) {
                $row[ $key ] = Ids::make( $prefix );
            }
        }
        unset( $row );
        return $rows;
    }

    private function registerSubjects() {
        acf_add_local_field_group(
            array(
                'key'      => 'group_wz_subjects',
                'title'    => 'Fächer',
                'fields'   => array(
                    array(
                        'key'          => 'field_wz_subjects_repeater',
                        'label'        => 'Fächer',
                        'name'         => 'subjects',
                        'type'         => 'repeater',
                        'layout'       => 'table',
                        'button_label' => 'Fach hinzufügen',
                        'sub_fields'   => array(
                            array(
                                'key'      => 'field_wz_subject_id',
                                'label'    => 'ID',
                                'name'     => 'subject_id',
                                'type'     => 'text',
                                'readonly' => 1,
                                'wrapper'  => array( 'width' => '35' ),
                            ),
                            array(
                                'key'      => 'field_wz_subject_name',
                                'label'    => 'Bezeichnung',
                                'name'     => 'name',
                                'type'     => 'text',
                                'required' => 1,
                                'wrapper'  => array( 'width' => '65' ),
                            ),
                        ),
                    ),
                ),
                'location' => array(
                    array(
                        array( 'param' => 'options_page', 'operator' => '==', 'value' => 'wz-subjects' ),
                    ),
                ),
            )
        );
    }

    private function registerEventTypes() {
        acf_add_local_field_group(
            array(
                'key'      => 'group_wz_event_types',
                'title'    => 'Terminarten',
                'fields'   => array(
                    array(
                        'key'          => 'field_wz_event_types_repeater',
                        'label'        => 'Terminarten',
                        'name'         => 'event_types',
                        'type'         => 'repeater',
                        'layout'       => 'table',
                        'button_label' => 'Terminart hinzufügen',
                        'sub_fields'   => array(
                            array(
                                'key'      => 'field_wz_event_type_id',
                                'label'    => 'ID',
                                'name'     => 'event_type_id',
                                'type'     => 'text',
                                'readonly' => 1,
                                'wrapper'  => array( 'width' => '25' ),
                            ),
                            array(
                                'key'      => 'field_wz_event_type_name',
                                'label'    => 'Bezeichnung',
                                'name'     => 'name',
                                'type'     => 'text',
                                'required' => 1,
                                'wrapper'  => array( 'width' => '25' ),
                            ),
                            array(
                                'key'      => 'field_wz_event_type_icon',
                                'label'    => 'Icon',
                                'name'     => 'icon',
                                'type'     => 'select',
                                'choices'  => array(
                                    'calendar'     => 'Kalender',
                                    'clock'        => 'Uhr',
                                    'flag'         => 'Flagge',
                                    'registration' => 'Anmeldung',
                                    'deadline'     => 'Frist',
                                    'trophy'       => 'Pokal',
                                    'star'         => 'Stern',
                                    'info'         => 'Information',
                                ),
                                'default_value' => 'calendar',
                                'wrapper'       => array( 'width' => '25' ),
                            ),
                            array(
                                'key'      => 'field_wz_event_type_style',
                                'label'    => 'Darstellung',
                                'name'     => 'display_style',
                                'type'     => 'select',
                                'choices'  => array(
                                    'standard' => 'Standard',
                                    'accent'   => 'Akzent',
                                    'outline'  => 'Umriss',
                                    'muted'    => 'Dezent',
                                ),
                                'default_value' => 'standard',
                                'wrapper'       => array( 'width' => '25' ),
                            ),
                        ),
                    ),
                ),
                'location' => array(
                    array(
                        array( 'param' => 'options_page', 'operator' => '==', 'value' => 'wz-event-types' ),
                    ),
                ),
            )
        );
    }

    private function registerCompetition() {
        $locations = array();
        foreach ( $this->registry->all() as $id ) {
            $locations[] = array(
                array(
                    'param'    => 'options_page',
                    'operator' => '==',
                    'value'    => $this->registry->menu_slug( $id ),
                ),
            );
        }

        if ( ! $locations ) {
            $locations[] = array(
                array( 'param' => 'options_page', 'operator' => '==', 'value' => 'wz-no-competition' ),
            );
        }

        $grades = array();
        for ( $i = 5; $i <= 12; $i++ ) {
            $grades[ (string) $i ] = (string) $i;
        }

        acf_add_local_field_group(
            array(
                'key'      => 'group_wz_competition',
                'title'    => 'Wettbewerb',
                'fields'   => array(
                    array( 'key' => 'field_wz_tab_master', 'label' => 'Stammdaten', 'type' => 'tab' ),
                    array( 'key' => 'field_wz_comp_name', 'label' => 'Name', 'name' => 'name', 'type' => 'text', 'required' => 1 ),
                    array( 'key' => 'field_wz_comp_short_name', 'label' => 'Kurzname', 'name' => 'short_name', 'type' => 'text' ),
                    array(
                        'key'           => 'field_wz_comp_subjects',
                        'label'         => 'Fächer',
                        'name'          => 'subjects',
                        'type'          => 'checkbox',
                        'required'      => 1,
                        'choices'       => array(),
                        'layout'        => 'horizontal',
                        'return_format' => 'value',
                    ),
                    array(
                        'key'           => 'field_wz_comp_grades',
                        'label'         => 'Klassenstufen',
                        'name'          => 'grades',
                        'type'          => 'checkbox',
                        'required'      => 1,
                        'choices'       => $grades,
                        'layout'        => 'horizontal',
                        'return_format' => 'value',
                    ),
                    array( 'key' => 'field_wz_comp_description', 'label' => 'Kurzbeschreibung', 'name' => 'description', 'type' => 'textarea', 'rows' => 4, 'new_lines' => '' ),
                    array( 'key' => 'field_wz_comp_organizer', 'label' => 'Veranstalter', 'name' => 'organizer', 'type' => 'text' ),
                    array( 'key' => 'field_wz_comp_logo', 'label' => 'Logo', 'name' => 'logo', 'type' => 'image', 'return_format' => 'id', 'preview_size' => 'medium', 'library' => 'all' ),
                    array(
                        'key'           => 'field_wz_comp_internal_page',
                        'label'         => 'Interne WordPress-Seite',
                        'name'          => 'internal_page',
                        'type'          => 'post_object',
                        'post_type'     => array( 'page' ),
                        'return_format' => 'id',
                        'multiple'      => 0,
                        'allow_null'    => 1,
                        'ui'            => 1,
                    ),
                    array( 'key' => 'field_wz_comp_external_url', 'label' => 'Externe Website', 'name' => 'external_url', 'type' => 'url' ),
                    array( 'key' => 'field_wz_tab_dates', 'label' => 'Schuljahre & Termine', 'type' => 'tab' ),
                    array(
                        'key'          => 'field_wz_school_years',
                        'label'        => 'Schuljahre',
                        'name'         => 'school_years',
                        'type'         => 'repeater',
                        'layout'       => 'block',
                        'button_label' => 'Schuljahr hinzufügen',
                        'collapsed'    => 'field_wz_school_year_start',
                        'sub_fields'   => array(
                            array(
                                'key'          => 'field_wz_school_year_start',
                                'label'        => 'Startjahr',
                                'name'         => 'start_year',
                                'type'         => 'number',
                                'required'     => 1,
                                'min'          => 2000,
                                'max'          => 2100,
                                'step'         => 1,
                                'instructions' => 'Beispiel: 2026 steht für das Schuljahr 2026/27.',
                            ),
                            array(
                                'key'          => 'field_wz_events',
                                'label'        => 'Termine',
                                'name'         => 'events',
                                'type'         => 'repeater',
                                'layout'       => 'row',
                                'button_label' => 'Termin hinzufügen',
                                'collapsed'    => 'field_wz_event_title',
                                'sub_fields'   => array(
                                    array( 'key' => 'field_wz_event_id', 'label' => 'Termin-ID', 'name' => 'event_id', 'type' => 'text', 'readonly' => 1 ),
                                    array( 'key' => 'field_wz_event_title', 'label' => 'Titel', 'name' => 'title', 'type' => 'text', 'required' => 1 ),
                                    array( 'key' => 'field_wz_event_type', 'label' => 'Terminart', 'name' => 'event_type', 'type' => 'select', 'required' => 1, 'choices' => array(), 'ui' => 1, 'return_format' => 'value' ),
                                    array( 'key' => 'field_wz_event_start', 'label' => 'Startdatum', 'name' => 'start_date', 'type' => 'date_picker', 'required' => 1, 'display_format' => 'd.m.Y', 'return_format' => 'Y-m-d', 'first_day' => 1 ),
                                    array( 'key' => 'field_wz_event_end', 'label' => 'Enddatum', 'name' => 'end_date', 'type' => 'date_picker', 'display_format' => 'd.m.Y', 'return_format' => 'Y-m-d', 'first_day' => 1, 'instructions' => 'Leer lassen bei einem Einzeltermin.' ),
                                    array( 'key' => 'field_wz_event_grades', 'label' => 'Eigene Klassenstufen', 'name' => 'grades', 'type' => 'checkbox', 'choices' => $grades, 'layout' => 'horizontal', 'return_format' => 'value', 'instructions' => 'Leer = Klassenstufen des Wettbewerbs übernehmen.' ),
                                    array( 'key' => 'field_wz_event_note', 'label' => 'Hinweis', 'name' => 'note', 'type' => 'textarea', 'rows' => 3, 'new_lines' => '' ),
                                    array( 'key' => 'field_wz_event_url', 'label' => 'Termin-Link', 'name' => 'url', 'type' => 'url' ),
                                ),
                            ),
                        ),
                    ),
                ),
                'location' => $locations,
                'position' => 'normal',
                'style'    => 'default',
            )
        );
    }
}