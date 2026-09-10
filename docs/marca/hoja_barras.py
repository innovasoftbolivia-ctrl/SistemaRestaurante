# -*- coding: utf-8 -*-
"""
Hoja imprimible con los códigos de barras de los productos de la demo.

Para qué sirve
--------------
Para probar el mostrador con un lector de verdad sin tener los productos
delante: se imprime, se apoya sobre el mostrador y se escanea del papel.
También sirve para probar con el celular, que es lo que casi todos tienen a
mano antes de comprar la pistola.

Tres cosas que hay que respetar o el lector no lee
--------------------------------------------------
1. El ANCHO DE MÓDULO. La norma pide 0,33 mm como tamaño nominal. Acá se usan
   4 píxeles a 300 ppp = 0,339 mm. Impreso más chico, un lector barato falla.

2. La ZONA MUDA. Once módulos en blanco a la izquierda y siete a la derecha.
   Es el error más común al imprimir códigos caseros: se los pega pegados a
   un borde o a otro texto y dejan de leerse, sin que se vea nada raro.

3. Las BARRAS DE GUARDA bajan más que las demás y el primer dígito va fuera,
   a la izquierda. No es decoración: es como el lector reconoce dónde empieza.

IMPRIMIR AL 100 %, sin «ajustar a la página»: si la impresora reescala, el
ancho de módulo se va de norma y los códigos dejan de leerse.
"""

import os
from PIL import Image, ImageDraw

import ean13
import logo as L
import logo_png as LP
import tipografia as T

PPP = 300
MM = PPP / 25.4
MODULO = 4                      # píxeles por módulo: 0,339 mm a 300 ppp
ALTO_BARRA = int(22 * MM)
QUIETA_IZQ = 11 * MODULO
QUIETA_DER = 7 * MODULO

A4 = (int(210 * MM), int(297 * MM))
MARGEN = int(14 * MM)
NEGRO, GRIS, VERDE = '#000000', '#6B7280', L.VERDE


def pt(p):
    return p * PPP / 72.0


def dibujar_codigo(img, codigo, x, y):
    """
    Un EAN-13 completo, con guardas largas y el primer dígito afuera.

    Devuelve (ancho, alto) de todo lo dibujado, zona muda incluida.
    """
    d = ImageDraw.Draw(img)
    mods = ean13.modulos(codigo)

    ancho = QUIETA_IZQ + 95 * MODULO + QUIETA_DER
    alto_texto = int(pt(11))
    alto_total = ALTO_BARRA + alto_texto + int(1 * MM)

    # fondo blanco: la zona muda tiene que ser BLANCA, no transparente
    d.rectangle([x, y, x + ancho, y + alto_total], fill='#FFFFFF')

    # Las guardas (posiciones 0-2, 45-49, 92-94) bajan hasta el pie.
    guardas = set(range(0, 3)) | set(range(45, 50)) | set(range(92, 95))
    largo = ALTO_BARRA + int(3.2 * MM)

    bx = x + QUIETA_IZQ
    for i, m in enumerate(mods):
        if m == '1':
            alto = largo if i in guardas else ALTO_BARRA
            d.rectangle([bx + i * MODULO, y, bx + (i + 1) * MODULO - 1, y + alto],
                        fill=NEGRO)

    # dígitos: el primero fuera, a la izquierda; 6 y 6 bajo cada mitad
    base = y + ALTO_BARRA + int(3.2 * MM) + int(pt(8))
    LP.texto(d, x + int(0.5 * MM), base, codigo[0], T.REGULAR, pt(10), NEGRO)
    izq = bx + 3 * MODULO + int(1.2 * MM)
    LP.texto(d, izq, base, codigo[1:7], T.REGULAR, pt(10), NEGRO, MODULO * 0.42)
    der = bx + 50 * MODULO + int(1.2 * MM)
    LP.texto(d, der, base, codigo[7:], T.REGULAR, pt(10), NEGRO, MODULO * 0.42)

    return ancho, alto_total


POR_PAGINA = 8          # 4 filas x 2 columnas, con aire de sobra


