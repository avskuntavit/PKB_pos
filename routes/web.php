<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BackOffice;
use App\Http\Controllers\Pos;
use App\Http\Controllers\SelfOrder;
use App\Http\Controllers\Storefront;
use App\Http\Middleware\ResolveTableSession;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store']);
});

Route::post('logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
| หน้าแรก — ไม่บังคับล็อกอินโดยตั้งใจ
|
| คนที่พิมพ์ชื่อเว็บเปล่า ๆ ส่วนใหญ่คือลูกค้าที่จะสั่งอาหาร ไม่ใช่พนักงาน
| ถ้าเด้งเข้าหน้า login ก่อน ลูกค้าจะคิดว่าสั่งไม่ได้แล้วปิดหน้าไปเลย
| ส่วนพนักงานที่ล็อกอินค้างไว้ยังเข้าหน้างานของตัวเองทันทีเหมือนเดิม
*/
Route::get('/', function () {
    $user = auth()->user();

    if (! $user) {
        return redirect()->route('storefront.menu');
    }

    return redirect()->route($user->canAccessBackOffice() ? 'backoffice.dashboard' : 'pos.tables');
})->name('home');

/*
|--------------------------------------------------------------------------
| หลังบ้าน (Back Office) — เจ้าของ / ผู้จัดการ
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'permission:backoffice.access'])
    ->prefix('backoffice')
    ->name('backoffice.')
    ->group(function () {
        // หน้าแรกของผู้บริหาร — ยอดวันนี้เทียบเป้า และเดือนนี้ยังตามแผนไหม
        // สลับสถานีที่กำลังดู — ใช้ร่วมกันทุกหน้าหลังบ้านผ่านแถบบนหัว
        Route::post('station', [BackOffice\StationSwitchController::class, 'update'])->name('station.switch');

        Route::get('dashboard', BackOffice\DashboardController::class)->name('dashboard');

        Route::get('summary', BackOffice\SummaryController::class)->name('summary');

        Route::get('tax', [BackOffice\TaxController::class, 'index'])->name('tax.index');
        // ดาวน์โหลดรายงานภาษีขายรายใบกำกับ ให้ผู้ทำบัญชีเอาไปใช้ต่อ
        Route::get('tax/export', [BackOffice\TaxController::class, 'export'])->name('tax.export');

        Route::get('sales', [BackOffice\SaleController::class, 'index'])->name('sales.index');
        Route::get('sales/{order}', [BackOffice\SaleController::class, 'show'])->name('sales.show');

        Route::get('products', [BackOffice\ProductController::class, 'index'])->name('products.index');
        Route::post('products', [BackOffice\ProductController::class, 'store'])->name('products.store');
        Route::put('products/{product}', [BackOffice\ProductController::class, 'update'])->name('products.update');
        // ข้อมูลเมนูแบบที่หน้าลูกค้าเห็น — แผ่นตัวอย่างในหน้าต่างแก้ไขดึงไปใช้
        Route::get('products/{product}/preview', [BackOffice\ProductPreviewController::class, 'show'])->name('products.preview');
        // ค่าเฉพาะสาขาของเมนูกลาง — ราคา/เปิด-ปิด/ลำดับ/จุดพิมพ์ ผู้จัดการสาขาแก้ได้
        Route::put('products/{product}/branch', [BackOffice\ProductController::class, 'saveOverride'])->name('products.override');
        Route::delete('products/{product}', [BackOffice\ProductController::class, 'destroy'])->name('products.destroy');
        // ผูกเซ็ตตัวเลือกจากฝั่งเมนู (อีกทางทำจากหน้าเซ็ตตัวเลือก)
        Route::put('products/{product}/modifier-groups', [BackOffice\ProductController::class, 'syncModifierGroups'])->name('products.modifier-groups');
        // สินค้าเด่น + ตะแกรงโปรโมทบนหน้าสั่งอาหาร
        Route::put('promo', [BackOffice\PromoController::class, 'update'])->name('promo.update');

        // หมวดหมู่เมนู — จัดการจากหน้ารายละเอียดสินค้า
        Route::post('categories', [BackOffice\CategoryController::class, 'store'])->name('categories.store');
        Route::put('categories/{category}', [BackOffice\CategoryController::class, 'update'])->name('categories.update');
        Route::delete('categories/{category}', [BackOffice\CategoryController::class, 'destroy'])->name('categories.destroy');

        Route::get('inventory', [BackOffice\InventoryController::class, 'index'])->name('inventory.index');
        Route::post('inventory/move', [BackOffice\InventoryController::class, 'move'])->name('inventory.move');
        Route::post('inventory/items', [BackOffice\InventoryController::class, 'store'])->name('inventory.items.store');
        Route::put('inventory/items/{item}', [BackOffice\InventoryController::class, 'update'])->name('inventory.items.update');
        Route::delete('inventory/items/{item}', [BackOffice\InventoryController::class, 'destroy'])->name('inventory.items.destroy');

        /*
        | โอนของข้ามสถานี — ทำได้ตั้งแต่ของในคลังเป็นของกลาง (stock_items.branch_id = NULL)
        |
        | สองขั้น: ต้นทางกดส่ง (ตัดสต๊อกทันที) แล้วปลายทางกดรับ
        | สิทธิ์ stock.transfer เปิดหน้าได้ ส่วนจะส่ง/รับใบไหนได้ คุมด้วย accessibleBranchIds()
        | ใน controller อีกชั้น — ผู้จัดการจึงโอนออกได้เฉพาะสาขาตัวเอง
        */
        Route::middleware('permission:stock.transfer')->group(function () {
            Route::get('stock-transfers', [BackOffice\StockTransferController::class, 'index'])->name('stock-transfers.index');
            Route::post('stock-transfers', [BackOffice\StockTransferController::class, 'store'])->name('stock-transfers.store');
            Route::post('stock-transfers/{transfer}/receive', [BackOffice\StockTransferController::class, 'receive'])->name('stock-transfers.receive');
            Route::post('stock-transfers/{transfer}/cancel', [BackOffice\StockTransferController::class, 'cancel'])->name('stock-transfers.cancel');
        });

        // กลุ่มตัวเลือก — ปริมาณ / เพิ่มพิเศษ และวัตถุดิบที่แต่ละตัวเลือกใช้
        Route::get('modifiers', [BackOffice\ModifierController::class, 'index'])->name('modifiers.index');
        Route::post('modifiers/groups', [BackOffice\ModifierController::class, 'storeGroup'])->name('modifiers.groups.store');
        Route::put('modifiers/groups/{group}', [BackOffice\ModifierController::class, 'updateGroup'])->name('modifiers.groups.update');
        Route::delete('modifiers/groups/{group}', [BackOffice\ModifierController::class, 'destroyGroup'])->name('modifiers.groups.destroy');
        Route::put('modifiers/groups/{group}/products', [BackOffice\ModifierController::class, 'syncProducts'])->name('modifiers.groups.products');
        Route::post('modifiers/groups/{group}/items', [BackOffice\ModifierController::class, 'storeModifier'])->name('modifiers.items.store');
        Route::put('modifiers/items/{modifier}', [BackOffice\ModifierController::class, 'updateModifier'])->name('modifiers.items.update');
        Route::delete('modifiers/items/{modifier}', [BackOffice\ModifierController::class, 'destroyModifier'])->name('modifiers.items.destroy');
        Route::put('modifiers/items/{modifier}/recipe', [BackOffice\ModifierController::class, 'saveRecipe'])->name('modifiers.items.recipe');

        Route::get('customers', [BackOffice\CustomerController::class, 'index'])->name('customers.index');
        Route::post('customers', [BackOffice\CustomerController::class, 'store'])->name('customers.store');

        Route::get('shifts', [BackOffice\ShiftController::class, 'index'])->name('shifts.index');
        Route::get('shifts/{shift}', [BackOffice\ShiftController::class, 'show'])->name('shifts.show');

        Route::get('promotions', [BackOffice\PromotionController::class, 'index'])->name('promotions.index');
        Route::post('promotions', [BackOffice\PromotionController::class, 'store'])->name('promotions.store');
        Route::put('promotions/{promotion}', [BackOffice\PromotionController::class, 'update'])->name('promotions.update');
        Route::delete('promotions/{promotion}', [BackOffice\PromotionController::class, 'destroy'])->name('promotions.destroy');

        // เครื่องพิมพ์ + คิวงานพิมพ์ของสาขา
        Route::get('printers', [BackOffice\PrinterController::class, 'index'])->name('printers.index');
        Route::post('printers', [BackOffice\PrinterController::class, 'store'])->name('printers.store');
        Route::put('printers/{printer}', [BackOffice\PrinterController::class, 'update'])->name('printers.update');
        Route::delete('printers/{printer}', [BackOffice\PrinterController::class, 'destroy'])->name('printers.destroy');
        Route::post('printers/{printer}/test', [BackOffice\PrinterController::class, 'test'])->name('printers.test');
        Route::post('print-jobs/{job}/retry', [BackOffice\PrinterController::class, 'retry'])->name('print-jobs.retry');
        Route::post('print-jobs/retry-all', [BackOffice\PrinterController::class, 'retryAll'])->name('print-jobs.retry-all');

        Route::get('vouchers', [BackOffice\VoucherController::class, 'index'])->name('vouchers.index');
        Route::post('vouchers', [BackOffice\VoucherController::class, 'store'])->name('vouchers.store');

        Route::get('staff', [BackOffice\StaffController::class, 'index'])->name('staff.index');

        // สูตรและส่วนผสม
        Route::get('recipes', [BackOffice\RecipeController::class, 'index'])->name('recipes.index');
        Route::put('recipes/{product}', [BackOffice\RecipeController::class, 'update'])->name('recipes.update');
        Route::post('recipes/sync-costs', [BackOffice\RecipeController::class, 'syncCosts'])->name('recipes.sync');

        // สิทธิ์พนักงานองค์กร (HR อนุมัติ)
        Route::middleware('permission:customer.employee_verify')->group(function () {
            Route::get('employees', [BackOffice\EmployeeVerificationController::class, 'index'])->name('employees.index');
            Route::post('employees/{customer}/approve', [BackOffice\EmployeeVerificationController::class, 'approve'])->name('employees.approve');
            Route::post('employees/{customer}/reject', [BackOffice\EmployeeVerificationController::class, 'reject'])->name('employees.reject');
            Route::post('employees/{customer}/revoke', [BackOffice\EmployeeVerificationController::class, 'revoke'])->name('employees.revoke');
        });

        // รายงานสวัสดิการ (ให้บัญชีตั้งเบิก)
        Route::middleware('permission:report.staff_benefit')->group(function () {
            Route::get('staff-benefit', [BackOffice\StaffBenefitReportController::class, 'index'])->name('staff-benefit.index');
            Route::get('staff-benefit/export', [BackOffice\StaffBenefitReportController::class, 'export'])->name('staff-benefit.export');
        });

        // รายงานต้นทุน-กำไรรายเมนู
        Route::get('reports/profit', [BackOffice\ProfitReportController::class, 'index'])
            ->middleware('permission:report.profit')
            ->name('reports.profit');

        // ความพึงพอใจ
        Route::get('reviews', [BackOffice\ReviewController::class, 'index'])->name('reviews.index');
        Route::post('reviews/{review}/reply', [BackOffice\ReviewController::class, 'reply'])->name('reviews.reply');

        // ตั้งค่าสาขาและสิทธิ์
        Route::middleware('permission:cash.settle_verify')->group(function () {
            Route::get('cash-settlements', [BackOffice\CashSettlementController::class, 'index'])->name('cash-settlements');
            Route::post('cash-settlements/{settlement}/verify', [BackOffice\CashSettlementController::class, 'verify'])->name('cash-settlements.verify');
            Route::post('cash-settlements/{settlement}/dispute', [BackOffice\CashSettlementController::class, 'dispute'])->name('cash-settlements.dispute');
        });

        // กระทบยอดเงินเข้าบัญชีธนาคาร — แยกสิทธิ์จากการตรวจใบนำส่งเงินสด
        // เพราะหน้านี้เห็นเงินทุกช่องทาง ไม่ใช่แค่ก้อนเงินสด
        Route::middleware('permission:bank.reconcile')->group(function () {
            Route::get('bank-reconciliation', [BackOffice\BankReconciliationController::class, 'index'])->name('bank-reconciliation');
            Route::get('bank-reconciliation/export', [BackOffice\BankReconciliationController::class, 'export'])->name('bank-reconciliation.export');
            Route::post('bank-reconciliation', [BackOffice\BankReconciliationController::class, 'reconcile'])->name('bank-reconciliation.store');
            Route::post('bank-reconciliation/{reconciliation}/undo', [BackOffice\BankReconciliationController::class, 'unreconcile'])->name('bank-reconciliation.undo');
        });

        // ส่งข้อมูลการขายให้ระบบบัญชี — ปกติตัวตั้งเวลาเป็นคนเรียก artisan
        // หน้านี้มีไว้ตามวันที่พลาด และส่งใหม่เมื่อมีการแก้บิลย้อนหลัง
        Route::middleware('permission:sales.export')->group(function () {
            Route::get('sales-export', [BackOffice\SalesExportController::class, 'index'])->name('sales-export');
            Route::post('sales-export', [BackOffice\SalesExportController::class, 'store'])->name('sales-export.store');
            Route::get('sales-export/{export}/download', [BackOffice\SalesExportController::class, 'download'])->name('sales-export.download');
        });

        /*
        | เปิดสถานีใหม่ ปิดสถานีเก่า และข้อมูลแบรนด์ของ "ทุก" สถานี
        |
        | แยกสิทธิ์ออกจาก branch.settings เพราะคนละขอบเขต:
        | branch.settings = ตั้งค่าการขายของสถานีตัวเอง (ผู้จัดการสาขาทำได้)
        | station.manage  = โครงสร้างของกิจการ (ค่าตั้งต้นมีเฉพาะเจ้าของระบบ)
        */
        Route::middleware('permission:station.manage')->group(function () {
            Route::get('stations', [BackOffice\StationController::class, 'index'])->name('stations.index');
            Route::post('stations', [BackOffice\StationController::class, 'store'])->name('stations.store');
            Route::put('stations/{branch}', [BackOffice\StationController::class, 'update'])->name('stations.update');
            Route::post('stations/{branch}/active', [BackOffice\StationController::class, 'toggleActive'])->name('stations.active');

            // รูปบรรยากาศร้าน — order ต้องมาก่อน {image} ไม่งั้น "order" จะถูกอ่านเป็นเลขรูป
            Route::put('stations/{branch}/images/order', [BackOffice\StationController::class, 'reorderImages'])->name('stations.images.order');
            Route::post('stations/{branch}/images', [BackOffice\StationController::class, 'storeImage'])->name('stations.images.store');
            Route::delete('stations/{branch}/images/{image}', [BackOffice\StationController::class, 'destroyImage'])->name('stations.images.destroy');
        });

        /*
        | สุขภาพระบบ — สำรองข้อมูล ข้อผิดพลาด และค่าตั้งค่าที่อันตราย
        |
        | เป็นเรื่องของทั้งระบบ ไม่ใช่ของสาขาใดสาขาหนึ่ง จึงไม่มีตัวกรองสาขา
        | และให้เฉพาะเจ้าของระบบเข้า เพราะหน้านี้บอก path ของไฟล์สำรอง
        | กับ stack trace ซึ่งเป็นข้อมูลที่ช่วยคนที่อยากเจาะระบบได้มาก
        */
        /*
        | ปิดงวดบัญชี
        |
        | ผู้จัดการปิดงวดได้ (เขาเป็นคนกระทบยอดและส่งข้อมูลให้บัญชีอยู่แล้ว)
        | แต่ "เปิดงวดที่ปิดแล้วกลับมา" เป็นของเจ้าของระบบเท่านั้น
        | ด่านนั้นอยู่ใน PeriodLockService::reopen() ไม่ใช่ที่ middleware
        | เพราะถ้าอยู่ที่ route จะมีแค่หน้าเว็บที่กัน ส่วนโค้ดที่เรียกตรงยังทะลุได้
        */
        Route::middleware('permission:period.close')->group(function () {
            Route::get('periods', [BackOffice\PeriodController::class, 'index'])->name('periods.index');
            Route::post('periods/close', [BackOffice\PeriodController::class, 'close'])->name('periods.close');
            Route::post('periods/reopen', [BackOffice\PeriodController::class, 'reopen'])->name('periods.reopen');
        });

        Route::middleware('permission:system.health')->group(function () {
            Route::get('health', [BackOffice\HealthController::class, 'index'])->name('health');
            Route::post('health/backup', [BackOffice\HealthController::class, 'backup'])->name('health.backup');
            Route::post('health/errors/{event}/resolve', [BackOffice\HealthController::class, 'resolveError'])->name('health.errors.resolve');
            Route::post('health/errors/{event}/reopen', [BackOffice\HealthController::class, 'reopenError'])->name('health.errors.reopen');
        });

        Route::middleware('permission:branch.settings')->group(function () {
            Route::get('settings/branch', [BackOffice\BranchSettingController::class, 'edit'])->name('settings.branch');
            Route::put('settings/branch', [BackOffice\BranchSettingController::class, 'update'])->name('settings.branch.update');

            // เป้ายอดขายรายเดือน — ฐานของทุกตัวเลข "เทียบแผน" บนหน้า dashboard
            Route::get('settings/targets', [BackOffice\SalesTargetController::class, 'index'])->name('settings.targets');
            Route::put('settings/targets', [BackOffice\SalesTargetController::class, 'update'])->name('settings.targets.update');
        });

        Route::middleware('permission:staff.manage')->group(function () {
            Route::get('settings/permissions', [BackOffice\PermissionController::class, 'index'])->name('settings.permissions');
            Route::put('settings/permissions/{user}', [BackOffice\PermissionController::class, 'update'])->name('settings.permissions.update');
        });

        Route::get('tables', [BackOffice\TableController::class, 'index'])->name('tables.index');
        Route::get('tables/qr', [BackOffice\TableController::class, 'qrCodes'])->name('tables.qr');
        Route::put('tables/layout', [BackOffice\TableController::class, 'updateLayout'])->name('tables.layout');

        // อ็อบเจ็กต์ผังร้าน (เคาน์เตอร์, แคชเชียร์, บาร์, ทางเข้า ฯลฯ)
        Route::post('tables/objects', [BackOffice\TableController::class, 'storeObject'])->name('tables.objects.store');
        Route::put('tables/objects/{object}', [BackOffice\TableController::class, 'updateObject'])->name('tables.objects.update');
        Route::delete('tables/objects/{object}', [BackOffice\TableController::class, 'destroyObject'])->name('tables.objects.destroy');

        // โซน
        Route::post('tables/zones', [BackOffice\TableController::class, 'storeZone'])->name('tables.zones.store');

        // โต๊ะ
        Route::post('tables', [BackOffice\TableController::class, 'store'])->name('tables.store');
        Route::post('tables/{table}/qr', [BackOffice\TableController::class, 'regenerateQr'])->name('tables.qr.regenerate');
        Route::put('tables/{table}', [BackOffice\TableController::class, 'update'])->name('tables.update');
        Route::delete('tables/{table}', [BackOffice\TableController::class, 'destroy'])->name('tables.destroy');
    });

