<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Suspicious scan rule
    |--------------------------------------------------------------------------
    |
    | A tag is flagged "suspicious" when it is scanned by this many distinct
    | devices (device id, or IP address when none is sent) within the window.
    | This is a heuristic, not proof of cloning. Tune it for your demo and
    | explain it to the panel as such.
    |
    */

    'suspicious_distinct_scanners' => (int) env('WEAVER_SUSPICIOUS_SCANNERS', 5),

    'suspicious_window_minutes' => (int) env('WEAVER_SUSPICIOUS_WINDOW', 30),

    /*
    |--------------------------------------------------------------------------
    | Tag write-password key
    |--------------------------------------------------------------------------
    |
    | Secret used to derive each tag's write password from its UID. Keep it
    | private and back it up: without it, tags that were secured cannot be
    | rewritten. Generate one with:
    |     php -r "echo bin2hex(random_bytes(32));"
    |
    */

    'tag_key' => env('WEAVER_TAG_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Counter window
    |--------------------------------------------------------------------------
    |
    | How far a secured tag's read counter may jump above the last one the
    | server saw. Taps by phones that never reach the server also count, so
    | the window must not be too small. A bigger jump is flagged and is not
    | saved, so a forged number cannot lock a genuine tag out.
    |
    */

    'counter_window' => (int) env('WEAVER_COUNTER_WINDOW', 1000),

    /*
    |--------------------------------------------------------------------------
    | Suspicious tag reports
    |--------------------------------------------------------------------------
    |
    | A phone may report a tag only if the server flagged a scan of that tag
    | from the same phone within this many minutes. One phone can report the
    | same tag once per this many hours.
    |
    */

    'report_window_minutes' => (int) env('WEAVER_REPORT_WINDOW', 60),

    'report_duplicate_hours' => (int) env('WEAVER_REPORT_DUPLICATE_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Seeder password
    |--------------------------------------------------------------------------
    |
    | Password given to the demo accounts by `php artisan db:seed`. When unset,
    | a random one is generated and printed once. Never commit a real value.
    |
    */

    'seed_password' => env('SEED_PASSWORD'),

];