def hoja(productos, pagina, total_paginas):
    """
    Una hoja con hasta ocho códigos.

    Ocho y no catorce: con catorce en una A4 las filas se pisaban —el número de
    un código caía sobre el título del siguiente— y la única forma de meterlos
    era acortar las barras. Acortarlas empeora la lectura en ángulo, que es
    justo lo que hay que probar. Mejor dos hojas.
    """
    img = Image.new('RGB', A4, '#FFFFFF')
    d = ImageDraw.Draw(img)

    marca = LP.horizontal(int(38 * MM), VERDE, VERDE, L.SALVIA, L.GRIS)
    img.paste(marca, (MARGEN, int(11 * MM)), marca)

    LP.texto(d, MARGEN, int(30 * MM), 'Códigos de barras para probar el lector',
             T.NEGRITA, pt(15), '#111827')
    LP.texto(d, MARGEN, int(35.5 * MM),
             'Imprimir al 100 %, sin «ajustar a la página». Si la impresora reescala, '
             'los códigos dejan de leerse.', T.REGULAR, pt(8.5), GRIS)

    if total_paginas > 1:
        etq = 'Hoja %d de %d' % (pagina, total_paginas)
        LP.texto(d, A4[0] - MARGEN - int(len(etq) * 1.7 * MM), int(30 * MM), etq,
                 T.REGULAR, pt(9), GRIS)

    col_ancho = (A4[0] - 2 * MARGEN) // 2
    x0, y0 = MARGEN, int(46 * MM)
    fila_alto = int(58 * MM)

    for i, (codigo, nombre, precio) in enumerate(productos):
        c, f = i % 2, i // 2
        x = x0 + c * col_ancho
        y = y0 + f * fila_alto

        LP.texto(d, x, y, nombre[:34], T.NEGRITA, pt(9), '#111827')
        LP.texto(d, x, y + int(4 * MM), 'Bs %.2f' % precio, T.REGULAR, pt(8.5), GRIS)
        dibujar_codigo(img, codigo, x, y + int(6.5 * MM))

    pie = ('Los códigos son EAN-13 válidos con prefijo 779, el de Bolivia. '
           'Un lector real los acepta igual que un producto de góndola.')
    LP.texto(d, MARGEN, A4[1] - int(12 * MM), pie, T.REGULAR, pt(8), GRIS)
    return img


if __name__ == '__main__':
    import subprocess

    sql = ("SELECT codigo_barras, nombre, precio_venta FROM productos "
           "WHERE codigo_barras IS NOT NULL ORDER BY id;")
    salida = subprocess.run(
        ['docker', 'exec', '-i', 'ventas_mysql', 'mysql', '--default-character-set=utf8mb4',
         '-uroot', '-pventas123', 'ventas_db', '-N', '-e', sql],
        capture_output=True, text=True, encoding='utf-8').stdout

    productos = []
    for linea in salida.strip().splitlines():
        p = linea.split('\t')
        if len(p) == 3:
            productos.append((p[0].strip(), p[1].strip(), float(p[2])))

    if not productos:
        raise SystemExit('no se pudieron leer los productos')

    destino = os.environ.get('SALIDA') or 'C:/Universidad/vivecoding/SistemaVentas/despliegue-demo'
    tandas = [productos[i:i + POR_PAGINA] for i in range(0, len(productos), POR_PAGINA)]
    paginas = [hoja(t, i + 1, len(tandas)) for i, t in enumerate(tandas)]

    pdf = os.path.join(destino, 'Codigos-de-barras-para-probar.pdf')
    paginas[0].save(pdf, 'PDF', resolution=PPP, save_all=True,
                    append_images=paginas[1:])
    for i, p in enumerate(paginas):
        p.save(os.path.join(destino, 'Codigos-de-barras-para-probar-%d.png' % (i + 1)),
               dpi=(PPP, PPP))
    print('  %d códigos en %d hojas · %s'
          % (len(productos), len(paginas), os.path.basename(pdf)))
    print('  ancho de módulo: %.3f mm (norma: 0,330)' % (MODULO / MM))
