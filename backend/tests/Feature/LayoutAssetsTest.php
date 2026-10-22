<?php

// The sign-in page, the application page and the dashboards share one stylesheet, and the two
// dashboards share one menu script. These checks catch a missing file (a folder forgotten when a
// patch is unpacked) before it turns every page into unstyled text. They do not judge the look:
// that is checked in a browser.

/** The text of a file in public/. */
function layoutFile(string $path): string
{
    $full = public_path($path);
    expect(is_file($full))->toBeTrue("public/{$path} is missing");

    return (string) file_get_contents($full);
}

it('ships the shared stylesheet and the menu script', function () {
    expect(strlen(layoutFile('css/weaver.css')))->toBeGreaterThan(1000);
    expect(strlen(layoutFile('js/layout.js')))->toBeGreaterThan(500);
});

it('links the shared stylesheet from every page', function (string $page) {
    expect(layoutFile($page))->toContain('css/weaver.css');
})->with(['login.html', 'register.html', 'admin.html', 'association_dashboard.html']);

it('keeps no private copy of the look inside a page', function (string $page) {
    $html = layoutFile($page);

    // The old pages each carried the same block of styles and capped the content at 1200 px.
    expect($html)->not->toContain('max-width: 1200px')
        ->and($html)->not->toContain('--sidebar-width')
        ->and($html)->not->toContain('.dashboard-card {');
})->with(['login.html', 'register.html', 'admin.html', 'association_dashboard.html']);

it('lets the dashboards fill the screen instead of stopping at 1200 px', function () {
    $css = layoutFile('css/weaver.css');

    expect($css)->not->toContain('max-width: 1200px')
        ->and($css)->toContain('--content-max')
        ->and($css)->toContain('@media (min-width: 1700px)');
});

it('gives both dashboards the menu button, backdrop and ids the menu script needs', function (string $page) {
    $html = layoutFile($page);

    expect($html)->toContain('js/layout.js');
    foreach (['menuToggle', 'menuBackdrop', 'sidebar', 'sideNav', 'sectionTitle', 'refreshBtn'] as $id) {
        expect($html)->toContain('id="'.$id.'"');
    }
})->with(['admin.html', 'association_dashboard.html']);

it('has the menu script look for exactly the ids the dashboards provide', function () {
    $script = layoutFile('js/layout.js');

    foreach (['menuToggle', 'menuBackdrop', 'sidebar', 'sideNav'] as $id) {
        expect($script)->toContain("getElementById('{$id}')");
    }
});

it('keeps the hidden bot field on the application page hidden even if the stylesheet fails', function () {
    $html = layoutFile('register.html');

    expect($html)->toContain('.hp-field')
        ->and($html)->toContain('left: -9999px');
});

it('styles every class the dashboards and scripts rely on', function (string $selector) {
    expect(layoutFile('css/weaver.css'))->toContain($selector);
})->with([
    '.sidebar', '.sidebar-nav .nav-link', '.sidebar-backdrop', '.main-content', '.page-head',
    '.metric-card', '.dashboard-card', '.association-box', '.attention-card', '.btn-dti',
    '#toastHost', '.login-card', '.apply-card', '.switcher', '.reference-box',
    // the three rules that make the menu fold and slide
    'html.menu-folded {', 'html.menu-open .sidebar {', 'html.menu-open .sidebar-backdrop {',
]);
