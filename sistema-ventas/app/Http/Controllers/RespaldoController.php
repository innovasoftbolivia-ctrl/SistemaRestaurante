<?php

namespace App\Http\Controllers;

use App\Services\Auditor;
use App\Services\Respaldos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Number;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Sistema > Respaldos: ver los que hay, hacer uno ahora y descargarlo.
 *
 * En Docker los hace solo el programador de tareas, todas las noches. En un
 * hosting compartido no hay quién los programe, y esta pantalla es la forma de
 * sacarlos: por eso avisa cuando el último tiene más de una semana.
 */
class RespaldoController extends Controller
{
    /** Pasado esto, la pantalla avisa que el último respaldo es viejo. */
    public const DIAS_DE_AVISO = 7;

    public function index(): View
    {
        $respaldos = Respaldos::listar();
        $ultimo = $respaldos->firstWhere('tipo', 'base');

        return view('respaldos.index', [
            'title' => 'Respaldos',
            'respaldos' => $respaldos,
            'ultimo' => $ultimo,
            'viejo' => $ultimo && $ultimo['fecha']->lt(now()->subDays(self::DIAS_DE_AVISO)),
            'dias' => Respaldos::DIAS,
            'diasAviso' => self::DIAS_DE_AVISO,
            'copiaAfuera' => Respaldos::carpetaDeCopia(),
        ]);
    }

    public function store(): RedirectResponse
    {
        try {
            $hecho = Respaldos::crear();
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('respaldos.index')
                ->with('error', 'No se pudo hacer el respaldo: '.$e->getMessage());
        }

        $nombre = basename($hecho['base']);

        Auditor::registrar('RESPALDO_CREADO', null, null, [
            'archivo' => $nombre,
            'fotos' => $hecho['fotos'] ? basename($hecho['fotos']) : null,
            'origen' => 'manual',
        ]);

        $mensaje = "Respaldo hecho: {$nombre} (".Number::fileSize((int) filesize($hecho['base'])).')'
            .($hecho['fotos'] ? ', con las fotos.' : '. No había fotos que guardar.')
            .($hecho['copia'] ? ' Copiado también a '.$hecho['copia'].'.' : '');

        if ($hecho['error_copia']) {
            return redirect()->route('respaldos.index')
                ->with('exito', $mensaje)
                ->with('error', 'El respaldo quedó en el servidor, pero no se pudo copiar afuera: '.$hecho['error_copia'].'.');
        }

        return redirect()->route('respaldos.index')->with('exito', $mensaje);
    }

    public function descargar(string $nombre): BinaryFileResponse
    {
        $ruta = Respaldos::ruta($nombre);

        abort_unless($ruta, 404);

        // Descargar la base entera —clientes, ventas, los hashes de las
        // contraseñas— es de lo más sensible que se puede hacer en el sistema.
        Auditor::registrar('RESPALDO_DESCARGADO', null, null, ['archivo' => $nombre]);

        return response()->download($ruta, $nombre);
    }
}
