const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../views/js/admin-tarif.js'), 'utf8');
const events = {};
const values = {
  '.tarif-reduction': '20',
  '.tarif-additional-reduction': '10',
  '.tarif-final': '80'
};
const texts = {};
const requests = [];
let row;
let publicAttr = '';
function field(selector) {
  return {
    val(value) {
      if (value === undefined) return values[selector];
      values[selector] = value;
      return this;
    },
    text(value) { texts[selector] = value; return this; },
    closest() { return row; }
  };
}
row = {
  length: 1,
  attr(name, value) {
    if (name === 'data-public-price') {
      if (value === undefined) return publicAttr;
      publicAttr = value;
      return this;
    }
    return 100;
  },
  data: () => 17,
  find: field
};
const context = vm.createContext({
  $: selector => {
    if (selector === '#tarif-sections') {
      return { on(event, target, callback) { events[event + ':' + target] = callback; } };
    }
    if (typeof selector === 'string' && selector.startsWith('.tarif-line[')) return row;
    return selector;
  },
  parseNum: value => parseFloat(String(value).replace(',', '.')) || 0,
  fmt: value => Number(value || 0).toFixed(2),
  fmtOptional: value => value == null || value === '' ? '' : Number(value).toFixed(2),
  updateSourceBadge() {},
  api(action, data, callback) {
    requests.push({ action, data });
    if (action === 'UpdatePublicPrice' && apiResponse) callback(apiResponse);
    return { always(done) { done(); } };
  }
});
let apiResponse = null;
const start = source.indexOf('    function getBase(');
vm.runInContext(source.slice(start, source.indexOf('    // ── Suppression de ligne', start)), context);

context.updatePricePreview(row);
assert.equal(values['.tarif-final'], '72.00');
assert.equal(texts['.tarif-intermediate'], '80.00 €');
assert.equal(texts['.tarif-total-reduction'], '28.00 %');
console.log('PASS: the live preview applies successive discounts and labels both prices and the 28% total.');

values['.tarif-final'] = '63';
events['input:.tarif-final'].call(field('.tarif-final'));
assert.equal(values['.tarif-reduction'], '30.0000');
assert.equal(values['.tarif-additional-reduction'], '10');
assert.equal(values['.tarif-final'], '63');
assert.equal(texts['.tarif-intermediate'], '70.00 €');
assert.equal(texts['.tarif-total-reduction'], '37.00 %');
events['change:.tarif-final'].call(field('.tarif-final'));
assert.equal(requests[0].data.mode, 'price');
assert.equal(requests[0].data.value, '63');
console.log('PASS: editing the net price changes the first discount only and saves the net-price mode.');

events['change:.tarif-additional-reduction'].call(field('.tarif-additional-reduction'));
assert.equal(requests[1].data.mode, 'additional_reduction');
context.applyLineResult({
  id_line: 17, reduction_percent: 20, additional_reduction_percent: 10,
  intermediate_price: 80, final_price: 72, total_reduction_percent: 28
});
assert.equal(values['.tarif-reduction'], '20.0000');
assert.equal(values['.tarif-additional-reduction'], '10.0000');
assert.equal(values['.tarif-final'], '72.00');
assert.equal(texts['.tarif-intermediate'], '80.00 €');
assert.equal(texts['.tarif-total-reduction'], '28.00 %');
console.log('PASS: server responses update every discount and price without mixing the intermediate and final amounts.');

values['.tarif-additional-reduction'] = '100';
context.updatePricePreview(row);
assert.equal(values['.tarif-final'], '0.00');
assert.equal(texts['.tarif-total-reduction'], '100.00 %');
events['input:.tarif-final'].call(field('.tarif-final'));
assert.equal(values['.tarif-reduction'], '20.0000');
console.log('PASS: a full additional discount has a zero net price and does not trigger a division by zero.');

context.window = { confirm: () => true, alert(message) { throw new Error(message); } };
const sectionInput = { val: () => '7,5' };
const section = {
  data: () => 4,
  find: selector => selector === '.tarif-line' ? { length: 2 } : sectionInput
};
const sectionHandler = events['click:.tarif-apply-section-reduction, .tarif-apply-section-additional'];
for (const additional of [false, true]) {
  const button = { hasClass: () => additional, closest: () => section };
  sectionHandler.call(button);
  const request = requests[requests.length - 1];
  assert.equal(request.action, 'ApplySectionReduction');
  assert.equal(request.data.mode, additional ? 'additional_reduction' : 'reduction');
  assert.equal(request.data.id_section, 4);
  assert.equal(request.data.value, 7.5);
}
console.log('PASS: both section buttons post the proper discount mode and accept French decimal separators.');

