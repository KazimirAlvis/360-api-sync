<?php

namespace ThreeSixty\ApiSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cron {

	public const EVENT_HOOK = '360_api_sync_event';
	public const LAST_SYNC_OPTION = '360_api_last_sync';

	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedule' ) );
		add_action( self::EVENT_HOOK, array( __CLASS__, 'handle_event' ) );
	}

	/**
	 * @param array<string,array<string,mixed>> $schedules
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_schedule( array $schedules ): array {
		if ( ! isset( $schedules['six_hours'] ) ) {
			$schedules['six_hours'] = array(
				'interval' => 6 * HOUR_IN_SECONDS,
				'display'  => __( 'Every 6 Hours', '360-api-sync' ),
			);
		}

		return $schedules;
	}

	public static function activate(): void {
		Sync_Log::install();

		if ( ! wp_next_scheduled( self::EVENT_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'six_hours', self::EVENT_HOOK );
		}
	}

	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( self::EVENT_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::EVENT_HOOK );
		}
	}

	public static function handle_event(): void {
		self::run_sync( 'cron' );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function run_sync( string $context = 'manual' ): array {
		$api_client  = new Api_Client();
		$clinic_sync = new Clinic_Sync();
		$doctor_sync = new Doctor_Sync();
		$settings    = Api_Client::get_settings();
		$dry_run     = ! empty( $settings['enable_dry_run'] );

		$last_sync = (string) get_option( self::LAST_SYNC_OPTION, '' );

		$payload = $api_client->get_sync_payload();
		if ( is_wp_error( $payload ) ) {
			$error_message = 'API request failure (/sync): ' . $payload->get_error_message();
			$error_data    = $payload->get_error_data();
			if ( is_array( $error_data ) ) {
				$status    = isset( $error_data['status'] ) ? (string) $error_data['status'] : '';
				$site_slug = isset( $error_data['site_slug'] ) ? (string) $error_data['site_slug'] : '';
				$snippet   = isset( $error_data['body_snippet'] ) ? (string) $error_data['body_snippet'] : '';

				if ( '' !== $status ) {
					$error_message .= ' [status=' . $status . ']';
				}

				if ( '' !== $site_slug ) {
					$error_message .= ' [site_slug=' . $site_slug . ']';
				}

				if ( '' !== $snippet ) {
					$error_message .= ' [body=' . $snippet . ']';
				}
			}

			$errors = array( $error_message );
			Sync_Log::log_run(
				array(
					'context'           => $context,
					'clinics_processed' => 0,
					'doctors_processed' => 0,
					'images_imported'   => 0,
					'errors'            => $errors,
				)
			);

			$result = array(
				'success' => false,
				'error'   => $payload->get_error_message(),
				'context' => $context,
			);
			update_option( '360_api_sync_last_run_result', $result, false );
			return $result;
		}

		$clinics = $payload['clinics'] ?? array();
		if ( ! is_array( $clinics ) || empty( $clinics ) ) {
			$warning = 'WARNING: Sync aborted because API payload contained no clinics. Last sync cursor was not advanced.';

			Sync_Log::log_run(
				array(
					'context'           => $context,
					'clinics_processed' => 0,
					'doctors_processed' => 0,
					'images_imported'   => 0,
					'errors'            => array( $warning ),
				)
			);

			$result = array(
				'success' => false,
				'warning' => $warning,
				'context' => $context,
			);

			update_option( '360_api_sync_last_run_result', $result, false );
			return $result;
		}

		$clinic_count = count( $clinics );
		if ( $clinic_count > 2000 ) {
			$error = sprintf( 'ERROR: Sync aborted because clinics payload exceeded safety threshold (%d > 2000).', $clinic_count );
			Sync_Log::log_run(
				array(
					'context'           => $context,
					'clinics_processed' => 0,
					'doctors_processed' => 0,
					'images_imported'   => 0,
					'errors'            => array( $error ),
				)
			);

			$result = array(
				'success' => false,
				'error'   => $error,
				'context' => $context,
			);

			update_option( '360_api_sync_last_run_result', $result, false );
			return $result;
		}

		$clinic_result = $clinic_sync->sync( $clinics, $last_sync, $dry_run );
		$doctor_result = $doctor_sync->sync( $clinics, $last_sync, $dry_run );
		$clinic_errors = is_array( $clinic_result['errors'] ?? null ) ? $clinic_result['errors'] : array();
		$doctor_errors = is_array( $doctor_result['errors'] ?? null ) ? $doctor_result['errors'] : array();
		$all_errors    = array_merge( $clinic_errors, $doctor_errors );
		$warnings      = $api_client->get_runtime_warnings();

		if ( $dry_run ) {
			$warnings[] = 'Dry run mode enabled: no posts, meta, or images were written.';
		}

		$log_messages = array_merge( $all_errors, $warnings );

		$new_last_sync = $last_sync;
		if ( empty( $all_errors ) && ! $dry_run ) {
			$new_last_sync = self::resolve_last_sync_time( $clinic_result, $doctor_result, $last_sync );
		}

		if ( empty( $all_errors ) && ! $dry_run && ! empty( $new_last_sync ) ) {
			update_option( self::LAST_SYNC_OPTION, $new_last_sync, false );
		}

		$result = array(
			'success'       => empty( $all_errors ),
			'context'       => $context,
			'clinic_result' => $clinic_result,
			'doctor_result' => $doctor_result,
			'dry_run'       => $dry_run,
			'warnings'      => $warnings,
			'last_sync_time' => $new_last_sync,
			'ran_at'        => gmdate( 'c' ),
		);

		Sync_Log::log_run(
			array(
				'context'           => $context,
				'clinics_processed' => (int) ( $clinic_result['processed'] ?? 0 ),
				'doctors_processed' => (int) ( $doctor_result['processed'] ?? 0 ),
				'images_imported'   => (int) ( $clinic_result['images_imported'] ?? 0 ) + (int) ( $doctor_result['images_imported'] ?? 0 ),
				'errors'            => $log_messages,
			)
		);

		update_option( '360_api_sync_last_run_result', $result, false );

		return $result;
	}

	/**
	 * @param array<string,mixed> $clinic_result
	 * @param array<string,mixed> $doctor_result
	 */
	private static function resolve_last_sync_time( array $clinic_result, array $doctor_result, string $fallback ): string {
		$times = array_filter(
			array(
				(string) ( $clinic_result['max_updated_at'] ?? '' ),
				(string) ( $doctor_result['max_updated_at'] ?? '' ),
				$fallback,
			)
		);

		$latest_time = 0;
		$latest      = $fallback;

		foreach ( $times as $time ) {
			$timestamp = strtotime( $time );
			if ( false !== $timestamp && $timestamp >= $latest_time ) {
				$latest_time = $timestamp;
				$latest      = $time;
			}
		}

		return $latest;
	}
}
