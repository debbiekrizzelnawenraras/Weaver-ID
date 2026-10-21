/*
 * Weaver-ID account window: "Change password" for the signed-in person, and the
 * window that cannot be closed when an administrator chose the current password.
 * Load after api.js, password-policy.js and the Bootstrap bundle. It adds its own
 * window and the key button next to "Log out", so the pages need no extra markup.
 */
(function () {
  'use strict';

  var modalEl = null;
  var modal = null;
  var meter = null;
  var forcedOpen = false;

  function $(id) { return document.getElementById(id); }

  function context() {
    var u = Weaver.user() || {};
    return { email: u.email, name: u.name };
  }

  function build() {
    if (modalEl) { return; }
    var wrap = document.createElement('div');
    wrap.innerHTML =
      '<div class="modal fade" id="changePwModal" tabindex="-1" aria-labelledby="changePwTitle" aria-hidden="true">' +
      '<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><form class="modal-content" id="changePwForm" novalidate>' +
      '<div class="modal-header"><h5 class="modal-title fw-bold" id="changePwTitle">Change password</h5>' +
      '<button type="button" class="btn-close" id="cpClose" data-bs-dismiss="modal" aria-label="Close"></button></div>' +
      '<div class="modal-body">' +
      '<p class="text-muted fs-7 mb-3" id="cpIntro"></p>' +
      '<div class="mb-3"><label class="form-label fs-7 fw-semibold" for="cpCurrent">Current password</label>' +
      '<input type="password" class="form-control" id="cpCurrent" autocomplete="current-password"></div>' +
      '<div class="mb-2"><label class="form-label fs-7 fw-semibold" for="cpNew">New password</label>' +
      '<input type="password" class="form-control" id="cpNew" autocomplete="new-password"></div>' +
      '<div id="cpMeter" class="mb-3"></div>' +
      '<div class="mb-2"><label class="form-label fs-7 fw-semibold" for="cpConfirm">Confirm new password</label>' +
      '<input type="password" class="form-control" id="cpConfirm" autocomplete="new-password"></div>' +
      '<div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="cpShow">' +
      '<label class="form-check-label fs-7" for="cpShow">Show passwords</label></div>' +
      '<div id="cpMsg" class="alert py-2 fs-7 d-none" role="alert"></div>' +
      '</div>' +
      '<div class="modal-footer">' +
      '<button type="button" class="btn btn-link text-muted me-auto d-none" id="cpLogout">Log out instead</button>' +
      '<button type="button" class="btn btn-secondary" id="cpCancel" data-bs-dismiss="modal">Cancel</button>' +
      '<button type="submit" class="btn btn-dti" id="cpSave">Change password</button>' +
      '</div></form></div></div>';
    document.body.appendChild(wrap.firstChild);
    modalEl = $('changePwModal');

    meter = WeaverPassword.attachMeter($('cpNew'), $('cpMeter'), {
      context: context,
      watch: []
    });

    $('cpShow').addEventListener('change', function () {
      var type = this.checked ? 'text' : 'password';
      ['cpCurrent', 'cpNew', 'cpConfirm'].forEach(function (id) { $(id).type = type; });
    });
    $('cpLogout').addEventListener('click', function () { Weaver.logout(); });
    $('changePwForm').addEventListener('submit', onSubmit);
  }

  function message(text, kind) {
    var box = $('cpMsg');
    box.className = 'alert alert-' + (kind || 'danger') + ' py-2 fs-7';
    box.textContent = text;
  }

  function onSubmit(event) {
    event.preventDefault();
    var current = $('cpCurrent').value;
    var password = $('cpNew').value;
    var confirmation = $('cpConfirm').value;

    if (!current) { message('Enter your current password.'); return; }
    var check = WeaverPassword.evaluate(password, context());
    if (!check.ok) { message(WeaverPassword.MESSAGES[check.failed[0]]); return; }
    if (password !== confirmation) { message('The new password and its confirmation do not match.'); return; }
    if (password === current) { message('The new password must be different from the current one.'); return; }

    var save = $('cpSave');
    save.disabled = true;
    Weaver.changePassword(current, password, confirmation).then(function (res) {
      message(res.message || 'Password changed.', 'success');
      ['cpCurrent', 'cpNew', 'cpConfirm'].forEach(function (id) { $(id).disabled = true; });
      setTimeout(function () {
        if (forcedOpen) { window.location.reload(); } else { modal.hide(); }
      }, 1400);
    }).catch(function (err) {
      message(Weaver.errorText(err));
      save.disabled = false;
    });
  }

  function open(forced) {
    if (forcedOpen) { return; }
    build();
    forcedOpen = !!forced;

    ['cpCurrent', 'cpNew', 'cpConfirm'].forEach(function (id) {
      var el = $(id);
      el.value = '';
      el.disabled = false;
      el.type = 'password';
    });
    $('cpShow').checked = false;
    $('cpMsg').className = 'alert py-2 fs-7 d-none';
    $('cpSave').disabled = false;
    meter.update();

    $('changePwTitle').textContent = forced ? 'Choose your own password' : 'Change password';
    $('cpIntro').textContent = forced
      ? 'An administrator chose your current password, so someone else knows it. Set one that only you know before you continue.'
      : 'Choose a new password. You will be signed out of your other devices.';
    $('cpClose').classList.toggle('d-none', forced);
    $('cpCancel').classList.toggle('d-none', forced);
    $('cpLogout').classList.toggle('d-none', !forced);

    var old = bootstrap.Modal.getInstance(modalEl);
    if (old) { old.dispose(); }
    modal = new bootstrap.Modal(modalEl, forced ? { backdrop: 'static', keyboard: false } : {});
    modal.show();
  }

  function addButton() {
    var logout = $('logoutBtn');
    if (!logout || $('changePwBtn')) { return; }
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'changePwBtn';
    btn.className = 'btn btn-sm btn-light border-0';
    btn.title = 'Change password';
    btn.setAttribute('aria-label', 'Change password');
    btn.innerHTML = '<i class="bi bi-key text-secondary fs-6"></i>';
    btn.addEventListener('click', function () { open(false); });
    logout.parentNode.insertBefore(btn, logout);
  }

  function start() {
    addButton();
    var u = Weaver.user();
    if (Weaver.token() && u && u.must_change_password) { open(true); }
  }

  window.addEventListener('weaver:password-required', function () { open(true); });

  window.WeaverAccount = { openChangePassword: function () { open(false); } };

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
