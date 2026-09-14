<?php

namespace App\Services\Qr;

use App\Models\CobroQr;
use App\Services\CobrosQr;

/**
 * Lo que el sistema necesita de un banco para cobrar por QR.
 *
 * Cada banco expone su propia API —distintas rutas, distintos nombres, distinta
 * autenticación— pero todos hacen las mismas tres cosas: generar un QR por un
 * importe, decir si ya se pagó, y (algunos) avisar por su cuenta cuando ocurre.
 * Esta interfaz es ese mínimo común.
 *
 * El resto del sistema —el mostrador, el servicio de cobros, las pruebas— habla
 * solo con esta interfaz. Cambiar de banco, o contratar el primero, es escribir
 * una clase nueva y cambiar una línea del `.env`: no se toca el punto de venta.
 *
 * @see QrSimulado  el adaptador de mentira, para desarrollar y demostrar
 * @see QrBanco     la plantilla a completar con la API real
 */
interface PasarelaQr
{
    /** Cómo se identifica esta pasarela en `cobros_qr.pasarela`. */
    public function codigo(): string;

    /**
     * Pide al banco un QR por un importe.
     *
     * Devuelve el cobro ya guardado, con el `payload` que hay que pintar y el
     * `id_externo` con el que después se consulta.
     */
    public function generar(CobroQr $cobro): CobroQr;

    /**
     * Pregunta al banco en qué quedó el cobro.
     *
     * Devuelve uno de los estados de `CobroQr::ESTADOS`. No escribe nada: de
     * guardar el resultado se encarga {@see CobrosQr}, que es
     * quien sabe qué hacer con cada estado.
     */
    public function consultar(CobroQr $cobro): string;

    /**
     * Anula en el banco un QR que no se va a usar, para que nadie lo pague
     * cuando ya no hay venta esperándolo.
     */
    public function anular(CobroQr $cobro): void;

    /**
     * La referencia bancaria del pago que encontró la última consulta, si la
     * pasarela la informa: queda en el cobro para conciliar con el extracto.
     */
    public function referenciaDelPago(): ?string;

    /**
     * ¿Este aviso viene de verdad del banco?
     *
     * Un webhook es una dirección pública: cualquiera puede llamarla diciendo
     * «este cobro ya está pagado». Sin comprobar la firma, regalar mercadería
     * es cuestión de mandar un POST.
     *
     * @param  array<string, mixed>  $datos  cuerpo del aviso
     * @param  array<string, string>  $cabeceras  encabezados de la petición
     */
    public function verificarAviso(array $datos, array $cabeceras): bool;

    /**
     * Saca del aviso el identificador del cobro al que se refiere.
     *
     * @param  array<string, mixed>  $datos
     */
    public function idExternoDelAviso(array $datos): ?string;
}
