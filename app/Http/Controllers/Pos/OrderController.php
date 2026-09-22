<?php

namespace App\Http\Controllers\Pos;

use App\Enums\Course;
use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\OrderService;
use App\Services\ShiftService;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(protected OrderService $orders) {}

    public function store(Request $request, ShiftService $shifts): RedirectResponse
    {
        $data = $request->validate([
            'dining_table_id' => ['nullable', 'exists:dining_tables,id'],
            'type' => ['required', 'in:dine_in,takeaway,delivery'],
            'guest_count' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $branch = CurrentBranch::getOrFail();
        $table = $data['dining_table_id'] ? DiningTable::findOrFail($data['dining_table_id']) : null;

        if ($table?->openOrder) {
            return redirect()->route('pos.terminal', $table->openOrder);
        }

        $order = $this->orders->open(
            $branch,
            $table,
            OrderType::from($data['type']),
            $data['guest_count'],
            $shifts->current($branch),
        );

        return redirect()->route('pos.terminal', $order);
    }

    public function addItem(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'qty' => ['required', 'numeric', 'min:0.001'],
            'modifier_ids' => ['array'],
            'modifier_ids.*' => ['integer', 'exists:modifiers,id'],
            'note' => ['nullable', 'string', 'max:255'],
            'open_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->orders->addItem(
            $order,
            Product::findOrFail($data['product_id']),
            (float) $data['qty'],
            $data['modifier_ids'] ?? [],
            $data['note'] ?? null,
            isset($data['open_price']) ? (float) $data['open_price'] : null,
        );

        return back();
    }

    public function updateItem(Request $request, OrderItem $item): RedirectResponse
    {
        $this->authorizeOrder($item->order);

        $data = $request->validate(['qty' => ['required', 'numeric', 'min:0']]);
        $this->orders->updateItemQty($item, (float) $data['qty']);

        return back();
    }

    public function voidItem(Request $request, OrderItem $item): RedirectResponse
    {
        $this->authorizeOrder($item->order);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $this->orders->voidItem($item, $data['reason'] ?? null);

        return back();
    }

    /**
     * ส่งครัว — ส่งทั้งบิล หรือเลือกเฉพาะบางรายการ
     *
     * ไม่ส่ง items มา = ส่งทุกอย่างที่ค้างอยู่ (พฤติกรรมเดิม ปุ่ม "ส่งทั้งหมด")
     * ส่ง items มา = ส่งเฉพาะที่ระบุ ใช้ตอนกดส่งทีละคอร์ส
     */
    public function send(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        $data = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*' => ['integer'],
        ]);

        $count = $this->orders->sendToKitchen($order, $data['items'] ?? null);

        if ($count === 0) {
            return back()->with('error', 'ไม่มีรายการที่ส่งครัวได้');
        }

        return back()->with('success', "ส่งรายการเข้าครัวแล้ว {$count} รายการ");
    }

    /** ตั้ง/ถอดคอร์สของรายการเดียว */
    public function setCourse(Request $request, OrderItem $item): RedirectResponse
    {
        $this->authorizeOrder($item->order);

        $data = $request->validate([
            'course' => ['nullable', Rule::enum(Course::class)],
        ]);

        $this->orders->setItemCourse(
            $item,
            filled($data['course'] ?? null) ? Course::from((int) $data['course']) : null,
        );

        return back();
    }

    public function discount(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0'],
            'percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $this->orders->applyBillDiscount(
            $order,
            (float) ($data['amount'] ?? 0),
            isset($data['percent']) ? (float) $data['percent'] : null,
        );

        return back()->with('success', 'ใส่ส่วนลดท้ายบิลแล้ว');
    }

    public function moveTable(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);

        $data = $request->validate(['dining_table_id' => ['required', 'exists:dining_tables,id']]);
        $this->orders->moveTable($order, DiningTable::findOrFail($data['dining_table_id']));

        return back()->with('success', 'ย้ายโต๊ะเรียบร้อย');
    }

    public function void(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrder($order);
        abort_unless($request->user()->role->canVoidBill(), 403, 'ต้องเป็นผู้จัดการขึ้นไปจึงจะทำลายบิลได้');

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->orders->void($order, $data['reason']);

        return redirect()->route('pos.tables')->with('success', 'ทำลายบิลแล้ว');
    }

    protected function authorizeOrder(Order $order): void
    {
        abort_unless($order->branch_id === CurrentBranch::id(), 403);
    }
}
