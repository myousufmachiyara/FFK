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
    CommissionInvoiceController,
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
    SaleReturnController,
    // PermissionController, // DISABLED: class does not exist in app/Http/Controllers yet.
                              // Was referenced in the original routes file but never built.
                              // Re-enable once that controller is actually created.
    ProductSubcategoryController,
};

Auth::routes();

Route::middleware(['auth'])->group(function () {
    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::put('/users/{id}/change-password', [UserController::class, 'changePassword'])->name('users.changePassword');
    Route::put('/users/{id}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggleActive');
    Route::post('/change-my-password', [UserController::class, 'changeMyPassword'])->name('users.changeMyPassword');
    
    // Product Helpers
    Route::get('/products/details', [ProductController::class, 'details'])->name('products.receiving');
    Route::get('/product/{product}/variations', [ProductController::class, 'getVariations'])->name('product.variations');
    Route::get('/get-subcategories/{category_id}', [ProductCategoryController::class, 'getSubcategories'])->name('products.getSubcategories');

    //Purchase Helper
    Route::get('/product/{product}/invoices', [PurchaseInvoiceController::class, 'getProductInvoices']);

    // Common Modules
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
        'purchase_return' => ['controller' => PurchaseReturnController::class, 'permission' => 'purchase_return'],

        // Sales
        'sale_invoices' => ['controller' => SaleInvoiceController::class, 'permission' => 'sale_invoices'],
        'sale_return' => ['controller' => SaleReturnController::class, 'permission' => 'sale_return'],

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

    // ─────────────────────────────────────────────────────────────
    // Commission Invoice — status workflow (Pending -> In Transit -> Delivered)
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

    // Reports (readonly)
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('inventory', [InventoryReportController::class, 'inventoryReports'])->name('inventory');
        Route::get('purchase', [PurchaseReportController::class, 'purchaseReports'])->name('purchase');
        Route::get('sale', [SalesReportController::class, 'saleReports'])->name('sale');
        Route::get('accounts', [AccountsReportController::class, 'accounts'])->name('accounts');
    });

    Route::get('/get-location-stock', [ProductController::class, 'getLocationStock']);

    // DISABLED: StockTransferController is referenced here but was never
    // imported above (and its existence hasn't been confirmed) — same class
    // of bug as PermissionController. Re-enable once that controller exists
    // and add it to the use {...} import block above.
    // Route::get('/stock-lots/available', [StockTransferController::class, 'getAvailableLots'])->name('stock.lots.available');
});