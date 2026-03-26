<?php

namespace ThreeSixty\ApiSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Clinic_Sync {

	/**
	 * @param array<int,array<string,mixed>> $clinics
	 * @return array<string,mixed>
	 */
	public function sync( array $clinics, string $last_sync = '', bool $dry_run = false ): array {
		$settings  = Api_Client::get_settings();
		$site_slug = sanitize_title( (string) ( $settings['site_slug'] ?? '' ) );
		$invalid_missing_required = 0;

		$results = array(
			'valid_clinics'  => 0,
			'invalid_clinics' => 0,
			'processed'      => 0,
			'skipped_unchanged' => 0,
			'skipped_invalid' => 0,
			'created'        => 0,
			'updated'        => 0,
			'drafted'        => 0,
			'temporary_created' => 0,
			'temporary_upgraded' => 0,
			'images_imported'=> 0,
			'errors'         => array(),
			'warnings'       => array(),
			'max_updated_at' => '',
		);

		$seen_temp_keys = array();

		foreach ( $clinics as $clinic ) {
			if ( ! is_array( $clinic ) ) {
				continue;
			}

			$clinic = $this->normalize_clinic_payload( $clinic );

			$organization_id = $this->resolve_organization_id( $clinic );
			$clinic_name     = $this->resolve_clinic_name( $clinic );
			$phone           = $this->resolve_phone( $clinic );
			$phone_for_key   = $this->normalize_phone_for_key( $phone );
			$clinic['clinic_name'] = $clinic_name;
			$clinic['phone']       = $phone;

			if ( '' === $organization_id && '' === $clinic_name ) {
				$results['skipped_invalid']++;
				$results['invalid_clinics']++;
				$invalid_missing_required++;
				continue;
			}

			$results['valid_clinics']++;

			$temp_key = $this->build_temp_key( 'clinic', $site_slug, $clinic_name, $phone_for_key );
			$is_temporary_input = '' === $organization_id;
			$clinic['organization_id'] = $organization_id;
			if ( '' !== $temp_key ) {
				$seen_temp_keys[ $temp_key ] = true;
			}

			$post_id = 0;
			if ( '' !== $organization_id ) {
				$post_id = $this->find_by_organization_id( $organization_id );
			}

			if ( $post_id <= 0 && '' !== $temp_key ) {
				$post_id = $this->find_by_temp_key( $temp_key );
			}

			if ( $post_id <= 0 && $is_temporary_input && '' !== $clinic_name ) {
				$post_id = $this->find_temporary_by_name( $clinic_name );
			}

			$clinic_is_active = $this->is_api_clinic_active( $clinic );
			if ( ! $clinic_is_active ) {
				if ( $post_id > 0 ) {
					if ( $dry_run ) {
						$results['drafted']++;
					} else {
						$status_update = wp_update_post(
							array(
								'ID'          => (int) $post_id,
								'post_status' => 'draft',
							),
							true
						);
						if ( ! is_wp_error( $status_update ) ) {
							$results['drafted']++;
						}
					}
				}

				continue;
			}

			$item_updated_at = sanitize_text_field( (string) ( $clinic['updated_at'] ?? '' ) );
			if ( ! empty( $item_updated_at ) && $this->is_more_recent( $item_updated_at, (string) $results['max_updated_at'] ) ) {
				$results['max_updated_at'] = $item_updated_at;
			}

			$incoming_addresses = $this->normalize_clinic_addresses( $this->collect_raw_addresses( $clinic ) );
			$incoming_clinic_info = $this->normalize_clinic_info( $this->collect_raw_clinic_info( $clinic ) );
			$incoming_reviews   = $this->normalize_clinic_reviews( $this->collect_raw_reviews( $clinic ) );

			if ( empty( $incoming_clinic_info ) ) {
				$alternate_info_keys = $this->detect_alternate_clinic_info_keys( $clinic );
				if ( ! empty( $alternate_info_keys ) ) {
					$results['warnings'][] = sprintf(
						'Clinic %s has no clinic_info payload. Non-empty alternate fields detected: %s',
						'' !== $organization_id ? $organization_id : ( '' !== $clinic_name ? $clinic_name : 'unknown' ),
						implode( ', ', $alternate_info_keys )
					);
				}
			}

			if ( $this->is_unchanged_since( $item_updated_at, $last_sync ) ) {
				$needs_address_backfill = $post_id > 0 && $this->should_backfill_addresses( $post_id, $incoming_addresses );
				$needs_clinic_info_backfill = $post_id > 0 && $this->should_backfill_clinic_info( $post_id, $incoming_clinic_info );
				$needs_review_backfill  = $post_id > 0 && $this->should_backfill_reviews( $post_id, $incoming_reviews );

				if ( $post_id > 0 && ! $needs_address_backfill && ! $needs_clinic_info_backfill && ! $needs_review_backfill ) {
					$results['skipped_unchanged']++;
					continue;
				}

				if ( $post_id > 0 ) {
					$backfill_targets = array();
					if ( $needs_address_backfill ) {
						$backfill_targets[] = 'addresses';
					}
					if ( $needs_clinic_info_backfill ) {
						$backfill_targets[] = 'clinic info';
					}
					if ( $needs_review_backfill ) {
						$backfill_targets[] = 'reviews';
					}

					$results['warnings'][] = sprintf(
						'Clinic %s unchanged timestamp but processed to backfill missing %s.',
						'' !== $organization_id ? $organization_id : (string) $temp_key,
						empty( $backfill_targets ) ? 'data' : implode( ' and ', $backfill_targets )
					);
				}
			}

			$results['processed']++;

			$is_new  = $post_id <= 0;
			$is_upgrading_temp = ( ! $is_new && '' !== $organization_id && $this->is_temporary_post( $post_id ) );

			$post_data = array(
				'post_title'   => '' !== $clinic_name ? $clinic_name : 'Temporary Clinic ' . substr( $temp_key, 0, 8 ),
				'post_content' => wp_kses_post( (string) ( $clinic['bio'] ?? '' ) ),
				'post_status'  => 'publish',
				'post_type'    => 'clinic',
			);

			if ( $is_new ) {
				if ( $dry_run ) {
					$results['created']++;
					if ( $is_temporary_input ) {
						$results['temporary_created']++;
						$results['warnings'][] = 'Clinic missing organization_id — created as temporary record.';
					}
					continue;
				}

				$post_id = wp_insert_post( $post_data, true );
				if ( is_wp_error( $post_id ) ) {
					$results['errors'][] = sprintf( 'Clinic %s insert failed: %s', $organization_id, $post_id->get_error_message() );
					continue;
				}
				$results['created']++;
			} else {
				if ( $dry_run ) {
					$results['updated']++;
					if ( $is_upgrading_temp ) {
						$results['temporary_upgraded']++;
						$results['warnings'][] = sprintf( 'Clinic upgraded from temporary record using organization_id %s.', $organization_id );
					}
					if ( $is_temporary_input ) {
						$results['warnings'][] = 'Clinic missing organization_id — updating as temporary record.';
					}
					continue;
				}

				$post_data['ID'] = $post_id;
				$updated         = wp_update_post( $post_data, true );
				if ( is_wp_error( $updated ) ) {
					$results['errors'][] = sprintf( 'Clinic %s update failed: %s', $organization_id, $updated->get_error_message() );
					continue;
				}
				$results['updated']++;
			}

			$this->save_meta( (int) $post_id, $clinic, $organization_id, $temp_key );

			if ( $is_temporary_input ) {
				$results['warnings'][] = 'Clinic missing organization_id — created as temporary record.';
			}

			if ( $is_new && $is_temporary_input ) {
				$results['temporary_created']++;
			}

			if ( $is_upgrading_temp ) {
				$results['temporary_upgraded']++;
				$results['warnings'][] = sprintf( 'Clinic upgraded from temporary record using organization_id %s.', $organization_id );
			}

			if ( ! empty( $clinic['logo_url'] ) ) {
				$image_org_key = '' !== $organization_id ? $organization_id : 'temp-' . substr( $temp_key, 0, 12 );
				$image_id = Image_Importer::import_clinic_logo( (string) $clinic['logo_url'], $image_org_key, (int) $post_id );
				if ( is_wp_error( $image_id ) ) {
					$results['warnings'][] = sprintf( 'Clinic image import failed: %s', $image_id->get_error_message() );
				} elseif ( function_exists( 'set_post_thumbnail' ) && $image_id > 0 ) {
					$results['images_imported']++;
					set_post_thumbnail( (int) $post_id, (int) $image_id );
					update_post_meta( (int) $post_id, 'clinic_logo', (int) $image_id );
					update_post_meta( (int) $post_id, '_clinic_logo_id', (int) $image_id );
					update_post_meta( (int) $post_id, 'clinic_logo_url', esc_url_raw( (string) $clinic['logo_url'] ) );
				}
			}

		}

		if ( $invalid_missing_required > 0 ) {
			$results['warnings'][] = sprintf(
				'%d invalid clinic records skipped (missing required fields).',
				$invalid_missing_required
			);
		}

		$stale_temp_clinics = get_posts(
			array(
				'post_type'      => 'clinic',
				'post_status'    => array( 'publish' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_360_is_temporary',
						'value' => '1',
					),
				),
			)
		);

