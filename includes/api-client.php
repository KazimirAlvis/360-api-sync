<?php

namespace ThreeSixty\ApiSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Api_Client {
	private const DEFAULT_API_BASE_URL = 'https://cmltutizsixpurslzfzl.supabase.co/functions/v1';

	/**
	 * @var array<int,string>
	 */
	private $runtime_warnings = array();

	/**
	 * @return array<string,mixed>
	 */
	public static function get_settings(): array {
		$defaults = array(
			'api_base_url' => self::DEFAULT_API_BASE_URL,
			'api_key'      => '',
			'site_slug'    => '',
			'enable_mock'  => 1,
			'enable_dry_run' => 0,
		);

		$settings = get_option( '360_api_sync_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings = wp_parse_args( $settings, $defaults );

		$settings['api_base_url'] = esc_url_raw( (string) $settings['api_base_url'] );
		if ( '' === $settings['api_base_url'] ) {
			$settings['api_base_url'] = self::DEFAULT_API_BASE_URL;
		}
		$settings['api_key']      = sanitize_text_field( (string) $settings['api_key'] );
		$settings['site_slug']    = sanitize_title( (string) $settings['site_slug'] );
		$settings['enable_mock']  = ! empty( $settings['enable_mock'] ) ? 1 : 0;
		$settings['enable_dry_run'] = ! empty( $settings['enable_dry_run'] ) ? 1 : 0;

		return $settings;
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function get_sync_payload() {
		$this->runtime_warnings = array();

		if ( $this->is_mock_mode() ) {
			$data = $this->load_mock_data( 'clinics.json' );
			if ( is_wp_error( $data ) ) {
				return $data;
			}

			if ( isset( $data['clinics'] ) && is_array( $data['clinics'] ) ) {
				return $data;
			}

			if ( array_values( $data ) === $data ) {
				return array(
					'site_slug' => (string) self::get_settings()['site_slug'],
					'condition' => '',
					'clinics'   => $data,
				);
			}

			return new \WP_Error( '360_api_sync_mock_unexpected_payload', __( 'Mock payload must include a clinics array.', '360-api-sync' ) );
		}

		return $this->get_remote_payload();
	}

	/**
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function get_clinics() {
		$payload = $this->get_sync_payload();
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$clinics = $payload['clinics'] ?? array();
		if ( ! is_array( $clinics ) ) {
			return new \WP_Error( '360_api_sync_unexpected_payload', __( 'Payload clinics field is invalid.', '360-api-sync' ) );
		}

		return $clinics;
	}

	public function is_mock_mode(): bool {
		$settings = self::get_settings();
		return ! empty( $settings['enable_mock'] );
	}

	/**
	 * @return array<int,string>
	 */
	public function get_runtime_warnings(): array {
		return $this->runtime_warnings;
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private function get_remote_payload() {
		$settings = self::get_settings();

		if ( empty( $settings['api_base_url'] ) || empty( $settings['site_slug'] ) ) {
			return new \WP_Error( '360_api_sync_missing_settings', __( 'API Base URL and Site Slug are required.', '360-api-sync' ) );
		}

		if ( empty( $settings['api_key'] ) ) {
			return new \WP_Error( '360_api_sync_missing_api_key', __( 'API Key is required when mock mode is disabled.', '360-api-sync' ) );
		}

		$api_url = trailingslashit( $settings['api_base_url'] ) . 'sync?site_slug=' . rawurlencode( (string) $settings['site_slug'] );

		$response = $this->request_sync_payload( $api_url, (string) $settings['api_key'], false );

		if ( is_wp_error( $response ) ) {
			$existing_data = $response->get_error_data();
			if ( ! is_array( $existing_data ) ) {
				$existing_data = array();
			}

			$existing_data['site_slug'] = (string) $settings['site_slug'];
			$existing_data['api_url']   = esc_url_raw( $api_url );
			$response->add_data( $existing_data );
			return $response;
		}

		$parsed = $this->parse_remote_response( $response, (string) $settings['site_slug'], $api_url );
		if ( ! is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$response_code = (int) wp_remote_retrieve_response_code( $response );
		$fallback_response = $this->request_sync_payload( $api_url, (string) $settings['api_key'], true );
		if ( is_wp_error( $fallback_response ) ) {
			return $fallback_response;
		}

		$fallback_parsed = $this->parse_remote_response( $fallback_response, (string) $settings['site_slug'], $api_url );
		if ( ! is_wp_error( $fallback_parsed ) ) {
			$this->runtime_warnings[] = sprintf(
				'Bearer fallback was used for /sync request (primary status %d). This compatibility path will be removed in a future release.',
				$response_code
			);
			return $fallback_parsed;
		}

		return $parsed;
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private function parse_remote_response( array $response, string $site_slug, string $api_url ) {
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return new \WP_Error(
				'360_api_sync_http_error',
				sprintf( 'API request failed with status %d.', $code ),
				array(
					'status'       => $code,
					'site_slug'    => $site_slug,
					'api_url'      => esc_url_raw( $api_url ),
					'body_snippet' => substr( wp_strip_all_tags( $body ), 0, 500 ),
				)
			);
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( '360_api_sync_invalid_json', __( 'API response was not valid JSON.', '360-api-sync' ) );
		}

		if ( ! isset( $data['clinics'] ) || ! is_array( $data['clinics'] ) ) {
			return new \WP_Error( '360_api_sync_unexpected_payload', __( 'API response must include a clinics array.', '360-api-sync' ) );
		}

		return $data;
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private function request_sync_payload( string $api_url, string $api_key, bool $include_bearer = false ) {
		$headers = array(
			'Accept'    => 'application/json',
			'x-api-key' => $api_key,
		);

		if ( $include_bearer ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		return wp_remote_get(
			esc_url_raw( $api_url ),
			array(
				'timeout' => 30,
				'headers' => $headers,
			)
		);
	}

	/**
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private function load_mock_data( string $file_name ) {
		$file_path = THREESIXTY_API_SYNC_PATH . 'mock-data/' . $file_name;
		if ( ! file_exists( $file_path ) ) {
			return new \WP_Error( '360_api_sync_mock_missing', sprintf( 'Mock file not found: %s', $file_name ) );
		}

		$contents = file_get_contents( $file_path );
		if ( false === $contents ) {
			return new \WP_Error( '360_api_sync_mock_read', sprintf( 'Unable to read mock file: %s', $file_name ) );
		}

		$data = json_decode( $contents, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( '360_api_sync_mock_invalid_json', sprintf( 'Invalid JSON in mock file: %s', $file_name ) );
		}

		return $data;
	}

}
