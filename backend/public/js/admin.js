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
  var hasReports = !isAssoc;
  var hasApps = !isAssoc;          // association applications: staff and admin decide them
  var API = {
    weavers: isAssoc ? '/api/association/weavers?status=all' : '/api/staff/weavers',
    weaver: isAssoc ? '/api/association/weavers' : '/api/staff/weavers',
    products: isAssoc ? '/api/association/products?status=all' : '/api/staff/products',
    product: isAssoc ? '/api/association/products' : '/api/staff/products',
    logs: isAssoc ? '/api/association/scan-logs' : '/api/admin/scan-logs',
    attention: '/api/attention'
  };
  var esc = Weaver.esc;
  var READER_URL = window.WEAVER_READER_URL || 'http://127.0.0.1:8777';

  var data = { associations: [], weavers: [], products: [], tags: [], users: [], summary: null, logs: null, reports: null, applications: null, municipalities: [], attention: null, attentionAt: null };
  var state = { tab: 'dashboard', filter: '', logPage: 1, logResult: '', reportPage: 1, reportStatus: '', appPage: 1, appStatus: 'pending', staffMunicipality: '',
    seen: { report: 0, scan: 0, application: 0 } };

  // How often the page asks what needs attention. window.WEAVER_POLL_MS exists for testing only.
  var POLL_MS = window.WEAVER_POLL_MS || 60000;

  var TITLES = {
    dashboard: 'Dashboard', weavers: 'Members', associations: 'Associations', products: 'Products',
    nfc: 'NFC Tags', logs: 'Scan Logs', reports: 'Reports', applications: 'Applications', users: 'Users'
  };

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

  function badge(status) {
    var map = { active: 'success', inactive: 'secondary', revoked: 'danger',
      authenticated: 'success', unregistered: 'secondary', suspicious: 'danger' };
    return '<span class="badge bg-' + (map[status] || 'secondary') + '">' + esc(status) + '</span>';
  }

  function securedBadge(t) {
    if (t.status !== 'active') { return ''; }
    return t.counter_enabled
      ? ' <span class="badge bg-info text-dark" title="Write password set and read counter on' + (t.code_enabled ? ', tag code written' : '; no tag code yet (Re-secure to add it)') + '">secured</span>'
      : ' <span class="badge bg-warning text-dark" title="This tag has no write password or counter yet">not secured</span>';
  }

  var REASONS = {
    tag_revoked: 'tag revoked', product_inactive: 'product inactive', weaver_inactive: 'member inactive',
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
    renderWeavers();
    if (!isAssoc) { renderAssociations(); }
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
      return matches([p.title, p.category, p.weaver ? p.weaver.name : '', productAssociation(p)]);
    }).map(function (p) {
      var tag = activeTagFor(p.id);
      var thumb = p.photo_url
        ? '<img src="' + esc(p.photo_url) + '" alt="" title="' + esc(photoNote(p)) + '" width="36" height="36" class="rounded me-2 border" style="object-fit:cover">'
        : '<span class="d-inline-flex align-items-center justify-content-center rounded me-2 border text-muted" style="width:36px;height:36px;vertical-align:middle"><i class="bi bi-image"></i></span>';
      return '<tr><td class="fw-semibold">' + thumb + esc(p.title) + '</td><td>' + esc(p.category) + '</td><td>' +
        esc(p.weaver ? p.weaver.name : '-') + '</td>' +
        (isAssoc ? '' : '<td>' + esc(productAssociation(p) || '-') + '</td>') + '<td>' +
        (tag ? '<code>' + esc(tag.uid) + '</code>' : '<span class="text-muted">none</span>') + '</td><td>' + badge(p.status) +
        '</td><td class="text-end pe-3" style="white-space:normal;min-width:200px">' +
        // Administrators may remove a photo but not add one: the association or its staff photographs the product.
        (isAdmin ? '' : '<button class="btn btn-sm btn-outline-primary py-0 me-1" data-action="photo-product" data-id="' +
        esc(p.id) + '">' + (p.photo_url ? 'Change photo' : 'Add photo') + '</button>') +
        (p.photo_url ? '<button class="btn btn-sm btn-outline-danger py-0 me-1" data-action="remove-photo" data-id="' + esc(p.id) + '">Remove photo</button>' : '') +
        '<button class="btn btn-sm btn-outline-secondary py-0" data-action="toggle-product" data-id="' +
        esc(p.id) + '">' + (p.status === 'active' ? 'Deactivate' : 'Activate') + '</button></td></tr>';
    });
    $('productsBody').innerHTML = rows.length ? rows.join('') : empty(isAssoc ? 6 : 7, 'No products to show.');
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
        '<td class="fw-semibold">' + esc(x.association_name) + '<div class="text-muted fs-8 fw-normal">' + esc(x.municipality) + '</div></td>' +
        '<td style="min-width:13rem">' + esc(x.contact_name) + '<div class="text-muted fs-8">' + esc(x.contact_phone) + '</div><div class="text-muted fs-8 text-break">' + esc(x.email) + '</div></td>' +
        '<td class="text-wrap" style="min-width:9rem">' + (x.registration_details ? esc(x.registration_details) : '<span class="text-muted">not given</span>') + '</td>' +
        '<td><span class="badge bg-' + st.cls + '">' + esc(st.label) + '</span>' + decided + conflicts + '</td>' +
        '<td class="text-end pe-3 text-nowrap">' + actions + '</td></tr>';
    });
    $('applicationsBody').innerHTML = rows.length ? rows.join('') : empty(6, 'No applications to show.');
    $('applicationPageInfo').textContent = r ? 'Page ' + r.current_page + ' of ' + r.last_page + ' (' + r.total + ' applications)' : '';
    $('applicationPrev').disabled = !r || r.current_page <= 1;
    $('applicationNext').disabled = !r || r.current_page >= r.last_page;
  }

  function renderUsers() {
    var rows = data.users.filter(function (u) {
      return matches([u.name, u.email, u.role, u.municipality || '', u.association ? u.association.name : '']);
    }).map(function (u) {
      var scope = u.role === 'staff' ? (u.municipality || '-') : (u.role === 'association' ? (u.association ? u.association.name : '-') : 'All');
      var self = u.id === me.id;
      return '<tr><td class="fw-semibold">' + esc(u.name) + '</td><td>' + esc(u.email) + '</td><td>' + esc(u.role) +
        '</td><td>' + esc(scope) + '</td><td>' + badge(u.status) +
        (u.must_change_password ? ' <span class="badge bg-warning text-dark" title="This account still has the password an administrator chose.">password not changed yet</span>' : '') +
        '</td><td class="text-end pe-3">' +
        (self ? '<span class="text-muted fs-8">you (use the key button)</span>' :
          '<button class="btn btn-sm btn-outline-secondary py-0 me-1" data-action="reset-user-password" data-id="' + esc(u.id) + '">Reset password</button>' +
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
    if (f.type === 'textarea') {
      return '<div class="mb-3">' + label + '<textarea class="form-control" id="' + id + '" name="' + esc(f.name) + '" rows="' +
        esc(f.rows || 3) + '"' + (f.readonly ? ' readonly' : '') + '>' + esc(f.value || '') + '</textarea></div>';
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
        '<button type="button" class="btn btn-outline-success" data-action="read-uid"><i class="bi bi-wifi"></i> Read from reader</button></div>' +
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
  // Active members of one association only (staff and admin pick the association first).
  function memberOptionsFor(associationId) {
    return data.weavers.filter(function (w) { return w.status === 'active' && String(w.association_id) === String(associationId); })
      .map(function (w) { return { value: w.id, label: w.name }; });
  }
  function fillMemberSelect(associationId) {
    var sel = $('f_weaver_id');
    var opts = associationId ? memberOptionsFor(associationId) : [];
    sel.innerHTML = '<option value="">' + (!associationId ? 'Select an association first' : (opts.length ? 'Select...' : 'This association has no active members')) + '</option>' +
      opts.map(function (o) { return '<option value="' + esc(o.value) + '">' + esc(o.label) + '</option>'; }).join('');
    sel.disabled = !opts.length;
  }
  function productAssociation(p) {
    return p.weaver && p.weaver.association ? p.weaver.association.name : '';
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
      var fields = [
        { name: 'weaver_id', label: 'Member', type: 'select', options: activeWeaverOptions(), int: true },
        { name: 'title', label: 'Product name' },
        { name: 'category', label: 'Category', placeholder: 'e.g. Blanket, Table runner' }
      ];
      if (!isAssoc) {
        // Staff and admin choose the association first, then a member of that association.
        fields.unshift({ name: 'association_id', label: 'Association', type: 'select', options: assocOptions(), skip: true });
        fields[1].options = [];
      }
      openForm('Add product', 'Save', fields, function (v) { return Weaver.post(API.product, v); });
      if (!isAssoc) {
        fillMemberSelect('');
        $('f_association_id').addEventListener('change', function () { fillMemberSelect(this.value); });
      }
    },
    // Product photo: pick an image, upload it, refresh the list.
    'photo-product': function (id) {
      if (isAdmin) { toast('Administrators can remove a photo but not add one.', 'danger'); return; }
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
        { name: 'a_email', label: 'Will sign in with', value: x.email, readonly: true, skip: true },
        { name: 'a_reg', label: 'Registration details they gave', value: x.registration_details || 'not given', readonly: true, skip: true }
      ], function () {
        return Weaver.post('/api/applications/' + x.id + '/approve', {});
      }, 'Approving creates the association and the login right away, with the password the applicant chose. Check that this is a real association first. Weaver-ID sends no email, so tell them yourself; they can also look up the result with their reference number.');
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
    if (!hasReports) {
      Array.prototype.forEach.call(document.querySelectorAll('.reports-ui'), function (el) { el.remove(); });
    }
    if (!hasApps) {
      Array.prototype.forEach.call(document.querySelectorAll('.apps-ui'), function (el) { el.remove(); });
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
      var go = e.target.closest('[data-goto]');
      if (go) { goTo(go.getAttribute('data-goto')); return; }
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
