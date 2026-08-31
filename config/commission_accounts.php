<?php

/**
 * Account codes for the Commission workflow — matched to your seeded
 * Chart of Accounts + the new accounts added by DatabaseSeeder.
 */

return [
    'commission_goods_in_transit' => env('ACC_CODE_COMMISSION_IN_TRANSIT', '106001'),
    'commission_income'           => env('ACC_CODE_COMMISSION_INCOME', '403001'),
    'commission_clearing'         => env('ACC_CODE_COMMISSION_CLEARING', '107001'),
    'other_income'                => env('ACC_CODE_OTHER_INCOME', '402001'), // reuses existing seeded "Other Income"

    'vendor_account_type'   => 'vendor',
    'customer_account_type' => 'customer',
];