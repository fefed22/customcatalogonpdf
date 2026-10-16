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
        + '" data-base-price="' + (parseFloat(line.base_price) || 0) + '"'
        + ' data-group-reduction="' + (parseFloat(line.group_reduction_percent) || 0) + '">'
        + '<span class="tarif-cell tarif-col-drag"><span class="tarif-drag-line"><i class="icon-arrows"></i></span></span>'
        + '<span class="tarif-cell tarif-col-img">' + img + '</span>'
        + '<span class="tarif-cell tarif-col-info"><strong>' + escapeHtml(line.name) + '</strong>' + attrs
        + '<span class="tarif-meta">' + meta.join(' &middot; ') + '</span>'
        + '<span class="tarif-badges">' + renderBadges(line) + '</span></span>'
        + '<span class="tarif-cell tarif-col-num tarif-catalog">' + fmt(line.base_price) + ' &euro;</span>'
        + '<span class="tarif-cell tarif-col-num tarif-group"><span class="tarif-group-value">'
        + fmt(line.group_reduction_percent) + ' %</span>'
        + ((parseFloat(line.group_reduction_percent) || 0) > 0
            ? '<button type="button" class="tarif-apply-group" title="Réappliquer la remise de groupe à la réduction client"><i class="icon-arrow-right"></i></button>'
            : '')
        + '</span>'
        + '<span class="tarif-cell tarif-col-num"><input type="text" class="form-control tarif-reduction" value="'
        + fmt(line.reduction_percent) + '"></span>'
        + '<span class="tarif-cell tarif-col-num"><div class="input-group tarif-final-group">'
        + '<input type="text" class="form-control tarif-final" value="' + fmt(line.final_price) + '">'
        + '<span class="input-group-addon">&euro;</span></div></span>'
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
        + '</div>'
        + '<div class="tarif-lines-head" id="tarif-section-head-' + id + '">'
        + '<span class="tarif-cell tarif-col-drag"></span><span class="tarif-cell tarif-col-img">Image</span>'
        + '<span class="tarif-cell tarif-col-info">Produit</span>'
        + '<span class="tarif-cell tarif-col-num">Prix catalogue</span>'
        + '<span class="tarif-cell tarif-col-num">Remise groupe</span>'
        + '<span class="tarif-cell tarif-col-num">Réduction client %</span>'
        + '<span class="tarif-cell tarif-col-num">Prix final HT</span>'
        + '<span class="tarif-cell tarif-col-actions"></span>'
        + '<span class="tarif-cell tarif-col-processed">Traité</span>'
        + '</div>'
        + '<div class="tarif-lines" id="tarif-section-lines-' + id + '" data-id-section="' + id + '"></div>'
        + '</div>';
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
        applyLineResult(resp.line);
      });
    }

    function applyLineResult(line) {
      var $row = $('.tarif-line[data-id-line="' + parseInt(line.id_line, 10) + '"]');
      if (!$row.length) {
        return;
      }
      $row.find('.tarif-reduction').val(fmt(line.reduction_percent));
      $row.find('.tarif-final').val(fmt(line.final_price));
      updateSourceBadge($row, line.reduction_source);
    }

    // ── Réappliquer la remise de groupe à la réduction client ────────────────
    $('#tarif-sections').on('click', '.tarif-apply-group', function () {
      var $row = $(this).closest('.tarif-line');
      var rg = parseFloat($row.attr('data-group-reduction')) || 0;
      var base = getBase($row);
      $row.find('.tarif-reduction').val(rg.toFixed(2));
      $row.find('.tarif-final').val((base * (1 - rg / 100)).toFixed(2));
      saveLine($row, 'reduction', rg);
    });

    // ── Réduction appliquée à toute une section ──────────────────────────────
    $('#tarif-sections').on('click', '.tarif-apply-section-reduction', function () {
      var $section = $(this).closest('.tarif-section');
      var idSection = parseInt($section.data('id-section'), 10);
      var $input = $section.find('.tarif-section-reduction-input');
      var pct = parseNum($input.val());
      var count = $section.find('.tarif-line').length;

      if (!count) {
        window.alert('Aucun produit dans cette section.');
        return;
      }
      if (!window.confirm('Appliquer une réduction de ' + pct.toFixed(2) + ' % à ' + count + ' produit(s) de cette section ?\n\nLes réductions client existantes de ces lignes seront écrasées.')) {
        return;
      }

      api('ApplySectionReduction', { id_section: idSection, value: pct }, function (resp) {
        $.each(resp.lines || [], function (i, line) {
          applyLineResult(line);
        });
        $input.val('');
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

    // ── Ajout en masse par références (modale) ───────────────────────────────
    $('#tarif-add-references').on('click', function () {
      $('#tarif-references-textarea').val('');
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
