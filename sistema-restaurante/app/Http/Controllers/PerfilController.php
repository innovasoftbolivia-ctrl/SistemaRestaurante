<?php

namespace App\Http\Controllers;

use App\Services\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Lo que cada quien puede ver y cambiar de su propia cuenta.
 * Los datos personales los edita el administrador desde Empleados.
 */
class PerfilController extends Controller
{
    public function edit(Request $request): View
    {
        $usuario = $request->user()->loadMissing(['empleado.cargo', 'rol.permisos']);

        return view('perfil.index', [
            'title' => 'Mi perfil',
            'usuario' => $usuario,
            'permisosPorModulo' => $usuario->rol?->permisos->groupBy('modulo') ?? collect(),
        ]);
    }

    public function actualizarPassword(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'password_actual' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:password_actual', Password::min(8)],
        ], [
            'password_actual.current_password' => 'La contraseña actual no es correcta.',
            'password.different' => 'La nueva contraseña tiene que ser distinta de la actual.',
        ], [
            'password_actual' => 'contraseña actual',
            'password' => 'nueva contraseña',
        ]);

        $usuario = $request->user();

        $usuario->forceFill([
            'password_hash' => Hash::make($datos['password']),
            'password_actualizado_en' => now(),
            'debe_cambiar_password' => false,
        ])->save();

        Auditor::registrar('PASSWORD_CAMBIADA', 'usuarios', $usuario->id);

        // La clave inicial de `admin` quedó escrita en disco al instalar (ver
        // CredencialesSeeder). Ya no vale: no tiene por qué seguir ahí.
        if ($usuario->usuario === 'admin') {
            @unlink(storage_path('app/respaldos/PRIMER-ACCESO.txt'));
        }

        return back()->with('exito', 'Tu contraseña fue actualizada.');
    }
}
