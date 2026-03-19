<?php

namespace ThreeSixty\ApiSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings_Page {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_managed_content_notice' ) );
		add_action( 'admin_post_360_api_sync_manual', array( __CLASS__, 'handle_manual_sync' ) );
		add_action( 'admin_post_360_api_sync_clear_log', array( __CLASS__, 'handle_clear_log' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( THREESIXTY_API_SYNC_PATH . '360-api-sync.php' ), array( __CLASS__, 'settings_link' ) );
	}

	public static function render_managed_content_notice(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		$is_supported_screen = in_array( $screen->base, array( 'post', 'post-new' ), true );
		$is_supported_type   = in_array( (string) $screen->post_type, array( 'clinic', 'doctor' ), true );

		if ( ! $is_supported_screen || ! $is_supported_type ) {
			return;
		}

		$post_id = 0;
		if ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_id = absint( wp_unslash( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$is_temporary = $post_id > 0 ? (bool) get_post_meta( $post_id, '_360_is_temporary', true ) : false;
		?>
		<div class="notice notice-warning">
			<p><strong>⚠</strong> <?php esc_html_e( 'This content is managed by the 360 API Sync system. Manual changes may be overwritten.', '360-api-sync' ); ?></p>
		</div>
		<?php if ( $is_temporary ) : ?>
			<div class="notice notice-info">
				<p><?php esc_html_e( 'This record is temporary and will be updated when full data is available.', '360-api-sync' ); ?></p>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array<int,string> $links
	 * @return array<int,string>
	 */
	public static function settings_link( array $links ): array {
		$url = admin_url( 'admin.php?page=360-api-sync' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', '360-api-sync' ) . '</a>' );
		return $links;
	}

	public static function add_menu(): void {
		add_menu_page(
			__( '360 API Sync', '360-api-sync' ),
			__( '360 API Sync', '360-api-sync' ),
			'manage_options',
			'360-api-sync',
			array( __CLASS__, 'render_page' ),
			'dashicons-update',
			65
		);

		add_submenu_page(
			'360-api-sync',
			__( 'Settings', '360-api-sync' ),
			__( 'Settings', '360-api-sync' ),
			'manage_options',
			'360-api-sync',
			array( __CLASS__, 'render_page' )
		);

		add_submenu_page(
			'360-api-sync',
			__( 'Sync Log', '360-api-sync' ),
			__( 'Sync Log', '360-api-sync' ),
			'manage_options',
			'360-api-sync-log',
			array( __CLASS__, 'render_log_page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'360_api_sync_settings_group',
			'360_api_sync_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => array(
					'api_base_url' => '',
					'api_key'      => '',
					'site_slug'    => '',
					'enable_mock'  => 1,
					'enable_dry_run' => 0,
				),
			)
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function sanitize_settings( array $input ): array {
		return array(
			'api_base_url' => esc_url_raw( (string) ( $input['api_base_url'] ?? '' ) ),
			'api_key'      => sanitize_text_field( (string) ( $input['api_key'] ?? '' ) ),
			'site_slug'    => sanitize_title( (string) ( $input['site_slug'] ?? '' ) ),
			'enable_mock'  => ! empty( $input['enable_mock'] ) ? 1 : 0,
			'enable_dry_run' => ! empty( $input['enable_dry_run'] ) ? 1 : 0,
		);
	}

	public static function handle_manual_sync(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to run sync.', '360-api-sync' ) );
		}

		check_admin_referer( '360_api_sync_manual_action', '360_api_sync_manual_nonce' );

		$result = Cron::run_sync( 'manual' );

		set_transient( '360_api_sync_manual_result', $result, 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=360-api-sync' ) );
		exit;
	}

	public static function handle_clear_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to clear logs.', '360-api-sync' ) );
		}

		check_admin_referer( '360_api_sync_clear_log_action', '360_api_sync_clear_log_nonce' );

		Sync_Log::clear();

		wp_safe_redirect( admin_url( 'admin.php?page=360-api-sync-log' ) );
		exit;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Api_Client::get_settings();
		$result   = get_transient( '360_api_sync_manual_result' );
		if ( false !== $result ) {
			delete_transient( '360_api_sync_manual_result' );
		}

		$last_run = get_option( '360_api_sync_last_run_result', array() );
		$last_sync_time = (string) get_option( Cron::LAST_SYNC_OPTION, '' );
		$temporary_counts = self::get_temporary_counts();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '360 API Sync', '360-api-sync' ); ?></h1>

			<?php if ( is_array( $result ) ) : ?>
				<?php if ( ! empty( $result['success'] ) ) : ?>
					<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Manual sync completed.', '360-api-sync' ); ?></p></div>
				<?php else : ?>
					<div class="notice notice-error is-dismissible"><p><?php echo esc_html( (string) ( $result['error'] ?? __( 'Manual sync failed.', '360-api-sync' ) ) ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( '360_api_sync_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="360_api_sync_api_base_url"><?php esc_html_e( 'API Base URL', '360-api-sync' ); ?></label></th>
						<td>
							<input name="360_api_sync_settings[api_base_url]" id="360_api_sync_api_base_url" type="url" class="regular-text" value="<?php echo esc_attr( (string) $settings['api_base_url'] ); ?>" placeholder="https://example.com" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="360_api_sync_api_key"><?php esc_html_e( 'API Key', '360-api-sync' ); ?></label></th>
						<td>
							<input name="360_api_sync_settings[api_key]" id="360_api_sync_api_key" type="text" class="regular-text" value="<?php echo esc_attr( (string) $settings['api_key'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="360_api_sync_site_slug"><?php esc_html_e( 'Site Slug', '360-api-sync' ); ?></label></th>
						<td>
							<input name="360_api_sync_settings[site_slug]" id="360_api_sync_site_slug" type="text" class="regular-text" value="<?php echo esc_attr( (string) $settings['site_slug'] ); ?>" placeholder="knee-pain" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Mock API', '360-api-sync' ); ?></th>
						<td>
							<label>
								<input name="360_api_sync_settings[enable_mock]" type="checkbox" value="1" <?php checked( ! empty( $settings['enable_mock'] ) ); ?> />
								<?php esc_html_e( 'Use local mock JSON files instead of remote API.', '360-api-sync' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Dry Run Mode', '360-api-sync' ); ?></th>
						<td>
							<label>
								<input name="360_api_sync_settings[enable_dry_run]" type="checkbox" value="1" <?php checked( ! empty( $settings['enable_dry_run'] ) ); ?> />
								<?php esc_html_e( 'Simulate sync and log what would change without writing posts, meta, or images.', '360-api-sync' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Settings', '360-api-sync' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Sync State', '360-api-sync' ); ?></h2>
			<p>
				<strong><?php esc_html_e( 'Last Sync Time:', '360-api-sync' ); ?></strong>
				<?php echo ! empty( $last_sync_time ) ? esc_html( $last_sync_time ) : esc_html__( 'Not synced yet', '360-api-sync' ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Temporary Clinics:', '360-api-sync' ); ?></strong>
				<?php echo esc_html( (string) (int) ( $temporary_counts['clinic'] ?? 0 ) ); ?>
				&nbsp;|&nbsp;
				<strong><?php esc_html_e( 'Temporary Doctors:', '360-api-sync' ); ?></strong>
				<?php echo esc_html( (string) (int) ( $temporary_counts['doctor'] ?? 0 ) ); ?>
			</p>

			<hr />

			<h2><?php esc_html_e( 'Manual Sync', '360-api-sync' ); ?></h2>
			<p><?php esc_html_e( 'Run a one-time sync now.', '360-api-sync' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="360_api_sync_manual" />
				<?php wp_nonce_field( '360_api_sync_manual_action', '360_api_sync_manual_nonce' ); ?>
				<?php submit_button( __( 'Run Manual Sync', '360-api-sync' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php if ( is_array( $last_run ) && ! empty( $last_run ) ) : ?>
				<hr />
				<h2><?php esc_html_e( 'Last Run Summary', '360-api-sync' ); ?></h2>
				<pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:900px;overflow:auto;"><?php echo esc_html( wp_json_encode( $last_run, JSON_PRETTY_PRINT ) ?: '' ); ?></pre>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_log_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$runs = Sync_Log::all( 100 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '360 API Sync Log', '360-api-sync' ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px;">
				<input type="hidden" name="action" value="360_api_sync_clear_log" />
				<?php wp_nonce_field( '360_api_sync_clear_log_action', '360_api_sync_clear_log_nonce' ); ?>
				<?php submit_button( __( 'Clear Log', '360-api-sync' ), 'delete', 'submit', false ); ?>
			</form>

			<?php if ( empty( $runs ) ) : ?>
				<p><?php esc_html_e( 'No sync log entries yet.', '360-api-sync' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
					<tr>
						<th><?php esc_html_e( 'ID', '360-api-sync' ); ?></th>
						<th><?php esc_html_e( 'Sync Time (UTC)', '360-api-sync' ); ?></th>
						<th><?php esc_html_e( 'Context', '360-api-sync' ); ?></th>
						<th><?php esc_html_e( 'Clinics Processed', '360-api-sync' ); ?></th>
						<th><?php esc_html_e( 'Doctors Processed', '360-api-sync' ); ?></th>
						<th><?php esc_html_e( 'Images Imported', '360-api-sync' ); ?></th>
						<th><?php esc_html_e( 'Errors', '360-api-sync' ); ?></th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ( $runs as $run ) : ?>
						<?php
						$error_items = json_decode( (string) ( $run['errors'] ?? '[]' ), true );
						if ( ! is_array( $error_items ) ) {
							$error_items = array();
						}
						?>
						<tr>
							<td><?php echo esc_html( (string) ( $run['id'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $run['sync_time'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $run['context'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $run['clinics_processed'] ?? 0 ) ); ?></td>
							<td><?php echo esc_html( (string) ( $run['doctors_processed'] ?? 0 ) ); ?></td>
							<td><?php echo esc_html( (string) ( $run['images_imported'] ?? 0 ) ); ?></td>
							<td>
								<?php if ( empty( $error_items ) ) : ?>
									<?php esc_html_e( 'None', '360-api-sync' ); ?>
								<?php else : ?>
									<details>
										<summary><?php echo esc_html( sprintf( _n( '%d error', '%d errors', count( $error_items ), '360-api-sync' ), count( $error_items ) ) ); ?></summary>
										<ul style="margin:8px 0 0 18px;">
											<?php foreach ( $error_items as $error ) : ?>
												<li><?php echo esc_html( (string) $error ); ?></li>
											<?php endforeach; ?>
										</ul>
									</details>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @return array<string,int>
	 */
	private static function get_temporary_counts(): array {
		$counts = array(
			'clinic' => 0,
			'doctor' => 0,
		);

		foreach ( array( 'clinic', 'doctor' ) as $post_type ) {
			$query = new \WP_Query(
				array(
					'post_type'      => $post_type,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => false,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => '_360_is_temporary',
							'value'   => '1',
							'compare' => '=',
						),
					),
				)
			);

			$counts[ $post_type ] = (int) $query->found_posts;
		}

		return $counts;
	}
}
