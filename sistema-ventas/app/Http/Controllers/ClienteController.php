<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OrdenaTablas;
use App\Models\Cliente;
use App\Models\Comprobante;
use App\Services\Auditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Registrar al cliente es opcional: la venta al paso va sin él. Solo la
 * factura obliga a identificarlo, y para eso tiene que ser persona jurídica
 * con NIT y dirección fiscal.
 */
class ClienteController extends Controller
{
    use OrdenaTablas;

    public function index(Request $request): View
    {
        $filtros = [
            'buscar' => $request->string('buscar')->toString(),
            'persona' => $request->string('persona')->toString(),
        ];

        $orden = $this->orden($request, [
            'nombre' => 'nombre',
            'documento' => 'documento',
            'tipo' => 'tipo_persona',
            'contacto' => 'telefono',
            // `ventas_count` lo añade withCount(); se puede ordenar por él.
            'compras' => 'ventas_count',
        ], 'nombre');

        $clientes = $this->aplicarOrden(
            Cliente::withCount(['ventas as ventas_count' => fn ($q) => $q->where('estado', '<>', 'ANULADA')])
                ->buscar($filtros['buscar'])
                ->when($filtros['persona'], fn ($q, $tipo) => $q->where('tipo_persona', $tipo)),
            $orden
        )
            ->paginate(12)
            ->withQueryString();

        return view('clientes.index', [
            'title' => 'Clientes',
            'clientes' => $clientes,
            'filtros' => $filtros,
        ]);
    }

    /** Búsqueda para el punto de venta. */
    public function buscar(Request $request): JsonResponse
    {
        $clientes = Cliente::activos()
            ->buscar($request->string('q')->toString())
            ->orderBy('nombre')
            ->limit(15)
            ->get();

        return response()->json(
            $clientes->map(fn (Cliente $c) => [
                'id' => $c->id,
                'nombre' => $c->nombre,
                'etiqueta' => $c->etiqueta,
                'juridica' => $c->esJuridica(),
            ])
        );
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $datos = $this->validar($request);

        // `nombre` lo arma MySQL (columna generada) al insertar: el modelo
        // recién creado solo trae lo que se le mandó, así que sin refrescar
        // queda vacío tanto aquí como en el mensaje de éxito.
        $cliente = Cliente::create($datos)->fresh();

        Auditor::registrar('CLIENTE_CREADO', 'clientes', $cliente->id, [
            'nombre' => $cliente->nombre,
            'tipo_persona' => $cliente->tipo_persona,
        ]);

        // Alta rápida desde el mostrador: el cajero no sale de /pos, así que
        // el carrito que ya tenía armado no se pierde con una navegación de
        // página completa. Si la validación falla, Laravel ya responde el
        // 422 con los errores en JSON solo porque se pidió ese formato.
        if ($request->wantsJson()) {
            return response()->json([
                'id' => $cliente->id,
                'nombre' => $cliente->nombre,
                'etiqueta' => $cliente->etiqueta,
                'juridica' => $cliente->esJuridica(),
            ], 201);
        }

        return redirect()->route('clientes.index')
            ->with('exito', "Cliente «{$cliente->nombre}» registrado.");
    }

    public function update(Request $request, Cliente $cliente): RedirectResponse
    {
        $datos = $this->validar($request, $cliente);

        $cliente->update($datos);

        Auditor::registrar('CLIENTE_ACTUALIZADO', 'clientes', $cliente->id, ['nombre' => $cliente->nombre]);

        return redirect()->route('clientes.index')
            ->with('exito', "Cliente «{$cliente->nombre}» actualizado.");
    }

