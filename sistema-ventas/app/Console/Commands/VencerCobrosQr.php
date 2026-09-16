<?php

namespace App\Console\Commands;

use App\Models\CobroQr;
use App\Services\CobrosQr;
use Illuminate\Console\Command;
use Throwable;

/**
 * Cancela en el banco los cobros por QR que ya vencieron.
 *
 * El QR vive diez minutos en el sistema, pero en el banco vive lo que dure su
 * plazo —con Banco Económico, todo el día—. Si el cajero cierra la pantalla, el
 * código queda vivo: el cliente puede pagarlo horas después, el dinero entra a
 * la cuenta y no hay venta que lo respalde. Antes solo se cancelaba si alguien
 * seguía mirando esa pantalla.
 *
 * Lo dispara el programador de tareas (routes/console.php); también se puede
 * correr a mano.
 */
class VencerCobrosQr extends Command
{
    protected $signature = 'qr:vencer';

    protected $description = 'Cancela en el banco los cobros por QR vencidos y los marca como vencidos';

    public function handle(): int
    {
        $vencidos = CobroQr::where('estado', CobroQr::PENDIENTE)
            ->whereNotNull('expira_en')
            ->where('expira_en', '<', now())
            ->orderBy('id')
            ->get();

        if ($vencidos->isEmpty()) {
            $this->line('No hay cobros por QR vencidos.');

            return self::SUCCESS;
        }

        $cancelados = 0;
        $pagados = 0;

        foreach ($vencidos as $cobro) {
            try {
                // Primero se le pregunta al banco: si el cliente alcanzó a
                // pagar, el cobro queda PAGADO y a la vista del cajero, no
                // cancelado a sus espaldas.
                $cobro = CobrosQr::refrescar($cobro);

                if ($cobro->estaPagado()) {
                    $pagados++;

                    continue;
                }

                CobrosQr::vencer($cobro);
                $cancelados++;
            } catch (Throwable $e) {
                report($e);
                $this->warn("Cobro #{$cobro->id}: no se pudo cancelar ({$e->getMessage()})");
            }
        }

        $this->info("Cobros por QR vencidos: {$cancelados} cancelados en el banco.");

        if ($pagados > 0) {
            $this->warn("{$pagados} se habían pagado fuera de plazo y quedaron PAGADOS, sin venta: revísalos.");
        }

        return self::SUCCESS;
    }
}
