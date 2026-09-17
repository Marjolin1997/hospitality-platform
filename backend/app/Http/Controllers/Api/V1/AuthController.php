<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', mb_strtolower($credentials['email']))->first();
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'The provided credentials are incorrect.']);
        }

        Auth::guard('web')->login($user, false);
        $request->session()->regenerate();

        return response()->json(['data' => $this->payload($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['message' => 'Signed out.']);
    }

    private function payload(User $user): array
    {
        $businesses = $user->businesses()
            ->wherePivot('status', 'active')
            ->where('businesses.status', 'active')
            ->with(['locations' => fn ($query) => $query->where('is_active', true)->orderBy('name')])
            ->get();

        $roleIds = $businesses->pluck('pivot.role_id')->filter()->unique()->values();
        $roles = Role::query()
            ->whereIn('id', $roleIds)
            ->with('permissions:id,key')
            ->get()
            ->keyBy('id');

        return [
            'id' => $user->getKey(), 'name' => $user->name, 'email' => $user->email,
            'businesses' => $businesses->map(function ($business) use ($roles): array {
                $role = $business->pivot->role_id ? $roles->get($business->pivot->role_id) : null;

                // Only business-scoped roles may authorize this business. The backend
                // permission middleware remains the security boundary; this list is
                // exposed so the SPA can hide actions the user cannot perform.
                $permissions = $role && (string) $role->business_id === (string) $business->getKey()
                    ? $role->permissions->pluck('key')->sort()->values()
                    : collect();

                return [
                    'id' => $business->getKey(), 'name' => $business->name, 'currency' => $business->currency,
                    'timezone' => $business->timezone, 'role_id' => $business->pivot->role_id,
                    'role' => $role && (string) $role->business_id === (string) $business->getKey() ? [
                        'id' => $role->getKey(), 'name' => $role->name, 'slug' => $role->slug,
                    ] : null,
                    'permissions' => $permissions,
                    'locations' => $business->locations->map(fn ($location) => [
                        'id' => $location->getKey(), 'name' => $location->name,
                    ]),
                ];
            })->values(),
        ];
    }
}
