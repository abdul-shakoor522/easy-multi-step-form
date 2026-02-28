<?php
/**
 * Admin panel for contact form submissions
 *
 * @package EasyMultiStepForm
 */

namespace EasyMultiStepForm\Includes;

class Admin {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
		add_action( 'admin_init', array( $this, 'ensure_database_column' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_dashboard_assets' ) );
		add_action( 'wp_ajax_emsf_send_test_email', array( $this, 'handle_test_email' ) );
	}

	/**
	 * Ensure the fields_data column exists (Self-repair)
	 */
	public function ensure_database_column() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'emsf_submissions';
		$column = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM $table_name LIKE %s", 'fields_data' ) );
		if ( empty( $column ) ) {
			$wpdb->query( "ALTER TABLE $table_name ADD fields_data longtext DEFAULT NULL AFTER status" );
		}
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_menu_page(
			esc_html__( 'Contact Form', 'easy-multi-step-form' ),
			esc_html__( 'Contact Form', 'easy-multi-step-form' ),
			'manage_options',
			'emsf-submissions',
			array( $this, 'render_submissions_page' ),
			'dashicons-email',
			30
		);

		add_submenu_page(
			'emsf-submissions',
			esc_html__( 'Submissions', 'easy-multi-step-form' ),
			esc_html__( 'Submissions', 'easy-multi-step-form' ),
			'manage_options',
			'emsf-submissions',
			array( $this, 'render_submissions_page' )
		);

		add_submenu_page(
			'emsf-submissions',
			esc_html__( 'Settings', 'easy-multi-step-form' ),
			esc_html__( 'Settings', 'easy-multi-step-form' ),
			'manage_options',
			'emsf-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Handle admin actions
	 */
	public function handle_admin_actions() {
		// Check for delete action
		if ( isset( $_GET['action'] ) && 'delete' === sanitize_text_field( wp_unslash( $_GET['action'] ) ) ) {
			if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'emsf_delete_submission' ) ) {
				wp_die( 'Security check failed' );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( 'You do not have permission to delete submissions' );
			}

			$submission_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
			if ( $submission_id ) {
				global $wpdb;
				$wpdb->delete(
					$wpdb->prefix . 'emsf_submissions',
					array( 'id' => $submission_id ),
					array( '%d' )
				);
				wp_safe_redirect( admin_url( 'admin.php?page=emsf-submissions' ) );
				exit;
			}
		}

		// Check for status update
		if ( isset( $_POST['emsf_update_status'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'emsf_update_status' ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( 'You do not have permission' );
			}

			$submission_id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
			$status        = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

			if ( $submission_id && $status ) {
				Database::update_submission_status( $submission_id, $status );
			}
		}

		// Handle Bulk Actions
		if ( isset( $_POST['emsf_bulk_action_submit'] ) && 'delete' === $_POST['emsf_bulk_action_type'] ) {
			if ( ! isset( $_POST['emsf_submissions_nonce'] ) || ! wp_verify_nonce( $_POST['emsf_submissions_nonce'], 'emsf_bulk_submissions' ) ) {
				wp_die( 'Security check failed' );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( 'You do not have permission' );
			}

			$ids = isset( $_POST['submission_ids'] ) ? array_map( 'intval', $_POST['submission_ids'] ) : array();
			if ( ! empty( $ids ) ) {
				global $wpdb;
				$table_name       = $wpdb->prefix . 'emsf_submissions';
				$placeholders     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM $table_name WHERE id IN ($placeholders)", $ids ) );
				
				set_transient( 'emsf_bulk_action_success', count( $ids ), 30 );
				wp_safe_redirect( admin_url( 'admin.php?page=emsf-submissions' ) );
				exit;
			}
		}
	}

	/**
	 * Render submissions page
	 */
	public function render_submissions_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page', 'easy-multi-step-form' ) );
		}

		// Check if we're viewing a single submission
		if ( isset( $_GET['action'] ) && 'view' === sanitize_text_field( wp_unslash( $_GET['action'] ) ) ) {
			$this->render_single_submission();
			$this->render_modal_html();
			return;
		}

		// Get Filter Parameters
		$current_status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : 'all';
		$search_query   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$submissions = Database::get_submissions(
			array(
				'status' => $current_status,
				'search' => $search_query,
				'limit'  => 50,
			)
		);

