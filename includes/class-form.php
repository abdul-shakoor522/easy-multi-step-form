<?php
/**
 * Contact form handler
 *
 * @package EasyMultiStepForm
 */

namespace EasyMultiStepForm\Includes;

class Form {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'wp_ajax_nopriv_emsf_submit_form', array( $this, 'handle_form_submission' ) );
		add_action( 'wp_ajax_emsf_submit_form', array( $this, 'handle_form_submission' ) );
		add_shortcode( 'easy_multi_step_form', array( $this, 'render_form' ) );

		// Background email actions for Action Scheduler
		add_action( 'emsf_send_admin_notification', array( $this, 'send_admin_notification' ), 10, 6 );
		add_action( 'emsf_send_user_confirmation', array( $this, 'send_user_confirmation' ), 10, 2 );
	}

	/**
	 * Render contact form shortcode
	 *
	 * @param array  $atts Shortcode attributes.
	 * @param string $content Shortcode content.
	 * @return string Form HTML.
	 */
	public function render_form( $atts, $content = '' ) {
		// Enqueue Google reCAPTCHA if enabled
		$recaptcha_enabled = get_option( 'emsf_recaptcha_enabled', 0 );
		$recaptcha_type    = get_option( 'emsf_recaptcha_type', 'v3' );
		$site_key          = get_option( 'emsf_recaptcha_site_key', '' );
		if ( $recaptcha_enabled && ! empty( $site_key ) ) {
			if ( 'v3' === $recaptcha_type ) {
				wp_enqueue_script( 'emsf-recaptcha', 'https://www.google.com/recaptcha/api.js?render=' . esc_attr( $site_key ), array(), null, true );
			} else {
				wp_enqueue_script( 'emsf-recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, true );
			}
		}

		wp_enqueue_script( 'emsf-public-scripts' );

		ob_start();

		// Get form width and name settings
		$form_width = get_option( 'emsf_form_width', '600px' );
		$form_width = sanitize_text_field( $form_width );
		$form_name = get_option( 'emsf_form_name', esc_html__( 'Get in Touch', 'easy-multi-step-form' ) );
		$form_name = sanitize_text_field( $form_name );
		$wrapper_style = 'max-width: ' . esc_attr( $form_width ) . ';';
		?>
		<div class="emsf-contact-form-wrapper" style="<?php echo esc_attr( $wrapper_style ); ?> margin: 0 auto;">
			<div class="emsf-form-header">
				<h2><?php echo esc_html( $form_name ); ?></h2>
				<!-- Progress Tracker -->
				<?php 
				$form_structure = get_option( 'emsf_form_structure', array() );
				
				// Fallback migration if empty (should be handled by admin, but safe to have here)
				if ( empty( $form_structure ) ) {
					// ...logic ... actually, if it's empty here, we likely haven't saved settings yet.
					// Let's use the same default as admin.
					$custom_fields = get_option( 'emsf_custom_fields', array() );
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

				$show_tracker = get_option( 'emsf_show_tracker', 1 );
				if ( $show_tracker ) : 
				?>
				<div class="emsf-steps-tracker">
					<?php
					$total_steps = count( $form_structure );
					foreach ( $form_structure as $index => $step ) {
						$step_num = $index + 1;
						$active_cls = ( 1 === $step_num ) ? ' active' : '';
						?>
						<div class="emsf-step-item<?php echo $active_cls; ?>" data-step="<?php echo esc_attr( $step_num ); ?>">
							<span class="emsf-step-number"><?php echo esc_html( $step_num ); ?></span>
							<span class="emsf-step-label"><?php echo esc_html( $step['title'] ); ?></span>
						</div>
						<?php if ( $step_num < $total_steps ) : ?>
							<div class="emsf-step-line"></div>
						<?php endif; ?>
						<?php
					}
					?>
				</div>
				<?php endif; ?>
				<?php $total_steps = count( $form_structure ); // Ensure $total_steps is available for field rendering ?>
			</div>
			
			<form id="emsf-contact-form" class="emsf-contact-form" novalidate enctype="multipart/form-data">
				<?php wp_nonce_field( 'emsf_form_nonce', 'emsf_nonce' ); ?>

				<!-- Global Message Area -->
				<div class="emsf-form-message" role="alert"></div>

				<?php
				foreach ( $form_structure as $index => $step ) {
					$step_num = $index + 1;
					$is_last_step = ( $step_num === $total_steps );
					$active_cls = ( 1 === $step_num ) ? ' active' : '';
					?>
					<!-- Step <?php echo esc_html( $step_num ); ?>: <?php echo esc_html( $step['title'] ); ?> -->
					<div class="emsf-form-step<?php echo $active_cls; ?>" data-step="<?php echo esc_attr( $step_num ); ?>">
						<div class="emsf-row">
							<?php
							if ( ! empty( $step['fields'] ) ) {
								foreach ( $step['fields'] as $field_id => $field ) {
									$label       = $field['label'];
									$type        = $field['type'];
									$width       = ! empty( $field['width'] ) ? $field['width'] : '100';
									$placeholder = ! empty( $field['placeholder'] ) ? $field['placeholder'] : '';
									$is_required = ! empty( $field['required'] );
									$req_attr    = $is_required ? 'required' : '';
									$req_label   = $is_required ? ' <span class="required">*</span>' : '';
									
									// System fields map to legacy names for backward compat
									$is_system = ! empty( $field['system'] );
									if ( $is_system ) {
										// Map system IDs to legacy names if needed, usually they match (name, email, phone)
										// Message is 'message'
										$input_name = $field_id; 
									} else {
										$input_name = 'emsf_custom[' . $field_id . ']';
									}

									$cond_enabled = ! empty( $field['conditional_enabled'] ) ? '1' : '0';
									$cond_field   = ! empty( $field['conditional_field'] ) ? $field['conditional_field'] : '';
									$cond_value   = ! empty( $field['conditional_value'] ) ? $field['conditional_value'] : '';
									$cond_class   = $cond_enabled === '1' ? ' emsf-conditional-field' : '';
									?>
									<div class="emsf-form-group emsf-col-<?php echo esc_attr( $width ); ?><?php echo esc_attr( $cond_class ); ?>" 
										 data-conditional-enabled="<?php echo esc_attr( $cond_enabled ); ?>"
										 data-conditional-field="<?php echo esc_attr( $cond_field ); ?>"
										 data-conditional-value="<?php echo esc_attr( $cond_value ); ?>">
										<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?><?php echo wp_kses_post( $req_label ); ?></label>
										
										<?php if ( 'textarea' === $type ) : ?>
											<textarea id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" class="emsf-form-control" rows="5" placeholder="<?php echo esc_attr( $placeholder ); ?>" <?php echo esc_attr( $req_attr ); ?>></textarea>
										<?php elseif ( 'select' === $type ) : ?>
											<select id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" class="emsf-form-control" <?php echo esc_attr( $req_attr ); ?>>
												<option value=""><?php esc_html_e( '-- Select --', 'easy-multi-step-form' ); ?></option>
												<?php
												if ( ! empty( $field['options'] ) ) {
													$options_list = explode( "\n", $field['options'] );
													foreach ( $options_list as $option ) {
														$option = trim( $option );
														if ( ! empty( $option ) ) {
															echo '<option value="' . esc_attr( $option ) . '">' . esc_html( $option ) . '</option>';
														}
													}
												}
												?>
											</select>
										<?php elseif ( 'file' === $type ) : ?>
											<input type="file" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" class="emsf-form-control" <?php echo esc_attr( $req_attr ); ?>>
										<?php elseif ( 'date' === $type ) : ?>
											<input type="date" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" class="emsf-form-control" <?php echo esc_attr( $req_attr ); ?>>
										<?php else : ?>
											<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" class="emsf-form-control" placeholder="<?php echo esc_attr( $placeholder ); ?>" <?php echo esc_attr( $req_attr ); ?>>
										<?php endif; ?>
									</div>
									<?php
								}
							}
							?>
						</div>

						<?php 
						$recaptcha_enabled = get_option( 'emsf_recaptcha_enabled', 0 );
						$recaptcha_type    = get_option( 'emsf_recaptcha_type', 'v3' );
						$site_key          = get_option( 'emsf_recaptcha_site_key', '' );
						if ( $is_last_step && $recaptcha_enabled && 'v2' === $recaptcha_type && ! empty( $site_key ) ) : 
						?>
							<div class="emsf-recaptcha-v2-container" style="margin-bottom: 25px; display: flex;">
								<div class="g-recaptcha" data-sitekey="<?php echo esc_attr( $site_key ); ?>"></div>
							</div>
						<?php endif; ?>

						<div class="emsf-step-navigation">
							<?php if ( $step_num > 1 ) : ?>
								<button type="button" class="emsf-prev-btn emsf-btn-secondary">
									<span class="dashicons dashicons-arrow-left-alt2"></span>
									<span><?php esc_html_e( 'Back', 'easy-multi-step-form' ); ?></span>
								</button>
							<?php endif; ?>
							
							<?php if ( ! $is_last_step ) : ?>
								<button type="button" class="emsf-next-btn emsf-submit-btn">
									<span><?php esc_html_e( 'Next Step', 'easy-multi-step-form' ); ?></span>
									<span class="dashicons dashicons-arrow-right-alt2"></span>
								</button>
							<?php else : ?>
								<button type="submit" class="emsf-submit-btn">
									<span><?php esc_html_e( 'Send Message', 'easy-multi-step-form' ); ?></span>
									<span class="dashicons dashicons-paper-plane"></span>
								</button>
							<?php endif; ?>
						</div>
					</div>
					<?php
				}
				?>
				<!-- Security/Token Area -->
				<input type="hidden" name="emsf_recaptcha_token" id="emsf-recaptcha-token" value="">
				</div>
			</form>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * Handle form submission via AJAX
	 */
	public function handle_form_submission() {
		// Verify nonce
		if ( ! isset( $_POST['emsf_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['emsf_nonce'] ) ), 'emsf_form_nonce' ) ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Security check failed', 'easy-multi-step-form' ),
				),
				403
			);
		}

		// Verify reCAPTCHA if enabled
		$recaptcha_enabled = get_option( 'emsf_recaptcha_enabled', 0 );
		if ( $recaptcha_enabled ) {
			$recaptcha_type = get_option( 'emsf_recaptcha_type', 'v3' );
			$token          = '';
			
			if ( 'v3' === $recaptcha_type ) {
				$token = isset( $_POST['emsf_recaptcha_token'] ) ? sanitize_text_field( $_POST['emsf_recaptcha_token'] ) : '';
			} else {
				$token = isset( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( $_POST['g-recaptcha-response'] ) : '';
			}

			$secret_key = get_option( 'emsf_recaptcha_secret_key', '' );

			if ( empty( $token ) ) {
				$msg = ( 'v2' === $recaptcha_type ) 
					? esc_html__( 'Please complete the CAPTCHA checkbox.', 'easy-multi-step-form' )
					: esc_html__( 'Security verification failed. Please refresh and try again.', 'easy-multi-step-form' );
				wp_send_json_error( array( 'message' => $msg ) );
			}

			$response = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', array(
				'body' => array(
					'secret'   => $secret_key,
					'response' => $token,
					'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '',
				),
			) );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Failed to connect to verification server.', 'easy-multi-step-form' ) ) );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! isset( $body->success ) || ! $body->success ) {
				wp_send_json_error( array( 'message' => esc_html__( 'CAPTCHA verification failed. Please check your credentials or try again.', 'easy-multi-step-form' ) ) );
			}

			if ( 'v3' === $recaptcha_type && isset( $body->score ) && $body->score < 0.5 ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Suspicious activity detected. Please try again.', 'easy-multi-step-form' ) ) );
			}
		}

		// Validate and sanitize input
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$message = isset( $_POST['message'] ) ? wp_kses_post( wp_unslash( $_POST['message'] ) ) : '';

		// If fields were submitted as emsf_custom[...] (builder makes fields custom), map them to core values
		$incoming_custom = isset( $_POST['emsf_custom'] ) ? $_POST['emsf_custom'] : array();
		if ( empty( $name ) && isset( $incoming_custom['name'] ) ) {
			$name = sanitize_text_field( wp_unslash( $incoming_custom['name'] ) );
		}
		if ( empty( $email ) && isset( $incoming_custom['email'] ) ) {
			$email = sanitize_email( wp_unslash( $incoming_custom['email'] ) );
		}
		if ( empty( $phone ) && isset( $incoming_custom['phone'] ) ) {
			$phone = sanitize_text_field( wp_unslash( $incoming_custom['phone'] ) );
		}
		if ( empty( $message ) && isset( $incoming_custom['message'] ) ) {
			$message = wp_kses_post( wp_unslash( $incoming_custom['message'] ) );
		}

		// Validate required fields based on form structure
		$form_structure = get_option( 'emsf_form_structure', array() );
		if ( ! empty( $form_structure ) ) {
			$step_num = 0;
			foreach ( $form_structure as $step ) {
				$step_num++;
				if ( empty( $step['fields'] ) || ! is_array( $step['fields'] ) ) {
					continue;
				}
				foreach ( $step['fields'] as $fid => $fconf ) {
					if ( ! empty( $fconf['required'] ) ) {
						$value = '';
						switch ( $fid ) {
							case 'name':
								$value = $name;
								break;
							case 'email':
								$value = $email;
								break;
							case 'phone':
								$value = $phone;
								break;
							case 'message':
								$value = $message;
								break;
							default:
								// Check in custom fields
								if ( ! empty( $fconf['type'] ) && 'file' === $fconf['type'] ) {
									$value = isset( $_FILES[ 'emsf_custom' ]['name'][ $fid ] ) ? $_FILES[ 'emsf_custom' ]['name'][ $fid ] : '';
								} else {
									$value = isset( $incoming_custom[ $fid ] ) ? sanitize_text_field( wp_unslash( $incoming_custom[ $fid ] ) ) : '';
								}
								break;
						}
						if ( empty( trim( $value ) ) ) {
							$label = isset( $fconf['label'] ) ? $fconf['label'] : $fid;
							wp_send_json_error(
								array(
									'message' => sprintf( esc_html__( 'The field "%s" is required.', 'easy-multi-step-form' ), $label ),
									'step'    => $step_num,
								)
							);
						}
					}

					// Validate email format
					if ( 'email' === $fid && ! empty( $email ) && ! is_email( $email ) ) {
						wp_send_json_error(
							array(
								'message' => esc_html__( 'Please enter a valid email address', 'easy-multi-step-form' ),
								'step'    => $step_num,
							)
						);
					}

					// Validate phone format
					if ( 'phone' === $fid && ! empty( $phone ) && ! preg_match( '/^[0-9]+$/', $phone ) ) {
						wp_send_json_error(
							array(
								'message' => esc_html__( 'Phone number must contain only digits', 'easy-multi-step-form' ),
								'step'    => $step_num,
							)
						);
					}
				}
			}
		}

		// Validate and sanitize custom fields
		$custom_fields_data = array();

		if ( ! empty( $form_structure ) ) {
			$step_num = 0;
			foreach ( $form_structure as $step ) {
				$step_num++;
				if ( empty( $step['fields'] ) || ! is_array( $step['fields'] ) ) {
					continue;
				}
				foreach ( $step['fields'] as $fid => $fconf ) {
					// Skip system fields (name, email, phone, message)
					if ( in_array( $fid, array( 'name', 'email', 'phone', 'message' ), true ) ) {
						continue;
					}
					
					$label = isset( $fconf['label'] ) ? $fconf['label'] : $fid;
					
					// Handle File Uploads
					if ( ! empty( $fconf['type'] ) && 'file' === $fconf['type'] ) {
						if ( ! empty( $_FILES['emsf_custom']['name'][ $fid ] ) ) {
							require_once ABSPATH . 'wp-admin/includes/file.php';
							
							$file_data = array(
								'name'     => $_FILES['emsf_custom']['name'][ $fid ],
								'type'     => $_FILES['emsf_custom']['type'][ $fid ],
								'tmp_name' => $_FILES['emsf_custom']['tmp_name'][ $fid ],
								'error'    => $_FILES['emsf_custom']['error'][ $fid ],
								'size'     => $_FILES['emsf_custom']['size'][ $fid ],
							);
							
							// Get per-field constraints
							$allowed_exts = ! empty( $fconf['allowed_mimes'] ) ? array_map( 'trim', explode( ',', strtolower( $fconf['allowed_mimes'] ) ) ) : array( 'jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx' );
							$max_size_mb  = ! empty( $fconf['max_size'] ) ? intval( $fconf['max_size'] ) : 5;
							$max_size_bytes = $max_size_mb * 1024 * 1024;

							// 1. Validate File Size
							if ( $file_data['size'] > $max_size_bytes ) {
								wp_send_json_error(
									array(
										'message' => sprintf( esc_html__( 'File "%s" is too large. Max allowed: %dMB', 'easy-multi-step-form' ), $label, $max_size_mb ),
										'step'    => $step_num,
									)
								);
							}

							// 2. Validate File Extension
							$file_ext = strtolower( pathinfo( $file_data['name'], PATHINFO_EXTENSION ) );
							if ( ! in_array( $file_ext, $allowed_exts, true ) ) {
								wp_send_json_error(
									array(
										'message' => sprintf( esc_html__( 'File type .%s is not allowed for "%s". Allowed: %s', 'easy-multi-step-form' ), $file_ext, $label, implode( ', ', $allowed_exts ) ),
										'step'    => $step_num,
									)
								);
							}

							// Simple restricted mime types (images, pdf, docs)
							$overrides = array(
								'test_form' => false,
								'mimes'     => array(
									'jpg|jpeg|jpe' => 'image/jpeg',
									'gif'          => 'image/gif',
									'png'          => 'image/png',
									'pdf'          => 'application/pdf',
									'doc'          => 'application/msword',
									'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
									'zip'          => 'application/zip',
									'txt'          => 'text/plain',
									'mp3'          => 'audio/mpeg',
									'mp4'          => 'video/mp4',
								),
							);
							
							// Dynamically add requested mimes to overrides if they aren't in the standard list above
							// Note: WordPress wp_handle_upload already checks mimes, but we want to be explicit.
							
							$movefile = wp_handle_upload( $file_data, $overrides );
							
							if ( $movefile && ! isset( $movefile['error'] ) ) {
								$custom_fields_data[ $label ] = $movefile['url'];
							} else {
								wp_send_json_error(
									array(
										'message' => sprintf( esc_html__( 'File upload failed for "%s": %s', 'easy-multi-step-form' ), $label, $movefile['error'] ),
										'step'    => $step_num,
									)
								);
							}
						}
					} else {
						$value = isset( $incoming_custom[ $fid ] ) ? sanitize_text_field( wp_unslash( $incoming_custom[ $fid ] ) ) : '';
						
						// Save field if it has ANY value (string, number, etc) - not just non-empty check
						if ( $value !== '' && $value !== null && $value !== false ) {
							$custom_fields_data[ $label ] = $value;
						}
					}
				}
			}
		}

		// Save submission to database
		$submission_id = Database::save_submission(
			array(
				'name'        => $name,
				'email'       => $email,
				'phone'       => $phone,
				'message'     => $message,
				'fields_data' => $custom_fields_data,
			)
		);

		if ( ! $submission_id ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'Failed to save submission', 'easy-multi-step-form' ),
				)
			);
		}

		// Defer email sending to background if enabled
		$background_email = get_option( 'emsf_background_email', 1 );
		if ( $background_email ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( 'emsf_send_admin_notification', array( $submission_id, $name, $email, $phone, $message, $custom_fields_data ) );
				as_enqueue_async_action( 'emsf_send_user_confirmation', array( $name, $email ) );
			} else {
				// Fallback to standard WP Cron for background processing
				wp_schedule_single_event( time(), 'emsf_send_admin_notification', array( $submission_id, $name, $email, $phone, $message, $custom_fields_data ) );
				wp_schedule_single_event( time(), 'emsf_send_user_confirmation', array( $name, $email ) );
			}
		} else {
			// Synchronous sending
			$this->send_admin_notification( $submission_id, $name, $email, $phone, $message, $custom_fields_data );
			$this->send_user_confirmation( $name, $email );
		}

		$success_message = get_option( 'emsf_success_message', esc_html__( 'Thank you for your message! We will get back to you soon.', 'easy-multi-step-form' ) );

		wp_send_json_success(
			array(
				'message'         => $success_message,
				'submission_id'   => $submission_id,
			)
		);
	}

	/**
	 * Send admin notification email
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $name Submitter name.
	 * @param string $email Submitter email.
	 * @param string $phone Submitter phone.
	 * @param string $message Submission message.
	 * @param array  $custom_fields Custom fields data.
	 */
	public function send_admin_notification( $submission_id, $name, $email, $phone, $message, $custom_fields = array() ) {
		$admin_email   = get_option( 'emsf_admin_email', get_option( 'admin_email' ) );
		$blog_name     = get_bloginfo( 'name' );
		$subject_pref  = get_option( 'emsf_email_subject', '[' . $blog_name . '] New Submission' );

		$subject = sprintf(
			/* translators: %s: prefix, %s: name, %d: id */
			esc_html__( '%s: %s (#%d)', 'easy-multi-step-form' ),
			$subject_pref,
			$name,
			$submission_id
		);

		// Build email body with ALL fields organized by step
		$fields_html = '';
		$form_structure = get_option( 'emsf_form_structure', array() );

		if ( ! empty( $form_structure ) ) {
			foreach ( $form_structure as $step ) {
				if ( empty( $step['fields'] ) || ! is_array( $step['fields'] ) ) {
					continue;
				}

				$step_items = array();
				foreach ( $step['fields'] as $fid => $fconf ) {
					$label = isset( $fconf['label'] ) ? $fconf['label'] : $fid;
					$value = '';

					// Get value from system fields or custom fields
					switch ( $fid ) {
						case 'name':
							$value = $name;
							break;
						case 'email':
							$value = $email;
							break;
						case 'phone':
							$value = $phone;
							break;
						case 'message':
							$value = $message;
							break;
						default:
							$value = isset( $custom_fields[ $label ] ) ? $custom_fields[ $label ] : '';
							break;
					}

					if ( $value !== '' && $value !== null && $value !== false ) {
						$step_items[] = array( 'label' => $label, 'value' => $value, 'type' => $fconf['type'] ?? 'text' );
					}
				}

				if ( ! empty( $step_items ) ) {
					$step_title = ! empty( $step['title'] ) ? $step['title'] : __( 'Step', 'easy-multi-step-form' );
					$fields_html .= '<div style="margin-top:20px;padding:15px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;">';
					$fields_html .= '<h3 style="margin:0 0 12px;color:#2271b1;font-size:14px;border-bottom:1px solid #f0f0f1;padding-bottom:8px;">' . esc_html( $step_title ) . '</h3>';
					foreach ( $step_items as $item ) {
						$display_value = esc_html( $item['value'] );
						// Make file URLs clickable
						if ( 'file' === $item['type'] && filter_var( $item['value'], FILTER_VALIDATE_URL ) ) {
							$display_value = '<a href="' . esc_url( $item['value'] ) . '" target="_blank" style="color:#2271b1;">' . esc_html( basename( $item['value'] ) ) . '</a>';
						}
						$fields_html .= '<p style="margin:6px 0;"><strong>' . esc_html( $item['label'] ) . ':</strong> ' . $display_value . '</p>';
					}
					$fields_html .= '</div>';
				}
			}
		}

		// Fallback: if no form structure, show raw data
		if ( empty( $fields_html ) ) {
			$fields_html .= '<div style="margin-top:20px;padding:15px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;">';
			$fields_html .= '<p><strong>' . esc_html__( 'Name:', 'easy-multi-step-form' ) . '</strong> ' . esc_html( $name ) . '</p>';
			$fields_html .= '<p><strong>' . esc_html__( 'Email:', 'easy-multi-step-form' ) . '</strong> ' . esc_html( $email ) . '</p>';
			if ( ! empty( $phone ) ) {
				$fields_html .= '<p><strong>' . esc_html__( 'Phone:', 'easy-multi-step-form' ) . '</strong> ' . esc_html( $phone ) . '</p>';
			}
			$fields_html .= '<p><strong>' . esc_html__( 'Message:', 'easy-multi-step-form' ) . '</strong> ' . nl2br( esc_html( $message ) ) . '</p>';
			if ( ! empty( $custom_fields ) ) {
				foreach ( $custom_fields as $cf_label => $cf_value ) {
					$fields_html .= '<p><strong>' . esc_html( $cf_label ) . ':</strong> ' . esc_html( $cf_value ) . '</p>';
				}
			}
			$fields_html .= '</div>';
		}

		$email_content = '
		<div style="font-family: sans-serif; padding: 20px; color: #333; line-height: 1.6;">
			<h2 style="color: #2271b1; border-bottom: 2px solid #2271b1; padding-bottom: 10px;">' . esc_html__( 'New Contact Form Submission', 'easy-multi-step-form' ) . '</h2>
			<p><strong>' . esc_html__( 'Submission ID:', 'easy-multi-step-form' ) . '</strong> #' . $submission_id . '</p>
			' . $fields_html . '
			<p style="margin-top: 30px;">
				<a href="' . esc_url( admin_url( 'admin.php?page=emsf-submissions&action=view&id=' . $submission_id ) ) . '" 
				   style="background: #2271b1; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 4px; font-weight: bold;">
					' . esc_html__( 'View Submission in Dashboard', 'easy-multi-step-form' ) . '
				</a>
			</p>
			<hr style="margin-top: 40px; border: 0; border-top: 1px solid #ddd;">
			<p style="font-size: 12px; color: #646970;">' . sprintf( esc_html__( 'Sent from %s', 'easy-multi-step-form' ), $blog_name ) . '</p>
		</div>';

		$headers = array( 
			'Content-Type: text/html; charset=UTF-8',
			'Reply-To: ' . $name . ' <' . $email . '>'
		);

		// Add error logging for debugging
		add_action( 'wp_mail_failed', function( $error ) {
			error_log( 'Easy Multi Step Form - wp_mail failed: ' . $error->get_error_message() );
		});

		$sent = wp_mail( $admin_email, $subject, $email_content, $headers );

		if ( ! $sent ) {
			error_log( 'Easy Multi Step Form: Admin email failed to send to ' . $admin_email );
		}
	}

	/**
	 * Send user confirmation email
	 *
	 * @param string $name User name.
	 * @param string $email User email.
	 */
	public function send_user_confirmation( $name, $email ) {
		$blog_name = get_bloginfo( 'name' );
		$subject   = sprintf(
			/* translators: %s: blog name */
			esc_html__( 'We received your message from %s', 'easy-multi-step-form' ),
			$blog_name
		);

		$email_content = sprintf(
			'<html><body>
			<h2>%s</h2>
			<p>%s %s,</p>
			<p>%s</p>
			<p>%s</p>
			<hr>
			<p>%s<br>%s</p>
			</body></html>',
			esc_html__( 'Thank You!', 'easy-multi-step-form' ),
			esc_html__( 'Hi', 'easy-multi-step-form' ),
			esc_html( $name ),
			esc_html__( 'Thank you for contacting us. We have received your message and will get back to you as soon as possible.', 'easy-multi-step-form' ),
			esc_html__( 'We appreciate your patience and look forward to assisting you.', 'easy-multi-step-form' ),
			get_bloginfo( 'name' ),
			home_url()
		);

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$sent = wp_mail( $email, $subject, $email_content, $headers );

		if ( ! $sent ) {
			error_log( 'Easy Multi Step Form: User confirmation email failed to send to ' . $email );
		}
	}
}