const assert = require('assert');
const fs = require('fs');
const path = require('path');

const tpl = fs.readFileSync(path.join(__dirname, '../views/templates/admin/tarif_editor.tpl'), 'utf8');
const js = fs.readFileSync(path.join(__dirname, '../views/js/admin-tarif.js'), 'utf8');
const css = fs.readFileSync(path.join(__dirname, '../views/css/admin-tarif.css'), 'utf8');
let passed = 0;
function check(cond, msg) { assert.ok(cond, msg); console.log('PASS: ' + msg); passed++; }

const order = ['id="tarif-target-section"', 'id="tarif-add-section"', 'id="tarif-product-manufacturer"',
  'id="tarif-product-search"', 'id="tarif-add-line"', 'tarif-add-alternatives', 'id="tarif-browse-products"',
  'id="tarif-add-references"'].map((s) => tpl.indexOf(s));
check(order.every((i, k) => i > -1 && (k === 0 || i > order[k - 1])),
  'The toolbar shows section, brand and search steps, then the alternative add methods.');
check((tpl.match(/class="tarif-step-number"/g) || []).length === 3, 'The three combined steps are numbered.');
check(/id="tarif-add-section"[\s\S]{0,400}Rajouter section/.test(tpl), 'The section button is labelled "Rajouter section".');
check(/id="tarif-validation" class="is-collapsed"/.test(tpl), 'The validation block is collapsed by default.');
check(/tarif-toggle-validation"[^>]*aria-expanded="false"[^>]*aria-controls="tarif-validation-body"/.test(tpl)
  && tpl.includes('id="tarif-validation-body"'), 'The validation toggle is accessible and controls the body.');
check(css.includes('#tarif-validation.is-collapsed .tarif-validation-body'), 'The collapsed body is hidden by CSS.');

// Exécution du gestionnaire de bascule avec un mini-jQuery
const state = { collapsed: true, expanded: 'false', icon: 'icon-chevron-right' };
const handlers = {};
function $(sel) {
  if (sel === '.tarif-toggle-validation') return { on: (e, fn) => { handlers.toggle = fn; } };
  if (sel === '#tarif-validation') {
    return { hasClass: () => state.collapsed, toggleClass: (c, v) => { state.collapsed = v; } };
  }
  return {
    attr: (k, v) => { state.expanded = v; },
    find: () => ({ first: () => ({ toggleClass(c, v) { if (v) state.icon = c; return this; } }) }),
  };
}
const start = js.indexOf("    $('.tarif-toggle-validation').on(");
const end = js.indexOf("    $('.tarif-validate').on(");
check(start > -1 && end > start, 'The validation toggle is declared before the validation handler.');
new Function('$', js.slice(start, end))($);
handlers.toggle.call({});
check(!state.collapsed && state.expanded === 'true' && state.icon === 'icon-chevron-down', 'Clicking expands the validation block.');
handlers.toggle.call({});
check(state.collapsed && state.expanded === 'false' && state.icon === 'icon-chevron-right', 'Clicking again collapses it.');

const add = js.slice(js.indexOf("$('#tarif-add-section').on("), js.indexOf("$('#tarif-add-section').on(") + 900);
check(add.includes("$('#tarif-sections').prepend(") && !add.includes('.tarif-section-none').valueOf(),
  'A new section is inserted at the top of the products.');
check(/\$\('#tarif-target-section'\)\.val\(/.test(add), 'The new section becomes the destination section.');
check(js.includes("$('#tarif-references-section').val($('#tarif-target-section').val())"),
  'Bulk references reuse the chosen destination section.');
// Numérotation globale avec un mini-modèle de DOM
function makeLine(id) { return { kind: 'line', id, number: { text: '', title: '' } }; }
const model = [
  { kind: 'section', range: '', lines: [makeLine(1), makeLine(2)] },
  { kind: 'section', range: '', lines: [] },
  { kind: 'section', range: '', lines: [makeLine(3)] },
  { kind: 'section', range: '', lines: [makeLine(4), makeLine(5), makeLine(6)] },
];
function coll(items) {
  return {
    length: items.length,
    each(fn) { items.forEach((item, i) => fn.call(item, i)); return this; },
    index(other) { return items.indexOf(other.items[0]); },
    first() { return coll(items.slice(0, 1)); },
    items,
  };
}
function wrap(node) {
  return {
    find(sel) {
      if (sel === '.tarif-line-number') {
        const api = { text(v) { node.number.text = String(v); return api; }, attr(k, v) { node.number.title = v; return api; } };
        return api;
      }
      if (sel === '.tarif-lines > .tarif-line') return coll(node.lines);
      if (sel === '.tarif-section-range') return { text(v) { node.range = v; } };
      throw new Error(sel);
    },
  };
}
function $$(sel) {
  if (typeof sel !== 'string') return wrap(sel);
  if (sel === '#tarif-sections .tarif-lines > .tarif-line') return coll(model.flatMap((sec) => sec.lines));
  if (sel === '#tarif-sections > .tarif-section') return coll(model);
  throw new Error(sel);
}
const rStart = js.indexOf('    function renumberLines(');
const rEnd = js.indexOf('    // ── Sortables');
check(rStart > -1 && rEnd > rStart, 'The numbering helper is defined before the sortables.');
const renumberLines = new Function('$', js.slice(rStart, rEnd) + '\nreturn renumberLines;')($$);
renumberLines();
check(model.flatMap((sec) => sec.lines).map((l) => l.number.text).join(',') === '1,2,3,4,5,6',
  'Lines are numbered continuously across sections.');
check(model[2].lines[0].number.title === 'Ligne 3 sur 6', 'Each number tells the position out of the total.');
check(model[0].range === 'Lignes 1 à 2 sur 6 · 2 produits' && model[1].range === 'Aucune ligne · 6 au total'
  && model[2].range === 'Ligne 3 sur 6 · 1 produit' && model[3].range === 'Lignes 4 à 6 sur 6 · 3 produits',
  'Each section header shows its line range and the total.');
model[3].lines.splice(0, 1);
model[0].lines.unshift(model[2].lines.pop());
renumberLines();
check(model[0].lines[0].id === 3 && model[0].lines[0].number.text === '1' && model[3].lines[1].number.text === '5'
  && model[3].range === 'Lignes 4 à 5 sur 5 · 2 produits', 'Numbers follow moves and deletions.');
check(/function saveOrder\(\) \{\s*renumberLines\(\);/.test(js) && /function initSortables\(\) \{\s*renumberLines\(\);/.test(js)
  && /\$row\.remove\(\);\s*renumberLines\(\);/.test(js), 'Numbering is refreshed on load, add, move, section changes and delete.');
check(tpl.includes('<span class="tarif-line-number"></span>') && tpl.includes('<span class="tarif-section-range"'),
  'Saved rows and section headers have the numbering placeholders.');

console.log(passed + ' checks passed');
