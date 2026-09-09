# -*- coding: utf-8 -*-
"""
El folleto en A5 (148 × 210 mm), que es el que se va a repartir de verdad.

POR QUÉ A5 Y NO OTRO
  * A4 es una hoja de oficina: el dueño la deja a un lado y se pierde entre
    papeles. Además cuesta el doble.
  * A6 es para meter debajo de una puerta. Ahí la captura de pantalla se
    vuelve ilegible, y la captura es lo que convence.
  * DL entra en el bolsillo, pero mide 10 cm de ancho: una pantalla de
    computadora es apaisada y a ese ancho no se lee.
  * A5 deja la captura legible, sale dos por hoja A4 —cualquier imprenta lo
    hace— y es el tamaño que un comerciante apoya al lado de la caja.

QUÉ SE RECORTÓ RESPECTO DEL A4
Cuatro beneficios en vez de seis, y el bloque del QR pasa a una línea. El
folleto no tiene que cerrar la venta: tiene que conseguir un mensaje de
WhatsApp. Lo demás se cuenta en la demostración.
"""

import os
from PIL import Image, ImageDraw

import logo as L
import logo_png as LP
import tipografia as T
from folleto import (PPP, MM, VERDE, SALVIA, SALVIA_CLARA, CREMA, TINTA, GRIS,
                     BLANCO, MARCA, pt, ancho, escribir, parrafo,
                     caja_redondeada, pegar_captura, burbuja)

A5 = (int(148 * MM), int(210 * MM))     # 1748 × 2480
MARGEN = 12 * MM
UTIL = A5[0] - 2 * MARGEN

# Cuatro, no seis. Y la descripción en una línea: en A5 no entra más, y el
# título ya dice lo importante.
PUNTOS = [
    ('El precio no depende del cajero',
     'Pasás el código de barras y el precio sale del catálogo.'),
    ('La caja cierra cuadrada',
     'El sistema dice cuánto debería haber en el cajón al cerrar el turno.'),
    ('Sabés qué se está por acabar',
     'Aviso automático cuando un producto baja del mínimo.'),
    ('Recibo o factura, sin pensarlo',
     'El sistema elige el documento según el cliente. Ticket o carta.'),
]