		foreach ( $stale_temp_clinics as $stale_temp_id ) {
			$stale_temp_id  = (int) $stale_temp_id;
			$stale_temp_key = sanitize_text_field( (string) get_post_meta( $stale_temp_id, '_360_temp_key', true ) );

			if ( '' !== $stale_temp_key && isset( $seen_temp_keys[ $stale_temp_key ] ) ) {
				continue;
			}

			if ( $dry_run ) {
				$results['drafted']++;
				continue;
			}

			$updated = wp_update_post(
				array(
					'ID'          => $stale_temp_id,
					'post_status' => 'draft',
				),
				true
			);

			if ( ! is_wp_error( $updated ) ) {
				$results['drafted']++;
			}
		}

		return $results;
	}

	/**
	 * @param array<string,mixed> $clinic
	 */
	private function is_api_clinic_active( array $clinic ): bool {
		if ( array_key_exists( 'is_active', $clinic ) ) {
			return $this->normalize_active_flag( $clinic['is_active'] );
		}

		if ( array_key_exists( 'published', $clinic ) ) {
			return $this->normalize_active_flag( $clinic['published'] );
		}

		if ( array_key_exists( 'status', $clinic ) && is_string( $clinic['status'] ) ) {
			$status = strtolower( trim( $clinic['status'] ) );
			if ( in_array( $status, array( 'draft', 'inactive', 'unpublished', 'disabled', 'archived' ), true ) ) {
				return false;
			}

			if ( in_array( $status, array( 'publish', 'published', 'active', 'enabled' ), true ) ) {
				return true;
			}
		}

		return true;
	}

	/**
	 * @param mixed $value
	 */
	private function normalize_active_flag( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return (int) $value !== 0;
		}

		if ( is_string( $value ) ) {
			$normalized = strtolower( trim( $value ) );
			if ( in_array( $normalized, array( '0', 'false', 'no', 'inactive', 'draft', 'unpublished', 'disabled', 'archived' ), true ) ) {
				return false;
			}

			if ( in_array( $normalized, array( '1', 'true', 'yes', 'active', 'published', 'publish', 'enabled' ), true ) ) {
				return true;
			}
		}

		return true;
	}

	private function find_by_temp_key( string $temp_key ): int {
		if ( '' === $temp_key ) {
			return 0;
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'clinic',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_360_temp_key',
						'value' => $temp_key,
					),
				),
			)
		);

		if ( empty( $query->posts ) ) {
			return 0;
		}

		return (int) $query->posts[0];
	}

	private function is_temporary_post( int $post_id ): bool {
		return (bool) get_post_meta( $post_id, '_360_is_temporary', true );
	}

	private function find_by_organization_id( string $organization_id ): int {
		$query = new \WP_Query(
			array(
				'post_type'      => 'clinic',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'   => '_360_organization_id',
						'value' => $organization_id,
					),
					array(
						'key'   => 'clinic_organization_id',
						'value' => $organization_id,
					),
					array(
						'key'   => 'organization_id',
						'value' => $organization_id,
					),
				),
			)
		);

		if ( empty( $query->posts ) ) {
			return 0;
		}

		return (int) $query->posts[0];
	}

	/**
	 * @param array<string,mixed> $clinic
	 */
	private function save_meta( int $post_id, array $clinic, string $organization_id, string $temp_key ): void {
		$organization_id = sanitize_text_field( $organization_id );
		$bio             = wp_kses_post( (string) ( $clinic['bio'] ?? '' ) );
		$phone           = $this->resolve_phone( $clinic );

		$meta_map = array(
			'_360_phone'           => $phone,
			'_360_website_url'     => esc_url_raw( (string) ( $clinic['website_url'] ?? '' ) ),
			'_360_google_place_id' => sanitize_text_field( (string) ( $clinic['google_place_id'] ?? '' ) ),
			'_360_assessment_id'   => sanitize_text_field( (string) ( $clinic['assessment_id'] ?? '' ) ),
			'_360_updated_at'      => sanitize_text_field( (string) ( $clinic['updated_at'] ?? '' ) ),
			'clinic_phone'           => $phone,
			'clinic_bio'             => $bio,
			'clinic_google_place_id' => sanitize_text_field( (string) ( $clinic['google_place_id'] ?? '' ) ),
			'clinic_assessment_id'   => sanitize_text_field( (string) ( $clinic['assessment_id'] ?? '' ) ),
			'clinic_website'         => esc_url_raw( (string) ( $clinic['website_url'] ?? '' ) ),
			'clinic_updated_at'      => sanitize_text_field( (string) ( $clinic['updated_at'] ?? '' ) ),
			'google_place_id'        => sanitize_text_field( (string) ( $clinic['google_place_id'] ?? '' ) ),
			'_cpt360_clinic_phone'   => $phone,
			'_clinic_website_url'    => esc_url_raw( (string) ( $clinic['website_url'] ?? '' ) ),
			'_cpt360_assessment_id'  => sanitize_text_field( (string) ( $clinic['assessment_id'] ?? '' ) ),
			'_cpt360_clinic_bio'     => $bio,
		);

		foreach ( $meta_map as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		update_post_meta( $post_id, '_360_temp_key', $temp_key );
		if ( '' === $organization_id ) {
			update_post_meta( $post_id, '_360_is_temporary', 1 );
			delete_post_meta( $post_id, '_360_organization_id' );
			delete_post_meta( $post_id, 'organization_id' );
			delete_post_meta( $post_id, 'clinic_organization_id' );
		} else {
			update_post_meta( $post_id, '_360_organization_id', $organization_id );
			update_post_meta( $post_id, 'organization_id', $organization_id );
			update_post_meta( $post_id, 'clinic_organization_id', $organization_id );
			delete_post_meta( $post_id, '_360_is_temporary' );
		}

		$raw_addresses = $this->collect_raw_addresses( $clinic );
		$addresses     = $this->normalize_clinic_addresses( $raw_addresses );
		if ( ! empty( $addresses ) ) {
			update_post_meta( $post_id, '_360_addresses', $raw_addresses );
			update_post_meta( $post_id, 'clinic_addresses', $addresses );

			$primary_address = $this->build_primary_address( $addresses );
			if ( '' !== $primary_address ) {
				update_post_meta( $post_id, 'clinic_address', $primary_address );
			}
		}

		$states = $this->normalize_clinic_states( $clinic );
		if ( ! empty( $states ) ) {
			update_post_meta( $post_id, '_360_states', $states );
			update_post_meta( $post_id, 'clinic_states', $states );
			update_post_meta( $post_id, '_cpt360_clinic_state', (string) $states[0] );
		} else {
			delete_post_meta( $post_id, '_360_states' );
			delete_post_meta( $post_id, 'clinic_states' );
			delete_post_meta( $post_id, '_cpt360_clinic_state' );
		}

		$raw_clinic_info = $this->collect_raw_clinic_info( $clinic );
		if ( ! empty( $raw_clinic_info ) ) {
			$clinic_info = $this->sanitize_nested_data( $raw_clinic_info );
			$clinic_info = $this->normalize_clinic_info( is_array( $clinic_info ) ? $clinic_info : array() );
			update_post_meta( $post_id, '_360_clinic_info', $clinic_info );
			update_post_meta( $post_id, 'clinic_info', $clinic_info );
		} else {
			delete_post_meta( $post_id, '_360_clinic_info' );
			delete_post_meta( $post_id, 'clinic_info' );
		}

		$raw_reviews = $this->collect_raw_reviews( $clinic );
		if ( ! empty( $raw_reviews ) ) {
			$reviews = $this->sanitize_nested_data( $raw_reviews );
			$reviews = $this->normalize_clinic_reviews( is_array( $reviews ) ? $reviews : array() );
			update_post_meta( $post_id, '_360_reviews', $reviews );
			update_post_meta( $post_id, 'clinic_reviews', $reviews );
		}
	}

	/**
	 * @param array<int|string,mixed> $addresses
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_clinic_addresses( array $addresses ): array {
		$normalized = array();
		foreach ( $addresses as $address ) {
			$street = '';
			$line2  = '';
			$city   = '';
			$state  = '';
			$zip    = '';
			$lat    = '';
			$lng    = '';
			$full   = '';

			if ( is_string( $address ) ) {
				$parsed = $this->parse_address_string( $address );
				$street = (string) ( $parsed['street'] ?? '' );
				$city   = (string) ( $parsed['city'] ?? '' );
				$state  = (string) ( $parsed['state'] ?? '' );
				$zip    = (string) ( $parsed['zip'] ?? '' );
				$full   = trim( $address );
			} elseif ( is_array( $address ) ) {
				$street = (string) ( $address['street'] ?? $address['line1'] ?? '' );
				$line2  = (string) ( $address['line2'] ?? $address['suite'] ?? $address['unit'] ?? $address['address2'] ?? '' );
				$city   = (string) ( $address['city'] ?? '' );
				$state  = (string) ( $address['state'] ?? $address['region'] ?? '' );
				$zip    = (string) ( $address['zip'] ?? $address['postal_code'] ?? '' );
				$coords = $this->extract_address_coordinates( $address );
				$lat    = (string) ( $coords['lat'] ?? '' );
				$lng    = (string) ( $coords['lng'] ?? '' );
				$full   = (string) ( $address['full_address'] ?? $address['address'] ?? '' );

				if ( '' === $street && '' !== $full ) {
					$street = $full;
				}

				if ( ( '' === $state || '' === $city || '' === $zip ) && '' !== $full ) {
					$parsed = $this->parse_address_string( $full );
					if ( '' === $state ) {
						$state = (string) ( $parsed['state'] ?? '' );
					}
					if ( '' === $city ) {
						$city = (string) ( $parsed['city'] ?? '' );
					}
					if ( '' === $zip ) {
						$zip = (string) ( $parsed['zip'] ?? '' );
					}
				}
			}

			if ( '' === $street && '' === $city && '' === $state && '' === $zip && '' === $full ) {
				continue;
			}

			$state_code = $this->normalize_state_code( $state );
			if ( '' !== $state_code ) {
				$state = $state_code;
			}

			if ( '' === $full ) {
				$parts = array_filter(
					array_map(
						'sanitize_text_field',
						array( $street, $line2, $city, trim( $state . ( '' !== $zip ? ' ' . $zip : '' ) ) )
					)
				);
				$full  = implode( ', ', $parts );
			}

			$full = $this->ensure_us_address( $full );

			if ( '' === $lat || '' === $lng ) {
				$can_geocode = '' !== trim( $city ) && '' !== trim( $state ) && ( '' !== trim( $street ) || '' !== trim( $zip ) );
				if ( $can_geocode ) {
					if ( \defined( 'WP_DEBUG' ) && true === \constant( 'WP_DEBUG' ) ) {
						error_log( '[360 Sync] Geocoding: ' . $full );
					}

					$geocoded = $this->geocode_address( $full );
					if ( isset( $geocoded['lat'], $geocoded['lng'] ) ) {
						$lat = (string) $geocoded['lat'];
						$lng = (string) $geocoded['lng'];
					}

					usleep( 200000 );
				}
			}

			$normalized[] = array(
				'street' => sanitize_text_field( $street ),
				'line2'  => sanitize_text_field( $line2 ),
				'city'   => sanitize_text_field( $city ),
				'state'  => sanitize_text_field( $state ),
				'zip'    => sanitize_text_field( $zip ),
				'full_address' => sanitize_text_field( $full ),
				'address'      => sanitize_text_field( $full ),
				'lat'          => sanitize_text_field( $lat ),
				'lng'          => sanitize_text_field( $lng ),
				'latitude'     => sanitize_text_field( $lat ),
				'longitude'    => sanitize_text_field( $lng ),
			);
		}

		return $normalized;
	}

	/**
	 * @param array<string,mixed> $address
	 * @return array{lat:string,lng:string}
	 */
	private function extract_address_coordinates( array $address ): array {
		$lat = $this->normalize_coordinate_value( $address['lat'] ?? $address['latitude'] ?? null );
		$lng = $this->normalize_coordinate_value( $address['lng'] ?? $address['longitude'] ?? $address['lon'] ?? $address['long'] ?? null );

		if ( '' === $lat || '' === $lng ) {
			$containers = array(
				$address['coordinates'] ?? null,
				$address['coordinate'] ?? null,
				$address['location'] ?? null,
				$address['geo'] ?? null,
				$address['geolocation'] ?? null,
				$address['position'] ?? null,
				$address['geometry'] ?? null,
			);

			foreach ( $containers as $container ) {
				if ( ! is_array( $container ) ) {
					continue;
				}

				$candidate_lat = $this->normalize_coordinate_value( $container['lat'] ?? $container['latitude'] ?? null );
				$candidate_lng = $this->normalize_coordinate_value( $container['lng'] ?? $container['longitude'] ?? $container['lon'] ?? $container['long'] ?? null );

				if ( '' !== $candidate_lat && '' !== $candidate_lng ) {
					$lat = $candidate_lat;
					$lng = $candidate_lng;
					break;
				}

				if ( isset( $container['location'] ) && is_array( $container['location'] ) ) {
					$candidate_lat = $this->normalize_coordinate_value( $container['location']['lat'] ?? $container['location']['latitude'] ?? null );
					$candidate_lng = $this->normalize_coordinate_value( $container['location']['lng'] ?? $container['location']['longitude'] ?? $container['location']['lon'] ?? null );
					if ( '' !== $candidate_lat && '' !== $candidate_lng ) {
						$lat = $candidate_lat;
						$lng = $candidate_lng;
						break;
					}
				}

				$indexed = array_values( $container );
				if ( count( $indexed ) >= 2 ) {
					$first  = $this->normalize_coordinate_value( $indexed[0] ?? null );
					$second = $this->normalize_coordinate_value( $indexed[1] ?? null );
					if ( '' !== $first && '' !== $second ) {
						$first_f  = (float) $first;
						$second_f = (float) $second;

						// Handle GeoJSON-style [lng, lat] vs [lat, lng].
						if ( abs( $first_f ) > 90 && abs( $second_f ) <= 90 ) {
							$lat = (string) $second;
							$lng = (string) $first;
						} else {
							$lat = (string) $first;
							$lng = (string) $second;
						}
						break;
					}
				}
			}
		}

		if ( '' !== $lat && '' !== $lng ) {
			$lat_f = (float) $lat;
			$lng_f = (float) $lng;

			// Swap if values are reversed.
			if ( abs( $lat_f ) > 90 && abs( $lng_f ) <= 90 ) {
				$temp = $lat;
				$lat  = $lng;
				$lng  = $temp;
			}
		}

		// Reject invalid coordinates so map can use non-coordinate fallback safely.
		if ( '' !== $lat && '' !== $lng ) {
			$lat_f = (float) $lat;
			$lng_f = (float) $lng;
			if ( abs( $lat_f ) > 90 || abs( $lng_f ) > 180 ) {
				$lat = '';
				$lng = '';
			}
		}

		return array(
			'lat' => $lat,
			'lng' => $lng,
		);
	}

	/**
	 * @param mixed $value
	 */
	private function normalize_coordinate_value( $value ): string {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		if ( ! is_string( $value ) ) {
			return '';
		}

		$clean = trim( $value );
		if ( '' === $clean ) {
			return '';
		}

		$clean = preg_replace( '/[^0-9\.\-\+]/', '', $clean );
		if ( null === $clean || '' === $clean || ! is_numeric( $clean ) ) {
			return '';
		}

		return (string) $clean;
	}

	private function ensure_us_address( string $address ): string {
		$address = trim( $address );
		if ( '' === $address ) {
			return '';
		}

		if ( preg_match( '/\b(usa|u\.s\.a\.|united states|united states of america)\b/i', $address ) ) {
			return $address;
		}

		return rtrim( $address, ', ' ) . ', USA';
	}

	/**
	 * @return array{lat:float,lng:float}|array{}
	 */
	private function geocode_address( string $address ): array {
		$address = trim( $address );
		if ( '' === $address ) {
			return array();
		}

		$url = add_query_arg(
			array(
				'format'       => 'json',
				'limit'        => 1,
				'countrycodes' => 'us',
				'q'            => $address,
			),
			'https://nominatim.openstreetmap.org/search'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'User-Agent' => '360-api-sync/' . API360_SYNC_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return array();
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || empty( $decoded ) || ! isset( $decoded[0] ) || ! is_array( $decoded[0] ) ) {
			return array();
		}

		$lat = $this->normalize_coordinate_value( $decoded[0]['lat'] ?? null );
		$lng = $this->normalize_coordinate_value( $decoded[0]['lon'] ?? null );
		if ( '' === $lat || '' === $lng ) {
			return array();
		}

		$lat_f = (float) $lat;
		$lng_f = (float) $lng;
		if ( abs( $lat_f ) > 90 || abs( $lng_f ) > 180 ) {
			return array();
		}

		return array(
			'lat' => $lat_f,
			'lng' => $lng_f,
		);
	}

	/**
	 * @param array<int|string,mixed> $clinic_info
	 * @return array<int,array<string,string>>
	 */
	private function normalize_clinic_info( array $clinic_info ): array {
		$normalized = array();
		foreach ( $clinic_info as $item ) {
			$title       = '';
			$description = '';

			if ( is_string( $item ) ) {
				$description = sanitize_textarea_field( $item );
			} elseif ( is_array( $item ) ) {
				$title       = sanitize_text_field( (string) ( $item['title'] ?? $item['label'] ?? $item['name'] ?? $item['heading'] ?? '' ) );
				$description = sanitize_textarea_field( (string) ( $item['description'] ?? $item['value'] ?? $item['text'] ?? $item['content'] ?? $item['details'] ?? '' ) );

				if ( '' === $title && '' === $description ) {
					foreach ( $item as $k => $v ) {
						if ( ! is_string( $k ) || ! is_scalar( $v ) ) {
							continue;
						}

						$title       = sanitize_text_field( $k );
						$description = sanitize_textarea_field( (string) $v );
						break;
					}
				}
			}

			if ( '' === $title && '' === $description ) {
				continue;
			}

			$normalized[] = array(
				'title'       => $title,
				'description' => $description,
			);
		}

		return $normalized;
	}

	/**
	 * @param array<int|string,mixed> $reviews
	 * @return array<int,array<string,string>>
	 */
	private function normalize_clinic_reviews( array $reviews ): array {
		$normalized = array();
		foreach ( $reviews as $review ) {
			$reviewer = '';
			$text     = '';

			if ( is_string( $review ) ) {
				$text = sanitize_textarea_field( $review );
			} elseif ( is_array( $review ) ) {
				$reviewer = sanitize_text_field( (string) ( $review['reviewer'] ?? $review['author'] ?? $review['author_name'] ?? $review['patient'] ?? $review['patient_name'] ?? $review['patientName'] ?? $review['name'] ?? $review['user'] ?? '' ) );
				$text     = sanitize_textarea_field( (string) ( $review['review'] ?? $review['comment'] ?? $review['comment_text'] ?? $review['text'] ?? $review['content'] ?? $review['message'] ?? $review['body'] ?? $review['review_text'] ?? '' ) );
			}

			if ( '' === $reviewer && '' === $text ) {
				continue;
			}

			if ( '' === $reviewer && '' !== $text ) {
				$reviewer = 'Patient';
			}

			$normalized[] = array(
				'reviewer' => $reviewer,
				'review'   => $text,
			);
		}

		return $normalized;
	}

	/**
	 * @param array<int|string,mixed> $addresses
	 */
	private function build_primary_address( array $addresses ): string {
		if ( empty( $addresses ) ) {
			return '';
		}

		$first = reset( $addresses );
		if ( ! is_array( $first ) ) {
			return '';
		}

		$parts = array();
		foreach ( array( 'street', 'line1', 'line2', 'city', 'state', 'zip' ) as $part_key ) {
			if ( ! empty( $first[ $part_key ] ) ) {
				$parts[] = sanitize_text_field( (string) $first[ $part_key ] );
			}
		}

		return implode( ', ', $parts );
	}

	/**
	 * @param mixed $data
	 * @return mixed
	 */
	private function sanitize_nested_data( $data ) {
		if ( is_array( $data ) ) {
			$sanitized = array();
			foreach ( $data as $key => $value ) {
				$sanitized_key             = is_string( $key ) ? sanitize_key( $key ) : $key;
				$sanitized[ $sanitized_key ] = $this->sanitize_nested_data( $value );
			}
			return $sanitized;
	}

		if ( is_scalar( $data ) ) {
			return sanitize_text_field( (string) $data );
		}

		return '';
	}

	private function is_more_recent( string $candidate, string $current ): bool {
		$candidate_time = strtotime( $candidate );
		$current_time   = strtotime( $current );

		if ( false === $candidate_time ) {
			return false;
		}

		if ( false === $current_time ) {
			return true;
		}

		return $candidate_time > $current_time;
	}

	private function is_unchanged_since( string $updated_at, string $last_sync ): bool {
		if ( '' === $last_sync || '' === $updated_at ) {
			return false;
		}

		$updated_time = strtotime( $updated_at );
		$last_time    = strtotime( $last_sync );

		if ( false === $updated_time || false === $last_time ) {
			return false;
		}

		return $updated_time <= $last_time;
	}

	/**
	 * @param array<string,mixed> $clinic
	 * @return array<string,mixed>
	 */
	private function normalize_clinic_payload( array $clinic ): array {
		if ( isset( $clinic['clinic'] ) && is_array( $clinic['clinic'] ) ) {
			$nested = $clinic['clinic'];
			if ( is_array( $nested ) ) {
				$clinic = $this->merge_prefer_non_empty( $clinic, $nested );
			}
		}

		if ( isset( $clinic['details'] ) && is_array( $clinic['details'] ) ) {
			$nested = $clinic['details'];
			if ( is_array( $nested ) ) {
				$clinic = $this->merge_prefer_non_empty( $clinic, $nested );
			}
		}

		return $clinic;
	}

	/**
	 * @param array<string,mixed> $clinic
	 */
	private function resolve_clinic_name( array $clinic ): string {
		$candidates = array(
			$clinic['clinic_name'] ?? '',
			$clinic['name'] ?? '',
			$clinic['title'] ?? '',
			$clinic['organization_name'] ?? '',
		);

		foreach ( $candidates as $candidate ) {
			$value = sanitize_text_field( (string) $candidate );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $clinic
	 */
	private function resolve_phone( array $clinic ): string {
		$candidates = array(
			$clinic['phone'] ?? '',
			$clinic['clinic_phone'] ?? '',
			$clinic['phone_number'] ?? '',
			$clinic['telephone'] ?? '',
		);

		foreach ( $candidates as $candidate ) {
			$value = sanitize_text_field( (string) $candidate );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $target
	 * @param array<string,mixed> $source
	 * @return array<string,mixed>
	 */
	private function merge_prefer_non_empty( array $target, array $source ): array {
		foreach ( $source as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			$existing = $target[ $key ] ?? null;
			$existing_empty = null === $existing || '' === trim( (string) $existing ) || ( is_array( $existing ) && empty( $existing ) );
			$value_empty    = null === $value || '' === trim( (string) $value ) || ( is_array( $value ) && empty( $value ) );

			if ( $existing_empty && ! $value_empty ) {
				$target[ $key ] = $value;
			}
		}

		return $target;
	}

	/**
	 * @param array<string,mixed> $clinic
	 */
	private function resolve_organization_id( array $clinic ): string {
		$candidates = array(
			$clinic['organization_id'] ?? '',
			$clinic['organizationId'] ?? '',
			$clinic['organizationID'] ?? '',
			$clinic['clinic_organization_id'] ?? '',
			$clinic['org_id'] ?? '',
		);

		if ( isset( $clinic['organization'] ) ) {
			if ( is_string( $clinic['organization'] ) || is_numeric( $clinic['organization'] ) ) {
				$candidates[] = $clinic['organization'];
			} elseif ( is_array( $clinic['organization'] ) ) {
				$candidates[] = $clinic['organization']['id'] ?? '';
				$candidates[] = $clinic['organization']['organization_id'] ?? '';
			}
		}

		foreach ( $candidates as $candidate ) {
			$value = sanitize_text_field( (string) $candidate );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $clinic
	 * @return array<int,string>
	 */
	private function normalize_clinic_states( array $clinic ): array {
		$values = array();

		if ( isset( $clinic['states'] ) ) {
			if ( is_array( $clinic['states'] ) ) {
				$values = array_merge( $values, $clinic['states'] );
			} elseif ( is_string( $clinic['states'] ) ) {
				$parts = preg_split( '/[,|]/', $clinic['states'] );
				if ( is_array( $parts ) ) {
					$values = array_merge( $values, $parts );
				}
			}
		}

		if ( isset( $clinic['state'] ) ) {
			$values[] = $clinic['state'];
		}

		$raw_addresses = $this->collect_raw_addresses( $clinic );
		foreach ( $raw_addresses as $address ) {
			if ( is_array( $address ) ) {
				$values[] = $address['state'] ?? $address['region'] ?? '';
			} elseif ( is_string( $address ) ) {
				$parsed = $this->parse_address_string( $address );
				$values[] = $parsed['state'] ?? '';
			}
		}

		$normalized = array();
		foreach ( $values as $value ) {
			$abbr = $this->normalize_state_code( (string) $value );
			if ( '' !== $abbr ) {
				$normalized[] = $abbr;
			}
		}

		$normalized = array_values( array_unique( $normalized ) );
		return $normalized;
	}

	private function normalize_state_code( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		$abbr_to_name = array(
			'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
			'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
			'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
			'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
			'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
			'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
			'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
			'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
			'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
			'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
			'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
			'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
			'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'DC' => 'District of Columbia',
		);

		$upper = strtoupper( $value );
		if ( isset( $abbr_to_name[ $upper ] ) ) {
			return $upper;
		}

		$letters_only = strtoupper( preg_replace( '/[^A-Za-z]/', '', $value ) );
		if ( 2 === strlen( $letters_only ) && isset( $abbr_to_name[ $letters_only ] ) ) {
			return $letters_only;
		}

		$name_to_abbr = array();
		foreach ( $abbr_to_name as $abbr => $name ) {
			$name_to_abbr[ strtolower( $name ) ] = $abbr;
		}

		$normalized_name = strtolower( trim( preg_replace( '/\s+/', ' ', preg_replace( '/[^A-Za-z\s]/', ' ', $value ) ) ) );
		if ( isset( $name_to_abbr[ $normalized_name ] ) ) {
			return $name_to_abbr[ $normalized_name ];
		}

		$haystack = ' ' . $normalized_name . ' ';
		foreach ( $name_to_abbr as $name => $abbr ) {
			if ( false !== strpos( $haystack, ' ' . $name . ' ' ) ) {
				return $abbr;
			}
		}

		if ( preg_match_all( '/\b([A-Za-z]{2})\b/', $value, $matches ) ) {
			$tokens = $matches[1] ?? array();
			for ( $i = count( $tokens ) - 1; $i >= 0; $i-- ) {
				$token = strtoupper( (string) $tokens[ $i ] );
				if ( isset( $abbr_to_name[ $token ] ) ) {
					return $token;
				}
			}
		}

		return '';
	}

	private function build_temp_key( string $entity, string $site_slug, string $name, string $secondary ): string {
		return md5(
			strtolower( trim( $entity ) ) . '|' .
			strtolower( trim( $site_slug ) ) . '|' .
			strtolower( trim( $name ) ) . '|' .
			strtolower( trim( $secondary ) )
		);
	}

	private function normalize_phone_for_key( string $phone ): string {
		$digits_only = preg_replace( '/\D+/', '', $phone );
		if ( ! is_string( $digits_only ) ) {
			return '';
		}

		if ( '' === $digits_only ) {
			return '';
		}

		if ( strlen( $digits_only ) > 10 ) {
			$digits_only = substr( $digits_only, -10 );
		}

		return $digits_only;
	}

	private function find_temporary_by_name( string $clinic_name ): int {
		$clinic_name = sanitize_text_field( $clinic_name );
		if ( '' === $clinic_name ) {
			return 0;
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'clinic',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'title'          => $clinic_name,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_360_is_temporary',
						'value' => '1',
					),
				),
			)
		);

		if ( empty( $query->posts ) ) {
			return 0;
		}

		return (int) $query->posts[0];
	}

	/**
	 * @param array<int,array<string,string>> $incoming_addresses
	 */
	private function should_backfill_addresses( int $post_id, array $incoming_addresses ): bool {
		if ( $post_id <= 0 || empty( $incoming_addresses ) ) {
			return false;
		}

		$existing = get_post_meta( $post_id, 'clinic_addresses', true );
		if ( is_array( $existing ) ) {
			return empty( $existing );
		}

		if ( is_string( $existing ) ) {
			return '' === trim( $existing );
		}

		return empty( $existing );
	}

	/**
	 * @param array<int,array<string,string>> $incoming_reviews
	 */
	private function should_backfill_reviews( int $post_id, array $incoming_reviews ): bool {
		if ( $post_id <= 0 || empty( $incoming_reviews ) ) {
			return false;
		}

		$existing = get_post_meta( $post_id, 'clinic_reviews', true );
		if ( is_array( $existing ) ) {
			return empty( $existing );
		}

		if ( is_string( $existing ) ) {
			return '' === trim( $existing );
		}

		return empty( $existing );
	}

	/**
	 * @param array<int,array<string,string>> $incoming_clinic_info
	 */
	private function should_backfill_clinic_info( int $post_id, array $incoming_clinic_info ): bool {
		if ( $post_id <= 0 || empty( $incoming_clinic_info ) ) {
			return false;
		}

		$existing = get_post_meta( $post_id, 'clinic_info', true );
		if ( is_array( $existing ) ) {
			return empty( $existing );
		}

		if ( is_string( $existing ) ) {
			return '' === trim( $existing );
		}

		return empty( $existing );
	}

	/**
	 * @param array<string,mixed> $clinic
	 * @return array<int,string>
	 */
	private function extract_address_lines( array $clinic ): array {
		$source = $clinic['clinic_addresses'] ?? ( $clinic['addresses'] ?? '' );
		$lines  = array();

		if ( is_string( $source ) ) {
			$parts = preg_split( '/\r\n|\r|\n/', $source );
			if ( is_array( $parts ) ) {
				$lines = $parts;
			}
		} elseif ( is_array( $source ) ) {
			foreach ( $source as $item ) {
				if ( is_string( $item ) ) {
					$lines[] = $item;
					continue;
				}

				if ( ! is_array( $item ) ) {
					continue;
				}

				$full = (string) ( $item['full_address'] ?? $item['address'] ?? '' );
				if ( '' === trim( $full ) ) {
					$parts = array_filter(
						array(
							(string) ( $item['street'] ?? $item['line1'] ?? '' ),
							(string) ( $item['line2'] ?? '' ),
							(string) ( $item['city'] ?? '' ),
							(string) ( $item['state'] ?? '' ),
							(string) ( $item['zip'] ?? $item['postal_code'] ?? '' ),
						),
						static function ( $value ) {
							return '' !== trim( (string) $value );
						}
					);

					$full = implode( ', ', array_map( 'trim', $parts ) );
				}

				if ( '' !== trim( $full ) ) {
					$lines[] = $full;
				}
			}
		}

		$lines = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $line ) {
							return sanitize_text_field( trim( (string) $line ) );
						},
						$lines
					),
					static function ( $line ) {
						return '' !== $line;
					}
				)
			)
		);

		return $lines;
	}

	/**
	 * @param array<string,mixed> $clinic
	 * @return array<int|string,mixed>
	 */
	private function collect_raw_addresses( array $clinic ): array {
		$source = $clinic['clinic_addresses'] ?? ( $clinic['addresses'] ?? array() );
		if ( is_array( $source ) ) {
			return $source;
		}

		if ( is_string( $source ) ) {
			$parts = preg_split( '/\r\n|\r|\n/', $source );
			return is_array( $parts ) ? $parts : array();
		}

		return array();
	}

	/**
	 * @param array<string,mixed> $clinic
	 * @return array<int|string,mixed>
	 */
	private function collect_raw_reviews( array $clinic ): array {
		$sources = array(
			$clinic['reviews'] ?? null,
			$clinic['clinic_reviews'] ?? null,
			$clinic['patient_reviews'] ?? null,
			$clinic['testimonials'] ?? null,
		);

		foreach ( $sources as $source ) {
			if ( is_string( $source ) && '' !== trim( $source ) ) {
				return array( $source );
			}

			if ( is_array( $source ) && isset( $source['reviews'] ) && is_array( $source['reviews'] ) && ! empty( $source['reviews'] ) ) {
				return $source['reviews'];
			}

			if ( is_array( $source ) && isset( $source['items'] ) && is_array( $source['items'] ) && ! empty( $source['items'] ) ) {
				return $source['items'];
			}

			if ( is_array( $source ) && $this->looks_like_review_item( $source ) ) {
				return array( $source );
			}

			if ( is_array( $source ) && ! empty( $source ) ) {
				return $source;
			}
		}

		return array();
	}

	/**
	 * @param array<string,mixed> $clinic
	 * @return array<int|string,mixed>
	 */
	private function collect_raw_clinic_info( array $clinic ): array {
		if ( ! array_key_exists( 'clinic_info', $clinic ) ) {
			return array();
		}

		$source = $clinic['clinic_info'];

		if ( is_string( $source ) ) {
			$source = trim( $source );
			return '' !== $source ? array( $source ) : array();
		}

		if ( is_array( $source ) ) {
			if ( isset( $source['clinic_info'] ) && is_array( $source['clinic_info'] ) ) {
				return $source['clinic_info'];
			}

			if ( isset( $source['items'] ) && is_array( $source['items'] ) ) {
				return $source['items'];
			}

			return $source;
		}

		return array();
	}

	/**
	 * @param array<string,mixed> $clinic
	 * @return array<int,string>
	 */
	private function detect_alternate_clinic_info_keys( array $clinic ): array {
		$alternate_keys = array(
			'about',
			'about_info',
			'about_items',
			'about_repeater',
			'about_section',
			'highlights',
			'info',
			'patient_info',
			'clinicInformation',
			'clinic_information',
		);

		$detected = array();
		foreach ( $alternate_keys as $key ) {
			if ( ! array_key_exists( $key, $clinic ) ) {
				continue;
			}

			$value = $clinic[ $key ];
			if ( $this->has_non_empty_value( $value ) ) {
				$detected[] = $key;
			}
		}

		return $detected;
	}

	/**
	 * @param mixed $value
	 */
	private function has_non_empty_value( $value ): bool {
		if ( is_string( $value ) ) {
			return '' !== trim( $value );
		}

		if ( is_array( $value ) ) {
			if ( empty( $value ) ) {
				return false;
			}

			foreach ( $value as $item ) {
				if ( $this->has_non_empty_value( $item ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param array<int|string,mixed> $item
	 */
	private function looks_like_review_item( array $item ): bool {
		$review_keys = array(
			'review',
			'comment',
			'comment_text',
			'text',
			'content',
			'message',
			'body',
			'review_text',
		);

		foreach ( $review_keys as $key ) {
			if ( array_key_exists( $key, $item ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<string,string>
	 */
	private function parse_address_string( string $address ): array {
		$address = trim( $address );
		if ( '' === $address ) {
			return array(
				'street' => '',
				'city'   => '',
				'state'  => '',
				'zip'    => '',
			);
		}

		$parts = array_map( 'trim', explode( ',', $address ) );
		if ( count( $parts ) < 2 ) {
			$state = $this->normalize_state_code( $address );
			$zip   = '';
			if ( preg_match( '/(\d{5}(?:-\d{4})?)$/', $address, $zip_match ) ) {
				$zip = (string) ( $zip_match[1] ?? '' );
			}

			return array(
				'street' => $address,
				'city'   => '',
				'state'  => sanitize_text_field( $state ),
				'zip'    => sanitize_text_field( $zip ),
			);
		}

		$street = array_shift( $parts );
		$city   = count( $parts ) > 1 ? array_shift( $parts ) : '';
		$state_zip = implode( ' ', $parts );

		$state = '';
		$zip   = '';
		if ( preg_match( '/([A-Za-z]{2})\s*(\d{5}(?:-\d{4})?)?$/', $state_zip, $matches ) ) {
			$state = $matches[1] ?? '';
			$zip   = $matches[2] ?? '';
		}

		if ( '' === $state ) {
			$state = $this->normalize_state_code( $state_zip );
		}

		return array(
			'street' => sanitize_text_field( (string) $street ),
			'city'   => sanitize_text_field( (string) $city ),
			'state'  => sanitize_text_field( (string) $state ),
			'zip'    => sanitize_text_field( (string) $zip ),
		);
	}
}