// ── Prix public conseillé ──────────────────────────────────────────────
values['.tarif-reduction'] = '20';
values['.tarif-additional-reduction'] = '0';
values['.tarif-public-price'] = '';
context.updatePricePreview(row);
assert.equal(values['.tarif-final'], '80.00');
assert.equal(values['.tarif-public-markup'], '');
values['.tarif-public-markup'] = '25';
events['input:.tarif-public-markup'].call(field('.tarif-public-markup'));
assert.equal(values['.tarif-public-price'], '100.00');
values['.tarif-public-price'] = '120';
events['input:.tarif-public-price'].call(field('.tarif-public-price'));
assert.equal(values['.tarif-public-markup'], '50.00');
console.log('PASS: the markup and the public price update each other live from the net price.');

values['.tarif-final'] = '60';
events['input:.tarif-final'].call(field('.tarif-final'));
assert.equal(values['.tarif-public-price'], '120');
assert.equal(values['.tarif-public-markup'], '100.00');
values['.tarif-reduction'] = '50';
context.updatePricePreview(row);
assert.equal(values['.tarif-final'], '50.00');
assert.equal(values['.tarif-public-markup'], '140.00');
console.log('PASS: changing the net price or a discount keeps the shared public price and recalculates the markup.');

values['.tarif-public-markup'] = '';
events['input:.tarif-public-markup'].call(field('.tarif-public-markup'));
assert.equal(values['.tarif-public-price'], '');
values['.tarif-final'] = '0';
values['.tarif-public-price'] = '30';
events['input:.tarif-public-price'].call(field('.tarif-public-price'));
assert.equal(values['.tarif-public-markup'], '');
console.log('PASS: empty values and free net prices leave the markup empty without dividing by zero.');

apiResponse = { line: { public_price: 99.5, public_markup_percent: 24.375 } };
values['.tarif-public-markup'] = ' 24,375 ';
events['change:.tarif-public-markup'].call(field('.tarif-public-markup'));
let request = requests[requests.length - 1];
assert.equal(request.action, 'UpdatePublicPrice');
assert.deepEqual({ ...request.data }, { id_line: 17, mode: 'public_markup', value: '24,375' });
assert.equal(publicAttr, '99.50');
assert.equal(values['.tarif-public-price'], '99.50');
assert.equal(values['.tarif-public-markup'], '24.38');
values['.tarif-public-price'] = '';
events['change:.tarif-public-price'].call(field('.tarif-public-price'));
request = requests[requests.length - 1];
assert.equal(request.data.mode, 'public_price');
assert.equal(request.data.value, '');
console.log('PASS: public price and markup edits are saved with their mode and display the server values.');

apiResponse = null;
values['.tarif-final'] = '79.6';
values['.tarif-public-price'] = '500';
events['change:.tarif-public-price'].call(field('.tarif-public-price'));
assert.equal(values['.tarif-public-price'], '99.50');
assert.equal(values['.tarif-public-markup'], '25.00');
console.log('PASS: a rejected public price restores the last saved value and its markup.');

context.applyLineResult({
  id_line: 17, reduction_percent: 25, additional_reduction_percent: 0, intermediate_price: 75,
  final_price: 75, total_reduction_percent: 25, public_price: 99.5, public_markup_percent: 32.6667
});
assert.equal(values['.tarif-public-markup'], '32.67');
assert.equal(values['.tarif-public-price'], '99.50');
console.log('PASS: discount and net-price server responses refresh the markup.');

