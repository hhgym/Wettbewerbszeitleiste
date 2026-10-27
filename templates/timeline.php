<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$type_map = array();
foreach ( $view['event_types'] as $type ) {
    $type_map[ $type['id'] ] = $type;
}

$subject_map = array();
foreach ( $view['subjects'] as $subject ) {
    $subject_map[ $subject['id'] ] = $subject['name'];
}

$instance = 'wz-' . wp_unique_id();
$subjects = array();

foreach ( $view['groups'] as $group ) {
    $subject = array(
        'id'           => $group['id'],
        'name'         => $group['name'],
        'competitions' => array(),
    );

    foreach ( $group['competitions'] as $competition ) {
        $entry = array(
            'id'          => $competition['id'],
            'name'        => $competition['name'],
            'shortName'   => $competition['short_name'],
            'internalUrl' => $competition['internal_page_id'] ? esc_url_raw( get_permalink( $competition['internal_page_id'] ) ) : '',
            'externalUrl' => esc_url_raw( $competition['external_url'] ),
            'events'      => array(),
        );

        foreach ( $competition['events'] as $event ) {
            $type = isset( $type_map[ $event['event_type_id'] ] )
                ? $type_map[ $event['event_type_id'] ]
                : array( 'name' => '', 'icon' => 'calendar', 'display_style' => 'standard' );

            $entry['events'][] = array(
                'id'            => $event['id'] . ':' . $group['id'],
                'eventId'       => $event['id'],
                'subjectId'     => $group['id'],
                'competitionId' => $competition['id'],
                'start'         => $event['start_date'],
                'end'           => $event['end_date'],
                'isRange'       => (bool) $event['is_range'],
                'title'         => $event['title'],
                'eventTypeId'   => $event['event_type_id'],
                'grades'        => array_values( array_map( 'intval', $event['grades'] ) ),
                'icon'          => $type['icon'],
                'displayStyle'  => $type['display_style'],
                'payload'       => $this->eventPayload( $event, $type_map, $subject_map ),
            );
        }

        $subject['competitions'][] = $entry;
    }

    if ( $subject['competitions'] ) {
        $subjects[] = $subject;
    }
}

