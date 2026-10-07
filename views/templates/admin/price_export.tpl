<div class="panel customcatalog-price-export">
  <div class="panel-heading">
    <i class="icon-tags"></i>
    {l s='Export des tarifs' mod='customcatalogonpdf'}
  </div>

  <form method="post" action="{$form_action|escape:'htmlall':'UTF-8'}">
    <div class="row">
      <div class="col-lg-4">
        <div class="form-group">
          <label for="id_group">{l s='Groupe client' mod='customcatalogonpdf'}</label>
          <select name="id_group" id="id_group" class="form-control">
            <option value="0">{l s='— Sélectionner —' mod='customcatalogonpdf'}</option>
            {foreach from=$groups item=group}
              <option value="{$group.id_group|intval}"{if $filters.id_group == $group.id_group} selected="selected"{/if}>
                {$group.name|escape:'htmlall':'UTF-8'}
              </option>
            {/foreach}
          </select>
          <p class="help-block">{l s='Tarif utilisé lorsqu’aucun client spécifique n’est sélectionné.' mod='customcatalogonpdf'}</p>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="form-group">
          <label for="id_customer">{l s='Client spécifique' mod='customcatalogonpdf'}</label>
          <input
            type="hidden"
            name="id_customer"
            id="id_customer"
            class="form-control js-price-export-customer"
            data-search-url="{$customer_search_url|escape:'htmlall':'UTF-8'}"
            value="{if $selected_customer}{$selected_customer.id|intval}{/if}"
            data-selected-text="{if $selected_customer}{$selected_customer.text|escape:'htmlall':'UTF-8'}{/if}"
          >
          <p class="help-block">{l s='Le tarif spécifique du client est prioritaire sur le groupe.' mod='customcatalogonpdf'}</p>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="form-group">
          <label for="id_comparison_group">{l s='Groupe de comparaison' mod='customcatalogonpdf'}</label>
          <select name="id_comparison_group" id="id_comparison_group" class="form-control">
            <option value="0">{l s='— Aucune comparaison —' mod='customcatalogonpdf'}</option>
            {foreach from=$groups item=group}
              <option value="{$group.id_group|intval}"{if $filters.id_comparison_group == $group.id_group} selected="selected"{/if}>
                {$group.name|escape:'htmlall':'UTF-8'}
              </option>
            {/foreach}
          </select>
          <p class="help-block">{l s='Ajoute une seconde colonne de prix pour ce groupe.' mod='customcatalogonpdf'}</p>
        </div>
      </div>
    </div>

    <div class="row purchase-price-options">
      <div class="col-lg-4">
        <div class="checkbox">
          <label>
            <input
              type="checkbox"
              name="include_purchase_price"
              id="include_purchase_price"
              value="1"
              {if $filters.purchase_price_requested} checked="checked"{/if}
            >
            {l s='Ajouter le prix d’achat HT' mod='customcatalogonpdf'}
          </label>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="form-group">
          <label for="id_supplier">{l s='Fournisseur' mod='customcatalogonpdf'}</label>
          <select name="id_supplier" id="id_supplier" class="form-control">
            <option value="0">{l s='— Sélectionner un fournisseur —' mod='customcatalogonpdf'}</option>
            {foreach from=$suppliers item=supplier}
              <option value="{$supplier.id_supplier|intval}"{if $filters.id_supplier == $supplier.id_supplier} selected="selected"{/if}>
                {$supplier.name|escape:'htmlall':'UTF-8'}
              </option>
            {/foreach}
          </select>
        </div>
      </div>
    </div>

    <div class="panel-footer">
      <button type="submit" name="preview_prices" class="btn btn-default">
        <i class="icon-search"></i> {l s='Afficher les tarifs' mod='customcatalogonpdf'}
      </button>
      <button type="submit" name="export_csv" class="btn btn-default" {if !$has_primary_price}disabled="disabled"{/if}>
        <i class="icon-file-text"></i> {l s='Exporter en CSV' mod='customcatalogonpdf'}
      </button>
      <button type="submit" name="export_xlsx" class="btn btn-primary" {if !$has_primary_price}disabled="disabled"{/if}>
        <i class="icon-file-excel-o"></i> {l s='Exporter en Excel' mod='customcatalogonpdf'}
      </button>
    </div>
  </form>
</div>

{if $headers}
  <div class="panel">
    <div class="panel-heading">
      <i class="icon-list"></i>
      {l s='Aperçu' mod='customcatalogonpdf'}
      <span class="badge">{$rows|count}</span>
    </div>
    <div class="table-responsive">
      <table class="table table-striped">
        <thead>
          <tr>
            {foreach from=$headers item=header}
              <th>{$header.label|escape:'htmlall':'UTF-8'}</th>
            {/foreach}
          </tr>
        </thead>
        <tbody>
          {foreach from=$rows item=row}
            <tr>
              {foreach from=$row item=value}
                <td>{$value|escape:'htmlall':'UTF-8'}</td>
              {/foreach}
            </tr>
          {foreachelse}
            <tr>
              <td colspan="{$headers|count}" class="text-center">
                {l s='Aucun produit actif trouvé.' mod='customcatalogonpdf'}
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  </div>
{/if}
