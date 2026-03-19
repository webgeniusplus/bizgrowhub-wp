/**
 * MarketPulse Guard Popup
 * Uses pre-rendered HTML from dashboard with mpg- prefixed classes.
 * AJAX pre-validation + woocommerce_checkout_process fallback.
 *
 * Placeholders in HTML template:
 *   {{TITLE}}, {{MESSAGE}}, {{ICON}}, {{BTN1_LABEL}}, {{BTN2_LABEL}}
 */
(function ($) {
    'use strict';

    if (typeof wooGuardParams === 'undefined') return;

    var $form = null;
    var activeOverlay = null;

    /* Default icon SVGs by reason type */
    var ICONS = {
        blacklist: '<svg viewBox="0 0 64 64" fill="none"><circle cx="32" cy="32" r="26" stroke="currentColor" stroke-width="3" fill="none" opacity=".15"/><circle cx="32" cy="32" r="26" stroke="currentColor" stroke-width="3" fill="none"/><line x1="14" y1="14" x2="50" y2="50" stroke="currentColor" stroke-width="4" stroke-linecap="round"/></svg>',
        rate_limit: '<svg viewBox="0 0 64 64" fill="none"><circle cx="32" cy="32" r="26" stroke="currentColor" stroke-width="3" fill="none" opacity=".15"/><circle cx="32" cy="32" r="26" stroke="currentColor" stroke-width="3" fill="none"/><polyline points="32,18 32,34 42,38" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>',
        duplicate: '<svg viewBox="0 0 64 64" fill="none"><rect x="18" y="8" width="28" height="10" rx="3" stroke="currentColor" stroke-width="3" fill="none" opacity=".15"/><rect x="18" y="8" width="28" height="10" rx="3" stroke="currentColor" stroke-width="3" fill="none"/><path d="M46 14h4a4 4 0 014 4v32a4 4 0 01-4 4H14a4 4 0 01-4-4V18a4 4 0 014-4h4" stroke="currentColor" stroke-width="3" fill="none"/></svg>',
        default: '<svg viewBox="0 0 64 64" fill="none"><path d="M32 4L8 16v16c0 14.4 10.2 27.8 24 32 13.8-4.2 24-17.6 24-32V16L32 4z" fill="currentColor" opacity=".1"/><path d="M32 4L8 16v16c0 14.4 10.2 27.8 24 32 13.8-4.2 24-17.6 24-32V16L32 4z" stroke="currentColor" stroke-width="2.5" fill="none"/><line x1="24" y1="24" x2="40" y2="40" stroke="currentColor" stroke-width="3.5" stroke-linecap="round"/><line x1="40" y1="24" x2="24" y2="40" stroke="currentColor" stroke-width="3.5" stroke-linecap="round"/></svg>'
    };

    $(function () {
        $form = $('form.checkout');
        if (!$form.length) return;
        $form.on('checkout_place_order', onPlaceOrder);
    });

    function onPlaceOrder() {
        if ($form.data('mpg-passed')) {
            $form.data('mpg-passed', false);
            return true;
        }

        $.post(wooGuardParams.ajaxUrl, {
            action: 'woo_guard_validate',
            nonce: wooGuardParams.nonce,
            billing_email: $('#billing_email').val() || '',
            billing_phone: $('#billing_phone').val() || ''
        })
        .done(function (res) {
            if (res.success) {
                $form.data('mpg-passed', true);
                $form.trigger('submit');
            } else {
                showPopup(res.data);
            }
        })
        .fail(function () {
            // Fail-open: let order through
            $form.data('mpg-passed', true);
            $form.trigger('submit');
        });

        return false;
    }

    function escHtml(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    /**
     * Show the popup with dynamic content from server response.
     *
     * data fields:
     *   .title        — popup title
     *   .message      — popup body text
     *   .reason_type  — 'rate_limit' | 'blacklist' | 'duplicate'
     *   .target       — 'email' | 'phone' | 'ip'
     *   .btn1_label   — ghost button label (optional, default from template)
     *   .btn1_action  — 'close' | 'url:...' | 'scroll' (default: close)
     *   .btn2_label   — solid button label (optional, default from template)
     *   .btn2_action  — 'retry' | 'close' | 'url:...' (default: retry)
     */
    function showPopup(data) {
        closePopup(true);

        var html = wooGuardParams.popupHtml || '';
        if (!html) { alert(data.message || 'Order blocked.'); return; }

        var reasonType = data.reason_type || 'default';
        var title = data.title || 'Order Could Not Be Placed';
        var message = data.message || 'Your order was blocked for security reasons.';
        var iconSvg = ICONS[reasonType] || ICONS['default'];

        // Button labels — optional, from server response
        var btn1Label = data.btn1_label || '';
        var btn2Label = data.btn2_label || '';
        var btn1Action = data.btn1_action || 'close';
        var btn2Action = data.btn2_action || 'close';

        // Build buttons HTML — only include buttons with labels
        var btns = [];
        if (btn1Label) btns.push('<button type="button" class="mpg-btn mpg-btn-ghost">' + escHtml(btn1Label) + '</button>');
        if (btn2Label) btns.push('<button type="button" class="mpg-btn mpg-btn-solid">' + escHtml(btn2Label) + '</button>');
        var buttonsHtml = btns.length ? '<div class="mpg-divider"></div><div class="mpg-buttons">' + btns.join('') + '</div>' : '';

        // Replace all placeholders
        html = html.replace('{{TITLE}}', escHtml(title));
        html = html.replace('{{MESSAGE}}', message); // HTML supported — render as-is
        html = html.replace('{{ICON}}', iconSvg);
        html = html.replace('{{BUTTONS}}', buttonsHtml);

        var $el = $(html);

        $('body').append($el);
        activeOverlay = $el;
        $el.find('.mpg-card').trigger('focus');

        // Dynamic button actions
        if (btn1Label) {
            $el.find('.mpg-btn-ghost').on('click', function () { handleAction(btn1Action); });
        }
        if (btn2Label) {
            $el.find('.mpg-btn-solid').on('click', function () { handleAction(btn2Action); });
        }

        // Close on overlay click
        $el.on('click', function (e) {
            if ($(e.target).hasClass('mpg-root') || $(e.target).hasClass('mpg-wrap')) closePopup();
        });

        // ESC to close
        $(document).on('keydown.mpg', function (e) {
            if (e.key === 'Escape' || e.keyCode === 27) closePopup();
        });

        $('body').css('overflow', 'hidden');
    }

    function handleAction(action) {
        if (!action || action === 'close') {
            closePopup();
        } else if (action === 'retry' || action === 'scroll') {
            closePopup();
            if ($form && $form.length) {
                $('html, body').animate({ scrollTop: $form.offset().top - 50 }, 400);
            }
        } else if (action.indexOf('url:') === 0) {
            closePopup();
            window.location.href = action.substring(4);
        }
    }

    function closePopup(immediate) {
        if (!activeOverlay) return;
        var $el = activeOverlay;
        activeOverlay = null;
        $(document).off('keydown.mpg');
        $('body').css('overflow', '');
        if (immediate) { $el.remove(); return; }
        $el.addClass('mpg-closing');
        setTimeout(function () { $el.remove(); }, 220);
    }

})(jQuery);
