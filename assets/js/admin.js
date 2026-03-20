/**
 * BizGrowHub Admin JavaScript
 * Handles tab switching and AJAX license management operations
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        initTabs();
        initLicenseManagement();
    });

    function initTabs() {
        $('.mp-tab').on('click', function() {
            var target = $(this).data('tab');
            $('.mp-tab').removeClass('active');
            $(this).addClass('active');
            $('.mp-tab-content').removeClass('active');
            $('#tab-' + target).addClass('active');
            // Update URL hash
            if (history.replaceState) {
                history.replaceState(null, null, '#' + target);
            }
        });
    }

    function initLicenseManagement() {
        var $licenseKey = $('#license_key');
        var $checkButton = $('#check-license-btn');
        var $activateButton = $('#activate-license-btn');
        var $deactivateButton = $('#deactivate-license-btn');

        // Check license
        $checkButton.on('click', function(e) {
            e.preventDefault();
            var key = $licenseKey.val().trim();
            if (!key) {
                showStatusMessage('License key is required.', 'error');
                return;
            }
            validateLicense(key);
        });

        // Activate license
        $activateButton.on('click', function(e) {
            e.preventDefault();
            var key = $licenseKey.val().trim();
            if (!key) {
                showStatusMessage('License key is required.', 'error');
                return;
            }
            activateLicense(key);
        });

        // Deactivate license
        $deactivateButton.on('click', function(e) {
            e.preventDefault();
            deactivateLicense();
        });

        updateButtonVisibility();
    }

    function validateLicense(licenseKey) {
        setButtonState('check', true);
        clearStatusMessage();

        $.ajax({
            url: insightHubAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'insight_hub_validate_license',
                license_key: licenseKey,
                nonce: insightHubAjax.nonce
            },
            success: function(response) {
                setButtonState('check', false);
                if (response.success) {
                    showStatusMessage(response.data.message, 'success');
                } else {
                    showStatusMessage(response.data.message || 'Validation failed.', 'error');
                }
            },
            error: function() {
                setButtonState('check', false);
                showStatusMessage('Connection error. Please try again.', 'error');
            }
        });
    }

    function activateLicense(licenseKey) {
        setButtonState('activate', true);
        clearStatusMessage();

        $.ajax({
            url: insightHubAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'insight_hub_activate_license',
                license_key: licenseKey,
                nonce: insightHubAjax.nonce
            },
            success: function(response) {
                setButtonState('activate', false);
                if (response.success) {
                    showStatusMessage(response.data.message, 'success');
                    updateLicenseStatus('active', response.data.data);
                    updateButtonVisibility();
                    // Reload page after 1.5s to show updated info
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    showStatusMessage(response.data.message || 'Activation failed.', 'error');
                }
            },
            error: function() {
                setButtonState('activate', false);
                showStatusMessage('Connection error. Could not reach the API server.', 'error');
            }
        });
    }

    function deactivateLicense() {
        setButtonState('deactivate', true);
        clearStatusMessage();

        $.ajax({
            url: insightHubAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'insight_hub_deactivate_license',
                nonce: insightHubAjax.nonce
            },
            success: function(response) {
                setButtonState('deactivate', false);
                if (response.success) {
                    showStatusMessage(response.data.message, 'success');
                    updateLicenseStatus('inactive');
                    updateButtonVisibility();
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    showStatusMessage(response.data.message || 'Deactivation failed.', 'error');
                }
            },
            error: function() {
                setButtonState('deactivate', false);
                showStatusMessage('Connection error. Please try again.', 'error');
            }
        });
    }

    function setButtonState(action, loading) {
        var btn;
        var loadingText;
        switch(action) {
            case 'check':
                btn = $('#check-license-btn');
                loadingText = 'Checking...';
                break;
            case 'activate':
                btn = $('#activate-license-btn');
                loadingText = 'Activating...';
                break;
            case 'deactivate':
                btn = $('#deactivate-license-btn');
                loadingText = 'Deactivating...';
                break;
            default: return;
        }

        if (loading) {
            btn.data('orig-text', btn.text());
            btn.text(loadingText).prop('disabled', true);
        } else {
            btn.text(btn.data('orig-text') || btn.text()).prop('disabled', false);
        }
    }

    function showStatusMessage(message, type) {
        $('#license-status-message')
            .removeClass('mp-alert-success mp-alert-error')
            .addClass('mp-alert-' + type)
            .html(message)
            .show();
    }

    function clearStatusMessage() {
        $('#license-status-message').hide().html('');
    }

    function updateLicenseStatus(status, data) {
        var $badge = $('.mp-badge');
        $badge.removeClass('mp-badge-active mp-badge-inactive mp-badge-invalid')
              .addClass('mp-badge-' + status)
              .text(status.charAt(0).toUpperCase() + status.slice(1));
    }

    function updateButtonVisibility() {
        // Don't override server-rendered visibility on initial load.
        // Only toggle after AJAX actions change the status.
    }

})(jQuery);