		// Handle Bulk Success Notice
		$bulk_success = get_transient( 'emsf_bulk_action_success' );
		if ( $bulk_success ) {
			delete_transient( 'emsf_bulk_action_success' );
			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( '%d submissions deleted successfully.', 'easy-multi-step-form' ), intval( $bulk_success ) ) . '</p></div>';
		}
		?>
		<div class="emsf-admin-wrap">
			<div class="emsf-header">
				<h1><?php esc_html_e( 'Contact Form Submissions', 'easy-multi-step-form' ); ?></h1>
			</div>

			<!-- Filter and Search Bar -->
			<div class="emsf-toolbar">
				<form method="get" action="" class="emsf-filter-form">
					<input type="hidden" name="page" value="emsf-submissions">
					
					<div class="emsf-filter-links">
						<a href="?page=emsf-submissions" class="<?php echo 'all' === $current_status ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'easy-multi-step-form' ); ?></a> |
						<a href="?page=emsf-submissions&status=new" class="<?php echo 'new' === $current_status ? 'current' : ''; ?>"><?php esc_html_e( 'New', 'easy-multi-step-form' ); ?></a> |
						<a href="?page=emsf-submissions&status=read" class="<?php echo 'read' === $current_status ? 'current' : ''; ?>"><?php esc_html_e( 'Read', 'easy-multi-step-form' ); ?></a>
					</div>

					<div class="emsf-search-box">
						<input type="search" name="s" value="<?php echo esc_attr( $search_query ); ?>" placeholder="<?php esc_attr_e( 'Search by name, email...', 'easy-multi-step-form' ); ?>">
						<button type="submit" class="button"><?php esc_html_e( 'Search Submissions', 'easy-multi-step-form' ); ?></button>
					</div>
				</form>
			</div>

			<form method="post" action="" id="emsf-bulk-action-form">
				<?php wp_nonce_field( 'emsf_bulk_submissions', 'emsf_submissions_nonce' ); ?>
				
				<div class="emsf-bulk-actions">
					<select name="emsf_bulk_action_type">
						<option value=""><?php esc_html_e( 'Bulk Actions', 'easy-multi-step-form' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete Permanently', 'easy-multi-step-form' ); ?></option>
					</select>
					<button type="submit" name="emsf_bulk_action_submit" class="button emsf-apply-bulk"><?php esc_html_e( 'Apply', 'easy-multi-step-form' ); ?></button>
				</div>

				<div class="emsf-submissions-table">
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<td id="cb" class="manage-column column-cb check-column">
									<input id="cb-select-all-1" type="checkbox">
								</td>
								<th width="50"><?php esc_html_e( 'ID', 'easy-multi-step-form' ); ?></th>
								<th><?php esc_html_e( 'Submitter', 'easy-multi-step-form' ); ?></th>
								<th><?php esc_html_e( 'Message Snippet', 'easy-multi-step-form' ); ?></th>
								<th width="120"><?php esc_html_e( 'Status', 'easy-multi-step-form' ); ?></th>
								<th width="150"><?php esc_html_e( 'Date', 'easy-multi-step-form' ); ?></th>
								<th width="150"><?php esc_html_e( 'Actions', 'easy-multi-step-form' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							if ( $submissions ) {
								foreach ( $submissions as $submission ) {
									$view_url   = admin_url( 'admin.php?page=emsf-submissions&action=view&id=' . $submission->id );
									$delete_url = wp_nonce_url( admin_url( 'admin.php?page=emsf-submissions&action=delete&id=' . $submission->id ), 'emsf_delete_submission' );
									?>
									<tr>
										<th scope="row" class="check-column">
											<input type="checkbox" name="submission_ids[]" value="<?php echo esc_attr( $submission->id ); ?>">
										</th>
										<td><?php echo esc_html( $submission->id ); ?></td>
										<td>
											<strong><?php echo esc_html( $submission->name ); ?></strong><br>
											<span class="description"><?php echo esc_html( $submission->email ); ?></span>
										</td>
										<td><?php echo wp_kses_post( wp_trim_words( $submission->message, 8 ) ); ?></td>
										<td>
											<span class="emsf-status-badge emsf-status-<?php echo esc_attr( $submission->status ); ?>">
												<?php echo esc_html( $submission->status ); ?>
											</span>
										</td>
										<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $submission->created_at ) ) ); ?></td>
										<td>
											<a href="<?php echo esc_url( $view_url ); ?>" class="button button-small"><?php esc_html_e( 'View', 'easy-multi-step-form' ); ?></a>
											<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small emsf-delete-link" style="color: #d63638;"><?php esc_html_e( 'Delete', 'easy-multi-step-form' ); ?></a>
										</td>
									</tr>
									<?php
								}
							} else {
								echo '<tr><td colspan="7" align="center">' . esc_html__( 'No submissions found.', 'easy-multi-step-form' ) . '</td></tr>';
							}
							?>
						</tbody>
					</table>
				</div>
			</form>
		</div>
		<?php
		$this->render_modal_html();
	}

	/**
	 * Render single submission details
	 */
	private function render_single_submission() {
		$submission_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
		if ( ! $submission_id ) {
			wp_die( esc_html__( 'Invalid submission ID', 'easy-multi-step-form' ) );
		}

		$submission = Database::get_submission( $submission_id );
		if ( ! $submission ) {
			wp_die( esc_html__( 'Submission not found', 'easy-multi-step-form' ) );
		}

		// Mark as read if it was new
		if ( 'new' === $submission->status ) {
			Database::update_submission_status( $submission_id, 'read' );
			$submission->status = 'read';
		}

		// Load form structure and submission data
		$form_structure = get_option( 'emsf_form_structure', array() );
		$fields_data    = array();
		if ( ! empty( $submission->fields_data ) ) {
			$fields_data = json_decode( $submission->fields_data, true );
			if ( ! is_array( $fields_data ) ) {
				$fields_data = array();
			}
		}

		// Prepare data grouped by steps
		$grouped_data = array();
		$all_processed_keys = array();

		// Helper for robust value retrieval
		$get_robust_value = function( $label, $fid, $submission, $fields_data ) use ( &$all_processed_keys ) {
			// 1. Check system properties on submission object
			if ( in_array( $fid, array( 'name', 'email', 'phone', 'message' ), true ) ) {
				if ( ! empty( $submission->$fid ) ) {
					$all_processed_keys[] = $fid;
					return $submission->$fid;
				}
			}

			// 2. Check exact Label match in fields_data
			if ( isset( $fields_data[ $label ] ) ) {
				$all_processed_keys[] = $label;
				return $fields_data[ $label ];
			}

			// 3. Check exact ID match in fields_data
			if ( isset( $fields_data[ $fid ] ) ) {
				$all_processed_keys[] = $fid;
				return $fields_data[ $fid ];
			}

			// 4. Fuzzy match (trimmed, case-insensitive)
			$search_label = trim( mb_strtolower( (string) $label ) );
			foreach ( $fields_data as $key => $val ) {
				if ( trim( mb_strtolower( (string) $key ) ) === $search_label ) {
					$all_processed_keys[] = $key;
					return $val;
				}
			}

			return '';
		};

		if ( ! empty( $form_structure ) ) {
			foreach ( $form_structure as $step ) {
				$step_fields = array();
				if ( ! empty( $step['fields'] ) ) {
					foreach ( $step['fields'] as $fid => $fconf ) {
						$label = isset( $fconf['label'] ) ? $fconf['label'] : $fid;
						$value = $get_robust_value( $label, $fid, $submission, $fields_data );

						if ( $value !== '' && $value !== null && $value !== false ) {
							$step_fields[] = array(
								'label'    => $label,
								'value'    => $value,
								'type'     => $fconf['type'] ?? 'text',
								'required' => ! empty( $fconf['required'] ),
							);
						}
					}
				}

				// Always add the step, even if empty, so the user sees all tabs
				$grouped_data[] = array(
					'title'  => ! empty( $step['title'] ) ? $step['title'] : __( 'Step', 'easy-multi-step-form' ),
					'fields' => $step_fields,
				);
			}
		}

		// Collect everything else from fields_data that wasn't used
		$legacy_data = array();
		foreach ( $fields_data as $key => $val ) {
			if ( ! in_array( $key, $all_processed_keys, true ) && $val !== '' && $val !== null && $val !== false ) {
				$legacy_data[] = array(
					'label' => $key,
					'value' => $val,
					'type'  => 'text',
				);
			}
		}

		if ( ! empty( $legacy_data ) ) {
			$grouped_data[] = array(
				'title'  => __( 'Legacy/Other Data', 'easy-multi-step-form' ),
				'fields' => $legacy_data,
			);
		}
		?>
		<div class="emsf-admin-wrap emsf-submission-view">
			<div class="emsf-header" style="margin-bottom: 30px;">
				<h1>
					<?php esc_html_e( 'Submission Details', 'easy-multi-step-form' ); ?> 
					<span style="color: var(--emsf-text-light); font-weight: 300; font-size: 0.8em; margin-left: 10px;">
						#<?php echo esc_html( $submission->id ); ?>
					</span>
				</h1>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=emsf-submissions' ) ); ?>" class="button button-large emsf-back-btn">
					<span class="dashicons dashicons-arrow-left-alt" style="margin-top: 4px;"></span>
					<?php esc_html_e( 'Back to List', 'easy-multi-step-form' ); ?>
				</a>
			</div>

			<!-- Tab Navigation -->
			<div class="emsf-tabs-nav">
				<?php foreach ( $grouped_data as $index => $group ) : ?>
					<button class="emsf-tab-nav-item<?php echo 0 === $index ? ' active' : ''; ?>" 
							onclick="emsfSwitchTab(event, 'emsf-tab-<?php echo esc_attr( $index ); ?>')">
						<?php echo esc_html( $group['title'] ); ?>
					</button>
				<?php endforeach; ?>
				<button class="emsf-tab-nav-item" onclick="emsfSwitchTab(event, 'emsf-tab-status')">
					<?php esc_html_e( 'Status & Metadata', 'easy-multi-step-form' ); ?>
				</button>
			</div>

			<!-- Card Content Container -->
			<div class="emsf-submission-card">
				<?php foreach ( $grouped_data as $index => $group ) : ?>
					<div id="emsf-tab-<?php echo esc_attr( $index ); ?>" class="emsf-tab-pane<?php echo 0 === $index ? ' active' : ''; ?>">
						<h3 class="emsf-pane-title">
							<span class="dashicons dashicons-text-page"></span>
							<?php echo esc_html( $group['title'] ); ?>
						</h3>
						
						<div class="emsf-fields-group">
							<?php if ( ! empty( $group['fields'] ) ) : ?>
								<?php foreach ( $group['fields'] as $field ) : ?>
									<div class="emsf-submission-field">
										<div class="emsf-field-label">
											<?php echo esc_html( $field['label'] ); ?>
											<?php if ( ! empty( $field['required'] ) ) : ?>
												<span style="color: var(--emsf-error);">*</span>
											<?php endif; ?>
										</div>
										<div class="emsf-field-value<?php echo 'textarea' === $field['type'] ? ' message-content' : ''; ?>">
											<?php 
											if ( 'file' === $field['type'] && ! empty( $field['value'] ) ) {
												$file_url = esc_url( $field['value'] );
												// robust extension check
												$file_path = parse_url( $file_url, PHP_URL_PATH );
												$file_ext = $file_path ? strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) ) : '';
												$file_name = basename( $file_path ?: $file_url );

												if ( in_array( $file_ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ) {
													echo '<a href="' . $file_url . '" target="_blank" class="emsf-image-preview" title="' . esc_attr( $file_name ) . '">';
													echo '<img src="' . $file_url . '" alt="' . esc_attr( $file_name ) . '">';
													echo '</a>';
												} else {
													echo '<a href="' . $file_url . '" target="_blank" class="emsf-file-link">';
													echo '<span class="dashicons dashicons-media-default"></span> ';
													echo esc_html( $file_name );
													echo '</a>';
												}
											} else {
												echo nl2br( esc_html( (string) $field['value'] ) );
											}
											?>
										</div>
									</div>
								<?php endforeach; ?>
							<?php else : ?>
								<div style="padding: 20px; text-align: center; background: #fafafa; border: 1px dashed #eee; border-radius: 4px;">
									<p style="color: var(--emsf-text-light); font-style: italic; margin: 0;">
										<?php esc_html_e( 'No information submitted for this step.', 'easy-multi-step-form' ); ?>
									</p>
								</div>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>

				<!-- Status & Metadata Tab -->
				<div id="emsf-tab-status" class="emsf-tab-pane">
					<h3 class="emsf-pane-title">
						<span class="dashicons dashicons-info"></span>
						<?php esc_html_e( 'Technical Details', 'easy-multi-step-form' ); ?>
					</h3>
					
					<div class="emsf-status-section">
						<div class="emsf-detail-group">
							<div class="emsf-field-label"><?php esc_html_e( 'Current Status', 'easy-multi-step-form' ); ?></div>
							<span class="emsf-status-badge emsf-status-<?php echo esc_attr( $submission->status ); ?>">
								<?php echo esc_html( ucfirst( $submission->status ) ); ?>
							</span>
						</div>
						
						<div class="emsf-detail-group">
							<div class="emsf-field-label"><?php esc_html_e( 'Submission Date', 'easy-multi-step-form' ); ?></div>
							<div class="emsf-field-value">
								<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $submission->created_at ) ) ); ?>
							</div>
						</div>
					</div>


					<div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end;">
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=emsf-submissions&action=delete&id=' . $submission->id ), 'emsf_delete_submission' ) ); ?>" 
						   class="button button-link-delete emsf-delete-link" style="color: var(--emsf-error);">
							<span class="dashicons dashicons-trash" style="margin-top: 3px;"></span>
							<?php esc_html_e( 'Delete permanently', 'easy-multi-step-form' ); ?>
						</a>
					</div>
				</div>
			</div>

			<style>
				/* Force styles even if cached */
				.emsf-tab-nav-item.active { border-bottom-color: #2271b1 !important; color: #2271b1 !important; background: #fff !important; }
				.emsf-pane-title .dashicons { margin-right: 8px; color: #2271b1; }
			</style>

			<script>
				function emsfSwitchTab(evt, tabId) {
					var i, tabContent, tabLinks;
					tabContent = document.getElementsByClassName("emsf-tab-pane");
					for (i = 0; i < tabContent.length; i++) {
						tabContent[i].style.display = "none";
						tabContent[i].classList.remove("active");
					}
					tabLinks = document.getElementsByClassName("emsf-tab-nav-item");
					for (i = 0; i < tabLinks.length; i++) {
						tabLinks[i].classList.remove("active");
					}
					var target = document.getElementById(tabId);
					if (target) {
						target.style.display = "block";
						target.classList.add("active");
					}
					evt.currentTarget.classList.add("active");
				}
			</script>
		</div>
		<?php
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page', 'easy-multi-step-form' ) );
		}

		// Save Settings
		if ( isset( $_POST['emsf_save_settings'] ) && wp_verify_nonce( $_POST['emsf_settings_nonce'], 'emsf_settings_save' ) ) {
			// Save General Settings
			update_option( 'emsf_admin_email', sanitize_email( $_POST['emsf_admin_email'] ) );
			update_option( 'emsf_email_subject', sanitize_text_field( $_POST['emsf_email_subject'] ) );
			update_option( 'emsf_success_message', sanitize_textarea_field( $_POST['emsf_success_message'] ) );
			update_option( 'emsf_background_email', isset( $_POST['emsf_background_email'] ) ? 1 : 0 );
			update_option( 'emsf_show_tracker', isset( $_POST['emsf_show_tracker'] ) ? 1 : 0 );

			// Save Styling Settings
			update_option( 'emsf_primary_color', sanitize_hex_color( $_POST['emsf_primary_color'] ) );
			update_option( 'emsf_primary_hover', sanitize_hex_color( $_POST['emsf_primary_hover'] ) );
			update_option( 'emsf_bg_input', sanitize_hex_color( $_POST['emsf_bg_input'] ) );
			update_option( 'emsf_form_bg', sanitize_hex_color( $_POST['emsf_form_bg'] ) );
			update_option( 'emsf_label_color', sanitize_hex_color( $_POST['emsf_label_color'] ) );
			update_option( 'emsf_placeholder_color', sanitize_hex_color( $_POST['emsf_placeholder_color'] ) );
			update_option( 'emsf_form_width', sanitize_text_field( $_POST['emsf_form_width'] ) );
			update_option( 'emsf_form_name', sanitize_text_field( $_POST['emsf_form_name'] ) );
			update_option( 'emsf_form_radius', sanitize_text_field( $_POST['emsf_form_radius'] ) );
			update_option( 'emsf_form_padding', sanitize_text_field( $_POST['emsf_form_padding'] ) );
			update_option( 'emsf_input_radius', sanitize_text_field( $_POST['emsf_input_radius'] ) );
			update_option( 'emsf_button_radius', sanitize_text_field( $_POST['emsf_button_radius'] ) );
			update_option( 'emsf_input_padding', sanitize_text_field( $_POST['emsf_input_padding'] ) );
			update_option( 'emsf_button_padding', sanitize_text_field( $_POST['emsf_button_padding'] ) );
			update_option( 'emsf_custom_css', wp_strip_all_tags( $_POST['emsf_custom_css'] ) );

			// Save Security Settings
			update_option( 'emsf_recaptcha_enabled', isset( $_POST['emsf_recaptcha_enabled'] ) ? 1 : 0 );
			update_option( 'emsf_recaptcha_type', sanitize_text_field( $_POST['emsf_recaptcha_type'] ?? 'v3' ) );
			update_option( 'emsf_recaptcha_site_key', sanitize_text_field( $_POST['emsf_recaptcha_site_key'] ) );
			update_option( 'emsf_recaptcha_secret_key', sanitize_text_field( $_POST['emsf_recaptcha_secret_key'] ) );

			// Save SMTP Settings
			update_option( 'emsf_smtp_enabled', isset( $_POST['emsf_smtp_enabled'] ) ? 1 : 0 );
			update_option( 'emsf_smtp_host', sanitize_text_field( $_POST['emsf_smtp_host'] ?? '' ) );
			update_option( 'emsf_smtp_port', sanitize_text_field( $_POST['emsf_smtp_port'] ?? '587' ) );
			update_option( 'emsf_smtp_encryption', sanitize_text_field( $_POST['emsf_smtp_encryption'] ?? 'tls' ) );
			update_option( 'emsf_smtp_username', sanitize_text_field( $_POST['emsf_smtp_username'] ?? '' ) );
			if ( ! empty( $_POST['emsf_smtp_password'] ) ) {
				// Strip spaces from app passwords (Google generates them with spaces like "abcd efgh ijkl mnop")
				$password = str_replace( ' ', '', sanitize_text_field( $_POST['emsf_smtp_password'] ) );
				update_option( 'emsf_smtp_password', $password );
			}

			// Save Form Structure
			$form_structure = array();
			if ( isset( $_POST['emsf_structure'] ) && is_array( $_POST['emsf_structure'] ) ) {
				foreach ( $_POST['emsf_structure'] as $step_id => $step_data ) {
					$fields = array();
					if ( isset( $step_data['fields'] ) && is_array( $step_data['fields'] ) ) {
						foreach ( $step_data['fields'] as $field_id => $field ) {
							$fields[ sanitize_key( $field_id ) ] = array(
										'label'       => sanitize_text_field( $field['label'] ),
										'type'        => sanitize_text_field( $field['type'] ),
										'placeholder' => isset( $field['placeholder'] ) ? sanitize_text_field( $field['placeholder'] ) : '',
										'options'     => isset( $field['options'] ) ? sanitize_textarea_field( $field['options'] ) : '',
										'width'       => isset( $field['width'] ) ? sanitize_text_field( $field['width'] ) : '100',
										'required'      => isset( $field['required'] ) ? intval( $field['required'] ) : 0,
										'system'        => isset( $field['system'] ) ? (bool) $field['system'] : false,
										'allowed_mimes' => isset( $field['allowed_mimes'] ) ? sanitize_text_field( $field['allowed_mimes'] ) : '',
										'max_size'      => isset( $field['max_size'] ) ? intval( $field['max_size'] ) : 5,
										'conditional_enabled' => isset( $field['conditional_enabled'] ) ? intval( $field['conditional_enabled'] ) : 0,
										'conditional_field'   => isset( $field['conditional_field'] ) ? sanitize_key( $field['conditional_field'] ) : '',
										'conditional_value'   => isset( $field['conditional_value'] ) ? sanitize_text_field( $field['conditional_value'] ) : '',
							);
						}
					}
					$form_structure[] = array(
						'id'     => sanitize_key( $step_id ),
						'title'  => sanitize_text_field( $step_data['title'] ),
						'fields' => $fields,
					);
				}
			}
			update_option( 'emsf_form_structure', $form_structure );
			
			// Legacy fallback: Save custom fields separately for backward compatibility if needed, 
			// but emsf_form_structure is now the source of truth.

			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved successfully!', 'easy-multi-step-form' ) . '</p></div>';
		}

		$admin_email     = get_option( 'emsf_admin_email', get_option( 'admin_email' ) );
		$email_subject   = get_option( 'emsf_email_subject', '[' . get_bloginfo( 'name' ) . '] New Submission' );
		$success_message = get_option( 'emsf_success_message', 'Thank you for your message! We will get back to you soon.' );
		$background_email = get_option( 'emsf_background_email', 1 );
		$show_tracker    = get_option( 'emsf_show_tracker', 1 );
		$as_active       = function_exists( 'as_enqueue_async_action' );

		// Styling defaults
		$primary_color = get_option( 'emsf_primary_color', '#0BC139' );
		$primary_hover = get_option( 'emsf_primary_hover', '#0F991F' );
		$bg_input      = get_option( 'emsf_bg_input', '#f8fafc' );
		$form_bg       = get_option( 'emsf_form_bg', '#ffffff' );
		$label_color   = get_option( 'emsf_label_color', '#1e293b' );
		$placeholder_color = get_option( 'emsf_placeholder_color', '#94a3b8' );
		$form_width    = get_option( 'emsf_form_width', '600px' );
		$form_name     = get_option( 'emsf_form_name', esc_html__( 'Get in Touch', 'easy-multi-step-form' ) );
		$form_radius   = get_option( 'emsf_form_radius', '12px' );
		$form_padding  = get_option( 'emsf_form_padding', '30px' );
		$input_radius  = get_option( 'emsf_input_radius', '8px' );
		$button_radius = get_option( 'emsf_button_radius', '8px' );
		$input_padding = get_option( 'emsf_input_padding', '12px 16px' );
		$button_padding = get_option( 'emsf_button_padding', '14px 24px' );
		$custom_css     = get_option( 'emsf_custom_css', '' );

		// Security Settings
		$recaptcha_enabled = get_option( 'emsf_recaptcha_enabled', 0 );
		$recaptcha_type    = get_option( 'emsf_recaptcha_type', 'v3' );
		$recaptcha_site_key = get_option( 'emsf_recaptcha_site_key', '' );
		$recaptcha_secret_key = get_option( 'emsf_recaptcha_secret_key', '' );

		// SMTP Settings
		$smtp_enabled    = get_option( 'emsf_smtp_enabled', 0 );
		$smtp_host       = get_option( 'emsf_smtp_host', '' );
		$smtp_port       = get_option( 'emsf_smtp_port', '587' );
		$smtp_encryption = get_option( 'emsf_smtp_encryption', 'tls' );
		$smtp_username   = get_option( 'emsf_smtp_username', '' );
		$smtp_password   = get_option( 'emsf_smtp_password', '' );

		// Prepare Form Structure (Migration Logic)
		$form_structure = get_option( 'emsf_form_structure', array() );
		if ( empty( $form_structure ) ) {
			$custom_fields = get_option( 'emsf_custom_fields', array() );
			
			// Step 1: Standard Info
			
			// Step 2: Custom fields + Message
			$step1_fields = array(
				'name'  => array('label' => 'Name', 'type' => 'text', 'required' => 1, 'width' => '50', 'system' => false, 'placeholder' => 'e.g., John Doe'),
				'email' => array('label' => 'Email', 'type' => 'email', 'required' => 1, 'width' => '50', 'system' => false, 'placeholder' => 'e.g., john@example.com'),
				'phone' => array('label' => 'Phone', 'type' => 'tel', 'required' => 0, 'width' => '100', 'system' => false, 'placeholder' => 'e.g., 1234567890'),
			);

			$step2_fields = $custom_fields;
			$step2_fields['message'] = array('label' => 'Your Message', 'type' => 'textarea', 'required' => 1, 'width' => '100', 'system' => false, 'placeholder' => 'How can we help you?');

			$form_structure = array(
				array( 'id' => 'step_1', 'title' => 'Contact Information', 'fields' => $step1_fields ),
				array( 'id' => 'step_2', 'title' => 'Message & Details', 'fields' => $step2_fields ),
			);
		}
		?>
		<div class="emsf-admin-wrap">
			<div class="emsf-header">
				<h1><?php esc_html_e( 'Form Settings', 'easy-multi-step-form' ); ?></h1>
			</div>

			<h2 class="nav-tab-wrapper">
				<a href="#general" class="nav-tab nav-tab-active"><?php esc_html_e( 'General Settings', 'easy-multi-step-form' ); ?></a>
				<a href="#builder" class="nav-tab"><?php esc_html_e( 'Form Builder', 'easy-multi-step-form' ); ?></a>
				<a href="#styling" class="nav-tab"><?php esc_html_e( 'Styling (Custom CSS)', 'easy-multi-step-form' ); ?></a>
				<a href="#security" class="nav-tab"><?php esc_html_e( 'Security (CAPTCHA)', 'easy-multi-step-form' ); ?></a>
				<a href="#smtp" class="nav-tab"><?php esc_html_e( 'Email (SMTP)', 'easy-multi-step-form' ); ?></a>
			</h2>

			<form method="post" action="">
				<?php wp_nonce_field( 'emsf_settings_save', 'emsf_settings_nonce' ); ?>

				<!-- General Tab -->
				<div id="emsf-tab-general" class="emsf-tab-content active">
					<div class="emsf-view-card emsf-shortcode-card" style="background: #f0f9ff; border: 1px solid #bae6fd; margin-bottom: 25px; cursor: pointer;">
						<div class="emsf-shortcode-display" id="emsf-copy-shortcode" title="<?php esc_attr_e( 'Click to copy shortcode', 'easy-multi-step-form' ); ?>">
							<div style="display: flex; align-items: center; justify-content: space-between;">
								<div>
									<h3 style="margin: 0; font-size: 14px; color: #0369a1;"><?php esc_html_e( 'How to use', 'easy-multi-step-form' ); ?></h3>
									<p style="margin: 5px 0 0; color: #0c4a6e;"><?php esc_html_e( 'Copy and paste this shortcode into any page or post:', 'easy-multi-step-form' ); ?></p>
								</div>
								<div class="emsf-copy-feedback" style="display: none; background: #0ea5e9; color: white; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 600;">
									<?php esc_html_e( 'Copied!', 'easy-multi-step-form' ); ?>
								</div>
							</div>
							<div style="margin-top: 15px; background: #ffffff; padding: 12px; border-radius: 6px; border: 1px dashed #0284c7; font-family: monospace; font-size: 16px; color: #0369a1; text-align: center; font-weight: bold;">
								[easy_multi_step_form]
							</div>
						</div>
					</div>

					<div class="emsf-view-card">
						<div class="emsf-data-row" style="flex-direction: column;">
							<div class="emsf-label" style="width: 100%; margin-bottom: 8px;"><?php esc_html_e( 'Notification Recipient Email', 'easy-multi-step-form' ); ?></div>
							<div class="emsf-value">
								<input type="email" name="emsf_admin_email" value="<?php echo esc_attr( $admin_email ); ?>" class="regular-text" style="width: 100%; max-width: 500px; padding: 10px;">
								<p class="description"><?php esc_html_e( 'The email address where you will receive form notifications.', 'easy-multi-step-form' ); ?></p>
							</div>
						</div>

						<div class="emsf-data-row" style="flex-direction: column;">
							<div class="emsf-label" style="width: 100%; margin-bottom: 8px;"><?php esc_html_e( 'Email Subject Prefix', 'easy-multi-step-form' ); ?></div>
							<div class="emsf-value">
								<input type="text" name="emsf_email_subject" value="<?php echo esc_attr( $email_subject ); ?>" class="regular-text" style="width: 100%; max-width: 500px; padding: 10px;">
								<p class="description"><?php esc_html_e( 'This will appear at the beginning of the email subject.', 'easy-multi-step-form' ); ?></p>
							</div>
						</div>

						<div class="emsf-data-row" style="flex-direction: column;">
							<div class="emsf-label" style="width: 100%; margin-bottom: 8px;"><?php esc_html_e( 'Form Success Message', 'easy-multi-step-form' ); ?></div>
							<div class="emsf-value">
								<textarea name="emsf_success_message" rows="4" class="large-text" style="width: 100%; max-width: 500px; padding: 10px;"><?php echo esc_textarea( $success_message ); ?></textarea>
								<p class="description"><?php esc_html_e( 'The message displayed to the user after a successful form submission.', 'easy-multi-step-form' ); ?></p>
							</div>
						</div>

						<div class="emsf-data-row" style="flex-direction: column; border-bottom: none;">
							<div class="emsf-label" style="width: 100%; margin-bottom: 8px;"><?php esc_html_e( 'Performance & Delivery', 'easy-multi-step-form' ); ?></div>
							<div class="emsf-value">
								<label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
									<input type="checkbox" name="emsf_background_email" value="1" <?php checked( $background_email, 1 ); ?>>
									<strong><?php esc_html_e( 'Send Emails in Background', 'easy-multi-step-form' ); ?></strong>
								</label>
								<p class="description">
									<?php esc_html_e( 'When enabled, emails are queued and sent in the background. This makes form submission feel significantly faster for your visitors.', 'easy-multi-step-form' ); ?>
									<?php if ( ! $as_active ) : ?>
										<br><span style="color: #646970; font-style: italic;">
											<span class="dashicons dashicons-info" style="font-size: 16px; width: 16px; height: 16px; vertical-align: middle;"></span>
											<?php esc_html_e( 'Action Scheduler not detected; using standard WordPress Cron for background processing.', 'easy-multi-step-form' ); ?>
										</span>
									<?php else : ?>
										<br><span style="color: #16a34a; font-weight: 600;">
											<span class="dashicons dashicons-yes-alt" style="font-size: 16px; width: 16px; height: 16px; vertical-align: middle;"></span>
											<?php esc_html_e( 'Action Scheduler is active. High-performance background processing enabled.', 'easy-multi-step-form' ); ?>
										</span>
									<?php endif; ?>
							</div>
						</div>

						<div class="emsf-data-row" style="flex-direction: column; border-bottom: none;">
							<div class="emsf-label" style="width: 100%; margin-bottom: 8px;"><?php esc_html_e( 'Form Display Options', 'easy-multi-step-form' ); ?></div>
							<div class="emsf-value">
								<label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
									<input type="checkbox" name="emsf_show_tracker" value="1" <?php checked( $show_tracker, 1 ); ?>>
									<strong><?php esc_html_e( 'Display Step Progress Tracker', 'easy-multi-step-form' ); ?></strong>
								</label>
								<p class="description"><?php esc_html_e( 'When enabled, the step numbers and titles will be displayed at the top of the form.', 'easy-multi-step-form' ); ?></p>
							</div>
						</div>
					</div>
				</div>

				<!-- Styling Tab -->
				<!-- Styling Tab -->
				<div id="emsf-tab-styling" class="emsf-tab-content">
					<!-- General Settings Section -->
					<!-- General Settings Section -->
					<div class="emsf-settings-section">
						<div class="emsf-settings-section-header">
							<span class="dashicons dashicons-admin-generic"></span>
							<?php esc_html_e( 'General Form Settings', 'easy-multi-step-form' ); ?>
						</div>
						<div class="emsf-settings-grid">
							<div class="emsf-settings-category">
								<div class="emsf-settings-category-title"><?php esc_html_e( 'Layout & Identity', 'easy-multi-step-form' ); ?></div>
								<div class="emsf-settings-row">
									<label><?php esc_html_e( 'Form Header Title', 'easy-multi-step-form' ); ?></label>
									<input type="text" name="emsf_form_name" value="<?php echo esc_attr( $form_name ); ?>" placeholder="Get in Touch">
								</div>
								<div class="emsf-settings-row">
									<label><?php esc_html_e( 'Form Max Width', 'easy-multi-step-form' ); ?></label>
									<input type="text" name="emsf_form_width" value="<?php echo esc_attr( $form_width ); ?>" placeholder="600px">
								</div>
							</div>

							<div class="emsf-settings-category" style="grid-column: span 1;">
								<div class="emsf-settings-category-title"><?php esc_html_e( 'Shape & Spacing', 'easy-multi-step-form' ); ?></div>
								<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
									<div class="emsf-settings-row">
										<label><?php esc_html_e( 'Input Radius', 'easy-multi-step-form' ); ?></label>
										<input type="text" name="emsf_input_radius" value="<?php echo esc_attr( $input_radius ); ?>" placeholder="8px">
									</div>
									<div class="emsf-settings-row">
										<label><?php esc_html_e( 'Button Radius', 'easy-multi-step-form' ); ?></label>
										<input type="text" name="emsf_button_radius" value="<?php echo esc_attr( $button_radius ); ?>" placeholder="8px">
									</div>
									<div class="emsf-settings-row">
										<label><?php esc_html_e( 'Input Padding', 'easy-multi-step-form' ); ?></label>
										<input type="text" name="emsf_input_padding" value="<?php echo esc_attr( $input_padding ); ?>" placeholder="12px 16px">
									</div>
									<div class="emsf-settings-row">
										<label><?php esc_html_e( 'Button Padding', 'easy-multi-step-form' ); ?></label>
										<input type="text" name="emsf_button_padding" value="<?php echo esc_attr( $button_padding ); ?>" placeholder="14px 24px">
									</div>
									<div class="emsf-settings-row">
										<label><?php esc_html_e( 'Form Radius', 'easy-multi-step-form' ); ?></label>
										<input type="text" name="emsf_form_radius" value="<?php echo esc_attr( $form_radius ); ?>" placeholder="12px">
									</div>
									<div class="emsf-settings-row">
										<label><?php esc_html_e( 'Form Padding', 'easy-multi-step-form' ); ?></label>
										<input type="text" name="emsf_form_padding" value="<?php echo esc_attr( $form_padding ); ?>" placeholder="30px">
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Colors Section -->
					<div class="emsf-settings-section">
						<div class="emsf-settings-section-header">
							<span class="dashicons dashicons-admin-appearance"></span>
							<?php esc_html_e( 'Color Palette', 'easy-multi-step-form' ); ?>
						</div>
						<div class="emsf-settings-grid">
							<!-- Brand Colors -->
							<div class="emsf-settings-category">
								<div class="emsf-settings-category-title"><?php esc_html_e( 'Brand & Action Colors', 'easy-multi-step-form' ); ?></div>
								<div class="emsf-settings-row">
									<label><?php esc_html_e( 'Primary Brand Color', 'easy-multi-step-form' ); ?></label>
									<div class="emsf-color-picker-wrap">
										<input type="color" name="emsf_primary_color" value="<?php echo esc_attr( $primary_color ); ?>" class="emsf-color-input">
										<code style="font-size: 11px;"><?php echo esc_html( $primary_color ); ?></code>
									</div>
								</div>
								<div class="emsf-settings-row">
									<label><?php esc_html_e( 'Hover State Color', 'easy-multi-step-form' ); ?></label>
									<div class="emsf-color-picker-wrap">
										<input type="color" name="emsf_primary_hover" value="<?php echo esc_attr( $primary_hover ); ?>" class="emsf-color-input">
										<code style="font-size: 11px;"><?php echo esc_html( $primary_hover ); ?></code>
									</div>
								</div>
							</div>

							<!-- Form UI Colors -->
							<div class="emsf-settings-category">
								<div class="emsf-settings-category-title"><?php esc_html_e( 'Surface & Text Colors', 'easy-multi-step-form' ); ?></div>
								<div class="emsf-settings-row">
									<label><?php esc_html_e( 'Form Background', 'easy-multi-step-form' ); ?></label>
									<div class="emsf-color-picker-wrap">
										<input type="color" name="emsf_form_bg" value="<?php echo esc_attr( $form_bg ); ?>" class="emsf-color-input">
										<code style="font-size: 11px;"><?php echo esc_html( $form_bg ); ?></code>
									</div>
								</div>
								<div class="emsf-settings-row">
									<label><?php esc_html_e( 'Input Background', 'easy-multi-step-form' ); ?></label>
									<div class="emsf-color-picker-wrap">
										<input type="color" name="emsf_bg_input" value="<?php echo esc_attr( $bg_input ); ?>" class="emsf-color-input">
										<code style="font-size: 11px;"><?php echo esc_html( $bg_input ); ?></code>
									</div>
								</div>
							</div>

							<!-- Typography Colors -->
							<div class="emsf-settings-category">
								<div class="emsf-settings-category-title"><?php esc_html_e( 'Typography & Labels', 'easy-multi-step-form' ); ?></div>
								<div class="emsf-settings-row">
									<label><?php esc_html_e( 'Field Label Color', 'easy-multi-step-form' ); ?></label>
									<div class="emsf-color-picker-wrap">
										<input type="color" name="emsf_label_color" value="<?php echo esc_attr( $label_color ); ?>" class="emsf-color-input">
										<code style="font-size: 11px;"><?php echo esc_html( $label_color ); ?></code>
									</div>
								</div>
								<div class="emsf-settings-row">
									<label><?php esc_html_e( 'Placeholder Color', 'easy-multi-step-form' ); ?></label>
									<div class="emsf-color-picker-wrap">
										<input type="color" name="emsf_placeholder_color" value="<?php echo esc_attr( $placeholder_color ); ?>" class="emsf-color-input">
										<code style="font-size: 11px;"><?php echo esc_html( $placeholder_color ); ?></code>
									</div>
								</div>
							</div>
						</div>
					</div>


					<!-- Custom CSS Section -->
					<div class="emsf-settings-section">
						<div class="emsf-settings-section-header">
							<span class="dashicons dashicons-editor-code"></span>
							<?php esc_html_e( 'Custom CSS Override', 'easy-multi-step-form' ); ?>
						</div>
						<div class="emsf-settings-category" style="max-width: 100%;">
							<div class="emsf-settings-row">
								<label><?php esc_html_e( 'Additional CSS', 'easy-multi-step-form' ); ?></label>
								<textarea name="emsf_custom_css" rows="10" style="width: 100%; font-family: monospace; padding: 15px; border-radius: 6px; border: 1px solid var(--emsf-border);" placeholder=".emsf-form-container { background: red; }"><?php echo esc_textarea( $custom_css ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Add your own CSS rules here to override form styles. Do not include <style> tags.', 'easy-multi-step-form' ); ?></p>
							</div>
						</div>
					</div>
				</div>

				<!-- Security Tab -->
				<div id="emsf-tab-security" class="emsf-tab-content">
					<div class="emsf-settings-section">
						<div class="emsf-settings-section-header">
							<span class="dashicons dashicons-shield"></span>
							<?php esc_html_e( 'Google reCAPTCHA', 'easy-multi-step-form' ); ?>
						</div>
						<div class="emsf-view-card" style="margin-top:20px;">
							<div class="emsf-data-row" style="flex-direction: column;">
								<div class="emsf-label" style="width: 100%; margin-bottom: 8px; display:flex; align-items:center; gap:10px;">
									<input type="checkbox" name="emsf_recaptcha_enabled" value="1" <?php checked( $recaptcha_enabled, 1 ); ?> style="margin:0;">
									<strong><?php esc_html_e( 'Enable reCAPTCHA Protection', 'easy-multi-step-form' ); ?></strong>
								</div>
								<p class="description"><?php esc_html_e( 'Enable invisible or checkbox spam protection.', 'easy-multi-step-form' ); ?></p>
							</div>

							<div class="emsf-data-row" style="flex-direction: column;">
								<div class="emsf-label" style="width: 100%; margin-bottom: 8px;"><?php esc_html_e( 'reCAPTCHA Version', 'easy-multi-step-form' ); ?></div>
								<div class="emsf-value">
									<select name="emsf_recaptcha_type" style="width: 100%; max-width: 500px; padding: 10px;">
										<option value="v3" <?php selected( $recaptcha_type, 'v3' ); ?>><?php esc_html_e( 'v3 (Invisible)', 'easy-multi-step-form' ); ?></option>
										<option value="v2" <?php selected( $recaptcha_type, 'v2' ); ?>><?php esc_html_e( 'v2 ("I\'m not a robot" Checkbox)', 'easy-multi-step-form' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Choose which version of reCAPTCHA you are using. Your keys must match the version selected.', 'easy-multi-step-form' ); ?></p>
								</div>
							</div>

							<div class="emsf-data-row" style="flex-direction: column;">
								<div class="emsf-label" style="width: 100%; margin-bottom: 8px;"><?php esc_html_e( 'Site Key', 'easy-multi-step-form' ); ?></div>
								<div class="emsf-value">
									<input type="text" name="emsf_recaptcha_site_key" value="<?php echo esc_attr( $recaptcha_site_key ); ?>" class="regular-text" style="width: 100%; max-width: 500px; padding: 10px;" placeholder="6Ld...">
								</div>
							</div>

							<div class="emsf-data-row" style="flex-direction: column; border-bottom: none;">
								<div class="emsf-label" style="width: 100%; margin-bottom: 8px;"><?php esc_html_e( 'Secret Key', 'easy-multi-step-form' ); ?></div>
								<div class="emsf-value">
									<input type="text" name="emsf_recaptcha_secret_key" value="<?php echo esc_attr( $recaptcha_secret_key ); ?>" class="regular-text" style="width: 100%; max-width: 500px; padding: 10px;" placeholder="6Ld...">
								</div>
							</div>
						</div>
						
						<div style="margin-top:20px; padding:15px; background:#f0f9ff; border-radius:8px; border:1px solid #bae6fd; color:#0369a1;">
							<span class="dashicons dashicons-info" style="color:#0369a1; vertical-align:middle; margin-right:5px;"></span>
							<?php printf( 
								esc_html__( 'You can get your keys from the %sGoogle reCAPTCHA Admin Console%s.', 'easy-multi-step-form' ),
								'<a href="https://www.google.com/recaptcha/admin" target="_blank" style="color:#0369a1; text-decoration:underline; font-weight:600;">',
								'</a>'
							); ?>
						</div>
					</div>
				</div>

				<!-- Field Builder Tab -->
				<div id="emsf-tab-builder" class="emsf-tab-content">
					<div class="emsf-view-card">
						<p class="description" style="margin-bottom: 20px;">
							<?php esc_html_e( 'Organize your form into multiple steps. You can add as many steps as you want and drag fields between them.', 'easy-multi-step-form' ); ?>
						</p>

						<div id="emsf-steps-container">
							<?php foreach ( $form_structure as $step ) : ?>
								<div class="emsf-step-item collapsed" data-id="<?php echo esc_attr( $step['id'] ); ?>">
									<div class="emsf-step-header">
										<h3>
											<span class="dashicons dashicons-arrow-down-alt2 emsf-toggle-step" style="cursor: pointer; margin-right: 5px; color: #646970;" title="<?php esc_attr_e( 'Toggle Step', 'easy-multi-step-form' ); ?>"></span>
											<span class="dashicons dashicons-menu" style="cursor: move; margin-right: 10px; color: #ccc;"></span>
											<?php esc_html_e( 'Step:', 'easy-multi-step-form' ); ?> 
											<input type="text" name="emsf_structure[<?php echo esc_attr( $step['id'] ); ?>][title]" value="<?php echo esc_attr( $step['title'] ); ?>" class="emsf-step-title-input">
										</h3>
										<?php if ( 'step_1' !== $step['id'] ) : // Prevent deleting step 1 ?>
											<span class="dashicons dashicons-trash emsf-remove-step" title="<?php esc_attr_e( 'Remove Step', 'easy-multi-step-form' ); ?>"></span>
										<?php endif; ?>
									</div>
									<div class="emsf-field-list">
										<?php
										if ( ! empty( $step['fields'] ) ) {
											foreach ( $step['fields'] as $field_id => $field ) {
												$is_system = false;
												$system_attr = '';
												$system_cls  = '';
												?>
												<div class="emsf-field-item<?php echo $system_cls; ?>" data-id="<?php echo esc_attr( $field_id ); ?>">
													<input type="hidden" name="emsf_structure[<?php echo esc_attr( $step['id'] ); ?>][fields][<?php echo esc_attr( $field_id ); ?>][system]" value="0">
													
													<div class="emsf-field-header">
														<div class="emsf-field-title">
															<span class="dashicons dashicons-move" style="cursor: move; color: #ccc;"></span>
															<span class="emsf-field-label-text"><?php echo esc_html( $field['label'] ); ?></span>
															<span class="emsf-field-type-badge"><?php echo esc_html( $field['type'] ); ?></span>
														</div>
															<div class="emsf-field-actions">
																<span class="dashicons dashicons-trash emsf-remove-field" title="<?php esc_attr_e( 'Remove Field', 'easy-multi-step-form' ); ?>"></span>
															</div>
													</div>
													<div class="emsf-field-grid">
														<div class="emsf-field-input-group">
															<label><?php esc_html_e( 'Field Label', 'easy-multi-step-form' ); ?></label>
															<input type="text" name="emsf_structure[<?php echo esc_attr( $step['id'] ); ?>][fields][<?php echo esc_attr( $field_id ); ?>][label]" value="<?php echo esc_attr( $field['label'] ); ?>" class="emsf-field-label-input">
														</div>
														<div class="emsf-field-input-group">
															<label><?php esc_html_e( 'Field Type', 'easy-multi-step-form' ); ?></label>
															<?php if ( $is_system ) : ?>
																<input type="text" value="<?php echo esc_attr( $field['type'] ); ?>" readonly style="background: #f0f0f1; border: 1px solid #ddd;">
																<input type="hidden" name="emsf_structure[<?php echo esc_attr( $step['id'] ); ?>][fields][<?php echo esc_attr( $field_id ); ?>][type]" value="<?php echo esc_attr( $field['type'] ); ?>">
															<?php else : ?>
																<select name="emsf_structure[<?php echo esc_attr( $step['id'] ); ?>][fields][<?php echo esc_attr( $field_id ); ?>][type]" class="emsf-field-type-select">
																	<option value="text" <?php selected( $field['type'], 'text' ); ?>><?php esc_html_e( 'Short Text', 'easy-multi-step-form' ); ?></option>
																	<option value="email" <?php selected( $field['type'], 'email' ); ?>><?php esc_html_e( 'Email', 'easy-multi-step-form' ); ?></option>
																	<option value="tel" <?php selected( $field['type'], 'tel' ); ?>><?php esc_html_e( 'Phone', 'easy-multi-step-form' ); ?></option>
																	<option value="textarea" <?php selected( $field['type'], 'textarea' ); ?>><?php esc_html_e( 'Long Text (Textarea)', 'easy-multi-step-form' ); ?></option>
																	<option value="select" <?php selected( $field['type'], 'select' ); ?>><?php esc_html_e( 'Dropdown (Select)', 'easy-multi-step-form' ); ?></option>
																	<option value="file" <?php selected( $field['type'], 'file' ); ?>><?php esc_html_e( 'File Upload', 'easy-multi-step-form' ); ?></option>
																	<option value="date" <?php selected( $field['type'], 'date' ); ?>><?php esc_html_e( 'Date Picker', 'easy-multi-step-form' ); ?></option>
																</select>
															<?php endif; ?>
														</div>

														<div class="emsf-field-input-group">
															<label><?php esc_html_e( 'Placeholder Text', 'easy-multi-step-form' ); ?></label>
															<input type="text" name="emsf_structure[<?php echo esc_attr( $step['id'] ); ?>][fields][<?php echo esc_attr( $field_id ); ?>][placeholder]" value="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>" placeholder="e.g., Enter your name..."></input>
														</div>
			
														<div class="emsf-field-input-group emsf-field-options" style="<?php echo 'select' === $field['type'] ? '' : 'display:none;'; ?>">
															<label><?php esc_html_e( 'Options (One per line)', 'easy-multi-step-form' ); ?></label>
															<textarea name="emsf_structure[<?php echo esc_attr( $step['id'] ); ?>][fields][<?php echo esc_attr( $field_id ); ?>][options]" rows="3" style="width:100%;"><?php echo esc_textarea( $field['options'] ?? '' ); ?></textarea>
														</div>
														<div class="emsf-field-input-group">
															<label><?php esc_html_e( 'Required?', 'easy-multi-step-form' ); ?></label>
															<select name="emsf_structure[<?php echo esc_attr( $step['id'] ); ?>][fields][<?php echo esc_attr( $field_id ); ?>][required]">
																<option value="0" <?php selected( $field['required'], '0' ); ?>><?php esc_html_e( 'No', 'easy-multi-step-form' ); ?></option>
																<option value="1" <?php selected( $field['required'], '1' ); ?>><?php esc_html_e( 'Yes', 'easy-multi-step-form' ); ?></option>
															</select>
														</div>
			
														<div class="emsf-field-input-group">
															<label><?php esc_html_e( 'Width', 'easy-multi-step-form' ); ?></label>
															<select name="emsf_structure[<?php echo esc_attr( $step['id'] ); ?>][fields][<?php echo esc_attr( $field_id ); ?>][width]">
																<option value="100" <?php selected( $field['width'] ?? '100', '100' ); ?>><?php esc_html_e( '100%', 'easy-multi-step-form' ); ?></option>
																<option value="50" <?php selected( $field['width'] ?? '100', '50' ); ?>><?php esc_html_e( '50%', 'easy-multi-step-form' ); ?></option>
																<option value="33" <?php selected( $field['width'] ?? '100', '33' ); ?>><?php esc_html_e( '33%', 'easy-multi-step-form' ); ?></option>
															</select>
														</div>

														<div class="emsf-field-input-group emsf-field-file-settings" style="<?php echo 'file' === $field['type'] ? '' : 'display:none;'; ?>">
															<label><?php esc_html_e( 'Allowed File Types (comma separated)', 'easy-multi-step-form' ); ?></label>
															<input type="text" name="emsf_structure[<?php echo esc_attr( $step['id'] ); ?>][fields][<?php echo esc_attr( $field_id ); ?>][allowed_mimes]" value="<?php echo esc_attr( $field['allowed_mimes'] ?? 'jpg, jpeg, png, pdf, doc, docx, mp3, mp4' ); ?>" placeholder="e.g. jpg, pdf">
														</div>
														<div class="emsf-field-input-group emsf-field-file-settings" style="<?php echo 'file' === $field['type'] ? '' : 'display:none;'; ?>">
															<label><?php esc_html_e( 'Max File Size (MB)', 'easy-multi-step-form' ); ?></label>
															<input type="number" name="emsf_structure[<?php echo esc_attr( $step['id'] ); ?>][fields][<?php echo esc_attr( $field_id ); ?>][max_size]" value="<?php echo esc_attr( $field['max_size'] ?? 5 ); ?>" min="1" max="50">
														</div>

														<!-- Conditional Logic Section -->
														<div class="emsf-field-input-group emsf-conditional-logic-wrap" style="grid-column: span 2; border-top: 1px dashed #e2e8f0; padding-top: 15px; margin-top: 5px;">
															<label>
																<input type="checkbox" name="emsf_structure[<?php echo esc_attr( $step['id'] ); ?>][fields][<?php echo esc_attr( $field_id ); ?>][conditional_enabled]" value="1" class="emsf-conditional-toggle" <?php checked( $field['conditional_enabled'] ?? 0, 1 ); ?>>
																<strong><?php esc_html_e( 'Enable Conditional Logic', 'easy-multi-step-form' ); ?></strong>
															</label>
															<div class="emsf-conditional-settings" style="<?php echo ! empty( $field['conditional_enabled'] ) ? '' : 'display:none;'; ?> margin-top:10px; padding:10px; background:#f8fafc; border-radius:4px; border:1px solid #e2e8f0;">
																<div style="display:flex; align-items:center; gap:10px;">
																	<span><?php esc_html_e( 'Show if', 'easy-multi-step-form' ); ?></span>
																	<select name="emsf_structure[<?php echo esc_attr( $step['id'] ); ?>][fields][<?php echo esc_attr( $field_id ); ?>][conditional_field]" class="emsf-conditional-field-select">
																		<option value=""><?php esc_html_e( '-- Select Field --', 'easy-multi-step-form' ); ?></option>
																		<?php if ( ! empty( $field['conditional_field'] ) ) : ?>
																			<option value="<?php echo esc_attr( $field['conditional_field'] ); ?>" selected><?php echo esc_html( $field['conditional_field'] ); ?></option>
																		<?php endif; ?>
																	</select>
																	<span><?php esc_html_e( 'equals', 'easy-multi-step-form' ); ?></span>
																	<input type="text" name="emsf_structure[<?php echo esc_attr( $step['id'] ); ?>][fields][<?php echo esc_attr( $field_id ); ?>][conditional_value]" value="<?php echo esc_attr( $field['conditional_value'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Value to match', 'easy-multi-step-form' ); ?>" style="flex:1;">
																</div>
																<p class="description" style="margin-top:5px; font-size:11px;"><?php esc_html_e( 'The field will be hidden until the selected field matches this value.', 'easy-multi-step-form' ); ?></p>
															</div>
														</div>
													</div>
												</div>
												<?php
											}
										}
										?>
									</div>
									<div class="emsf-step-actions">
										<button type="button" class="emsf-add-field-step-btn">
											<span class="dashicons dashicons-plus-alt2"></span>
											<?php esc_html_e( 'Add Field to this Step', 'easy-multi-step-form' ); ?>
										</button>
									</div>
								</div>
							<?php endforeach; ?>
						</div>

						<div class="emsf-add-actions" style="margin-top: 20px; display: flex; gap: 10px; justify-content: center;">
							<button type="button" id="emsf-add-step" class="emsf-add-step-btn button">
								<span class="dashicons dashicons-migrate"></span>
								<?php esc_html_e( 'Add New Step', 'easy-multi-step-form' ); ?>
							</button>
						</div>
					</div>
				</div>

				<!-- SMTP Tab -->
				<div id="emsf-tab-smtp" class="emsf-tab-content">
					<div class="emsf-settings-section">
						<div class="emsf-settings-section-header">
							<span class="dashicons dashicons-email-alt"></span>
							<?php esc_html_e( 'SMTP Mail Configuration', 'easy-multi-step-form' ); ?>
						</div>

						<div style="margin-bottom:20px; padding:15px; background:#fffbeb; border-radius:8px; border:1px solid #fde68a; color:#92400e;">
							<span class="dashicons dashicons-info" style="vertical-align:middle; margin-right:5px;"></span>
							<?php esc_html_e( 'Configure SMTP to ensure form notifications reach your inbox reliably. Without SMTP, emails sent by WordPress may land in spam or not be delivered at all.', 'easy-multi-step-form' ); ?>
						</div>

						<div class="emsf-view-card" style="margin-top:20px;">
							<div class="emsf-data-row" style="flex-direction: column;">
								<div class="emsf-label" style="width: 100%; margin-bottom: 8px; display:flex; align-items:center; gap:10px;">
									<input type="checkbox" name="emsf_smtp_enabled" value="1" <?php checked( $smtp_enabled, 1 ); ?> style="margin:0;">
									<strong><?php esc_html_e( 'Enable Custom SMTP', 'easy-multi-step-form' ); ?></strong>
								</div>
								<p class="description"><?php esc_html_e( 'When enabled, all emails from this plugin will be sent through your SMTP server instead of the default PHP mail.', 'easy-multi-step-form' ); ?></p>
							</div>

							<div class="emsf-data-row" style="flex-direction: column;">
								<div class="emsf-label" style="width: 100%; margin-bottom: 8px;"><?php esc_html_e( 'SMTP Host', 'easy-multi-step-form' ); ?></div>
								<div class="emsf-value">
									<input type="text" name="emsf_smtp_host" value="<?php echo esc_attr( $smtp_host ); ?>" class="regular-text" style="width: 100%; max-width: 500px; padding: 10px;" placeholder="smtp.gmail.com">
									<p class="description"><?php esc_html_e( 'For Gmail: smtp.gmail.com | For Outlook: smtp.office365.com', 'easy-multi-step-form' ); ?></p>
								</div>
							</div>

							<div class="emsf-data-row" style="flex-direction: column;">
								<div class="emsf-label" style="width: 100%; margin-bottom: 8px;"><?php esc_html_e( 'SMTP Port', 'easy-multi-step-form' ); ?></div>
								<div class="emsf-value">
									<input type="number" name="emsf_smtp_port" value="<?php echo esc_attr( $smtp_port ); ?>" class="regular-text" style="width: 100%; max-width: 500px; padding: 10px;" placeholder="587">
									<p class="description"><?php esc_html_e( 'Common ports: 587 (TLS), 465 (SSL), 25 (None)', 'easy-multi-step-form' ); ?></p>
								</div>
							</div>

							<div class="emsf-data-row" style="flex-direction: column;">
								<div class="emsf-label" style="width: 100%; margin-bottom: 8px;"><?php esc_html_e( 'Encryption', 'easy-multi-step-form' ); ?></div>
								<div class="emsf-value">
									<select name="emsf_smtp_encryption" style="width: 100%; max-width: 500px; padding: 10px;">
										<option value="tls" <?php selected( $smtp_encryption, 'tls' ); ?>><?php esc_html_e( 'TLS (Recommended)', 'easy-multi-step-form' ); ?></option>
										<option value="ssl" <?php selected( $smtp_encryption, 'ssl' ); ?>><?php esc_html_e( 'SSL', 'easy-multi-step-form' ); ?></option>
										<option value="none" <?php selected( $smtp_encryption, 'none' ); ?>><?php esc_html_e( 'None', 'easy-multi-step-form' ); ?></option>
									</select>
								</div>
							</div>

							<div class="emsf-data-row" style="flex-direction: column;">
								<div class="emsf-label" style="width: 100%; margin-bottom: 8px;"><?php esc_html_e( 'Your Email Address', 'easy-multi-step-form' ); ?></div>
								<div class="emsf-value">
									<input type="text" name="emsf_smtp_username" value="<?php echo esc_attr( $smtp_username ); ?>" class="regular-text" style="width: 100%; max-width: 500px; padding: 10px;" placeholder="your@gmail.com" autocomplete="off">
									<p class="description"><?php esc_html_e( 'The email address used to log in to your mail server.', 'easy-multi-step-form' ); ?></p>
								</div>
							</div>

							<div class="emsf-data-row" style="flex-direction: column; border-bottom: none;">
								<div class="emsf-label" style="width: 100%; margin-bottom: 8px;"><?php esc_html_e( 'SMTP Password / App Password', 'easy-multi-step-form' ); ?></div>
								<div class="emsf-value">
									<input type="password" name="emsf_smtp_password" value="" class="regular-text" style="width: 100%; max-width: 500px; padding: 10px;" placeholder="<?php echo ! empty( $smtp_password ) ? '••••••••••••••••' : ''; ?>" autocomplete="new-password">
									<p class="description">
										<?php if ( ! empty( $smtp_password ) ) : ?>
											<span style="color: #16a34a;">✓ <?php esc_html_e( 'Password is saved. Leave blank to keep current password.', 'easy-multi-step-form' ); ?></span><br>
										<?php endif; ?>
										<?php esc_html_e( 'For Gmail: Use an App Password (not your Gmail password). Spaces are automatically removed.', 'easy-multi-step-form' ); ?>
									</p>
								</div>
							</div>
						</div>

						<!-- Gmail Setup Guide -->
						<div style="margin-top:20px; padding:20px; background:#f0fdf4; border-radius:8px; border:1px solid #bbf7d0;">
							<h4 style="margin:0 0 12px; color:#166534;"><span class="dashicons dashicons-google" style="margin-right:5px;"></span><?php esc_html_e( 'Gmail Quick Setup Guide', 'easy-multi-step-form' ); ?></h4>
							<ol style="color:#15803d; margin:0; padding-left:20px; line-height:1.8;">
								<li><?php esc_html_e( 'Enable 2-Step Verification on your Google Account', 'easy-multi-step-form' ); ?></li>
								<li><?php printf( esc_html__( 'Go to %sGoogle App Passwords%s', 'easy-multi-step-form' ), '<a href="https://myaccount.google.com/apppasswords" target="_blank" style="color:#166534; font-weight:600;">', '</a>' ); ?></li>
								<li><?php esc_html_e( 'Create a new App Password (select "Mail" as the app)', 'easy-multi-step-form' ); ?></li>
								<li><?php esc_html_e( 'Use: Host = smtp.gmail.com, Port = 587, Encryption = TLS', 'easy-multi-step-form' ); ?></li>
								<li><?php esc_html_e( 'Paste the generated App Password in the password field above', 'easy-multi-step-form' ); ?></li>
							</ol>
						</div>

						<!-- Send Test Email Button -->
						<div style="margin-top:20px; padding:20px; background:#fff; border:1px solid #e2e8f0; border-radius:8px;">
							<h4 style="margin:0 0 10px;"><?php esc_html_e( 'Send Test Email', 'easy-multi-step-form' ); ?></h4>
							<p class="description" style="margin-bottom:12px;"><?php esc_html_e( 'Save your settings first, then click below to send a test email to the admin address.', 'easy-multi-step-form' ); ?></p>
							<button type="button" id="emsf-send-test-email" class="button button-secondary">
								<span class="dashicons dashicons-email" style="margin-top:4px; margin-right:4px;"></span>
								<?php esc_html_e( 'Send Test Email Now', 'easy-multi-step-form' ); ?>
							</button>
							<span id="emsf-test-email-result" style="margin-left:15px; font-weight:600;"></span>
						</div>
					</div>
				</div>

				<div style="margin-top: 30px; padding: 20px; background: #fff; border: 1px solid #c3c4c7; border-radius: 8px;">
					<button type="submit" name="emsf_save_settings" class="button button-primary button-large" style="height: 46px; padding: 0 30px; font-size: 15px; font-weight: 600;">
						<?php esc_html_e( 'Apply All Configurations', 'easy-multi-step-form' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
		$this->render_modal_html();
	}

	/**
	 * Render Modal HTML
	 */
	private function render_modal_html() {
		?>
		<div class="emsf-modal-overlay">
			<div class="emsf-modal">
				<div class="emsf-modal-icon">
					<span class="dashicons dashicons-warning"></span>
				</div>
				<h3 id="emsf-modal-title"><?php esc_html_e( 'Are you sure?', 'easy-multi-step-form' ); ?></h3>
				<p id="emsf-modal-description"><?php esc_html_e( 'This action cannot be undone. This submission will be permanently deleted from your database.', 'easy-multi-step-form' ); ?></p>
				<div class="emsf-modal-buttons">
					<button type="button" class="emsf-btn emsf-btn-cancel"><?php esc_html_e( 'No, Cancel', 'easy-multi-step-form' ); ?></button>
					<button type="button" class="emsf-btn emsf-btn-delete" id="emsf-confirm-delete"><?php esc_html_e( 'Yes, Delete', 'easy-multi-step-form' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Register Dashboard Widget
	 */
	public function register_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'emsf_dashboard_widget',
			esc_html__( 'Contact Form Activity', 'easy-multi-step-form' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * Enqueue Dashboard Assets
	 */
	public function enqueue_dashboard_assets( $hook ) {
		if ( 'index.php' !== $hook ) {
			return;
		}

		// Enqueue Chart.js from CDN (or local if prefered, but CDN is standard for lightweight plugins)
		wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.1', true );
		
		// Enqueue our admin script which now includes the chart logic
		// We need to make sure admin.js is enqueued on dashboard too, normally it might be limited to our plugin pages
		if ( ! wp_script_is( 'emsf-admin-script', 'enqueued' ) ) {
			wp_enqueue_script(
				'emsf-dashboard-script',
				EMSF_PLUGIN_URL . 'assets/js/admin.js',
				array( 'jquery', 'chart-js' ),
				EMSF_VERSION,
				true
			);
		}

		// Pass data to script
		$chart_data = Database::get_daily_submission_counts( 14 ); // Last 14 days
		wp_localize_script( 'emsf-dashboard-script', 'emsf_dashboard_data', $chart_data );
		wp_localize_script( 'emsf-admin-script', 'emsf_dashboard_data', $chart_data ); // Just in case name varies
	}

	/**
	 * Render Dashboard Widget Content
	 */
	public function render_dashboard_widget() {
		?>
		<div class="emsf-dashboard-widget">
			<div style="position: relative; height: 250px; width: 100%;">
				<canvas id="emsf-submission-chart"></canvas>
			</div>
			<div style="margin-top: 15px; border-top: 1px solid #f0f0f1; padding-top: 10px;">
				<p style="text-align:right; margin: 0;">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=emsf-submissions' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'View All Submissions', 'easy-multi-step-form' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle AJAX Test Email
	 */
	public function handle_test_email() {
		check_ajax_referer( 'emsf_form_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$to      = get_option( 'emsf_admin_email', get_option( 'admin_email' ) );
		$subject = '[' . get_bloginfo( 'name' ) . '] SMTP Test Email';
		$message = sprintf(
			"This is a test email from the Easy Multi Step Form plugin.\n\nIf you are reading this, your SMTP configuration is working correctly!\n\nSent at: %s\nSMTP Host: %s\nSMTP Port: %s\nEncryption: %s",
			current_time( 'mysql' ),
			get_option( 'emsf_smtp_host', 'Not configured' ),
			get_option( 'emsf_smtp_port', '587' ),
			strtoupper( get_option( 'emsf_smtp_encryption', 'tls' ) )
		);

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$result  = wp_mail( $to, $subject, $message, $headers );

		if ( $result ) {
			wp_send_json_success( array( 'message' => sprintf( 'Test email sent successfully to %s!', $to ) ) );
		} else {
			global $phpmailer;
			$error = '';
			if ( isset( $phpmailer ) && is_object( $phpmailer ) && ! empty( $phpmailer->ErrorInfo ) ) {
				$error = $phpmailer->ErrorInfo;
			}
			wp_send_json_error( array( 'message' => 'Failed to send test email. ' . $error ) );
		}
	}
}