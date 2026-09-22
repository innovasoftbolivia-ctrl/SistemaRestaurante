<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\OrdenaTablas;
use App\Models\Empleado;
use App\Models\Rol;
use App\Models\Usuario;
use App\Services\Auditor;
use App\Support\Administracion;
use App\Support\ReglaDeClave;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UsuarioController extends Controller
{
    use OrdenaTablas;

    public function index(Request $request): View
    {
        $filtros = [
            'buscar' => $request->string('buscar')->toString(),
            'rol' => $request->integer('rol') ?: null,
            'estado' => $request->string('estado')->toString(),
        ];

        $orden = $this->orden($request, [
            'usuario' => 'usuario',
            'empleado' => Empleado::select('nombre_completo')->whereColumn('empleados.id', 'usuarios.empleado_id'),
            'rol' => Rol::select('nombre')->whereColumn('roles.id', 'usuarios.rol_id'),
            'acceso' => 'activo',
            'ingreso' => 'ultimo_acceso',
        ], 'usuario');

        $usuarios = $this->aplicarOrden(
            Usuario::with(['empleado:id,nombre_completo,estado,cargo_id', 'empleado.cargo:id,nombre', 'rol:id,nombre'])
                ->buscar($filtros['buscar'])
                ->when($filtros['rol'], fn ($q, $rol) => $q->where('rol_id', $rol))
                ->when($filtros['estado'] !== '', fn ($q) => $q->where('activo', $filtros['estado'] === 'ACTIVO')),
            $orden
        )
            ->paginate(10)
            ->withQueryString();

        return view('usuarios.index', [
            'title' => 'Usuarios del sistema',
            'trail' => ['Seguridad' => route('usuarios.index')],
            'usuarios' => $usuarios,
            'filtros' => $filtros,
            'roles' => Rol::activos()->orderBy('nombre')->pluck('nombre', 'id'),
        ]);
    }

    public function create(): View
    {
        return view('usuarios.form', [
            'title' => 'Nueva cuenta',
            'trail' => ['Seguridad' => route('usuarios.index'), 'Usuarios' => route('usuarios.index')],
            'usuario' => new Usuario(['activo' => true]),
            ...$this->opciones(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'empleado_id' => [
                'required',
                Rule::exists('empleados', 'id')->where('estado', 'ACTIVO'),
                Rule::unique('usuarios', 'empleado_id'),
            ],
            // Un rol desactivado dejaría la cuenta nueva sin permisos y sin acceso.
            'rol_id' => ['required', Rule::exists('roles', 'id')->where('activo', 1)],
            'usuario' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9._-]+$/', Rule::unique('usuarios', 'usuario')],
            'password' => ['required', 'confirmed', Password::min(8), ReglaDeClave::para($request->input('usuario'))],
            'activo' => ['boolean'],
        ], $this->mensajes(), $this->atributos());

        if (Administracion::rolPorEncima(Rol::find($datos['rol_id']))) {
            return back()->with('error', Administracion::MENSAJE_POR_ENCIMA)->withInput();
        }

        $cuenta = Usuario::create([
            'empleado_id' => $datos['empleado_id'],
            'rol_id' => $datos['rol_id'],
            'usuario' => $datos['usuario'],
            'password_hash' => Hash::make($datos['password']),
            'password_actualizado_en' => now(),
            // La puso el administrador: la persona pone la suya al entrar.
            'debe_cambiar_password' => true,
            'activo' => $datos['activo'] ?? true,
        ]);

        Auditor::registrar('USUARIO_CREADO', 'usuarios', $cuenta->id, [
            'usuario' => $cuenta->usuario,
            'rol_id' => $cuenta->rol_id,
            'empleado_id' => $cuenta->empleado_id,
        ]);

        return redirect()->route('usuarios.index')->with('exito', "Cuenta «{$cuenta->usuario}» creada.");
    }

    public function edit(Usuario $usuario): View
    {
        $usuario->load('empleado:id,nombre_completo,estado');

        return view('usuarios.form', [
            'title' => "Cuenta: {$usuario->usuario}",
            'trail' => ['Seguridad' => route('usuarios.index'), 'Usuarios' => route('usuarios.index')],
            'usuario' => $usuario,
            ...$this->opciones($usuario),
        ]);
    }

    public function update(Request $request, Usuario $usuario): RedirectResponse
    {
        $esPropia = $usuario->id === Auth::id();

        $datos = $request->validate([
            // Solo roles vigentes: con uno desactivado la cuenta se queda sin
            // permisos y sin poder entrar, en silencio.
            'rol_id' => ['required', Rule::exists('roles', 'id')->where('activo', 1)],
            'usuario' => [
                'required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9._-]+$/',
                Rule::unique('usuarios', 'usuario')->ignore($usuario->id),
            ],
            'password' => ['nullable', 'confirmed', Password::min(8), ReglaDeClave::para($request->input('usuario', $usuario->usuario))],
            // Cambiar la PROPIA contraseña exige la actual, igual que en Perfil:
            // una sesión de administrador olvidada abierta no puede quedarse con
            // la cuenta poniéndole otra clave.
            'password_actual' => [Rule::requiredIf($esPropia && filled($request->input('password'))), 'current_password'],
            'activo' => ['boolean'],
        ], $this->mensajes(), $this->atributos());

        // Ni tocar una cuenta de más rango (restablecerle la clave es quedarse
        // con ella) ni subir a nadie por encima de uno mismo.
        if (! $esPropia && (Administracion::rolPorEncima($usuario->rol) || Administracion::rolPorEncima(Rol::find($datos['rol_id'])))) {
            return back()->with('error', Administracion::MENSAJE_POR_ENCIMA)->withInput();
        }

        // Nadie puede quitarse a sí mismo el acceso ni cambiar su propio rol:
        // dejaría el sistema sin administrador por accidente.
        $cambios = [
            'rol_id' => $esPropia ? $usuario->rol_id : $datos['rol_id'],
            'usuario' => $datos['usuario'],
            'activo' => $esPropia ? true : ($datos['activo'] ?? $usuario->activo),
        ];

        if (filled($datos['password'] ?? null)) {
            $cambios['password_hash'] = Hash::make($datos['password']);
            $cambios['password_actualizado_en'] = now();
            $cambios['intentos_fallidos'] = 0;
            // Restablecida por otro: al entrar, la persona pone una propia.
            $cambios['debe_cambiar_password'] = ! $esPropia;
        }

        $aviso = $esPropia && ($datos['rol_id'] != $usuario->rol_id || ! ($datos['activo'] ?? true))
            ? ' No se cambió tu propio rol ni tu acceso: eso debe hacerlo otro administrador.'
            : '';

        $usuario->update($cambios);

        // `auth.session` guarda al final de la petición la huella de la clave
        // del usuario EN SESIÓN, que es otra instancia: sin esto se quedaba
        // con la vieja y la siguiente página echaba a quien acababa de
        // cambiar su propia contraseña.
        if ($esPropia) {
            Auth::setUser($usuario);
        }

        Auditor::registrar('USUARIO_ACTUALIZADO', 'usuarios', $usuario->id, [
            'usuario' => $usuario->usuario,
            'rol_id' => $usuario->rol_id,
            'password_cambiada' => isset($cambios['password_hash']),
        ]);

        return redirect()->route('usuarios.index')
            ->with('exito', "Cuenta «{$usuario->usuario}» actualizada.".$aviso);
    }

    /** Habilita o deshabilita el acceso sin tocar el vínculo laboral. */
    public function alternarAcceso(Usuario $usuario): RedirectResponse
    {
        if ($usuario->id === Auth::id()) {
            return back()->with('error', 'No puedes desactivar tu propia cuenta.');
        }

        if (Administracion::rolPorEncima($usuario->rol)) {
            return back()->with('error', Administracion::MENSAJE_POR_ENCIMA);
        }

        if (! $usuario->activo && $usuario->empleado?->estado !== 'ACTIVO') {
            return back()->with('error', 'No se puede activar la cuenta: el empleado no está activo en el negocio.');
        }

        $usuario->update([
            'activo' => ! $usuario->activo,
            'intentos_fallidos' => 0,
        ]);

        Auditor::registrar($usuario->activo ? 'USUARIO_ACTIVADO' : 'USUARIO_DESACTIVADO', 'usuarios', $usuario->id);

        return back()->with('exito', $usuario->activo
            ? "Cuenta «{$usuario->usuario}» activada."
            : "Cuenta «{$usuario->usuario}» desactivada.");
    }

    public function destroy(Usuario $usuario): RedirectResponse
    {
        if ($usuario->id === Auth::id()) {
            return back()->with('error', 'No puedes eliminar tu propia cuenta.');
        }

        if (Administracion::rolPorEncima($usuario->rol)) {
            return back()->with('error', Administracion::MENSAJE_POR_ENCIMA);
        }

        $nombre = $usuario->usuario;

        // La cuenta puede estar referenciada por ventas, cajas o auditoría;
        // en ese caso se desactiva en lugar de borrarse.
        try {
            $usuario->delete();
        } catch (QueryException $e) {
            // Solo «hay filas que la referencian» (1451) quiere decir que tiene
            // operaciones. Cualquier otro error —un bloqueo, la conexión— se
            // informa como error, no desactiva la cuenta en silencio.
            if ((int) ($e->errorInfo[1] ?? 0) !== 1451) {
                throw $e;
            }

            $usuario->update(['activo' => false]);

            Auditor::registrar('USUARIO_DESACTIVADO', 'usuarios', $usuario->id, ['motivo' => 'tiene operaciones asociadas']);

            return back()->with('exito', "La cuenta «{$nombre}» tiene operaciones registradas, así que se desactivó en lugar de eliminarse.");
        }

        Auditor::registrar('USUARIO_ELIMINADO', 'usuarios', null, ['usuario' => $nombre]);

        return back()->with('exito', "Cuenta «{$nombre}» eliminada.");
    }

    private function opciones(?Usuario $usuario = null): array
    {
        // Solo empleados activos y sin cuenta (más el propio, al editar).
        $empleados = Empleado::activos()
            ->where(function ($q) use ($usuario) {
                $q->whereDoesntHave('usuario');

                if ($usuario?->exists) {
                    $q->orWhere('id', $usuario->empleado_id);
                }
            })
            ->with('cargo:id,nombre')
            ->orderBy('nombre_completo')
            ->get(['id', 'nombre_completo', 'cargo_id'])
            ->mapWithKeys(fn (Empleado $e) => [$e->id => $e->nombre_completo.' — '.$e->cargo?->nombre]);

        return [
            'empleados' => $empleados,
            'roles' => Rol::activos()->orderBy('nombre')->get(['id', 'nombre', 'descripcion']),
        ];
    }

    private function mensajes(): array
    {
        return [
            'usuario.regex' => 'El usuario solo admite minúsculas, números, punto, guion y guion bajo.',
            'usuario.unique' => 'Ese nombre de usuario ya está tomado.',
            'empleado_id.unique' => 'Ese empleado ya tiene una cuenta en el sistema.',
            'empleado_id.exists' => 'El empleado no existe o no está activo.',
        ];
    }

    private function atributos(): array
    {
        return [
            'empleado_id' => 'empleado',
            'rol_id' => 'rol',
            'usuario' => 'usuario',
            'password' => 'contraseña',
            'password_actual' => 'contraseña actual',
        ];
    }
}
