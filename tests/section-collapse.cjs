const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'views/js/admin-tarif.js'), 'utf8');
const template = fs.readFileSync(path.join(root, 'views/templates/admin/tarif_editor.tpl'), 'utf8');
const css = fs.readFileSync(path.join(root, 'views/css/admin-tarif.css'), 'utf8');
let click;
function button() {
  const section = {
    classes: new Set(),
    lines: [11, 12],
    hasClass(name) { return this.classes.has(name); },
    toggleClass(name, enabled) {
      if (enabled) this.classes.add(name);
      else this.classes.delete(name);
      return this;
    }
  };
  const icon = {
    classes: new Set(['icon-chevron-down']),
    toggleClass: section.toggleClass
  };
  return {
    section,
    icon,
    label: 'Replier',
    attrs: { 'aria-expanded': 'true', 'data-expand-label': 'Déplier', 'data-collapse-label': 'Replier' },
    closest() { return section; },
    attr(name, value) {
      if (value === undefined) return this.attrs[name];
      this.attrs[name] = value;
      return this;
    },
    find(selector) {
      return selector === 'i' ? icon : { text: value => { this.label = value; } };
    }
  };
}
const context = vm.createContext({
  parseInt,
  escapeHtml: value => String(value).replace(/</g, '&lt;'),
  $: value => value === '#tarif-sections'
    ? { on(event, selector, callback) {
      assert.equal(event, 'click');
      assert.equal(selector, '.tarif-toggle-section');
      click = callback;
    } }
    : value
});
vm.runInContext(source.slice(source.indexOf('    function renderSectionBlock('), source.indexOf('    // ── Sortables')), context);

const first = button();
const second = button();
click.call(first);
assert(first.section.hasClass('is-collapsed'));
assert.equal(first.attrs['aria-expanded'], 'false');
assert.equal(first.label, 'Déplier');
assert(first.icon.classes.has('icon-chevron-right'));
assert(!first.icon.classes.has('icon-chevron-down'));
assert(!second.section.hasClass('is-collapsed'));
assert.deepEqual(first.section.lines, [11, 12]);
click.call(first);
assert(!first.section.hasClass('is-collapsed'));
assert.equal(first.attrs['aria-expanded'], 'true');
assert.equal(first.label, 'Replier');
assert(first.icon.classes.has('icon-chevron-down'));
assert(!first.icon.classes.has('icon-chevron-right'));
console.log('PASS: sections collapse independently and expand again with correct labels, icons and accessibility state; lines are preserved.');

const html = context.renderSectionBlock({ id_section: 7, title: '<New>' });
assert.match(html, /tarif-toggle-section/);
assert.match(html, /aria-expanded="true"/);
assert.match(html, /aria-controls="tarif-section-head-7 tarif-section-lines-7"/);
assert.match(html, /id="tarif-section-head-7"/);
assert.match(html, /id="tarif-section-lines-7"/);
assert.match(html, /&lt;New>/);
const headerStart = template.indexOf('<div class="tarif-section-header">');
assert(template.indexOf('tarif-toggle-section', headerStart)
  < template.indexOf('{if $section.id_section > 0}', headerStart));
assert.match(template, /id="tarif-section-head-\{\$section.id_section\|intval\}"/);
assert.match(template, /id="tarif-section-lines-\{\$section.id_section\|intval\}"/);
assert.match(css, /\.tarif-section\.is-collapsed > \.tarif-lines-head,\s*\.customcatalog-tarif-editor \.tarif-section\.is-collapsed > \.tarif-lines\s*\{\s*display: none;/);
console.log('PASS: saved, unsectioned and dynamically created sections have toggle controls; CSS hides only product content.');
