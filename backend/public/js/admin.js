/*
 * Weaver-ID control panel (admin and staff).
 * Needs js/api.js. Every value that came from the server goes through esc()
 * before it is placed into HTML.
 */
(function () {
  'use strict';

  var allowed = (document.body.getAttribute('data-roles') || 'admin,staff').split(',');
  var me = Weaver.requireRole(allowed);
  if (!me) { return; }
  var isAdmin = me.role === 'admin';
  var isAssoc = me.role === 'association';
  var hasLogs = isAdmin || isAssoc;
  var API = {
    weavers: isAssoc ? '/api/association/weavers?status=all' : '/api/staff/weavers',
    weaver: isAssoc ? '/api/association/weavers' : '/api/staff/weavers',
    products: isAssoc ? '/api/association/products?status=all' : '/api/staff/products',
    product: isAssoc ? '/api/association/products' : '/api/staff/products',
    logs: isAssoc ? '/api/association/scan-logs' : '/api/admin/scan-logs'
  };
  var esc = Weaver.esc;
  var READER_URL = window.WEAVER_READER_URL || 'http://127.0.0.1:8777';

  var data = { associations: [], weavers: [], products: [], tags: [], users: [], summary: null, logs: null };
  var state = { tab: 'dashboard', filter: '', logPage: 1, logResult: '', staffMunicipality: '' };

  var TITLES = {
    dashboard: 'Dashboard', weavers: 'Members', associations: 'Associations', products: 'Products',
    nfc: 'NFC Tags', logs: 'Scan Logs', users: 'Users'
  };

  function $(id) { return document.getElementById(id); }

  // ------------------------------------------------------------ small helpers
  function toast(message, kind) {
    var el = document.createElement('div');
    el.className = 'alert alert-' + (kind || 'success') + ' shadow-sm py-2 fs-7';
    el.textContent = message;
    $('toastHost').appendChild(el);
    setTimeout(function () { el.remove(); }, 4500);
  }

  function badge(status) {
    var map = { active: 'success', inactive: 'secondary', revoked: 'danger',
      authenticated: 'success', unregistered: 'secondary', suspicious: 'danger' };
    return '<span class="badge bg-' + (map[status] || 'secondary') + '">' + esc(status) + '</span>';
  }

  function securedBadge(t) {
    if (t.status !== 'active') { return ''; }
    return t.counter_enabled
      ? ' <span class="badge bg-info text-dark" title="Write password set and read counter on">secured</span>'
      : ' <span class="badge bg-warning text-dark" title="This tag has no write password or counter yet">not secured</span>';
  }

  var REASONS = {
    tag_revoked: 'tag revoked', product_inactive: 'product inactive', weaver_inactive: 'member inactive',
    many_devices: 'many devices', counter_rollback: 'possible copy (counter went back)',
    counter_missing: 'secured tag read without counter'
  };

  function when(value) {
    if (!value) { return '-'; }
    var d = new Date(value);
    return isNaN(d) ? esc(value) : esc(d.toLocaleString());
  }

  function matches(parts) {
    if (!state.filter) { return true; }
    return parts.join(' ').toLowerCase().indexOf(state.filter) !== -1;
  }

  function empty(cols, text) {
    return '<tr><td colspan="' + cols + '" class="empty-row">' + esc(text) + '</td></tr>';
  }

  function activeTagFor(productId) {
    return data.tags.filter(function (t) { return t.product_id === productId && t.status === 'active'; })[0] || null;
  }

  function byId(list, id) {
    return list.filter(function (x) { return x.id === id; })[0] || null;
  }

  // ------------------------------------------------------------------ loading
  function loadAll() {
    $('loadError').classList.add('d-none');
    var jobs = [
      Weaver.get(API.weavers).then(function (r) {
        data.weavers = (r.weavers || []).map(function (w) {
          if (w.products_count === undefined) { w.products_count = (w.products || []).length; }
          return w;
        });
      }),
      Weaver.get(API.products).then(function (r) { data.products = r.products || []; }),
      Weaver.get('/api/nfc-tags').then(function (r) { data.tags = r.tags || []; })
    ];
    if (!isAssoc) {
      jobs.push(Weaver.get('/api/staff/associations').then(function (r) { data.associations = r.associations || []; }));
    }
    if (hasLogs) { jobs.push(loadLogs()); }
    if (isAdmin) {
      jobs.push(Weaver.get('/api/admin/summary').then(function (r) { data.summary = r; }));
      jobs.push(Weaver.get('/api/admin/users').then(function (r) { data.users = r.users || []; }));
    } else {
      jobs.push(Weaver.get('/api/user').then(function (r) {
        var u = r.user || r;
        if (isAssoc && u.association) {
          state.association = u.association;
          state.staffMunicipality = u.association.municipality || '';
          setMeName(u.association.name);
          document.title = 'Weaver-ID | ' + u.association.name;
        } else {
          state.staffMunicipality = u.municipality || '';
        }
      }));
    }
    return Promise.all(jobs).then(renderAll).catch(function (err) {
      var box = $('loadError');
      box.textContent = Weaver.errorText(err);
      box.classList.remove('d-none');
    });
  }

  function loadLogs() {
    var q = '?page=' + state.logPage + (state.logResult ? '&result=' + encodeURIComponent(state.logResult) : '');
    return Weaver.get(API.logs + q).then(function (r) { data.logs = r; });
  }

  // ---------------------------------------------------------------- rendering
  function renderAll() {
    renderTiles();
    renderDashboardExtras();
    renderWeavers();
    if (!isAssoc) { renderAssociations(); }
    renderProducts();
    renderTags();
    if (hasLogs) { renderLogs(); }
    if (isAdmin) { renderUsers(); }
  }

  function tile(icon, cls, value, label, sub) {
    return '<div class="col-6 col-md-3"><div class="metric-card">' +
      '<div class="metric-icon ' + cls + '"><i class="bi ' + icon + '"></i></div>' +
      '<h2 class="fw-bold mb-0">' + esc(value) + '</h2>' +
      '<div class="text-muted fs-8 mt-1">' + esc(label) + '</div>' +
      (sub ? '<div class="text-muted fs-8">' + esc(sub) + '</div>' : '') +
      '</div></div>';
  }

  function renderTiles() {
    var s = data.summary, html;
    if (isAdmin && s) {
      html = tile('bi-people-fill', 'icon-weavers', s.weavers.active, 'Active members', 'of ' + s.weavers.total + ' registered') +
        tile('bi-box-seam-fill', 'icon-products', s.products.total, 'Products', s.products.with_active_tag + ' have an active tag') +
        tile('bi-wifi', 'icon-nfc', s.tags.active, 'Active tags', s.tags.revoked + ' revoked') +
        tile('bi-check-circle-fill', 'icon-verifications', s.scans.total, 'Total scans', s.scans.suspicious + ' suspicious');
    } else if (isAssoc) {
      var act = data.tags.filter(function (t) { return t.status === 'active'; }).length;
      var activeWeavers = data.weavers.filter(function (w) { return w.status === 'active'; }).length;
      html = tile('bi-people-fill', 'icon-weavers', activeWeavers, 'Active members', 'of ' + data.weavers.length + ' members') +
        tile('bi-box-seam-fill', 'icon-products', data.products.length, 'Products', data.products.filter(function (p) { return activeTagFor(p.id); }).length + ' have an active tag') +
        tile('bi-wifi', 'icon-nfc', act, 'Active tags', (data.tags.length - act) + ' revoked') +
        tile('bi-check-circle-fill', 'icon-verifications', data.logs ? data.logs.total : 0, 'Scans of your tags', '');
    } else {
      var active = data.tags.filter(function (t) { return t.status === 'active'; }).length;
      html = tile('bi-people-fill', 'icon-weavers', data.weavers.length, 'Members', 'in your scope') +
        tile('bi-box-seam-fill', 'icon-products', data.products.length, 'Products', '') +
        tile('bi-wifi', 'icon-nfc', active, 'Active tags', (data.tags.length - active) + ' revoked') +
        tile('bi-building', 'icon-verifications', data.associations.length, 'Associations', '');
    }
    $('tiles').innerHTML = html;
  }

  function renderDashboardExtras() {
    if (isAssoc) { renderRecentScans(); return; }
    if (!isAdmin || !data.summary) { return; }
    var s = data.summary, total = Math.max(s.scans.total, 1);
    var rows = [['authenticated', '#2e7d32'], ['unregistered', '#78909c'], ['suspicious', '#c62828']];
    $('scanBars').innerHTML = rows.map(function (r) {
      var n = s.scans[r[0]];
      return '<div class="mb-2"><div class="d-flex justify-content-between fs-8"><span class="text-capitalize">' + esc(r[0]) +
        '</span><span>' + esc(n) + '</span></div><div class="bar-track"><div class="bar-fill" style="width:' +
        Math.round((n / total) * 100) + '%;background:' + r[1] + '"></div></div></div>';
    }).join('');
    var by = s.tags.issued_by_role;
    $('issuedBy').innerHTML = ['admin', 'staff', 'association'].map(function (role) {
      return '<span class="badge bg-light text-dark border">' + esc(role) + ': ' + esc(by[role]) + '</span>';
    }).join('');

    renderRecentScans();
  }

  function renderRecentScans() {
    var recent = (data.logs && data.logs.data ? data.logs.data : []).slice(0, 8);
    if (!$('recentScans')) { return; }
    $('recentScans').innerHTML = recent.length ? recent.map(function (l) {
      var product = l.nfc_tag && l.nfc_tag.product ? l.nfc_tag.product.title : 'Unknown tag';
      var cls = l.result === 'authenticated' ? 'success' : (l.result === 'suspicious' ? 'danger' : 'secondary');
      var icon = l.result === 'authenticated' ? 'bi-check-lg' : (l.result === 'suspicious' ? 'bi-exclamation-lg' : 'bi-question-lg');
      return '<div class="d-flex align-items-center justify-content-between p-1"><div class="d-flex align-items-center gap-3">' +
        '<div class="scan-status-icon bg-' + cls + '-subtle text-' + cls + '"><i class="bi ' + icon + '"></i></div><div>' +
        '<div class="fw-bold fs-7 text-capitalize">' + esc(l.result) + '</div>' +
        '<div class="text-muted fs-8">' + esc(l.uid) + ' | ' + esc(product) + '</div></div></div>' +
        '<span class="text-muted fs-8">' + when(l.created_at) + '</span></div>';
    }).join('<hr class="my-1">') : '<div class="text-muted fs-7">No scans yet.</div>';
  }

  function renderWeavers() {
    var rows = data.weavers.filter(function (w) {
      return matches([w.name, w.municipality, w.association ? w.association.name : '']);
    }).map(function (w) {
      return '<tr><td class="fw-semibold">' + esc(w.name) + '</td><td>' + esc(w.municipality) +
        '</td>' + (isAssoc ? '' : '<td>' + esc(w.association ? w.association.name : '-') + '</td>') + '<td>' + esc(w.products_count) +
        '</td><td>' + badge(w.status) + '</td><td class="text-end pe-3">' +
        '<button class="btn btn-sm btn-outline-secondary py-0" data-action="toggle-weaver" data-id="' + esc(w.id) + '">' +
        (w.status === 'active' ? 'Deactivate' : 'Activate') + '</button></td></tr>';
    });
    $('weaversBody').innerHTML = rows.length ? rows.join('') : empty(isAssoc ? 5 : 6, 'No members to show.');
  }

  function renderAssociations() {
    var cards = data.associations.filter(function (a) {
      return matches([a.name, a.municipality]);
    }).map(function (a) {
      return '<div class="col-12 col-md-6 col-xl-4"><div class="association-box"><div class="d-flex justify-content-between">' +
        '<h6 class="fw-bold mb-1">' + esc(a.name) + '</h6>' + badge(a.status || 'active') + '</div>' +
        '<div class="text-muted fs-7"><i class="bi bi-geo-alt"></i> ' + esc(a.municipality || '-') + '</div>' +
        '<div class="text-muted fs-7"><i class="bi bi-people"></i> ' + esc(a.weavers_count) + ' member(s)</div></div></div>';
    });
    $('associationsBody').innerHTML = cards.length ? cards.join('') :
      '<div class="col-12"><div class="dashboard-card text-muted text-center">No associations to show.</div></div>';
  }

  function renderProducts() {
    var rows = data.products.filter(function (p) {
      return matches([p.title, p.category, p.weaver ? p.weaver.name : '']);
    }).map(function (p) {
      var tag = activeTagFor(p.id);
      return '<tr><td class="fw-semibold">' + esc(p.title) + '</td><td>' + esc(p.category) + '</td><td>' +
        esc(p.weaver ? p.weaver.name : '-') + '</td><td>' +
        (tag ? '<code>' + esc(tag.uid) + '</code>' : '<span class="text-muted">none</span>') + '</td><td>' + badge(p.status) +
        '</td><td class="text-end pe-3"><button class="btn btn-sm btn-outline-secondary py-0" data-action="toggle-product" data-id="' +
        esc(p.id) + '">' + (p.status === 'active' ? 'Deactivate' : 'Activate') + '</button></td></tr>';
    });
    $('productsBody').innerHTML = rows.length ? rows.join('') : empty(6, 'No products to show.');
  }

  function renderTags() {
    var rows = data.tags.filter(function (t) {
      var p = t.product || {};
      return matches([t.uid, p.title, p.weaver ? p.weaver.name : '', t.status]);
    }).map(function (t) {
      var p = t.product || {}, w = p.weaver || {}, a = w.association || {};
      var actions = '';
      if (t.status === 'active') {
        actions += '<button class="btn btn-sm btn-outline-primary py-0 me-1" data-action="secure-tag" data-id="' + esc(t.id) + '">' +
          (t.counter_enabled ? 'Re-secure' : 'Secure') + '</button>';
        actions += '<button class="btn btn-sm btn-outline-danger py-0 me-1" data-action="revoke-tag" data-id="' + esc(t.id) + '">Revoke</button>';
      }
      if (isAdmin) {
        actions += '<button class="btn btn-sm btn-outline-secondary py-0" data-action="reassign-tag" data-id="' + esc(t.id) + '">Reassign</button>';
      }
      return '<tr><td><code>' + esc(t.uid) + '</code></td><td>' + esc(p.title || '-') + '</td><td>' + esc(w.name || '-') +
        '</td><td>' + esc(a.name || '-') + '</td><td>' + when(t.issued_at || t.created_at) + '</td><td>' + badge(t.status) + securedBadge(t) +
        '</td><td class="text-end pe-3">' + actions + '</td></tr>';
    });
    $('tagsBody').innerHTML = rows.length ? rows.join('') : empty(7, 'No tags to show.');
  }

  function renderLogs() {
    var l = data.logs;
    var items = l && l.data ? l.data : [];
    var rows = items.filter(function (x) {
      return matches([x.uid, x.result, x.nfc_tag && x.nfc_tag.product ? x.nfc_tag.product.title : '']);
    }).map(function (x) {
      return '<tr><td>' + when(x.created_at) + '</td><td><code>' + esc(x.uid) + '</code></td><td>' + badge(x.result) +
        (x.flag_reason ? ' <span class="text-muted fs-8">' + esc(REASONS[x.flag_reason] || x.flag_reason) + '</span>' : '') +
        '</td><td>' + esc(x.nfc_tag && x.nfc_tag.product ? x.nfc_tag.product.title : '-') + '</td><td>' + esc(x.device_id || x.ip_address || '-') + '</td></tr>';
    });
    $('logsBody').innerHTML = rows.length ? rows.join('') : empty(5, 'No scans match.');
    $('logPageInfo').textContent = l ? 'Page ' + l.current_page + ' of ' + l.last_page + ' (' + l.total + ' scans)' : '';
    $('logPrev').disabled = !l || l.current_page <= 1;
    $('logNext').disabled = !l || l.current_page >= l.last_page;
  }

  function renderUsers() {
    var rows = data.users.filter(function (u) {
      return matches([u.name, u.email, u.role, u.municipality || '', u.association ? u.association.name : '']);
    }).map(function (u) {
      var scope = u.role === 'staff' ? (u.municipality || '-') : (u.role === 'association' ? (u.association ? u.association.name : '-') : 'All');
      var self = u.id === me.id;
      return '<tr><td class="fw-semibold">' + esc(u.name) + '</td><td>' + esc(u.email) + '</td><td>' + esc(u.role) +
        '</td><td>' + esc(scope) + '</td><td>' + badge(u.status) + '</td><td class="text-end pe-3">' +
        (self ? '<span class="text-muted fs-8">you</span>' :
          '<button class="btn btn-sm btn-outline-secondary py-0" data-action="toggle-user" data-id="' + esc(u.id) + '">' +
          (u.status === 'active' ? 'Deactivate' : 'Activate') + '</button>') + '</td></tr>';
    });
    $('usersBody').innerHTML = rows.length ? rows.join('') : empty(6, 'No users to show.');
  }

  // --------------------------------------------------------------------- tabs
  function showTab(tab) {
    state.tab = tab;
    state.filter = '';
    $('searchInput').value = '';
    Array.prototype.forEach.call(document.querySelectorAll('.tab-view'), function (v) {
      v.classList.toggle('d-none', v.id !== 'view-' + tab);
    });
    Array.prototype.forEach.call(document.querySelectorAll('#sideNav .nav-link'), function (a) {
      a.classList.toggle('active', a.getAttribute('data-tab') === tab);
    });
    $('sectionTitle').textContent = TITLES[tab];
    $('searchWrap').classList.toggle('d-none', tab === 'dashboard');
  }

  // -------------------------------------------------------------- form dialog
  var modal = null;
  var activeSubmit = null;
  var activeFields = [];

  function fieldHtml(f) {
    var id = 'f_' + f.name;
    var label = '<label class="form-label fs-7 fw-semibold" for="' + id + '">' + esc(f.label) + '</label>';
    if (f.type === 'checkbox') {
      return '<div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="' + id + '" name="' + esc(f.name) +
        '"><label class="form-check-label fs-7" for="' + id + '">' + esc(f.label) + '</label></div>';
    }
    if (f.type === 'select') {
      var opts = (f.options || []).map(function (o) {
        return '<option value="' + esc(o.value) + '"' + (String(o.value) === String(f.value) ? ' selected' : '') + '>' + esc(o.label) + '</option>';
      }).join('');
      return '<div class="mb-3">' + label + '<select class="form-select" id="' + id + '" name="' + esc(f.name) + '">' +
        '<option value="">Select...</option>' + opts + '</select></div>';
    }
    var input = '<input class="form-control" id="' + id + '" name="' + esc(f.name) + '" type="' +
      esc(f.type || 'text') + '" value="' + esc(f.value || '') + '"' + (f.readonly ? ' readonly' : '') +
      (f.placeholder ? ' placeholder="' + esc(f.placeholder) + '"' : '') + (f.type === 'password' ? ' autocomplete="new-password"' : '') + '>';
    if (f.reader) {
      return '<div class="mb-3">' + label + '<div class="input-group">' + input +
        '<button type="button" class="btn btn-outline-success" data-action="read-uid"><i class="bi bi-wifi"></i> Read from reader</button></div>' +
        '<div id="readerMsg" class="form-text"></div></div>';
    }
    return '<div class="mb-3">' + label + input + '</div>';
  }

  function openForm(title, submitLabel, fields, onSubmit, note) {
    activeFields = fields;
    activeSubmit = onSubmit;
    $('formTitle').textContent = title;
    $('formSubmit').textContent = submitLabel;
    $('formBody').innerHTML = (note ? '<p class="text-muted fs-7">' + esc(note) + '</p>' : '') + fields.map(fieldHtml).join('');
    $('formErr').classList.add('d-none');
    modal = modal || new bootstrap.Modal($('formModal'));
    modal.show();
  }

  function collect() {
    var out = {};
    activeFields.forEach(function (f) {
      var el = $('f_' + f.name);
      if (f.type === 'checkbox') { out[f.name] = el.checked; return; }
      var v = el.value.trim();
      out[f.name] = (f.int && v !== '') ? parseInt(v, 10) : v;
    });
    return out;
  }

  function onFormSubmit(event) {
    event.preventDefault();
    if (!activeSubmit) { return; }
    var btn = $('formSubmit');
    btn.disabled = true;
    $('formErr').classList.add('d-none');
    activeSubmit(collect()).then(function (res) {
      modal.hide();
      toast((res && res.message) || 'Saved.');
      return loadAll();
    }).catch(function (err) {
      $('formErr').textContent = Weaver.errorText(err);
      $('formErr').classList.remove('d-none');
    }).then(function () { btn.disabled = false; });
  }

  function assocOptions() {
    return data.associations.map(function (a) { return { value: a.id, label: a.name + (a.municipality ? ' (' + a.municipality + ')' : '') }; });
  }
  function activeWeaverOptions() {
    return data.weavers.filter(function (w) { return w.status === 'active'; })
      .map(function (w) { return { value: w.id, label: w.name }; });
  }
  function activeProductOptions(ignoreTagId) {
    return data.products.filter(function (p) { return p.status === 'active'; }).map(function (p) {
      var tag = activeTagFor(p.id);
      var taken = tag && tag.id !== ignoreTagId;
      return { value: p.id, label: p.title + (taken ? ' (already has a tag)' : '') };
    });
  }

  // ----------------------------------------------------------------- actions
  function confirmThen(text, fn) { if (window.confirm(text)) { fn(); } }

  function run(promise) {
    return promise.then(function (res) { toast((res && res.message) || 'Done.'); return loadAll(); })
      .catch(function (err) { toast(Weaver.errorText(err), 'danger'); });
  }

  // Ask the server for the tag's write password, have the reader bridge write it
  // and turn on the tag counter, then tell the server which counter the tag showed.
  function secureTag(tag) {
    return Weaver.post('/api/nfc-tags/' + tag.id + '/secure-key', {}).then(function (key) {
      return fetch(READER_URL + '/secure', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ uid: key.uid, password: key.password, pack: key.pack, timeout: 15 })
      }).then(function (res) {
        return res.json().then(function (d) { return { ok: res.ok, d: d }; });
      }).then(function (r) {
        if (!r.ok) { throw new Error(r.d.error || 'Reader error.'); }
        return Weaver.post('/api/nfc-tags/' + tag.id + '/secured', { counter: r.d.counter });
      });
    });
  }

  function secureErrorText(err) {
    if (err instanceof TypeError) {
      return 'cannot reach the reader bridge (start python reader_bridge.py).';
    }
    return (err && err.message) ? err.message : Weaver.errorText(err);
  }

  var actions = {
    'add-weaver': function () {
      var fields = [
        { name: 'name', label: 'Member name (weaver, sewer or workshop)' },
        { name: 'municipality', label: 'Municipality', value: state.staffMunicipality }
      ];
      if (!isAssoc) {
        fields.unshift({ name: 'association_id', label: 'Association', type: 'select', options: assocOptions(), int: true });
      }
      openForm('Add member', 'Save', fields, function (v) { return Weaver.post(API.weaver, v); });
    },
    'add-association': function () {
      openForm('Add association', 'Save', [
        { name: 'name', label: 'Association name' },
        { name: 'municipality', label: 'Municipality', value: isAdmin ? '' : state.staffMunicipality, readonly: !isAdmin }
      ], function (v) { return Weaver.post('/api/staff/associations', v); });
    },
    'add-product': function () {
      openForm('Add product', 'Save', [
        { name: 'weaver_id', label: 'Member', type: 'select', options: activeWeaverOptions(), int: true },
        { name: 'title', label: 'Product name' },
        { name: 'category', label: 'Category', placeholder: 'e.g. Blanket, Table runner' }
      ], function (v) { return Weaver.post(API.product, v); });
    },
    'toggle-product': function (id) {
      var p = byId(data.products, id);
      if (!p) { return; }
      var next = p.status === 'active' ? 'inactive' : 'active';
      confirmThen((next === 'inactive' ? 'Deactivate ' : 'Activate ') + p.title + '?', function () {
        var body = { title: p.title, category: p.category, status: next };
        if (!isAssoc) { body.weaver_id = p.weaver_id; }
        run(put(API.product + '/' + p.id, body));
      });
    },
    'issue-tag': function () {
      openForm('Link a tag to a product', 'Link tag', [
        { name: 'uid', label: 'Tag UID', placeholder: 'e.g. 04E3CA79410289', reader: true },
        { name: 'product_id', label: 'Product', type: 'select', options: activeProductOptions(0), int: true }
      ], function (v) {
        return Weaver.post('/api/nfc-tags', v).then(function (res) {
          toast('Tag linked. Keep it on the reader while it is being secured...', 'info');
          return secureTag(res.tag).then(function () {
            res.message = 'NFC tag issued and secured.';
            return res;
          }).catch(function (err) {
            toast('The tag is linked but NOT secured yet: ' + secureErrorText(err) + ' Use the Secure button on its row.', 'warning');
            res.message = 'NFC tag issued (not secured yet).';
            return res;
          });
        });
      },
      'Place the tag on the reader and click "Read from reader", or type the UID. After linking, keep the tag on the reader: it is secured automatically (write password and read counter).');
    },
    'secure-tag': function (id) {
      var t = byId(data.tags, id);
      if (!t) { return; }
      confirmThen('Place tag ' + t.uid + ' on the reader and keep it there, then press OK.' +
        (t.counter_enabled ? ' This tag is already secured: this resets its saved counter.' : ''), function () {
        toast('Securing the tag. Keep it on the reader...', 'info');
        run(secureTag(t).then(function () { return { message: 'NFC tag secured.' }; }));
      });
    },
    'read-uid': function () {
      var msg = $('readerMsg'), field = $('f_uid');
      var btn = document.querySelector('[data-action="read-uid"]');
      msg.className = 'form-text';
      msg.textContent = 'Place the tag on the reader (waiting up to 15 seconds)...';
      btn.disabled = true;
      fetch(READER_URL + '/uid?timeout=15').then(function (res) {
        return res.json().then(function (d) { return { ok: res.ok, d: d }; });
      }).then(function (r) {
        if (!r.ok) { throw new Error(r.d.error || 'Reader error.'); }
        field.value = r.d.uid;
        msg.className = 'form-text text-success';
        msg.textContent = 'Tag read: ' + r.d.uid + '. Now choose the product.';
      }).catch(function (err) {
        msg.className = 'form-text text-danger';
        msg.textContent = (err instanceof TypeError)
          ? 'Cannot reach the reader bridge. Start it with: python reader_bridge.py (in the tools folder), then try again.'
          : err.message;
      }).then(function () { btn.disabled = false; });
    },
    'revoke-tag': function (id) {
      var t = byId(data.tags, id);
      if (!t) { return; }
      confirmThen('Revoke tag ' + t.uid + '? Scans will show as suspicious.', function () {
        run(Weaver.patch('/api/nfc-tags/' + t.id + '/revoke'));
      });
    },
    'reassign-tag': function (id) {
      var t = byId(data.tags, id);
      if (!t) { return; }
      var fields = [{ name: 'product_id', label: 'Move to product', type: 'select', options: activeProductOptions(t.id), int: true }];
      if (t.status === 'revoked') {
        fields.push({ name: 'reactivate', label: 'This tag is revoked. Put it back in service.', type: 'checkbox' });
      }
      openForm('Reassign tag ' + t.uid, 'Reassign', fields, function (v) {
        var body = { product_id: v.product_id };
        if (v.reactivate) { body.reactivate = true; }
        return Weaver.patch('/api/admin/nfc-tags/' + t.id + '/reassign', body);
      }, 'Use this to fix a mistake. Every change is recorded in the audit trail.');
    },
    'add-user': function () {
      openForm('Add user', 'Create', [
        { name: 'name', label: 'Full name' },
        { name: 'email', label: 'Email', type: 'email' },
        { name: 'password', label: 'Password (10+ characters)', type: 'password' },
        { name: 'role', label: 'Role', type: 'select', options: [
          { value: 'staff', label: 'Staff' }, { value: 'association', label: 'Association' }, { value: 'admin', label: 'Admin' }] },
        { name: 'municipality', label: 'Municipality (staff only)' },
        { name: 'association_id', label: 'Association (association accounts only)', type: 'select', options: assocOptions(), int: true }
      ], function (v) {
        if (v.role !== 'staff') { delete v.municipality; }
        if (v.role !== 'association') { delete v.association_id; }
        return Weaver.post('/api/admin/users', v);
      });
    },
    'toggle-user': function (id) {
      var u = byId(data.users, id);
      if (!u) { return; }
      var next = u.status === 'active' ? 'inactive' : 'active';
      confirmThen((next === 'inactive' ? 'Deactivate ' : 'Activate ') + u.name + '?' +
        (next === 'inactive' ? ' They will be signed out.' : ''), function () {
        run(put('/api/admin/users/' + u.id, {
          name: u.name, email: u.email, status: next,
          municipality: u.municipality || null, association_id: u.association_id || null
        }));
      });
    }
  };

  // api.js has no PUT helper; use a tiny local one with the same auth and error handling.
  function put(path, body) {
    var base = (window.WEAVER_API_BASE || '').replace(/\/$/, '');
    return fetch(base + path, {
      method: 'PUT',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + Weaver.token() },
      body: JSON.stringify(body)
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (d) {
        if (res.status === 401) { Weaver.logout(); }
        if (!res.ok) { throw new Weaver.ApiError(res.status, d); }
        return d;
      });
    });
  }

  // toggle-weaver needs the full record, so it is finished here with put().
  actions['toggle-weaver'] = function (id) {
    var w = byId(data.weavers, id);
    if (!w) { return; }
    var next = w.status === 'active' ? 'inactive' : 'active';
    confirmThen((next === 'inactive' ? 'Deactivate ' : 'Activate ') + w.name + '?' +
      (next === 'inactive' ? ' Its tags will scan as suspicious.' : ''), function () {
      var body = { name: w.name, municipality: w.municipality, status: next };
      if (!isAssoc) { body.association_id = w.association_id; }
      run(put(API.weaver + '/' + w.id, body));
    });
  };

  // -------------------------------------------------------------------- start
  function setMeName(text) {
    var el = $('meName');
    el.textContent = text;
    el.title = text;
  }

  function init() {
    setMeName(me.name);
    $('meSub').textContent = me.email + ' (' + me.role + ')';
    $('meSub').title = me.email;
    $('panelName').textContent = isAdmin ? 'Admin Control Panel' : (isAssoc ? 'Association Portal' : 'Staff Control Panel');
    if (!isAdmin) {
      Array.prototype.forEach.call(document.querySelectorAll('.admin-only'), function (el) { el.remove(); });
    }
    if (!hasLogs) {
      Array.prototype.forEach.call(document.querySelectorAll('.logs-ui'), function (el) { el.remove(); });
    }

    $('sideNav').addEventListener('click', function (e) {
      var a = e.target.closest('[data-tab]');
      if (a) { showTab(a.getAttribute('data-tab')); }
    });
    $('logoutBtn').addEventListener('click', function () { Weaver.logout(); });
    $('refreshBtn').addEventListener('click', function () { loadAll(); });
    $('formEl').addEventListener('submit', onFormSubmit);

    $('searchInput').addEventListener('input', function () {
      state.filter = this.value.trim().toLowerCase();
      renderAll();
    });

    document.addEventListener('click', function (e) {
      var el = e.target.closest('[data-action]');
      if (!el) { return; }
      var fn = actions[el.getAttribute('data-action')];
      if (fn) { fn(el.hasAttribute('data-id') ? parseInt(el.getAttribute('data-id'), 10) : undefined); }
    });

    if (hasLogs) {
      $('logFilter').addEventListener('change', function () {
        state.logResult = this.value; state.logPage = 1;
        loadLogs().then(renderLogs);
      });
      $('logPrev').addEventListener('click', function () { state.logPage--; loadLogs().then(renderLogs); });
      $('logNext').addEventListener('click', function () { state.logPage++; loadLogs().then(renderLogs); });
    }

    showTab('dashboard');
    loadAll();
  }

  init();
})();
