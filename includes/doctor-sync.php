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
			'temporary_created' => 0,
			'temporary_upgraded' => 0,
			'images_imported'=> 0,
			'missing_clinic' => 0,
			'errors'         => array(),
			'warnings'       => array(),
			'max_updated_at' => '',
		);

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

			foreach ( $doctors as $doctor ) {
				if ( ! is_array( $doctor ) ) {
					continue;
				}

				$doctor_slug = sanitize_title( (string) ( $doctor['doctor_slug'] ?? ( $doctor['slug'] ?? '' ) ) );
				$doctor_id   = sanitize_text_field( (string) ( $doctor['doctor_id'] ?? '' ) );
				$doctor_name = $this->resolve_doctor_name( $doctor );
				$doctor_title = sanitize_text_field( (string) ( $doctor['title'] ?? '' ) );
				if ( '' === $doctor_slug && '' === $doctor_name ) {
					$results['skipped_invalid']++;
					$results['warnings'][] = 'Doctor record skipped: missing both doctor_slug and doctor_name.';
					continue;
				}

				$doctor['organization_id'] = $this->resolve_organization_id( $doctor, $organization_id );
				$doctor['doctor_name']     = $doctor_name;
				$temp_key                  = $this->build_temp_key( 'doctor', $site_slug, $doctor_name, $doctor_title );
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
				if ( '' !== $doctor_slug ) {
					$post_id = $this->find_doctor_post_id( $doctor_slug, $doctor_id );
				}

				if ( $post_id <= 0 && '' !== $temp_key ) {
					$post_id = $this->find_by_temp_key( $temp_key );
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

		return $results;
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

	private function find_doctor_post_id( string $doctor_slug, string $doctor_id ): int {
		$post_id = $this->find_by_meta( '_360_doctor_slug', $doctor_slug );
		if ( $post_id > 0 ) {
			return $post_id;
		}

		if ( ! empty( $doctor_id ) ) {
			$post_id = $this->find_by_meta( '_360_doctor_id', $doctor_id );
			if ( $post_id > 0 ) {
				return $post_id;
			}

			$post_id = $this->find_by_meta( 'doctor_id', $doctor_id );
			if ( $post_id > 0 ) {
				return $post_id;
			}
		}

		return 0;
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
		$doctor_id       = sanitize_text_field( (string) ( $doctor['doctor_id'] ?? '' ) );
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
			return true;
		}

		delete_post_meta( $post_id, '_360_clinic_post_id' );
		delete_post_meta( $post_id, 'clinic_post_id' );
		delete_post_meta( $post_id, 'clinic_id' );

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

	private function build_temp_key( string $entity, string $site_slug, string $name, string $secondary ): string {
		return md5(
			strtolower( trim( $entity ) ) . '|' .
			strtolower( trim( $site_slug ) ) . '|' .
			strtolower( trim( $name ) ) . '|' .
			strtolower( trim( $secondary ) )
		);
	}
}
