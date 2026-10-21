/*
 * Weaver-ID password rules and strength meter (browser copy).
 *
 * The server decides: app/Support/PasswordPolicy.php checks every password again.
 * This file only gives live feedback. tests/fixtures/password-vectors.json is run
 * against both copies (php: tests/Unit/PasswordPolicyTest.php, js: tests/js/password-policy.check.cjs)
 * so the rules cannot drift apart.
 *
 * Level: Weak = a rule is not met. Medium = every rule is met (12 to 15 characters).
 * Strong = every rule is met and the password has 16 characters or more.
 * It measures rule compliance and length, not how fast a password could be cracked.
 */
(function (root, factory) {
  root.WeaverPassword = factory();
}(self, function () {
  'use strict';

  var MIN_LENGTH = 12;
  var STRONG_LENGTH = 16;
  var ORDER = ['length', 'lower', 'upper', 'number', 'symbol', 'common', 'personal'];

  var LABELS = {
    length: 'At least 12 characters',
    lower: 'A lowercase letter',
    upper: 'An uppercase letter',
    number: 'A number',
    symbol: 'A symbol, such as ! @ # ?',
    common: 'Not a common password or pattern',
    personal: 'Does not contain your name or email'
  };

  // Same wording as the server, so the meter and the server's error say the same thing.
  var MESSAGES = {
    length: 'Use at least 12 characters.',
    lower: 'Add a lowercase letter.',
    upper: 'Add an uppercase letter.',
    number: 'Add a number.',
    symbol: 'Add a symbol, such as ! @ # ?',
    common: 'Avoid common passwords and patterns such as password, 123456 or aaaa.',
    personal: 'Do not include your name or email in the password.'
  };

  var COMMON = [
    'password', 'passw0rd', 'pssw0rd', 'qwerty', 'asdfgh', 'zxcvbn', '123456', '654321',
    '111111', 'abcdef', 'letmein', 'welcome', 'admin', 'weaverid', 'iloveyou', 'bontoc',
    'mountainprovince'
  ];

  function count(text) { return Array.from(text).length; }

  function plain(text) { return text.replace(/[^\p{L}\p{N}]+/gu, ''); }

  function isCommon(lower, plainText) {
    if (/(.)\1{3,}/su.test(lower)) { return true; }
    return COMMON.some(function (word) { return plainText.indexOf(word) !== -1; });
  }

  // Words of four letters or more from the name and the part of the email before the @.
  function personalTokens(context) {
    var tokens = [];
    var email = String((context && context.email) || '');
    if (email) {
      var local = email.split('@')[0].toLowerCase();
      tokens.push(plain(local));
      local.split(/[^\p{L}\p{N}]+/u).forEach(function (part) { if (part) { tokens.push(part); } });
    }
    var name = String((context && context.name) || '').toLowerCase();
    name.split(/[^\p{L}\p{N}]+/u).forEach(function (word) { if (word) { tokens.push(word); } });
    return tokens.filter(function (t) { return count(t) >= 4; });
  }

  function evaluate(password, context) {
    password = String(password || '');
    var lower = password.toLowerCase();
    var plainText = plain(lower);
    var passed = {
      length: count(password) >= MIN_LENGTH,
      lower: /\p{Ll}/u.test(password),
      upper: /\p{Lu}/u.test(password),
      number: /[0-9]/.test(password),
      symbol: /[^\p{L}\p{N}\s]/u.test(password),
      common: !isCommon(lower, plainText),
      personal: !personalTokens(context).some(function (t) { return plainText.indexOf(t) !== -1; })
    };
    var failed = ORDER.filter(function (id) { return !passed[id]; });
    var level = failed.length ? 'weak' : (count(password) >= STRONG_LENGTH ? 'strong' : 'medium');
    return {
      failed: failed,
      level: level,
      ok: failed.length === 0,
      length: count(password),
      rules: ORDER.map(function (id) { return { id: id, label: LABELS[id], message: MESSAGES[id], ok: passed[id] }; })
    };
  }

  // ------------------------------------------------------------------ meter widget
  var styled = false;
  function addStyles() {
    if (styled || typeof document === 'undefined') { return; }
    styled = true;
    var css = '.wpw-bar{display:flex;gap:4px;margin:.35rem 0}' +
      '.wpw-bar span{flex:1;height:6px;border-radius:3px;background:#e5e7eb}' +
      '.wpw-weak .wpw-bar span:nth-child(1){background:#b42318}' +
      '.wpw-medium .wpw-bar span:nth-child(-n+2){background:#9a5b00}' +
      '.wpw-strong .wpw-bar span{background:#0e523a}' +
      '.wpw-level{font-size:.85rem}.wpw-weak .wpw-word{color:#b42318}.wpw-medium .wpw-word{color:#9a5b00}.wpw-strong .wpw-word{color:#0e523a}' +
      '.wpw-rules{list-style:none;padding:0;margin:.3rem 0 0;font-size:.8rem;line-height:1.4;columns:2}' +
      '.wpw-rules li{break-inside:avoid;color:#64748b}.wpw-rules li.ok{color:#0e523a}' +
      '.wpw-rules i{margin-right:.35rem}' +
      '@media (max-width:575.98px){.wpw-rules{columns:1}}';
    var el = document.createElement('style');
    el.textContent = css;
    document.head.appendChild(el);
  }

  /**
   * Shows the level and the rule checklist under a password field.
   *   input   the password <input>
   *   host    an empty element to draw the meter in
   *   options { context: function () { return { email, name }; }, watch: [other inputs that change the result] }
   * Returns { update(), evaluate() }.
   */
  function attachMeter(input, host, options) {
    options = options || {};
    addStyles();
    host.innerHTML = '<div class="wpw wpw-empty" aria-live="polite">' +
      '<div class="wpw-bar" aria-hidden="true"><span></span><span></span><span></span></div>' +
      '<div class="wpw-level"></div>' +
      '<ul class="wpw-rules">' + ORDER.map(function (id) {
        return '<li data-rule="' + id + '"><i class="bi bi-circle"></i>' + LABELS[id] + '</li>';
      }).join('') + '</ul></div>';
    var box = host.firstChild;
    var levelEl = box.querySelector('.wpw-level');

    function current() {
      return evaluate(input.value, options.context ? options.context() : {});
    }

    function update() {
      var r = current();
      var empty = input.value === '';
      box.className = 'wpw ' + (empty ? 'wpw-empty' : 'wpw-' + r.level);
      Array.prototype.forEach.call(box.querySelectorAll('li'), function (li, i) {
        var ok = !empty && r.rules[i].ok;
        li.className = ok ? 'ok' : '';
        li.querySelector('i').className = ok ? 'bi bi-check-circle-fill' : 'bi bi-circle';
      });
      if (empty) {
        levelEl.textContent = 'Type a password to see how strong it is.';
      } else if (r.level === 'weak') {
        levelEl.innerHTML = '<strong class="wpw-word">Weak</strong> <span class="text-muted"></span>';
        levelEl.lastChild.textContent = ' ' + MESSAGES[r.failed[0]];
      } else if (r.level === 'medium') {
        levelEl.innerHTML = '<strong class="wpw-word">Medium</strong> <span class="text-muted"> Accepted. Use 16 or more characters to make it Strong.</span>';
      } else {
        levelEl.innerHTML = '<strong class="wpw-word">Strong</strong> <span class="text-muted"> Long passwords are the hardest to guess.</span>';
      }
    }

    input.addEventListener('input', update);
    (options.watch || []).forEach(function (el) { if (el) { el.addEventListener('input', update); } });
    update();
    return { update: update, evaluate: current };
  }

  return {
    MIN_LENGTH: MIN_LENGTH,
    STRONG_LENGTH: STRONG_LENGTH,
    ORDER: ORDER,
    LABELS: LABELS,
    MESSAGES: MESSAGES,
    evaluate: evaluate,
    attachMeter: attachMeter
  };
}));
