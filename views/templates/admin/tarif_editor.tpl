{*
 * Éditeur de tarif client : sections, lignes produits, exports et validation.
 * Affiché sous le formulaire d'en-tête du tarif.
 *}
<div class="panel customcatalog-tarif-editor"
     id="tarif-editor"
     data-id-tarif="{$tarif.id_tarif|intval}"
     data-ajax-url="{$ajax_url|escape:'htmlall':'UTF-8'}">

  <div class="panel-heading">
    <i class="icon-list"></i>
    {l s='Contenu du tarif' mod='customcatalogonpdf'}
    {if $tarif.is_validated}
      <span class="badge badge-success">{l s='Validé' mod='customcatalogonpdf'}</span>
    {else}
      <span class="badge badge-default">{l s='Brouillon' mod='customcatalogonpdf'}</span>
    {/if}
  </div>

  <div class="panel-body">

    {* ── Barre d'actions ─────────────────────────────────────────────── *}
    <div class="row tarif-toolbar">
      <div class="col-lg-5 col-md-6">
        <label>{l s='Ajouter un produit' mod='customcatalogonpdf'}</label>
        <input type="hidden"
               id="tarif-product-search"
               class="form-control"
               data-search-url="{$product_search_url|escape:'htmlall':'UTF-8'}">
      </div>
      <div class="col-lg-3 col-md-6">
        <label>{l s='Dans la section' mod='customcatalogonpdf'}</label>
        <div class="tarif-add-group">
          <select id="tarif-target-section" class="form-control">
            <option value="0">{l s='— Sans section —' mod='customcatalogonpdf'}</option>
            {foreach from=$sections item=section}
              {if $section.id_section > 0}
                <option value="{$section.id_section|intval}">{$section.title|escape:'htmlall':'UTF-8'}</option>
              {/if}
            {/foreach}
          </select>
          <button type="button" class="btn btn-primary" id="tarif-add-line">
            <i class="icon-plus"></i> {l s='Ajouter' mod='customcatalogonpdf'}
          </button>
        </div>
      </div>
      <div class="col-lg-4 col-md-12 text-right tarif-toolbar-buttons">
        <label class="hidden-md hidden-sm hidden-xs">&nbsp;</label>
        <div>
          <button type="button" class="btn btn-default" id="tarif-add-section">
            <i class="icon-folder-open"></i> {l s='Section' mod='customcatalogonpdf'}
          </button>
          <button type="button" class="btn btn-default" id="tarif-refresh-prices">
            <i class="icon-refresh"></i> {l s='Prix' mod='customcatalogonpdf'}
          </button>
          <a class="btn btn-default" href="{$pdf_url|escape:'htmlall':'UTF-8'}">
            <i class="icon-file-pdf-o"></i> PDF
          </a>
          <a class="btn btn-default" href="{$xlsx_url|escape:'htmlall':'UTF-8'}">
            <i class="icon-file-excel-o"></i> Excel
          </a>
          <a class="btn btn-default" href="{$csv_url|escape:'htmlall':'UTF-8'}">
            <i class="icon-file-text-o"></i> CSV
          </a>
          <a class="btn btn-success" id="tarif-validate" href="{$validate_url|escape:'htmlall':'UTF-8'}">
            <i class="icon-lock"></i> {l s='Valider' mod='customcatalogonpdf'}
          </a>
        </div>
      </div>
    </div>

    <hr>

    {* ── Alerte : prix catalogue / remise groupe modifiés ────────────── *}
    {if $has_changes}
      <div class="alert alert-warning tarif-changes-alert">
        <i class="icon-warning"></i>
        {l s='Le prix catalogue ou la remise de groupe de certains produits ont changé depuis la dernière mise à jour de ce tarif.' mod='customcatalogonpdf'}
        <button type="button" class="btn btn-warning btn-sm" id="tarif-sync-prices">
          <i class="icon-refresh"></i> {l s='Mettre à jour en conservant les prix finaux' mod='customcatalogonpdf'}
        </button>
      </div>
    {/if}

    {* ── Sections et lignes ──────────────────────────────────────────── *}
    <div id="tarif-sections">
      {foreach from=$sections item=section}
        <div class="tarif-section{if $section.id_section == 0} tarif-section-none{/if}"
             data-id-section="{$section.id_section|intval}">
          <div class="tarif-section-header">
            {if $section.id_section > 0}
              <span class="tarif-drag-section" title="{l s='Déplacer la section' mod='customcatalogonpdf'}"><i class="icon-arrows"></i></span>
              <input type="text"
                     class="form-control tarif-section-title"
                     value="{$section.title|escape:'htmlall':'UTF-8'}"
                     placeholder="{l s='Titre de la section' mod='customcatalogonpdf'}">
              <button type="button" class="btn btn-xs btn-danger tarif-delete-section"
                      title="{l s='Supprimer la section' mod='customcatalogonpdf'}">
                <i class="icon-trash"></i>
              </button>
            {else}
              <span class="tarif-section-none-label">{l s='Sans section' mod='customcatalogonpdf'}</span>
            {/if}
          </div>

          <div class="tarif-lines-head">
            <span class="tarif-cell tarif-col-drag"></span>
            <span class="tarif-cell tarif-col-img">{l s='Image' mod='customcatalogonpdf'}</span>
            <span class="tarif-cell tarif-col-info">{l s='Produit' mod='customcatalogonpdf'}</span>
            <span class="tarif-cell tarif-col-num">{l s='Prix catalogue' mod='customcatalogonpdf'}</span>
            <span class="tarif-cell tarif-col-num">{l s='Remise groupe' mod='customcatalogonpdf'}</span>
            <span class="tarif-cell tarif-col-num">{l s='Réduction client %' mod='customcatalogonpdf'}</span>
            <span class="tarif-cell tarif-col-num">{l s='Prix final HT' mod='customcatalogonpdf'}</span>
            <span class="tarif-cell tarif-col-actions"></span>
          </div>

          <div class="tarif-lines" data-id-section="{$section.id_section|intval}">
            {foreach from=$section.lines item=line}
              <div class="tarif-line" data-id-line="{$line.id_line|intval}" data-base-price="{$line.base_price|string_format:"%.6f"}">
                <span class="tarif-cell tarif-col-drag"><span class="tarif-drag-line"><i class="icon-arrows"></i></span></span>
                <span class="tarif-cell tarif-col-img">
                  {if $line.image_url}
                    <img src="{$line.image_url|escape:'htmlall':'UTF-8'}" alt="" class="tarif-thumb">
                  {else}
                    <span class="tarif-thumb-empty"></span>
                  {/if}
                </span>
                <span class="tarif-cell tarif-col-info">
                  <strong>{$line.name|escape:'htmlall':'UTF-8'}</strong>
                  {if $line.attribute_names}<span class="tarif-attrs">{$line.attribute_names|escape:'htmlall':'UTF-8'}</span>{/if}
                  <span class="tarif-meta">
                    {if $line.reference}{l s='Réf' mod='customcatalogonpdf'} : {$line.reference|escape:'htmlall':'UTF-8'}{/if}
                    {if $line.ean13} &middot; EAN : {$line.ean13|escape:'htmlall':'UTF-8'}{/if}
                  </span>
                  <span class="tarif-badges">
                    {if $line.has_group_rule}<span class="tarif-badge tarif-badge-group">{l s='Remise groupe' mod='customcatalogonpdf'}</span>{/if}
                    {if $line.has_customer_rule}<span class="tarif-badge tarif-badge-customer">{l s='Remise client' mod='customcatalogonpdf'}</span>{/if}
                    {if $line.has_changes}<span class="tarif-badge tarif-badge-changed" title="{l s='Catalogue' mod='customcatalogonpdf'} : {$line.base_price|string_format:"%.2f"} &rarr; {$line.live_base_price|string_format:"%.2f"} &euro; | {l s='Remise groupe' mod='customcatalogonpdf'} : {$line.group_reduction_percent|string_format:"%.2f"} &rarr; {$line.live_group_reduction_percent|string_format:"%.2f"} %">{l s='Prix modifiés' mod='customcatalogonpdf'}</span>{/if}
                  </span>
                </span>
                <span class="tarif-cell tarif-col-num tarif-catalog">{$line.base_price|string_format:"%.2f"} &euro;</span>
                <span class="tarif-cell tarif-col-num tarif-group">{$line.group_reduction_percent|string_format:"%.2f"} %</span>
                <span class="tarif-cell tarif-col-num">
                  <input type="text" class="form-control tarif-reduction" value="{$line.reduction_percent|string_format:"%.2f"}">
                </span>
                <span class="tarif-cell tarif-col-num">
                  <div class="input-group tarif-final-group">
                    <input type="text" class="form-control tarif-final" value="{$line.final_price|string_format:"%.2f"}">
                    <span class="input-group-addon">&euro;</span>
                  </div>
                </span>
                <span class="tarif-cell tarif-col-actions">
                  <button type="button" class="btn btn-xs btn-danger tarif-delete-line"
                          title="{l s='Retirer' mod='customcatalogonpdf'}">
                    <i class="icon-trash"></i>
                  </button>
                </span>
              </div>
            {/foreach}
          </div>
        </div>
      {/foreach}
    </div>

    {* ── Duplication vers un autre client ────────────────────────────── *}
    <hr>
    <form method="post" action="{$form_action|escape:'htmlall':'UTF-8'}" class="form-inline tarif-duplicate-form">
      <input type="hidden" name="id_tarif" value="{$tarif.id_tarif|intval}">
      <label>{l s='Dupliquer vers le client' mod='customcatalogonpdf'}</label>
      <input type="hidden"
             name="duplicate_id_customer"
             id="tarif-duplicate-customer"
             class="form-control"
             data-search-url="{$customer_search_url|escape:'htmlall':'UTF-8'}">
      <button type="submit" name="submitDuplicateTarif" class="btn btn-default">
        <i class="icon-copy"></i> {l s='Dupliquer' mod='customcatalogonpdf'}
      </button>
    </form>

  </div>
</div>
