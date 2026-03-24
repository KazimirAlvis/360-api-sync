<?php

namespace ThreeSixty\ApiSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Doctor_Sync {

	/**
	 * @param array<int,array<string,mixed>> $clinics
	 * @return array<string,mixed>
	 */
	public function sync( array $clinics, string $last_sync = '', bool $dry_run = false ): array {
		$settings  = Api_Client::get_settings();
		$site_slug = sanitize_title( (string) ( $settings['site_slug'] ?? '' ) );

		$results = array(
			'processed'      => 0,
			'skipped_unchanged' => 0,
			'skipped_invalid' => 0,
			'created'        => 0,
			'updated'        => 0,
			'drafted'        => 0,
			'temporary_created' => 0,
			'temporary_upgraded' => 0,
			'images_imported'=> 0,
			'missing_clinic' => 0,
			'errors'         => array(),
			'warnings'       => array(),
			'max_updated_at' => '',
		);

		$doctor_id_present_count = 0;
		$doctor_id_missing_count = 0;
		$doctor_id_key_seen      = false;
		$doctor_id_first_value   = null;

		$seen_doctor_ids   = array();
		$seen_doctor_slugs = array();

		foreach ( $clinics as $clinic ) {
			if ( ! is_array( $clinic ) ) {
				continue;
			}

			$clinic          = $this->normalize_clinic_payload( $clinic );
			$organization_id = $this->resolve_organization_id( $clinic );
			$doctors         = $clinic['doctors'] ?? ( $clinic['providers'] ?? array() );
			if ( ! is_array( $doctors ) ) {
				continue;
			}

			$doctor_identity_totals = array();
			foreach ( $doctors as $doctor_index_item ) {
				if ( ! is_array( $doctor_index_item ) ) {
					continue;
				}

				$idx_slug  = sanitize_title( (string) ( $doctor_index_item['doctor_slug'] ?? ( $doctor_index_item['slug'] ?? '' ) ) );
				$idx_name  = $this->resolve_doctor_name( $doctor_index_item );
				$idx_title = sanitize_text_field( (string) ( $doctor_index_item['title'] ?? '' ) );
				$idx_org   = $this->resolve_organization_id( $doctor_index_item, $organization_id );

				if ( '' === $idx_slug && '' === $idx_name ) {
					continue;
				}

				$idx_seed = strtolower( trim( $idx_org . '|' . $idx_slug . '|' . $idx_name . '|' . $idx_title ) );
				$doctor_identity_totals[ $idx_seed ] = (int) ( $doctor_identity_totals[ $idx_seed ] ?? 0 ) + 1;
			}

			$doctor_identity_counts = array();
			$used_post_ids          = array();

			foreach ( $doctors as $doctor ) {
				if ( ! is_array( $doctor ) ) {
					continue;
				}

				if ( array_key_exists( 'doctor_id', $doctor ) ) {
					$doctor_id_key_seen = true;
					if ( null === $doctor_id_first_value ) {
						$doctor_id_first_value = sanitize_text_field( (string) $doctor['doctor_id'] );
					}
				}

				$doctor_slug = sanitize_title( (string) ( $doctor['doctor_slug'] ?? ( $doctor['slug'] ?? '' ) ) );
				$doctor_id   = $this->resolve_doctor_id( $doctor );
				$doctor_name = $this->resolve_doctor_name( $doctor );
				$doctor_title = sanitize_text_field( (string) ( $doctor['title'] ?? '' ) );
				if ( '' === $doctor_slug && '' === $doctor_name ) {
					$results['skipped_invalid']++;
					$results['warnings'][] = 'Doctor record skipped: missing both doctor_slug and doctor_name.';
					continue;
				}

				if ( '' !== $doctor_id ) {
					$doctor_id_present_count++;
				} else {
					$doctor_id_missing_count++;
				}

				$doctor['organization_id'] = $this->resolve_organization_id( $doctor, $organization_id );
				$doctor['doctor_name']     = $doctor_name;

				if ( '' !== $doctor_id ) {
					$seen_doctor_ids[ $doctor_id ] = true;
				} elseif ( '' !== $doctor_slug ) {
					$seen_doctor_slugs[ $doctor_slug ] = true;
				}

				$identity_seed = strtolower(
					trim(
						(string) $doctor['organization_id'] . '|' .
						$doctor_slug . '|' .
						$doctor_name . '|' .
						$doctor_title
					)
				);
				$doctor_identity_counts[ $identity_seed ] = (int) ( $doctor_identity_counts[ $identity_seed ] ?? 0 ) + 1;
				$identity_ordinal = (int) $doctor_identity_counts[ $identity_seed ];
				$identity_total   = (int) ( $doctor_identity_totals[ $identity_seed ] ?? 1 );

				// Keep ordinal 1 on legacy temp-key shape for backward compatibility.
				$temp_secondary = $doctor_title;
				if ( $identity_ordinal > 1 ) {
					$temp_secondary .= '|org:' . (string) $doctor['organization_id'] . '|slug:' . $doctor_slug . '|dup:' . (string) $identity_ordinal;
				}

				$temp_key                  = $this->build_temp_key( 'doctor', $site_slug, $doctor_name, $temp_secondary );
				$is_temporary_input        = '' === $doctor_slug;

				$item_updated_at = sanitize_text_field( (string) ( $doctor['updated_at'] ?? '' ) );
				if ( ! empty( $item_updated_at ) && $this->is_more_recent( $item_updated_at, (string) $results['max_updated_at'] ) ) {
					$results['max_updated_at'] = $item_updated_at;
				}

				if ( $this->is_unchanged_since( $item_updated_at, $last_sync ) ) {
					$results['skipped_unchanged']++;
					continue;
				}

				$results['processed']++;

				$post_id = 0;
				if ( '' !== $temp_key ) {
					$post_id = $this->find_by_temp_key( $temp_key );
				}

				if ( $post_id <= 0 && '' !== $doctor_slug ) {
					$allow_slug_match = $identity_total <= 1;
					$post_id          = $this->find_doctor_post_id( $doctor_slug, $doctor_id, $allow_slug_match );
				}

				$doctor_is_active = $this->is_api_doctor_active( $doctor );
				if ( ! $doctor_is_active ) {
					if ( $post_id > 0 ) {
						if ( $dry_run ) {
							$results['drafted']++;
						} else {
							$updated_status = wp_update_post(
								array(
									'ID'          => (int) $post_id,
									'post_status' => 'draft',
								),
								true
							);
							if ( ! is_wp_error( $updated_status ) ) {
								$results['drafted']++;
							}
						}
					}

					continue;
				}

				// If a row without stable doctor_id resolves to a post we've already used
				// in this clinic loop, force insert so we don't overwrite the prior row.
				if ( '' === $doctor_id && $post_id > 0 && isset( $used_post_ids[ $post_id ] ) ) {
					$post_id = 0;
				}

				$is_new  = $post_id <= 0;
				$is_upgrading_temp = ( ! $is_new && '' !== $doctor_slug && $this->is_temporary_post( $post_id ) );

				$post_data = array(
					'post_title'   => '' !== $doctor_name ? $doctor_name : 'Temporary Doctor ' . substr( $temp_key, 0, 8 ),
					'post_name'    => '' !== $doctor_slug ? $doctor_slug : 'temp-doc-' . substr( $temp_key, 0, 12 ),
					'post_content' => wp_kses_post( (string) ( $doctor['bio'] ?? '' ) ),
					'post_status'  => 'publish',
					'post_type'    => 'doctor',
				);

				if ( $is_new ) {
					if ( $dry_run ) {
						$results['created']++;
						if ( $is_temporary_input ) {
							$results['temporary_created']++;
							$results['warnings'][] = 'Doctor missing doctor_slug — created as temporary record.';
						}
						continue;
					}

					$post_id = wp_insert_post( $post_data, true );
					if ( is_wp_error( $post_id ) ) {
						$results['errors'][] = sprintf( 'Doctor %s insert failed: %s', $doctor_slug, $post_id->get_error_message() );
						continue;
					}
					$results['created']++;
				} else {
					if ( $dry_run ) {
						$results['updated']++;
						if ( $is_upgrading_temp ) {
							$results['temporary_upgraded']++;
							$results['warnings'][] = sprintf( 'Doctor upgraded from temporary record using doctor_slug %s.', $doctor_slug );
						}
						if ( $is_temporary_input ) {
							$results['warnings'][] = 'Doctor missing doctor_slug — updating as temporary record.';
						}
						continue;
					}

					$post_data['ID'] = $post_id;
					$updated         = wp_update_post( $post_data, true );
					if ( is_wp_error( $updated ) ) {
						$results['errors'][] = sprintf( 'Doctor %s update failed: %s', $doctor_slug, $updated->get_error_message() );
						continue;
					}
					$results['updated']++;
				}

				$linked_to_clinic = $this->save_meta( (int) $post_id, $doctor, $doctor_slug, $temp_key );
				if ( $is_temporary_input ) {
					$results['warnings'][] = 'Doctor missing doctor_slug — created as temporary record.';
				}

				if ( $is_new && $is_temporary_input ) {
					$results['temporary_created']++;
				}

				if ( $is_upgrading_temp ) {
					$results['temporary_upgraded']++;
					$results['warnings'][] = sprintf( 'Doctor upgraded from temporary record using doctor_slug %s.', $doctor_slug );
				}

				if ( ! $linked_to_clinic ) {
					$results['missing_clinic']++;
					$results['warnings'][] = sprintf( 'Doctor %s missing clinic for organization_id %s', $post_data['post_name'], sanitize_text_field( (string) ( $doctor['organization_id'] ?? '' ) ) );
				}

				if ( $post_id > 0 ) {
					$used_post_ids[ $post_id ] = true;
				}

				$doctor_org_id = sanitize_text_field( (string) ( $doctor['organization_id'] ?? '' ) );
				if ( ! empty( $doctor['photo_url'] ) ) {
					$image_org_key = '' !== $doctor_org_id ? $doctor_org_id : 'temp-' . substr( $temp_key, 0, 12 );
					$image_slug    = '' !== $doctor_slug ? $doctor_slug : 'temp-doc-' . substr( $temp_key, 0, 12 );
					$image_id = Image_Importer::import_doctor_photo( (string) $doctor['photo_url'], $image_org_key, $image_slug, (int) $post_id );
					if ( is_wp_error( $image_id ) ) {
						$results['warnings'][] = sprintf( 'Doctor image import failed: %s', $image_id->get_error_message() );
					} elseif ( function_exists( 'set_post_thumbnail' ) && $image_id > 0 ) {
						$results['images_imported']++;
						set_post_thumbnail( (int) $post_id, (int) $image_id );
						update_post_meta( (int) $post_id, '_doctor_photo_id', (int) $image_id );
						update_post_meta( (int) $post_id, 'doctor_photo', (int) $image_id );
						update_post_meta( (int) $post_id, 'doctor_photo_url', esc_url_raw( (string) $doctor['photo_url'] ) );
					}
				}
			}
		}

		$should_cleanup_missing = ! empty( $seen_doctor_ids ) || ! empty( $seen_doctor_slugs );
		if ( ! $should_cleanup_missing ) {
			$results['warnings'][] = 'Doctor cleanup skipped: no doctor identifiers were seen in API payload.';
			$results['warnings'][] = sprintf( 'Doctor ID coverage (temp debug): %d with doctor_id, %d without doctor_id.', $doctor_id_present_count, $doctor_id_missing_count );
			$results['warnings'][] = sprintf( 'Doctor ID key seen in payload (temp debug): %s; first doctor_id value: %s', $doctor_id_key_seen ? 'yes' : 'no', null === $doctor_id_first_value ? '[none]' : $doctor_id_first_value );
			return $results;
		}

		$existing_doctors = get_posts(
			array(
				'post_type'      => 'doctor',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish' ),
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => '_360_doctor_id',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_360_doctor_slug',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( $existing_doctors as $existing_id ) {
			$existing_id   = (int) $existing_id;
			$wp_doctor_id  = sanitize_text_field( (string) get_post_meta( $existing_id, '_360_doctor_id', true ) );
			$wp_doctor_slug = sanitize_title( (string) get_post_meta( $existing_id, '_360_doctor_slug', true ) );

			$still_seen = false;
			if ( '' !== $wp_doctor_id && isset( $seen_doctor_ids[ $wp_doctor_id ] ) ) {
				$still_seen = true;
			} elseif ( '' === $wp_doctor_id && '' !== $wp_doctor_slug && isset( $seen_doctor_slugs[ $wp_doctor_slug ] ) ) {
				$still_seen = true;
			}

			if ( $still_seen ) {
				continue;
			}

			if ( $dry_run ) {
				$results['drafted']++;
				continue;
			}

			$updated = wp_update_post(
				array(
					'ID'          => $existing_id,
					'post_status' => 'draft',
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				$results['warnings'][] = sprintf( 'Doctor %d could not be drafted during cleanup: %s', $existing_id, $updated->get_error_message() );
				continue;
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[360 Sync] Doctor set to draft (missing from API): ' . ( '' !== $wp_doctor_id ? $wp_doctor_id : $wp_doctor_slug ) );
			}

			$results['drafted']++;
		}

		$results['warnings'][] = sprintf( 'Doctor ID coverage (temp debug): %d with doctor_id, %d without doctor_id.', $doctor_id_present_count, $doctor_id_missing_count );
		$results['warnings'][] = sprintf( 'Doctor ID key seen in payload (temp debug): %s; first doctor_id value: %s', $doctor_id_key_seen ? 'yes' : 'no', null === $doctor_id_first_value ? '[none]' : $doctor_id_first_value );

		return $results;
	}

	/**
	 * @param array<string,mixed> $doctor
	 */
	private function is_api_doctor_active( array $doctor ): bool {
		if ( array_key_exists( 'is_active', $doctor ) ) {
			return $this->normalize_api_active_flag( $doctor['is_active'] );
		}

		if ( array_key_exists( 'published', $doctor ) ) {
			return $this->normalize_api_active_flag( $doctor['published'] );
		}

		if ( array_key_exists( 'status', $doctor ) && is_string( $doctor['status'] ) ) {
			$status = strtolower( trim( $doctor['status'] ) );
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
	private function normalize_api_active_flag( $value ): bool {
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
				'post_type'      => 'doctor',
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

	private function find_doctor_post_id( string $doctor_slug, string $doctor_id, bool $allow_slug_match = true ): int {
		if ( ! empty( $doctor_id ) ) {
			$post_id = $this->find_by_meta( '_360_doctor_id', $doctor_id );
			if ( $post_id > 0 ) {
				return $post_id;
			}

			$post_id = $this->find_by_meta( 'doctor_id', $doctor_id );
			if ( $post_id > 0 ) {
				return $post_id;
			}

			// When doctor_id is present but not found, do not match by slug.
			// This prevents different doctors sharing the same slug from overwriting each other.
			return 0;
		}

		if ( $allow_slug_match && '' !== $doctor_slug ) {
			return $this->find_unique_by_meta( '_360_doctor_slug', $doctor_slug );
		}

		return 0;
	}

	private function find_unique_by_meta( string $meta_key, string $meta_value ): int {
		if ( '' === $meta_value ) {
			return 0;
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'doctor',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 2,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => $meta_key,
						'value' => $meta_value,
					),
				),
			)
		);

		if ( empty( $query->posts ) ) {
			return 0;
		}

		if ( count( $query->posts ) > 1 ) {
			return 0;
		}

		return (int) $query->posts[0];
	}

	private function find_by_meta( string $meta_key, string $meta_value ): int {
		if ( '' === $meta_value ) {
			return 0;
		}

		$query = new \WP_Query(
			array(
				'post_type'      => 'doctor',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => $meta_key,
						'value' => $meta_value,
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
	 * @param array<string,mixed> $doctor
	 */
	private function save_meta( int $post_id, array $doctor, string $doctor_slug, string $temp_key ): bool {
		$doctor_slug     = sanitize_title( $doctor_slug );
		$doctor_id       = $this->resolve_doctor_id( $doctor );
		$doctor_bio      = wp_kses_post( (string) ( $doctor['bio'] ?? '' ) );
		$organization_id = sanitize_text_field( (string) ( $doctor['organization_id'] ?? '' ) );

		$meta_map = array(
			'_360_doctor_id'       => $doctor_id,
			'_360_doctor_slug'     => $doctor_slug,
			'_360_title'           => sanitize_text_field( (string) ( $doctor['title'] ?? '' ) ),
			'_360_organization_id' => $organization_id,
			'_360_updated_at'      => sanitize_text_field( (string) ( $doctor['updated_at'] ?? '' ) ),
			'doctor_id'            => $doctor_id,
			'doctor_slug'          => $doctor_slug,
			'doctor_name'          => sanitize_text_field( (string) ( $doctor['doctor_name'] ?? '' ) ),
			'doctor_bio'           => $doctor_bio,
			'doctor_title'         => sanitize_text_field( (string) ( $doctor['title'] ?? '' ) ),
			'clinic_organization_id' => $organization_id,
			'doctor_updated_at'      => sanitize_text_field( (string) ( $doctor['updated_at'] ?? '' ) ),
		);

		foreach ( $meta_map as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		update_post_meta( $post_id, '_360_temp_key', $temp_key );
		if ( '' === $doctor_slug ) {
			update_post_meta( $post_id, '_360_is_temporary', 1 );
			delete_post_meta( $post_id, '_360_doctor_slug' );
			delete_post_meta( $post_id, 'doctor_slug' );
		} else {
			delete_post_meta( $post_id, '_360_is_temporary' );
		}

		$clinic_post_id = $this->find_clinic_post_id( $organization_id );
		if ( $clinic_post_id > 0 ) {
			update_post_meta( $post_id, '_360_clinic_post_id', $clinic_post_id );
			update_post_meta( $post_id, 'clinic_post_id', $clinic_post_id );
			update_post_meta( $post_id, 'clinic_id', array( (int) $clinic_post_id ) );

			$clinic_address = sanitize_text_field( (string) get_post_meta( $clinic_post_id, 'clinic_address', true ) );
			if ( '' !== $clinic_address ) {
				update_post_meta( $post_id, 'clinic_address', $clinic_address );
			}

			$clinic_addresses = get_post_meta( $clinic_post_id, 'clinic_addresses', true );
			if ( is_array( $clinic_addresses ) ) {
				update_post_meta( $post_id, 'clinic_addresses', $clinic_addresses );
			} elseif ( is_string( $clinic_addresses ) ) {
				$clinic_addresses = trim( $clinic_addresses );
				if ( '' !== $clinic_addresses ) {
					update_post_meta( $post_id, 'clinic_addresses', $clinic_addresses );
				}
			}

			return true;
		}

		delete_post_meta( $post_id, '_360_clinic_post_id' );
		delete_post_meta( $post_id, 'clinic_post_id' );
		delete_post_meta( $post_id, 'clinic_id' );
		delete_post_meta( $post_id, 'clinic_address' );
		delete_post_meta( $post_id, 'clinic_addresses' );

		return false;
	}

	private function find_clinic_post_id( string $organization_id ): int {
		if ( empty( $organization_id ) ) {
			return 0;
		}

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
	 * @param array<string,mixed> $item
	 * @return array<string,mixed>
	 */
	private function normalize_clinic_payload( array $item ): array {
		if ( isset( $item['clinic'] ) && is_array( $item['clinic'] ) ) {
			$item = array_merge( $item['clinic'], $item );
		}

		if ( isset( $item['details'] ) && is_array( $item['details'] ) ) {
			$item = array_merge( $item['details'], $item );
		}

		return $item;
	}

	/**
	 * @param array<string,mixed> $item
	 */
	private function resolve_organization_id( array $item, string $fallback = '' ): string {
		$candidates = array(
			$item['organization_id'] ?? '',
			$item['organizationId'] ?? '',
			$item['organizationID'] ?? '',
			$item['clinic_organization_id'] ?? '',
			$item['org_id'] ?? '',
			$fallback,
		);

		if ( isset( $item['organization'] ) ) {
			if ( is_string( $item['organization'] ) || is_numeric( $item['organization'] ) ) {
				$candidates[] = $item['organization'];
			} elseif ( is_array( $item['organization'] ) ) {
				$candidates[] = $item['organization']['id'] ?? '';
				$candidates[] = $item['organization']['organization_id'] ?? '';
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
	 * @param array<string,mixed> $doctor
	 */
	private function resolve_doctor_name( array $doctor ): string {
		$candidates = array(
			$doctor['doctor_name'] ?? '',
			$doctor['full_name'] ?? '',
			$doctor['name'] ?? '',
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
	 * @param array<string,mixed> $doctor
	 */
	private function resolve_doctor_id( array $doctor ): string {
		$candidates = array(
			$doctor['doctor_id'] ?? '',
		);

		foreach ( $candidates as $candidate ) {
			$value = sanitize_text_field( (string) $candidate );
			if ( '' !== $value ) {
				return $value;
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
}