def armar():
    img = Image.new('RGB', A5, BLANCO)
    d = ImageDraw.Draw(img)

    # ---------------------------------------------------- cabecera
    banda = int(37 * MM)
    d.rectangle([0, 0, A5[0], banda], fill=VERDE)
    d.rectangle([0, banda, A5[0], banda + int(1.3 * MM)], fill=SALVIA)

    marca = LP.horizontal(int(40 * MM), BLANCO, BLANCO, SALVIA_CLARA, '#B6C2C7')
    img.paste(marca, (int(MARGEN), int(6 * MM)), marca)

    escribir(d, MARGEN, 23.5 * MM, 'Tu minimarket,', T.NEGRITA, pt(20), BLANCO, -pt(0.3))
    escribir(d, MARGEN, 32 * MM, 'bajo control', T.NEGRITA, pt(20), SALVIA_CLARA, -pt(0.3))

    # ---------------------------------------------------- bajada
    y = banda + int(7.5 * MM)
    y = parrafo(d, MARGEN, y,
                'Punto de venta, caja por turno, almacén y comprobantes. En la computadora '
                'que ya tenés.',
                T.REGULAR, pt(9.6), GRIS, UTIL, interlinea=1.45)

    # ---------------------------------------------------- la captura
    y += int(5 * MM)
    ancho_cap = 116 * MM                  # más angosta que el margen: gana alto
    alto_cap = pegar_captura(img, os.path.join(MARCA, 'captura-pos-a5.png'),
                             (A5[0] - ancho_cap) / 2, y, ancho_cap, 2.5 * MM)
    y += alto_cap + int(4.5 * MM)
    pie = 'La pantalla de cobro, tal como la ve el cajero.'
    escribir(d, (A5[0] - ancho(pie, T.REGULAR, pt(7), pt(0.2))) / 2, y, pie,
             T.REGULAR, pt(7), '#9AA8A3', pt(0.2))

    # ---------------------------------------------------- los cuatro puntos
    y += int(7 * MM)
    for titulo, texto in PUNTOS:
        d.rounded_rectangle([MARGEN, y - pt(7.6), MARGEN + pt(6.2), y - pt(1.4)],
                            radius=pt(1.6), fill=SALVIA)
        yt = parrafo(d, MARGEN + pt(11), y, titulo, T.NEGRITA, pt(10), TINTA,
                     UTIL - pt(11), interlinea=1.3)
        yd = parrafo(d, MARGEN + pt(11), yt + pt(12.5), texto, T.REGULAR, pt(8.6),
                     GRIS, UTIL - pt(11), interlinea=1.4)
        y = yd + pt(13)

    # ---------------------------------------------------- el QR, en una línea
    y += int(3 * MM)
    alto_caja = int(14.5 * MM)
    caja_redondeada(d, [MARGEN, y, MARGEN + UTIL, y + alto_caja], 2.2 * MM, relleno=CREMA)
    sim = LP.marca_cuadrada(int(8 * MM), VERDE, compacto=True)
    img.paste(sim, (int(MARGEN + 4 * MM), int(y + 3.2 * MM)), sim)
    escribir(d, MARGEN + 15 * MM, y + 6.4 * MM, 'COBRO POR QR CON EL MONTO YA PUESTO',
             T.NEGRITA, pt(8.4), VERDE, pt(0.5))
    escribir(d, MARGEN + 15 * MM, y + 11 * MM,
             'El cliente escanea y paga lo que debe. No teclea el importe.',
             T.REGULAR, pt(8.2), GRIS)
    y += alto_caja

    # ---------------------------------------------------- llamado a la acción
    alto_cta = int(31 * MM)
    y_cta = A5[1] - int(11 * MM) - alto_cta

    # La versión A4 se desbordó dos veces antes de que existiera este control:
    # el contenido bajaba y el bloque del WhatsApp, anclado abajo, lo tapaba.
    # No se ve hasta abrir el archivo, así que se corta acá.
    if y > y_cta - 4 * MM:
        raise SystemExit(
            'el contenido llega a %.0f mm y el llamado empieza en %.0f mm: se pisan.'
            % (y / MM, y_cta / MM))

    caja_redondeada(d, [MARGEN, y_cta, MARGEN + UTIL, y_cta + alto_cta],
                    2.2 * MM, relleno=VERDE)

    # Apilado y no en dos columnas: en A5 la caja mide 124 mm y el número, al
    # tamaño que tiene que tener para leerse de lejos, no entra al lado del
    # texto. En la primera versión se salía de la caja y pisaba la frase.
    escribir(d, MARGEN + 6 * MM, y_cta + 8 * MM, '¿Querés verlo funcionando?',
             T.NEGRITA, pt(11.5), BLANCO, -pt(0.2))
    escribir(d, MARGEN + 6 * MM, y_cta + 13 * MM,
             'Escribime al WhatsApp y coordinamos una demostración.',
             T.REGULAR, pt(8.2), '#C9DCD4')

    burbuja(d, MARGEN + 6 * MM, y_cta + 17.5 * MM, 7 * MM, SALVIA_CLARA)
    escribir(d, MARGEN + 16 * MM, y_cta + 24.6 * MM, '63490075',
             T.NEGRITA, pt(21), BLANCO, pt(0.4))
    escribir(d, MARGEN + 62 * MM, y_cta + 24.6 * MM, 'WhatsApp',
             T.REGULAR, pt(8.4), SALVIA_CLARA, pt(1.1))

    pie2 = 'innovasoftbo · Sistemas para tu negocio · Santa Cruz de la Sierra'
    escribir(d, (A5[0] - ancho(pie2, T.REGULAR, pt(7), pt(0.25))) / 2,
             A5[1] - int(5.5 * MM), pie2, T.REGULAR, pt(7), '#9AA8A3', pt(0.25))
    return img


def dos_por_hoja(a5):
    """
    Las dos A5 sobre una A4, que es como lo va a imprimir la imprenta.

    Con marcas de corte finas al borde: sin ellas, quien corta lo hace a ojo y
    los dos folletos salen desparejos.
    """
    A4 = (int(210 * MM), int(297 * MM))
    hoja = Image.new('RGB', A4, BLANCO)
    hoja.paste(a5, (0, 0))
    hoja.paste(a5, (0, int(148.5 * MM)))
    d = ImageDraw.Draw(hoja)
    corte = int(148.5 * MM)
    for x0, x1 in ((0, int(5 * MM)), (A4[0] - int(5 * MM), A4[0])):
        d.line([x0, corte, x1, corte], fill='#B9C2BE', width=2)
    return hoja


if __name__ == '__main__':
    destino = L.carpeta()
    a5 = armar()

    png = os.path.join(destino, 'Folleto-Sistema-de-Ventas-A5.png')
    pdf = os.path.join(destino, 'Folleto-Sistema-de-Ventas-A5.pdf')
    a5.save(png, dpi=(PPP, PPP))
    a5.save(pdf, 'PDF', resolution=PPP)
    print('  %-46s %d x %d px, %d ppp' % (os.path.basename(png), a5.width, a5.height, PPP))
    print('  %s' % os.path.basename(pdf))

    hoja = dos_por_hoja(a5)
    pdf2 = os.path.join(destino, 'Folleto-Sistema-de-Ventas-A5-dos-por-hoja.pdf')
    hoja.save(pdf2, 'PDF', resolution=PPP)
    print('  %-46s para la imprenta: dos A5 en una A4' % os.path.basename(pdf2))

    print('\nen %s' % destino)
