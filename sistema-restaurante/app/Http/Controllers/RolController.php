<?php

namespace App\Http\Controllers;

use App\Models\Permiso;
use App\Models\Rol;
use App\Services\Auditor;
use App\Support\Administracion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RolController extends Controller
{
    public function index(): View
    {
        return view('roles.index', [
            'title' => 'Roles y permisos',
            'trail' => ['Seguridad' => route('usuarios.index')],
            'roles' => Rol::with('permisos:id,codigo,modulo,descripcion')
                ->withCount(['usuarios', 'usuarios as usuarios_activos_count' => fn ($q) => $q->activos()])
                ->orderBy('nombre')
                ->get(),
            // Agrupados por módulo para que el formulario se lea como el
            // sistema y no como una lista plana de códigos.
            'permisosPorModulo' => Permiso::orderBy('modulo')->orderBy('codigo')->get()->groupBy('modulo'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        if (Administracion::otorgaDeMas($datos['permisos'] ?? [])) {
            return back()->with('error', Administracion::MENSAJE_POR_ENCIMA)->withInput();
        }

        $rol = Rol::create([
            'nombre' => $datos['nombre'],
            'descripcion' => $datos['descripcion'] ?? null,
            'activo' => $datos['activo'] ?? true,
        ]);

        $rol->permisos()->sync($datos['permisos'] ?? []);

        Auditor::registrar('ROL_CREADO', 'roles', $rol->id, [
            'nombre' => $rol->nombre,
            'permisos' => $datos['permisos'] ?? [],
        ]);

        return redirect()->route('roles.index')->with('exito', "Rol «{$rol->nombre}» creado.");
    }

    public function update(Request $request, Rol $rol): RedirectResponse
    {
        $datos = $this->validar($request, $rol);

        if (Administracion::rolPorEncima($rol) || Administracion::otorgaDeMas($datos['permisos'] ?? [])) {
            return back()->with('error', Administracion::MENSAJE_POR_ENCIMA)->withInput();
        }

        $conservaAdministracion = ($datos['activo'] ?? $rol->activo)
            && in_array(Permiso::where('codigo', Administracion::PERMISO)->value('id'), array_map('intval', $datos['permisos'] ?? []), true);

        if (! $conservaAdministracion && Administracion::restantes(rolSinPermiso: $rol->id) === 0) {
            return back()->with('error', Administracion::MENSAJE)->withInput();
        }

        $rol->update([
            'nombre' => $datos['nombre'],
            'descripcion' => $datos['descripcion'] ?? null,
            'activo' => $datos['activo'] ?? $rol->activo,
        ]);

        $rol->permisos()->sync($datos['permisos'] ?? []);

        Auditor::registrar('ROL_ACTUALIZADO', 'roles', $rol->id, [
            'nombre' => $rol->nombre,
            'permisos' => $datos['permisos'] ?? [],
        ]);

        return redirect()->route('roles.index')->with('exito', "Rol «{$rol->nombre}» actualizado.");
    }

    public function destroy(Rol $rol): RedirectResponse
    {
        if (Administracion::rolPorEncima($rol)) {
            return back()->with('error', Administracion::MENSAJE_POR_ENCIMA);
        }

        if ($rol->usuarios()->exists()) {
            if (Administracion::restantes(rolSinPermiso: $rol->id) === 0) {
                return back()->with('error', Administracion::MENSAJE);
            }

            $rol->update(['activo' => false]);

            Auditor::registrar('ROL_DESACTIVADO', 'roles', $rol->id);

            return redirect()->route('roles.index')
                ->with('exito', "El rol «{$rol->nombre}» tiene cuentas asignadas, así que se desactivó en lugar de eliminarse.");
        }

        $nombre = $rol->nombre;
        $rol->permisos()->detach();
        $rol->delete();

        Auditor::registrar('ROL_ELIMINADO', 'roles', null, ['nombre' => $nombre]);

        return redirect()->route('roles.index')->with('exito', "Rol «{$nombre}» eliminado.");
    }

    private function validar(Request $request, ?Rol $rol = null): array
    {
        return $request->validate([
            'nombre' => [
                'required', 'string', 'max:40',
                Rule::unique('roles', 'nombre')->ignore($rol?->id),
            ],
            'descripcion' => ['nullable', 'string', 'max:150'],
            'activo' => ['boolean'],
            'permisos' => ['array'],
            'permisos.*' => ['integer', Rule::exists('permisos', 'id')],
        ], [], [
            'nombre' => 'nombre',
            'descripcion' => 'descripción',
            'permisos' => 'permisos',
        ]);
    }
}
