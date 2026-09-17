<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OrdenaTablas;
use App\Models\Proveedor;
use App\Services\Auditor;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

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
            'productos' => 'productos_count',
            'estado' => 'activo',
        ], 'proveedor');

        $proveedores = $this->aplicarOrden(
            Proveedor::withCount([
                'productos',
                'productos as productos_activos_count' => fn ($q) => $q->activos(),
            ])
                ->buscar($filtros['buscar'])
                ->when($filtros['estado'] !== '', fn ($q) => $q->where('activo', $filtros['estado'] === 'ACTIVO')),
            $orden
        )
            ->paginate(10)
            ->withQueryString();

        return view('proveedores.index', [
            'title' => 'Proveedores',
            'trail' => ['Catálogo' => route('productos.index')],
            'proveedores' => $proveedores,
            'filtros' => $filtros,
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $datos = $this->validar($request);

        $proveedor = Proveedor::create($datos);

        Auditor::registrar('PROVEEDOR_CREADO', 'proveedores', $proveedor->id, $datos);

        // Alta rápida desde la pantalla de compras, por el mismo motivo que en
        // productos: el proveedor nuevo se descubre justo cuando se está
        // cargando su primera factura, y salir de la pantalla costaría todo lo
        // que ya se tecleó.
        if ($request->wantsJson()) {
            return response()->json([
                'id' => $proveedor->id,
                'razon_social' => $proveedor->razon_social,
            ], 201);
        }

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

    /**
     * Con productos o ingresos de mercadería a su nombre, el proveedor se
     * desactiva: su rastro en el kardex tiene que seguir siendo legible.
     */
    public function destroy(Proveedor $proveedor): RedirectResponse
    {
        $razon = $proveedor->razon_social;

        if ($proveedor->productos()->exists()) {
            $proveedor->update(['activo' => false]);

            Auditor::registrar('PROVEEDOR_DESACTIVADO', 'proveedores', $proveedor->id);

            return redirect()->route('proveedores.index')
                ->with('exito', "El proveedor «{$razon}» abastece productos del catálogo, así que se desactivó en lugar de eliminarse.");
        }

        try {
            $proveedor->delete();
        } catch (QueryException) {
            $proveedor->update(['activo' => false]);

            Auditor::registrar('PROVEEDOR_DESACTIVADO', 'proveedores', $proveedor->id, ['motivo' => 'tiene movimientos asociados']);

            return redirect()->route('proveedores.index')
                ->with('exito', "El proveedor «{$razon}» tiene movimientos registrados, así que se desactivó en lugar de eliminarse.");
        }

        Auditor::registrar('PROVEEDOR_ELIMINADO', 'proveedores', null, ['razon_social' => $razon]);

        return redirect()->route('proveedores.index')->with('exito', "Proveedor «{$razon}» eliminado.");
    }

    private function validar(Request $request, ?Proveedor $proveedor = null): array
    {
        return $request->validate([
            'razon_social' => ['required', 'string', 'max:120'],
            'documento' => [
                'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('proveedores', 'documento')->ignore($proveedor?->id),
            ],
            'telefono' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120'],
            'direccion' => ['nullable', 'string', 'max:200'],
            'activo' => ['boolean'],
        ], [
            'documento.unique' => 'Ya hay un proveedor registrado con ese documento.',
            'documento.regex' => 'El documento solo admite letras, números y guiones.',
        ], [
            'razon_social' => 'razón social',
            'documento' => 'documento',
            'telefono' => 'teléfono',
            'email' => 'correo',
            'direccion' => 'dirección',
        ]);
    }
}
