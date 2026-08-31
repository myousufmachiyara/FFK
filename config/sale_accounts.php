<?php

/**
 * Account codes for the Sales workflow — matched to your seeded
 * Chart of Accounts (see DatabaseSeeder.php).
 */

return [
    'sales_revenue' => env('ACC_CODE_SALES_REVENUE', '401001'), // Sales Revenue
    'cogs'          => env('ACC_CODE_COGS', '501001'),          // Cost of Goods Sold

    'inventory' => env('ACC_CODE_INVENTORY', '104001'), // Stock in Hand

    // Cash in Hand (101001) = account_type 'cash', Main Bank Account (102001) = account_type 'bank'.
    'customer_account_type'  => 'customer',
    'payment_account_types'  => ['cash', 'bank'],
];