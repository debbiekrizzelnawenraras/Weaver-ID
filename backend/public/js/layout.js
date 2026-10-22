/*
 * Layout only: opens and closes the side menu.
 *
 * Wide screens: the menu stays open, and a button folds it to icons. The choice is kept in this
 * browser so the next visit looks the same.
 * Tablets and phones: the menu is hidden, a button slides it in over the page, and a tap outside
 * the menu, the Escape key or choosing a page closes it again.
 *
 * Nothing here reads or sends data, and nothing here touches the sign-in.
 */
(function () {
  'use strict';

  var KEY = 'weaver.menu';
  var root = document.documentElement;
  var wide = window.matchMedia ? window.matchMedia('(min-width: 992px)') : { matches: true };

  function remembered() {
    try { return window.localStorage.getItem(KEY); } catch (e) { return null; }
  }
  function remember(value) {
    try { window.localStorage.setItem(KEY, value); } catch (e) { /* private window: the choice is just not kept */ }
  }

  // Runs before the page paints, so a folded menu does not flash open first.
  if (remembered() === 'folded') { root.classList.add('menu-folded'); }

  function each(list, fn) { Array.prototype.forEach.call(list, fn); }

  // The words of a menu link, without the little count badge inside it.
  function ownText(el) {
    var text = '';
    for (var n = el.firstChild; n; n = n.nextSibling) {
      if (n.nodeType === 3) { text += n.nodeValue; }
    }
    return text.replace(/\s+/g, ' ').trim();
  }

  function start() {
    var toggle = document.getElementById('menuToggle');
    var backdrop = document.getElementById('menuBackdrop');
    var panel = document.getElementById('sidebar');
    var nav = document.getElementById('sideNav');
    if (!toggle || !panel || !nav) { return; }

    function folded() { return root.classList.contains('menu-folded'); }
    function opened() { return root.classList.contains('menu-open'); }

    function refresh() {
      var expanded, text;
      if (wide.matches) {
        expanded = !folded();
        text = expanded ? 'Fold the menu' : 'Unfold the menu';
      } else {
        expanded = opened();
        text = expanded ? 'Close the menu' : 'Open the menu';
      }
      toggle.setAttribute('aria-expanded', String(expanded));
      toggle.setAttribute('aria-label', text);
      toggle.title = text;

      // A folded menu shows icons only, so the words move into tooltips.
      each(nav.querySelectorAll('.nav-link'), function (a) {
        if (wide.matches && folded()) { a.title = ownText(a); } else { a.removeAttribute('title'); }
      });
    }

    function setOpen(on) {
      root.classList.toggle('menu-open', on);
      refresh();
      if (on) {
        var target = nav.querySelector('.nav-link.active') || nav.querySelector('.nav-link');
        if (target) { target.focus(); }
      }
    }

    function setFolded(on) {
      root.classList.toggle('menu-folded', on);
      remember(on ? 'folded' : 'open');
      refresh();
    }

    toggle.addEventListener('click', function () {
      if (wide.matches) { setFolded(!folded()); } else { setOpen(!opened()); }
    });

    if (backdrop) { backdrop.addEventListener('click', function () { setOpen(false); }); }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && opened()) { setOpen(false); toggle.focus(); }
    });

    // Choosing a page closes the slid-in menu. The dashboard script does the switching itself.
    nav.addEventListener('click', function (e) {
      if (!wide.matches && e.target.closest && e.target.closest('.nav-link')) { setOpen(false); }
    });

    // The menu links are not real links (no address), so give them the keyboard behaviour of buttons.
    each(nav.querySelectorAll('.nav-link'), function (a) {
      if (!a.hasAttribute('href')) {
        a.setAttribute('tabindex', '0');
        a.setAttribute('role', 'button');
      }
    });
    nav.addEventListener('keydown', function (e) {
      var a = e.target.closest ? e.target.closest('.nav-link') : null;
      if (a && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); a.click(); }
    });

    function onBreakpoint() { root.classList.remove('menu-open'); refresh(); }
    if (wide.addEventListener) { wide.addEventListener('change', onBreakpoint); }
    else if (wide.addListener) { wide.addListener(onBreakpoint); }

    refresh();
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
