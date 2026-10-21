/*
 * Weaver-ID public page: an association applies for an account, or checks its application.
 * No login and no api.js (that file signs people out on a 401). The server checks everything
 * again; the checks here only save a round trip. Text from the server goes in with textContent.
 */
(function () {
  'use strict';

  var API = (window.WEAVER_API_BASE || '').replace(/\/$/, '');
  var FIELDS = ['association_name', 'municipality', 'registration_details', 'contact_name', 'contact_phone', 'email', 'password', 'privacy_consent'];

  function $(id) { return document.getElementById(id); }

  function call(method, path, body) {
    return fetch(API + path, {
      method: method,
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (res) {
      return res.text().then(function (raw) {
        var data = {};
        try { data = raw ? JSON.parse(raw) : {}; } catch (e) { data = { message: 'Unexpected server response.' }; }
        if (!res.ok) {
          var err = new Error(data.message || 'Request failed.');
          err.status = res.status;
          err.data = data;
          throw err;
        }
        return data;
      });
    });
  }

  function failureText(err) {
    if (!err || err.status === undefined) { return 'Cannot reach the Weaver-ID server. Check your connection and try again.'; }
    if (err.status === 429) { return 'Too many attempts. Please wait a while and try again.'; }
    if (err.status >= 500) { return 'Something went wrong on the server. Please try again later.'; }
    return err.message;
  }

  // ------------------------------------------------------------------- tabs
  function showPane(name) {
    var apply = name === 'apply';
    $('paneApply').classList.toggle('d-none', !apply);
    $('paneCheck').classList.toggle('d-none', apply);
    $('tabApply').setAttribute('aria-selected', apply ? 'true' : 'false');
    $('tabCheck').setAttribute('aria-selected', apply ? 'false' : 'true');
  }
  $('tabApply').addEventListener('click', function () { showPane('apply'); });
  $('tabCheck').addEventListener('click', function () { showPane('check'); });
  $('goCheck').addEventListener('click', function () {
    $('check_reference').value = $('doneReference').textContent;
    $('check_email').value = lastEmail;
    showPane('check');
    $('checkForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
  if (window.location.hash === '#check') { showPane('check'); }

  // ------------------------------------------------------- municipality list
  call('GET', '/api/municipalities').then(function (res) {
    var select = $('municipality');
    select.innerHTML = '';
    var first = document.createElement('option');
    first.value = '';
    first.textContent = 'Select...';
    select.appendChild(first);
    (res.municipalities || []).forEach(function (name) {
      var o = document.createElement('option');
      o.value = name;
      o.textContent = name;
      select.appendChild(o);
    });
  }).catch(function (err) {
    $('municipality').innerHTML = '<option value="">Could not load the list</option>';
    showFormError(failureText(err));
    $('applyBtn').disabled = true;
  });

  // ----------------------------------------------------------- password meter
  function context() { return { email: $('email').value, name: $('contact_name').value }; }
  WeaverPassword.attachMeter($('password'), $('meter'), { context: context, watch: [$('email'), $('contact_name')] });

  $('showPw').addEventListener('change', function () {
    var type = this.checked ? 'text' : 'password';
    $('password').type = type;
    $('password_confirmation').type = type;
  });

  // ------------------------------------------------------------------ errors
  function clearErrors() {
    $('applyError').classList.add('d-none');
    FIELDS.forEach(function (f) {
      var input = $(f);
      if (input) { input.classList.remove('is-invalid'); }
      var fb = document.querySelector('[data-for="' + f + '"]');
      if (fb) { fb.textContent = ''; }
    });
  }

  function showFormError(text) {
    var box = $('applyError');
    box.textContent = text;
    box.classList.remove('d-none');
  }

  function fieldError(field, text) {
    var input = $(field);
    var fb = document.querySelector('[data-for="' + field + '"]');
    if (input) { input.classList.add('is-invalid'); }
    if (fb) { fb.textContent = text; fb.style.display = 'block'; }
  }

  // An error disappears as soon as the person edits that field, so a corrected field never stays red.
  FIELDS.forEach(function (f) {
    var input = $(f);
    if (!input) { return; }
    var clear = function () {
      input.classList.remove('is-invalid');
      var fb = document.querySelector('[data-for="' + f + '"]');
      if (fb) { fb.textContent = ''; }
    };
    input.addEventListener('input', clear);
    input.addEventListener('change', clear);
  });

  function focusFirstError() {
    var bad = document.querySelector('#applyForm .is-invalid');
    if (bad) { bad.focus(); bad.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
  }

  // ------------------------------------------------------------------- apply
  var lastEmail = '';

  function checkBeforeSending(v) {
    var problems = {};
    if (v.association_name.length < 3) { problems.association_name = 'Enter the name of the association.'; }
    if (!v.municipality) { problems.municipality = 'Choose the municipality.'; }
    if (v.contact_name.length < 3) { problems.contact_name = 'Enter the name of the contact person.'; }
    if (!/^(?:\+63|63|0)9\d{9}$/.test(v.contact_phone.replace(/[\s\-().]+/g, ''))) { problems.contact_phone = 'Enter a mobile number such as 0917 123 4567.'; }
    if (!/^\S+@\S+\.\S+$/.test(v.email)) { problems.email = 'Enter a valid email address.'; }
    var check = WeaverPassword.evaluate(v.password, { email: v.email, name: v.contact_name });
    if (!check.ok) { problems.password = WeaverPassword.MESSAGES[check.failed[0]]; }
    else if (v.password !== v.password_confirmation) { problems.password = 'The password and its confirmation do not match.'; }
    if (!v.privacy_consent) { problems.privacy_consent = 'You must agree to the privacy notice to apply.'; }
    return problems;
  }

  $('applyForm').addEventListener('submit', function (event) {
    event.preventDefault();
    clearErrors();

    var values = {
      association_name: $('association_name').value.trim(),
      municipality: $('municipality').value,
      registration_details: $('registration_details').value.trim(),
      contact_name: $('contact_name').value.trim(),
      contact_phone: $('contact_phone').value.trim(),
      email: $('email').value.trim(),
      password: $('password').value,
      password_confirmation: $('password_confirmation').value,
      privacy_consent: $('privacy_consent').checked,
      website: $('website').value
    };

    var problems = checkBeforeSending(values);
    var keys = Object.keys(problems);
    if (keys.length) {
      keys.forEach(function (f) { fieldError(f, problems[f]); });
      showFormError('Please fix the highlighted fields.');
      focusFirstError();
      return;
    }

    var btn = $('applyBtn');
    btn.disabled = true;
    btn.textContent = 'Sending...';

    call('POST', '/api/association-applications', values).then(function (res) {
      lastEmail = values.email;
      $('doneReference').textContent = res.reference;
      $('applyForm').classList.add('d-none');
      $('applyDone').classList.remove('d-none');
      $('applyForm').reset();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }).catch(function (err) {
      var fields = (err.data && err.data.errors) || {};
      var shown = false;
      Object.keys(fields).forEach(function (f) {
        var text = (fields[f] || [])[0];
        if (text && FIELDS.indexOf(f) !== -1) { fieldError(f, text); shown = true; }
      });
      showFormError(shown ? 'Please fix the highlighted fields.' : failureText(err));
      if (shown) { focusFirstError(); }
    }).then(function () {
      btn.disabled = false;
      btn.textContent = 'Send application';
    });
  });

  $('copyRef').addEventListener('click', function () {
    var text = $('doneReference').textContent;
    var button = this;
    function done() { button.innerHTML = '<i class="bi bi-clipboard-check"></i> Copied'; }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(select);
    } else { select(); }
    function select() {
      var range = document.createRange();
      range.selectNodeContents($('doneReference'));
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
      button.textContent = 'Selected: press Ctrl+C';
    }
  });

  // ------------------------------------------------------------------- check
  var STATUS = {
    pending: { label: 'Waiting for review', cls: 'warning text-dark', icon: 'bi-hourglass-split' },
    approved: { label: 'Approved', cls: 'success', icon: 'bi-check-circle-fill' },
    rejected: { label: 'Not approved', cls: 'danger', icon: 'bi-x-circle-fill' }
  };

  function line(label, value) {
    var row = document.createElement('div');
    row.className = 'd-flex justify-content-between gap-3 py-1 border-bottom fs-7';
    var a = document.createElement('span');
    a.className = 'text-muted';
    a.textContent = label;
    var b = document.createElement('span');
    b.className = 'text-end fw-semibold';
    b.textContent = value;
    row.appendChild(a);
    row.appendChild(b);
    return row;
  }

  function showResult(res) {
    var s = STATUS[res.status] || { label: res.status, cls: 'secondary', icon: 'bi-info-circle' };
    var box = $('checkResult');
    box.innerHTML = '';

    var head = document.createElement('div');
    head.className = 'd-flex align-items-center gap-2 mb-3';
    var badge = document.createElement('span');
    badge.className = 'badge result-badge bg-' + s.cls;
    badge.innerHTML = '<i class="bi ' + s.icon + ' me-1"></i>';
    badge.appendChild(document.createTextNode(s.label));
    head.appendChild(badge);
    box.appendChild(head);

    box.appendChild(line('Reference', res.reference));
    box.appendChild(line('Association', res.association_name));
    box.appendChild(line('Municipality', res.municipality));
    box.appendChild(line('Sent', new Date(res.submitted_at).toLocaleDateString()));
    if (res.decided_at) { box.appendChild(line('Decided', new Date(res.decided_at).toLocaleDateString())); }

    var msg = document.createElement('p');
    msg.className = 'mt-3 mb-2';
    msg.textContent = res.message;
    box.appendChild(msg);

    if (res.status === 'rejected' && res.decision_note) {
      var note = document.createElement('div');
      note.className = 'notice-box mb-3';
      var strong = document.createElement('strong');
      strong.textContent = 'Reason from DTI-Mountain Province: ';
      note.appendChild(strong);
      note.appendChild(document.createTextNode(res.decision_note));
      box.appendChild(note);
    }

    var next = document.createElement('a');
    if (res.status === 'approved') {
      next.href = 'login.html';
      next.className = 'btn btn-dti w-100';
      next.textContent = 'Go to sign in';
      box.appendChild(next);
    } else if (res.status === 'rejected') {
      next.href = '#';
      next.className = 'btn btn-outline-secondary w-100';
      next.textContent = 'Apply again';
      next.addEventListener('click', function (e) {
        e.preventDefault();
        $('applyDone').classList.add('d-none');
        $('applyForm').classList.remove('d-none');
        $('password').dispatchEvent(new Event('input'));   // redraw the meter for the emptied field
        showPane('apply');
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
      box.appendChild(next);
    }
    box.classList.remove('d-none');
  }

  $('checkForm').addEventListener('submit', function (event) {
    event.preventDefault();
    var err = $('checkError');
    err.classList.add('d-none');
    $('checkResult').classList.add('d-none');

    var reference = $('check_reference').value.trim();
    var email = $('check_email').value.trim();
    if (!reference || !email) {
      err.textContent = 'Enter the reference number and the email you applied with.';
      err.classList.remove('d-none');
      return;
    }

    var btn = $('checkBtn');
    btn.disabled = true;
    btn.textContent = 'Checking...';
    call('POST', '/api/association-applications/status', { reference: reference, email: email }).then(showResult).catch(function (e) {
      err.textContent = failureText(e);
      err.classList.remove('d-none');
    }).then(function () {
      btn.disabled = false;
      btn.textContent = 'Check';
    });
  });
})();
