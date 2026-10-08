(function ($) {
  'use strict';

  function escapeHtml(value) {
    return $('<div>').text(value == null ? '' : value).html();
  }

  function parseNum(value) {
    if (value == null) { return 0; }
    return parseFloat(String(value).replace(/\s/g, '').replace(',', '.')) || 0;
  }

  function fmt(value) {
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
    function renderBadges(line) {
      var html = '';
      if (line.has_group_rule) {
        html += '<span class="tarif-badge tarif-badge-group">Remise groupe</span>';
      }
      if (line.has_customer_rule) {
        html += '<span class="tarif-badge tarif-badge-customer">Remise client</span>';
      }
      return html;
    }

    function renderLineRow(line) {
      var img = line.image_url
        ? '<img src="' + escapeHtml(line.image_url) + '" alt="" class="tarif-thumb">'
        : '<span class="tarif-thumb-empty"></span>';

      var attrs = line.attribute_names
        ? '<span class="tarif-attrs">' + escapeHtml(line.attribute_names) + '</span>'
        : '';

      var meta = [];
      if (line.reference) { meta.push('Réf : ' + escapeHtml(line.reference)); }
      if (line.ean13) { meta.push('EAN : ' + escapeHtml(line.ean13)); }

      return '<div class="tarif-line" data-id-line="' + parseInt(line.id_line, 10)
        + '" data-base-price="' + (parseFloat(line.base_price) || 0) + '">'
        + '<span class="tarif-cell tarif-col-drag"><span class="tarif-drag-line"><i class="icon-arrows"></i></span></span>'
        + '<span class="tarif-cell tarif-col-img">' + img + '</span>'
        + '<span class="tarif-cell tarif-col-info"><strong>' + escapeHtml(line.name) + '</strong>' + attrs
        + '<span class="tarif-meta">' + meta.join(' &middot; ') + '</span>'
        + '<span class="tarif-badges">' + renderBadges(line) + '</span></span>'
        + '<span class="tarif-cell tarif-col-num tarif-catalog">' + fmt(line.base_price) + ' &euro;</span>'
        + '<span class="tarif-cell tarif-col-num tarif-group">' + fmt(line.group_reduction_percent) + ' %</span>'
        + '<span class="tarif-cell tarif-col-num"><input type="text" class="form-control tarif-reduction" value="'
        + fmt(line.reduction_percent) + '"></span>'
        + '<span class="tarif-cell tarif-col-num"><div class="input-group tarif-final-group">'
        + '<input type="text" class="form-control tarif-final" value="' + fmt(line.final_price) + '">'
        + '<span class="input-group-addon">&euro;</span></div></span>'
        + '<span class="tarif-cell tarif-col-actions"><button type="button" class="btn btn-xs btn-danger tarif-delete-line">'
        + '<i class="icon-trash"></i></button></span>'
        + '</div>';
    }

    function renderSectionBlock(section) {
      var id = parseInt(section.id_section, 10);
      return '<div class="tarif-section" data-id-section="' + id + '">'
        + '<div class="tarif-section-header">'
        + '<span class="tarif-drag-section"><i class="icon-arrows"></i></span>'
        + '<input type="text" class="form-control tarif-section-title" value="' + escapeHtml(section.title) + '" placeholder="Titre de la section">'
        + '<button type="button" class="btn btn-xs btn-danger tarif-delete-section"><i class="icon-trash"></i></button>'
        + '</div>'
        + '<div class="tarif-lines-head">'
        + '<span class="tarif-cell tarif-col-drag"></span><span class="tarif-cell tarif-col-img">Image</span>'
        + '<span class="tarif-cell tarif-col-info">Produit</span>'
        + '<span class="tarif-cell tarif-col-num">Prix catalogue</span>'
        + '<span class="tarif-cell tarif-col-num">Remise groupe</span>'
        + '<span class="tarif-cell tarif-col-num">Réduction client %</span>'
        + '<span class="tarif-cell tarif-col-num">Prix final HT</span>'
        + '<span class="tarif-cell tarif-col-actions"></span>'
        + '</div>'
        + '<div class="tarif-lines" data-id-section="' + id + '"></div>'
        + '</div>';
    }

    // ── Sortables ────────────────────────────────────────────────────────────
    function saveOrder() {
      var sections = [];
      $('#tarif-sections .tarif-lines').each(function () {
        var idSection = parseInt($(this).data('id-section'), 10);
        var lines = $(this).find('> .tarif-line').map(function () {
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
        items: '> .tarif-line',
        handle: '.tarif-drag-line',
        placeholder: 'tarif-line-placeholder',
        forcePlaceholderSize: true,
        tolerance: 'pointer',
        stop: saveOrder
      });
      if ($('#tarif-sections').data('uiSortable')) {
        $('#tarif-sections').sortable('destroy');
      }
      $('#tarif-sections').sortable({
        items: '> .tarif-section:not(.tarif-section-none)',
        handle: '.tarif-drag-section',
        tolerance: 'pointer',
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
        $('.tarif-lines[data-id-section="' + idSection + '"]').append(renderLineRow(resp.line));
        $productSearch.select2('val', '');
        initSortables();
      });
    });

    // ── Double saisie : réduction % ⇄ prix final (live + sauvegarde) ─────────
    function getBase($row) {
      return parseFloat($row.attr('data-base-price')) || 0;
    }

    // Saisie réduction -> met à jour le prix final en direct
    $('#tarif-sections').on('input', '.tarif-reduction', function () {
      var $row = $(this).closest('.tarif-line');
      var base = getBase($row);
      var pct = parseNum($(this).val());
      $row.find('.tarif-final').val((base * (1 - pct / 100)).toFixed(2));
    });

    // Saisie prix final -> met à jour la réduction en direct
    $('#tarif-sections').on('input', '.tarif-final', function () {
      var $row = $(this).closest('.tarif-line');
      var base = getBase($row);
      var price = parseNum($(this).val());
      var pct = base > 0 ? ((base - price) / base) * 100 : 0;
      $row.find('.tarif-reduction').val(pct.toFixed(2));
    });

    // Validation (blur) -> sauvegarde côté serveur
    $('#tarif-sections').on('change', '.tarif-reduction', function () {
      var $row = $(this).closest('.tarif-line');
      saveLine($row, 'reduction', parseNum($(this).val()));
    });
    $('#tarif-sections').on('change', '.tarif-final', function () {
      var $row = $(this).closest('.tarif-line');
      saveLine($row, 'price', parseNum($(this).val()));
    });

    function saveLine($row, mode, value) {
      var idLine = parseInt($row.data('id-line'), 10);
      api('UpdateLine', { id_line: idLine, mode: mode, value: value }, function (resp) {
        $row.find('.tarif-reduction').val(fmt(resp.line.reduction_percent));
        $row.find('.tarif-final').val(fmt(resp.line.final_price));
      });
    }

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
        var $lines = $section.find('.tarif-lines > .tarif-line');
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

    // ── Synchroniser (conserver les prix finaux) ─────────────────────────────
    $('#tarif-sync-prices').on('click', function () {
      if (!window.confirm('Mettre à jour les prix catalogue et remises de groupe en conservant les prix finaux ?\n\nLa réduction client sera recalculée et, si le tarif est validé, le prix spécifique du client sera adapté pour préserver le prix final.')) {
        return;
      }
      api('SyncPrices', {}, function () {
        window.location.reload();
      });
    });

    // ── Confirmation de la validation ────────────────────────────────────────
    $('#tarif-validate').on('click', function (e) {
      if (!window.confirm('Valider ce tarif et verrouiller les prix spécifiques du client ?\n\nLes prix spécifiques existants propres au client seront écrasés. Les remises de groupe ne sont pas modifiées.')) {
        e.preventDefault();
      }
    });
  });
})(jQuery);
