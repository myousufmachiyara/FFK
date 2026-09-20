<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

use App\Http\Controllers\{
    DashboardController,
    SubHeadOfAccController,
    COAController,
    SaleInvoiceController,
    PurchaseInvoiceController,
    PurchaseReturnController,
    SaleReturnController,
    CommissionInvoiceController,
    CommissionReturnController,
    ProductController,
    UserController,
    RoleController,
    AttributeController,
    ProductCategoryController,
    VoucherController,
    InventoryReportController,
    PurchaseReportController,
    SalesReportController,
    AccountsReportController,
    CommissionReportController,
    // PermissionController, // DISABLED: class does not exist in app/Http/Controllers yet.
                              // Was referenced in the original routes file but never built.
                              // Re-enable once that controller is actually created.
    ProductSubcategoryController,
};

Auth::routes();

Route::middleware(['auth'])->group(function () {

    // ─────────────────────────────────────────────────────────────
    // Dashboard
    // ─────────────────────────────────────────────────────────────
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // ─────────────────────────────────────────────────────────────
    // User account helpers
    // ─────────────────────────────────────────────────────────────
    Route::put('/users/{id}/change-password', [UserController::class, 'changePassword'])->name('users.changePassword');
    Route::put('/users/{id}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggleActive');
    Route::post('/change-my-password', [UserController::class, 'changeMyPassword'])->name('users.changeMyPassword');

    // ─────────────────────────────────────────────────────────────
    // Product helpers
    // ─────────────────────────────────────────────────────────────
    Route::get('/products/details', [ProductController::class, 'details'])->name('products.receiving');
    Route::get('/product/{product}/variations', [ProductController::class, 'getVariations'])->name('product.variations');
    Route::get('/get-subcategories/{category_id}', [ProductCategoryController::class, 'getSubcategories'])->name('products.getSubcategories');
    Route::get('/get-location-stock', [ProductController::class, 'getLocationStock'])->name('products.getLocationStock');
    Route::get('/products/variations/{variation}/barcode', [ProductController::class, 'variationBarcode'])->name('products.variation.barcode');

    // Purchase helper
    Route::get('/product/{product}/invoices', [PurchaseInvoiceController::class, 'getProductInvoices']);

    // ─────────────────────────────────────────────────────────────
    // Common CRUD modules — generic index/create/store/show/edit/update/destroy/print
    //
    // NOTE: 'purchase_return', 'sale_return', and Commission's return
    // module are intentionally NOT in this generic list. Their create()
    // methods require a specific invoice ID as a parameter (a return
    // always starts FROM an invoice, it's never a standalone blank
    // form) — the generic loop below would register
    // "GET purchase_return/create" with no parameter at all, which
    // would fatal-error the moment it's hit. Each Return module has its
    // own fully custom route block further down instead.
    // ─────────────────────────────────────────────────────────────
    $modules = [
        // User Management
        'roles' => ['controller' => RoleController::class, 'permission' => 'user_roles'],
        // 'permissions' => ['controller' => PermissionController::class, 'permission' => 'role_permissions'],
        // DISABLED — see note above. Uncomment once PermissionController exists.
        'users' => ['controller' => UserController::class, 'permission' => 'users'],

        // Accounts
        'coa' => ['controller' => COAController::class, 'permission' => 'coa'],
        'shoa' => ['controller' => SubHeadOfAccController::class, 'permission' => 'shoa'],

        // Products
        'products' => ['controller' => ProductController::class, 'permission' => 'products'],
        'product_categories' => ['controller' => ProductCategoryController::class, 'permission' => 'product_categories'],
        'product_subcategories' => ['controller' => ProductSubcategoryController::class, 'permission' => 'product_subcategories'],
        'attributes' => ['controller' => AttributeController::class, 'permission' => 'attributes'],

        // Purchases
        'purchase_invoices' => ['controller' => PurchaseInvoiceController::class, 'permission' => 'purchase_invoices'],

        // Sales
        'sale_invoices' => ['controller' => SaleInvoiceController::class, 'permission' => 'sale_invoices'],

        // Commission / Brokerage
        'commission_invoices' => ['controller' => CommissionInvoiceController::class, 'permission' => 'commission_invoices'],

        // Vouchers
        'vouchers' => ['controller' => VoucherController::class, 'permission' => 'vouchers'],
    ];

    foreach ($modules as $uri => $config) {
        $controller = $config['controller'];
        $permission = $config['permission'];

        // Determine route parameter
        $param = $uri === 'roles' ? '{role}' : '{id}';

        if ($uri === 'vouchers') {
            // Voucher routes with type in all relevant actions
            Route::prefix("$uri/{type}")->group(function () use ($controller, $permission) {
                Route::get('/', [$controller, 'index'])->middleware("check.permission:$permission.index")->name("vouchers.index");
                Route::get('/create', [$controller, 'create'])->middleware("check.permission:$permission.create")->name("vouchers.create");
                Route::post('/', [$controller, 'store'])->middleware("check.permission:$permission.create")->name("vouchers.store");

                Route::get('/{id}', [$controller, 'show'])->middleware("check.permission:$permission.index")->name("vouchers.show");
                Route::get('/{id}/edit', [$controller, 'edit'])->middleware("check.permission:$permission.edit")->name("vouchers.edit");
                Route::put('/{id}', [$controller, 'update'])->middleware("check.permission:$permission.edit")->name("vouchers.update");
                Route::delete('/{id}', [$controller, 'destroy'])->middleware("check.permission:$permission.delete")->name("vouchers.destroy");
                Route::get('/{id}/print', [$controller, 'print'])->middleware("check.permission:$permission.print")->name('vouchers.print');
            });

            continue;
        }

        // Index & Create
        Route::get("$uri", [$controller, 'index'])->middleware("check.permission:$permission.index")->name("$uri.index");
        Route::get("$uri/create", [$controller, 'create'])->middleware("check.permission:$permission.create")->name("$uri.create");
        Route::post("$uri", [$controller, 'store'])->middleware("check.permission:$permission.create")->name("$uri.store");

        // Show, Edit, Update, Delete, Print
        Route::get("$uri/$param", [$controller, 'show'])->middleware("check.permission:$permission.index")->name("$uri.show");
        Route::get("$uri/$param/edit", [$controller, 'edit'])->middleware("check.permission:$permission.edit")->name("$uri.edit");
        Route::put("$uri/$param", [$controller, 'update'])->middleware("check.permission:$permission.edit")->name("$uri.update");
        Route::delete("$uri/$param", [$controller, 'destroy'])->middleware("check.permission:$permission.delete")->name("$uri.destroy");
        Route::get("$uri/$param/print", [$controller, 'print'])->middleware("check.permission:$permission.print")->name("$uri.print");
    }

    // ─────────────────────────────────────────────────────────────
    // Purchase Invoice — status workflow (Pending -> In Transit -> Received)
    // plus payment tracking, all grouped together.
    // ─────────────────────────────────────────────────────────────
    Route::post('purchase_invoices/{id}/move-to-in-transit', [PurchaseInvoiceController::class, 'moveToInTransit'])
        ->middleware('check.permission:purchase_invoices.move_to_in_transit')
        ->name('purchase_invoices.moveToInTransit');

    Route::get('purchase_invoices/{id}/receive', [PurchaseInvoiceController::class, 'receiveForm'])
        ->middleware('check.permission:purchase_invoices.receive')
        ->name('purchase_invoices.receiveForm');

    Route::post('purchase_invoices/{id}/receive', [PurchaseInvoiceController::class, 'receive'])
        ->middleware('check.permission:purchase_invoices.receive')
        ->name('purchase_invoices.receive');

    Route::post('purchase_invoices/{id}/restore', [PurchaseInvoiceController::class, 'restore'])
        ->middleware('check.permission:purchase_invoices.restore')
        ->name('purchase_invoices.restore');

    Route::post('purchase_invoices/{id}/revert-to-pending', [PurchaseInvoiceController::class, 'revertToPending'])
        ->middleware('check.permission:purchase_invoices.revert_to_pending')
        ->name('purchase_invoices.revertToPending');

    Route::post('purchase_invoices/{id}/revert-to-in-transit', [PurchaseInvoiceController::class, 'revertToInTransit'])
        ->middleware('check.permission:purchase_invoices.revert_to_in_transit')
        ->name('purchase_invoices.revertToInTransit');

    Route::post('purchase_invoices/{id}/add-payment', [PurchaseInvoiceController::class, 'addPayment'])
        ->middleware('check.permission:purchase_invoices.add_payment')
        ->name('purchase_invoices.addPayment');

    // ─────────────────────────────────────────────────────────────
    // Purchase Return — always starts from a specific Received invoice.
    // ─────────────────────────────────────────────────────────────
    Route::get('purchase_returns', [PurchaseReturnController::class, 'index'])
        ->middleware('check.permission:purchase_return.index')
        ->name('purchase_returns.index');

    Route::get('purchase_invoices/{purchaseInvoice}/return', [PurchaseReturnController::class, 'create'])
        ->middleware('check.permission:purchase_return.create')
        ->name('purchase_returns.create');

    Route::post('purchase_returns', [PurchaseReturnController::class, 'store'])
        ->middleware('check.permission:purchase_return.create')
        ->name('purchase_returns.store');

    Route::get('purchase_returns/{id}', [PurchaseReturnController::class, 'show'])
        ->middleware('check.permission:purchase_return.index')
        ->name('purchase_returns.show');

    Route::get('purchase_returns/{id}/edit', [PurchaseReturnController::class, 'edit'])
        ->middleware('check.permission:purchase_return.edit')
        ->name('purchase_returns.edit');

    Route::put('purchase_returns/{id}', [PurchaseReturnController::class, 'update'])
        ->middleware('check.permission:purchase_return.edit')
        ->name('purchase_returns.update');

    Route::get('purchase_returns/{id}/print', [PurchaseReturnController::class, 'print'])
        ->middleware('check.permission:purchase_return.print')
        ->name('purchase_returns.print');

    Route::delete('purchase_returns/{id}', [PurchaseReturnController::class, 'destroy'])
        ->middleware('check.permission:purchase_return.delete')
        ->name('purchase_returns.destroy');

    // ─────────────────────────────────────────────────────────────
    // Commission Invoice — status workflow (Pending -> In Transit -> Delivered)
    // plus payment tracking, all grouped together.
    // ─────────────────────────────────────────────────────────────
    Route::post('commission_invoices/{id}/move-to-in-transit', [CommissionInvoiceController::class, 'moveToInTransit'])
        ->middleware('check.permission:commission_invoices.move_to_in_transit')
        ->name('commission_invoices.moveToInTransit');

    Route::post('commission_invoices/{id}/deliver', [CommissionInvoiceController::class, 'deliver'])
        ->middleware('check.permission:commission_invoices.deliver')
        ->name('commission_invoices.deliver');

    Route::post('commission_invoices/{id}/restore', [CommissionInvoiceController::class, 'restore'])
        ->middleware('check.permission:commission_invoices.restore')
        ->name('commission_invoices.restore');

    Route::post('commission_invoices/{id}/revert-to-pending', [CommissionInvoiceController::class, 'revertToPending'])
        ->middleware('check.permission:commission_invoices.revert_to_pending')
        ->name('commission_invoices.revertToPending');

    Route::post('commission_invoices/{id}/revert-to-in-transit', [CommissionInvoiceController::class, 'revertToInTransit'])
        ->middleware('check.permission:commission_invoices.revert_to_in_transit')
        ->name('commission_invoices.revertToInTransit');

    Route::post('commission_invoices/{id}/add-vendor-payment', [CommissionInvoiceController::class, 'addVendorPayment'])
        ->middleware('check.permission:commission_invoices.add_vendor_payment')
        ->name('commission_invoices.addVendorPayment');

    Route::post('commission_invoices/{id}/add-customer-receipt', [CommissionInvoiceController::class, 'addCustomerReceipt'])
        ->middleware('check.permission:commission_invoices.add_customer_receipt')
        ->name('commission_invoices.addCustomerReceipt');

    // ─────────────────────────────────────────────────────────────
    // Sale Return — always starts from a specific Sale invoice.
    // ─────────────────────────────────────────────────────────────
    Route::get('sale_returns', [SaleReturnController::class, 'index'])
        ->middleware('check.permission:sale_return.index')
        ->name('sale_returns.index');

    Route::get('sale_invoices/{saleInvoice}/return', [SaleReturnController::class, 'create'])
        ->middleware('check.permission:sale_return.create')
        ->name('sale_returns.create');

    Route::post('sale_returns', [SaleReturnController::class, 'store'])
        ->middleware('check.permission:sale_return.create')
        ->name('sale_returns.store');

    Route::get('sale_returns/{id}', [SaleReturnController::class, 'show'])
        ->middleware('check.permission:sale_return.index')
        ->name('sale_returns.show');

    Route::get('sale_returns/{id}/edit', [SaleReturnController::class, 'edit'])
        ->middleware('check.permission:sale_return.edit')
        ->name('sale_returns.edit');

    Route::put('sale_returns/{id}', [SaleReturnController::class, 'update'])
        ->middleware('check.permission:sale_return.edit')
        ->name('sale_returns.update');

    Route::get('sale_returns/{id}/print', [SaleReturnController::class, 'print'])
        ->middleware('check.permission:sale_return.print')
        ->name('sale_returns.print');

    Route::delete('sale_returns/{id}', [SaleReturnController::class, 'destroy'])
        ->middleware('check.permission:sale_return.delete')
        ->name('sale_returns.destroy');

    // ─────────────────────────────────────────────────────────────
    // Commission Return — always starts from a specific Delivered invoice.
    // ─────────────────────────────────────────────────────────────
    Route::get('commission_returns', [CommissionReturnController::class, 'index'])
        ->middleware('check.permission:commission_return.index')
        ->name('commission_returns.index');

    Route::get('commission_invoices/{commissionInvoice}/return', [CommissionReturnController::class, 'create'])
        ->middleware('check.permission:commission_return.create')
        ->name('commission_returns.create');

    Route::post('commission_returns', [CommissionReturnController::class, 'store'])
        ->middleware('check.permission:commission_return.create')
        ->name('commission_returns.store');

    Route::get('commission_returns/{id}', [CommissionReturnController::class, 'show'])
        ->middleware('check.permission:commission_return.index')
        ->name('commission_returns.show');

    Route::get('commission_returns/{id}/edit', [CommissionReturnController::class, 'edit'])
        ->middleware('check.permission:commission_return.edit')
        ->name('commission_returns.edit');

    Route::put('commission_returns/{id}', [CommissionReturnController::class, 'update'])
        ->middleware('check.permission:commission_return.edit')
        ->name('commission_returns.update');

    Route::get('commission_returns/{id}/print', [CommissionReturnController::class, 'print'])
        ->middleware('check.permission:commission_return.print')
        ->name('commission_returns.print');

    Route::delete('commission_returns/{id}', [CommissionReturnController::class, 'destroy'])
        ->middleware('check.permission:commission_return.delete')
        ->name('commission_returns.destroy');

    // ─────────────────────────────────────────────────────────────
    // Reports (readonly)
    // ─────────────────────────────────────────────────────────────
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('inventory', [InventoryReportController::class, 'inventoryReports'])->name('inventory');
        Route::get('purchase', [PurchaseReportController::class, 'purchaseReports'])->name('purchase');
        Route::get('sale', [SalesReportController::class, 'saleReports'])->name('sale');
        Route::get('accounts', [AccountsReportController::class, 'accounts'])->name('accounts');
        Route::get('commission', [CommissionReportController::class, 'commissionReports'])
            ->middleware('check.permission:reports.commission')
            ->name('commission');
    });

    // DISABLED: StockTransferController is referenced here but was never
    // imported above (and its existence hasn't been confirmed) — same class
    // of bug as PermissionController. Re-enable once that controller exists
    // and add it to the use {...} import block above.
    // Route::get('/stock-lots/available', [StockTransferController::class, 'getAvailableLots'])->name('stock.lots.available');
});