/*
|--------------------------------------------------------------------------
| หน้าร้าน (POS)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')
    ->prefix('pos')
    ->name('pos.')
    ->group(function () {
        // ชีพจร — หน้าจอ POS เรียกเช็คว่ายังคุยกับเซิร์ฟเวอร์ได้อยู่ไหม
        Route::get('health', Pos\HealthController::class)
            ->middleware('throttle:120,1')
            ->name('health');

        Route::get('/', [Pos\PosController::class, 'tables'])->name('tables');
        Route::get('terminal/{order?}', [Pos\PosController::class, 'terminal'])->name('terminal');

        Route::post('orders', [Pos\OrderController::class, 'store'])
            ->middleware('idempotent')
            ->name('orders.store');
        Route::post('orders/{order}/items', [Pos\OrderController::class, 'addItem'])->name('orders.items.store');
        Route::put('items/{item}', [Pos\OrderController::class, 'updateItem'])->name('orders.items.update');
        Route::delete('items/{item}', [Pos\OrderController::class, 'voidItem'])->name('orders.items.void');
        Route::put('items/{item}/course', [Pos\OrderController::class, 'setCourse'])->name('orders.items.course');
        Route::post('orders/{order}/send', [Pos\OrderController::class, 'send'])
            ->middleware('idempotent')
            ->name('orders.send');
        Route::post('orders/{order}/discount', [Pos\OrderController::class, 'discount'])->name('orders.discount');
        Route::post('orders/{order}/move-table', [Pos\OrderController::class, 'moveTable'])->name('orders.move-table');
        Route::delete('orders/{order}', [Pos\OrderController::class, 'void'])->name('orders.void');

        Route::post('orders/{order}/pay', [Pos\PaymentController::class, 'store'])
            ->middleware('idempotent')
            ->name('orders.pay');
        Route::get('orders/{order}/receipt', [Pos\PaymentController::class, 'receipt'])->name('receipt');

        // นำส่งเงินสดสิ้นวัน — เงินสดที่รับมา พนักงานโอนเข้าบัญชีบริษัทแทนการนำฝาก
        Route::middleware('permission:cash.settle')->group(function () {
            Route::get('cash-settlement', [Pos\CashSettlementController::class, 'show'])->name('cash-settlement');
            Route::post('cash-settlement', [Pos\CashSettlementController::class, 'store'])
                ->middleware('idempotent')
                ->name('cash-settlement.store');
        });

        Route::post('shifts', [Pos\ShiftController::class, 'open'])->name('shifts.open');
        Route::post('shifts/{shift}/close', [Pos\ShiftController::class, 'close'])->name('shifts.close');

        // ยืนยัน/ปฏิเสธรายการที่ลูกค้าสแกนสั่งเอง
        Route::post('orders/{order}/approve', [Pos\ApprovalController::class, 'approve'])->name('orders.approve');
        Route::post('orders/{order}/reject', [Pos\ApprovalController::class, 'reject'])->name('orders.reject');

        // คิวออเดอร์ล่วงหน้าจากหน้าร้านออนไลน์
        Route::get('online-orders', [Pos\OnlineOrderController::class, 'index'])->name('online.index');
        Route::get('online-orders/feed', [Pos\OnlineOrderController::class, 'feed'])->name('online.feed');
        Route::post('online-orders/{order}/accept', [Pos\OnlineOrderController::class, 'accept'])->name('online.accept');
        Route::post('online-orders/{order}/reject', [Pos\OnlineOrderController::class, 'reject'])->name('online.reject');
        Route::post('online-orders/{order}/status', [Pos\OnlineOrderController::class, 'moveTo'])->name('online.status');

        // จัดการคิวรับอาหาร — รวมทุกช่องทางไว้ที่เดียว
        Route::get('queue', [Pos\QueueController::class, 'index'])->name('queue.index');
        Route::get('queue/feed', [Pos\QueueController::class, 'feed'])->name('queue.feed');
        Route::post('queue/next', [Pos\QueueController::class, 'next'])->name('queue.next');
        Route::post('queue/{order}/call', [Pos\QueueController::class, 'call'])->name('queue.call');
        Route::post('queue/{order}/skip', [Pos\QueueController::class, 'skip'])->name('queue.skip');
        Route::post('queue/{order}/complete', [Pos\QueueController::class, 'complete'])->name('queue.complete');

        // ปิดขายเมนูชั่วคราว (ของหมดวันนี้)
        Route::get('availability', [Pos\ProductAvailabilityController::class, 'index'])->name('availability');
        Route::post('availability/{product}', [Pos\ProductAvailabilityController::class, 'toggle'])->name('availability.toggle');

        // แจ้งเตือนเรียกพนักงาน
        Route::get('alerts', [Pos\ServiceCallController::class, 'feed'])->name('alerts');
        Route::post('calls/{call}/ack', [Pos\ServiceCallController::class, 'acknowledge'])->name('calls.ack');
        Route::post('calls/{call}/done', [Pos\ServiceCallController::class, 'done'])->name('calls.done');
    });

/*
|--------------------------------------------------------------------------
| หน้าจอครัว (KDS) + ใบสั่งครัว
|--------------------------------------------------------------------------
*/
Route::middleware('auth')
    ->prefix('kitchen')
    ->name('kitchen.')
    ->group(function () {
        Route::get('/', [Pos\KitchenController::class, 'index'])->name('index');
        Route::get('feed', [Pos\KitchenController::class, 'feed'])->name('feed');
        Route::get('tickets/{ticket}/print', [Pos\KitchenController::class, 'print'])->name('tickets.print');
        Route::post('tickets/{ticket}/advance', [Pos\KitchenController::class, 'advance'])->name('tickets.advance');
        Route::post('tickets/{ticket}/status', [Pos\KitchenController::class, 'moveTo'])->name('tickets.status');
        Route::delete('tickets/{ticket}', [Pos\KitchenController::class, 'cancel'])->name('tickets.cancel');
    });

