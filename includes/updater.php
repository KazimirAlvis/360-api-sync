<?php

namespace ThreeSixty\ApiSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Updater {
	private const MANIFEST_URL = 'https://raw.githubusercontent.com/KazimirAlvis/360-api-sync/main/plugin-manifest.json';
	private const SLUG         = '360-api-sync';

	public static function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'rename_github_package' ), 10, 4 );
	}

	/**
	 * @param object $transient
	 * @return object
	 */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		if ( empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}

		$manifest = self::get_manifest();
		if ( ! is_array( $manifest ) ) {
			return $transient;
		}

		$plugin_file = plugin_basename( THREESIXTY_API_SYNC_PATH . '360-api-sync.php' );
		$new_version = sanitize_text_field( (string) ( $manifest['version'] ?? '' ) );
		$package     = esc_url_raw( (string) ( $manifest['download_url'] ?? '' ) );
		$homepage    = esc_url_raw( (string) ( $manifest['homepage'] ?? '' ) );

		if ( '' === $new_version || '' === $package ) {
			return $transient;
		}

		if ( version_compare( API360_SYNC_VERSION, $new_version, '>=' ) ) {
			if ( isset( $transient->response[ $plugin_file ] ) ) {
				unset( $transient->response[ $plugin_file ] );
			}
			return $transient;
		}

		$transient->response[ $plugin_file ] = (object) array(
			'id'          => $homepage,
			'slug'        => self::SLUG,
			'plugin'      => $plugin_file,
			'new_version' => $new_version,
			'url'         => $homepage,
			'package'     => $package,
			'tested'      => sanitize_text_field( (string) ( $manifest['tested'] ?? '' ) ),
			'requires'    => sanitize_text_field( (string) ( $manifest['requires'] ?? '' ) ),
			'requires_php'=> sanitize_text_field( (string) ( $manifest['requires_php'] ?? '' ) ),
		);

		return $transient;
	}

	/**
	 * @param false|object|array<string,mixed> $result
	 * @param object $args
	 * @return false|object|array<string,mixed>
	 */
	public static function plugins_api( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || self::SLUG !== (string) $args->slug ) {
			return $result;
		}

		$manifest = self::get_manifest();
		if ( ! is_array( $manifest ) ) {
			return $result;
		}

		$sections = array();
		if ( isset( $manifest['sections'] ) && is_array( $manifest['sections'] ) ) {
			foreach ( $manifest['sections'] as $key => $value ) {
				if ( is_string( $key ) && is_string( $value ) ) {
					$sections[ sanitize_key( $key ) ] = wp_kses_post( $value );
				}
			}
		}

		return (object) array(
			'name'          => sanitize_text_field( (string) ( $manifest['name'] ?? '360 API Sync' ) ),
			'slug'          => self::SLUG,
			'version'       => sanitize_text_field( (string) ( $manifest['version'] ?? API360_SYNC_VERSION ) ),
			'author'        => wp_kses_post( (string) ( $manifest['author'] ?? '' ) ),
			'author_profile'=> esc_url_raw( (string) ( $manifest['author_profile'] ?? '' ) ),
			'homepage'      => esc_url_raw( (string) ( $manifest['homepage'] ?? '' ) ),
			'download_link' => esc_url_raw( (string) ( $manifest['download_url'] ?? '' ) ),
			'requires'      => sanitize_text_field( (string) ( $manifest['requires'] ?? '' ) ),
			'tested'        => sanitize_text_field( (string) ( $manifest['tested'] ?? '' ) ),
			'requires_php'  => sanitize_text_field( (string) ( $manifest['requires_php'] ?? '' ) ),
			'last_updated'  => sanitize_text_field( (string) ( $manifest['last_updated'] ?? '' ) ),
			'sections'      => $sections,
		);
	}

	/**
	 * @param array<string,mixed> $hook_extra
	 */
	public static function rename_github_package( string $source, string $remote_source, $upgrader, array $hook_extra ): string {
		if ( empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return $source;
		}

		$plugin = (string) ( $hook_extra['plugin'] ?? '' );
		if ( '' !== $plugin && plugin_basename( THREESIXTY_API_SYNC_PATH . '360-api-sync.php' ) !== $plugin ) {
			return $source;
		}

		$source_basename = basename( $source );
		if ( self::SLUG === $source_basename ) {
			return $source;
		}

		$target = trailingslashit( dirname( $source ) ) . self::SLUG;
		if ( @rename( $source, $target ) ) {
			return $target;
		}

		return $source;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private static function get_manifest(): ?array {
		$response = wp_remote_get(
			self::MANIFEST_URL,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return null;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return null;
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		return $decoded;
	}
}
