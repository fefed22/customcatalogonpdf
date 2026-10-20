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
  attr: () => 100,
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
  updateSourceBadge() {},
  api(action, data) { requests.push({ action, data }); }
});
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

const renderContext = vm.createContext({
  escapeHtml: value => value || '',
  fmt: value => Number(value || 0).toFixed(2),
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
console.log('PASS: dynamically added rows and sections include both discounts, a read-only total and both section actions.');

const template = fs.readFileSync(path.join(__dirname, '../views/templates/admin/tarif_editor.tpl'), 'utf8');
assert.match(template, /<output class="tarif-total-reduction"/);
assert.match(template, /tarif-additional-reduction/);
assert.match(template, /tarif-apply-section-additional/);
assert.match(template, /Net après les deux remises/);
assert.match(template, /Prix net final, modifie la première remise/);
console.log('PASS: saved rows and dynamic rows offer the same discount and net-price controls.');
