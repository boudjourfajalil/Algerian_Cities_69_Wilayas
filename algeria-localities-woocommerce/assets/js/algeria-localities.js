/* global jQuery, AlgeriaLocalities */
(function ($) {
    'use strict';

    /**
     * Update commune (city) dropdown when a wilaya (state) changes.
     *
     * @param {jQuery} $wilayaField
     * @param {jQuery} $communeField
     */
    function updateCommunes($wilayaField, $communeField) {
        var wilayaVal = $wilayaField.val();

        if (!wilayaVal) {
            // Reset communes dropdown.
            $communeField.empty().append(
                $('<option/>', {
                    value: '',
                    text: AlgeriaLocalities.placeholder || ''
                })
            );
            $communeField.trigger('change');
            return;
        }

        $.ajax({
            url: AlgeriaLocalities.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'load_algeria_communes',
                security: AlgeriaLocalities.nonce,
                wilaya_id: wilayaVal
            }
        }).done(function (response) {
            if (!response || !response.success || !response.data || !response.data.options) {
                window.console && console.warn('AlgeriaLocalities: unexpected response', response);
                return;
            }

            $communeField.empty();
            $communeField.append(
                $('<option/>', {
                    value: '',
                    text: AlgeriaLocalities.placeholder || ''
                })
            );

            $.each(response.data.options, function (idx, item) {
                $communeField.append(
                    $('<option/>', {
                        value: item.value,
                        text: item.value
                    })
                );
            });

            $communeField.trigger('change');
        }).fail(function () {
            window.alert(AlgeriaLocalities.error || 'Error');
        });
    }

    $(document).ready(function () {
        // Apply RTL styling if Arabic labels are enabled.
        if (AlgeriaLocalities.arabic_rtl) {
            $('body').addClass('algeria-localities-rtl');
        }

        // Billing fields.
        var $billingWilaya = $('#billing_state');
        var $billingCommune = $('#billing_city');

        if ($billingWilaya.length && $billingCommune.length) {
            $billingWilaya.on('change.algeriaLocalities', function () {
                updateCommunes($billingWilaya, $billingCommune);
            });

            // If a value is already selected (e.g., returning to checkout), try to reload communes.
            if ($billingWilaya.val()) {
                updateCommunes($billingWilaya, $billingCommune);
            }
        }

        // Shipping fields.
        var $shippingWilaya = $('#shipping_state');
        var $shippingCommune = $('#shipping_city');

        if ($shippingWilaya.length && $shippingCommune.length) {
            $shippingWilaya.on('change.algeriaLocalities', function () {
                updateCommunes($shippingWilaya, $shippingCommune);
            });

            if ($shippingWilaya.val()) {
                updateCommunes($shippingWilaya, $shippingCommune);
            }
        }
    });
})(jQuery);

