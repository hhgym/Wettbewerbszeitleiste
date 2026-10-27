<?php
namespace WZ\Update;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GitHubUpdater {
    private const OWNER = 'hhgym';
    private const REPOSITORY = 'Wettbewerbszeitleiste';
    private const REPOSITORY_URL = 'https://github.com/hhgym/Wettbewerbszeitleiste';
    private const API_LATEST_RELEASE = 'https://api.github.com/repos/hhgym/Wettbewerbszeitleiste/releases/latest';
    private const CACHE_KEY = 'wz_github_latest_release';
    private const CACHE_TTL = 21600; // 6 hours.

    public function hooks() {
        add_filter( 'update_plugins_github.com', array( $this, 'filterUpdate' ), 10, 4 );
        add_filter( 'plugins_api', array( $this, 'pluginInformation' ), 20, 3 );
        add_action( 'upgrader_process_complete', array( $this, 'clearCacheAfterUpgrade' ), 10, 2 );
    }

    public function filterUpdate( $update, $plugin_data, $plugin_file, $locales ) {
        unset( $locales );

        if ( empty( $plugin_data['UpdateURI'] ) || self::REPOSITORY_URL !== untrailingslashit( $plugin_data['UpdateURI'] ) ) {
            return $update;
        }

        if ( plugin_basename( WZ_FILE ) !== $plugin_file ) {
            return $update;
        }

        $release = $this->latestRelease();
        if ( ! $release ) {
            return false;
        }

        $version = $this->releaseVersion( $release );
        $package = $this->releasePackageUrl( $release, $version );

        if ( ! $version || ! $package ) {
            return false;
        }

        return array(
            'id'           => self::REPOSITORY_URL,
            'slug'         => 'wettbewerbszeitleiste',
            'version'      => $version,
            'new_version'  => $version,
            'url'          => $release['html_url'] ?? self::REPOSITORY_URL,
            'package'      => $package,
            'requires_php' => '7.4',
            'tested'       => '6.8',
        );
    }

    public function pluginInformation( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || 'wettbewerbszeitleiste' !== $args->slug ) {
            return $result;
        }

        $release = $this->latestRelease();
        if ( ! $release ) {
            return $result;
        }

        $version = $this->releaseVersion( $release );
        $package = $this->releasePackageUrl( $release, $version );

        if ( ! $version ) {
            return $result;
        }

        $info = new \stdClass();
        $info->name          = 'Wettbewerbszeitleiste';
        $info->slug          = 'wettbewerbszeitleiste';
        $info->version       = $version;
        $info->author        = '<a href="https://hhgym.de/">Heinrich-Hertz-Gymnasium Berlin</a>';
        $info->homepage      = self::REPOSITORY_URL;
        $info->requires      = '6.8';
        $info->requires_php  = '7.4';
        $info->tested        = '6.8';
        $info->last_updated  = isset( $release['published_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $release['published_at'] ) ) : '';
        $info->download_link = $package ?: '';
        $info->sections      = array(
            'description' => '<p>WordPress-Plugin zur Verwaltung und responsiven Darstellung schulischer Wettbewerbe und Termine als interaktive Zeitleiste.</p>',
            'changelog'   => $this->releaseNotesHtml( $release['body'] ?? '' ),
        );

        return $info;
    }

    public function clearCacheAfterUpgrade( $upgrader, $hook_extra ) {
        unset( $upgrader );

        if ( empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
            return;
        }

        $plugins = array();
        if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
            $plugins = $hook_extra['plugins'];
        } elseif ( ! empty( $hook_extra['plugin'] ) ) {
            $plugins = array( $hook_extra['plugin'] );
        }

        if ( in_array( plugin_basename( WZ_FILE ), $plugins, true ) ) {
            delete_site_transient( self::CACHE_KEY );
        }
    }

    private function latestRelease() {
        $cached = get_site_transient( self::CACHE_KEY );
        if ( is_array( $cached ) && ! empty( $cached['tag_name'] ) ) {
            return $cached;
        }

        $response = wp_remote_get(
            self::API_LATEST_RELEASE,
            array(
                'timeout' => 10,
                'headers' => array(
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'Wettbewerbszeitleiste/' . WZ_VERSION . '; ' . home_url( '/' ),
                ),
            )
        );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $release ) || empty( $release['tag_name'] ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
            return null;
        }

        set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
        return $release;
    }

    private function releaseVersion( array $release ) {
        if ( empty( $release['tag_name'] ) ) {
            return null;
        }

        $version = ltrim( trim( (string) $release['tag_name'] ), "vV" );
        return preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version ) ? $version : null;
    }

    private function releasePackageUrl( array $release, $version ) {
        if ( empty( $release['assets'] ) || ! is_array( $release['assets'] ) || ! $version ) {
            return null;
        }

        $preferred = array(
            'wettbewerbszeitleiste.zip',
            'wettbewerbszeitleiste-' . $version . '.zip',
        );

        foreach ( $preferred as $name ) {
            foreach ( $release['assets'] as $asset ) {
                if ( isset( $asset['name'], $asset['browser_download_url'] ) && $name === $asset['name'] ) {
                    return esc_url_raw( $asset['browser_download_url'] );
                }
            }
        }

        foreach ( $release['assets'] as $asset ) {
            if ( empty( $asset['name'] ) || empty( $asset['browser_download_url'] ) ) {
                continue;
            }
            if ( preg_match( '/^wettbewerbszeitleiste(?:-[0-9A-Za-z.+-]+)?\.zip$/i', $asset['name'] ) ) {
                return esc_url_raw( $asset['browser_download_url'] );
            }
        }

        return null;
    }

    private function releaseNotesHtml( $markdown ) {
        $markdown = trim( (string) $markdown );
        if ( '' === $markdown ) {
            return '<p>Keine Release Notes hinterlegt.</p>';
        }

        // Keep the plugin-information dialog safe and dependency-free. GitHub Markdown
        // is shown as readable plain text while preserving line breaks.
        return wpautop( esc_html( $markdown ) );
    }
}
