<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OrdenaTablas;
use App\Models\Cargo;
use App\Models\Empleado;
use App\Models\TipoDocumento;
use App\Services\Auditor;
use App\Support\Administracion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmpleadoController extends Controller
{
    use OrdenaTablas;

    /** Columnas por las que se puede ordenar la tabla. La clave viaja en la URL. */
    private const ORDENABLES = [
        'nombre' => 'nombre_completo',
        'documento' => 'documento',
        'contrato' => 'tipo_contrato',
        'ingreso' => 'fecha_ingreso',
        'estado' => 'estado',
    ];

    public function index(Request $request): View
    {
        $filtros = [
            'buscar' => $request->string('buscar')->toString(),
            'estado' => $request->string('estado')->toString(),
            'cargo' => $request->integer('cargo') ?: null,
        ];

        $orden = $this->orden($request, self::ORDENABLES + [
            // El cargo vive en otra tabla: se ordena con una subconsulta en vez
            // de por `cargo_id`, que ordenaría por número y no por nombre.
            'cargo' => Cargo::select('nombre')->whereColumn('cargos.id', 'empleados.cargo_id'),
        ], 'nombre');

        $empleados = $this->aplicarOrden(
            Empleado::with(['cargo:id,nombre', 'usuario:id,empleado_id,usuario,activo'])
                ->buscar($filtros['buscar'])
                ->when($filtros['estado'], fn ($q, $estado) => $q->where('estado', $estado))
                ->when($filtros['cargo'], fn ($q, $cargo) => $q->where('cargo_id', $cargo)),
            $orden
        )
            ->paginate(10)
            ->withQueryString();

        return view('empleados.index', [
            'title' => 'Empleados',
            'trail' => ['Personal' => route('empleados.index')],
            'empleados' => $empleados,
            'filtros' => $filtros,
            'cargos' => Cargo::activos()->orderBy('nombre')->pluck('nombre', 'id'),
            'estados' => Empleado::ESTADOS,
        ]);
    }

    public function create(): View
    {
        return view('empleados.form', [
            'title' => 'Nuevo empleado',
            'trail' => ['Personal' => route('empleados.index'), 'Empleados' => route('empleados.index')],
            'empleado' => new Empleado(['estado' => 'ACTIVO', 'tipo_documento' => 'CI', 'tipo_contrato' => 'INDEFINIDO']),
            ...$this->opciones(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        $empleado = Empleado::create($datos);

        Auditor::registrar('EMPLEADO_CREADO', 'empleados', $empleado->id, [
            'nombre' => $empleado->nombre_completo,
            'cargo_id' => $empleado->cargo_id,
        ]);

        return redirect()->route('empleados.index')
            ->with('exito', "Empleado «{$empleado->nombre_completo}» registrado.");
    }

    public function show(Empleado $empleado): View
    {
        $empleado->load(['cargo:id,nombre', 'usuario.rol:id,nombre']);

        return view('empleados.show', [
            'title' => $empleado->nombre_completo,
            'trail' => ['Personal' => route('empleados.index'), 'Empleados' => route('empleados.index')],
            'empleado' => $empleado,
        ]);
    }

    public function edit(Empleado $empleado): View
    {
        return view('empleados.form', [
            'title' => 'Editar empleado',
            'trail' => ['Personal' => route('empleados.index'), 'Empleados' => route('empleados.index')],
            'empleado' => $empleado,
            ...$this->opciones(),
        ]);
    }

    public function update(Request $request, Empleado $empleado): RedirectResponse
    {
        $datos = $this->validar($request, $empleado);

        if ($datos['estado'] !== 'ACTIVO' && Administracion::restantes(empleadoFuera: $empleado->id) === 0) {
            return back()->with('error', Administracion::MENSAJE)->withInput();
        }

        $estadoAnterior = $empleado->estado;
        $empleado->update($datos);

        Auditor::registrar('EMPLEADO_ACTUALIZADO', 'empleados', $empleado->id, [
            'nombre' => $empleado->nombre_completo,
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $empleado->estado,
        ]);

        return redirect()->route('empleados.index')
            ->with('exito', "Empleado «{$empleado->nombre_completo}» actualizado.");
    }

    /**
     * Un empleado nunca se borra: se cesa. El histórico de ventas y de caja
     * depende de él, y el trigger de la base le retira el acceso al sistema.
     */
    public function destroy(Request $request, Empleado $empleado): RedirectResponse
    {
        $datos = $request->validate([
            'fecha_cese' => ['required', 'date', 'after_or_equal:'.$empleado->fecha_ingreso->format('Y-m-d')],
            'motivo_cese' => ['required', 'string', 'max:255'],
        ], [
            'fecha_cese.after_or_equal' => 'La fecha de cese no puede ser anterior a la fecha de ingreso.',
        ], [
            'fecha_cese' => 'fecha de cese',
            'motivo_cese' => 'motivo de cese',
        ]);

        if (Administracion::restantes(empleadoFuera: $empleado->id) === 0) {
            return back()->with('error', Administracion::MENSAJE);
        }

        $empleado->update([
            'estado' => 'CESADO',
            'fecha_cese' => $datos['fecha_cese'],
            'motivo_cese' => $datos['motivo_cese'],
        ]);

        Auditor::registrar('EMPLEADO_CESADO', 'empleados', $empleado->id, $datos);

        return back()->with('exito', "Se registró el cese de «{$empleado->nombre_completo}». Su cuenta quedó sin acceso.");
    }

    /** Revierte un cese o una suspensión. */
    public function reactivar(Empleado $empleado): RedirectResponse
    {
        $empleado->update([
            'estado' => 'ACTIVO',
            'fecha_cese' => null,
            'motivo_cese' => null,
        ]);

        Auditor::registrar('EMPLEADO_REACTIVADO', 'empleados', $empleado->id);

        return back()->with('exito', "«{$empleado->nombre_completo}» vuelve a figurar como activo. Su cuenta debe reactivarse aparte.");
    }

    private function opciones(): array
    {
        return [
            'cargos' => Cargo::activos()->orderBy('nombre')->pluck('nombre', 'id'),
            'tiposDocumento' => TipoDocumento::opciones('EMPLEADO'),
            'tiposContrato' => [
                'INDEFINIDO' => 'Indefinido',
                'PLAZO_FIJO' => 'Plazo fijo',
                'PARCIAL' => 'Tiempo parcial',
                'PRACTICAS' => 'Prácticas',
            ],
            'estados' => [
                'ACTIVO' => 'Activo',
                'SUSPENDIDO' => 'Suspendido',
                'CESADO' => 'Cesado',
            ],
        ];
    }

    private function validar(Request $request, ?Empleado $empleado = null): array
    {
        $datos = $request->validate([
            'cargo_id' => ['required', Rule::exists('cargos', 'id')],
            'tipo_documento' => ['required', TipoDocumento::regla('EMPLEADO')],
            'documento' => [
                'required', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('empleados', 'documento')
                    ->where(fn ($q) => $q->where('tipo_documento', $request->input('tipo_documento')))
                    ->ignore($empleado?->id),
            ],
            'nombres' => ['required', 'string', 'max:60'],
            'apellidos' => ['required', 'string', 'max:60'],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120'],
            'direccion' => ['nullable', 'string', 'max:200'],
            'fecha_ingreso' => ['required', 'date'],
            'tipo_contrato' => ['required', Rule::in(Empleado::TIPOS_CONTRATO)],
            'estado' => ['required', Rule::in(Empleado::ESTADOS)],
            'fecha_cese' => ['nullable', 'date', 'after_or_equal:fecha_ingreso', 'required_if:estado,CESADO'],
            'motivo_cese' => ['nullable', 'string', 'max:255', 'required_if:estado,CESADO'],
        ], [
            'documento.unique' => 'Ya existe un empleado con ese tipo y número de documento.',
            'documento.regex' => 'El documento solo admite letras, números y guiones.',
            'fecha_cese.required_if' => 'Un empleado cesado necesita fecha de cese.',
            'motivo_cese.required_if' => 'Un empleado cesado necesita el motivo del cese.',
            'fecha_cese.after_or_equal' => 'La fecha de cese no puede ser anterior a la fecha de ingreso.',
        ], [
            'cargo_id' => 'cargo',
            'tipo_documento' => 'tipo de documento',
            'fecha_nacimiento' => 'fecha de nacimiento',
            'fecha_ingreso' => 'fecha de ingreso',
            'fecha_cese' => 'fecha de cese',
            'motivo_cese' => 'motivo de cese',
            'tipo_contrato' => 'tipo de contrato',
            'email' => 'correo',
        ]);

        // La base exige que solo un empleado CESADO tenga fecha de cese.
        if ($datos['estado'] !== 'CESADO') {
            $datos['fecha_cese'] = null;
            $datos['motivo_cese'] = null;
        }

        return $datos;
    }
}
