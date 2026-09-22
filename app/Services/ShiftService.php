<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Shift;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class ShiftService
{
    public function __construct(protected ActivityLogger $logger) {}

    public function current(Branch $branch): ?Shift
    {
        return Shift::where('branch_id', $branch->id)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();
    }

    public function open(Branch $branch, float $openingCash = 0): Shift
    {
        if ($this->current($branch)) {
            throw new \DomainException('ยังมีรอบการขายที่เปิดค้างอยู่ กรุณาปิดรอบก่อน');
        }

        $businessDate = $branch->businessDateFor();

        $shift = Shift::create([
            'branch_id' => $branch->id,
            'shift_no' => 'S'.$businessDate->format('ymd').'-'
                .str_pad((string) (Shift::where('branch_id', $branch->id)
                    ->whereDate('business_date', $businessDate)->count() + 1), 2, '0', STR_PAD_LEFT),
            'business_date' => $businessDate->toDateString(),
            'opened_by' => auth()->id(),
            'opened_at' => now(),
            'opening_cash' => Money::round($openingCash),
            'status' => 'open',
        ]);

        $this->logger->log('shift.open', $shift, ['opening_cash' => $openingCash], $branch);

        return $shift;
    }

    /** ปิดรอบ — เทียบเงินสดที่ควรมีกับที่นับได้ */
    public function close(Shift $shift, float $countedCash, ?string $note = null): Shift
    {
        return DB::transaction(function () use ($shift, $countedCash, $note) {
            $cashSales = (float) $shift->payments()
                ->where('method', PaymentMethod::Cash->value)
                ->sum('amount');

            $refunds = (float) $shift->orders()
                ->join('refunds', 'refunds.order_id', '=', 'orders.id')
                ->where('refunds.method', 'cash')
                ->sum('refunds.amount');

            $expected = Money::round(
                (float) $shift->opening_cash
                + $cashSales
                + (float) $shift->cash_in
                - (float) $shift->cash_out
                - $refunds
            );

            $shift->update([
                'closed_by' => auth()->id(),
                'closed_at' => now(),
                'expected_cash' => $expected,
                'counted_cash' => Money::round($countedCash),
                'cash_diff' => Money::round($countedCash - $expected),
                'status' => 'closed',
                'note' => $note,
            ]);

            $this->logger->log('shift.close', $shift, [
                'expected' => $expected,
                'counted' => $countedCash,
                'diff' => (float) $shift->cash_diff,
            ]);

            return $shift;
        });
    }

    public function addCashMovement(Shift $shift, string $type, float $amount, ?string $reason = null): void
    {
        DB::transaction(function () use ($shift, $type, $amount, $reason) {
            $shift->cashMovements()->create([
                'user_id' => auth()->id(),
                'type' => $type,
                'amount' => Money::round($amount),
                'reason' => $reason,
            ]);

            $shift->increment($type === 'in' ? 'cash_in' : 'cash_out', Money::round($amount));
        });
    }
}
