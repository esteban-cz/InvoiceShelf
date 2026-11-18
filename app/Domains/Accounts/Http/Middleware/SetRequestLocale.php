<?php

namespace App\Domains\Accounts\Http\Middleware;

use App\Domains\Accounts\Application\UserLocale;
use App\Domains\Accounts\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetRequestLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $previous = app()->getLocale();
        if ($request->user() instanceof User) {
            $company = $request->header('company');
            app()->setLocale(UserLocale::for($request->user(), $company ? (int) $company : null));
        }

        try {
            return $next($request);
        } finally {
            app()->setLocale($previous);
        }
    }
}
