<?php

namespace App\Http\Middleware;

use App\Models\Business;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveBusinessContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $businessId = $request->header('X-Business-Id');
        abort_unless($businessId, 400, 'Business context is required.');

        $business = Business::query()
            ->whereKey($businessId)
            ->where('status', 'active')
            ->whereHas('users', fn ($query) => $query
                ->whereKey($request->user()->getKey())
                ->wherePivot('status', 'active'))
            ->firstOrFail();

        app()->instance(Business::class, $business);
        $request->attributes->set('business', $business);
        return $next($request);
    }
}
