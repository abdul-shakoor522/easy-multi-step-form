(function ($) {
    'use strict';

    $(document).ready(function () {
        const modalOverlay = $('.emsf-modal-overlay');
        const modalDeleteBtn = $('#emsf-confirm-delete');
        const modalCancelBtn = $('.emsf-btn-cancel');
        let pendingDelete = null;
        const modalTitle = $('#emsf-modal-title');
        const modalDesc = $('#emsf-modal-description');
        const defaultTitle = 'Are you sure?';
        const defaultDesc = 'This action cannot be undone. This submission will be permanently deleted from your database.';

        function openModal(target, title, description) {
            pendingDelete = target;
            modalTitle.text(title || defaultTitle);
            modalDesc.text(description || defaultDesc);
            modalDeleteBtn.text('Yes, Delete').prop('disabled', false);
            modalOverlay.css('display', 'flex');
        }

        // Handle delete button click in table or view page
        $(document).on('click', '.emsf-delete-link', function (e) {
            e.preventDefault();
            const url = $(this).attr('href');
            openModal(url, defaultTitle, defaultDesc);
        });

        // Close modal on cancel
        modalCancelBtn.on('click', function () {
            modalOverlay.hide();
            pendingDelete = null;
        });

        // Close modal on overlay click
        modalOverlay.on('click', function (e) {
            if ($(e.target).hasClass('emsf-modal-overlay')) {
                modalOverlay.hide();
                pendingDelete = null;
            }
        });

        // Proceed with delete
        modalDeleteBtn.on('click', function () {
            if (!pendingDelete) return;

            if (typeof pendingDelete === 'string') {
                // It's a URL (Submission Delete)
                $(this).text('Deleting...').prop('disabled', true);
                window.location.href = pendingDelete;
            } else if (typeof pendingDelete === 'object') {
                // It's a jQuery Element (Step/Field Delete)
                pendingDelete.fadeOut(300, function () {
                    $(this).remove();
                });
                modalOverlay.hide();
                pendingDelete = null;
            }
        });

        // Select All checkboxes
        const selectAll = $('#cb-select-all-1');
        const rowCheckboxes = $('input[name="submission_ids[]"]');

        selectAll.on('change', function () {
            rowCheckboxes.prop('checked', $(this).prop('checked'));
        });

        rowCheckboxes.on('change', function () {
            if (rowCheckboxes.filter(':checked').length === rowCheckboxes.length) {
                selectAll.prop('checked', true);
            } else {
                selectAll.prop('checked', false);
            }
        });

        // Handle Bulk Action validation
        $('#emsf-bulk-action-form').on('submit', function (e) {
            const action = $('select[name="emsf_bulk_action_type"]').val();
            const checkedCount = rowCheckboxes.filter(':checked').length;

            if ('delete' === action) {
                if (0 === checkedCount) {
                    alert('Please select at least one submission to delete.');
                    e.preventDefault();
                    return;
                }

                if (!confirm(`Are you sure you want to permanently delete ${checkedCount} submissions?`)) {
                    e.preventDefault();
                }
            }
        });
        // Copy Shortcode Logic
        $('#emsf-copy-shortcode').on('click', function () {
            const shortcode = '[easy_multi_step_form]';
            const tempInput = $('<input>');
            $('body').append(tempInput);
            tempInput.val(shortcode).select();
            document.execCommand('copy');
            tempInput.remove();

            const feedback = $('.emsf-copy-feedback');
            feedback.fadeIn(200);
            setTimeout(function () {
                feedback.fadeOut(500);
            }, 2000);
        });

        // Tab Switching Logic
        $('.nav-tab-wrapper a').on('click', function (e) {
            e.preventDefault();
            const target = $(this).attr('href').replace('#', '');

            // Update Tabs
            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');

            // Update Content
            $('.emsf-tab-content').removeClass('active');
            $(`#emsf-tab-${target}`).addClass('active');

            // Persist tab via URL hash (optional but nice)
            window.location.hash = target;
        });

        // Initialize from URL hash
        if (window.location.hash) {
            const hash = window.location.hash;
            $(`.nav-tab-wrapper a[href="${hash}"]`).trigger('click');
        }

        // Multi-Step Builder Logic
        const stepsContainer = $('#emsf-steps-container');
        const addStepBtn = $('#emsf-add-step');

        // Initialize Sortable for Steps (Reorder Steps)
        if ($.fn.sortable) {
            stepsContainer.sortable({
                handle: '.emsf-step-header',
                placeholder: 'emsf-sortable-placeholder',
                axis: 'y',
                opacity: 0.7,
            });

            // Initialize Sortable for Fields (Nested, Connect Steps)
            function initFieldSortable() {
                $('.emsf-field-list').sortable({
                    handle: '.dashicons-move',
                    connectWith: '.emsf-field-list', // Allow dragging between steps
                    placeholder: 'emsf-sortable-placeholder',
                    axis: 'y',
                    opacity: 0.7,
                    stop: function (event, ui) {
                        // When a field is moved, update the step IDs for all fields in the affected steps
                        const sourceStep = $(event.target).closest('.emsf-step-item');
                        const targetStep = ui.item.closest('.emsf-step-item');

                        updateFieldStepIds(sourceStep);
                        if (sourceStep.data('id') !== targetStep.data('id')) {
                            updateFieldStepIds(targetStep);
                        }
                    }
                });
            }
            initFieldSortable();

            // Helper to update field step IDs when moved
            function updateFieldStepIds(stepItem) {
                const stepId = stepItem.data('id');
                if (!stepId) return;

                stepItem.find('.emsf-field-item').each(function () {
                    const fieldId = $(this).data('id');
                    $(this).find('input, select, textarea').each(function () {
                        const name = $(this).attr('name');
                        if (name && name.indexOf('emsf_structure') !== -1) {
                            // Replace step ID in name: emsf_structure[OLD_STEP][fields][FIELD_ID][PROP]
                            const newName = name.replace(/emsf_structure\[[^\]]+\]/, `emsf_structure[${stepId}]`);
                            $(this).attr('name', newName);
                        }
                    });
                });
            }
        }

        // Add New Step
        addStepBtn.on('click', function () {
            const stepId = 'step_' + Date.now();
            const stepCount = $('.emsf-step-item').length + 1;

            const stepHtml = `
                <div class="emsf-step-item collapsed" data-id="${stepId}">
                    <div class="emsf-step-header">
                        <h3>
                            <span class="dashicons dashicons-arrow-down-alt2 emsf-toggle-step" style="cursor: pointer; margin-right: 5px; color: #646970;" title="Toggle Step"></span>
                            <span class="dashicons dashicons-menu" style="cursor: move; margin-right: 10px; color: #ccc;"></span>
                            Step: <input type="text" name="emsf_structure[${stepId}][title]" value="Step ${stepCount}" class="emsf-step-title-input">
                        </h3>
                        <span class="dashicons dashicons-trash emsf-remove-step" title="Remove Step"></span>
                    </div>
                    <div class="emsf-field-list">
                        <!-- Fields go here -->
                    </div>
                    <div class="emsf-step-actions">
                        <button type="button" class="emsf-add-field-step-btn">
                            <span class="dashicons dashicons-plus-alt2"></span>
                            Add Field to this Step
                        </button>
                    </div>
                </div>
            `;

            stepsContainer.append(stepHtml);
            if ($.fn.sortable) {
                initFieldSortable(); // Re-bind sortable for new list
            }
        });

        // Add New Field (Per-Step Button)
        stepsContainer.on('click', '.emsf-add-field-step-btn', function (e) {
            e.preventDefault();
            const stepItem = $(this).closest('.emsf-step-item');
            const stepId = stepItem.data('id');
            const fieldId = 'field_' + Date.now();
            const fieldHtml = `
                <div class="emsf-field-item" data-id="${fieldId}">
                    <div class="emsf-field-header">
                        <div class="emsf-field-title">
                            <span class="dashicons dashicons-move" style="cursor: move; color: #ccc;"></span>
                            <span class="emsf-field-label-text">New Field</span>
                            <span class="emsf-field-type-badge">text</span>
                        </div>
                        <div class="emsf-field-actions">
                            <span class="dashicons dashicons-trash emsf-remove-field" title="Remove Field"></span>
                        </div>
                    </div>
                    <div class="emsf-field-grid">
                        <div class="emsf-field-input-group">
                            <label>Field Label</label>
                            <input type="text" name="emsf_structure[${stepId}][fields][${fieldId}][label]" value="New Field" class="emsf-field-label-input">
                        </div>
                        <div class="emsf-field-input-group">
                            <label>Field Type</label>
                            <select name="emsf_structure[${stepId}][fields][${fieldId}][type]" class="emsf-field-type-select">
                                <option value="text">Short Text</option>
                                <option value="email">Email</option>
                                <option value="tel">Phone</option>
                                <option value="textarea">Long Text (Textarea)</option>
                                <option value="select">Dropdown (Select)</option>
                                <option value="file">File Upload</option>
                                <option value="date">Date Picker</option>
                            </select>
                        </div>

                        <div class="emsf-field-input-group">
                            <label>Placeholder Text</label>
                            <input type="text" name="emsf_structure[${stepId}][fields][${fieldId}][placeholder]" placeholder="e.g., Enter your name...">
                        </div>
                        
                        <div class="emsf-field-input-group emsf-field-options" style="display:none;">
                            <label>Options (One per line)</label>
                            <textarea name="emsf_structure[${stepId}][fields][${fieldId}][options]" rows="3" style="width:100%;"></textarea>
                        </div>

                        <div class="emsf-field-input-group">
                            <label>Required?</label>
                            <select name="emsf_structure[${stepId}][fields][${fieldId}][required]">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>
                        <div class="emsf-field-input-group">
                            <label>Width</label>
                            <select name="emsf_structure[${stepId}][fields][${fieldId}][width]">
                                <option value="100">100%</option>
                                <option value="50">50%</option>
                                <option value="33">33%</option>
                            </select>
                        </div>

                        <div class="emsf-field-input-group emsf-field-file-settings" style="display:none;">
                            <label>Allowed File Types (comma separated)</label>
                            <input type="text" name="emsf_structure[${stepId}][fields][${fieldId}][allowed_mimes]" value="jpg, jpeg, png, pdf, doc, docx, mp3, mp4" placeholder="e.g. jpg, pdf">
                        </div>

                        <div class="emsf-field-input-group emsf-field-file-settings" style="display:none;">
                            <label>Max File Size (MB)</label>
                            <input type="number" name="emsf_structure[${stepId}][fields][${fieldId}][max_size]" value="5" min="1" max="50">
                        </div>

                        <!-- Conditional Logic Section -->
                        <div class="emsf-field-input-group emsf-conditional-logic-wrap" style="grid-column: span 2; border-top: 1px dashed #e2e8f0; padding-top: 15px; margin-top: 5px;">
                            <label>
                                <input type="checkbox" name="emsf_structure[${stepId}][fields][${fieldId}][conditional_enabled]" value="1" class="emsf-conditional-toggle">
                                <strong>Enable Conditional Logic</strong>
                            </label>
                            <div class="emsf-conditional-settings" style="display:none; margin-top:10px; padding:10px; background:#f8fafc; border-radius:4px; border:1px solid #e2e8f0;">
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <span>Show if</span>
                                    <select name="emsf_structure[${stepId}][fields][${fieldId}][conditional_field]" class="emsf-conditional-field-select">
                                        <option value="">-- Select Field --</option>
                                    </select>
                                    <span>equals</span>
                                    <input type="text" name="emsf_structure[${stepId}][fields][${fieldId}][conditional_value]" placeholder="Value to match" style="flex:1;">
                                </div>
                                <p class="description" style="margin-top:5px; font-size:11px;">The field will be hidden until the selected field matches this value.</p>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            const $newField = $(fieldHtml).hide();
            stepItem.find('.emsf-field-list').append($newField);
            $newField.fadeIn(300);
        });

        // Remove Step
        stepsContainer.on('click', '.emsf-remove-step', function () {
            const stepItem = $(this).closest('.emsf-step-item');
            openModal(
                stepItem,
                'Delete Step?',
                'Are you sure you want to remove this step and all its fields? This cannot be undone.'
            );
        });

        // Remove Field
        stepsContainer.on('click', '.emsf-remove-field', function () {
            const fieldItem = $(this).closest('.emsf-field-item');
            openModal(
                fieldItem,
                'Delete Field?',
                'Are you sure you want to remove this field? This cannot be undone.'
            );
        });

        // Update Label Text dynamically
        stepsContainer.on('input', '.emsf-field-label-input', function () {
            $(this).closest('.emsf-field-item').find('.emsf-field-label-text').text($(this).val() || 'Unnamed Field');
        });

        // Update Type Badge dynamically and toggle options visibility
        stepsContainer.on('change', '.emsf-field-type-select', function () {
            const type = $(this).val();
            const fieldItem = $(this).closest('.emsf-field-item');

            fieldItem.find('.emsf-field-type-badge').text(type);

            if (type === 'select') {
                fieldItem.find('.emsf-field-options').slideDown(200);
                fieldItem.find('.emsf-field-file-settings').slideUp(200);
            } else if (type === 'file') {
                fieldItem.find('.emsf-field-options').slideUp(200);
                fieldItem.find('.emsf-field-file-settings').slideDown(200);
            } else {
                fieldItem.find('.emsf-field-options').slideUp(200);
                fieldItem.find('.emsf-field-file-settings').slideUp(200);
            }
        });

        // Toggle Step Collapse
        stepsContainer.on('click', '.emsf-toggle-step', function (e) {
            e.stopPropagation(); // Prevent sortable drag start if applicable
            $(this).closest('.emsf-step-item').toggleClass('collapsed');
        });

        // Toggle Conditional Logic Visibility
        stepsContainer.on('change', '.emsf-conditional-toggle', function () {
            const settings = $(this).closest('.emsf-conditional-logic-wrap').find('.emsf-conditional-settings');
            if ($(this).is(':checked')) {
                settings.slideDown(200);
                populateFieldChoices($(this).closest('.emsf-field-item'));
            } else {
                settings.slideUp(200);
            }
        });

        // Populate conditional field choices based on PREVIOUS fields
        function populateFieldChoices(currentFieldItem) {
            const select = currentFieldItem.find('.emsf-conditional-field-select');
            const currentValue = select.val();

            select.find('option:not(:first)').remove();

            // Get all fields in the entire builder
            $('.emsf-field-item').each(function () {
                const item = $(this);
                // Stop if we reach the current field (only allow depending on previous fields)
                if (item.is(currentFieldItem)) {
                    return false;
                }

                const id = item.data('id');
                const label = item.find('.emsf-field-label-text').text() || id;
                const type = item.find('.emsf-field-type-badge').text();

                // Only allow text/email/tel/select/date for dependencies
                if (['text', 'email', 'tel', 'select', 'date'].indexOf(type) !== -1) {
                    select.append(`<option value="${id}">${label} (${type})</option>`);
                }
            });

            if (currentValue) {
                select.val(currentValue);
            }
        }

        // Initialize choices for existing conditional fields on tab switch
        $('.nav-tab-wrapper a[href="#builder"]').on('click', function () {
            setTimeout(function () {
                $('.emsf-field-item').each(function () {
                    if ($(this).find('.emsf-conditional-toggle').is(':checked')) {
                        populateFieldChoices($(this));
                    }
                });
            }, 100);
        });

        // Dashboard Widget Chart Initialization
        if (typeof emsf_dashboard_data !== 'undefined' && $('#emsf-submission-chart').length) {
            const chartCanvas = document.getElementById('emsf-submission-chart');
            const ctx = chartCanvas.getContext('2d');

            // Fix: Check for existing chart instance to prevent "Canvas is already in use" error
            // This can happen if the script is loaded multiple times or if content is dynamically reloaded
            if (Chart.getChart(chartCanvas)) {
                Chart.getChart(chartCanvas).destroy();
            }

            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: emsf_dashboard_data.labels,
                    datasets: [{
                        label: 'Submissions',
                        data: emsf_dashboard_data.data,
                        backgroundColor: 'rgba(34, 113, 177, 0.7)', // WordPress Blue
                        borderColor: 'rgba(34, 113, 177, 1)',
                        borderWidth: 1,
                        borderRadius: 4,
                        barPercentage: 0.6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            },
                            grid: {
                                color: '#f0f0f1'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }

        // SMTP Test Email Button
        $('#emsf-send-test-email').on('click', function () {
            const $btn = $(this);
            const $result = $('#emsf-test-email-result');

            $btn.prop('disabled', true).text('Sending...');
            $result.text('').css('color', '');

            $.post(emsf_admin_ajax.ajaxUrl, {
                action: 'emsf_send_test_email',
                nonce: emsf_admin_ajax.nonce
            }, function (response) {
                if (response.success) {
                    $result.text('✓ ' + response.data.message).css('color', '#16a34a');
                } else {
                    $result.text('✗ ' + response.data.message).css('color', '#dc2626');
                }
            }).fail(function () {
                $result.text('✗ Request failed. Please try again.').css('color', '#dc2626');
            }).always(function () {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-email" style="margin-top:4px; margin-right:4px;"></span> Send Test Email Now');
            });
        });

    });

})(jQuery);
