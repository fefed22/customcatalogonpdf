const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../views/js/admin-tarif.js'), 'utf8');
const start = source.indexOf("    $('.tarif-validate').on(");
const handler = source.slice(start, source.indexOf('\n  });\n})(jQuery);', start));

function run({ answers = [true, true], target = 'group', fail = '', client = 10, malformed = '' } = {}) {
  let click;
  let disabled = false;
  let reloaded = false;
  const requests = [];
  const confirms = [];
  const alerts = [];
  const button = { data: key => ({ target, 'id-target': target === 'group' ? 3 : 10, 'target-name': 'Test' })[key] };
  const buttons = { prop(name, value) { assert.equal(name, 'disabled'); disabled = value; } };
  const context = vm.createContext({
    $: selector => {
      if (selector === button) return button;
      if (selector === '.tarif-validate') return { on(event, callback) { click = callback; } };
      if (selector === '.tarif-validate:not(:disabled)') return buttons;
      if (selector === '#tarif_id_customer') return { val: () => client };
      if (selector === '#tarif-validation') return { data: () => 10 };
      throw new Error('Unexpected selector: ' + selector);
    },
    window: {
      confirm(message) { confirms.push(message); return answers[confirms.length - 1]; },
      alert(message) { alerts.push(message); },
      location: { reload() { reloaded = true; } }
    },
    api(action, data, callback) {
      assert.equal(disabled, true);
      requests.push({ action, data });
      if (action !== fail) {
        callback(action === malformed ? {} : action === 'PrepareTarifValidation'
          ? { success: true, target_name: 'Confirmed target', id_target: target === 'group' ? 3 : 10, line_count: 7, confirmation_token: 'signed' }
          : { success: true });
      }
      return { always(callback) { callback(); } };
    }
  });
  vm.runInContext(handler, context);
  click.call(button);
  return { requests, confirms, alerts, disabled, reloaded };
}

assert.equal(run({ answers: [false] }).requests.length, 0);
const cancelled = run({ answers: [true, false] });
assert.equal(cancelled.requests.length, 1);
assert.equal(cancelled.requests[0].action, 'PrepareTarifValidation');
assert.equal(cancelled.reloaded, false);
assert.equal(cancelled.disabled, false);
console.log('PASS: cancelling either confirmation never applies the tariff.');

for (const target of ['customer', 'group']) {
  const result = run({ target });
  assert.equal(result.confirms.length, 2);
  assert.match(result.confirms[1], /Confirmed target/);
  assert.match(result.confirms[1], /Lignes concernées : 7/);
  assert.equal(result.requests[1].action, 'ValidateTarif');
  assert.equal(result.requests[1].data.validation_target, target);
  assert.equal(result.requests[1].data.confirmation_token, 'signed');
  assert.equal(result.requests[1].data.confirmed, 1);
  assert.equal(result.reloaded, true);
}
console.log('PASS: both targets require two confirmations with the server-confirmed target and line count.');

for (const fail of ['PrepareTarifValidation', 'ValidateTarif']) {
  const result = run({ fail });
  assert.equal(result.disabled, false);
  assert.equal(result.reloaded, false);
}
assert.equal(run({ client: 11 }).requests.length, 0);
assert.equal(run({ client: 11 }).alerts.length, 1);
console.log('PASS: failures restore buttons and unsaved customer changes block validation.');

for (const malformed of ['PrepareTarifValidation', 'ValidateTarif']) {
  const result = run({ malformed });
  assert.equal(result.reloaded, false);
  assert.equal(result.disabled, false);
  assert.equal(result.alerts.length, 1);
}
console.log('PASS: malformed responses are reported rather than treated as successful validation.');

const template = fs.readFileSync(path.join(__dirname, '../views/templates/admin/tarif_editor.tpl'), 'utf8');
assert.equal((template.match(/class="btn btn-(success|warning) tarif-validate"/g) || []).length, 2);
assert.match(template, /Valider le tarif sur le client/);
assert.match(template, /Valider le tarif sur le groupe/);
assert.match(template, /Groupe de clients par défaut/);
assert.match(template, /Historique des validations/);
assert.match(template, /\$validation\.date_add\|escape/);
assert.match(template, /\$validation\.target_name\|escape/);
assert.match(template, /\$validation\.employee_name\|escape/);
console.log('PASS: the editor exposes exactly two validation buttons, the default group and an escaped dated history.');
