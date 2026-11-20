/*
 * Weaver-ID control panel (admin, staff and association).
 * Roles (panel directive, 19 Nov 2026): the administrator approves applications, manages
 * accounts, reviews reports and fixes tags (reassign); staff register associations and
 * products, link tags and run the tag inventory; associations add products and link the
 * tags sent to them. The server enforces all of this; the page only hides what a role cannot do.
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
  var isStaff = me.role === 'staff';
  var hasLogs = isAdmin || isAssoc;
  var hasReports = isAdmin;        // only the administrator reviews reports
  var hasApps = isAdmin;           // only the administrator decides association applications
  var canEdit = !isAdmin;          // staff and associations register products and link tags
  var hasInventory = !isAssoc;     // staff run it, the administrator only looks
  var API = {
    products: isAssoc ? '/api/association/products?status=all' : '/api/staff/products',
    product: isAssoc ? '/api/association/products' : '/api/staff/products',
    logs: isAssoc ? '/api/association/scan-logs' : '/api/admin/scan-logs',
    attention: '/api/attention'
  };
  var esc = Weaver.esc;
  var READER_URL = window.WEAVER_READER_URL || 'http://127.0.0.1:8777';

  var data = { associations: [], products: [], inventory: null, invTags: null, tags: [], users: [], summary: null, logs: null, reports: null, applications: null, municipalities: [], attention: null, attentionAt: null };
  var state = { tab: 'dashboard', filter: '', logPage: 1, logResult: '', reportPage: 1, reportStatus: '', appPage: 1, appStatus: 'pending', staffMunicipality: '', invPage: 1, invStatus: 'in_stock', invBatch: '',
    seen: { report: 0, scan: 0, application: 0 } };

  // How often the page asks what needs attention. window.WEAVER_POLL_MS exists for testing only.
  var POLL_MS = window.WEAVER_POLL_MS || 60000;

  var TITLES = {
    dashboard: 'Dashboard', associations: 'Associations', products: 'Products',
    nfc: 'NFC Tags', inventory: 'Tag Inventory', logs: 'Scan Logs', reports: 'Reports', applications: 'Applications', users: 'Users'
  };
  // On the Administrator and staff dashboards the linked-tags page sits next to
  // Tag Inventory, so it is called Linked Tags there; associations keep NFC Tags.
  if (!isAssoc) { TITLES.nfc = 'Linked Tags'; }

  // Shrinks a chosen photo in the browser before it is uploaded: the longest
  // side becomes at most 1000 px and the file is saved as JPEG, which usually
  // turns a 3-8 MB phone photo into 100-300 KB. If anything goes wrong the
  // original file is returned and the server's own limits still apply.
  var PHOTO_MAX_SIDE = 1000;
  var PHOTO_KEEP_BELOW = 300 * 1024;

  function shrinkPhoto(file) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        URL.revokeObjectURL(url);
        var scale = Math.min(1, PHOTO_MAX_SIDE / Math.max(img.naturalWidth, img.naturalHeight));
        if (scale === 1 && file.size <= PHOTO_KEEP_BELOW) { resolve(file); return; }
        var canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff'; // JPEG has no transparency
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(function (blob) {
          if (!blob || blob.size >= file.size) { resolve(file); return; }
          blob.name = file.name.replace(/\.[^.]+$/, '') + '.jpg';
          resolve(blob);
        }, 'image/jpeg', 0.82);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  function $(id) { return document.getElementById(id); }

  // ------------------------------------------------------------ small helpers
  // opts.ms: how long it stays (default 4.5 s); opts.go: tab to open when it is clicked.
  function toast(message, kind, opts) {
    opts = opts || {};
    var el = document.createElement('div');
    el.className = 'alert alert-' + (kind || 'success') + ' shadow-sm py-2 fs-7';
    el.textContent = message;
    if (opts.go) {
      el.style.cursor = 'pointer';
      el.title = 'Click to open';
      el.addEventListener('click', function () { goTo(opts.go); el.remove(); });
    }
    $('toastHost').appendChild(el);
    setTimeout(function () { el.remove(); }, opts.ms || 4500);
  }

  var TAG_STATUS = {
    in_stock: { label: 'in stock', cls: 'light text-dark border' },
    sent: { label: 'sent, not linked', cls: 'primary' },
    damaged: { label: 'damaged or lost', cls: 'dark' }
  };

  // Scan results use the same words as the mobile app. The stored values stay
  // authenticated / unregistered / suspicious; only the label changes.
  var RESULT_LABEL = { authenticated: 'Genuine', unregistered: 'Not registered', suspicious: 'Suspicious' };
  function resultLabel(result) { return RESULT_LABEL[result] || result; }

  function badge(status) {
    if (RESULT_LABEL[status]) {
      var rc = { authenticated: 'success', unregistered: 'secondary', suspicious: 'danger' }[status];
      return '<span class="badge bg-' + rc + '">' + esc(RESULT_LABEL[status]) + '</span>';
    }
    if (TAG_STATUS[status]) {
      return '<span class="badge bg-' + TAG_STATUS[status].cls + '">' + esc(TAG_STATUS[status].label) + '</span>';
    }
    var map = { active: 'success', inactive: 'secondary', revoked: 'danger',
      authenticated: 'success', unregistered: 'secondary', suspicious: 'danger' };
    return '<span class="badge bg-' + (map[status] || 'secondary') + '">' + esc(status) + '</span>';
  }

  // Tag status in tag lists: 'active' reads as 'linked' next to the inventory states.
  function tagBadge(status) {
    return status === 'active' ? '<span class="badge bg-success">linked</span>' : badge(status);
  }

  function securedBadge(t) {
    if (t.status !== 'active') { return ''; }
    return t.counter_enabled
      ? ' <span class="badge bg-info text-dark" title="Write password set and read counter on' + (t.code_enabled ? ', tag code written' : '; no tag code yet (Re-secure to add it)') + '">secured</span>'
      : ' <span class="badge bg-warning text-dark" title="This tag has no write password or counter yet">not secured</span>';
  }

  var REASONS = {
    tag_revoked: 'tag revoked', product_inactive: 'product inactive', association_inactive: 'association inactive',
    weaver_inactive: 'member inactive (before members were removed)',
    many_devices: 'many devices', counter_rollback: 'possible copy (counter went back)',
    counter_missing: 'secured tag read without counter',
    counter_jump: 'counter jumped too far (not saved)',
    code_missing: 'secured tag read without its tag code',
    code_invalid: 'tag code does not match this UID (copied or forged)'
  };

  var REPORT_STATUS = {
    new: { label: 'New', cls: 'warning text-dark' },
    under_review: { label: 'Under review', cls: 'info text-dark' },
    verified: { label: 'Verified', cls: 'danger' },
    dismissed: { label: 'Dismissed', cls: 'secondary' },
    referred: { label: 'Referred', cls: 'primary' }
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

  // ------------------------------------------------------------ list filters
  // Each list tab has its own dropdowns: status (Active only by default), and where it applies
  // association and sort order. They only narrow what is already loaded: nothing is
  // sent to the server, and the dashboard tiles still count everything.
  // "Deactivated" means status 'inactive' for products, associations and users,
  // and 'revoked' or 'damaged' for NFC tags (a tag sent but not linked yet counts as active).
  // The two product categories named at the PIWPC forum (12 Nov 2026), with a short hint for the form.
  var CATEGORIES = [
    ['Indigenous Traditional Garment', 'prescribed design, used in rituals'],
    ['Traditional Woven Fabric', 'woven on a backstrap or loom, or made from it']
  ];

  var FILTERS = {
    associations: { assoc: false, sorts: [['name', 'Name (A-Z)'], ['municipality', 'Municipality'], ['newest', 'Newest first']] },
    products: { assoc: !isAssoc, cat: true, sorts: [['name', 'Name (A-Z)'], ['association', 'Association'], ['newest', 'Newest first']] },
    nfc: { assoc: !isAssoc, sorts: [['newest', 'Newest first'], ['association', 'Association'], ['name', 'Product (A-Z)']] },
    users: { assoc: false, role: true, muni: true, sorts: [['name', 'Name (A-Z)'], ['newest', 'Newest first']] }
  };
  var listState = {};
  Object.keys(FILTERS).forEach(function (k) {
    listState[k] = { status: 'active', assoc: '', role: '', muni: '', cat: '', sort: FILTERS[k].sorts[0][0] };
  });

  function isActiveStatus(status) { return ['active', 'sent', 'in_stock'].indexOf(status || 'active') !== -1; }

  // What each list row is, for filtering and sorting.
  function rowInfo(list, x) {
    if (list === 'associations') {
      return { status: x.status, name: x.name, municipality: x.municipality };
    }
    if (list === 'products') {
      return { status: x.status, assocId: x.association_id, assoc: productAssociation(x), name: x.title, cat: x.category };
    }
    if (list === 'nfc') {
      var p = x.product || {};
      return { status: x.status, assocId: x.association_id, assoc: x.association ? x.association.name : '', name: p.title || '' };
    }
    return { status: x.status, role: x.role, name: x.name, muni: userMunicipality(x) };
  }

  // Where a user works: staff by their municipality, association accounts by their association's.
  function userMunicipality(u) {
    if (u.role === 'staff') { return u.municipality || ''; }
    if (u.role === 'association') { return u.association ? (u.association.municipality || '') : ''; }
    return '';
  }

  function applyListFilters(list, items) {
    var f = listState[list], cfg = FILTERS[list];
    var shown = items.filter(function (x) {
      var r = rowInfo(list, x);
      if (f.status === 'active' && !isActiveStatus(r.status)) { return false; }
      if (f.status === 'deactivated' && isActiveStatus(r.status)) { return false; }
      if (cfg.assoc && f.assoc && String(r.assocId) !== f.assoc) { return false; }
      if (cfg.role && f.role && r.role !== f.role) { return false; }
      if (cfg.muni && f.muni && r.muni !== f.muni) { return false; }
      if (cfg.cat && f.cat && r.cat !== f.cat) { return false; }
      return true;
    });
    var key = f.sort;
    return shown.slice().sort(function (a, b) {
      if (key === 'newest') { return (b.id || 0) - (a.id || 0); }
      var ra = rowInfo(list, a), rb = rowInfo(list, b);
      var va = String(ra[key] || '').toLowerCase(), vb = String(rb[key] || '').toLowerCase();
      var c = va.localeCompare(vb);
      return c !== 0 ? c : String(ra.name || '').toLowerCase().localeCompare(String(rb.name || '').toLowerCase());
    });
  }

  function selectHtml(list, field, label, options, value) {
    return '<label class="d-flex align-items-center gap-1 fs-8 text-muted mb-0">' + esc(label) +
      '<select class="form-select form-select-sm" style="width:auto" data-list-filter="' + esc(list) + '" data-field="' + esc(field) + '">' +
      options.map(function (o) {
        return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(value) ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
      }).join('') + '</select></label>';
  }

  function renderListFilters(list, total, shown) {
    var host = $('filters-' + list);
    if (!host) { return; }
    var f = listState[list], cfg = FILTERS[list];
    var parts = [selectHtml(list, 'status', 'Show', [['active', 'Active only'], ['deactivated', 'Deactivated only'], ['all', 'All']], f.status)];
    if (cfg.assoc) {
      parts.push(selectHtml(list, 'assoc', 'Association', [['', 'All associations']].concat(data.associations.map(function (a) { return [String(a.id), a.name]; })), f.assoc));
    }
    if (cfg.role) {
      parts.push(selectHtml(list, 'role', 'Role', [['', 'All roles'], ['admin', 'Admin'], ['staff', 'Staff'], ['association', 'Association']], f.role));
    }
    if (cfg.cat) {
      parts.push(selectHtml(list, 'cat', 'Category', [['', 'All categories']].concat(CATEGORIES.map(function (c) { return [c[0], c[0]]; })), f.cat));
    }
    if (cfg.muni) {
      var munis = {};
      (data.users || []).forEach(function (u) { var m = userMunicipality(u); if (m) { munis[m] = true; } });
      parts.push(selectHtml(list, 'muni', 'Municipality', [['', 'All municipalities']].concat(Object.keys(munis).sort().map(function (m) { return [m, m]; })), f.muni));
    }
    parts.push(selectHtml(list, 'sort', 'Sort by', cfg.sorts, f.sort));
    var hidden = total - shown;
    parts.push('<span class="text-muted fs-8 ms-auto">Showing ' + esc(shown) + ' of ' + esc(total) +
      (hidden > 0 ? ' (' + esc(hidden) + ' hidden by the filters)' : '') + '</span>');
    host.innerHTML = parts.join('');
  }

  function onListFilterChange(e) {
    var sel = e.target.closest('[data-list-filter]');
    if (!sel) { return; }
    var list = sel.getAttribute('data-list-filter');
    listState[list][sel.getAttribute('data-field')] = sel.value;
    renderAll();
  }

  // ------------------------------------------------------------------ loading
  function loadAll() {
    $('loadError').classList.add('d-none');
    var jobs = [
      Weaver.get(API.products).then(function (r) { data.products = r.products || []; }),
      Weaver.get('/api/nfc-tags').then(function (r) { data.tags = r.tags || []; })
    ];
    if (!isAssoc) {
      jobs.push(Weaver.get('/api/staff/associations').then(function (r) { data.associations = r.associations || []; }));
    }
    if (isStaff) {
      jobs.push(Weaver.get('/api/staff/association-applications').then(function (r) { data.staffApps = r.applications || []; }).catch(function () { data.staffApps = []; }));
    }
    if (hasInventory) { jobs.push(loadInventory()); }
    if (hasLogs) { jobs.push(loadLogs()); }
    if (hasReports) { jobs.push(loadReports()); }
    if (hasApps) { jobs.push(loadApplications()); }
    jobs.push(loadAttention());
    if (isAdmin) {
      jobs.push(Weaver.get('/api/admin/summary').then(function (r) { data.summary = r; }));
      jobs.push(Weaver.get('/api/admin/users').then(function (r) { data.users = r.users || []; }));
      // The list for the staff Municipality choice in Add user. If it cannot be loaded the form falls back to typing.
      jobs.push(Weaver.get('/api/municipalities').then(function (r) { data.municipalities = r.municipalities || []; }).catch(function () { data.municipalities = []; }));
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

  function loadInventory() {
    var q = '?page=' + state.invPage + (state.invStatus ? '&status=' + encodeURIComponent(state.invStatus) : '') +
      (state.invBatch ? '&batch_id=' + encodeURIComponent(state.invBatch) : '');
    return Promise.all([
      Weaver.get('/api/inventory').then(function (r) { data.inventory = r; }),
      Weaver.get('/api/inventory/tags' + q).then(function (r) { data.invTags = r; }),
      isAdmin ? Weaver.get('/api/admin/tag-assignments').then(function (r) { data.assignments = r; }) : null
    ]);
  }

  function loadLogs() {
    var q = '?page=' + state.logPage + (state.logResult ? '&result=' + encodeURIComponent(state.logResult) : '');
    return Weaver.get(API.logs + q).then(function (r) { data.logs = r; });
  }

  // The panel and the badge are extras: if this call fails the rest of the page still loads.
  function loadAttention() {
    return Weaver.get(API.attention).then(function (a) {
      data.attention = a;
      data.attentionAt = new Date();
      state.seen = newestIds(a, state.seen);
    }).catch(function () { data.attention = null; });
  }

  function loadApplications() {
    var q = '?page=' + state.appPage + (state.appStatus ? '&status=' + encodeURIComponent(state.appStatus) : '');
    return Weaver.get('/api/applications' + q).then(function (r) { data.applications = r; });
  }

  function loadReports() {
    var q = '?page=' + state.reportPage + (state.reportStatus ? '&status=' + encodeURIComponent(state.reportStatus) : '');
    return Weaver.get('/api/reports' + q).then(function (r) { data.reports = r; });
  }

  // ---------------------------------------------------------------- rendering
  function renderAll() {
    renderTiles();
    renderAttention();
    renderBadges();
    renderDashboardExtras();
    if (!isAssoc) { renderAssociations(); }
    if (hasInventory) { renderInventory(); }
    renderProducts();
    renderTags();
    if (hasLogs) { renderLogs(); }
    if (hasReports) { renderReports(); }
    if (hasApps) { renderApplications(); }
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
      html = tile('bi-building', 'icon-weavers', s.associations.active, 'Active associations', 'of ' + s.associations.total + ' registered') +
        tile('bi-box-seam-fill', 'icon-products', s.products.total, 'Products', s.products.with_active_tag + ' have an active tag') +
        tile('bi-wifi', 'icon-nfc', s.tags.active, 'Linked tags', s.tags.in_stock + ' in stock, ' + s.tags.sent + ' sent, ' + s.tags.revoked + ' revoked') +
        tile('bi-check-circle-fill', 'icon-verifications', s.scans.total, 'Total scans', s.scans.suspicious + ' suspicious');
    } else if (isAssoc) {
      var act = data.tags.filter(function (t) { return t.status === 'active'; }).length;
      var waiting = data.tags.filter(function (t) { return t.status === 'sent'; }).length;
      html = tile('bi-archive', 'icon-weavers', waiting, 'Tags ready to link', 'sent to you by DTI') +
        tile('bi-box-seam-fill', 'icon-products', data.products.length, 'Products', data.products.filter(function (p) { return activeTagFor(p.id); }).length + ' have an active tag') +
        tile('bi-wifi', 'icon-nfc', act, 'Linked tags', data.tags.filter(function (t) { return t.status === 'revoked'; }).length + ' revoked') +
        tile('bi-check-circle-fill', 'icon-verifications', data.logs ? data.logs.total : 0, 'Scans of your tags', '');
    } else {
      var active = data.tags.filter(function (t) { return t.status === 'active'; }).length;
      var totals = data.inventory ? data.inventory.totals : null;
      html = tile('bi-archive', 'icon-weavers', totals ? totals.in_stock : 0, 'Tags in stock', 'your Negosyo Center') +
        tile('bi-box-seam-fill', 'icon-products', data.products.length, 'Products', 'in your municipality') +
        tile('bi-wifi', 'icon-nfc', active, 'Linked tags', 'in your municipality') +
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
      return '<div class="mb-2"><div class="d-flex justify-content-between fs-8"><span>' + esc(resultLabel(r[0])) +
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
        '<div class="fw-bold fs-7">' + esc(resultLabel(l.result)) + '</div>' +
        '<div class="text-muted fs-8">' + esc(l.uid) + ' | ' + esc(product) + '</div></div></div>' +
        '<span class="text-muted fs-8">' + when(l.created_at) + '</span></div>';
    }).join('<hr class="my-1">') : '<div class="text-muted fs-7">No scans yet.</div>';
  }

  // ------------------------------------------------------------ attention panel
  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : (many || one + 's')); }

  function newestIds(a, previous) {
    var newestReport = a && a.new_reports && a.new_reports.latest.length ? a.new_reports.latest[0].id : 0;
    var newestScan = a && a.suspicious_scans && a.suspicious_scans.latest.length ? a.suspicious_scans.latest[0].id : 0;
    var newestApplication = a && a.pending_applications && a.pending_applications.latest.length ? a.pending_applications.latest[0].id : 0;
    // Never go backwards: deciding the newest one must not make an older one look new.
    return {
      report: Math.max(newestReport, previous ? previous.report : 0),
      scan: Math.max(newestScan, previous ? previous.scan : 0),
      application: Math.max(newestApplication, previous ? previous.application : 0)
    };
  }

  function attentionBlock(title, goTab, goLabel, items, total, line) {
    var rows = items.map(function (x) {
      return '<li class="d-flex justify-content-between gap-3 py-1 border-top"><span class="text-break">' + line(x) +
        '</span><span class="text-muted fs-8 text-nowrap">' + when(x.created_at) + '</span></li>';
    }).join('');
    return '<div class="attn-block"><div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">' +
      '<div class="fw-semibold">' + esc(title) + '</div>' +
      '<button type="button" class="btn btn-sm btn-dti" data-goto="' + esc(goTab) + '">' + esc(goLabel) + '</button></div>' +
      '<ul class="list-unstyled fs-7 mb-0 mt-2">' + rows + '</ul>' +
      (total > items.length ? '<div class="text-muted fs-8 mt-1">and ' + esc(total - items.length) + ' more in the list.</div>' : '') +
      '</div>';
  }

  function renderAttention() {
    var host = $('attention');
    var a = data.attention;
    if (!host || !a) { if (host) { host.innerHTML = ''; } return; }

    var blocks = [];
    var r = a.new_reports;
    if (r && r.count > 0) {
      blocks.push(attentionBlock(plural(r.count, 'new report') + ' waiting for review', 'reports', 'Open Reports', r.latest, r.count,
        function (x) {
          return '<code>' + esc(x.reference) + '</code> ' + esc(x.product || 'Unknown tag') +
            ' <span class="text-muted">' + esc(REASONS[x.flag_reason] || x.flag_reason || '') + '</span>';
        }));
    }
    var ap = a.pending_applications;
    if (ap && ap.count > 0) {
      blocks.push(attentionBlock(plural(ap.count, 'association application') + ' waiting for a decision', 'applications', 'Open Applications', ap.latest, ap.count,
        function (x) {
          return '<code>' + esc(x.reference) + '</code> ' + esc(x.association_name) + ' <span class="text-muted">' + esc(x.municipality) + '</span>';
        }));
    }
    var w = a.wrong_center_tags;
    if (w && w.count > 0) {
      blocks.push(attentionBlock(plural(w.count, 'tag') + ' scanned in at the wrong Negosyo Center', 'inventory', 'Open Tag Inventory', w.latest, w.count,
        function (x) {
          return '<code>' + esc(x.uid) + '</code> assigned to ' + esc(x.assigned_municipality) +
            ' <span class="text-muted">tried at ' + esc(x.attempted_municipality) + '</span>';
        }));
    }
    var s = a.suspicious_scans;
    if (s && s.count > 0) {
      var hours = a.window_hours === 24 ? 'in the last 24 hours' : 'in the last ' + plural(a.window_hours, 'hour');
      blocks.push(attentionBlock(plural(s.count, 'suspicious scan') + (isAssoc ? ' of your tags ' : ' ') + hours, 'logs', 'Open Scan Logs', s.latest, s.count,
        function (x) {
          return '<code>' + esc(x.uid) + '</code> ' + esc(x.product || 'Unknown tag') +
            ' <span class="text-muted">' + esc(REASONS[x.flag_reason] || x.flag_reason || '') + '</span>';
        }));
    }

    var checked = data.attentionAt ? 'Checked ' + data.attentionAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + '. Updates every minute.' : '';
    if (!blocks.length) {
      host.innerHTML = '<div class="attention-card attention-clear d-flex align-items-center justify-content-between gap-2 flex-wrap">' +
        '<div><i class="bi bi-check-circle-fill text-success me-2"></i><span class="fw-semibold">Nothing needs attention right now.</span></div>' +
        '<span class="text-muted fs-8">' + esc(checked) + '</span></div>';
      return;
    }
    host.innerHTML = '<div class="attention-card">' +
      '<div class="d-flex align-items-center justify-content-between gap-2 mb-2 flex-wrap">' +
      '<h6 class="fw-bold mb-0"><i class="bi bi-bell-fill text-danger me-2"></i>Needs attention</h6>' +
      '<span class="text-muted fs-8">' + esc(checked) + '</span></div>' +
      blocks.join('<hr class="my-2">') + '</div>';
  }

  function setBadge(id, n, noun) {
    var badgeEl = $(id);
    if (!badgeEl) { return; }
    badgeEl.textContent = n > 99 ? '99+' : String(n);
    badgeEl.setAttribute('aria-label', plural(n, noun));
    badgeEl.classList.toggle('d-none', n === 0);
  }

  function renderBadges() {
    var a = data.attention;
    setBadge('reportsBadge', a && a.new_reports ? a.new_reports.count : 0, 'new report');
    setBadge('appsBadge', a && a.pending_applications ? a.pending_applications.count : 0, 'application waiting for a decision');
    setBadge('invBadge', a && a.wrong_center_tags ? a.wrong_center_tags.count : 0, 'tag at the wrong Negosyo Center');
  }

  // Opens a tab from the panel or a toast; reports and logs open already narrowed to what needs attention.
  function goTo(tab) {
    if (tab === 'reports' && hasReports) {
      state.reportStatus = 'new'; state.reportPage = 1;
      $('reportFilter').value = 'new';
      loadReports().then(renderReports);
    } else if (tab === 'applications' && hasApps) {
      state.appStatus = 'pending'; state.appPage = 1;
      $('applicationFilter').value = 'pending';
      loadApplications().then(renderApplications);
    } else if (tab === 'logs' && hasLogs) {
      state.logResult = 'suspicious'; state.logPage = 1;
      $('logFilter').value = 'suspicious';
      loadLogs().then(renderLogs);
    }
    showTab(tab);
  }

  // Asks the server what needs attention. When something new arrived since the last look,
  // says so in a toast and reloads the lists so the tabs match.
  var polling = false;
  function poll() {
    if (polling || document.hidden) { return; }
    polling = true;
    Weaver.get(API.attention).then(function (a) {
      var before = state.seen;
      var firstLook = !data.attention;      // the first load failed or has not finished: set the baseline, announce nothing
      var fresh = [];
      var newReports = a.new_reports ? a.new_reports.latest.filter(function (x) { return x.id > before.report; }).length : 0;
      var newScans = a.suspicious_scans ? a.suspicious_scans.latest.filter(function (x) { return x.id > before.scan; }).length : 0;
      var newApps = a.pending_applications ? a.pending_applications.latest.filter(function (x) { return x.id > before.application; }).length : 0;
      if (newReports && !firstLook) {
        fresh.push(function () { toast((newReports >= 5 ? '5 or more' : String(newReports)) + ' new ' + (newReports === 1 ? 'report' : 'reports') + ' received.', 'warning', { ms: 12000, go: 'reports' }); });
      }
      if (newApps && !firstLook) {
        fresh.push(function () { toast((newApps >= 5 ? '5 or more' : String(newApps)) + ' new association ' + (newApps === 1 ? 'application' : 'applications') + ' waiting.', 'info', { ms: 12000, go: 'applications' }); });
      }
      if (newScans && !firstLook) {
        fresh.push(function () { toast((newScans >= 5 ? '5 or more' : String(newScans)) + ' new suspicious ' + (newScans === 1 ? 'scan' : 'scans') + '.', 'danger', { ms: 12000, go: 'logs' }); });
      }
      data.attention = a;
      data.attentionAt = new Date();
      state.seen = newestIds(a, before);
      renderAttention();
      renderBadges();
      if (fresh.length) {
        fresh.forEach(function (f) { f(); });
        return loadAll();
      }
    }).catch(function () { /* a missed check is not worth an error message; the next one tries again */ })
      .then(function () { polling = false; });
  }

  function renderAssociations() {
    var list = applyListFilters('associations', data.associations).filter(function (a) {
      return matches([a.name, a.municipality]);
    });
    renderListFilters('associations', data.associations.length, list.length);
    var cards = list.map(function (a) {
      return '<div class="col-12 col-md-6 col-xl-4"><div class="association-box"><div class="d-flex justify-content-between">' +
        '<h6 class="fw-bold mb-1">' + esc(a.name) + '</h6>' + badge(a.status || 'active') + '</div>' +
        '<div class="text-muted fs-7"><i class="bi bi-geo-alt"></i> ' + esc(a.municipality || '-') + '</div>' +
        '<div class="text-muted fs-7"><i class="bi bi-box-seam"></i> ' + esc(a.products_count) + ' product(s)</div></div></div>';
    });
    $('associationsBody').innerHTML = cards.length ? cards.join('') :
      '<div class="col-12"><div class="dashboard-card text-muted text-center">No associations to show.</div></div>';
    renderStaffApplications();
  }

  // Staff see what they (or the public page) filed in their municipality, and what the administrator decided.
  function renderStaffApplications() {
    var host = $('staffAppsBody');
    if (!host) { return; }
    var items = (data.staffApps || []).filter(function (x) { return x.status === 'pending' || x.status === 'rejected'; });
    $('staffAppsCard').classList.toggle('d-none', !items.length);
    host.innerHTML = items.map(function (x) {
      var st = APPLICATION_STATUS[x.status] || { label: x.status, cls: 'secondary' };
      return '<tr><td class="text-nowrap">' + when(x.created_at) + '<div class="text-muted fs-8"><code>' + esc(x.reference) + '</code></div></td>' +
        '<td class="fw-semibold">' + esc(x.association_name) + '</td><td>' + esc(x.contact_name) + '</td>' +
        '<td>' + esc(x.submitted_by || 'public page') + '</td>' +
        '<td><span class="badge bg-' + st.cls + '">' + esc(st.label) + '</span>' +
        (x.decision_note ? '<div class="fs-8 mt-1 text-wrap" style="max-width:16rem">' + esc(x.decision_note) + '</div>' : '') + '</td></tr>';
    }).join('');
  }

  function renderProducts() {
    var list = applyListFilters('products', data.products).filter(function (p) {
      return matches([p.title, p.category, productAssociation(p)]);
    });
    renderListFilters('products', data.products.length, list.length);
    var rows = list.map(function (p) {
      var tag = activeTagFor(p.id);
      var thumb = p.photo_url
        ? '<img src="' + esc(p.photo_url) + '" alt="" title="' + esc(photoNote(p)) + '" width="36" height="36" class="rounded me-2 border" style="object-fit:cover">'
        : '<span class="d-inline-flex align-items-center justify-content-center rounded me-2 border text-muted" style="width:36px;height:36px;vertical-align:middle"><i class="bi bi-image"></i></span>';
      return '<tr><td class="fw-semibold">' + thumb + esc(p.title) + '</td><td>' + esc(p.category) + '</td>' +
        (isAssoc ? '' : '<td>' + esc(productAssociation(p) || '-') + '</td>') + '<td>' +
        (tag ? '<code>' + esc(tag.uid) + '</code>' : '<span class="text-muted">none</span>') + '</td><td>' + badge(p.status) +
        '</td><td class="text-end pe-3" style="white-space:normal;min-width:200px">' +
        // The administrator only looks: staff and the association register and photograph products.
        (!canEdit ? '<span class="text-muted fs-8">view only</span>' :
        '<button class="btn btn-sm btn-outline-primary py-0 me-1" data-action="photo-product" data-id="' +
        esc(p.id) + '">' + (p.photo_url ? 'Change photo' : 'Add photo') + '</button>' +
        (p.photo_url ? '<button class="btn btn-sm btn-outline-danger py-0 me-1" data-action="remove-photo" data-id="' + esc(p.id) + '">Remove photo</button>' : '') +
        '<button class="btn btn-sm btn-outline-secondary py-0" data-action="toggle-product" data-id="' +
        esc(p.id) + '">' + (p.status === 'active' ? 'Deactivate' : 'Activate') + '</button>') + '</td></tr>';
    });
    $('productsBody').innerHTML = rows.length ? rows.join('') : empty(isAssoc ? 5 : 6, 'No products to show.');
  }

  // Tags that went out to an association. Tags still in a Negosyo Center's stock live in the Inventory tab (the server leaves them out of this list).
  function outTags() {
    return data.tags.filter(function (t) { return t.association_id; });
  }

  function renderTags() {
    var all = outTags();
    var list = applyListFilters('nfc', all).filter(function (t) {
      var p = t.product || {};
      return matches([t.uid, p.title, t.association ? t.association.name : '', t.status]);
    });
    renderListFilters('nfc', all.length, list.length);
    var rows = list.map(function (t) {
      var p = t.product || {}, a = t.association || {};
      var actions = '';
      if (t.status === 'active' && canEdit) {
        actions += '<button class="btn btn-sm btn-outline-primary py-0 me-1" data-action="secure-tag" data-id="' + esc(t.id) + '">' +
          (t.counter_enabled ? 'Re-secure' : 'Secure') + '</button>';
      }
      if (t.status === 'active') {
        actions += '<button class="btn btn-sm btn-outline-danger py-0 me-1" data-action="revoke-tag" data-id="' + esc(t.id) + '">Revoke</button>';
      }
      if (isAdmin && (t.status === 'active' || t.status === 'revoked')) {
        actions += '<button class="btn btn-sm btn-outline-secondary py-0" data-action="reassign-tag" data-id="' + esc(t.id) + '">Reassign</button>';
      }
      if (t.status === 'sent' && canEdit) {
        actions += '<button class="btn btn-sm btn-outline-success py-0" data-action="issue-tag" data-uid="' + esc(t.uid) + '">Link to a product</button>';
      }
      return '<tr><td><code>' + esc(t.uid) + '</code></td><td>' + esc(p.title || '-') + '</td><td>' +
        (isAssoc ? when(t.sent_at) : esc(a.name || '-')) + '</td><td>' + (t.status === 'active' || t.status === 'revoked' ? when(t.issued_at) : '-') +
        '</td><td>' + tagBadge(t.status) + securedBadge(t) +
        '</td><td class="text-end pe-3">' + actions + '</td></tr>';
    });
    $('tagsBody').innerHTML = rows.length ? rows.join('') : empty(6, isAssoc ? 'No tags yet. DTI staff send tags to your association.' : 'No tags to show.');
  }

  // ---------------------------------------------------------------- inventory
  function invCount(n) { return n ? esc(n) : '<span class="text-muted">0</span>'; }

  function renderInventory() {
    var inv = data.inventory;
    if (!inv || !$('invTiles')) { return; }
    var t = inv.totals;
    var where = inv.municipality ? inv.municipality + ' Negosyo Center' : 'all Negosyo Centers';
    if ($('invScope')) {
      $('invScope').textContent = inv.municipality
        ? 'The stock of the ' + where + '. You receive tags here, send them to associations in ' + inv.municipality + ', and each tag keeps a record of where it went.'
        : 'Each Negosyo Center receives and keeps its own stock. This page shows every center; only staff change the inventory.';
    }
    $('invTiles').innerHTML =
      tile('bi-archive', 'icon-weavers', t.in_stock, 'In stock', where) +
      tile('bi-send', 'icon-products', t.sent, 'Sent, not linked', 'at associations') +
      tile('bi-wifi', 'icon-nfc', t.active, 'Linked', t.revoked + ' revoked') +
      tile('bi-x-octagon', 'icon-verifications', t.damaged, 'Damaged or lost', '');

    $('invBatchesBody').innerHTML = inv.batches.length ? inv.batches.map(function (b) {
      var c = b.counts;
      return '<tr><td class="fw-semibold">' + esc(b.code) + (b.notes ? '<div class="text-muted fs-8 fw-normal text-wrap" style="max-width:16rem">' + esc(b.notes) + '</div>' : '') +
        '</td><td>' + esc(b.municipality || '-') + '</td><td>' + esc(b.received_on || '-') + '</td><td>' + esc(b.supplier || '-') + '</td><td>' + esc(b.received_by || '-') +
        '</td><td>' + invCount(c.in_stock) + '</td><td>' + invCount(c.sent) + '</td><td>' + invCount(c.active) +
        '</td><td>' + invCount(c.revoked) + '</td><td>' + invCount(c.damaged) + '</td><td class="text-end pe-3">' +
        (isStaff ? '<button class="btn btn-sm btn-outline-secondary py-0" data-action="add-to-batch" data-id="' + esc(b.id) + '">Add tags</button>' : '') +
        '</td></tr>';
    }).join('') : empty(11, 'No batches yet.' + (isStaff ? ' Use Receive tags.' : ''));

    if ($('invMuniBody')) {
      var munis = inv.municipalities || [];
      $('invMuniCard').classList.toggle('d-none', !!inv.municipality);
      $('invMuniBody').innerHTML = munis.length ? munis.map(function (m) {
        var c = m.counts;
        return '<tr><td class="fw-semibold">' + esc(m.municipality) + '</td><td>' + esc(m.batches) + '</td><td>' + invCount(c.in_stock) +
          '</td><td>' + invCount(c.sent) + '</td><td>' + invCount(c.active) + '</td><td>' + invCount(c.revoked) + '</td><td>' + invCount(c.damaged) + '</td></tr>';
      }).join('') : empty(7, 'No Negosyo Center has received tags yet.');
    }

    renderAssignments();

    $('invAssocBody').innerHTML = inv.associations.length ? inv.associations.map(function (a) {
      var c = a.counts;
      return '<tr><td class="fw-semibold">' + esc(a.name) + '</td><td>' + esc(a.municipality || '-') + '</td><td>' + invCount(c.sent) +
        '</td><td>' + invCount(c.active) + '</td><td>' + invCount(c.revoked) + '</td><td>' + invCount(c.damaged) + '</td></tr>';
    }).join('') : empty(6, 'No associations to show.');

    var batchSel = $('invBatch');
    batchSel.innerHTML = '<option value="">All batches</option>' + inv.batches.map(function (b) {
      return '<option value="' + esc(b.id) + '"' + (String(b.id) === String(state.invBatch) ? ' selected' : '') + '>' + esc(b.code) + '</option>';
    }).join('');

    var page = data.invTags, items = page && page.data ? page.data : [];
    var rows = items.filter(function (x) {
      return matches([x.uid, x.status, x.batch ? x.batch.code : '', x.association ? x.association.name : '', x.product ? x.product.title : '']);
    }).map(function (x) {
      var acts = '';
      if (isStaff && x.status === 'sent') {
        acts += '<button class="btn btn-sm btn-outline-secondary py-0 me-1" data-action="return-tag" data-id="' + esc(x.id) + '">Back to stock</button>';
      }
      if (isStaff && (x.status === 'in_stock' || x.status === 'sent' || x.status === 'revoked')) {
        acts += '<button class="btn btn-sm btn-outline-danger py-0" data-action="damage-tag" data-id="' + esc(x.id) + '">Damaged or lost</button>';
      }
      return '<tr><td><code>' + esc(x.uid) + '</code></td><td>' + esc(x.batch ? x.batch.code : '-') + '</td><td>' + tagBadge(x.status) +
        '</td><td>' + esc(x.association ? x.association.name : '-') + '</td><td>' +
        (x.sent_at ? esc(x.sender ? x.sender.name : '-') + '<div class="text-muted fs-8">' + when(x.sent_at) + '</div>' : '-') +
        '</td><td>' + esc(x.product ? x.product.title : '-') + '</td><td class="text-end pe-3">' + acts + '</td></tr>';
    });
    $('invTagsBody').innerHTML = rows.length ? rows.join('') : empty(7, 'No tags match.');
    $('invPageInfo').textContent = page ? 'Page ' + page.current_page + ' of ' + page.last_page + ' (' + page.total + ' tags)' : '';
    $('invPrev').disabled = !page || page.current_page <= 1;
    $('invNext').disabled = !page || page.current_page >= page.last_page;
  }

  // Admin only: the delivery lists (which tags go to which Negosyo Center) and the wrong-center alerts.
  function renderAssignments() {
    var as = data.assignments;
    if (!as || !$('assignBody')) { return; }
    $('assignBody').innerHTML = as.municipalities.length ? as.municipalities.map(function (m) {
      return '<tr><td class="fw-semibold">' + esc(m.municipality) + '</td><td>' + invCount(m.assigned) + '</td><td>' + invCount(m.received) +
        '</td><td>' + (m.waiting ? '<span class="badge bg-warning text-dark">' + esc(m.waiting) + '</span>' : invCount(0)) + '</td></tr>';
    }).join('') : empty(4, 'No delivery lists yet. Use Assign tags to a center before the boxes ship.');
    $('alertCard').classList.toggle('d-none', !as.alerts.length);
    $('alertCount').textContent = as.open_alerts;
    $('alertBody').innerHTML = as.alerts.map(function (x) {
      return '<tr><td class="text-nowrap">' + when(x.created_at) + '</td><td><code>' + esc(x.uid) + '</code></td><td>' + esc(x.assigned_municipality) +
        '</td><td class="fw-semibold text-danger">' + esc(x.attempted_municipality) + '<div class="text-muted fs-8 fw-normal">' + esc(x.attempted_by || '-') + '</div></td>' +
        '<td class="text-end pe-3 text-nowrap"><button class="btn btn-sm btn-outline-primary py-0 me-1" data-action="move-alert" data-id="' + esc(x.id) + '">Move to ' + esc(x.attempted_municipality) + '</button>' +
        '<button class="btn btn-sm btn-outline-secondary py-0" data-action="dismiss-alert" data-id="' + esc(x.id) + '">Dismiss</button></td></tr>';
    }).join('');
  }

  function renderLogs() {
    var l = data.logs;
    var items = l && l.data ? l.data : [];
    var rows = items.filter(function (x) {
      return matches([x.uid, x.result, x.nfc_tag && x.nfc_tag.product ? x.nfc_tag.product.title : '']);
    }).map(function (x) {
      return '<tr><td>' + when(x.created_at) + '</td><td><code>' + esc(x.uid) + '</code></td><td>' + badge(x.result) +
        (x.flag_reason ? ' <span class="text-muted fs-8">' + esc(REASONS[x.flag_reason] || x.flag_reason) + '</span>' : '') +
        '</td><td>' + esc(x.nfc_tag && x.nfc_tag.product ? x.nfc_tag.product.title : '-') + '</td><td>' + esc(x.device_id || x.ip_address || '-') +
        '</td><td>' + (x.counter === null || x.counter === undefined ? '-' : esc(x.counter)) + '</td></tr>';
    });
    $('logsBody').innerHTML = rows.length ? rows.join('') : empty(6, 'No scans match.');
    $('logPageInfo').textContent = l ? 'Page ' + l.current_page + ' of ' + l.last_page + ' (' + l.total + ' scans)' : '';
    $('logPrev').disabled = !l || l.current_page <= 1;
    $('logNext').disabled = !l || l.current_page >= l.last_page;
  }

  function reportBadge(status) {
    var s = REPORT_STATUS[status] || { label: status, cls: 'secondary' };
    return '<span class="badge bg-' + s.cls + '">' + esc(s.label) + '</span>';
  }

  function renderReports() {
    var r = data.reports;
    var items = r && r.data ? r.data : [];
    var rows = items.filter(function (x) {
      return matches([x.reference, x.uid, x.location, x.status, x.product ? x.product.title : '',
        x.association ? x.association.name : '']);
    }).map(function (x) {
      return '<tr><td>' + when(x.created_at) + '</td><td><code>' + esc(x.reference) + '</code></td><td><code>' + esc(x.uid) +
        '</code></td><td>' + esc(x.product ? x.product.title : '-') +
        (x.association ? '<div class="text-muted fs-8">' + esc(x.association.name) + '</div>' : '') +
        '</td><td>' + esc(REASONS[x.flag_reason] || x.flag_reason || '-') +
        '</td><td class="text-wrap" style="min-width:12rem">' + esc(x.location) +
        '</td><td>' + esc(x.same_tag_reports) + '</td><td>' + reportBadge(x.status) +
        '</td><td class="text-end pe-3"><button class="btn btn-sm btn-outline-success py-0" data-action="review-report" data-id="' +
        esc(x.id) + '">Review</button></td></tr>';
    });
    $('reportsBody').innerHTML = rows.length ? rows.join('') : empty(9, 'No reports to show.');
    $('reportPageInfo').textContent = r ? 'Page ' + r.current_page + ' of ' + r.last_page + ' (' + r.total + ' reports)' : '';
    $('reportPrev').disabled = !r || r.current_page <= 1;
    $('reportNext').disabled = !r || r.current_page >= r.last_page;
  }

  var APPLICATION_STATUS = {
    pending: { label: 'Waiting', cls: 'warning text-dark' },
    approved: { label: 'Approved', cls: 'success' },
    rejected: { label: 'Rejected', cls: 'secondary' }
  };

  function renderApplications() {
    var r = data.applications;
    var items = r && r.data ? r.data : [];
    var rows = items.filter(function (x) {
      return matches([x.reference, x.association_name, x.municipality, x.contact_name, x.email, x.contact_phone, x.registration_details || '', x.status]);
    }).map(function (x) {
      var st = APPLICATION_STATUS[x.status] || { label: x.status, cls: 'secondary' };
      var decided = x.status === 'pending' ? '' :
        '<div class="text-muted fs-8 mt-1">' + esc(x.decided_by || 'a removed user') + ', ' + when(x.decided_at) + '</div>' +
        (x.decision_note ? '<div class="fs-8 mt-1 text-wrap" style="max-width:16rem">' + esc(x.decision_note) + '</div>' : '');
      var conflicts = (x.conflict || []).map(function (c) {
        return '<div class="text-danger fs-8 mt-1 text-wrap" style="max-width:18rem"><i class="bi bi-exclamation-triangle-fill me-1"></i>' + esc(c) + '</div>';
      }).join('');
      var actions = x.status === 'pending'
        ? '<button class="btn btn-sm btn-outline-success py-0 me-1" data-action="approve-application" data-id="' + esc(x.id) + '">Approve</button>' +
          '<button class="btn btn-sm btn-outline-danger py-0" data-action="reject-application" data-id="' + esc(x.id) + '">Reject</button>'
        : '';
      return '<tr><td class="text-nowrap">' + when(x.created_at) + '<div class="text-muted fs-8"><code>' + esc(x.reference) + '</code></div></td>' +
        '<td class="fw-semibold">' + esc(x.association_name) + '<div class="text-muted fs-8 fw-normal">' + esc(x.municipality) + '</div>' +
          (x.submitted_by ? '<span class="badge bg-info text-dark mt-1" title="Filed by staff on the association\'s behalf. Its password is temporary.">Filed by ' + esc(x.submitted_by) + '</span>' : '') + '</td>' +
        '<td style="min-width:13rem">' + esc(x.contact_name) + '<div class="text-muted fs-8">' + esc(x.contact_phone) + '</div><div class="text-muted fs-8 text-break">' + esc(x.email) + '</div>' +
          (x.submitted_by ? '' : x.email_verified
            ? '<span class="badge bg-success mt-1" title="The applicant typed the code that was emailed to this address.">Email confirmed</span>'
            : '<span class="badge bg-warning text-dark mt-1" title="Nobody has shown that this mailbox is theirs. Check the address before approving.">Email not confirmed</span>') + '</td>' +
        '<td class="text-wrap" style="min-width:9rem">' + (x.registration_details ? esc(x.registration_details) : '<span class="text-muted">not given</span>') + '</td>' +
        '<td><span class="badge bg-' + st.cls + '">' + esc(st.label) + '</span>' + decided + conflicts + '</td>' +
        '<td class="text-end pe-3 text-nowrap">' + actions + '</td></tr>';
    });
    $('applicationsBody').innerHTML = rows.length ? rows.join('') : empty(6, 'No applications to show.');
    $('applicationPageInfo').textContent = r ? 'Page ' + r.current_page + ' of ' + r.last_page + ' (' + r.total + ' applications)' : '';
    $('applicationPrev').disabled = !r || r.current_page <= 1;
    $('applicationNext').disabled = !r || r.current_page >= r.last_page;
  }

  // Users are grouped by role (administrators, staff, association accounts), each with a header
  // and a count. Inside a group, rows are ordered by municipality; each row already shows it.
  var USER_GROUPS = [['admin', 'Administrators'], ['staff', 'Staff (Negosyo Centers)'], ['association', 'Association accounts']];

  function userRow(u) {
    var scope = u.role === 'staff' ? (u.municipality || '-') : (u.role === 'association'
      ? (u.association ? u.association.name + (u.association.municipality ? ' (' + u.association.municipality + ')' : '') : '-')
      : 'Whole province');
    var self = u.id === me.id;
    return '<tr><td class="fw-semibold">' + esc(u.name) + '</td><td>' + esc(u.email) + '</td><td>' + esc(u.role) +
      '</td><td>' + esc(scope) + '</td><td>' + badge(u.status) +
      (u.must_change_password ? ' <span class="badge bg-warning text-dark" title="This account still has its temporary password.">password not changed yet</span>' : '') +
      '</td><td class="text-end pe-3">' +
      (self ? '<span class="text-muted fs-8">you (use the key button)</span>' :
        '<button class="btn btn-sm btn-outline-secondary py-0 me-1" data-action="reset-user-password" data-id="' + esc(u.id) + '">Reset password</button>' +
        '<button class="btn btn-sm btn-outline-secondary py-0" data-action="toggle-user" data-id="' + esc(u.id) + '">' +
        (u.status === 'active' ? 'Deactivate' : 'Activate') + '</button>') + '</td></tr>';
  }

  function groupHeader(text, count) {
    return '<tr class="table-secondary"><td colspan="6" class="fw-semibold">' +
      esc(text) + ' <span class="badge bg-light text-dark border ms-1">' + esc(count) + '</span></td></tr>';
  }

  function renderUsers() {
    var list = applyListFilters('users', data.users).filter(function (u) {
      return matches([u.name, u.email, u.role, u.municipality || '', u.association ? u.association.name : '', userMunicipality(u)]);
    });
    renderListFilters('users', data.users.length, list.length);
    var rows = [];
    USER_GROUPS.forEach(function (g) {
      var inGroup = list.filter(function (u) { return u.role === g[0]; });
      if (!inGroup.length) { return; }
      rows.push(groupHeader(g[1], inGroup.length));
      if (g[0] !== 'admin') {
        // Same municipality together, then association, then the order the Sort by filter chose.
        inGroup = inGroup.map(function (u, i) { return [u, i]; }).sort(function (x, y) {
          var c = userMunicipality(x[0]).localeCompare(userMunicipality(y[0]));
          if (c === 0 && g[0] === 'association') {
            c = (x[0].association ? x[0].association.name : '').localeCompare(y[0].association ? y[0].association.name : '');
          }
          return c !== 0 ? c : x[1] - y[1];
        }).map(function (x) { return x[0]; });
      }
      inGroup.forEach(function (u) { rows.push(userRow(u)); });
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
    if (f.type === 'textarea') {
      var area = '<textarea class="form-control" id="' + id + '" name="' + esc(f.name) + '" rows="' +
        esc(f.rows || 3) + '"' + (f.readonly ? ' readonly' : '') + (f.placeholder ? ' placeholder="' + esc(f.placeholder) + '"' : '') + '>' + esc(f.value || '') + '</textarea>';
      if (f.reader) {
        // One UID per line: each read from the reader adds a line.
        return '<div class="mb-3">' + label + area +
          '<div class="d-flex gap-2 align-items-center mt-2"><button type="button" class="btn btn-outline-success btn-sm" data-action="read-uid" data-target="' + id + '"><i class="bi bi-wifi"></i> Read a tag and add it</button>' +
          '<span id="readerMsg" class="form-text mt-0"></span></div></div>';
      }
      return '<div class="mb-3">' + label + area + '</div>';
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
    if (f.type === 'password') {
      // Only lets the person see what they are typing. Saved passwords are one-way hashes and cannot be shown.
      input += '<div class="form-check mt-2"><input class="form-check-input js-show-password" type="checkbox" id="show_' + esc(f.name) +
        '" data-for="' + id + '"><label class="form-check-label fs-7" for="show_' + esc(f.name) + '">Show password</label></div>';
    }
    if (f.reader) {
      return '<div class="mb-3">' + label + '<div class="input-group">' + input +
        '<button type="button" class="btn btn-outline-success" data-action="read-uid" data-target="' + id + '"><i class="bi bi-wifi"></i> Read from reader</button></div>' +
        '<div id="readerMsg" class="form-text"></div></div>';
    }
    return '<div class="mb-3">' + label + input + (f.meter ? '<div id="meter_' + esc(f.name) + '" class="mt-2"></div>' : '') + '</div>';
  }

  function openForm(title, submitLabel, fields, onSubmit, note, onReady) {
    activeFields = fields;
    activeSubmit = onSubmit;
    $('formTitle').textContent = title;
    $('formSubmit').textContent = submitLabel;
    $('formBody').innerHTML = (note ? '<p class="text-muted fs-7">' + esc(note) + '</p>' : '') + fields.map(fieldHtml).join('');
    $('formErr').classList.add('d-none');
    fields.forEach(function (f) {
      if (f.meter && window.WeaverPassword) {
        WeaverPassword.attachMeter($('f_' + f.name), $('meter_' + f.name), {
          context: f.context,
          watch: (f.watch || []).map(function (n) { return $('f_' + n); })
        });
      }
    });
    Array.prototype.forEach.call($('formBody').querySelectorAll('.js-show-password'), function (box) {
      box.addEventListener('change', function () { $(box.getAttribute('data-for')).type = box.checked ? 'text' : 'password'; });
    });
    if (onReady) { onReady(); }
    modal = modal || new bootstrap.Modal($('formModal'));
    modal.show();
  }

  function collect() {
    var out = {};
    activeFields.forEach(function (f) {
      if (f.skip) { return; }          // helper field, not sent to the server
      var el = $('f_' + f.name);
      if (f.type === 'checkbox') { out[f.name] = el.checked; return; }
      var v = el.value.trim();
      out[f.name] = (f.int && v !== '') ? parseInt(v, 10) : v;
      if (f.uids) {
        out[f.name] = v.split(/[\r\n,;]+/).map(function (x) { return x.trim(); }).filter(function (x) { return x !== ''; });
      }
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
  function productAssociation(p) {
    return p.association ? p.association.name : (isAssoc && state.association ? state.association.name : '');
  }
  function photoNote(p) {
    var e = p.latest_photo_event;
    return e ? 'Photo ' + e.action + ' by ' + (e.user ? e.user.name : 'a removed user') + ' on ' + when(e.created_at) : 'Product photo';
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
        body: JSON.stringify({ uid: key.uid, password: key.password, pack: key.pack, code: key.code, timeout: 15 })
      }).then(function (res) {
        return res.json().then(function (d) { return { ok: res.ok, d: d }; });
      }).then(function (r) {
        if (!r.ok) { throw new Error(r.d.error || 'Reader error.'); }
        return Weaver.post('/api/nfc-tags/' + tag.id + '/secured', { counter: r.d.counter, code_written: r.d.code_written === true });
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
    'add-association': function () {
      openForm('Add association', 'Send for approval', [
        { name: 'association_name', label: 'Association name' },
        { name: 'municipality', label: 'Municipality', value: state.staffMunicipality, readonly: true, skip: true },
        { name: 'contact_name', label: 'Contact person' },
        { name: 'contact_phone', label: 'Mobile number', placeholder: 'e.g. 09171234567' },
        { name: 'email', label: 'Email of the association (used to sign in)', type: 'email' },
        { name: 'password', label: 'Temporary password (12+ characters)', type: 'password', meter: true, watch: ['contact_name', 'email'],
          context: function () { return { email: $('f_email').value, name: $('f_contact_name').value }; } },
        { name: 'registration_details', label: 'Registration details (optional)', placeholder: 'e.g. DTI / CDA / SEC number' },
        { name: 'privacy_consent', label: 'The association has read and agreed to the privacy notice', type: 'checkbox' }
      ], function (v) { return Weaver.post('/api/staff/associations', v); },
      'This sends an application to the administrator, the same as the public registration page. The association and its login exist only after the administrator approves. Give the temporary password to the association: it must change it at the first sign-in.');
    },
    'add-product': function () {
      var fields = [
        { name: 'title', label: 'Product name' },
        { name: 'category', label: 'Category', type: 'select', options: CATEGORIES.map(function (c) { return { value: c[0], label: c[0] + ' (' + c[1] + ')' }; }) }
      ];
      if (!isAssoc) {
        // Staff choose the association that owns the product.
        fields.unshift({ name: 'association_id', label: 'Association', type: 'select', options: assocOptions().filter(function (o) {
          var a = byId(data.associations, o.value); return !a || (a.status || 'active') === 'active';
        }), int: true });
      }
      openForm('Add product', 'Save', fields, function (v) { return Weaver.post(API.product, v); });
    },
    // Product photo: pick an image, upload it, refresh the list.
    'photo-product': function (id) {
      var input = document.createElement('input');
      input.type = 'file';
      input.accept = 'image/jpeg,image/png,image/webp';
      input.onchange = function () {
        var file = input.files && input.files[0];
        if (!file) { return; }
        toast('Preparing the photo...', 'info');
        shrinkPhoto(file).then(function (small) {
          if (small.size > 3 * 1024 * 1024) { toast('The photo is still larger than 3 MB. Choose a smaller one.', 'danger'); return; }
          var form = new FormData();
          form.append('photo', small, small.name || file.name);
          toast('Uploading the photo...', 'info');
          run(Weaver.upload(API.product + '/' + id + '/photo', form));
        });
      };
      input.click();
    },
    'remove-photo': function (id) {
      var p = byId(data.products, id);
      if (!p) { return; }
      confirmThen('Remove the photo of ' + p.title + '?', function () {
        run(Weaver.del(API.product + '/' + p.id + '/photo'));
      });
    },
    'toggle-product': function (id) {
      var p = byId(data.products, id);
      if (!p) { return; }
      var next = p.status === 'active' ? 'inactive' : 'active';
      confirmThen((next === 'inactive' ? 'Deactivate ' : 'Activate ') + p.title + '?', function () {
        var body = { title: p.title, category: p.category, status: next };
        if (!isAssoc) { body.association_id = p.association_id; }
        run(put(API.product + '/' + p.id, body));
      });
    },
    'issue-tag': function (id, el) {
      var waiting = data.tags.filter(function (t) { return t.status === 'sent'; }).length;
      openForm('Link a tag to a product', 'Link tag', [
        { name: 'uid', label: 'Tag UID', placeholder: 'e.g. 04E3CA79410289', reader: true, value: el && el.getAttribute('data-uid') || '' },
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
      (isAssoc
        ? 'Only tags DTI sent to your association can be linked (' + plural(waiting, 'tag') + ' ready). '
        : 'The tag must be in the inventory: from the stock, or sent to the product\'s association. ') +
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
    'read-uid': function (id, el) {
      var msg = $('readerMsg'), field = $((el && el.getAttribute('data-target')) || 'f_uid');
      var btn = el || document.querySelector('[data-action="read-uid"]');
      msg.className = 'form-text';
      msg.textContent = 'Place the tag on the reader (waiting up to 15 seconds)...';
      btn.disabled = true;
      fetch(READER_URL + '/uid?timeout=15').then(function (res) {
        return res.json().then(function (d) { return { ok: res.ok, d: d }; });
      }).then(function (r) {
        if (!r.ok) { throw new Error(r.d.error || 'Reader error.'); }
        msg.className = 'form-text text-success';
        if (field.tagName === 'TEXTAREA') {
          var lines = field.value.split(/\r?\n/).map(function (x) { return x.trim(); }).filter(Boolean);
          if (lines.indexOf(r.d.uid) !== -1) {
            msg.className = 'form-text text-warning';
            msg.textContent = r.d.uid + ' is already in the list.';
          } else {
            lines.push(r.d.uid);
            field.value = lines.join('\n');
            msg.textContent = 'Added ' + r.d.uid + ' (' + plural(lines.length, 'tag') + ' in the list). Place the next tag and read again.';
          }
          return;
        }
        field.value = r.d.uid;
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
      }, 'Use this to fix a mistake. The tag moves to the association of the new product. Every change is recorded in the audit trail.');
    },
    'assign-tags': function () {
      var munis = (data.municipalities || []).map(function (m) { return { value: m, label: m }; });
      openForm('Assign tags to a Negosyo Center', 'Assign', [
        { name: 'municipality', label: 'Negosyo Center (municipality)', type: 'select', options: munis },
        { name: 'delivery_ref', label: 'Delivery reference (optional)', placeholder: 'e.g. DL-2026-11-01' },
        { name: 'uids', label: 'Tags in this box, one UID per line', type: 'textarea', rows: 6, reader: true, uids: true }
      ], function (v) { return Weaver.post('/api/admin/tag-assignments', v); },
      'Read each tag as you pack the box. If another Negosyo Center tries to receive one of these tags, it is refused and you get an alert here.');
    },
    'move-alert': function (id) {
      var x = byId((data.assignments || {}).alerts || [], id);
      if (!x) { return; }
      confirmThen('Move ' + x.uid + ' to the ' + x.attempted_municipality + ' list? That center can then receive it.', function () {
        run(Weaver.post('/api/admin/tag-alerts/' + x.id + '/move', {}));
      });
    },
    'dismiss-alert': function (id) {
      var x = byId((data.assignments || {}).alerts || [], id);
      if (!x) { return; }
      confirmThen('Dismiss the alert for ' + x.uid + '? It stays on the ' + x.assigned_municipality + ' list.', function () {
        run(Weaver.post('/api/admin/tag-alerts/' + x.id + '/dismiss', {}));
      });
    },
    'receive-batch': function () {
      openForm('Receive tags into the stock', 'Receive', [
        { name: 'code', label: 'Batch code (from the box or delivery receipt)', placeholder: 'e.g. DTI-2026-11-A' },
        { name: 'received_on', label: 'Date received', type: 'date', value: new Date().toISOString().slice(0, 10) },
        { name: 'supplier', label: 'Supplier (optional)' },
        { name: 'notes', label: 'Notes (optional)', type: 'textarea', rows: 2 },
        { name: 'uids', label: 'Tag UIDs, one per line', type: 'textarea', rows: 6, reader: true, uids: true, placeholder: '04E3CA79410289' }
      ], function (v) {
        if (!v.supplier) { delete v.supplier; }
        if (!v.notes) { delete v.notes; }
        return Weaver.post('/api/inventory/batches', v);
      }, 'Read each tag on the reader (or paste the UIDs). Every tag is recorded as in stock, with you as the person who received it.');
    },
    'add-to-batch': function (id) {
      var b = data.inventory ? byId(data.inventory.batches, id) : null;
      if (!b) { return; }
      openForm('Add tags to batch ' + b.code, 'Add', [
        { name: 'uids', label: 'Tag UIDs, one per line', type: 'textarea', rows: 6, reader: true, uids: true }
      ], function (v) { return Weaver.post('/api/inventory/batches/' + b.id + '/tags', v); });
    },
    'send-tags': function () {
      var stock = (data.invTags && data.invTags.data ? data.invTags.data : []).filter(function (t) { return t.status === 'in_stock'; });
      openForm('Send tags to an association', 'Send', [
        { name: 'association_id', label: 'Association', type: 'select', options: assocOptions(), int: true },
        { name: 'pick', label: 'Fill the list with this many tags from the stock (optional)', type: 'number', skip: true, placeholder: 'e.g. 10' },
        { name: 'uids', label: 'Tags being sent, one UID per line', type: 'textarea', rows: 6, reader: true, uids: true }
      ], function (v) { return Weaver.post('/api/inventory/send', { association_id: v.association_id, uids: v.uids }); },
      'Read each tag as you pack it, so the record matches what leaves the office. Only tags in stock can be sent.', function () {
        $('f_pick').addEventListener('change', function () {
          var n = Math.max(0, parseInt(this.value, 10) || 0);
          $('f_uids').value = stock.slice(0, n).map(function (t) { return t.uid; }).join('\n');
          if (n > stock.length) { toast('Only ' + stock.length + ' tags in stock are listed on this page.', 'warning'); }
        });
      });
    },
    'return-tag': function (id) {
      var t = data.invTags ? byId(data.invTags.data, id) : null;
      if (!t) { return; }
      confirmThen('Take tag ' + t.uid + ' back from ' + (t.association ? t.association.name : 'the association') + ' into the stock?', function () {
        run(Weaver.post('/api/inventory/tags/' + t.id + '/return', {}));
      });
    },
    'damage-tag': function (id) {
      var t = data.invTags ? byId(data.invTags.data, id) : null;
      if (!t) { return; }
      openForm('Mark tag ' + t.uid + ' damaged or lost', 'Mark damaged or lost', [
        { name: 'note', label: 'What happened (optional)', type: 'textarea', rows: 2 }
      ], function (v) { return Weaver.post('/api/inventory/tags/' + t.id + '/damaged', v.note ? { note: v.note } : {}); },
      'The tag can never be linked after this. It stays in the records.');
    },
    'review-report': function (id) {
      var x = data.reports && data.reports.data ? byId(data.reports.data, id) : null;
      if (!x) { return; }
      var statusOptions = Object.keys(REPORT_STATUS).map(function (k) { return { value: k, label: REPORT_STATUS[k].label }; });
      openForm('Report ' + x.reference, 'Save review', [
        { name: 'r_tag', label: 'Tag UID and why it was flagged', value: x.uid + ' - ' + (REASONS[x.flag_reason] || x.flag_reason || '-'), readonly: true, skip: true },
        { name: 'r_where', label: 'Where it was found', value: x.location, readonly: true, skip: true },
        { name: 'r_seen', label: 'Date seen', value: x.seen_on || '-', readonly: true, skip: true },
        { name: 'r_note', label: 'What the person wrote', type: 'textarea', value: x.note || '-', readonly: true, skip: true },
        { name: 'r_contact', label: 'Contact (optional, only if they chose to give it)', value: x.contact || 'none (anonymous)', readonly: true, skip: true },
        { name: 'status', label: 'Status', type: 'select', value: x.status, options: statusOptions },
        { name: 'review_note', label: 'Review note (what you checked or decided)', type: 'textarea', value: x.review_note || '' }
      ], function (v) {
        return Weaver.patch('/api/reports/' + x.id, { status: v.status, review_note: v.review_note || null })
          .then(function () { return { message: 'Review saved.' }; });
      }, 'A report does not change the tag. Revoking a tag is a separate step in NFC Tags, done only on your decision. Referral to other offices is also your decision.');
    },
    'approve-application': function (id) {
      var x = data.applications && data.applications.data ? byId(data.applications.data, id) : null;
      if (!x) { return; }
      openForm('Approve ' + x.association_name, 'Approve', [
        { name: 'a_name', label: 'Association', value: x.association_name + ' (' + x.municipality + ')', readonly: true, skip: true },
        { name: 'a_contact', label: 'Contact person', value: x.contact_name + ', ' + x.contact_phone, readonly: true, skip: true },
        { name: 'a_email', label: 'Will sign in with', value: x.email + (x.submitted_by ? ' (filed by ' + x.submitted_by + ', temporary password)' : x.email_verified ? ' (email confirmed)' : ' (email NOT confirmed)'), readonly: true, skip: true },
        { name: 'a_reg', label: 'Registration details they gave', value: x.registration_details || 'not given', readonly: true, skip: true }
      ], function () {
        return Weaver.post('/api/applications/' + x.id + '/approve', {});
      }, 'Approving creates the association and the login right away, with the password the applicant chose. Check that this is a real association first. Weaver-ID does not email the result, so tell them yourself; they can also look up the result with their reference number.');
    },
    'reject-application': function (id) {
      var x = data.applications && data.applications.data ? byId(data.applications.data, id) : null;
      if (!x) { return; }
      openForm('Reject ' + x.association_name, 'Reject application', [
        { name: 'a_name', label: 'Association', value: x.association_name + ' (' + x.municipality + ')', readonly: true, skip: true },
        { name: 'note', label: 'Reason (the applicant will read this)', type: 'textarea', rows: 4 }
      ], function (v) {
        return Weaver.post('/api/applications/' + x.id + '/reject', { note: v.note });
      }, 'The applicant can look up this decision and your reason with their reference number, and may apply again. Their saved password is deleted.');
    },
    'add-user': function () {
      openForm('Add user', 'Create', [
        { name: 'name', label: 'Full name' },
        { name: 'email', label: 'Email', type: 'email' },
        { name: 'password', label: 'Starting password (12+ characters)', type: 'password', meter: true, watch: ['name', 'email'],
          context: function () { return { email: $('f_email').value, name: $('f_name').value }; } },
        { name: 'role', label: 'Role', type: 'select', options: [
          { value: 'staff', label: 'Staff' }, { value: 'association', label: 'Association' }, { value: 'admin', label: 'Admin' }] },
        data.municipalities.length
          ? { name: 'municipality', label: 'Municipality', type: 'select', options: data.municipalities.map(function (m) { return { value: m, label: m }; }) }
          : { name: 'municipality', label: 'Municipality' },
        { name: 'association_id', label: 'Association', type: 'select', options: assocOptions(), int: true }
      ], function (v) {
        if (v.role !== 'staff') { delete v.municipality; }
        if (v.role !== 'association') { delete v.association_id; }
        return Weaver.post('/api/admin/users', v);
      }, 'The person must choose their own password the first time they sign in.', function () {
        // Staff are tied to a municipality, association accounts to an association, admins to neither:
        // show only the field that belongs to the chosen role, and forget what was typed in the other.
        var role = $('f_role');
        function sync() {
          [['f_municipality', 'staff'], ['f_association_id', 'association']].forEach(function (pair) {
            var input = $(pair[0]);
            var show = role.value === pair[1];
            input.closest('.mb-3').classList.toggle('d-none', !show);
            if (!show) { input.value = ''; }
          });
        }
        role.addEventListener('change', sync);
        sync();
      });
    },
    'reset-user-password': function (id) {
      var u = byId(data.users, id);
      if (!u) { return; }
      openForm('Reset password: ' + u.name, 'Reset password', [
        { name: 'password', label: 'New password (12+ characters)', type: 'password', meter: true,
          context: function () { return { email: u.email, name: u.name }; } }
      ], function (v) {
        return put('/api/admin/users/' + u.id, {
          name: u.name, email: u.email, status: u.status,
          municipality: u.municipality || null, association_id: u.association_id || null,
          password: v.password
        });
      }, 'They will be signed out of every device and must choose their own password the next time they sign in.');
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
    if (!isStaff) {
      Array.prototype.forEach.call(document.querySelectorAll('.staff-only'), function (el) { el.remove(); });
    }
    if (!hasLogs) {
      Array.prototype.forEach.call(document.querySelectorAll('.logs-ui'), function (el) { el.remove(); });
    }
    if (!hasReports) {
      Array.prototype.forEach.call(document.querySelectorAll('.reports-ui'), function (el) { el.remove(); });
    }
    if (!hasApps) {
      Array.prototype.forEach.call(document.querySelectorAll('.apps-ui'), function (el) { el.remove(); });
    }
    // A sidebar heading whose links were all removed for this role goes too.
    Array.prototype.forEach.call(document.querySelectorAll('#sideNav .nav-section'), function (el) {
      var next = el.nextElementSibling;
      if (!next || next.classList.contains('nav-section')) { el.remove(); }
    });

    $('sideNav').addEventListener('click', function (e) {
      var a = e.target.closest('[data-tab]');
      if (a) { showTab(a.getAttribute('data-tab')); }
    });
    $('logoutBtn').addEventListener('click', function () { Weaver.logout(); });
    $('refreshBtn').addEventListener('click', function () { loadAll(); });
    $('formEl').addEventListener('submit', onFormSubmit);

    document.addEventListener('change', onListFilterChange);

    $('searchInput').addEventListener('input', function () {
      state.filter = this.value.trim().toLowerCase();
      renderAll();
    });

    document.addEventListener('click', function (e) {
      var go = e.target.closest('[data-goto]');
      if (go) { goTo(go.getAttribute('data-goto')); return; }
      var el = e.target.closest('[data-action]');
      if (!el) { return; }
      var fn = actions[el.getAttribute('data-action')];
      if (fn) { fn(el.hasAttribute('data-id') ? parseInt(el.getAttribute('data-id'), 10) : undefined, el); }
    });

    if (hasLogs) {
      $('logFilter').addEventListener('change', function () {
        state.logResult = this.value; state.logPage = 1;
        loadLogs().then(renderLogs);
      });
      $('logPrev').addEventListener('click', function () { state.logPage--; loadLogs().then(renderLogs); });
      $('logNext').addEventListener('click', function () { state.logPage++; loadLogs().then(renderLogs); });
    }

    if (hasInventory) {
      $('invStatus').addEventListener('change', function () {
        state.invStatus = this.value; state.invPage = 1;
        loadInventory().then(renderInventory);
      });
      $('invBatch').addEventListener('change', function () {
        state.invBatch = this.value; state.invPage = 1;
        loadInventory().then(renderInventory);
      });
      $('invPrev').addEventListener('click', function () { state.invPage--; loadInventory().then(renderInventory); });
      $('invNext').addEventListener('click', function () { state.invPage++; loadInventory().then(renderInventory); });
    }

    if (hasReports) {
      $('reportFilter').addEventListener('change', function () {
        state.reportStatus = this.value; state.reportPage = 1;
        loadReports().then(renderReports);
      });
      $('reportPrev').addEventListener('click', function () { state.reportPage--; loadReports().then(renderReports); });
      $('reportNext').addEventListener('click', function () { state.reportPage++; loadReports().then(renderReports); });
    }

    if (hasApps) {
      $('applicationFilter').addEventListener('change', function () {
        state.appStatus = this.value; state.appPage = 1;
        loadApplications().then(renderApplications);
      });
      $('applicationPrev').addEventListener('click', function () { state.appPage--; loadApplications().then(renderApplications); });
      $('applicationNext').addEventListener('click', function () { state.appPage++; loadApplications().then(renderApplications); });
    }

    showTab('dashboard');
    loadAll();

    setInterval(poll, POLL_MS);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });
  }

  init();
})();
