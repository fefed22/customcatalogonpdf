const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../views/js/admin-tarif.js'), 'utf8');
let change;
let success = true;
let request;
const checkbox = {
  checked: true,
  disabled: false,
  prop(name, value) {
    if (value === undefined) return this[name];
    this[name] = value;
    return this;
  },
  closest: () => ({ data: () => 42 })
};
const context = vm.createContext({
  $: value => value === '#tarif-sections'
    ? { on(event, selector, callback) { change = callback; } }
    : value,
  api(action, data, callback) {
    request = { action, data };
    assert.equal(checkbox.disabled, true);
    if (success) callback({ line: { is_processed: Boolean(data.is_processed) } });
    return { always(callback) { callback(); } };
  }
});
const start = source.indexOf("    $('#tarif-sections').on('change', '.tarif-processed'");
vm.runInContext(source.slice(start, source.indexOf('    // ── Double saisie', start)), context);
change.call(checkbox);
assert.equal(request.action, 'SetLineProcessed');
assert.equal(request.data.id_line, 42);
assert.equal(request.data.is_processed, 1);
assert.equal(checkbox.checked, true);
assert.equal(checkbox.disabled, false);
checkbox.checked = false;
change.call(checkbox);
assert.equal(request.data.is_processed, 0);
assert.equal(checkbox.checked, false);
success = false;
checkbox.checked = true;
change.call(checkbox);
assert.equal(checkbox.checked, false);
assert.equal(checkbox.disabled, false);
checkbox.checked = false;
change.call(checkbox);
assert.equal(checkbox.checked, true);
assert.equal(checkbox.disabled, false);
console.log('PASS: processed check/uncheck saves the correct line, disables during requests and restores the previous state on failure.');

const renderStart = source.indexOf('    function renderLineRow(');
const renderContext = vm.createContext({
  escapeHtml: value => value || '',
  fmt: value => String(value || 0),
  renderBadges: () => '',
  fmtOptional: value => value == null ? '' : String(value),
});
vm.runInContext(source.slice(renderStart, source.indexOf('    function renderSectionBlock(', renderStart)), renderContext);
assert.match(renderContext.renderLineRow({ id_line: 1, is_processed: true }), /class="tarif-processed"[^>]* checked/);
assert.doesNotMatch(renderContext.renderLineRow({ id_line: 1, is_processed: false }), /class="tarif-processed"[^>]* checked/);
const template = fs.readFileSync(path.join(__dirname, '../views/templates/admin/tarif_editor.tpl'), 'utf8');
assert.match(template, /\{if \$line.is_processed\}checked\{\/if\}/);
assert.match(source, /line.is_processed \? ' checked' : ''/);
console.log('PASS: saved and newly rendered rows reflect their persisted processed status.');

const css = fs.readFileSync(path.join(__dirname, '../views/css/admin-tarif.css'), 'utf8');
assert.match(css, /\.tarif-lines > \.tarif-line:has\(\.tarif-processed:checked\)\s*\{\s*background: #e3f3e7;/);
assert(css.indexOf('.tarif-line:has(.tarif-processed:checked)') > css.indexOf('.tarif-line:hover'));
console.log('PASS: checked rows use a green background, overriding alternating and hover backgrounds; unchecking or failed saves restore the appropriate state.');
