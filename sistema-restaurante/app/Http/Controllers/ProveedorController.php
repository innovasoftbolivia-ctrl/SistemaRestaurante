<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OrdenaTablas;
use App\Models\Proveedor;
use App\Services\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * A quién se le compran las bebidas embotelladas. Con compras a su nombre no
 * se borra: se desactiva, y sus facturas siguen leyéndose.
 */
class ProveedorController extends Controller
{
    use OrdenaTablas;

    public function index(Request $request): View
    {
        $filtros = [
            'buscar' => $request->string('buscar')->toString(),
            'estado' => $request->string('estado')->toString(),
        ];

        $orden = $this->orden($request, [
            'proveedor' => 'razon_social',
            'documento' => 'documento',
            'contacto' => 'telefono',
            'compras' => 'compras_count',
        ], 'proveedor');

        $proveedores = $this->aplicarOrden(
            Proveedor::withCount('compras')
                ->buscar($filtros['buscar'])
                ->when($filtros['estado'] === 'activos', fn ($q) => $q->where('activo', 1))
                ->when($filtros['estado'] === 'inactivos', fn ($q) => $q->where('activo', 0)),
            $orden
        )
            ->paginate(10)
            ->withQueryString();

        return view('proveedores.index', [
            'title' => 'Proveedores',
            'trail' => ['Inventario' => route('inventario.index')],
            'proveedores' => $proveedores,
            'filtros' => $filtros,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        $proveedor = Proveedor::create($datos);

        Auditor::registrar('PROVEEDOR_CREADO', 'proveedores', $proveedor->id, $datos);

        return redirect()->route('proveedores.index')
            ->with('exito', "Proveedor «{$proveedor->razon_social}» registrado.");
    }

    public function update(Request $request, Proveedor $proveedor): RedirectResponse
    {
        $datos = $this->validar($request, $proveedor);

        // Desactivar es la forma de «eliminar» lo que tiene historial: sin el
        // permiso de eliminar, el estado queda como estaba.
        if (! $request->user()->tienePermiso('registros.eliminar')) {
            unset($datos['activo']);
        }

        $proveedor->update($datos);

        Auditor::registrar('PROVEEDOR_ACTUALIZADO', 'proveedores', $proveedor->id, $datos);

        return redirect()->route('proveedores.index')
            ->with('exito', "Proveedor «{$proveedor->razon_social}» actualizado.");
    }

    /** Con compras a su nombre se desactiva: las facturas lo referencian. */
    public function destroy(Proveedor $proveedor): RedirectResponse
    {
        $razon = $proveedor->razon_social;

        if ($proveedor->compras()->exists()) {
            $proveedor->update(['activo' => false]);

            Auditor::registrar('PROVEEDOR_DESACTIVADO', 'proveedores', $proveedor->id);

            return redirect()->route('proveedores.index')
                ->with('exito', "«{$razon}» tiene compras registradas, así que se desactivó en lugar de eliminarse.");
        }

        $proveedor->delete();

        Auditor::registrar('PROVEEDOR_ELIMINADO', 'proveedores', null, ['razon_social' => $razon]);

        return redirect()->route('proveedores.index')->with('exito', "Proveedor «{$razon}» eliminado.");
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, ?Proveedor $proveedor = null): array
    {
        return $request->validate([
            'razon_social' => [
                'required', 'string', 'max:120',
                Rule::unique('proveedores', 'razon_social')->ignore($proveedor?->id),
            ],
            'documento' => [
                'nullable', 'string', 'max:20', 'regex:/^\d+$/',
                Rule::unique('proveedores', 'documento')->ignore($proveedor?->id),
            ],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
            'direccion' => ['nullable', 'string', 'max:200'],
            'activo' => ['boolean'],
        ], [
            'razon_social.unique' => 'Ya hay un proveedor registrado con esa razón social.',
            'documento.unique' => 'Ya hay un proveedor registrado con ese NIT.',
            'documento.regex' => 'El NIT lleva solo números.',
        ], [
            'razon_social' => 'razón social',
            'documento' => 'NIT',
            'telefono' => 'teléfono',
            'email' => 'correo',
            'direccion' => 'dirección',
        ]);
    }
}
