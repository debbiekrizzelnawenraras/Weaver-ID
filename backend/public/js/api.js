/*
 * Weaver-ID shared front-end helper.
 * Load with <script src="js/api.js"></script> before any page script.
 * Same-origin by default: the pages and /api are served by the same Laravel app.
 * To point at another server, set window.WEAVER_API_BASE before loading this file.
 */
(function () {
  'use strict';

  var TOKEN_KEY = 'weaver_token';
  var USER_KEY = 'weaver_user';
  var LOGIN_PAGE = 'login.html';
  var HOME = { admin: 'admin.html', staff: 'admin.html', association: 'association_dashboard.html' };

  function store(key, value) {
    try {
      if (value === null) { localStorage.removeItem(key); } else { localStorage.setItem(key, value); }
    } catch (e) { /* storage blocked: the session just will not persist */ }
  }
  function load(key) {
    try { return localStorage.getItem(key); } catch (e) { return null; }
  }

  function token() { return load(TOKEN_KEY); }

  function user() {
    try { return JSON.parse(load(USER_KEY) || 'null'); } catch (e) { return null; }
  }

  function clearSession() { store(TOKEN_KEY, null); store(USER_KEY, null); }

  function toLogin() { window.location.href = LOGIN_PAGE; }

  // Escape anything that came from the server before putting it into innerHTML.
  function esc(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function ApiError(status, data) {
    this.status = status;
    this.data = data || {};
    this.message = this.data.message || 'Request failed.';
  }
  ApiError.prototype = Object.create(Error.prototype);

  // One readable string from a Laravel error (message plus field errors).
  function errorText(err) {
    if (!(err instanceof ApiError)) { return 'Cannot reach the Weaver-ID server.'; }
    var lines = [err.message];
    var fields = err.data.errors || {};
    Object.keys(fields).forEach(function (f) {
      (fields[f] || []).forEach(function (m) { if (lines.indexOf(m) === -1) { lines.push(m); } });
    });
    return lines.join(' ');
  }

  function request(method, path, body, opts) {
    opts = opts || {};
    var base = (window.WEAVER_API_BASE || '').replace(/\/$/, '');
    var headers = { 'Accept': 'application/json' };
    var t = token();
    if (t) { headers['Authorization'] = 'Bearer ' + t; }
    var init = { method: method, headers: headers };
    if (body !== undefined && body !== null) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
    return fetch(base + path, init).then(function (res) {
      return res.text().then(function (raw) {
        var data = {};
        try { data = raw ? JSON.parse(raw) : {}; } catch (e) { data = { message: 'Unexpected server response.' }; }
        if (res.status === 401 && t && !opts.noRedirect) {
          clearSession();
          toLogin();
        }
        if (!res.ok) { throw new ApiError(res.status, data); }
        return data;
      });
    });
  }

  function login(email, password, role) {
    var payload = { email: email, password: password };
    if (role) { payload.role = role; }
    return request('POST', '/api/login', payload, { noRedirect: true })
      .then(function (data) {
        store(TOKEN_KEY, data.token);
        store(USER_KEY, JSON.stringify(data.user));
        return data.user;
      });
  }

  function logout() {
    var done = function () { clearSession(); toLogin(); };
    if (!token()) { done(); return Promise.resolve(); }
    return request('POST', '/api/logout', null, { noRedirect: true }).then(done, done);
  }

  // Call at the top of a protected page. Sends the visitor away if the role is wrong.
  function requireRole(roles) {
    var u = user();
    if (!token() || !u || roles.indexOf(u.role) === -1) {
      clearSession();
      toLogin();
      return null;
    }
    return u;
  }

  window.Weaver = {
    get: function (path) { return request('GET', path); },
    post: function (path, body) { return request('POST', path, body === undefined ? {} : body); },
    patch: function (path, body) { return request('PATCH', path, body === undefined ? {} : body); },
    del: function (path) { return request('DELETE', path); },
    login: login,
    logout: logout,
    user: user,
    token: token,
    requireRole: requireRole,
    homeFor: function (role) { return HOME[role] || null; },
    esc: esc,
    errorText: errorText,
    ApiError: ApiError
  };
})();
