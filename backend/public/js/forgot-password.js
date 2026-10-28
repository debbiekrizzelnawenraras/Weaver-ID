/*
 * Weaver-ID public page: forgot password. Step 1 asks for a code by email, step 2 takes the code and a new
 * password. No login and no api.js (that file signs people out on a 401). The server checks everything
 * again; the checks here only save a round trip. Text from the server goes in with textContent.
 */
(function () {
  'use strict';

  var API = (window.WEAVER_API_BASE || '').replace(/\/$/, '');
  var FIELDS = ['email', 'code', 'password'];
  var resendTimer = null;

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
    if (err.status === 503) { return err.message; }
    if (err.status >= 500) { return 'Something went wrong on the server. Please try again later.'; }
    return err.message;
  }

  function showError(text) {
    var box = $('forgotError');
    box.textContent = text;
    box.classList.remove('d-none');
  }

  function clearErrors() {
    $('forgotError').classList.add('d-none');
    FIELDS.forEach(function (f) {
      $(f).classList.remove('is-invalid');
      var fb = document.querySelector('[data-for="' + f + '"]');
      if (fb) { fb.textContent = ''; }
    });
  }

  function fieldError(field, text) {
    $(field).classList.add('is-invalid');
    var fb = document.querySelector('[data-for="' + field + '"]');
    if (fb) { fb.textContent = text; fb.style.display = 'block'; }
  }

  FIELDS.forEach(function (f) {
    $(f).addEventListener('input', function () {
      $(f).classList.remove('is-invalid');
      var fb = document.querySelector('[data-for="' + f + '"]');
      if (fb) { fb.textContent = ''; }
    });
  });

  // ----------------------------------------------------------- password meter
  WeaverPassword.attachMeter($('password'), $('meter'), {
    context: function () { return { email: $('email').value }; },
    watch: [$('email')]
  });

  $('showPw').addEventListener('change', function () {
    var type = this.checked ? 'text' : 'password';
    $('password').type = type;
    $('password_confirmation').type = type;
  });

  // ------------------------------------------------------------ asking for a code
  function countdown(seconds) {
    var button = $('again');
    var left = seconds;
    clearInterval(resendTimer);
    function tick() {
      if (left <= 0) {
        clearInterval(resendTimer);
        button.disabled = false;
        button.textContent = 'Send a new code';
        return;
      }
      button.disabled = true;
      button.textContent = 'Send a new code in ' + left + ' s';
      left -= 1;
    }
    tick();
    resendTimer = setInterval(tick, 1000);
  }

  function askForCode(button, idleText) {
    clearErrors();
    var email = $('email').value.trim();
    if (!/^\S+@\S+\.\S+$/.test(email)) {
      fieldError('email', 'Enter a valid email address.');
      $('email').focus();
      return;
    }

    button.disabled = true;
    button.textContent = 'Sending...';
    call('POST', '/api/password/forgot', { email: email }).then(function (res) {
      $('askForm').classList.add('d-none');
      $('resetForm').classList.remove('d-none');
      // The server answers the same way whether or not the address has an account, so the page says "if".
      $('codeSent').textContent = res.message;
      $('code').value = '';
      $('code').focus();
      countdown(res.resend_in || 60);
    }).catch(function (err) {
      var fields = (err.data && err.data.errors) || {};
      if (fields.email) { fieldError('email', fields.email[0]); } else { showError(failureText(err)); }
      button.disabled = false;
      button.textContent = idleText;
    });
  }

  $('askForm').addEventListener('submit', function (event) {
    event.preventDefault();
    var button = $('askBtn');
    askForCode(button, 'Send code');
  });

  $('again').addEventListener('click', function () {
    askForCode(this, 'Send a new code');
  });

  // ---------------------------------------------------------- choosing the password
  $('resetForm').addEventListener('submit', function (event) {
    event.preventDefault();
    clearErrors();

    var values = {
      email: $('email').value.trim(),
      code: $('code').value.trim(),
      password: $('password').value,
      password_confirmation: $('password_confirmation').value
    };

    var problems = {};
    if (!/^\d{6}$/.test(values.code)) { problems.code = 'Type the 6-digit code from your email.'; }
    var check = WeaverPassword.evaluate(values.password, { email: values.email });
    if (!check.ok) { problems.password = WeaverPassword.MESSAGES[check.failed[0]]; }
    else if (values.password !== values.password_confirmation) { problems.password = 'The password and its confirmation do not match.'; }

    var keys = Object.keys(problems);
    if (keys.length) {
      keys.forEach(function (f) { fieldError(f, problems[f]); });
      showError('Please fix the highlighted fields.');
      $(keys[0]).focus();
      return;
    }

    var btn = $('resetBtn');
    btn.disabled = true;
    btn.textContent = 'Changing...';
    call('POST', '/api/password/reset', values).then(function () {
      clearInterval(resendTimer);
      $('resetForm').classList.add('d-none');
      $('done').classList.remove('d-none');
      $('resetForm').reset();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }).catch(function (err) {
      var fields = (err.data && err.data.errors) || {};
      var shown = false;
      FIELDS.forEach(function (f) {
        var text = (fields[f] || [])[0];
        if (text) { fieldError(f, text); shown = true; }
      });
      showError(shown ? 'Please fix the highlighted fields.' : failureText(err));
    }).then(function () {
      btn.disabled = false;
      btn.textContent = 'Change password';
    });
  });
})();
