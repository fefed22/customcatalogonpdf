const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(require('node:path').join(__dirname, '../views/js/admin-tarif.js'), 'utf8');
const handlers = {};
const cards = [
  { product: '2:21', present: true },
  { product: '2:22', present: false }
];
let request;
let appended;
let fail = false;
let onlyMissing = false;
let reloadPage;

function collection(items) {
  return {
    find() { return { prop(name, value) { items.forEach(card => { card.disabled = value; }); } }; },
    addClass() { items.forEach(card => { card.present = true; }); return this; },
    filter(selector) { return collection(items.filter(card => selector.includes('"' + card.product + '"'))); },
    each(callback) { items.forEach(card => callback.call(card)); }
  };
}

const context = vm.createContext({
  parseInt,
  escapeHtml: value => String(value == null ? '' : value).replace(/</g, '&lt;').replace(/>/g, '&gt;'),
  $browserProducts: {
    on(selector, target, handler) { handlers[target] = handler; },
    find() { return collection(cards); }
  },
  $browserStatus: {
    removeClass() { return this; },
    text(value) { this.message = value; }
  },
  $: value => {
    if (value === '#tarif-browser-only-missing') {
      return { prop: () => onlyMissing };
    }
    if (value.trigger) {
      return {
        closest: () => context.$(cards[1]),
        hasClass: name => value.trigger === 'all' && name === 'tarif-product-add-all'
      };
    }
    return {
      attr: name => name === 'data-id-product' ? '2' : value.product,
      hasClass: () => value.present,
      find: selector => selector === 'select'
        ? { val: () => '7' }
        : {
          prop(name, state) { value.disabled = state; },
          text(message) { value.message = message; }
        }
    };
  },
  appendLines: (lines, section) => { appended = { lines, section }; },
  loadBrowser: page => { reloadPage = page; },
  api: (action, data, callback) => {
    request = { action, data };
    if (!fail) callback({ lines: [{ id_line: 5 }], skipped: data.product.endsWith(':all') ? 1 : 0 });
    return { always(callback) { callback(); } };
  }
});

vm.runInContext(
  source.slice(source.indexOf('    function renderProductCard('), source.indexOf('    function loadBrowser('))
  + source.slice(source.indexOf("    $browserProducts.on('click'"), source.indexOf('    // ── Ajout de ligne')),
  context
);

const product = { id: '2:22', id_product: 2, combination_count: 2, active: false, name: '<Test>' };
const html = context.renderProductCard(product, 1);
assert.match(html, /tarif-product-add-all/);
assert.match(html, /data-id-product="2"/);
assert.match(html, /Inactif/);
assert.match(html, /&lt;Test&gt;/);
assert.doesNotMatch(context.renderProductCard({ ...product, combination_count: 0 }, 1), /tarif-product-add-all/);
console.log('PASS: bulk action only on variant cards; inactive status and escaping preserved.');

const click = handlers['.tarif-product-add, .tarif-product-add-all'];
click.call({ trigger: 'all' });
assert.equal(request.action, 'AddLine');
assert.equal(request.data.product, '2:all');
assert.equal(request.data.id_section, 7);
assert.equal(appended.section, 7);
assert.equal(appended.lines.length, 1);
assert(cards.every(card => card.present && card.disabled));
assert.match(context.$browserStatus.message, /1 déclinaison/);
console.log('PASS: bulk request uses parent and chosen section, appends lines, greys all variants and reports skipped duplicates.');

cards[1].present = false;
fail = true;
click.call({ trigger: 'all' });
assert.equal(cards[1].present, false);
assert.equal(cards[1].disabled, false);
assert.equal(cards[0].disabled, true);
console.log('PASS: failed bulk addition restores available controls without enabling existing variants.');

fail = false;
cards[0].present = false;
click.call({ trigger: 'single' });
assert.equal(request.data.product, '2:22');
assert.equal(cards[0].present, false);
assert.equal(cards[0].disabled, false);
assert.equal(cards[1].present, true);
console.log('PASS: individual addition still only marks the chosen variant.');

onlyMissing = true;
cards[1].present = false;
click.call({ trigger: 'all' });
assert.equal(reloadPage, 1);
console.log('PASS: additions refresh missing-only results from page one.');
reloadPage = undefined;
fail = true;
click.call({ trigger: 'all' });
assert.equal(reloadPage, undefined);
console.log('PASS: failed additions do not refresh missing-only results.');
