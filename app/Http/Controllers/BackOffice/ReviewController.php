<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\OrderReview;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReviewController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->dateRange($request);
        $branchIds = $this->branchIds($request);

        $reviews = OrderReview::with(['order:id,order_no,grand_total', 'customer:id,name,phone', 'repliedBy:id,name'])
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [$from, $to])
            ->when($request->filled('rating'), fn ($q) => $q->where('rating', $request->integer('rating')))
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        // กระจายคะแนน 1-5 ดาว ใช้ดูว่าปัญหากระจุกอยู่ตรงไหน
        $distribution = OrderReview::whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [$from, $to])
            ->selectRaw('rating, COUNT(*) AS total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        $counts = collect(range(1, 5))->mapWithKeys(fn (int $r) => [$r => (int) ($distribution[$r] ?? 0)]);
        $total = $counts->sum();

        // แท็กที่ถูกเลือกบ่อย — นับใน PHP เพราะเก็บเป็น json
        $tagCounts = [];

        foreach (OrderReview::whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [$from, $to])
            ->pluck('tags') as $tags) {
            foreach ($tags ?? [] as $tag) {
                $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
            }
        }

        arsort($tagCounts);

        return Inertia::render('BackOffice/Reviews/Index', [
            'filters' => ['from' => $from, 'to' => $to, 'rating' => $request->input('rating')],
            'reviews' => $reviews,
            'stats' => [
                'total' => $total,
                'average' => $total > 0
                    ? round($counts->reduce(fn ($carry, $c, $r) => $carry + ($c * $r), 0) / $total, 2)
                    : 0.0,
                'distribution' => $counts,
                'top_tags' => collect($tagCounts)->take(8)->map(fn ($count, $tag) => ['tag' => $tag, 'count' => $count])->values(),
            ],
        ]);
    }

    public function reply(Request $request, OrderReview $review): RedirectResponse
    {
        abort_unless(in_array($review->branch_id, $request->user()->accessibleBranchIds(), true), 403);

        $data = $request->validate(['reply' => ['required', 'string', 'max:500']]);

        $review->update([
            'reply' => $data['reply'],
            'replied_by' => $request->user()->id,
            'replied_at' => now(),
        ]);

        return back()->with('success', 'บันทึกคำตอบแล้ว');
    }
}
