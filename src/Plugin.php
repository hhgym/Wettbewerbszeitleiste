<?php
namespace WZ;

use WZ\Admin\Fields;
use WZ\Admin\Menu;
use WZ\Admin\Registry;
use WZ\Admin\ImportExport;
use WZ\Data\AcfOptionsCompetitionRepository;
use WZ\Data\AcfOptionsStore;
use WZ\Service\SchoolYearService;
use WZ\Frontend\Shortcodes;
use WZ\Update\GitHubUpdater;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Plugin {
    private static $instance;
    private $booted = false;

    public static function instance() {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate() {
        if ( false === get_option( Registry::OPTION, false ) ) {
            add_option( Registry::OPTION, array(), '', false );
        }
        if ( false === get_option( Registry::ARCHIVED_OPTION, false ) ) {
            add_option( Registry::ARCHIVED_OPTION, array(), '', false );
        }
        if ( false === get_option( 'wz_schema_version', false ) ) {
            add_option( 'wz_schema_version', 1, '', false );
        }
    }

    public function boot() {
        if ( $this->booted ) {
            return;
        }
        $this->booted = true;

        $registry = new Registry();
        $store    = new AcfOptionsStore();
        $years    = new SchoolYearService();
        $repo     = new AcfOptionsCompetitionRepository( $registry, $store, $years );

        ( new Shortcodes( $repo, $years ) )->hooks();
        ( new GitHubUpdater() )->hooks();

        if ( is_admin() ) {
            ( new Menu( $registry, $repo, $store ) )->hooks();
            ( new ImportExport( $repo ) )->hooks();
        }

        add_action(
            'acf/init',
            function () use ( $registry, $repo, $store ) {
                if ( ! $this->acfProAvailable() ) {
                    return;
                }

                $fields = new Fields( $registry, $repo, $store );
                $fields->register();
                $fields->registerValueFilters();
                $fields->seedDefaults();

                if ( is_admin() ) {
                    Menu::registerAcfPages( $registry, $repo );
                }
            },
            10
        );

        add_action( 'admin_notices', array( $this, 'acfNotice' ) );
    }

    public function acfProAvailable() {
        return function_exists( 'acf_add_options_page' )
            && function_exists( 'acf_add_local_field_group' )
            && function_exists( 'have_rows' );
    }

    public function acfNotice() {
        if ( $this->acfProAvailable() || ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>Wettbewerbszeitleiste:</strong> Für die Wettbewerbsverwaltung wird ACF PRO benötigt (Options Pages und Repeater).</p></div>';
    }
}
