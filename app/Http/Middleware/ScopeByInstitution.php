<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\InstitutionUser;
use Symfony\Component\HttpFoundation\Response;

class ScopeByInstitution
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(401, 'Unauthenticated.');
        }

        // Super-admin can access all institutions
        if ($user->hasRole('super-admin')) {
            return $next($request);
        }

        // Get institution ID for institution-admin
        if ($user->hasRole('institution-admin')) {
            $institutionUser = InstitutionUser::where('user_id', $user->id)->first();

            if (!$institutionUser) {
                abort(403, 'You are not associated with any institution.');
            }

            // Store institution ID in request for controllers to use
            $request->merge(['scope_institution_id' => $institutionUser->institution_id]);

            return $next($request);
        }

        // For other roles, pass through (they have their own scoping)
        return $next($request);
    }
}