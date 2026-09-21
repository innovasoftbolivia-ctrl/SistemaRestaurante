<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\Auditor;
use App\Support\Menu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Ingreso al sistema con nombre de usuario (no correo): la cuenta vive en
 * `usuarios` y la persona en `empleados`.
 */
class LoginController extends Controller
{
    /** Cuántos intentos seguidos se toleran antes de bloquear temporalmente. */
    private const MAX_INTENTOS = 5;

    private const BLOQUEO_SEGUNDOS = 60;

    /**
     * Tope por CUENTA, sin importar desde dónde: el de arriba es por cuenta y
     * dirección juntas, y cambiando de dirección se probaban contraseñas sin
     * fin contra el administrador.
     */
    private const MAX_INTENTOS_CUENTA = 10;

    private const BLOQUEO_CUENTA_SEGUNDOS = 900;

    /** La misma regla con la que `UsuarioController` crea las cuentas. */
    private const PATRON_USUARIO = '/^[a-z0-9._-]+$/';

    /**
     * Hash de una contraseña que no existe, para comparar contra ella cuando
     * el usuario no existe. Sin esto, `usuario inexistente` respondía sin
     * pasar por bcrypt mientras `contraseña incorrecta` sí (~100-300ms de
     * `Hash::check`): el mensaje de error es igual en ambos casos, pero el
     * tiempo de respuesta no lo era, y alcanza para enumerar usuarios
     * válidos midiendo la latencia de /login sin depender del mensaje.
     */
    private const HASH_DUMMY = '$2y$12$3/5X.WfE7cz0o.dEmQUjMOCyoW10W2dp0ks9/dk6ObVbvS9TnYfF6';

    public function create(): View
    {
        return view('auth.login', ['title' => 'Ingresar']);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'usuario' => ['required', 'string', 'max:40'],
            'password' => ['required', 'string'],
        ], [], [
            'usuario' => 'usuario',
            'password' => 'contraseña',
        ]);

        $this->verificarBloqueo($request);

        // Solo se busca un nombre que cumpla la regla con la que se crean las
        // cuentas. La columna compara sin distinguir acentos (`ai_ci`): sin
        // este filtro, `ádmin` o `ａｄｍｉｎ` entraban a `admin` con un contador
        // de intentos propio cada una, y el bloqueo por cuenta no frenaba nada.
        $nombre = $this->nombreNormalizado($request);
        $cuenta = preg_match(self::PATRON_USUARIO, $nombre)
            ? Usuario::with(['empleado', 'rol'])->where('usuario', $nombre)->first()
            : null;

        // Siempre se calcula un Hash::check, exista o no la cuenta —contra el
        // hash real o contra el dummy—: así ambos casos tardan lo mismo.
        // `getAuthPassword()` y no `->password`: el esquema llama a la
        // columna `password_hash` (ver el docblock del propio método).
        $claveValida = Hash::check($datos['password'], $cuenta?->getAuthPassword() ?? self::HASH_DUMMY);

        if (! $cuenta || ! $claveValida) {
            RateLimiter::hit($this->claveThrottle($request), self::BLOQUEO_SEGUNDOS);
            RateLimiter::hit($this->claveCuenta($request), self::BLOQUEO_CUENTA_SEGUNDOS);
            // Con tope: la columna es TINYINT y el intento 256 reventaba con
            // un 500 antes de llegar a la bitácora.
            if ($cuenta) {
                Usuario::whereKey($cuenta->id)->update([
                    'intentos_fallidos' => DB::raw('LEAST(intentos_fallidos + 1, 255)'),
                ]);
            }

            Auditor::registrar('LOGIN_FALLIDO', 'usuarios', $cuenta?->id, [
                'usuario' => $datos['usuario'],
            ], $cuenta?->id);

            throw ValidationException::withMessages([
                'usuario' => 'Usuario o contraseña incorrectos.',
            ]);
        }

        // La cuenta existe y la contraseña es correcta, pero el acceso puede
        // estar cerrado: cuenta desactivada o vínculo laboral no vigente.
        if (! $cuenta->puedeIngresar()) {
            RateLimiter::hit($this->claveThrottle($request), self::BLOQUEO_SEGUNDOS);

            Auditor::registrar('LOGIN_BLOQUEADO', 'usuarios', $cuenta->id, [
                'activo' => $cuenta->activo,
                'estado_empleado' => $cuenta->empleado?->estado,
            ], $cuenta->id);

            throw ValidationException::withMessages([
                'usuario' => $cuenta->activo
                    ? 'El empleado no se encuentra activo en el negocio.'
                    : 'La cuenta está desactivada. Comunícate con el administrador.',
            ]);
        }

        RateLimiter::clear($this->claveThrottle($request));
        RateLimiter::clear($this->claveCuenta($request));

        Auth::login($cuenta);
        $request->session()->regenerate();

        $cuenta->forceFill([
            'ultimo_acceso' => now(),
            'intentos_fallidos' => 0,
        ])->save();

        Auditor::registrar('LOGIN', 'usuarios', $cuenta->id);

        return redirect()->intended(Menu::inicio());
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auditor::registrar('LOGOUT', 'usuarios', Auth::id());

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /** El nombre tal como se busca y se cuenta: sin espacios y en minúsculas. */
    private function nombreNormalizado(Request $request): string
    {
        return mb_strtolower(trim((string) $request->input('usuario')));
    }

    private function claveThrottle(Request $request): string
    {
        return 'login:'.$this->nombreNormalizado($request).'|'.$request->ip();
    }

    private function claveCuenta(Request $request): string
    {
        return 'login-cuenta:'.$this->nombreNormalizado($request);
    }

    private function verificarBloqueo(Request $request): void
    {
        if (RateLimiter::tooManyAttempts($this->claveCuenta($request), self::MAX_INTENTOS_CUENTA)) {
            $minutos = (int) ceil(RateLimiter::availableIn($this->claveCuenta($request)) / 60);

            throw ValidationException::withMessages([
                'usuario' => "Demasiados intentos fallidos con esta cuenta. Queda bloqueada {$minutos} minuto(s); si no fuiste tú, avisa al administrador.",
            ]);
        }

        if (! RateLimiter::tooManyAttempts($this->claveThrottle($request), self::MAX_INTENTOS)) {
            return;
        }

        $segundos = RateLimiter::availableIn($this->claveThrottle($request));

        throw ValidationException::withMessages([
            'usuario' => "Demasiados intentos. Vuelve a intentarlo en {$segundos} segundos.",
        ]);
    }
}
