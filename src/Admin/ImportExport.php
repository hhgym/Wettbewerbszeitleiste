<?php
namespace WZ\Admin;

use WZ\Data\AcfOptionsCompetitionRepository;
use WZ\Support\Ids;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ImportExport {
    const CAPABILITY = 'manage_options';

    private $repo;

    public function __construct( AcfOptionsCompetitionRepository $repo ) {
        $this->repo = $repo;
    }

    public function hooks() {
        add_action( 'admin_menu', array( $this, 'menu' ), 10 );
        add_action( 'admin_post_wz_export_json', array( $this, 'exportJson' ) );
        add_action( 'admin_post_wz_prepare_json_import', array( $this, 'prepareJsonImport' ) );
        add_action( 'admin_post_wz_apply_json_import', array( $this, 'applyJsonImport' ) );
        add_action( 'admin_post_wz_prepare_csv_import', array( $this, 'prepareCsvImport' ) );
        add_action( 'admin_post_wz_apply_csv_import', array( $this, 'applyCsvImport' ) );
    }

    public function menu() {
        add_submenu_page(
            Menu::PARENT_SLUG,
            'Import / Export',
            'Import / Export',
            self::CAPABILITY,
            'wz-import-export',
            array( $this, 'render' )
        );
    }

    public function render() {
        $this->guard();
        echo '<div class="wrap"><h1>Wettbewerbe – Import / Export</h1>';
        $this->renderNotices();
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;max-width:1100px">';

        echo '<div class="card" style="max-width:none"><h2>JSON-Export</h2><p>Exportiert das normalisierte Datenmodell mit Fächern, Terminarten, Wettbewerben, Schuljahren und Terminen.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wz_export_json">';
        wp_nonce_field( 'wz_export_json' );
        submit_button( 'JSON exportieren', 'secondary', 'submit', false );
        echo '</form></div>';

        echo '<div class="card" style="max-width:none"><h2>JSON-Import</h2><p>Importiert einen Export dieses Plugins. Vor dem Schreiben wird eine Validierung und Vorschau angezeigt.</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wz_prepare_json_import">';
        wp_nonce_field( 'wz_prepare_json_import' );
        echo '<input type="file" name="json_file" accept="application/json,.json" required> ';
        submit_button( 'JSON prüfen', 'secondary', 'submit', false );
        echo '</form></div>';

        echo '<div class="card" style="max-width:none"><h2>CSV-Terminimport</h2><p>Für den gebündelten Terminimport zu Beginn eines Schuljahres. Unterstützt Semikolon, Komma und Tabulator.</p>';
        echo '<p><code>Wettbewerb;Schuljahr;Titel;Terminart;Start;Ende;Klassen;Hinweis;URL</code></p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wz_prepare_csv_import">';
        wp_nonce_field( 'wz_prepare_csv_import' );
        echo '<input type="file" name="csv_file" accept="text/csv,.csv,.txt" required> ';
        submit_button( 'CSV prüfen', 'secondary', 'submit', false );
        echo '</form></div>';
        echo '</div>';

        $preview = isset( $_GET['wz_preview'] ) ? sanitize_key( wp_unslash( $_GET['wz_preview'] ) ) : '';
        if ( 'json' === $preview ) {
            $this->renderJsonPreview();
        } elseif ( 'csv' === $preview ) {
            $this->renderCsvPreview();
        }

        echo '</div>';
    }

    public function exportJson() {
        $this->guard();
        check_admin_referer( 'wz_export_json' );

        $payload = array(
            'schema_version' => 1,
            'exported_at'    => gmdate( 'c' ),
            'subjects'       => $this->repo->getSubjects(),
            'event_types'    => $this->repo->getEventTypes(),
            'competitions'   => array_map( array( $this, 'canonicalCompetition' ), $this->repo->getCompetitions( true ) ),
        );

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="wettbewerbe-' . gmdate( 'Y-m-d-His' ) . '.json"' );
        echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        exit;
    }

    public function prepareJsonImport() {
        $this->guard();
        check_admin_referer( 'wz_prepare_json_import' );
        $file = $this->uploadedFile( 'json_file' );
        if ( is_wp_error( $file ) ) {
            $this->redirectError( $file->get_error_message() );
        }

        $raw  = file_get_contents( $file );
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            $this->redirectError( 'Die JSON-Datei ist ungültig.' );
        }

        $validation = $this->validateJson( $data );
        set_transient( $this->key( 'json' ), array( 'data' => $data, 'validation' => $validation ), 30 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=wz-import-export&wz_preview=json' ) );
        exit;
    }

    public function applyJsonImport() {
        $this->guard();
        check_admin_referer( 'wz_apply_json_import' );
        $preview = get_transient( $this->key( 'json' ) );
        if ( ! is_array( $preview ) || empty( $preview['data'] ) || ! empty( $preview['validation']['errors'] ) ) {
            $this->redirectError( 'Die Importvorschau ist abgelaufen oder enthält Fehler.' );
        }

        $data = $preview['data'];
        $this->repo->replaceSubjects( $this->mergeById( $this->repo->getSubjects(), isset( $data['subjects'] ) && is_array( $data['subjects'] ) ? $data['subjects'] : array() ) );
        $this->repo->replaceEventTypes( $this->mergeById( $this->repo->getEventTypes(), isset( $data['event_types'] ) && is_array( $data['event_types'] ) ? $data['event_types'] : array() ) );
        foreach ( $data['competitions'] as $competition ) {
            $this->repo->upsertCompetition( $competition );
        }
        delete_transient( $this->key( 'json' ) );
        wp_safe_redirect( admin_url( 'admin.php?page=wz-import-export&wz_done=json' ) );
        exit;
    }

    public function prepareCsvImport() {
        $this->guard();
        check_admin_referer( 'wz_prepare_csv_import' );
        $file = $this->uploadedFile( 'csv_file' );
        if ( is_wp_error( $file ) ) {
            $this->redirectError( $file->get_error_message() );
        }

        $preview = $this->parseCsv( $file );
        set_transient( $this->key( 'csv' ), $preview, 30 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=wz-import-export&wz_preview=csv' ) );
        exit;
    }

    public function applyCsvImport() {
        $this->guard();
        check_admin_referer( 'wz_apply_csv_import' );
        $preview = get_transient( $this->key( 'csv' ) );
        if ( ! is_array( $preview ) || empty( $preview['rows'] ) ) {
            $this->redirectError( 'Die CSV-Importvorschau ist abgelaufen.' );
        }
        $update_duplicates = ! empty( $_POST['update_duplicates'] );
        $changed = array();

        foreach ( $preview['rows'] as $row ) {
            if ( 'error' === $row['status'] || ( 'duplicate' === $row['status'] && ! $update_duplicates ) ) {
                continue;
            }
            $competition_id = $row['competition_id'];
            if ( ! isset( $changed[ $competition_id ] ) ) {
                $changed[ $competition_id ] = $this->repo->getCompetition( $competition_id );
            }
            if ( ! $changed[ $competition_id ] ) {
                continue;
            }
            $this->mergeImportedEvent( $changed[ $competition_id ], $row['event'], 'duplicate' === $row['status'] );
        }

        foreach ( $changed as $competition ) {
            $this->repo->upsertCompetition( $competition );
        }
        delete_transient( $this->key( 'csv' ) );
        wp_safe_redirect( admin_url( 'admin.php?page=wz-import-export&wz_done=csv' ) );
        exit;
    }

    private function renderJsonPreview() {
        $preview = get_transient( $this->key( 'json' ) );
        if ( ! is_array( $preview ) ) {
            echo '<div class="notice notice-warning inline"><p>Die JSON-Vorschau ist nicht mehr verfügbar.</p></div>';
            return;
        }
        $v = $preview['validation'];
        echo '<hr><h2>JSON-Importvorschau</h2>';
        if ( $v['errors'] ) {
            echo '<div class="notice notice-error inline"><p><strong>Der Import kann nicht ausgeführt werden:</strong></p><ul style="list-style:disc;padding-left:20px">';
            foreach ( $v['errors'] as $error ) {
                echo '<li>' . esc_html( $error ) . '</li>';
            }
            echo '</ul></div>';
        }
        if ( $v['warnings'] ) {
            echo '<div class="notice notice-warning inline"><ul style="list-style:disc;padding-left:20px">';
            foreach ( $v['warnings'] as $warning ) {
                echo '<li>' . esc_html( $warning ) . '</li>';
            }
            echo '</ul></div>';
        }
        echo '<p><strong>' . esc_html( $v['subjects'] ) . '</strong> Fächer · <strong>' . esc_html( $v['event_types'] ) . '</strong> Terminarten · <strong>' . esc_html( $v['competitions'] ) . '</strong> Wettbewerbe · <strong>' . esc_html( $v['events'] ) . '</strong> Termine</p>';
        if ( ! $v['errors'] ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wz_apply_json_import">';
            wp_nonce_field( 'wz_apply_json_import' );
            echo '<p>Fächer und Terminarten werden anhand ihrer stabilen IDs zusammengeführt; vorhandene Einträge bleiben erhalten. Wettbewerbe mit derselben stabilen ID werden aktualisiert.</p>';
            submit_button( 'JSON-Import ausführen', 'primary', 'submit', false );
            echo '</form>';
        }
    }

    private function renderCsvPreview() {
        $preview = get_transient( $this->key( 'csv' ) );
        if ( ! is_array( $preview ) ) {
            echo '<div class="notice notice-warning inline"><p>Die CSV-Vorschau ist nicht mehr verfügbar.</p></div>';
            return;
        }
        echo '<hr><h2>CSV-Importvorschau</h2>';
        echo '<p>' . esc_html( $preview['new_count'] ) . ' neu · ' . esc_html( $preview['duplicate_count'] ) . ' Dubletten · ' . esc_html( $preview['error_count'] ) . ' Fehler</p>';
        echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr><th>Zeile</th><th>Status</th><th>Wettbewerb</th><th>Schuljahr</th><th>Termin</th><th>Datum</th><th>Hinweis</th></tr></thead><tbody>';
        foreach ( $preview['rows'] as $row ) {
            $status = array( 'new' => 'Neu', 'duplicate' => 'Dublettenfund', 'error' => 'Fehler' );
            echo '<tr><td>' . esc_html( $row['line'] ) . '</td><td><strong>' . esc_html( isset( $status[ $row['status'] ] ) ? $status[ $row['status'] ] : $row['status'] ) . '</strong></td><td>' . esc_html( $row['competition_name'] ) . '</td><td>' . esc_html( $row['school_year_label'] ) . '</td><td>' . esc_html( isset( $row['event']['title'] ) ? $row['event']['title'] : '' ) . '</td><td>' . esc_html( isset( $row['event']['start_date'] ) ? $row['event']['start_date'] : '' ) . '</td><td>' . esc_html( $row['message'] ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        if ( $preview['new_count'] || $preview['duplicate_count'] ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:16px"><input type="hidden" name="action" value="wz_apply_csv_import">';
            wp_nonce_field( 'wz_apply_csv_import' );
            echo '<label><input type="checkbox" name="update_duplicates" value="1"> Gefundene Dubletten mit den CSV-Werten aktualisieren</label><br><br>';
            submit_button( 'CSV-Import ausführen', 'primary', 'submit', false );
            echo '</form>';
        }
    }

    private function validateJson( array $data ) {
        $errors = array();
        $warnings = array();
        if ( 1 !== (int) ( isset( $data['schema_version'] ) ? $data['schema_version'] : 0 ) ) {
            $errors[] = 'Nicht unterstützte oder fehlende schema_version. Erwartet wird Version 1.';
        }
        foreach ( array( 'subjects', 'event_types', 'competitions' ) as $key ) {
            if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
                $errors[] = 'Der Bereich „' . $key . '“ fehlt oder ist ungültig.';
            }
        }
        if ( $errors ) {
            return array( 'errors' => $errors, 'warnings' => $warnings, 'subjects' => 0, 'event_types' => 0, 'competitions' => 0, 'events' => 0 );
        }

        $subject_ids = $this->uniqueIds( $data['subjects'], 'id', 'Fach', $errors );
        $type_ids    = $this->uniqueIds( $data['event_types'], 'id', 'Terminart', $errors );
        $comp_ids    = $this->uniqueIds( $data['competitions'], 'id', 'Wettbewerb', $errors );
        $events = 0;

        foreach ( $data['competitions'] as $competition ) {
            if ( empty( $competition['name'] ) ) {
                $errors[] = 'Ein Wettbewerb besitzt keinen Namen.';
            }
            if ( array_key_exists( 'archived', $competition ) && ! is_bool( $competition['archived'] ) ) {
                $errors[] = 'Beim Wettbewerb „' . ( isset( $competition['name'] ) ? $competition['name'] : '?' ) . '“ muss archived ein boolescher Wert sein.';
            }
            foreach ( isset( $competition['subject_ids'] ) && is_array( $competition['subject_ids'] ) ? $competition['subject_ids'] : array() as $sid ) {
                if ( ! in_array( $sid, $subject_ids, true ) ) {
                    $errors[] = 'Wettbewerb „' . ( isset( $competition['name'] ) ? $competition['name'] : '?' ) . '“ verweist auf ein unbekanntes Fach: ' . $sid;
                }
            }
            foreach ( isset( $competition['school_years'] ) && is_array( $competition['school_years'] ) ? $competition['school_years'] : array() as $year ) {
                foreach ( isset( $year['events'] ) && is_array( $year['events'] ) ? $year['events'] : array() as $event ) {
                    $events++;
                    if ( empty( $event['title'] ) || empty( $event['start_date'] ) ) {
                        $errors[] = 'Ein Termin in „' . ( isset( $competition['name'] ) ? $competition['name'] : '?' ) . '“ hat keinen Titel oder kein Startdatum.';
                    }
                    if ( ! empty( $event['event_type_id'] ) && ! in_array( $event['event_type_id'], $type_ids, true ) ) {
                        $errors[] = 'Termin „' . ( isset( $event['title'] ) ? $event['title'] : '?' ) . '“ verweist auf eine unbekannte Terminart.';
                    }
                    if ( ! empty( $event['end_date'] ) && $event['end_date'] < $event['start_date'] ) {
                        $errors[] = 'Beim Termin „' . ( isset( $event['title'] ) ? $event['title'] : '?' ) . '“ liegt das Enddatum vor dem Startdatum.';
                    }
                }
            }
        }

        if ( count( $comp_ids ) !== count( $data['competitions'] ) ) {
            $warnings[] = 'Wettbewerbsdatensätze ohne gültige ID können nicht eindeutig übernommen werden.';
        }
        return array(
            'errors'       => array_values( array_unique( $errors ) ),
            'warnings'     => array_values( array_unique( $warnings ) ),
            'subjects'     => count( $data['subjects'] ),
            'event_types'  => count( $data['event_types'] ),
            'competitions' => count( $data['competitions'] ),
            'events'       => $events,
        );
    }

    private function parseCsv( $file ) {
        $handle = fopen( $file, 'r' );
        if ( ! $handle ) {
            return array( 'rows' => array(), 'new_count' => 0, 'duplicate_count' => 0, 'error_count' => 1 );
        }
        $first = fgets( $handle );
        $delimiter = $this->detectDelimiter( $first );
        rewind( $handle );
        $header = fgetcsv( $handle, 0, $delimiter );
        if ( ! is_array( $header ) ) {
            fclose( $handle );
            return array( 'rows' => array(), 'new_count' => 0, 'duplicate_count' => 0, 'error_count' => 1 );
        }
        $keys = array_map( array( $this, 'normalizeHeader' ), $header );

        $competitions = $this->repo->getCompetitions();
        $types = $this->repo->getEventTypes();
        $comp_lookup = array();
        foreach ( $competitions as $comp ) {
            $comp_lookup[ strtolower( $comp['id'] ) ] = $comp;
            if ( $comp['name'] ) $comp_lookup[ $this->normalizeLookup( $comp['name'] ) ] = $comp;
            if ( $comp['short_name'] ) $comp_lookup[ $this->normalizeLookup( $comp['short_name'] ) ] = $comp;
        }
        $type_lookup = array();
        foreach ( $types as $type ) {
            $type_lookup[ strtolower( $type['id'] ) ] = $type;
            $type_lookup[ $this->normalizeLookup( $type['name'] ) ] = $type;
        }

        $rows = array(); $line = 1; $new = 0; $dup = 0; $errors = 0;
        while ( false !== ( $values = fgetcsv( $handle, 0, $delimiter ) ) ) {
            $line++;
            if ( 1 === count( $values ) && '' === trim( (string) $values[0] ) ) continue;
            $values = array_pad( $values, count( $keys ), '' );
            $raw = array_combine( $keys, array_slice( $values, 0, count( $keys ) ) );
            $parsed = $this->parseCsvRow( $raw, $line, $comp_lookup, $type_lookup );
            if ( 'new' === $parsed['status'] ) $new++;
            elseif ( 'duplicate' === $parsed['status'] ) $dup++;
            else $errors++;
            $rows[] = $parsed;
        }
        fclose( $handle );
        return array( 'rows' => $rows, 'new_count' => $new, 'duplicate_count' => $dup, 'error_count' => $errors );
    }

    private function parseCsvRow( array $raw, $line, array $comp_lookup, array $type_lookup ) {
        $competition_key = ! empty( $raw['competition_id'] ) ? strtolower( trim( $raw['competition_id'] ) ) : $this->normalizeLookup( isset( $raw['competition'] ) ? $raw['competition'] : '' );
        $competition = isset( $comp_lookup[ $competition_key ] ) ? $comp_lookup[ $competition_key ] : null;
        $type_key = ! empty( $raw['event_type_id'] ) ? strtolower( trim( $raw['event_type_id'] ) ) : $this->normalizeLookup( isset( $raw['event_type'] ) ? $raw['event_type'] : '' );
        $type = isset( $type_lookup[ $type_key ] ) ? $type_lookup[ $type_key ] : null;
        $school_year = $this->parseSchoolYear( isset( $raw['school_year'] ) ? $raw['school_year'] : '' );
        $start = $this->parseDate( isset( $raw['start_date'] ) ? $raw['start_date'] : '' );
        $end   = $this->parseDate( isset( $raw['end_date'] ) ? $raw['end_date'] : '', true );
        $title = sanitize_text_field( isset( $raw['title'] ) ? $raw['title'] : '' );
        $message = array();

        if ( ! $competition ) $message[] = 'Wettbewerb nicht gefunden.';
        if ( ! $type ) $message[] = 'Terminart nicht gefunden.';
        if ( ! $school_year ) $message[] = 'Schuljahr ungültig.';
        if ( ! $title ) $message[] = 'Titel fehlt.';
        if ( ! $start ) $message[] = 'Startdatum ungültig.';
        if ( $end && $start && $end < $start ) $message[] = 'Enddatum liegt vor Startdatum.';

        $event = array(
            'id'            => Ids::make( 'evt' ),
            'title'         => $title,
            'event_type_id' => $type ? $type['id'] : '',
            'start_date'    => $start,
            'end_date'      => $end,
            'grades'        => $this->parseGrades( isset( $raw['grades'] ) ? $raw['grades'] : '' ),
            'grade_source'  => trim( isset( $raw['grades'] ) ? $raw['grades'] : '' ) === '' ? 'competition' : 'event',
            'note'          => sanitize_textarea_field( isset( $raw['note'] ) ? $raw['note'] : '' ),
            'url'           => esc_url_raw( isset( $raw['url'] ) ? $raw['url'] : '' ),
            'school_year'   => $school_year,
        );

        if ( $message ) {
            return array( 'line' => $line, 'status' => 'error', 'message' => implode( ' ', $message ), 'competition_id' => $competition ? $competition['id'] : '', 'competition_name' => $competition ? $competition['name'] : ( isset( $raw['competition'] ) ? $raw['competition'] : '' ), 'school_year_label' => $school_year ? sprintf( '%d/%02d', $school_year, ( $school_year + 1 ) % 100 ) : '', 'event' => $event );
        }

        $duplicate = $this->findDuplicate( $competition, $event, $school_year );
        if ( $duplicate ) {
            $event['id'] = $duplicate['id'];
            return array( 'line' => $line, 'status' => 'duplicate', 'message' => 'Passender Termin ist bereits vorhanden.', 'competition_id' => $competition['id'], 'competition_name' => $competition['name'], 'school_year_label' => sprintf( '%d/%02d', $school_year, ( $school_year + 1 ) % 100 ), 'event' => $event );
        }
        return array( 'line' => $line, 'status' => 'new', 'message' => '', 'competition_id' => $competition['id'], 'competition_name' => $competition['name'], 'school_year_label' => sprintf( '%d/%02d', $school_year, ( $school_year + 1 ) % 100 ), 'event' => $event );
    }

    private function mergeImportedEvent( array &$competition, array $event, $replace ) {
        $year_index = null;
        foreach ( $competition['school_years'] as $i => $year ) {
            if ( (int) $year['start_year'] === (int) $event['school_year'] ) { $year_index = $i; break; }
        }
        if ( null === $year_index ) {
            $competition['school_years'][] = array( 'start_year' => (int) $event['school_year'], 'label' => sprintf( '%d/%02d', $event['school_year'], ( $event['school_year'] + 1 ) % 100 ), 'events' => array() );
            $year_index = count( $competition['school_years'] ) - 1;
        }
        unset( $event['school_year'] );
        if ( $replace ) {
            foreach ( $competition['school_years'][ $year_index ]['events'] as $i => $existing ) {
                if ( ! empty( $event['id'] ) && $existing['id'] === $event['id'] ) {
                    $competition['school_years'][ $year_index ]['events'][ $i ] = array_merge( $existing, $event );
                    return;
                }
            }
        }
        $competition['school_years'][ $year_index ]['events'][] = $event;
    }

    private function findDuplicate( array $competition, array $event, $school_year ) {
        foreach ( $competition['school_years'] as $year ) {
            if ( (int) $year['start_year'] !== (int) $school_year ) continue;
            foreach ( $year['events'] as $existing ) {
                if ( $existing['title'] === $event['title'] && $existing['event_type_id'] === $event['event_type_id'] && $existing['start_date'] === $event['start_date'] && ( $existing['end_date'] ?: '' ) === ( $event['end_date'] ?: '' ) ) {
                    return $existing;
                }
            }
        }
        return null;
    }

    public function canonicalCompetition( array $competition ) {
        $years = array();
        foreach ( $competition['school_years'] as $year ) {
            $events = array();
            foreach ( $year['events'] as $event ) {
                $events[] = array(
                    'id'            => $event['id'],
                    'title'         => $event['title'],
                    'event_type_id' => $event['event_type_id'],
                    'start_date'    => $event['start_date'],
                    'end_date'      => $event['end_date'],
                    'grades'        => $event['grades'],
                    'grade_source'  => $event['grade_source'],
                    'note'          => $event['note'],
                    'url'           => $event['url'],
                );
            }
            $years[] = array( 'start_year' => $year['start_year'], 'events' => $events );
        }
        return array(
            'id'               => $competition['id'],
            'name'             => $competition['name'],
            'short_name'       => $competition['short_name'],
            'subject_ids'      => $competition['subject_ids'],
            'grades'           => $competition['grades'],
            'description'      => $competition['description'],
            'organizer'        => $competition['organizer'],
            'logo_id'          => $competition['logo_id'],
            'internal_page_id' => $competition['internal_page_id'],
            'external_url'     => $competition['external_url'],
            'archived'         => ! empty( $competition['archived'] ),
            'school_years'     => $years,
        );
    }

    private function mergeById( array $existing, array $incoming ) {
        $merged = array();
        foreach ( $existing as $row ) {
            if ( is_array( $row ) && ! empty( $row['id'] ) ) {
                $merged[ sanitize_key( $row['id'] ) ] = $row;
            }
        }
        foreach ( $incoming as $row ) {
            if ( is_array( $row ) && ! empty( $row['id'] ) ) {
                $merged[ sanitize_key( $row['id'] ) ] = $row;
            }
        }
        return array_values( $merged );
    }

    private function uniqueIds( array $rows, $key, $label, array &$errors ) {
        $ids = array();
        foreach ( $rows as $row ) {
            $id = is_array( $row ) && ! empty( $row[ $key ] ) ? sanitize_key( $row[ $key ] ) : '';
            if ( ! $id ) { $errors[] = $label . ' ohne stabile ID gefunden.'; continue; }
            if ( in_array( $id, $ids, true ) ) $errors[] = $label . '-ID doppelt vorhanden: ' . $id;
            $ids[] = $id;
        }
        return array_values( array_unique( $ids ) );
    }

    private function uploadedFile( $field ) {
        if ( empty( $_FILES[ $field ] ) || UPLOAD_ERR_OK !== (int) $_FILES[ $field ]['error'] ) {
            return new \WP_Error( 'upload', 'Die Datei konnte nicht hochgeladen werden.' );
        }
        if ( (int) $_FILES[ $field ]['size'] > 5 * 1024 * 1024 ) {
            return new \WP_Error( 'size', 'Die Importdatei ist größer als 5 MB.' );
        }
        return $_FILES[ $field ]['tmp_name'];
    }

    private function detectDelimiter( $line ) {
        $counts = array( ';' => substr_count( $line, ';' ), ',' => substr_count( $line, ',' ), "\t" => substr_count( $line, "\t" ) );
        arsort( $counts );
        return (string) key( $counts );
    }

    private function normalizeHeader( $header ) {
        $key = $this->normalizeLookup( $header );
        $map = array(
            'wettbewerb' => 'competition', 'competition' => 'competition',
            'wettbewerb_id' => 'competition_id', 'competition_id' => 'competition_id',
            'schuljahr' => 'school_year', 'school_year' => 'school_year',
            'titel' => 'title', 'title' => 'title',
            'terminart' => 'event_type', 'event_type' => 'event_type',
            'terminart_id' => 'event_type_id', 'event_type_id' => 'event_type_id',
            'start' => 'start_date', 'startdatum' => 'start_date', 'start_date' => 'start_date',
            'ende' => 'end_date', 'enddatum' => 'end_date', 'end_date' => 'end_date',
            'klassen' => 'grades', 'klassenstufen' => 'grades', 'grades' => 'grades',
            'hinweis' => 'note', 'note' => 'note',
            'url' => 'url', 'link' => 'url',
        );
        return isset( $map[ $key ] ) ? $map[ $key ] : $key;
    }

    private function normalizeLookup( $value ) {
        $value = strtolower( remove_accents( trim( (string) $value ) ) );
        $value = preg_replace( '/[^a-z0-9]+/', '_', $value );
        return trim( $value, '_' );
    }

    private function parseSchoolYear( $value ) {
        if ( preg_match( '/^(20\\d{2})(?:\\s*\\/\\s*\\d{2,4})?$/', trim( (string) $value ), $m ) ) return (int) $m[1];
        return 0;
    }

    private function parseDate( $value, $allow_empty = false ) {
        $value = trim( (string) $value );
        if ( '' === $value ) return $allow_empty ? null : '';
        $formats = array( '!Y-m-d', '!d.m.Y', '!d/m/Y' );
        foreach ( $formats as $format ) {
            $date = \DateTimeImmutable::createFromFormat( $format, $value, wp_timezone() );
            if ( $date && $date->format( str_replace( '!', '', $format ) ) === $value ) return $date->format( 'Y-m-d' );
        }
        return '';
    }

    private function parseGrades( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value ) return array();
        $grades = array();
        foreach ( preg_split( '/[\\s,;]+/', $value ) as $part ) {
            if ( preg_match( '/^(\\d{1,2})[-–](\\d{1,2})$/', $part, $m ) ) {
                for ( $g = (int) $m[1]; $g <= (int) $m[2]; $g++ ) if ( $g >= 5 && $g <= 12 ) $grades[] = $g;
            } elseif ( ctype_digit( $part ) ) {
                $g = (int) $part; if ( $g >= 5 && $g <= 12 ) $grades[] = $g;
            }
        }
        $grades = array_values( array_unique( $grades ) ); sort( $grades, SORT_NUMERIC ); return $grades;
    }

    private function renderNotices() {
        if ( isset( $_GET['wz_done'] ) ) echo '<div class="notice notice-success is-dismissible"><p>Import wurde erfolgreich ausgeführt.</p></div>';
        if ( isset( $_GET['wz_error'] ) ) echo '<div class="notice notice-error"><p>' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['wz_error'] ) ) ) ) . '</p></div>';
    }

    private function redirectError( $message ) {
        wp_safe_redirect( admin_url( 'admin.php?page=wz-import-export&wz_error=' . rawurlencode( $message ) ) );
        exit;
    }

    private function key( $type ) { return 'wz_' . sanitize_key( $type ) . '_preview_' . get_current_user_id(); }

    private function guard() {
        if ( ! current_user_can( self::CAPABILITY ) ) wp_die( esc_html__( 'Du hast keine Berechtigung für diese Aktion.', 'wettbewerbszeitleiste' ) );
    }
}