<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Services\ShiftService;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    public function open(Request $request, ShiftService $shifts): RedirectResponse
    {
        $data = $request->validate(['opening_cash' => ['required', 'numeric', 'min:0']]);

        try {
            $shifts->open(CurrentBranch::getOrFail(), (float) $data['opening_cash']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'เปิดรอบการขายแล้ว');
    }

    public function close(Request $request, Shift $shift, ShiftService $shifts): RedirectResponse
    {
        abort_unless($shift->branch_id === CurrentBranch::id(), 403);

        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $shifts->close($shift, (float) $data['counted_cash'], $data['note'] ?? null);

        return back()->with('success', 'ปิดรอบการขายแล้ว');
    }
}
