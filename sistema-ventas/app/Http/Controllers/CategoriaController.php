<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Services\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategoriaController extends Controller
{
    public function index(Request $request): View
    {
        $buscar = $request->string('buscar')->toString();

        $categorias = Categoria::withCount([
            'productos',
            'productos as productos_activos_count' => fn ($q) => $q->activos(),
        ])
            ->when($buscar !== '', fn ($q) => $q->where('nombre', 'like', "%{$buscar}%"))
            ->orderBy('nombre')
            ->get();

        return view('categorias.index', [
            'title' => 'Categorías',
            'trail' => ['Menú' => route('productos.index')],
            'categorias' => $categorias,
            'buscar' => $buscar,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        $categoria = Categoria::create($datos);

        Auditor::registrar('CATEGORIA_CREADA', 'categorias', $categoria->id, $datos);

        return redirect()->route('categorias.index')
            ->with('exito', "Categoría «{$categoria->nombre}» creada.");
    }

    public function update(Request $request, Categoria $categoria): RedirectResponse
    {
        $datos = $this->validar($request, $categoria);

        // Desactivar es la forma de «eliminar» lo que tiene historial: sin el
        // permiso de eliminar, el estado queda como estaba.
        if (! $request->user()->tienePermiso('registros.eliminar')) {
            unset($datos['activo']);
        }

        $categoria->update($datos);

        Auditor::registrar('CATEGORIA_ACTUALIZADA', 'categorias', $categoria->id, $datos);

        return redirect()->route('categorias.index')
            ->with('exito', "Categoría «{$categoria->nombre}» actualizada.");
    }

    /** Con algo del menú dentro se desactiva; el histórico no se rompe. */
    public function destroy(Categoria $categoria): RedirectResponse
    {
        if ($categoria->productos()->exists()) {
            $categoria->update(['activo' => false]);

            Auditor::registrar('CATEGORIA_DESACTIVADA', 'categorias', $categoria->id);

            return redirect()->route('categorias.index')
                ->with('exito', "La categoría «{$categoria->nombre}» tiene ítems del menú, así que se desactivó en lugar de eliminarse.");
        }

        $nombre = $categoria->nombre;
        $categoria->delete();

        Auditor::registrar('CATEGORIA_ELIMINADA', 'categorias', null, ['nombre' => $nombre]);

        return redirect()->route('categorias.index')->with('exito', "Categoría «{$nombre}» eliminada.");
    }

    private function validar(Request $request, ?Categoria $categoria = null): array
    {
        return $request->validate([
            'nombre' => [
                'required', 'string', 'max:60',
                Rule::unique('categorias', 'nombre')->ignore($categoria?->id),
            ],
            'descripcion' => ['nullable', 'string', 'max:200'],
            'activo' => ['boolean'],
            // Sin marcar al crear, la base la deja en «sí»: casi todo lo de la
            // carta se cocina, y olvidarla no puede esconderle un plato a la
            // cocina.
            'pasa_por_cocina' => ['boolean'],
        ], [], [
            'nombre' => 'nombre',
            'descripcion' => 'descripción',
            'pasa_por_cocina' => 'pasa por la cocina',
        ]);
    }
}
