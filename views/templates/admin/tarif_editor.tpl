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
      <div class="col-lg-6">
        <label>{l s='Ajouter un produit' mod='customcatalogonpdf'}</label>
        <div class="input-group">
          <input type="hidden"
                 id="tarif-product-search"
                 class="form-control"
                 data-search-url="{$product_search_url|escape:'htmlall':'UTF-8'}">
          <span class="input-group-btn">
            <select id="tarif-target-section" class="form-control">
              <option value="0">{l s='— Sans section —' mod='customcatalogonpdf'}</option>
              {foreach from=$sections item=section}
                {if $section.id_section > 0}
                  <option value="{$section.id_section|intval}">{$section.title|escape:'htmlall':'UTF-8'}</option>
                {/if}
              {/foreach}
            </select>
          </span>
          <span class="input-group-btn">
            <button type="button" class="btn btn-primary" id="tarif-add-line">
              <i class="icon-plus"></i> {l s='Ajouter' mod='customcatalogonpdf'}
            </button>
          </span>
        </div>
      </div>
      <div class="col-lg-6 text-right tarif-toolbar-buttons">
        <button type="button" class="btn btn-default" id="tarif-add-section">
          <i class="icon-folder-open"></i> {l s='Nouvelle section' mod='customcatalogonpdf'}
        </button>
        <button type="button" class="btn btn-default" id="tarif-refresh-prices">
          <i class="icon-refresh"></i> {l s='Rafraîchir les prix' mod='customcatalogonpdf'}
        </button>
        <a class="btn btn-default" href="{$pdf_url|escape:'htmlall':'UTF-8'}">
          <i class="icon-file-pdf-o"></i> {l s='PDF' mod='customcatalogonpdf'}
        </a>
        <a class="btn btn-default" href="{$xlsx_url|escape:'htmlall':'UTF-8'}">
          <i class="icon-file-excel-o"></i> {l s='Excel' mod='customcatalogonpdf'}
        </a>
        <a class="btn btn-default" href="{$csv_url|escape:'htmlall':'UTF-8'}">
          <i class="icon-file-text-o"></i> {l s='CSV' mod='customcatalogonpdf'}
        </a>
        <a class="btn btn-success" href="{$validate_url|escape:'htmlall':'UTF-8'}"
           onclick="return confirm('{l s='Valider ce tarif et verrouiller les prix spécifiques du client ?' mod='customcatalogonpdf' js=1}');">
          <i class="icon-lock"></i> {l s='Valider' mod='customcatalogonpdf'}
        </a>
      </div>
    </div>

    <hr>

    {* ── Sections et lignes ──────────────────────────────────────────── *}
    <div id="tarif-sections">
      {foreach from=$sections item=section}
        <div class="tarif-section{if $section.id_section == 0} tarif-section-none{/if}"
             data-id-section="{$section.id_section|intval}">
          <div class="tarif-section-header">
            {if $section.id_section > 0}
              <span class="tarif-drag-section"><i class="icon-arrows"></i></span>
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

          <table class="table tarif-lines-table">
            <thead>
              <tr>
                <th class="tarif-col-drag"></th>
                <th class="tarif-col-img">{l s='Image' mod='customcatalogonpdf'}</th>
                <th>{l s='Produit' mod='customcatalogonpdf'}</th>
                <th class="tarif-col-price">{l s='Prix actuel HT' mod='customcatalogonpdf'}</th>
                <th class="tarif-col-reduction">{l s='Réduction %' mod='customcatalogonpdf'}</th>
                <th class="tarif-col-price">{l s='Prix final HT' mod='customcatalogonpdf'}</th>
                <th class="tarif-col-actions"></th>
              </tr>
            </thead>
            <tbody class="tarif-lines" data-id-section="{$section.id_section|intval}">
              {foreach from=$section.lines item=line}
                <tr class="tarif-line" data-id-line="{$line.id_line|intval}">
                  <td class="tarif-col-drag"><span class="tarif-drag-line"><i class="icon-arrows"></i></span></td>
                  <td class="tarif-col-img">
                    {if $line.image_url}
                      <img src="{$line.image_url|escape:'htmlall':'UTF-8'}" alt="" class="tarif-thumb">
                    {else}
                      <span class="tarif-thumb-empty"></span>
                    {/if}
                  </td>
                  <td>
                    <strong>{$line.name|escape:'htmlall':'UTF-8'}</strong>
                    {if $line.attribute_names}<div class="tarif-attrs">{$line.attribute_names|escape:'htmlall':'UTF-8'}</div>{/if}
                    <div class="tarif-meta">
                      {if $line.reference}{l s='Réf' mod='customcatalogonpdf'} : {$line.reference|escape:'htmlall':'UTF-8'}{/if}
                      {if $line.ean13} &middot; EAN : {$line.ean13|escape:'htmlall':'UTF-8'}{/if}
                    </div>
                  </td>
                  <td class="tarif-col-price tarif-current">{$line.current_price|string_format:"%.2f"} &euro;</td>
                  <td class="tarif-col-reduction">
                    <input type="text" class="form-control tarif-reduction"
                           value="{$line.reduction_percent|string_format:"%.2f"}">
                  </td>
                  <td class="tarif-col-price tarif-final"><strong>{$line.final_price|string_format:"%.2f"} &euro;</strong></td>
                  <td class="tarif-col-actions">
                    <button type="button" class="btn btn-xs btn-danger tarif-delete-line"
                            title="{l s='Retirer' mod='customcatalogonpdf'}">
                      <i class="icon-trash"></i>
                    </button>
                  </td>
                </tr>
              {/foreach}
            </tbody>
          </table>
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
