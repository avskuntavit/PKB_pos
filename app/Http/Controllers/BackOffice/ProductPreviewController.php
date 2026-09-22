<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\CurrentBranch;
use App\Support\StorefrontProduct;
use Illuminate\Http\JsonResponse;

/**
 * ข้อมูลเมนูสำหรับแผ่นตัวอย่างในหน้าจัดการสินค้า
 *
 * ไม่ยัดตัวเลือกทั้งหมดไปกับรายการสินค้าหน้าแรก เพราะหน้านั้นโหลด 30 เมนูต่อหน้า
 * ถ้าลากตัวเลือกของทุกเมนูไปด้วยจะกลายเป็นก้อนใหญ่ที่แทบไม่มีใครได้ใช้
 * ดึงตอนเปิดหน้าต่างแก้ไขทีละเมนูแทน
 *
 * ใช้ StorefrontProduct ตัวเดียวกับหน้าลูกค้า ตัวอย่างจึงไม่มีทางเพี้ยนจากของจริง
 */
class ProductPreviewController extends Controller
{
    public function show(Product $product): JsonResponse
    {
        // เมนูกลางดูตัวอย่างได้จากทุกสาขา ส่วนเมนูเฉพาะสาขาต้องเป็นสาขาที่กำลังดูอยู่
        abort_unless($product->branch_id === null || $product->branch_id === CurrentBranch::id(), 403);

        $product->load(StorefrontProduct::eagerLoad());

        return response()->json([
            // ราคาพนักงานคิดแยกในหน้าตัวอย่าง (ผู้ใช้สลับดูเองได้) จึงส่ง false ไว้ก่อน
            // ตัวอย่างต้องโชว์ราคาของสาขาที่กำลังดู ไม่ใช่ราคากลาง
            'product' => StorefrontProduct::payload($product, false, CurrentBranch::id()),
            'staff_price' => $product->staffPrice(),
        ]);
    }
}
