<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\HeadOfAccounts;
use App\Models\SubHeadOfAccounts;
use App\Models\ChartOfAccounts;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\MeasurementUnit;
use App\Models\ProductCategory;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\ProductSubcategory;
use App\Models\Product;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $now = now();
        $userId = 1; // ID for created_by / updated_by

        // 🔑 Create Super Admin User
        $admin = User::firstOrCreate(
            ['username' => 'hamiz'],
            [
                'name' => 'Hamiz',
                'email' => null,
                'password' => Hash::make('12345678'),
            ]
        );

        $superAdmin = Role::firstOrCreate(['name' => 'superadmin']);
        $admin->assignRole($superAdmin);

        // 🔑 Create Admin User — Yousuf
        $yousuf = User::firstOrCreate(
            ['username' => 'yousuf'],
            [
                'name'     => 'Yousuf',
                'email'    => null,
                'password' => Hash::make('12345678'),
            ]
        );

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $yousuf->assignRole($adminRole);

        // 📌 Functional Modules (CRUD-style permissions)
        $modules = [
            // User Management
            'user_roles',
            'users',

            // Accounts
            'coa',
            'shoa',

            // Products
            'products',
            'product_categories',
            'product_subcategories',
            'attributes',

            // Purchases
            'purchase_invoices',
            'purchase_return',

            // Sales
            'sale_invoices',
            'sale_return',

            // Commission / Brokerage
            'commission_invoices',

            // Vouchers
            'vouchers',
        ];

        $actions = ['index', 'create', 'edit', 'delete', 'print'];

        foreach ($modules as $module) {
            foreach ($actions as $action) {
                Permission::firstOrCreate([
                    'name' => "$module.$action",
                ]);
            }
        }

        // 🔁 Custom, non-CRUD status-transition permissions
        // (Purchase: Pending -> In Transit -> Received.
        //  Commission: Pending -> In Transit -> Delivered.
        //  These don't fit the generic index/create/edit/delete/print set above.)
        $customPermissions = [
            'purchase_invoices.move_to_in_transit',
            'purchase_invoices.receive',
            'purchase_invoices.restore',

            'commission_invoices.move_to_in_transit',
            'commission_invoices.deliver',
            'commission_invoices.restore',
        ];

        foreach ($customPermissions as $permName) {
            Permission::firstOrCreate(['name' => $permName]);
        }

        // 📊 Report permissions
        $reports = ['inventory', 'purchase', 'sales', 'accounts', 'commission'];

        foreach ($reports as $report) {
            Permission::firstOrCreate([
                'name' => "reports.$report",
            ]);
        }

        // Assign ALL permissions to both Superadmin and Admin
        $superAdmin->syncPermissions(Permission::all());
        $adminRole->syncPermissions(Permission::all());

       // ─────────────────────────────────────────
        // HEADS OF ACCOUNTS  (5 standard heads)
        // ─────────────────────────────────────────
        HeadOfAccounts::insert([
            ['id' => 1, 'name' => 'Assets',      'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Liabilities', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => 'Equity',      'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'name' => 'Revenue',     'created_at' => $now, 'updated_at' => $now],
            ['id' => 5, 'name' => 'Expenses',    'created_at' => $now, 'updated_at' => $now],
        ]);

        // ─────────────────────────────────────────
        // SUB HEADS
        // Convention: hoa_id prefix tells which head
        // ─────────────────────────────────────────
        SubHeadOfAccounts::insert([
            // Assets (head 1)
            ['id' =>  1, 'hoa_id' => 1, 'name' => 'Cash',               'created_at' => $now, 'updated_at' => $now],
            ['id' =>  2, 'hoa_id' => 1, 'name' => 'Bank',               'created_at' => $now, 'updated_at' => $now],
            ['id' =>  3, 'hoa_id' => 1, 'name' => 'Accounts Receivable','created_at' => $now, 'updated_at' => $now],
            ['id' =>  4, 'hoa_id' => 1, 'name' => 'Inventory',          'created_at' => $now, 'updated_at' => $now],

            // Liabilities (head 2)
            ['id' =>  5, 'hoa_id' => 2, 'name' => 'Accounts Payable',   'created_at' => $now, 'updated_at' => $now],
            ['id' =>  6, 'hoa_id' => 2, 'name' => 'Loans Payable',      'created_at' => $now, 'updated_at' => $now],

            // Equity (head 3)
            ['id' =>  7, 'hoa_id' => 3, 'name' => 'Owner Capital',      'created_at' => $now, 'updated_at' => $now],

            // Revenue (head 4)
            ['id' =>  8, 'hoa_id' => 4, 'name' => 'Sales',              'created_at' => $now, 'updated_at' => $now],
            ['id' =>  9, 'hoa_id' => 4, 'name' => 'Other Income',       'created_at' => $now, 'updated_at' => $now],

            // Expenses (head 5)
            // FIX: COGS gets its OWN sub-head, not grouped under 'Purchases'
            ['id' => 10, 'hoa_id' => 5, 'name' => 'Cost of Goods Sold', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 11, 'hoa_id' => 5, 'name' => 'Salaries',          'created_at' => $now, 'updated_at' => $now],
            ['id' => 12, 'hoa_id' => 5, 'name' => 'Rent',              'created_at' => $now, 'updated_at' => $now],
            ['id' => 13, 'hoa_id' => 5, 'name' => 'Utilities',         'created_at' => $now, 'updated_at' => $now],
            ['id' => 14, 'hoa_id' => 5, 'name' => 'Other Expenses',    'created_at' => $now, 'updated_at' => $now],

            // ── NEW — Purchase / Commission workflow sub-heads ──────
            ['id' => 15, 'hoa_id' => 1, 'name' => 'Inventory In Transit',           'created_at' => $now, 'updated_at' => $now],
            ['id' => 16, 'hoa_id' => 1, 'name' => 'Commission Goods In Transit',     'created_at' => $now, 'updated_at' => $now],
            ['id' => 17, 'hoa_id' => 1, 'name' => 'Clearing / Suspense',            'created_at' => $now, 'updated_at' => $now],
            ['id' => 18, 'hoa_id' => 2, 'name' => 'Other Payables',                 'created_at' => $now, 'updated_at' => $now],
            ['id' => 19, 'hoa_id' => 4, 'name' => 'Commission Income',              'created_at' => $now, 'updated_at' => $now],
            ['id' => 20, 'hoa_id' => 5, 'name' => 'Inventory Shortage / Loss',      'created_at' => $now, 'updated_at' => $now],
        ]);

        // ─────────────────────────────────────────
        // CHART OF ACCOUNTS
        //
        // Code format: HHSsssNNN
        //   HH  = head number (1 digit or 2)
        //   Sss = sub-head sequential within that head
        //   NNN = account sequence within sub-head
        //
        // Simplified readable format used below: HSSNNN
        //   1 = Assets, 2 = Liabilities, 3 = Equity,
        //   4 = Revenue, 5 = Expenses
        // ─────────────────────────────────────────
        $coaData = [

            // ── ASSETS ──────────────────────────────────────────────
            // FIX: account_type='cash' (not 'asset') so cash book works
            ['id' =>  1, 'account_code' => '101001', 'shoa_id' =>  1, 'name' => 'Cash in Hand',         'account_type' => 'cash',      'receivables' => 0, 'payables' => 0],
            ['id' =>  2, 'account_code' => '102001', 'shoa_id' =>  2, 'name' => 'Main Bank Account',    'account_type' => 'bank',      'receivables' => 0, 'payables' => 0],
            // FIX: account_type='inventory' (was 'asset') so controllers can find it
            ['id' =>  3, 'account_code' => '104001', 'shoa_id' =>  4, 'name' => 'Stock in Hand',        'account_type' => 'inventory', 'receivables' => 0, 'payables' => 0],

            // ── LIABILITIES ─────────────────────────────────────────
            // (Vendors are created dynamically, but a control account is useful)
            ['id' =>  4, 'account_code' => '201001', 'shoa_id' =>  5, 'name' => 'Accounts Payable',     'account_type' => 'liability', 'receivables' => 0, 'payables' => 0],
            ['id' =>  5, 'account_code' => '202001', 'shoa_id' =>  6, 'name' => 'Loan Payable',         'account_type' => 'liability', 'receivables' => 0, 'payables' => 0],

            // ── EQUITY ──────────────────────────────────────────────
            ['id' =>  6, 'account_code' => '301001', 'shoa_id' =>  7, 'name' => 'Owner Capital',        'account_type' => 'equity',    'receivables' => 0, 'payables' => 0],
            ['id' =>  7, 'account_code' => '302001', 'shoa_id' =>  7, 'name' => 'Owner Drawings',       'account_type' => 'equity',    'receivables' => 0, 'payables' => 0],
            ['id' =>  8, 'account_code' => '303001', 'shoa_id' =>  7, 'name' => 'Retained Earnings',    'account_type' => 'equity',    'receivables' => 0, 'payables' => 0],

            // ── REVENUE ─────────────────────────────────────────────
            // FIX: code 401001, not 408001
            ['id' =>  9, 'account_code' => '401001', 'shoa_id' =>  8, 'name' => 'Sales Revenue',        'account_type' => 'revenue',   'receivables' => 0, 'payables' => 0],
            ['id' => 10, 'account_code' => '402001', 'shoa_id' =>  9, 'name' => 'Other Income',         'account_type' => 'revenue',   'receivables' => 0, 'payables' => 0],

            // ── EXPENSES ────────────────────────────────────────────
            // FIX: COGS under its own sub-head (shoa_id=10), code 501001
            ['id' => 11, 'account_code' => '501001', 'shoa_id' => 10, 'name' => 'Cost of Goods Sold',   'account_type' => 'cogs',      'receivables' => 0, 'payables' => 0],
            ['id' => 12, 'account_code' => '502001', 'shoa_id' => 11, 'name' => 'Salaries Expense',     'account_type' => 'expenses',  'receivables' => 0, 'payables' => 0],
            ['id' => 13, 'account_code' => '503001', 'shoa_id' => 12, 'name' => 'Rent Expense',         'account_type' => 'expenses',  'receivables' => 0, 'payables' => 0],
            ['id' => 14, 'account_code' => '504001', 'shoa_id' => 13, 'name' => 'Utilities Expense',    'account_type' => 'expenses',  'receivables' => 0, 'payables' => 0],
            ['id' => 15, 'account_code' => '505001', 'shoa_id' => 14, 'name' => 'Miscellaneous Expense','account_type' => 'expenses',  'receivables' => 0, 'payables' => 0],

            // ── NEW — Purchase workflow accounts ────────────────────
            ['id' => 16, 'account_code' => '105001', 'shoa_id' => 15, 'name' => 'Inventory In Transit',          'account_type' => 'inventory_in_transit', 'receivables' => 0, 'payables' => 0],
            ['id' => 19, 'account_code' => '203001', 'shoa_id' => 18, 'name' => 'Purchase Expenses Payable',     'account_type' => 'liability',            'receivables' => 0, 'payables' => 0],
            ['id' => 21, 'account_code' => '506001', 'shoa_id' => 20, 'name' => 'Inventory Shortage / Loss',     'account_type' => 'expenses',             'receivables' => 0, 'payables' => 0],

            // ── NEW — Commission / Brokerage workflow accounts ──────
            ['id' => 17, 'account_code' => '106001', 'shoa_id' => 16, 'name' => 'Commission Goods In Transit',   'account_type' => 'commission_in_transit', 'receivables' => 0, 'payables' => 0],
            ['id' => 18, 'account_code' => '107001', 'shoa_id' => 17, 'name' => 'Commission Clearing',           'account_type' => 'clearing',               'receivables' => 0, 'payables' => 0],
            ['id' => 20, 'account_code' => '403001', 'shoa_id' => 19, 'name' => 'Commission Income',             'account_type' => 'revenue',                'receivables' => 0, 'payables' => 0],
        ];

        foreach ($coaData as $data) {
            ChartOfAccounts::create(array_merge($data, [
                'credit_limit' => 0,
                'opening_date' => now(),
                'created_by'   => $userId,
                'updated_by'   => $userId,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]));
        }

        // Note: Commission's "Other Income" reimbursement leg reuses the
        // existing account above (id=10, code 402001 / Other Income) —
        // no separate new account needed for that one.

        // 📏 Measurement Units
        MeasurementUnit::insert([
            ['id' => 1, 'name' => 'Kilogram', 'shortcode' => 'kg'],
            ['id' => 2, 'name' => 'Meter',    'shortcode' => 'm'],
            ['id' => 3, 'name' => 'Pieces',   'shortcode' => 'pcs'],
            ['id' => 4, 'name' => 'Bag',      'shortcode' => 'bag'],
            ['id' => 5, 'name' => 'Bundle',   'shortcode' => 'bundle'],
        ]);
    }
}