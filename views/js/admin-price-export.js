(function ($) {
  'use strict';

  $(function () {
    var $customer = $('.js-price-export-customer');
    var $purchasePrice = $('#include_purchase_price');
    var $supplier = $('#id_supplier');

    if ($customer.length && $.fn.select2) {
      $customer.select2({
        width: '100%',
        allowClear: true,
        placeholder: 'Rechercher un client',
        initSelection: function (element, callback) {
          var id = element.val();
          var text = element.data('selected-text');

          if (id && text) {
            callback({id: id, text: text});
          }
        },
        ajax: {
          url: $customer.data('search-url'),
          dataType: 'json',
          quietMillis: 250,
          data: function (params) {
            return {term: params};
          },
          results: function (data) {
            return {results: data.results || []};
          }
        }
      });
    }

    function updateSupplierState() {
      $supplier.prop('disabled', !$purchasePrice.is(':checked'));
    }

    $purchasePrice.on('change', updateSupplierState);
    updateSupplierState();
  });
})(jQuery);
