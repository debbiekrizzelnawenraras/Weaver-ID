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
    | Seeder password
    |--------------------------------------------------------------------------
    |
    | Password given to the demo accounts by `php artisan db:seed`. When unset,
    | a random one is generated and printed once. Never commit a real value.
    |
    */

    'seed_password' => env('SEED_PASSWORD'),

];
