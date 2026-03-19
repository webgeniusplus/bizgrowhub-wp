/**
 * Incomplete Checkout Capture
 * Runs on WooCommerce checkout page.
 * Captures billing fields after user pauses typing (2s debounce) → AJAX → API.
 */
(function () {
  'use strict';

  if (typeof jQuery === 'undefined') return;

  jQuery(document).ready(function ($) {
    if (!$('.woocommerce-checkout').length) return;

    var DEBOUNCE_MS = 2000;
    var timer = null;
    var lastHash = '';

    // Session ID (passed by PHP via wp_localize_script, or generated client-side)
    var sessionId = (typeof ihCheckout !== 'undefined' && ihCheckout.sessionId)
      ? ihCheckout.sessionId
      : 'sess_' + Date.now().toString(36) + Math.random().toString(36).slice(2);

    // Persist session cookie for 30 min
    document.cookie = 'ih_checkout_session=' + sessionId + ';path=/;max-age=1800';

    /** Collect all billing_* fields from the checkout form */
    function collectFields() {
      var data = {
        action:     'ih_capture_checkout',
        nonce:      (typeof ihCheckout !== 'undefined') ? ihCheckout.nonce : '',
        session_id: sessionId,
      };

      $('.woocommerce-checkout').find('input, select, textarea').each(function () {
        var name = $(this).attr('name');
        var type = $(this).attr('type');
        if (!name || name.indexOf('billing_') !== 0) return;

        if (type === 'checkbox' || type === 'radio') {
          if ($(this).is(':checked')) data[name] = $(this).val() || 'yes';
        } else {
          data[name] = $(this).val() || '';
        }
      });

      return data;
    }

    /** Lightweight hash to avoid resending unchanged data */
    function hashData(data) {
      return ['billing_first_name', 'billing_last_name', 'billing_phone',
              'billing_email', 'billing_address_1', 'billing_city']
        .map(function (k) { return data[k] || ''; })
        .join('|');
    }

    /** Require at least phone (10 digits) or a valid email */
    function hasMinData(data) {
      var phone = (data.billing_phone || '').replace(/\D/g, '');
      var email = data.billing_email || '';
      return phone.length >= 10 || email.indexOf('@') > 0;
    }

    function doCapture() {
      var data = collectFields();
      if (!hasMinData(data)) return;
      var hash = hashData(data);
      if (hash === lastHash) return;
      lastHash = hash;

      $.ajax({
        url:  (typeof ihCheckout !== 'undefined') ? ihCheckout.ajaxUrl : '/wp-admin/admin-ajax.php',
        type: 'POST',
        data: data,
      });
    }

    function scheduleCapture() {
      clearTimeout(timer);
      timer = setTimeout(doCapture, DEBOUNCE_MS);
    }

    // Capture on any billing field interaction
    $('.woocommerce-checkout').on('change input blur', 'input, select, textarea', scheduleCapture);

    // Last-chance capture on page close / navigation away
    $(window).on('beforeunload', function () {
      var data = collectFields();
      if (!hasMinData(data) || hashData(data) === lastHash) return;

      if (navigator.sendBeacon) {
        var fd = new FormData();
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        navigator.sendBeacon(
          (typeof ihCheckout !== 'undefined') ? ihCheckout.ajaxUrl : '/wp-admin/admin-ajax.php',
          fd
        );
      }
    });
  });
})();
