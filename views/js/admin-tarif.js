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

  function fmtOptional(value) {
    return value == null || value === '' ? '' : (parseFloat(value) || 0).toFixed(2);
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
      return $.post(ajaxUrl, data, function (resp) {
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
        placeholder: 'Rechercher un produit (nom, référence, EAN, marque)',
        minimumInputLength: 2,
        ajax: {
          url: $productSearch.data('search-url'),
          dataType: 'json',
          quietMillis: 250,
          data: function (params) {
            return {
              term: params,
              id_tarif: idTarif,
              id_manufacturer: $('#tarif-product-manufacturer').val()
            };
          },
          results: function (data) {
            if (data.success === false) {
              window.alert(data.error || 'Impossible de rechercher les produits.');
            }
            return { results: data.results || [] };
          }
        }
      });
    }
    $('#tarif-product-manufacturer').on('change', function () {
      if ($.fn.select2) {
        $productSearch.select2('val', '');
      }
    });

    // ── Construction d'une ligne ─────────────────────────────────────────────
    function renderBadges(line) {
      var html = '';
      if (line.reduction_source === 'group') {
        html += '<span class="tarif-badge tarif-badge-group">Remise groupe</span>';
      } else if (line.reduction_source === 'customer') {
        html += '<span class="tarif-badge tarif-badge-customer">Remise client</span>';
      } else if (line.reduction_source === 'custom') {
        html += '<span class="tarif-badge tarif-badge-perso">Remise perso</span>';
      }
      return html;
    }

    function updateSourceBadge($row, source) {
      var $badges = $row.find('.tarif-badges');
      $badges.find('.tarif-badge-group, .tarif-badge-customer, .tarif-badge-perso').remove();
      var label = '';
      var cls = '';
      if (source === 'group') { label = 'Remise groupe'; cls = 'tarif-badge-group'; }
      else if (source === 'customer') { label = 'Remise client'; cls = 'tarif-badge-customer'; }
      else if (source === 'custom') { label = 'Remise perso'; cls = 'tarif-badge-perso'; }
      if (label) {
        $badges.prepend('<span class="tarif-badge ' + cls + '">' + label + '</span>');
      }
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
        + '" data-public-price="' + fmtOptional(line.public_price) + '"'
        + ' data-base-price="' + (parseFloat(line.base_price) || 0) + '"'
        + ' data-group-reduction="' + (parseFloat(line.group_reduction_percent) || 0) + '">'
        + '<span class="tarif-cell tarif-col-drag"><span class="tarif-line-number"></span><span class="tarif-drag-line"><i class="icon-arrows"></i></span></span>'
        + '<span class="tarif-cell tarif-col-img">' + img + '</span>'
        + '<span class="tarif-cell tarif-col-info"><strong>' + escapeHtml(line.name) + '</strong>' + attrs
        + '<span class="tarif-meta">' + meta.join(' &middot; ') + '</span>'
        + '<span class="tarif-badges">' + renderBadges(line) + '</span></span>'
        + '<span class="tarif-cell tarif-col-num tarif-zone-catalog tarif-zone-start tarif-catalog">' + fmt(line.base_price) + ' &euro;</span>'
        + '<span class="tarif-cell tarif-col-num tarif-zone-catalog tarif-group"><span class="tarif-group-value">'
        + fmt(line.group_reduction_percent) + ' %</span>'
        + ((parseFloat(line.group_reduction_percent) || 0) > 0
            ? '<button type="button" class="tarif-apply-group" title="Réappliquer la remise de groupe à la réduction client"><i class="icon-arrow-right"></i></button>'
            : '')
        + '</span>'
        + '<span class="tarif-cell tarif-col-num tarif-zone-discount tarif-zone-start"><input type="text" class="form-control tarif-reduction" value="'
        + (parseFloat(line.reduction_percent) || 0).toFixed(4) + '" aria-label="Remise théorique en pourcentage"></span>'
        + '<span class="tarif-cell tarif-col-num tarif-zone-discount"><input type="text" class="form-control tarif-additional-reduction" value="'
        + (parseFloat(line.additional_reduction_percent) || 0).toFixed(4) + '" aria-label="Remise supplémentaire en pourcentage"></span>'
        + '<span class="tarif-cell tarif-col-num tarif-zone-discount"><output class="tarif-total-reduction" title="Pourcentage global calculé, non modifiable">'
        + fmt(line.total_reduction_percent) + ' %</output></span>'
        + '<span class="tarif-cell tarif-col-num tarif-zone-net tarif-zone-start tarif-prices"><span class="tarif-price-label">Après remise théorique</span>'
        + '<span class="tarif-intermediate">' + fmt(line.intermediate_price) + ' &euro;</span>'
        + '<span class="tarif-price-label tarif-net-label">Net après les deux remises</span>'
        + '<div class="input-group tarif-final-group">'
        + '<input type="text" class="form-control tarif-final" aria-label="Prix net final, modifie la première remise" value="' + fmt(line.final_price) + '">'
        + '<span class="input-group-addon">&euro;</span></div></span>'
        + '<span class="tarif-cell tarif-col-num tarif-zone-public tarif-zone-start"><div class="input-group">'
        + '<input type="text" class="form-control tarif-public-markup" placeholder="—"'
        + ' aria-label="Majoration du prix public conseillé, en pourcentage du prix net" value="'
        + fmtOptional(line.public_markup_percent) + '"><span class="input-group-addon">%</span></div></span>'
        + '<span class="tarif-cell tarif-col-num tarif-zone-public"><div class="input-group">'
        + '<input type="text" class="form-control tarif-public-price" placeholder="—"'
        + ' aria-label="Prix public conseillé HT, partagé par tous les tarifs" value="'
        + fmtOptional(line.public_price) + '"><span class="input-group-addon">&euro;</span></div></span>'
        + '<span class="tarif-cell tarif-col-actions"><button type="button" class="btn btn-xs btn-danger tarif-delete-line">'
        + '<i class="icon-trash"></i></button></span>'
        + '<span class="tarif-cell tarif-col-processed"><input type="checkbox" class="tarif-processed"'
        + ' aria-label="Marquer cette ligne comme traitée"' + (line.is_processed ? ' checked' : '') + '></span>'
        + '</div>';
    }

    function renderSectionBlock(section) {
      var id = parseInt(section.id_section, 10);
      return '<div class="tarif-section" data-id-section="' + id + '">'
        + '<div class="tarif-section-header">'
        + '<button type="button" class="btn btn-default btn-xs tarif-toggle-section" aria-expanded="true"'
        + ' aria-controls="tarif-section-head-' + id + ' tarif-section-lines-' + id + '"'
        + ' data-collapse-label="Replier" data-expand-label="Déplier">'
        + '<i class="icon-chevron-down" aria-hidden="true"></i> <span>Replier</span></button>'
        + '<span class="tarif-drag-section"><i class="icon-arrows"></i></span>'
        + '<input type="text" class="form-control tarif-section-title" value="' + escapeHtml(section.title) + '" placeholder="Titre de la section">'
        + '<button type="button" class="btn btn-xs btn-danger tarif-delete-section"><i class="icon-trash"></i></button>'
        + '<span class="tarif-section-range" aria-live="polite"></span>'
        + '</div>'
        + '<div class="tarif-lines-head" id="tarif-section-head-' + id + '">'
        + '<span class="tarif-head-group tarif-hg-catalog">Catalogue</span>'
        + '<span class="tarif-head-group tarif-hg-discount">Remises client</span>'
        + '<span class="tarif-head-group tarif-hg-net">Prix net client</span>'
        + '<span class="tarif-head-group tarif-hg-public" title="Prix partagé : identique dans tous les tarifs contenant ce produit">Prix public conseillé</span>'
        + '<span title="Numéro de la ligne dans tout le tarif" class="tarif-cell tarif-col-drag">N°</span>'
        + '<span class="tarif-cell tarif-col-img">Image</span>'
        + '<span class="tarif-cell tarif-col-info">Produit</span>'
        + '<span class="tarif-cell tarif-col-num tarif-zone-catalog tarif-zone-start">Prix catalogue HT</span>'
        + '<span class="tarif-cell tarif-col-num tarif-zone-catalog">Remise groupe</span>'
        + '<span class="tarif-cell tarif-col-num tarif-zone-discount tarif-zone-start">Remise théorique %</span>'
        + '<span class="tarif-cell tarif-col-num tarif-zone-discount">Remise suppl. %</span>'
        + '<span class="tarif-cell tarif-col-num tarif-zone-discount">Remise totale %</span>'
        + '<span class="tarif-cell tarif-col-num tarif-zone-net tarif-zone-start">Prix net HT</span>'
        + '<span class="tarif-cell tarif-col-num tarif-zone-public tarif-zone-start">Majoration %</span>'
        + '<span class="tarif-cell tarif-col-num tarif-zone-public">PVC HT</span>'
        + '<span class="tarif-cell tarif-col-actions"></span>'
        + '<span class="tarif-cell tarif-col-processed">Traité</span>'
        + '<span class="tarif-head-tool tarif-head-tool-label">Appliquer à toute la section <i class="icon-arrow-right" aria-hidden="true"></i></span>'
        + renderSectionTool('reduction', 'tarif-zone-discount tarif-zone-start', 'Remise théorique de section',
          'Remplace la remise théorique de toutes les lignes de la section, en conservant la remise supplémentaire',
          'Appliquer la remise théorique à la section')
        + renderSectionTool('additional', 'tarif-zone-discount', 'Remise supplémentaire de section',
          'Remplace la remise supplémentaire de toutes les lignes de la section, en conservant la remise théorique',
          'Appliquer la remise supplémentaire à la section')
        + renderSectionTool('markup', 'tarif-zone-public tarif-zone-start', 'Majoration du prix public conseillé de section',
          'Fixe le prix public conseillé de toutes les lignes de la section : prix net + majoration. Le PVC est partagé par tous les tarifs.',
          'Appliquer la majoration à la section')
        + '</div>'
        + '<div class="tarif-lines" id="tarif-section-lines-' + id + '" data-id-section="' + id + '"></div>'
        + '</div>';
    }

    function renderSectionTool(kind, zone, label, help, action) {
      return '<span class="tarif-head-tool tarif-tool-' + kind + ' ' + zone + '">'
        + '<span class="tarif-section-' + kind + ' input-group input-group-sm" title="' + help + '">'
        + '<input type="text" class="form-control tarif-section-' + kind + '-input" placeholder="%" aria-label="' + label + '">'
        + '<span class="input-group-btn"><button type="button" class="btn btn-default tarif-apply-section-' + kind + '"'
        + ' title="' + action + '"><i class="icon-magic"></i></button></span></span></span>';
    }

    $('#tarif-sections').on('click', '.tarif-toggle-section', function () {
      var $button = $(this);
      var $section = $button.closest('.tarif-section');
      var collapsed = !$section.hasClass('is-collapsed');
      $section.toggleClass('is-collapsed', collapsed);
      $button.attr('aria-expanded', collapsed ? 'false' : 'true');
      $button.find('i').toggleClass('icon-chevron-down', !collapsed).toggleClass('icon-chevron-right', collapsed);
      $button.find('span').text($button.attr(collapsed ? 'data-expand-label' : 'data-collapse-label'));
    });

    // ── Numérotation globale des lignes, indépendante des sections ───────────
    function renumberLines() {
      var $lines = $('#tarif-sections .tarif-lines > .tarif-line');
      var total = $lines.length;
      $lines.each(function (index) {
        $(this).find('.tarif-line-number').text(index + 1)
          .attr('title', 'Ligne ' + (index + 1) + ' sur ' + total);
      });
      $('#tarif-sections > .tarif-section').each(function () {
        var $sectionLines = $(this).find('.tarif-lines > .tarif-line');
        var text = 'Aucune ligne · ' + total + ' au total';
        if ($sectionLines.length) {
          var first = $lines.index($sectionLines.first()) + 1;
          var last = first + $sectionLines.length - 1;
          text = (first === last ? 'Ligne ' + first : 'Lignes ' + first + ' à ' + last)
            + ' sur ' + total + ' · ' + $sectionLines.length + ' produit' + ($sectionLines.length > 1 ? 's' : '');
        }
        $(this).find('.tarif-section-range').text(text);
      });
    }

    // ── Sortables ────────────────────────────────────────────────────────────
    function saveOrder() {
      renumberLines();
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
      renumberLines();
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
      $('#tarif-target-section, #tarif-references-section, .tarif-product-section').each(function () {
        var $select = $(this);
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
        if ($select.val() === null) {
          $select.val('0');
        }
      });
    }

    function appendLines(lines, idSection) {
      var $target = $('.tarif-lines[data-id-section="' + idSection + '"]');
      $.each(lines, function (i, line) {
        $target.append(renderLineRow(line));
      });
      initSortables();
      if ($.fn.select2) {
        $productSearch.select2('val', '');
      }
    }

    var browserPage = 1;
    var browserRequest;
    var browserTimer;
    var browserVersion = 0;
    var $browser = $('#tarif-products-modal');
    var $browserProducts = $('#tarif-browser-products');
    var $browserStatus = $('#tarif-browser-status');

    function renderProductCard(product, index) {
      var sectionId = 'tarif-product-section-' + index;
      return '<div class="tarif-product-card' + (product.in_tarif ? ' is-in-tarif' : '')
        + '" data-product="' + escapeHtml(product.id) + '" data-id-product="' + parseInt(product.id_product, 10) + '">'
        + (product.image_url
          ? '<img class="tarif-product-photo" src="' + escapeHtml(product.image_url) + '" alt="" loading="lazy">'
          : '<div class="tarif-product-photo tarif-product-no-photo">Sans photo</div>')
        + '<strong>' + escapeHtml(product.name) + '</strong>'
        + (product.active === false ? '<span><span class="label label-warning">Inactif</span></span>' : '')
        + '<span>' + escapeHtml(product.attribute_names) + '</span>'
        + '<span>' + escapeHtml(product.manufacturer_name) + '</span>'
        + '<span>Réf : ' + escapeHtml(product.reference || '—') + '</span>'
        + '<strong>' + escapeHtml(product.price) + ' HT</strong>'
        + '<label for="' + sectionId + '">Section cible</label>'
        + '<select id="' + sectionId + '" class="form-control tarif-product-section"></select>'
        + '<button type="button" class="btn btn-primary tarif-product-add">Ajouter</button>'
        + (product.combination_count > 0
          ? '<button type="button" class="btn btn-default tarif-product-add-all">Ajouter toutes les déclinaisons restantes</button>'
          : '')
        + '<span class="tarif-product-state"></span></div>';
    }

    function updateBrowserCards($cards) {
      ($cards || $browserProducts.find('.tarif-product-card')).each(function () {
        var $card = $(this);
        var present = $card.hasClass('is-in-tarif');
        $card.find('select, button').prop('disabled', present);
        $card.find('.tarif-product-state').text(present ? 'Déjà dans le tarif' : '');
      });
    }

    function loadBrowser(page) {
      clearTimeout(browserTimer);
      var version = ++browserVersion;
      if (browserRequest) {
        browserRequest.abort();
      }
      $browserProducts.empty();
      $browserStatus.removeClass('alert alert-danger').text('Chargement des produits…');
      $('#tarif-browser-previous, #tarif-browser-next').prop('disabled', true);
      browserRequest = $.ajax({
        url: ajaxUrl,
        dataType: 'json',
        data: {
          ajax: 1,
          action: 'BrowseProducts',
          id_tarif: idTarif,
          id_manufacturer: $('#tarif-browser-manufacturer').val(),
          only_missing: $('#tarif-browser-only-missing').prop('checked') ? 1 : 0,
          term: $('#tarif-browser-search').val(),
          page: page
        }
      }).done(function (resp) {
        if (version !== browserVersion) { return; }
        if (!resp || resp.success === false || !Array.isArray(resp.products)) {
          $browserStatus.addClass('alert alert-danger').text((resp && resp.error) || 'Impossible de charger les produits.');
          return;
        }
        browserPage = resp.page;
        $browserProducts.html(resp.products.map(renderProductCard).join(''));
        $browserProducts.find('.tarif-product-section').each(function () {
          $(this).append($('#tarif-target-section option').clone()).val($('#tarif-target-section').val());
        });
        updateBrowserCards();
        $browserStatus.text(resp.products.length ? '' : 'Aucun produit trouvé.');
        $('#tarif-browser-page').text('Page ' + browserPage);
        $('#tarif-browser-previous').prop('disabled', browserPage <= 1);
        $('#tarif-browser-next').prop('disabled', !resp.has_more);
      }).fail(function (xhr, status) {
        if (status !== 'abort' && version === browserVersion) {
          $browserStatus.addClass('alert alert-danger').text('Erreur réseau lors du chargement des produits.');
        }
      });
    }

    $('#tarif-browse-products').on('click', function () {
      $('#tarif-browser-manufacturer').val($('#tarif-product-manufacturer').val());
      $('#tarif-browser-search').val('');
      $browser.modal('show');
      loadBrowser(1);
    });
    $('#tarif-browser-manufacturer, #tarif-browser-only-missing').on('change', function () { loadBrowser(1); });
    $('#tarif-browser-search').on('input', function () {
      clearTimeout(browserTimer);
      ++browserVersion;
      $('#tarif-browser-previous, #tarif-browser-next').prop('disabled', true);
      browserTimer = setTimeout(function () { loadBrowser(1); }, 250);
    });
    $('#tarif-browser-search').on('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        loadBrowser(1);
      }
    });
    $('#tarif-browser-previous').on('click', function () { loadBrowser(browserPage - 1); });
    $('#tarif-browser-next').on('click', function () { loadBrowser(browserPage + 1); });
    $browser.on('hidden.bs.modal', function () {
      clearTimeout(browserTimer);
      ++browserVersion;
      if (browserRequest) { browserRequest.abort(); }
    });
    $browserProducts.on('click', '.tarif-product-add, .tarif-product-add-all', function () {
      var $card = $(this).closest('.tarif-product-card');
      var addAll = $(this).hasClass('tarif-product-add-all');
      var idProduct = parseInt($card.attr('data-id-product'), 10);
      var $related = $browserProducts.find('.tarif-product-card[data-id-product="' + idProduct + '"]');
      var idSection = parseInt($card.find('select').val(), 10) || 0;
      $related.find('button, select').prop('disabled', true);
      api('AddLine', {
        product: addAll ? idProduct + ':all' : $card.attr('data-product'),
        id_section: idSection
      }, function (resp) {
        var lines = resp.lines || [];
        appendLines(lines, idSection);
        var $currentRelated = $browserProducts.find('.tarif-product-card[data-id-product="' + idProduct + '"]');
        if (addAll) {
          $currentRelated.addClass('is-in-tarif');
        } else {
          $currentRelated.filter('[data-product="' + $card.attr('data-product') + '"]').addClass('is-in-tarif');
        }
        $browserStatus.removeClass('alert alert-danger').text(
          lines.length + ' ligne(s) ajoutée(s).'
          + (resp.skipped ? ' ' + resp.skipped + ' déclinaison(s) déjà présente(s), ignorée(s).' : '')
        );
        if ($('#tarif-browser-only-missing').prop('checked')) {
          loadBrowser(1);
        }
      }).always(function () {
        updateBrowserCards($browserProducts.find('.tarif-product-card[data-id-product="' + idProduct + '"]'));
      });
    });

    // ── Ajout de ligne ───────────────────────────────────────────────────────
    $('#tarif-add-line').on('click', function () {
      var product = $productSearch.val();
      if (!product) {
        window.alert('Sélectionnez un produit.');
        return;
      }
      var idSection = parseInt($('#tarif-target-section').val(), 10) || 0;

      api('AddLine', { product: product, id_section: idSection }, function (resp) {
        var lines = resp.lines || (resp.line ? [resp.line] : []);
        appendLines(lines, idSection);

        if (resp.skipped) {
          window.alert(resp.skipped + ' déclinaison(s) déjà présente(s) dans le tarif, ignorée(s).');
        } else if (!lines.length) {
          window.alert('Ce produit est déjà présent dans le tarif.');
        }
      });
    });

    $('#tarif-sections').on('change', '.tarif-processed', function () {
      var $checkbox = $(this);
      var processed = $checkbox.prop('checked');
      var saved = false;
      $checkbox.prop('disabled', true);
      api('SetLineProcessed', {
        id_line: parseInt($checkbox.closest('.tarif-line').data('id-line'), 10),
        is_processed: processed ? 1 : 0
      }, function (resp) {
        saved = true;
        $checkbox.prop('checked', resp.line.is_processed);
      }).always(function () {
        if (!saved) {
          $checkbox.prop('checked', !processed);
        }
        $checkbox.prop('disabled', false);
      });
    });

    // ── Double saisie : réduction % ⇄ prix final (live + sauvegarde) ─────────
    function getBase($row) {
      return parseFloat($row.attr('data-base-price')) || 0;
    }

    function updatePricePreview($row, intermediate) {
      var base = getBase($row);
      var reduction = parseNum($row.find('.tarif-reduction').val());
      var additional = parseNum($row.find('.tarif-additional-reduction').val());
      var first = intermediate === undefined ? Math.round((base * (1 - reduction / 100) + Number.EPSILON) * 100) / 100 : intermediate;
      var net = Math.round((first * (1 - additional / 100) + Number.EPSILON) * 100) / 100;
      if (intermediate === undefined) {
        $row.find('.tarif-final').val(net.toFixed(2));
      }
      $row.find('.tarif-intermediate').text(first.toFixed(2) + ' €');
      $row.find('.tarif-total-reduction').text((base > 0 ? (1 - net / base) * 100 : 0).toFixed(2) + ' %');
      updatePublicMarkup($row, intermediate === undefined ? net : parseNum($row.find('.tarif-final').val()));
    }

    // Le prix public conseillé est fixe (partagé par produit) : seule la majoration suit le prix net.
    function updatePublicMarkup($row, net) {
      var raw = $row.find('.tarif-public-price').val();
      var $markup = $row.find('.tarif-public-markup');
      if (raw == null || String(raw).trim() === '' || !(net > 0)) {
        $markup.val('');
        return;
      }
      $markup.val(((parseNum(raw) / net - 1) * 100).toFixed(2));
    }

    $('#tarif-sections').on('input', '.tarif-public-markup', function () {
      var $row = $(this).closest('.tarif-line');
      var raw = $(this).val();
      var net = parseNum($row.find('.tarif-final').val());
      $row.find('.tarif-public-price').val(raw == null || String(raw).trim() === '' || !(net > 0)
        ? ''
        : (Math.round((net * (1 + parseNum(raw) / 100) + Number.EPSILON) * 100) / 100).toFixed(2));
    });

    $('#tarif-sections').on('input', '.tarif-public-price', function () {
      var $row = $(this).closest('.tarif-line');
      updatePublicMarkup($row, parseNum($row.find('.tarif-final').val()));
    });

    $('#tarif-sections').on('change', '.tarif-public-markup', function () {
      savePublicPrice($(this).closest('.tarif-line'), 'public_markup', $(this).val());
    });

    $('#tarif-sections').on('change', '.tarif-public-price', function () {
      savePublicPrice($(this).closest('.tarif-line'), 'public_price', $(this).val());
    });

    function savePublicPrice($row, mode, value) {
      var saved = false;
      var request = api('UpdatePublicPrice', {
        id_line: parseInt($row.data('id-line'), 10),
        mode: mode,
        value: value == null ? '' : String(value).trim()
      }, function (resp) {
        saved = true;
        applyPublicResult($row, resp.line);
      });
      if (request && request.always) {
        request.always(function () {
          if (!saved) {
            var previous = $row.attr('data-public-price');
            $row.find('.tarif-public-price').val(previous);
            updatePublicMarkup($row, parseNum($row.find('.tarif-final').val()));
          }
        });
      }
    }

    function applyPublicResult($row, line) {
      $row.attr('data-public-price', fmtOptional(line.public_price));
      $row.find('.tarif-public-price').val(fmtOptional(line.public_price));
      $row.find('.tarif-public-markup').val(fmtOptional(line.public_markup_percent));
    }

    $('#tarif-sections').on('input', '.tarif-reduction, .tarif-additional-reduction', function () {
      updatePricePreview($(this).closest('.tarif-line'));
    });

    // Saisie prix final -> met à jour la réduction en direct
    $('#tarif-sections').on('input', '.tarif-final', function () {
      var $row = $(this).closest('.tarif-line');
      var base = getBase($row);
      var price = parseNum($(this).val());
      var additional = parseNum($row.find('.tarif-additional-reduction').val());
      if (additional < 100) {
        var intermediate = Math.round((price / (1 - additional / 100) + Number.EPSILON) * 100) / 100;
        var pct = base > 0 ? ((base - intermediate) / base) * 100 : 0;
        $row.find('.tarif-reduction').val(pct.toFixed(4));
        updatePricePreview($row, intermediate);
      }
    });

    // Validation (blur) -> sauvegarde côté serveur
    $('#tarif-sections').on('change', '.tarif-reduction', function () {
      var $row = $(this).closest('.tarif-line');
      saveLine($row, 'reduction', $(this).val());
    });
    $('#tarif-sections').on('change', '.tarif-final', function () {
      var $row = $(this).closest('.tarif-line');
      saveLine($row, 'price', $(this).val());
    });
    $('#tarif-sections').on('change', '.tarif-additional-reduction', function () {
      saveLine($(this).closest('.tarif-line'), 'additional_reduction', $(this).val());
    });

    function saveLine($row, mode, value) {
      var idLine = parseInt($row.data('id-line'), 10);
      api('UpdateLine', { id_line: idLine, mode: mode, value: value }, function (resp) {
        applyLineResult(resp.line);
      });
    }

    function applyLineResult(line) {
      var $row = $('.tarif-line[data-id-line="' + parseInt(line.id_line, 10) + '"]');
      if (!$row.length) {
        return;
      }
      $row.find('.tarif-reduction').val((parseFloat(line.reduction_percent) || 0).toFixed(4));
      $row.find('.tarif-additional-reduction').val((parseFloat(line.additional_reduction_percent) || 0).toFixed(4));
      $row.find('.tarif-final').val(fmt(line.final_price));
      $row.find('.tarif-intermediate').text(fmt(line.intermediate_price) + ' €');
      $row.find('.tarif-total-reduction').text(fmt(line.total_reduction_percent) + ' %');
      if (line.public_price !== undefined) {
        applyPublicResult($row, line);
      }
      updateSourceBadge($row, line.reduction_source);
    }

    // ── Réappliquer la remise de groupe à la réduction client ────────────────
    $('#tarif-sections').on('click', '.tarif-apply-group', function () {
      var $row = $(this).closest('.tarif-line');
      var rg = parseFloat($row.attr('data-group-reduction')) || 0;
      $row.find('.tarif-reduction').val(rg.toFixed(4));
      updatePricePreview($row);
      saveLine($row, 'reduction', rg);
    });

    // ── Réduction appliquée à toute une section ──────────────────────────────
    $('#tarif-sections').on('click', '.tarif-apply-section-reduction, .tarif-apply-section-additional', function () {
      var additional = $(this).hasClass('tarif-apply-section-additional');
      var $section = $(this).closest('.tarif-section');
      var idSection = parseInt($section.data('id-section'), 10);
      var $input = $section.find(additional ? '.tarif-section-additional-input' : '.tarif-section-reduction-input');
      var raw = String($input.val()).replace(/\s/g, '').replace(',', '.');
      var pct = Number(raw);
      if (raw === '' || !Number.isFinite(pct) || pct > 100 || (additional && pct < 0)) {
        window.alert('Saisissez une remise valide ; la remise supplémentaire doit être comprise entre 0 et 100 %.');
        return;
      }
      var count = $section.find('.tarif-line').length;

      if (!count) {
        window.alert('Aucun produit dans cette section.');
        return;
      }
      if (!window.confirm('Appliquer une remise ' + (additional ? 'supplémentaire' : 'théorique') + ' de '
        + pct.toFixed(2) + ' % à ' + count + ' produit(s) de cette section ?\n\n'
        + 'Seule cette remise sera remplacée ; l’autre remise sera conservée.')) {
        return;
      }

      api('ApplySectionReduction', {
        id_section: idSection, value: pct, mode: additional ? 'additional_reduction' : 'reduction'
      }, function (resp) {
        $.each(resp.lines || [], function (i, line) {
          applyLineResult(line);
        });
        $input.val('');
      });
    });

    $('#tarif-sections').on('click', '.tarif-apply-section-markup', function () {
      var $section = $(this).closest('.tarif-section');
      var $input = $section.find('.tarif-section-markup-input');
      var raw = String($input.val()).replace(/\s/g, '').replace(',', '.');
      var pct = Number(raw);
      if (raw === '' || !Number.isFinite(pct) || pct <= -100) {
        window.alert('Saisissez une majoration valide, supérieure à -100 %.');
        return;
      }
      var count = $section.find('.tarif-line').length;
      if (!count) {
        window.alert('Aucun produit dans cette section.');
        return;
      }
      if (!window.confirm('Appliquer une majoration de ' + pct.toFixed(2) + ' % au prix net de ' + count
        + ' produit(s) de cette section pour fixer leur prix public conseillé ?\n\n'
        + 'Le prix public conseillé est partagé : il sera modifié dans tous les tarifs contenant ces produits. '
        + 'Les produits à prix net nul sont ignorés.')) {
        return;
      }

      api('ApplySectionPublicMarkup', {
        id_section: parseInt($section.data('id-section'), 10), value: pct
      }, function (resp) {
        $.each(resp.lines || [], function (i, line) {
          var $row = $('.tarif-line[data-id-line="' + parseInt(line.id_line, 10) + '"]');
          if ($row.length) {
            applyPublicResult($row, line);
          }
        });
        $input.val('');
        if (resp.skipped && resp.skipped.length) {
          window.alert(resp.skipped.length + ' produit(s) à prix net nul ignoré(s) : saisissez leur prix public directement.');
        }
      });
    });

    $('#tarif-sections').on('keydown', '.tarif-section-reduction-input, .tarif-section-additional-input, .tarif-section-markup-input', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        $(this).closest('.input-group').find('button').trigger('click');
      }
    });

    // ── Suppression de ligne ─────────────────────────────────────────────────
    $('#tarif-sections').on('click', '.tarif-delete-line', function () {
      var $row = $(this).closest('.tarif-line');
      var idLine = parseInt($row.data('id-line'), 10);
      api('DeleteLine', { id_line: idLine }, function () {
        $row.remove();
        renumberLines();
      });
    });

    // ── Ajout de section ─────────────────────────────────────────────────────
    // La nouvelle section se place en tête des produits et devient la destination.
    $('#tarif-add-section').on('click', function () {
      var $button = $(this);
      $button.prop('disabled', true);
      api('AddSection', { title: '' }, function (resp) {
        var $section = $(renderSectionBlock(resp.section));
        $('#tarif-sections').prepend($section);
        refreshTargetSections();
        $('#tarif-target-section').val(String(parseInt(resp.section.id_section, 10)));
        initSortables();
        $('#tarif-sections').scrollTop(0);
        $section.find('.tarif-section-title').trigger('focus');
      }).always(function () {
        $button.prop('disabled', false);
      });
    });

    // ── Ajout en masse par références (modale) ───────────────────────────────
    $('#tarif-add-references').on('click', function () {
      $('#tarif-references-textarea').val('');
      $('#tarif-references-section').val($('#tarif-target-section').val());
      $('#tarif-references-result').hide().empty();
      $('#tarif-references-modal').modal('show');
    });

    $('#tarif-references-submit').on('click', function () {
      var $btn = $(this);
      var idSection = parseInt($('#tarif-references-section').val(), 10) || 0;
      var references = $('#tarif-references-textarea').val();

      if (!$.trim(references)) {
        window.alert('Saisissez au moins une référence.');
        return;
      }

      $btn.prop('disabled', true);
      $.post(ajaxUrl, {
        ajax: 1,
        action: 'AddByReferences',
        id_tarif: idTarif,
        id_section: idSection,
        references: references
      }, function (resp) {
        if (resp && resp.success === false) {
          window.alert(resp.error || 'Erreur');
          return;
        }
        var lines = resp.lines || [];
        appendLines(lines, idSection);

        var msg = lines.length + ' ligne(s) ajoutée(s).';
        var notFound = resp.not_found || [];
        var duplicates = resp.duplicates || [];
        var $result = $('#tarif-references-result');
        var details = '';
        if (duplicates.length) {
          details += '<br>Déjà présentes (' + duplicates.length + ') : '
            + $('<div>').text(duplicates.join(', ')).html();
        }
        if (notFound.length) {
          details += '<br>Introuvables (' + notFound.length + ') : '
            + $('<div>').text(notFound.join(', ')).html();
        }
        if (details) {
          $result.removeClass('alert-success').addClass('alert-warning')
            .html('<strong>' + msg + '</strong>' + details).show();
        } else {
          $result.removeClass('alert-warning').addClass('alert-success')
            .html('<strong>' + msg + '</strong>').show();
          $('#tarif-references-textarea').val('');
        }
      }, 'json').fail(function () {
        window.alert('Erreur réseau.');
      }).always(function () {
        $btn.prop('disabled', false);
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

    // ── Affichage du prix public conseillé dans les exports ─────────────────
    $('#tarif-show-public-price').on('change', function () {
      var $checkbox = $(this);
      var show = $checkbox.prop('checked');
      var saved = false;
      $checkbox.prop('disabled', true);
      api('SetShowPublicPrice', { show_public_price: show ? 1 : 0 }, function (resp) {
        saved = true;
        $checkbox.prop('checked', !!resp.show_public_price);
      }).always(function () {
        if (!saved) {
          $checkbox.prop('checked', !show);
        }
        $checkbox.prop('disabled', false);
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
      if (!window.confirm('Mettre à jour les prix catalogue et remises de groupe en conservant les prix finaux ?\n\nLa réduction sera recalculée. Les prix spécifiques déjà validés sur ce client seront adaptés ; les prix validés sur le groupe ne seront pas modifiés sans une nouvelle double validation.')) {
        return;
      }
      api('SyncPrices', {}, function () {
        window.location.reload();
      });
    });

    $('.tarif-toggle-validation').on('click', function () {
      var $button = $(this);
      var collapsed = !$('#tarif-validation').hasClass('is-collapsed');
      $('#tarif-validation').toggleClass('is-collapsed', collapsed);
      $button.attr('aria-expanded', collapsed ? 'false' : 'true');
      $button.find('.icon-chevron-right, .icon-chevron-down').first()
        .toggleClass('icon-chevron-down', !collapsed).toggleClass('icon-chevron-right', collapsed);
    });

    $('.tarif-validate').on('click', function () {
      var $button = $(this);
      var target = $button.data('target');
      var idTarget = parseInt($button.data('id-target'), 10);
      var label = (target === 'group' ? 'Groupe de clients par défaut : ' : 'Client : ')
        + $button.data('target-name') + ' (#' + idTarget + ')';
      if (parseInt($('#tarif_id_customer').val(), 10) !== parseInt($('#tarif-validation').data('id-customer'), 10)) {
        window.alert('Enregistrez le changement de client et rechargez le tarif avant de le valider.');
        return;
      }
      if (!window.confirm('Première confirmation : valider le tarif sur cette cible ?\n\n' + label
        + (target === 'group' ? '\n\nAttention : cette action concerne tous les clients de ce groupe.' : '')
        + '\n\nLes anciennes remises des lignes du tarif seront remplacées pour cette cible uniquement.')) {
        return;
      }
      var $buttons = $('.tarif-validate:not(:disabled)');
      $buttons.prop('disabled', true);
      var committing = false;
      api('PrepareTarifValidation', { validation_target: target, id_target: idTarget }, function (prepared) {
        if (!prepared || prepared.success !== true || !prepared.confirmation_token
          || parseInt(prepared.id_target, 10) !== idTarget || !(prepared.line_count > 0)) {
          window.alert('La préparation de la validation a échoué. Rechargez le tarif et recommencez.');
          return;
        }
        var confirmedLabel = (target === 'group' ? 'Groupe de clients par défaut : ' : 'Client : ')
          + prepared.target_name + ' (#' + prepared.id_target + ')';
        if (!window.confirm('Seconde confirmation — application définitive\n\n' + confirmedLabel
          + '\nLignes concernées : ' + prepared.line_count
          + '\n\nConfirmez-vous le remplacement des anciens prix spécifiques pour ces produits et déclinaisons ?'
          + '\nCette validation sera inscrite dans l’historique.')) {
          return;
        }
        committing = true;
        api('ValidateTarif', {
          validation_target: target,
          id_target: prepared.id_target,
          confirmation_token: prepared.confirmation_token,
          confirmed: 1
        }, function (result) {
          if (!result || result.success !== true) {
            window.alert('La validation n’a pas été confirmée par le serveur. Vérifiez l’historique avant de réessayer.');
            return;
          }
          window.location.reload();
        }).always(function () {
          $buttons.prop('disabled', false);
        });
      }).always(function () {
        if (!committing) {
          $buttons.prop('disabled', false);
        }
      });
    });
  });
})(jQuery);