$start_year = (int) $view['school_year'];
$config     = array(
    'instance'   => $instance,
    'schoolYear' => $start_year,
    'label'      => $view['label'],
    'bounds'     => array(
        'start' => sprintf( '%04d-08-01', $start_year ),
        'end'   => sprintf( '%04d-08-01', $start_year + 1 ),
    ),
    'today'      => ( $this->years->currentStartYear() === $start_year ) ? wp_date( 'Y-m-d' ) : null,
    'months'     => array_values( $view['months'] ),
    'subjects'   => $subjects,
    'eventTypes' => array_values( $view['event_types'] ),
);
?>
<div class="wz-timeline" id="<?php echo esc_attr( $instance ); ?>" data-wz-timeline>
    <div class="wz-toolbar">
        <label class="wz-control wz-school-year-control">
            <span>Schuljahr</span>
            <select data-wz-school-year>
                <?php if ( ! isset( $years[ $view['school_year'] ] ) ) : ?>
                    <option value="<?php echo esc_attr( $view['school_year'] ); ?>" selected><?php echo esc_html( $view['label'] ); ?></option>
                <?php endif; ?>
                <?php foreach ( $years as $start => $label ) : ?>
                    <option value="<?php echo esc_attr( $start ); ?>" <?php selected( (int) $start, (int) $view['school_year'] ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <div class="wz-filter-controls" aria-label="Wettbewerbe filtern">
            <details class="wz-filter">
                <summary>Fächer</summary>
                <div class="wz-filter__panel">
                    <?php foreach ( $view['subjects'] as $subject ) : ?>
                        <label><input type="checkbox" value="<?php echo esc_attr( $subject['id'] ); ?>" data-wz-filter-subject> <?php echo esc_html( $subject['name'] ); ?></label>
                    <?php endforeach; ?>
                </div>
            </details>

            <details class="wz-filter">
                <summary><span class="wz-label-desktop">Klassenstufen</span><span class="wz-label-mobile">Klassen</span></summary>
                <div class="wz-filter__panel">
                    <div class="wz-quickfilters">
                        <button type="button" data-wz-grades="5,6,7,8,9,10">Sek I</button>
                        <button type="button" data-wz-grades="11,12">Sek II</button>
                        <button type="button" data-wz-grades="">Alle</button>
                    </div>
                    <?php for ( $grade = 5; $grade <= 12; $grade++ ) : ?>
                        <label><input type="checkbox" value="<?php echo esc_attr( $grade ); ?>" data-wz-filter-grade> Klasse <?php echo esc_html( $grade ); ?></label>
                    <?php endfor; ?>
                </div>
            </details>

            <details class="wz-filter">
                <summary>Terminarten</summary>
                <div class="wz-filter__panel wz-filter__panel--right">
                    <?php foreach ( $view['event_types'] as $type ) : ?>
                        <label><input type="checkbox" value="<?php echo esc_attr( $type['id'] ); ?>" data-wz-filter-type> <?php echo esc_html( $type['name'] ); ?></label>
                    <?php endforeach; ?>
                </div>
            </details>
        </div>

        <div class="wz-view-controls" aria-label="Darstellung">
            <div class="wz-view-switch" role="group" aria-label="Ansicht">
                <button type="button" class="is-active" data-wz-view="timeline" aria-pressed="true">Zeitleiste</button>
                <button type="button" data-wz-view="list" aria-pressed="false">Liste</button>
            </div>

            <label class="wz-range-control">
                <span>Zeitraum</span>
                <select data-wz-range>
                    <option value="12">Ganzes Jahr</option>
                    <option value="6">6 Monate</option>
                    <option value="3">3 Monate</option>
                </select>
            </label>

            <?php if ( $config['today'] ) : ?><button type="button" class="wz-today-button" data-wz-today>Heute</button><?php endif; ?>
        </div>
    </div>

    <?php if ( empty( $view['groups'] ) ) : ?>
        <p class="wz-empty">Für dieses Schuljahr sind noch keine Wettbewerbstermine hinterlegt.</p>
    <?php else : ?>
        <div class="wz-stage" data-wz-stage>
            <div class="wz-scroller" data-wz-scroller aria-label="Wettbewerbszeitleiste <?php echo esc_attr( $view['label'] ); ?>"></div>
            <div class="wz-list" data-wz-list hidden aria-label="Terminliste <?php echo esc_attr( $view['label'] ); ?>"></div>
            <p class="wz-empty" data-wz-filter-empty hidden>Für die gewählten Filter sind keine Termine vorhanden.</p>
        </div>
    <?php endif; ?>

    <div class="wz-preview" data-wz-preview hidden>
        <strong data-wz-preview-title></strong>
        <span data-wz-preview-competition></span>
        <span data-wz-preview-date></span>
        <button type="button" data-wz-more>Weitere Informationen</button>
    </div>

    <div class="wz-modal" data-wz-modal hidden>
        <div class="wz-modal__backdrop" data-wz-modal-close></div>
        <section class="wz-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $instance ); ?>-modal-title">
            <button type="button" class="wz-modal__close" data-wz-modal-close aria-label="Schließen">×</button>
            <p class="wz-modal__eyebrow" data-wz-modal-type></p>
            <h2 id="<?php echo esc_attr( $instance ); ?>-modal-title" data-wz-modal-title></h2>
            <p><strong data-wz-modal-competition></strong></p>
            <dl class="wz-modal__facts">
                <div><dt>Datum</dt><dd data-wz-modal-date></dd></div>
                <div><dt>Fächer</dt><dd data-wz-modal-subjects></dd></div>
                <div><dt>Klassen</dt><dd data-wz-modal-grades></dd></div>
            </dl>
            <div class="wz-modal__note" data-wz-modal-note-wrap hidden><h3>Hinweis</h3><p data-wz-modal-note></p></div>
            <div class="wz-modal__links" data-wz-modal-links></div>
        </section>
    </div>

    <script type="application/json" data-wz-timeline-data><?php echo wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
</div>
