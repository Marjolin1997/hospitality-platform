<?php

namespace App\Http\Middleware;

use App\Models\Business;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireBusinessPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $business = app(Business::class);
        $user = $request->user();

        $allowed = $user
            && $permissions !== []
            && collect($permissions)->contains(
                fn (string $permission): bool => $user->hasPermissionInBusiness($business, $permission)
            );

        abort_unless($allowed, 403, 'You do not have permission to perform this action.');

        return $next($request);
    }
}
