<?php

return [
    'allowed_ips' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('SCHOOL_ALLOWED_IPS', ''))),
        fn (string $ip) => $ip !== ''
    )),

    /*
    |--------------------------------------------------------------------------
    | Attendance slot windows (minutes from midnight, school local time)
    |--------------------------------------------------------------------------
    |
    | present_minutes: first N minutes after opens count as "present" (through
    | opens + N minutes − 1 second, e.g. 09:30:00–09:44:59 when N = 15).
    |
    */
    'present_minutes' => 15,

    'slots' => [
        'morning' => [
            'opens' => 9 * 60 + 30,   // 09:30
            'closes' => 11 * 60,      // 11:00
        ],
        'lunch' => [
            'opens' => 11 * 60 + 30,  // 11:30
            'closes' => 13 * 60,      // 13:00
        ],
        'evening' => [
            'opens' => 14 * 60,       // 14:00
            'closes' => 17 * 60,      // 17:00
        ],
    ],

    'slot_order' => ['morning', 'lunch', 'evening'],

    'day_opens' => 9 * 60 + 30,   // 09:30 — first slot opens
    'day_closes' => 18 * 60,      // 17:00 — last slot closes
];
