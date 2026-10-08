(function ($) {
  'use strict';

  function escapeHtml(value) {
    return $('<div>').text(value == null ? '' : value).html();
  }

  function formatPrice(value) {
    return (parseFloat(value) || 0).toFixed(2);
  }

  // ── Select2 client (formulaire d'en-tête + duplication) ────────────────────
  function initCustomerSelect($el, placeholder) {
    if (!$el.length || !$.fn.select2) {
      return;
    }
    $el.select2({
      width: '100%',
      allowClear: true,
      placeholder: placeholder,
      initSelection: function (element, callback) {
        var id = element.val();
        var text = element.data('selected-text');
        if (id && text) {
          callback({ id: id, text: text });
        }
      },
      ajax: {
        url: $el.data('search-url'),
        dataType: 'json',
        quietMillis: 250,
        data: function (params) {
          return { term: params };
        },
        results: function (data) {
          return { results: data.results || [] };
        }
      }
    });
  }

  $(function () {
    initCustomerSelect($('.js-tarif-customer'), 'Rechercher un client');
    initCustomerSelect($('#tarif-duplicate-customer'), 'Rechercher un client');

    var $editor = $('#tarif-editor');
    if (!$editor.length) {
      return;
    }

    var ajaxUrl = $editor.data('ajax-url');
    var idTarif = $editor.data('id-tarif');

    function api(action, data, done) {
      data = data || {};
      data.ajax = 1;
      data.action = action;
      data.id_tarif = idTarif;
      $.post(ajaxUrl, data, function (resp) {
        if (resp && resp.success === false) {
          window.alert(resp.error || 'Erreur');
          return;
        }
        if (done) {
          done(resp);
        }
      }, 'json').fail(function () {
        window.alert('Erreur réseau.');
      });
    }

    // ── Recherche produit ────────────────────────────────────────────────────
    var $productSearch = $('#tarif-product-search');
    if ($productSearch.length && $.fn.select2) {
      $productSearch.select2({
        width: '100%',
        placeholder: 'Rechercher un produit (nom, référence, EAN)',
        minimumInputLength: 2,
        ajax: {
          url: $productSearch.data('search-url'),
          dataType: 'json',
          quietMillis: 250,
          data: function (params) {
            return { term: params };
          },
          results: function (data) {
            return { results: data.results || [] };
          }
        }
      });
    }

    // ── Construction d'une ligne ─────────────────────────────────────────────
    function renderLineRow(line) {
      var img = line.image_url
        ? '<img src="' + escapeHtml(line.image_url) + '" alt="" class="tarif-thumb">'
        : '<span class="tarif-thumb-empty"></span>';

      var attrs = line.attribute_names
        ? '<div class="tarif-attrs">' + escapeHtml(line.attribute_names) + '</div>'
        : '';

      var meta = [];
      if (line.reference) { meta.push('Réf : ' + escapeHtml(line.reference)); }
      if (line.ean13) { meta.push('EAN : ' + escapeHtml(line.ean13)); }

      return '<tr class="tarif-line" data-id-line="' + parseInt(line.id_line, 10) + '">'
        + '<td class="tarif-col-drag"><span class="tarif-drag-line"><i class="icon-arrows"></i></span></td>'
        + '<td class="tarif-col-img">' + img + '</td>'
        + '<td><strong>' + escapeHtml(line.name) + '</strong>' + attrs
        + '<div class="tarif-meta">' + meta.join(' &middot; ') + '</div></td>'
        + '<td class="tarif-col-price tarif-current">' + formatPrice(line.current_price) + ' &euro;</td>'
        + '<td class="tarif-col-reduction"><input type="text" class="form-control tarif-reduction" value="'
        + formatPrice(line.reduction_percent) + '"></td>'
        + '<td class="tarif-col-price tarif-final"><strong>' + formatPrice(line.final_price) + ' &euro;</strong></td>'
        + '<td class="tarif-col-actions"><button type="button" class="btn btn-xs btn-danger tarif-delete-line">'
        + '<i class="icon-trash"></i></button></td>'
        + '</tr>';
    }

    function renderSectionBlock(section) {
      return '<div class="tarif-section" data-id-section="' + parseInt(section.id_section, 10) + '">'
        + '<div class="tarif-section-header">'
        + '<span class="tarif-drag-section"><i class="icon-arrows"></i></span>'
        + '<input type="text" class="form-control tarif-section-title" value="' + escapeHtml(section.title) + '" placeholder="Titre de la section">'
        + '<button type="button" class="btn btn-xs btn-danger tarif-delete-section"><i class="icon-trash"></i></button>'
        + '</div>'
        + '<table class="table tarif-lines-table"><thead><tr>'
        + '<th class="tarif-col-drag"></th><th class="tarif-col-img">Image</th><th>Produit</th>'
        + '<th class="tarif-col-price">Prix actuel HT</th><th class="tarif-col-reduction">Réduction %</th>'
        + '<th class="tarif-col-price">Prix final HT</th><th class="tarif-col-actions"></th>'
        + '</tr></thead>'
        + '<tbody class="tarif-lines" data-id-section="' + parseInt(section.id_section, 10) + '"></tbody>'
        + '</table></div>';
    }

    // ── Sortables ────────────────────────────────────────────────────────────
    function saveOrder() {
      var sections = [];
      $('#tarif-sections .tarif-lines').each(function () {
        var idSection = parseInt($(this).data('id-section'), 10);
        var lines = $(this).find('> tr.tarif-line').map(function () {
          return parseInt($(this).data('id-line'), 10);
        }).get();
        sections.push({ id_section: idSection, lines: lines });
      });

      var order = $('#tarif-sections > .tarif-section').map(function () {
        return parseInt($(this).data('id-section'), 10);
      }).get().filter(function (id) {
        return id > 0;
      });

      api('Reorder', {
        sections: JSON.stringify(sections),
        section_order: JSON.stringify(order)
      });
    }

    function initSortables() {
      $('#tarif-sections .tarif-lines').sortable({
        connectWith: '.tarif-lines',
        items: '> tr.tarif-line',
        handle: '.tarif-drag-line',
        placeholder: 'tarif-line-placeholder',
        stop: saveOrder
      });
      if ($('#tarif-sections').data('uiSortable')) {
        $('#tarif-sections').sortable('destroy');
      }
      $('#tarif-sections').sortable({
        items: '> .tarif-section:not(.tarif-section-none)',
        handle: '.tarif-drag-section',
        stop: saveOrder
      });
    }

    initSortables();

    function refreshTargetSections() {
      var $select = $('#tarif-target-section');
      var current = $select.val();
      $select.find('option').not('[value="0"]').remove();
      $('#tarif-sections > .tarif-section').each(function () {
        var id = parseInt($(this).data('id-section'), 10);
        if (id > 0) {
          var title = $(this).find('.tarif-section-title').val() || ('Section ' + id);
          $select.append($('<option>').val(id).text(title));
        }
      });
      $select.val(current);
    }

    // ── Ajout de ligne ───────────────────────────────────────────────────────
    $('#tarif-add-line').on('click', function () {
      var product = $productSearch.val();
      if (!product) {
        window.alert('Sélectionnez un produit.');
        return;
      }
      var idSection = parseInt($('#tarif-target-section').val(), 10) || 0;

      api('AddLine', { product: product, id_section: idSection }, function (resp) {
        var $tbody = $('.tarif-lines[data-id-section="' + idSection + '"]');
        $tbody.append(renderLineRow(resp.line));
        $productSearch.select2('val', '');
        initSortables();
      });
    });

    // ── Modification de la réduction ─────────────────────────────────────────
    $('#tarif-sections').on('change', '.tarif-reduction', function () {
      var $input = $(this);
      var $row = $input.closest('.tarif-line');
      var idLine = parseInt($row.data('id-line'), 10);

      api('UpdateLine', { id_line: idLine, reduction_percent: $input.val() }, function (resp) {
        $input.val(formatPrice(resp.line.reduction_percent));
        $row.find('.tarif-final').html('<strong>' + formatPrice(resp.line.final_price) + ' &euro;</strong>');
      });
    });

    // ── Suppression de ligne ─────────────────────────────────────────────────
    $('#tarif-sections').on('click', '.tarif-delete-line', function () {
      var $row = $(this).closest('.tarif-line');
      var idLine = parseInt($row.data('id-line'), 10);
      api('DeleteLine', { id_line: idLine }, function () {
        $row.remove();
      });
    });

    // ── Ajout de section ─────────────────────────────────────────────────────
    $('#tarif-add-section').on('click', function () {
      api('AddSection', { title: '' }, function (resp) {
        $('.tarif-section-none').before(renderSectionBlock(resp.section));
        refreshTargetSections();
        initSortables();
      });
    });

    // ── Modification du titre de section ─────────────────────────────────────
    $('#tarif-sections').on('change', '.tarif-section-title', function () {
      var $input = $(this);
      var idSection = parseInt($input.closest('.tarif-section').data('id-section'), 10);
      api('UpdateSection', { id_section: idSection, title: $input.val() }, function () {
        refreshTargetSections();
      });
    });

    // ── Suppression de section ───────────────────────────────────────────────
    $('#tarif-sections').on('click', '.tarif-delete-section', function () {
      if (!window.confirm('Supprimer cette section ? Ses produits reviendront « sans section ».')) {
        return;
      }
      var $section = $(this).closest('.tarif-section');
      var idSection = parseInt($section.data('id-section'), 10);

      api('DeleteSection', { id_section: idSection }, function () {
        var $lines = $section.find('> .tarif-lines-table .tarif-lines > tr.tarif-line');
        $('.tarif-section-none .tarif-lines').append($lines);
        $section.remove();
        refreshTargetSections();
        initSortables();
        saveOrder();
      });
    });

    // ── Rafraîchir les prix ──────────────────────────────────────────────────
    $('#tarif-refresh-prices').on('click', function () {
      api('RefreshPrices', {}, function () {
        window.location.reload();
      });
    });
  });
})(jQuery);
