<?php

namespace ThreeSixty\ApiSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Image_Importer {

	public static function import_clinic_logo( string $image_url, string $organization_id, int $post_id ) {
		$extension = self::get_extension_from_url( $image_url );
		$file_name = 'clinic-' . sanitize_file_name( $organization_id ) . '-logo.' . $extension;
		$subdir    = '360-clinics/' . sanitize_file_name( $organization_id );

		return self::import_image(
			$image_url,
			$post_id,
			$subdir,
			$file_name,
			'_360_clinic_logo_url',
			'_360_clinic_logo_id'
		);
	}

	public static function import_doctor_photo( string $image_url, string $organization_id, string $doctor_slug, int $post_id ) {
		$extension = self::get_extension_from_url( $image_url );
		$slug_part = sanitize_file_name( $doctor_slug );
		if ( '' === $slug_part ) {
			$slug_part = 'doctor';
		}

		$file_name = 'doctor-' . $slug_part . '-' . (int) $post_id . '.' . $extension;
		$subdir    = '360-doctors/' . sanitize_file_name( $organization_id );

		return self::import_image(
			$image_url,
			$post_id,
			$subdir,
			$file_name,
			'_360_doctor_photo_url',
			'_360_doctor_photo_id'
		);
	}

	/**
	 * @return int|\WP_Error Attachment ID on success.
	 */
	private static function import_image(
		string $image_url,
		int $post_id,
		string $subdir,
		string $file_name,
		string $source_url_meta_key,
		string $attachment_id_meta_key
	) {
		$image_url = esc_url_raw( $image_url );
		if ( empty( $image_url ) ) {
			return 0;
		}

		$existing_url = (string) get_post_meta( $post_id, $source_url_meta_key, true );
		$existing_id  = (int) get_post_meta( $post_id, $attachment_id_meta_key, true );
		if ( $existing_url === $image_url && $existing_id > 0 && get_post( $existing_id ) ) {
			$existing_file = get_attached_file( $existing_id );
			$expected_name = sanitize_file_name( $file_name );
			if ( is_string( $existing_file ) && '' !== $existing_file && file_exists( $existing_file ) && basename( $existing_file ) === $expected_name ) {
				return $existing_id;
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp_file = download_url( $image_url, 20 );
		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			@unlink( $tmp_file );
			return new \WP_Error( '360_api_sync_upload_error', (string) $upload_dir['error'] );
		}

		$target_dir = trailingslashit( (string) $upload_dir['basedir'] ) . trim( $subdir, '/' );
		if ( ! wp_mkdir_p( $target_dir ) ) {
			@unlink( $tmp_file );
			return new \WP_Error( '360_api_sync_mkdir_failed', __( 'Failed to create image directory.', '360-api-sync' ) );
		}

		$target_file = trailingslashit( $target_dir ) . sanitize_file_name( $file_name );
		if ( file_exists( $target_file ) ) {
			@unlink( $target_file );
		}

		$moved = @rename( $tmp_file, $target_file );
		if ( ! $moved ) {
			$moved = @copy( $tmp_file, $target_file );
			@unlink( $tmp_file );
		}

		if ( ! $moved || ! file_exists( $target_file ) ) {
			return new \WP_Error( '360_api_sync_image_move_failed', __( 'Failed to move downloaded image.', '360-api-sync' ) );
		}

		$filetype = wp_check_filetype( basename( $target_file ), null );
		$mime     = ! empty( $filetype['type'] ) ? $filetype['type'] : 'image/jpeg';
		$file_url = trailingslashit( (string) $upload_dir['baseurl'] ) . trim( $subdir, '/' ) . '/' . basename( $target_file );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => sanitize_text_field( pathinfo( $file_name, PATHINFO_FILENAME ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'guid'           => esc_url_raw( $file_url ),
			),
			$target_file,
			$post_id
		);

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			return new \WP_Error( '360_api_sync_attachment_failed', __( 'Failed to create attachment.', '360-api-sync' ) );
		}

		$meta = wp_generate_attachment_metadata( $attachment_id, $target_file );
		if ( ! empty( $meta ) ) {
			wp_update_attachment_metadata( $attachment_id, $meta );
		}

		update_post_meta( $attachment_id, '_360_api_sync_source_url', $image_url );
		update_post_meta( $post_id, $source_url_meta_key, $image_url );
		update_post_meta( $post_id, $attachment_id_meta_key, (int) $attachment_id );

		return (int) $attachment_id;
	}

	private static function get_extension_from_url( string $url ): string {
		$path      = parse_url( $url, PHP_URL_PATH );
		$extension = $path ? pathinfo( (string) $path, PATHINFO_EXTENSION ) : '';
		$extension = strtolower( (string) $extension );

		$allowed = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );
		if ( empty( $extension ) || ! in_array( $extension, $allowed, true ) ) {
			return 'jpg';
		}

		return $extension;
	}
}
