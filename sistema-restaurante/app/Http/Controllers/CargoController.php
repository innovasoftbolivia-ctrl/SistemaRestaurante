<?php

namespace App\Http\Controllers;

use App\Models\Cargo;
use App\Services\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CargoController extends Controller
{
    public function index(Request $request): View
    {
        $buscar = $request->string('buscar')->toString();

        $cargos = Cargo::withCount([
            'empleados',
            'empleados as empleados_activos_count' => fn ($q) => $q->activos(),
        ])
            ->when($buscar !== '', fn ($q) => $q->where('nombre', 'like', "%{$buscar}%"))
            ->orderBy('nombre')
            ->get();

        return view('cargos.index', [
            'title' => 'Cargos',
            'trail' => ['Personal' => route('empleados.index')],
            'cargos' => $cargos,
            'buscar' => $buscar,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        $cargo = Cargo::create($datos);

        Auditor::registrar('CARGO_CREADO', 'cargos', $cargo->id, $datos);

        return redirect()->route('cargos.index')
            ->with('exito', "Cargo «{$cargo->nombre}» creado.");
    }

    public function update(Request $request, Cargo $cargo): RedirectResponse
    {
        $datos = $this->validar($request, $cargo);

        $cargo->update($datos);

        Auditor::registrar('CARGO_ACTUALIZADO', 'cargos', $cargo->id, $datos);

        return redirect()->route('cargos.index')
            ->with('exito', "Cargo «{$cargo->nombre}» actualizado.");
    }

    /**
     * Un cargo con empleados no se borra: se desactiva, para no romper el
     * histórico. Solo se elimina si nunca se usó.
     */
    public function destroy(Cargo $cargo): RedirectResponse
    {
        if ($cargo->empleados()->exists()) {
            $cargo->update(['activo' => false]);

            Auditor::registrar('CARGO_DESACTIVADO', 'cargos', $cargo->id);

            return redirect()->route('cargos.index')
                ->with('exito', "El cargo «{$cargo->nombre}» tiene empleados asignados, así que se desactivó en lugar de eliminarse.");
        }

        $nombre = $cargo->nombre;
        $cargo->delete();

        Auditor::registrar('CARGO_ELIMINADO', 'cargos', null, ['nombre' => $nombre]);

        return redirect()->route('cargos.index')->with('exito', "Cargo «{$nombre}» eliminado.");
    }

    private function validar(Request $request, ?Cargo $cargo = null): array
    {
        return $request->validate([
            'nombre' => [
                'required', 'string', 'max:50',
                Rule::unique('cargos', 'nombre')->ignore($cargo?->id),
            ],
            'descripcion' => ['nullable', 'string', 'max:150'],
            'activo' => ['boolean'],
        ], [], [
            'nombre' => 'nombre',
            'descripcion' => 'descripción',
        ]);
    }
}
