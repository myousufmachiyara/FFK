<?php

/**
 * Account codes used by the Purchase workflow — matched to your seeded
 * Chart of Accounts + the new accounts added by DatabaseSeeder.
 */

return [
    'inventory' => env('ACC_CODE_INVENTORY', '104001'), // Stock in Hand

    'inventory_in_transit'      => env('ACC_CODE_INVENTORY_IN_TRANSIT', '105001'),
    'purchase_expenses_payable' => env('ACC_CODE_PURCHASE_EXPENSES_PAYABLE', '203001'),
    'shortage_loss'             => env('ACC_CODE_SHORTAGE_LOSS', '506001'),
];