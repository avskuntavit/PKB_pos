<?php

namespace App\Http\Controllers\BackOffice;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** ตั้งค่าสิทธิ์พนักงาน — ตำแหน่งเป็นค่าตั้งต้น แล้วปรับรายคนได้ */
class PermissionController extends Controller
{
    public function index(Request $request): Response
    {
        $branchIds = $this->branchIds($request);

        $users = User::whereIn('branch_id', $branchIds)
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role->value,
                'role_label' => $u->role->label(),
                'is_active' => $u->is_active,
                'permissions' => $u->permissionValues(),
                'overrides' => $u->permissions ?? ['grant' => [], 'revoke' => []],
            ]);

        return Inertia::render('BackOffice/Settings/Permissions', [
            'users' => $users,
            'groups' => Permission::grouped(),
            'roles' => collect(UserRole::cases())->map(fn (UserRole $r) => [
                'value' => $r->value,
                'label' => $r->label(),
                'defaults' => array_map(fn (Permission $p) => $p->value, Permission::defaultsFor($r)),
            ]),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless(in_array($user->branch_id, $request->user()->accessibleBranchIds(), true), 403);

        $data = $request->validate([
            'role' => ['required', 'in:owner,manager,cashier,staff'],
            'is_active' => ['boolean'],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);

        $role = UserRole::from($data['role']);

        // เจ้าของร้านคนสุดท้ายต้องเหลืออยู่เสมอ ไม่งั้นไม่มีใครเข้าหลังบ้านได้อีก
        if ($user->isOwner() && $role !== UserRole::Owner) {
            $remainingOwners = User::where('role', UserRole::Owner->value)
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->count();

            if ($remainingOwners === 0) {
                return back()->with('error', 'ต้องมีเจ้าของร้านอย่างน้อย 1 คนเสมอ');
            }
        }

        // เก็บเป็นส่วนต่างจากค่าตั้งต้นของตำแหน่ง ไม่ใช่รายการสิทธิ์ทั้งชุด
        // พอแก้ค่าตั้งต้นของตำแหน่งภายหลัง คนที่ไม่ได้ตั้งข้อยกเว้นจะได้ตามไปด้วย
        $selected = array_values(array_intersect(
            $data['permissions'] ?? [],
            array_map(fn (Permission $p) => $p->value, Permission::cases()),
        ));

        $defaults = array_map(fn (Permission $p) => $p->value, Permission::defaultsFor($role));

        $overrides = [
            'grant' => array_values(array_diff($selected, $defaults)),
            'revoke' => array_values(array_diff($defaults, $selected)),
        ];

        $user->update([
            'role' => $role,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'permissions' => ($overrides['grant'] || $overrides['revoke']) ? $overrides : null,
        ]);

        return back()->with('success', "บันทึกสิทธิ์ของ {$user->name} แล้ว");
    }
}
