<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAdminPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(403, 'No autorizado.');
        }

        // Si el usuario tiene sesión de qué certificado usó, podríamos chequearlo.
        // Pero es más robusto dejar que si ALGÚN certificado del usuario tiene el permiso, es admin.
        $hasPermission = $user->certificates()->whereHas('permissions', function($query) {
            $query->where('slug', 'manage_system');
        })->exists();

        if (!$hasPermission) {
            abort(403, 'No tienes permisos para realizar esta acción. Necesitas el permiso de administrador.');
        }

        return $next($request);
    }
}