/*
|--------------------------------------------------------------------------
| จอแสดงคิวหน้าร้าน — เปิดค้างบนทีวี/แท็บเล็ต ไม่ต้องล็อกอิน
|--------------------------------------------------------------------------
*/
Route::get('queue/{branchCode}', [Pos\QueueBoardController::class, 'show'])->name('queue.board');
Route::get('queue/{branchCode}/feed', [Pos\QueueBoardController::class, 'feed'])
    ->middleware('throttle:120,1')
    ->name('queue.feed');

/*
|--------------------------------------------------------------------------
| หน้าร้านออนไลน์ — สั่งล่วงหน้า ไม่ต้องล็อกอิน
|--------------------------------------------------------------------------
| หน้าเดียวกันนี้พนักงานที่ล็อกอินอยู่ก็ใช้สั่งแทนลูกค้าได้
| ระบบดูจาก auth() เอง ไม่ต้องมี URL แยก
*/
Route::prefix('order')
    ->name('storefront.')
    ->group(function () {
        Route::get('/', [Storefront\MenuController::class, 'index'])->name('menu');

        // เลือกร้าน — ต้องประกาศก่อน {branchCode} ไม่งั้น "stations" จะถูกอ่านเป็นรหัสร้าน
        Route::get('stations', [Storefront\StationController::class, 'index'])->name('stations');
        Route::post('stations/{branchCode}', [Storefront\StationController::class, 'select'])->name('stations.select');

        Route::post('checkout', [Storefront\CheckoutController::class, 'store'])
            ->middleware(['throttle:20,1', 'idempotent'])
            ->name('checkout');

        // ชื่อเล่นผู้สั่ง — ต้องอยู่ก่อน {branchCode} เหมือนกัน
        Route::post('guest-name', [Storefront\TableAssistController::class, 'setGuestName'])
            ->middleware('throttle:20,1')
            ->name('guest-name');

        // ขอให้พนักงานมาเปิดโต๊ะ — ต้องอยู่ก่อน {branchCode} เหมือนกัน
        Route::post('table/open-request', [Storefront\TableAssistController::class, 'requestOpen'])
            ->middleware('throttle:10,1')
            ->name('table.open-request');

        // บิลของโต๊ะ — ต้องประกาศก่อน {branchCode} ไม่งั้น "bill" จะถูกอ่านเป็นรหัสร้าน
        Route::get('bill', [Storefront\TableBillController::class, 'feed'])
            ->middleware('throttle:60,1')
            ->name('bill');
        Route::post('bill/call', [Storefront\TableBillController::class, 'call'])
            ->middleware('throttle:10,1')
            ->name('bill.call');

        // สมาชิก — เบอร์ + OTP ไม่มีรหัสผ่าน
        Route::get('login', [Storefront\AuthController::class, 'show'])->name('login');
        Route::post('login/request', [Storefront\AuthController::class, 'requestCode'])
            ->middleware('throttle:10,1')
            ->name('login.request');
        Route::post('login/verify', [Storefront\AuthController::class, 'verify'])
            ->middleware('throttle:20,1')
            ->name('login.verify');
        Route::post('logout', [Storefront\AuthController::class, 'logout'])->name('logout');

        // บัญชีลูกค้า — ต้องล็อกอิน
        Route::middleware('auth:customer')->group(function () {
            Route::get('account', [Storefront\AccountController::class, 'show'])->name('account');
            Route::put('account', [Storefront\AccountController::class, 'updateProfile'])->name('account.update');
            Route::post('account/employee', [Storefront\AccountController::class, 'requestEmployeeBenefit'])
                ->middleware('throttle:5,10')
                ->name('account.employee');
        });

        Route::post('track/{token}/review', [Storefront\ReviewController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('track.review');

        Route::get('track/{token}', [Storefront\TrackController::class, 'show'])->name('track');
        Route::get('track/{token}/status', [Storefront\TrackController::class, 'feed'])
            ->middleware('throttle:60,1')
            ->name('track.status');
        Route::post('track/{token}/cancel', [Storefront\TrackController::class, 'cancel'])
            ->middleware('throttle:10,1')
            ->name('track.cancel');

        // ร้านหลายสาขา — เปิดเมนูเฉพาะสาขาได้ด้วยรหัสสาขา
        Route::get('{branchCode}', [Storefront\MenuController::class, 'index'])->name('menu.branch');
    });

/*
|--------------------------------------------------------------------------
| ลูกค้าสแกน QR ที่โต๊ะสั่งเอง — ไม่ต้องล็อกอิน
|--------------------------------------------------------------------------
| ไม่มี auth middleware โดยตั้งใจ ความปลอดภัยมาจาก
|   1. qr_token สุ่ม 40 ตัวอักษร เดาไม่ได้
|   2. รอบการนั่ง (table_sessions) ปิดอัตโนมัติเมื่อบิลถูกชำระ1
|   3. ทุกรายการที่ลูกค้าสั่งต้องให้พนักงานกดยืนยันก่อนเข้าครัว
|   4. throttle กันยิงรัว
*/
// QR ที่ติดโต๊ะ — ลิงก์นี้พิมพ์ติดโต๊ะไปแล้ว ห้ามเปลี่ยนรูปแบบ
// พาเข้าหน้าสั่งอาหารหน้าเดียวกับลูกค้าทั่วไป โดยผูกร้านและเลขโต๊ะให้อัตโนมัติ
Route::get('t/{qrToken}', Storefront\TableEntryController::class)->name('storefront.table');

Route::prefix('t/{qrToken}')
    ->middleware(ResolveTableSession::class)
    ->name('selforder.')
    ->group(function () {
        Route::get('status', [SelfOrder\StatusController::class, 'show'])
            ->middleware('throttle:60,1')
            ->name('status');

        Route::post('orders', [SelfOrder\OrderController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('orders.store');

        Route::post('call', [SelfOrder\ServiceCallController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('call');
    });
