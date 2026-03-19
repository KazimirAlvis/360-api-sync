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
		$results = array(
			'processed'      => 0,
			'skipped_unchanged' => 0,
			'created'        => 0,
			'updated'        => 0,
			'images_imported'=> 0,
			'errors'         => array(),
			'max_updated_at' => '',
		);

		foreach ( $clinics as $clinic ) {
			if ( ! is_array( $clinic ) ) {
				continue;
			}

			$clinic = $this->normalize_clinic_payload( $clinic );

			$organization_id = $this->resolve_organization_id( $clinic );
			if ( empty( $organization_id ) ) {
				$results['errors'][] = 'Clinic record skipped: missing organization_id.';
				continue;
			}

			$clinic['organization_id'] = $organization_id;

			$item_updated_at = sanitize_text_field( (string) ( $clinic['updated_at'] ?? '' ) );
			if ( ! empty( $item_updated_at ) && $this->is_more_recent( $item_updated_at, (string) $results['max_updated_at'] ) ) {
				$results['max_updated_at'] = $item_updated_at;
			}

			if ( $this->is_unchanged_since( $item_updated_at, $last_sync ) ) {
				$results['skipped_unchanged']++;
				continue;
			}

			$results['processed']++;

			$post_id = $this->find_by_organization_id( $organization_id );
			$is_new  = $post_id <= 0;

			$post_data = array(
				'post_title'   => sanitize_text_field( (string) ( $clinic['clinic_name'] ?? 'Clinic' ) ),
				'post_content' => wp_kses_post( (string) ( $clinic['bio'] ?? '' ) ),
				'post_status'  => 'publish',
				'post_type'    => 'clinic',
			);

			if ( $is_new ) {
				if ( $dry_run ) {
					$results['created']++;
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

			$this->save_meta( (int) $post_id, $clinic );

			if ( ! empty( $clinic['logo_url'] ) ) {
				$image_id = Image_Importer::import_clinic_logo( (string) $clinic['logo_url'], $organization_id, (int) $post_id );
				if ( is_wp_error( $image_id ) ) {
					$results['errors'][] = sprintf( 'Clinic %s image import failed: %s', $organization_id, $image_id->get_error_message() );
				} elseif ( function_exists( 'set_post_thumbnail' ) && $image_id > 0 ) {
					$results['images_imported']++;
					set_post_thumbnail( (int) $post_id, (int) $image_id );
					update_post_meta( (int) $post_id, 'clinic_logo', (int) $image_id );
					update_post_meta( (int) $post_id, '_clinic_logo_id', (int) $image_id );
					update_post_meta( (int) $post_id, 'clinic_logo_url', esc_url_raw( (string) $clinic['logo_url'] ) );
				}
			}

		}

		return $results;
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
	private function save_meta( int $post_id, array $clinic ): void {
		$organization_id = sanitize_text_field( (string) ( $clinic['organization_id'] ?? '' ) );
		$bio             = wp_kses_post( (string) ( $clinic['bio'] ?? '' ) );

		$meta_map = array(
			'_360_organization_id' => $organization_id,
			'organization_id'      => $organization_id,
			'_360_phone'           => sanitize_text_field( (string) ( $clinic['phone'] ?? '' ) ),
			'_360_website_url'     => esc_url_raw( (string) ( $clinic['website_url'] ?? '' ) ),
			'_360_google_place_id' => sanitize_text_field( (string) ( $clinic['google_place_id'] ?? '' ) ),
			'_360_assessment_id'   => sanitize_text_field( (string) ( $clinic['assessment_id'] ?? '' ) ),
			'_360_updated_at'      => sanitize_text_field( (string) ( $clinic['updated_at'] ?? '' ) ),
			'clinic_organization_id' => $organization_id,
			'clinic_phone'           => sanitize_text_field( (string) ( $clinic['phone'] ?? '' ) ),
			'clinic_bio'             => $bio,
			'clinic_google_place_id' => sanitize_text_field( (string) ( $clinic['google_place_id'] ?? '' ) ),
			'clinic_assessment_id'   => sanitize_text_field( (string) ( $clinic['assessment_id'] ?? '' ) ),
			'clinic_website'         => esc_url_raw( (string) ( $clinic['website_url'] ?? '' ) ),
			'clinic_updated_at'      => sanitize_text_field( (string) ( $clinic['updated_at'] ?? '' ) ),
			'google_place_id'        => sanitize_text_field( (string) ( $clinic['google_place_id'] ?? '' ) ),
			'_cpt360_clinic_phone'   => sanitize_text_field( (string) ( $clinic['phone'] ?? '' ) ),
			'_clinic_website_url'    => esc_url_raw( (string) ( $clinic['website_url'] ?? '' ) ),
			'_cpt360_assessment_id'  => sanitize_text_field( (string) ( $clinic['assessment_id'] ?? '' ) ),
			'_cpt360_clinic_bio'     => $bio,
		);

		foreach ( $meta_map as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		if ( isset( $clinic['addresses'] ) ) {
			$raw_addresses = $this->sanitize_nested_data( $clinic['addresses'] );
			$addresses     = $this->normalize_clinic_addresses( is_array( $raw_addresses ) ? $raw_addresses : array() );
			update_post_meta( $post_id, '_360_addresses', $raw_addresses );
			update_post_meta( $post_id, 'clinic_addresses', $addresses );
			$first_address = $this->build_primary_address( $addresses );
			if ( ! empty( $first_address ) ) {
				update_post_meta( $post_id, 'clinic_address', $first_address );
			}
		}

		if ( isset( $clinic['states'] ) ) {
			$states = $this->sanitize_nested_data( $clinic['states'] );
			update_post_meta( $post_id, '_360_states', $states );
			update_post_meta( $post_id, 'clinic_states', $states );
		}

		if ( isset( $clinic['clinic_info'] ) ) {
			$clinic_info = $this->sanitize_nested_data( $clinic['clinic_info'] );
			$clinic_info = $this->normalize_clinic_info( is_array( $clinic_info ) ? $clinic_info : array() );
			update_post_meta( $post_id, '_360_clinic_info', $clinic_info );
			update_post_meta( $post_id, 'clinic_info', $clinic_info );
		}

		if ( isset( $clinic['reviews'] ) ) {
			$reviews = $this->sanitize_nested_data( $clinic['reviews'] );
			$reviews = $this->normalize_clinic_reviews( is_array( $reviews ) ? $reviews : array() );
			update_post_meta( $post_id, '_360_reviews', $reviews );
			update_post_meta( $post_id, 'clinic_reviews', $reviews );
		}
	}

	/**
	 * @param array<int|string,mixed> $addresses
	 * @return array<int,array<string,string>>
	 */
	private function normalize_clinic_addresses( array $addresses ): array {
		$normalized = array();
		foreach ( $addresses as $address ) {
			if ( ! is_array( $address ) ) {
				continue;
			}

			$street = (string) ( $address['street'] ?? $address['line1'] ?? '' );
			$city   = (string) ( $address['city'] ?? '' );
			$state  = (string) ( $address['state'] ?? '' );
			$zip    = (string) ( $address['zip'] ?? $address['postal_code'] ?? '' );

			if ( '' === $street && '' === $city && '' === $state && '' === $zip ) {
				continue;
			}

			$normalized[] = array(
				'street' => sanitize_text_field( $street ),
				'city'   => sanitize_text_field( $city ),
				'state'  => sanitize_text_field( $state ),
				'zip'    => sanitize_text_field( $zip ),
			);
		}

		return $normalized;
	}

	/**
	 * @param array<int|string,mixed> $clinic_info
	 * @return array<int,array<string,string>>
	 */
	private function normalize_clinic_info( array $clinic_info ): array {
		$normalized = array();
		foreach ( $clinic_info as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$title       = sanitize_text_field( (string) ( $item['title'] ?? $item['label'] ?? '' ) );
			$description = sanitize_text_field( (string) ( $item['description'] ?? $item['value'] ?? '' ) );

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
			if ( ! is_array( $review ) ) {
				continue;
			}

			$reviewer = sanitize_text_field( (string) ( $review['reviewer'] ?? $review['author'] ?? '' ) );
			$text     = sanitize_text_field( (string) ( $review['review'] ?? $review['comment'] ?? '' ) );

			if ( '' === $reviewer && '' === $text ) {
				continue;
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
				$clinic = array_merge( $nested, $clinic );
			}
		}

		if ( isset( $clinic['details'] ) && is_array( $clinic['details'] ) ) {
			$nested = $clinic['details'];
			if ( is_array( $nested ) ) {
				$clinic = array_merge( $nested, $clinic );
			}
		}

		return $clinic;
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
}
