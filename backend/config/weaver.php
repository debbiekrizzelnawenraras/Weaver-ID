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

];
