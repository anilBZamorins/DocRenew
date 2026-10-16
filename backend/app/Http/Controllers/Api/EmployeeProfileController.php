<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeProfileRequest;
use App\Http\Requests\UpdateEmployeeProfileRequest;
use App\Http\Resources\EmployeeProfileResource;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * FSD 3.2 / 3.3 / 4.4 — Employee profile CRUD. Visibility is scoped by
 * EmployeeProfile::visibleTo() (role-based: tenant-wide / own team /
 * own profile); individual actions are gated by EmployeeProfilePolicy.
 */
class EmployeeProfileController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', EmployeeProfile::class);

        $query = EmployeeProfile::query();
        EmployeeProfile::visibleTo($query, $request->user());

        if ($request->filled('department')) {
            $query->where('department', $request->string('department'));
        }

        if ($request->filled('active')) {
            $query->where('active', $request->boolean('active'));
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)->orWhere('employee_code', 'like', $term);
            });
        }

        $profiles = $query->orderBy('name')->paginate($request->integer('per_page', 25));

        return EmployeeProfileResource::collection($profiles);
    }

    public function store(StoreEmployeeProfileRequest $request): JsonResponse
    {
        $profile = EmployeeProfile::create([
            ...$request->validated(),
            'tenant_id' => $request->user()->tenant_id,
            'active' => true,
        ]);

        AuditLog::record('employee_profile.create', 'EmployeeProfile', $profile->id, [
            'name' => $profile->name,
        ]);

        return (new EmployeeProfileResource($profile))->response()->setStatusCode(201);
    }

    public function show(Request $request, EmployeeProfile $employee_profile): EmployeeProfileResource
    {
        $this->authorize('view', $employee_profile);

        return new EmployeeProfileResource($employee_profile->load('user'));
    }

    public function update(UpdateEmployeeProfileRequest $request, EmployeeProfile $employee_profile): EmployeeProfileResource
    {
        $employee_profile->update($request->validated());

        AuditLog::record('employee_profile.update', 'EmployeeProfile', $employee_profile->id, [
            'fields' => array_keys($request->validated()),
        ]);

        return new EmployeeProfileResource($employee_profile);
    }

    /**
     * FSD 4.4: "Deactivating an employee suspends their user account and
     * retains their document history for audit purposes; it does not
     * delete records." There is deliberately no destroy() endpoint.
     */
    public function deactivate(Request $request, EmployeeProfile $employee_profile): EmployeeProfileResource
    {
        $this->authorize('deactivate', $employee_profile);

        $employee_profile->update(['active' => false]);
        $employee_profile->user?->update(['status' => 'SUSPENDED']);

        AuditLog::record('employee_profile.deactivate', 'EmployeeProfile', $employee_profile->id, []);

        return new EmployeeProfileResource($employee_profile);
    }

    public function reactivate(Request $request, EmployeeProfile $employee_profile): EmployeeProfileResource
    {
        $this->authorize('deactivate', $employee_profile);

        $employee_profile->update(['active' => true]);

        AuditLog::record('employee_profile.reactivate', 'EmployeeProfile', $employee_profile->id, []);

        return new EmployeeProfileResource($employee_profile);
    }
}
