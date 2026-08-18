<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Peak Windows (docs/02 FR-008 -> TOU)
    |--------------------------------------------------------------------------
    |
    | Which hours count as peak, for time-of-use tariffs. Configuration rather
    | than constants in code (docs/10 rule 9): utilities revise these, and a
    | change must not require a deployment.
    |
    | `days` uses ISO-8601 weekday numbers (1 = Monday). Times are in the
    | display timezone, because a TOU window is defined in local time -- a
    | Bangkok peak window expressed in UTC would shift by seven hours.
    |
    | The defaults mirror the common Thai TOU shape (weekday daytime peak) but
    | are only a starting point: the authoritative rates always come from
    | tariff_versions, and a deployment should set these to match the tariff
    | actually signed with the utility.
    |
    */

    'peak_windows' => [
        'weekday' => [
            'days' => [1, 2, 3, 4, 5],
            'start' => '09:00',
            'end' => '22:00',
        ],
    ],

];
