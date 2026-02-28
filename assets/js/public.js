(function ($) {
	'use strict';

	$(document).ready(function () {
		const form = $('#emsf-contact-form');
		const messageDiv = form.find('.emsf-form-message');

		initConditionalLogic();

		// Restrict phone inputs to numbers only
		form.on('input', 'input[type="tel"]', function () {
			this.value = this.value.replace(/[^0-9]/g, '');
		});

		// Navigation: Next Step
		form.on('click', '.emsf-next-btn', function () {
			messageDiv.hide().text('').removeClass('error success');

			const currentStep = $(this).closest('.emsf-form-step');
			const nextStepNum = parseInt(currentStep.data('step')) + 1;

			// Validate Current Step
			if (!validateStep(currentStep)) {
				return;
			}

			switchStep(nextStepNum);
		});

		// Navigation: Previous Step
		form.on('click', '.emsf-prev-btn', function () {
			messageDiv.hide().text('').removeClass('error success');
			const currentStep = $(this).closest('.emsf-form-step');
			const prevStepNum = parseInt(currentStep.data('step')) - 1;
			switchStep(prevStepNum);
		});

		// Validate Fields in a Container
		function validateStep(stepContainer) {
			let isValid = true;
			let firstError = null;
			let errorMessage = 'Please fill in all required fields correctly.';

			// Find all inputs that need validation
			stepContainer.find('input, select, textarea').each(function () {
				const field = $(this);

				// Skip if hidden or disabled
				if (field.is(':hidden') || field.prop('disabled')) return;

				const val = field.val() ? field.val().trim() : '';
				const isRequired = field.prop('required');
				const type = field.attr('type');

				// Clear previous error styles
				field.removeClass('emsf-input-error');
				field.closest('.choices').removeClass('emsf-input-error');

				// Check Required
				if (isRequired && val === '') {
					isValid = false;
					field.addClass('emsf-input-error');
					field.closest('.choices').addClass('emsf-input-error');
					if (!firstError) {
						firstError = field;
						errorMessage = 'Please fill in all required fields correctly.';
					}
				}

				// Check Email Format
				if (val !== '' && type === 'email' && !validateEmail(val)) {
					isValid = false;
					field.addClass('emsf-input-error');
					if (!firstError) {
						firstError = field;
						errorMessage = 'Please enter a valid email address.';
					}
				}
			});

			if (!isValid) {
				messageDiv.removeClass('success').addClass('error').text(errorMessage).show();
				if (firstError) {
					firstError.focus();
				}
			}

			return isValid;
		}

		function switchStep(stepNumber) {
			const steps = form.find('.emsf-form-step');
			const trackerItems = $('.emsf-step-item');

			// Validate if step exists
			if (form.find(`.emsf-form-step[data-step="${stepNumber}"]`).length === 0) {
				return;
			}

			// Update Form Steps
			steps.removeClass('active');
			form.find(`.emsf-form-step[data-step="${stepNumber}"]`).addClass('active');

			// Update Tracker
			trackerItems.removeClass('active completed');
			trackerItems.each(function () {
				const itemStep = parseInt($(this).data('step'));
				if (itemStep < stepNumber) {
					$(this).addClass('completed');
				} else if (itemStep === stepNumber) {
					$(this).addClass('active');
				}
			});
		}

		function validateEmail(email) {
			const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
			return re.test(email);
		}

		// Final Submission
		form.on('submit', function (e) {
			e.preventDefault();
			messageDiv.hide().text('').removeClass('error success');

			// Identify Current Active Step
			const activeStep = form.find('.emsf-form-step.active');

			// Validate Final Step before sending
			if (!validateStep(activeStep)) {
				return;
			}

			const submitBtn = form.find('button[type="submit"]');
			const originalBtnText = submitBtn.find('span:first').text();

			function performAjax(recaptchaToken = '') {
				submitBtn.prop('disabled', true).find('span:first').text('Sending...');

				// Prepare Form Data
				let formData = new FormData(form[0]);
				formData.append('action', 'emsf_submit_form');
				if (recaptchaToken) {
					formData.append('emsf_recaptcha_token', recaptchaToken);
				}

				$.ajax({
					url: emsfData.ajaxUrl,
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					success: function (response) {
						if (response.success) {
							messageDiv.removeClass('error').addClass('success').html(response.data.message).slideDown();
							form[0].reset();
							if (emsfData.recaptcha_type === 'v2' && typeof grecaptcha !== 'undefined' && typeof grecaptcha.reset === 'function') {
								try {
									grecaptcha.reset();
								} catch (e) {
									console.error('reCAPTCHA reset failed:', e);
								}
							}
							// Reset to Step 1
							setTimeout(function () {
								switchStep(1);
								submitBtn.prop('disabled', false).find('span:first').text(originalBtnText);
							}, 3000);
						} else {
							messageDiv.removeClass('success').addClass('error').html(response.data.message || 'An error occurred.').slideDown();

							// If error belongs to specific step, switch to it
							if (response.data.step) {
								switchStep(response.data.step);
							}

							submitBtn.prop('disabled', false).find('span:first').text(originalBtnText);
						}
					},
					error: function () {
						messageDiv.removeClass('success').addClass('error').text('An error occurred. Please try again.').slideDown();
						submitBtn.prop('disabled', false).find('span:first').text(originalBtnText);
					}
				});
			}

			// Execute reCAPTCHA or normal submit
			const isRecaptchaEnabled = parseInt(emsfData.recaptcha_enabled) === 1 && emsfData.recaptcha_site_key && typeof grecaptcha !== 'undefined';
			const isV3 = emsfData.recaptcha_type === 'v3';

			if (isRecaptchaEnabled && isV3) {
				submitBtn.prop('disabled', true).find('span:first').text('Sending...');
				grecaptcha.ready(function () {
					grecaptcha.execute(emsfData.recaptcha_site_key, { action: 'submit' }).then(function (token) {
						performAjax(token);
					}).catch(function (error) {
						submitBtn.prop('disabled', false).find('span:first').text(originalBtnText);
						messageDiv.removeClass('success').addClass('error').text('CAPTCHA Error: Invalid Site Key or API not loaded. Please check your settings.').slideDown();
					});
				});
			} else {
				performAjax();
			}
		});

		// Initialize Professional Controls
		initPremiumControls();

		// Conditional Logic Handler
		function initConditionalLogic() {
			const conditionalFields = $('.emsf-conditional-field');
			if (conditionalFields.length === 0) return;

			function evaluateLogic() {
				conditionalFields.each(function () {
					const fieldWrap = $(this);
					const targetId = fieldWrap.data('conditional-field');
					const targetValue = String(fieldWrap.data('conditional-value')).trim().toLowerCase();

					if (!targetId) return;

					const targetInput = $(`[name="${targetId}"], [name="emsf_custom[${targetId}]"]`);
					if (targetInput.length === 0) return;

					let currentVal = '';
					if (targetInput.is(':checkbox')) {
						currentVal = targetInput.is(':checked') ? '1' : '0';
					} else if (targetInput.is(':radio')) {
						currentVal = targetInput.filter(':checked').val();
					} else {
						currentVal = String(targetInput.val()).trim().toLowerCase();
					}

					if (currentVal === targetValue) {
						fieldWrap.slideDown(200).find('input, select, textarea').prop('disabled', false);
					} else {
						fieldWrap.slideUp(200).find('input, select, textarea').prop('disabled', true);
					}
				});
			}

			// Initial evaluation
			evaluateLogic();

			// Listen for changes on potential target fields
			form.on('change input', 'input, select, textarea', function () {
				evaluateLogic();
			});
		}

		function initPremiumControls() {
			// Initialize Flatpickr for Date Fields
			if (typeof flatpickr !== 'undefined') {
				$('input[type="date"]').each(function () {
					flatpickr(this, {
						dateFormat: "Y-m-d", // Native format for DB
						altInput: true,
						altFormat: "F j, Y", // User friendly format
						allowInput: true,
						animate: true
					});
				});
			}

			// Initialize Choices.js for Select Fields
			if (typeof Choices !== 'undefined') {
				$('select.emsf-form-control').each(function () {
					new Choices(this, {
						searchEnabled: false,
						itemSelectText: '',
						classNames: {
							containerOuter: 'choices emsf-form-control-choices'
						},
						shouldSort: false
					});
				});
			}
		}
	});

})(jQuery);
