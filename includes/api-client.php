<?php

namespace ThreeSixty\ApiSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Api_Client {

	/**
	 * @return array<string,mixed>
	 */
	public static function get_settings(): array {
		$defaults = array(
			'api_base_url' => '',
			'api_key'      => '',
			'site_slug'    => '',
			'enable_mock'  => 1,
		);

		$settings = get_option( '360_api_sync_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings = wp_parse_args( $settings, $defaults );

		$settings['api_base_url'] = esc_url_raw( (string) $settings['api_base_url'] );
		$settings['api_key']      = sanitize_text_field( (string) $settings['api_key'] );
		$settings['site_slug']    = sanitize_title( (string) $settings['site_slug'] );
		$settings['enable_mock']  = ! empty( $settings['enable_mock'] ) ? 1 : 0;

		return $settings;
	}

	/**
	 * @param string $updated_since ISO 8601 string.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function get_clinics( string $updated_since = '' ) {
		if ( $this->is_mock_mode() ) {
			$data = $this->load_mock_data( 'clinics.json' );
			if ( is_wp_error( $data ) ) {
				return $data;
			}

			return $this->filter_updated_since( $data, $updated_since );
		}

		return $this->get_remote_resource( 'clinics', $updated_since );
	}

	/**
	 * @param string $updated_since ISO 8601 string.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function get_doctors( string $updated_since = '' ) {
		if ( $this->is_mock_mode() ) {
			$data = $this->load_mock_data( 'doctors.json' );
			if ( is_wp_error( $data ) ) {
				return $data;
			}

			return $this->filter_updated_since( $data, $updated_since );
		}

		return $this->get_remote_resource( 'doctors', $updated_since );
	}

	public function is_mock_mode(): bool {
		$settings = self::get_settings();
		return ! empty( $settings['enable_mock'] );
	}

	/**
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private function get_remote_resource( string $resource, string $updated_since = '' ) {
		$settings = self::get_settings();

		if ( empty( $settings['api_base_url'] ) || empty( $settings['site_slug'] ) ) {
			return new \WP_Error( '360_api_sync_missing_settings', __( 'API Base URL and Site Slug are required.', '360-api-sync' ) );
		}

		$endpoint = trailingslashit( $settings['api_base_url'] ) . 'api/' . $settings['site_slug'] . '/' . $resource;
		$params   = array();

		if ( ! empty( $updated_since ) ) {
			$params['updated_since'] = $updated_since;
		}

		if ( ! empty( $params ) ) {
			$endpoint = add_query_arg( $params, $endpoint );
		}

		$args = array(
			'timeout' => 20,
			'headers' => array(
				'Accept' => 'application/json',
			),
		);

		if ( ! empty( $settings['api_key'] ) ) {
			$args['headers']['x-api-key']     = $settings['api_key'];
			$args['headers']['Authorization'] = 'Bearer ' . $settings['api_key'];
		}

		$response = wp_remote_get( esc_url_raw( $endpoint ), $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'360_api_sync_http_error',
				sprintf( 'API request failed with status %d.', $code ),
				array( 'body' => $body )
			);
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( '360_api_sync_invalid_json', __( 'API response was not valid JSON.', '360-api-sync' ) );
		}

		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$data = $data['data'];
		}

		if ( array_values( $data ) !== $data ) {
			return new \WP_Error( '360_api_sync_unexpected_payload', __( 'API response format was unexpected.', '360-api-sync' ) );
		}

		return $this->filter_updated_since( $data, $updated_since );
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

	/**
	 * @param array<int,array<string,mixed>> $items
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_updated_since( array $items, string $updated_since ): array {
		if ( empty( $updated_since ) ) {
			return $items;
		}

		$threshold = strtotime( $updated_since );
		if ( false === $threshold ) {
			return $items;
		}

		$filtered = array_filter(
			$items,
			static function ( $item ) use ( $threshold ) {
				if ( ! is_array( $item ) || empty( $item['updated_at'] ) ) {
					return true;
				}

				$item_time = strtotime( (string) $item['updated_at'] );
				if ( false === $item_time ) {
					return true;
				}

				return $item_time > $threshold;
			}
		);

		return array_values( $filtered );
	}
}
