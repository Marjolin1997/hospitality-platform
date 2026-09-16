<?php

namespace App\Http\Middleware;

use App\Models\Business;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireBusinessPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $business = app(Business::class);
        $user = $request->user();

        abort_unless($user && $user->hasPermissionInBusiness($business, $permission), 403, 'You do not have permission to perform this action.');

        return $next($request);
    }
}