    /** Con ventas a su nombre se desactiva: los comprobantes lo referencian. */
    public function destroy(Cliente $cliente): RedirectResponse
    {
        $nombre = $cliente->nombre;

        // También cuenta un comprobante: al sustituir una factura a nombre de
        // otro, el documento viejo sigue apuntando a este cliente aunque la
        // venta ya no, y borrarlo reventaba con la clave foránea.
        if ($cliente->ventas()->exists() || Comprobante::where('cliente_id', $cliente->id)->exists()) {
            $cliente->update(['activo' => false]);

            Auditor::registrar('CLIENTE_DESACTIVADO', 'clientes', $cliente->id);

            return redirect()->route('clientes.index')
                ->with('exito', "«{$nombre}» tiene compras registradas, así que se desactivó en lugar de eliminarse.");
        }

        $cliente->delete();

        Auditor::registrar('CLIENTE_ELIMINADO', 'clientes', null, ['nombre' => $nombre]);

        return redirect()->route('clientes.index')->with('exito', "Cliente «{$nombre}» eliminado.");
    }

    /**
     * Las reglas siguen los CHECK del esquema: cada tipo de persona exige sus
     * propios campos y prohíbe los del otro.
     *
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?Cliente $cliente = null): array
    {
        $juridica = $request->input('tipo_persona') === 'JURIDICA';

        $datos = $request->validate([
            'tipo_persona' => ['required', Rule::in(Cliente::TIPOS_PERSONA)],
            'tipo_documento' => [
                'required',
                $juridica ? Rule::in(['NIT']) : Rule::in(Cliente::DOCUMENTOS_NATURAL),
            ],
            'documento' => [
                $juridica ? 'required' : 'nullable',
                'string', 'max:20',
                // Documentos bolivianos: el NIT son solo dígitos; el CI, dígitos
                // con complemento opcional (-1A) y extensión opcional (LP, SC…).
                match ($request->input('tipo_documento')) {
                    'NIT' => 'regex:/^\d{6,13}$/',
                    'CI' => 'regex:/^\d{5,10}(-?\d[A-Za-z])?( ?(LP|CB|SC|OR|PT|TJ|CH|BE|PD))?$/i',
                    default => 'regex:/^[A-Za-z0-9-]{4,20}$/',
                },
                Rule::unique('clientes', 'documento')
                    ->where(fn ($q) => $q->where('tipo_documento', $request->input('tipo_documento')))
                    ->ignore($cliente?->id),
            ],
            'nombres' => [$juridica ? 'nullable' : 'required', 'string', 'max:60'],
            'apellidos' => [$juridica ? 'nullable' : 'required', 'string', 'max:60'],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            'razon_social' => [$juridica ? 'required' : 'nullable', 'string', 'max:150'],
            'nombre_comercial' => ['nullable', 'string', 'max:120'],
            'representante_legal' => ['nullable', 'string', 'max:120'],
            'direccion' => [$juridica ? 'required' : 'nullable', 'string', 'max:200'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120'],
            'activo' => ['boolean'],
        ], [
            'documento.unique' => 'Ya hay un cliente registrado con ese documento.',
            'documento.regex' => match ($request->input('tipo_documento')) {
                'NIT' => 'El NIT lleva solo números, entre 6 y 13 dígitos.',
                'CI' => 'El CI lleva números, con complemento y extensión opcionales: 1234567, 1234567-1A o 1234567 LP.',
                default => 'El documento lleva letras, números o guiones, entre 4 y 20 caracteres.',
            },
            'documento.required' => 'La persona jurídica necesita NIT para poder emitirle factura.',
            'direccion.required' => 'La factura exige la dirección fiscal de la empresa.',
            'razon_social.required' => 'La persona jurídica se identifica por su razón social.',
            'nombres.required' => 'La persona natural se identifica por sus nombres y apellidos.',
            'apellidos.required' => 'La persona natural se identifica por sus nombres y apellidos.',
        ], [
            'tipo_persona' => 'tipo de persona',
            'tipo_documento' => 'tipo de documento',
            'razon_social' => 'razón social',
            'nombre_comercial' => 'nombre comercial',
            'representante_legal' => 'representante legal',
            'direccion' => 'dirección',
            'email' => 'correo',
        ]);

        // El esquema exige que los campos del otro tipo queden nulos.
        foreach ($juridica ? ['nombres', 'apellidos', 'fecha_nacimiento'] : ['razon_social', 'nombre_comercial', 'representante_legal'] as $campo) {
            $datos[$campo] = null;
        }

        return $datos;
    }
}
