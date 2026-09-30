
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Active Subscription Grace Period
    |--------------------------------------------------------------------------
    |
    | Number of days an active subscription remains valid after its
    | current billing period ends.
    |
    */

    'active_grace_period_days' => (int) env(
        'SUBSCRIPTION_GRACE_PERIOD_DAYS',
        7
    ),
];