const renderContext = vm.createContext({
  escapeHtml: value => value || '',
  fmt: value => Number(value || 0).toFixed(2),
  fmtOptional: value => value == null || value === '' ? '' : Number(value).toFixed(2),
  renderBadges: () => ''
});
const renderStart = source.indexOf('    function renderLineRow(');
vm.runInContext(source.slice(renderStart, source.indexOf("    $('#tarif-sections').on('click', '.tarif-toggle-section'")), renderContext);
const lineHtml = renderContext.renderLineRow({
  id_line: 1, reduction_percent: 20, additional_reduction_percent: 10,
  intermediate_price: 80, final_price: 72, total_reduction_percent: 28
});
assert.match(lineHtml, /class="tarif-total-reduction"[^>]*>28.00 %<\/output>/);
assert.match(lineHtml, /class="tarif-intermediate">80.00/);
assert.match(lineHtml, /class="form-control tarif-final"[^>]*value="72.00"/);
assert.match(lineHtml, /class="form-control tarif-additional-reduction"[^>]*value="10.0000"/);
const sectionHtml = renderContext.renderSectionBlock({ id_section: 7, title: 'New' });
assert.match(sectionHtml, /tarif-apply-section-additional/);
assert.match(sectionHtml, /tarif-apply-section-reduction/);
assert.match(sectionHtml, /Remise totale %/);
const pricedHtml = renderContext.renderLineRow({ id_line: 2, final_price: 80, public_price: 100, public_markup_percent: 25 });
assert.match(pricedHtml, /data-public-price="100.00"/);
assert.match(pricedHtml, /class="form-control tarif-public-markup"[^>]*value="25.00"/);
assert.match(pricedHtml, /class="form-control tarif-public-price"[^>]*value="100.00"/);
assert.match(lineHtml, /class="form-control tarif-public-price"[^>]*value=""/);
for (const group of ['Catalogue', 'Remises client', 'Prix net client', 'Prix public conseillé']) {
  assert.match(sectionHtml, new RegExp('class="tarif-head-group[^"]*"[^>]*>' + group + '<'));
}
assert.match(sectionHtml, /Majoration %/);
assert.match(sectionHtml, /PVC HT/);
const cellCount = html => (html.match(/class="tarif-cell /g) || []).length;
assert.equal(cellCount(lineHtml), 13);
assert.equal(cellCount(sectionHtml.slice(sectionHtml.indexOf('tarif-lines-head'))), 13);
console.log('PASS: dynamic rows and headers show grouped columns with the markup and public price cells.');
console.log('PASS: dynamically added rows and sections include both discounts, a read-only total and both section actions.');

const template = fs.readFileSync(path.join(__dirname, '../views/templates/admin/tarif_editor.tpl'), 'utf8');
assert.match(template, /<output class="tarif-total-reduction"/);
assert.match(template, /tarif-additional-reduction/);
assert.match(template, /tarif-apply-section-additional/);
assert.match(template, /Net après les deux remises/);
assert.match(template, /Prix net final, modifie la première remise/);
assert.match(template, /id="tarif-show-public-price"[^>]*\{if \$show_public_price\}checked\{\/if\}/);
assert.match(template, /class="form-control tarif-public-markup"/);
assert.match(template, /class="form-control tarif-public-price"/);
assert.match(template, /data-public-price=/);
const headStart = template.indexOf('<div class="tarif-lines-head"');
const head = template.slice(headStart, template.indexOf('</div>', headStart));
assert.equal(cellCount(head), 13);
assert.equal((head.match(/class="tarif-head-group /g) || []).length, 4);
const lineStart = template.indexOf('<div class="tarif-line" ');
const savedLine = template.slice(lineStart, template.indexOf('{/foreach}', lineStart));
assert.equal(cellCount(savedLine), 13);
console.log('PASS: saved rows match dynamic rows and offer the public price export option.');

const css = fs.readFileSync(path.join(__dirname, '../views/css/admin-tarif.css'), 'utf8');
const grid = css.match(/\.tarif-line \{\s*display: grid;\s*grid-template-columns:([^;]+);/)[1].trim().split(/\s+(?![^(]*\))/);
assert.equal(grid.length, 13);
assert.match(css, /\.tarif-lines-head \{[^}]*position: sticky;[^}]*top: 0;/);
assert.match(css, /#tarif-sections \{[^}]*overflow: auto;/);
console.log('PASS: the grid has one column per cell and the column header stays visible while scrolling.');

let toggle;
let toggleResult = true;
const toggleCalls = [];
const checkbox = {
  checked: true,
  disabled: false,
  prop(name, value) {
    if (value === undefined) return this[name];
    this[name] = value;
    return this;
  }
};
const toggleContext = vm.createContext({
  $: value => value === '#tarif-show-public-price'
    ? { on(event, callback) { toggle = callback; } }
    : value,
  api(action, data, callback) {
    toggleCalls.push({ action, data, disabled: checkbox.disabled });
    if (toggleResult) callback({ show_public_price: Boolean(data.show_public_price) });
    return { always(done) { done(); } };
  }
});
const toggleStart = source.indexOf("    $('#tarif-show-public-price').on(");
vm.runInContext(source.slice(toggleStart, source.indexOf('    // ── Rafraîchir les prix', toggleStart)), toggleContext);
toggle.call(checkbox);
assert.equal(toggleCalls[0].action, 'SetShowPublicPrice');
assert.equal(toggleCalls[0].data.show_public_price, 1);
assert.equal(toggleCalls[0].disabled, true);
assert.equal(checkbox.checked, true);
assert.equal(checkbox.disabled, false);
toggleResult = false;
checkbox.checked = false;
toggle.call(checkbox);
assert.equal(toggleCalls[1].data.show_public_price, 0);
assert.equal(checkbox.checked, true);
assert.equal(checkbox.disabled, false);
console.log('PASS: the export option is saved per tariff and restored when saving fails.');
console.log('PASS: saved rows and dynamic rows offer the same discount and net-price controls.');
