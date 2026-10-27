<?php
namespace WZ\Admin;

use WZ\Data\AcfOptionsCompetitionRepository;
use WZ\Data\AcfOptionsStore;
use WZ\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Menu {
    const PARENT_SLUG = 'wz-overview';
    const CAPABILITY  = 'manage_options';

    private $registry;
    private $repo;
    private $store;

    public function __construct( Registry $registry, AcfOptionsCompetitionRepository $repo, AcfOptionsStore $store ) {
        $this->registry = $registry;
        $this->repo     = $repo;
        $this->store    = $store;
    }

    public function hooks() {
        add_action( 'admin_menu', array( $this, 'registerBaseMenus' ), 9 );
        // Dynamische Bearbeitungs- und Bestätigungsseiten erst nach der
        // WordPress-Zugriffsprüfung aus der sichtbaren Navigation entfernen.
        add_action( 'admin_head', array( $this, 'hideInternalSubmenus' ), 1 );
        add_action( 'admin_notices', array( $this, 'competitionStatusNotice' ) );
        add_action( 'admin_post_wz_create_competition', array( $this, 'createCompetition' ) );
        add_action( 'admin_post_wz_archive_competition', array( $this, 'archiveCompetition' ) );
        add_action( 'admin_post_wz_restore_competition', array( $this, 'restoreCompetition' ) );
        add_action( 'admin_post_wz_delete_competition', array( $this, 'deleteCompetition' ) );
    }

    public function registerBaseMenus() {
        add_menu_page(
            'Wettbewerbe',
            'Wettbewerbe',
            self::CAPABILITY,
            self::PARENT_SLUG,
            array( $this, 'renderOverview' ),
            'dashicons-chart-bar',
            58.3
        );

        add_submenu_page(
            self::PARENT_SLUG,
            'Übersicht',
            'Übersicht',
            self::CAPABILITY,
            self::PARENT_SLUG,
            array( $this, 'renderOverview' )
        );

        add_submenu_page(
            self::PARENT_SLUG,
            'Wettbewerb hinzufügen',
            'Wettbewerb hinzufügen',
            self::CAPABILITY,
            'wz-add-competition',
            array( $this, 'renderAddCompetition' )
        );

        // Technische Bestätigungsseite für endgültiges Löschen. Sie bleibt
        // registriert, wird jedoch in admin_head aus dem Menü ausgeblendet.
        add_submenu_page(
            self::PARENT_SLUG,
            'Wettbewerb löschen',
            'Wettbewerb löschen',
            self::CAPABILITY,
            'wz-delete-competition',
            array( $this, 'renderDeleteCompetition' )
        );
    }

    public static function registerAcfPages( Registry $registry, AcfOptionsCompetitionRepository $repo ) {
        acf_add_options_page(
            array(
                'page_title'      => 'Fächer',
                'menu_title'      => 'Fächer',
                'menu_slug'       => 'wz-subjects',
                'parent_slug'     => self::PARENT_SLUG,
                'capability'      => self::CAPABILITY,
                'post_id'         => 'wz_subjects',
                'autoload'        => false,
                'redirect'        => false,
                'update_button'   => 'Fächer speichern',
                'updated_message' => 'Fächer wurden gespeichert.',
            )
        );

        acf_add_options_page(
            array(
                'page_title'      => 'Terminarten',
                'menu_title'      => 'Terminarten',
                'menu_slug'       => 'wz-event-types',
                'parent_slug'     => self::PARENT_SLUG,
                'capability'      => self::CAPABILITY,
                'post_id'         => 'wz_event_types',
                'autoload'        => false,
                'redirect'        => false,
                'update_button'   => 'Terminarten speichern',
                'updated_message' => 'Terminarten wurden gespeichert.',
            )
        );

        foreach ( $registry->all() as $id ) {
            $competition = $repo->getCompetition( $id );
            $name        = $competition && $competition['name'] ? $competition['name'] : 'Wettbewerb bearbeiten';
            if ( $registry->isArchived( $id ) ) {
                $name .= ' (archiviert)';
            }

            acf_add_options_page(
                array(
                    'page_title'      => $name,
                    'menu_title'      => $name,
                    'menu_slug'       => $registry->menu_slug( $id ),
                    'parent_slug'     => self::PARENT_SLUG,
                    'capability'      => self::CAPABILITY,
                    'post_id'         => $registry->post_id( $id ),
                    'autoload'        => false,
                    'redirect'        => false,
                    'update_button'   => 'Wettbewerb speichern',
                    'updated_message' => 'Wettbewerb wurde gespeichert.',
                )
            );
        }
    }

    public function hideInternalSubmenus() {
        foreach ( $this->registry->all() as $id ) {
            remove_submenu_page( self::PARENT_SLUG, $this->registry->menu_slug( $id ) );
        }
        remove_submenu_page( self::PARENT_SLUG, 'wz-delete-competition' );
    }

    public function renderOverview() {
        $this->guard();

        $view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'active';
        if ( ! in_array( $view, array( 'active', 'archived' ), true ) ) {
            $view = 'active';
        }

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">Wettbewerbe</h1> ';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=wz-add-competition' ) ) . '" class="page-title-action">Wettbewerb hinzufügen</a>';

        $this->renderActionNotice();

        if ( ! Plugin::instance()->acfProAvailable() ) {
            echo '<div class="notice notice-error"><p>ACF PRO ist nicht verfügbar. Die Wettbewerbsdaten können derzeit nicht bearbeitet werden.</p></div></div>';
            return;
        }

        $subjects = array();
        foreach ( $this->repo->getSubjects() as $subject ) {
            $subjects[ $subject['id'] ] = $subject['name'];
        }

        $all_items = $this->repo->getCompetitions( true );
        $active_count = 0;
        $archived_count = 0;
        foreach ( $all_items as $item ) {
            if ( ! empty( $item['archived'] ) ) {
                $archived_count++;
            } else {
                $active_count++;
            }
        }

        echo '<hr class="wp-header-end">';
        echo '<ul class="subsubsub">';
        echo '<li><a ' . ( 'active' === $view ? 'class="current" aria-current="page" ' : '' ) . 'href="' . esc_url( admin_url( 'admin.php?page=' . self::PARENT_SLUG . '&view=active' ) ) . '">Aktiv <span class="count">(' . esc_html( (string) $active_count ) . ')</span></a> | </li>';
        echo '<li><a ' . ( 'archived' === $view ? 'class="current" aria-current="page" ' : '' ) . 'href="' . esc_url( admin_url( 'admin.php?page=' . self::PARENT_SLUG . '&view=archived' ) ) . '">Archiviert <span class="count">(' . esc_html( (string) $archived_count ) . ')</span></a></li>';
        echo '</ul><br class="clear">';

        $items = array_values(
            array_filter(
                $all_items,
                static function ( $item ) use ( $view ) {
                    return 'archived' === $view ? ! empty( $item['archived'] ) : empty( $item['archived'] );
                }
            )
        );

        if ( ! $items ) {
            echo '<div class="notice notice-info inline"><p>' . ( 'archived' === $view ? 'Keine archivierten Wettbewerbe vorhanden.' : 'Noch keine aktiven Wettbewerbe vorhanden.' ) . '</p></div>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped table-view-list">';
        echo '<thead><tr><th>Wettbewerb</th><th>Kurzname</th><th>Fächer</th><th>Klassen</th><th>Schuljahre</th><th style="width:330px">Aktionen</th></tr></thead><tbody>';
        foreach ( $items as $item ) {
            $subject_names = array();
            foreach ( $item['subject_ids'] as $subject_id ) {
                $subject_names[] = isset( $subjects[ $subject_id ] ) ? $subjects[ $subject_id ] : $subject_id;
            }

            $edit_url = admin_url( 'admin.php?page=' . $this->registry->menu_slug( $item['id'] ) );
            $delete_url = wp_nonce_url(
                admin_url( 'admin.php?page=wz-delete-competition&id=' . rawurlencode( $item['id'] ) ),
                'wz_delete_competition_confirm_' . $item['id']
            );

            echo '<tr>';
            echo '<td><strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $item['name'] ?: '(ohne Namen)' ) . '</a></strong>';
            if ( ! empty( $item['archived'] ) ) {
                echo '<br><span class="description">Archiviert' . ( ! empty( $item['archived_at'] ) ? ' am ' . esc_html( mysql2date( get_option( 'date_format' ), $item['archived_at'] ) ) : '' ) . '</span>';
            }
            echo '</td>';
            echo '<td>' . esc_html( $item['short_name'] ) . '</td>';
            echo '<td>' . esc_html( implode( ', ', $subject_names ) ) . '</td>';
            echo '<td>' . esc_html( $this->formatGrades( $item['grades'] ) ) . '</td>';
            echo '<td>' . esc_html( (string) count( $item['school_years'] ) ) . '</td>';
            echo '<td><a class="button button-secondary" href="' . esc_url( $edit_url ) . '">Bearbeiten</a> ';

            if ( ! empty( $item['archived'] ) ) {
                $restore_url = wp_nonce_url(
                    admin_url( 'admin-post.php?action=wz_restore_competition&id=' . rawurlencode( $item['id'] ) ),
                    'wz_restore_competition_' . $item['id']
                );
                echo '<a class="button" href="' . esc_url( $restore_url ) . '">Wiederherstellen</a> ';
            } else {
                $archive_url = wp_nonce_url(
                    admin_url( 'admin-post.php?action=wz_archive_competition&id=' . rawurlencode( $item['id'] ) ),
                    'wz_archive_competition_' . $item['id']
                );
                echo '<a class="button" href="' . esc_url( $archive_url ) . '">Archivieren</a> ';
            }

            echo '<a href="' . esc_url( $delete_url ) . '" style="color:#b32d2e;text-decoration:none;margin-left:4px">Löschen</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    public function renderAddCompetition() {
        $this->guard();

        echo '<div class="wrap"><h1>Wettbewerb hinzufügen</h1>';
        if ( ! Plugin::instance()->acfProAvailable() ) {
            echo '<div class="notice notice-error inline"><p>ACF PRO muss aktiv sein, bevor ein Wettbewerb angelegt werden kann.</p></div></div>';
            return;
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:680px">';
        echo '<input type="hidden" name="action" value="wz_create_competition">';
        wp_nonce_field( 'wz_create_competition' );
        echo '<table class="form-table" role="presentation"><tbody><tr>';
        echo '<th scope="row"><label for="wz-name">Name</label></th>';
        echo '<td><input name="name" id="wz-name" type="text" class="regular-text" required autofocus><p class="description">Weitere Stammdaten und Termine werden anschließend auf der Bearbeitungsseite ergänzt.</p></td>';
        echo '</tr></tbody></table>';
        submit_button( 'Wettbewerb anlegen' );
        echo '</form></div>';
    }

    public function renderDeleteCompetition() {
        $this->guard();

        $id = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
        if ( ! $id || ! $this->registry->contains( $id ) ) {
            wp_die( esc_html__( 'Der Wettbewerb wurde nicht gefunden.', 'wettbewerbszeitleiste' ) );
        }
        check_admin_referer( 'wz_delete_competition_confirm_' . $id );

        $competition = $this->repo->getCompetition( $id );
        $name        = $competition && $competition['name'] ? $competition['name'] : $id;
        $event_count = 0;
        if ( $competition ) {
            foreach ( $competition['school_years'] as $school_year ) {
                $event_count += count( $school_year['events'] );
            }
        }

        echo '<div class="wrap"><h1>Wettbewerb endgültig löschen</h1>';
        echo '<div class="notice notice-error inline" style="max-width:760px"><p><strong>Diese Aktion kann nicht rückgängig gemacht werden.</strong></p>';
        echo '<p>Der Wettbewerb <strong>' . esc_html( $name ) . '</strong> einschließlich <strong>' . esc_html( (string) count( $competition ? $competition['school_years'] : array() ) ) . '</strong> Schuljahr(en) und <strong>' . esc_html( (string) $event_count ) . '</strong> Termin(en) wird vollständig aus der Wettbewerbsverwaltung entfernt.</p>';
        echo '<p>Verknüpfte WordPress-Seiten und hochgeladene Logos/Medien werden nicht gelöscht.</p></div>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:20px">';
        echo '<input type="hidden" name="action" value="wz_delete_competition">';
        echo '<input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
        wp_nonce_field( 'wz_delete_competition_' . $id );
        submit_button( 'Endgültig löschen', 'delete', 'submit', false );
        echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PARENT_SLUG . '&view=' . ( ! empty( $competition['archived'] ) ? 'archived' : 'active' ) ) ) . '">Abbrechen</a>';
        echo '</form></div>';
    }

    public function createCompetition() {
        $this->guard();
        check_admin_referer( 'wz_create_competition' );

        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        if ( '' === $name ) {
            wp_safe_redirect( admin_url( 'admin.php?page=wz-add-competition&error=missing-name' ) );
            exit;
        }

        $id = $this->registry->create();
        $this->repo->setCompetitionName( $id, $name );

        wp_safe_redirect( admin_url( 'admin.php?page=' . $this->registry->menu_slug( $id ) ) );
        exit;
    }

    public function archiveCompetition() {
        $this->guard();
        $id = $this->requestId();
        check_admin_referer( 'wz_archive_competition_' . $id );

        if ( ! $this->repo->archiveCompetition( $id ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::PARENT_SLUG . '&wz_notice=error' ) );
            exit;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PARENT_SLUG . '&view=active&wz_notice=archived' ) );
        exit;
    }

    public function restoreCompetition() {
        $this->guard();
        $id = $this->requestId();
        check_admin_referer( 'wz_restore_competition_' . $id );

        if ( ! $this->repo->restoreCompetition( $id ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::PARENT_SLUG . '&view=archived&wz_notice=error' ) );
            exit;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PARENT_SLUG . '&view=archived&wz_notice=restored' ) );
        exit;
    }

    public function deleteCompetition() {
        $this->guard();
        $id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
        if ( ! $id || ! $this->registry->contains( $id ) ) {
            wp_die( esc_html__( 'Der Wettbewerb wurde nicht gefunden.', 'wettbewerbszeitleiste' ) );
        }
        check_admin_referer( 'wz_delete_competition_' . $id );

        if ( ! $this->repo->deleteCompetition( $id ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::PARENT_SLUG . '&wz_notice=error' ) );
            exit;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PARENT_SLUG . '&wz_notice=deleted' ) );
        exit;
    }

    public function competitionStatusNotice() {
        if ( empty( $_GET['page'] ) ) {
            return;
        }
        $page = sanitize_key( wp_unslash( $_GET['page'] ) );
        foreach ( $this->registry->archived() as $id ) {
            if ( $page !== $this->registry->menu_slug( $id ) ) {
                continue;
            }
            $restore_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=wz_restore_competition&id=' . rawurlencode( $id ) ),
                'wz_restore_competition_' . $id
            );
            echo '<div class="notice notice-warning"><p><strong>Dieser Wettbewerb ist archiviert.</strong> Er bleibt vollständig gespeichert und bearbeitbar, wird aber nicht in der öffentlichen Zeitleiste oder bei kommenden Terminen angezeigt. <a href="' . esc_url( $restore_url ) . '">Wiederherstellen</a></p></div>';
            break;
        }
    }

    private function requestId() {
        $id = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
        if ( ! $id || ! $this->registry->contains( $id ) ) {
            wp_die( esc_html__( 'Der Wettbewerb wurde nicht gefunden.', 'wettbewerbszeitleiste' ) );
        }
        return $id;
    }

    private function renderActionNotice() {
        $notice = isset( $_GET['wz_notice'] ) ? sanitize_key( wp_unslash( $_GET['wz_notice'] ) ) : '';
        $messages = array(
            'archived' => 'Der Wettbewerb wurde archiviert und aus den öffentlichen Ansichten ausgeblendet.',
            'restored' => 'Der Wettbewerb wurde wiederhergestellt und ist wieder in den öffentlichen Ansichten verfügbar.',
            'deleted'  => 'Der Wettbewerb und seine Wettbewerbsdaten wurden endgültig gelöscht.',
        );

        if ( isset( $messages[ $notice ] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
        } elseif ( 'error' === $notice ) {
            echo '<div class="notice notice-error"><p>Die Aktion konnte nicht ausgeführt werden.</p></div>';
        }
    }

    private function guard() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Du hast keine Berechtigung für diese Aktion.', 'wettbewerbszeitleiste' ) );
        }
    }

    private function formatGrades( array $grades ) {
        if ( ! $grades ) {
            return '–';
        }

        sort( $grades, SORT_NUMERIC );
        $ranges = array();
        $start  = $prev = array_shift( $grades );
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
}
