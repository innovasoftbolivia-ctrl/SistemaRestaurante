# -*- coding: utf-8 -*-
"""
El folleto del Sistema de Ventas, en A4 a 300 ppp.

PARA QUIÉN ESTÁ ESCRITO
Para el dueño de un minimarket, no para un programador. Por eso no dice
«módulo de gestión de inventario» sino «sabés qué se está por acabar». Cada
punto empieza por lo que al dueño le duele y recién después cuenta cómo se
resuelve.

QUÉ NO LLEVA, A PROPÓSITO
  * Precios. El precio va en la propuesta, después de que el cliente vio el
    sistema andando. En un papel suelto solo sirve para que lo comparen
    contra algo que no entienden.
  * La dirección de la demo. Quien quiera verla tiene que escribir al
    WhatsApp: así el contacto ocurre, en vez de que miren y se olviden.

SE DIBUJA A 300 PPP porque va a imprenta. A 150 el texto chico se ve sucio y
la captura de pantalla se empasta.
"""

import os
from PIL import Image, ImageDraw

import logo as L
import logo_png as LP
import tipografia as T

# ------------------------------------------------------------------ medidas
PPP = 300
MM = PPP / 25.4                      # píxeles por milímetro
A4 = (int(210 * MM), int(297 * MM))        # 2480 × 3508
# Carta es la hoja de oficina que se usa en Bolivia: más ancha que la A4 y
# 17,6 mm más baja. Esos 17,6 mm hay que sacarlos de algún lado, así que la
# maqueta se parametrizó en vez de copiarla.
CARTA = (int(215.9 * MM), int(279.4 * MM))  # 2550 × 3300

MARGEN = 15 * MM
ANCHO_UTIL = A4[0] - 2 * MARGEN

VERDE = L.VERDE
SALVIA = L.SALVIA
SALVIA_CLARA = '#7FC3A4'
CREMA = '#EEF5F1'                    # el verde muy lavado, para cajas
TINTA = '#16211C'
GRIS = '#5E6B66'
BLANCO = '#FFFFFF'

MARCA = L.carpeta()


def pt(x):
    """Puntos tipográficos a píxeles a 300 ppp."""
    return x * PPP / 72.0


# ------------------------------------------------------------------ texto
def ancho(cadena, ruta, tam, tracking=0.0):
    return T.medir(cadena, ruta, tam) + tracking * max(len(cadena) - 1, 0)


def escribir(d, x, base, cadena, ruta, tam, color, tracking=0.0):
    return LP.texto(d, x, base, cadena, ruta, tam, color, tracking)


def parrafo(d, x, base, cadena, ruta, tam, color, ancho_max, interlinea=1.42,
            tracking=0.0):
    """Escribe con corte de línea. Devuelve la última línea base usada."""
    palabras = cadena.split()
    linea, y = '', base
    for p in palabras:
        prueba = (linea + ' ' + p).strip()
        if ancho(prueba, ruta, tam, tracking) > ancho_max and linea:
            escribir(d, x, y, linea, ruta, tam, color, tracking)
            y += tam * interlinea
            linea = p
        else:
            linea = prueba
    if linea:
        escribir(d, x, y, linea, ruta, tam, color, tracking)
    return y


def alto_parrafo(cadena, ruta, tam, ancho_max, interlinea=1.42, tracking=0.0):
    palabras, linea, n = cadena.split(), '', 1
    for p in palabras:
        prueba = (linea + ' ' + p).strip()
        if ancho(prueba, ruta, tam, tracking) > ancho_max and linea:
            n += 1
            linea = p
        else:
            linea = prueba
    return (n - 1) * tam * interlinea


# ------------------------------------------------------------------ piezas
def caja_redondeada(d, caja, r, relleno=None, borde=None, grosor=2):
    d.rounded_rectangle(caja, radius=r, fill=relleno, outline=borde, width=int(grosor))


def pegar_captura(lienzo, ruta, x, y, ancho_px, radio):
    """La captura, con las esquinas redondeadas y un borde fino."""
    im = Image.open(ruta).convert('RGB')
    alto_px = int(im.height * ancho_px / im.width)
    im = im.resize((int(ancho_px), alto_px), Image.LANCZOS)

    mascara = Image.new('L', im.size, 0)
    ImageDraw.Draw(mascara).rounded_rectangle([0, 0, im.size[0] - 1, im.size[1] - 1],
                                              radius=int(radio), fill=255)
    lienzo.paste(im, (int(x), int(y)), mascara)

    d = ImageDraw.Draw(lienzo)
    caja_redondeada(d, [x, y, x + ancho_px, y + alto_px], radio, borde='#D3DEDA', grosor=2)
    return alto_px


def burbuja(d, x, y, lado, color):
    """Una burbuja de chat, para señalar el WhatsApp sin usar su logotipo."""
    r = lado * 0.24
    d.rounded_rectangle([x, y, x + lado, y + lado * 0.78], radius=r, fill=color)
    d.polygon([(x + lado * 0.22, y + lado * 0.72),
               (x + lado * 0.42, y + lado * 0.72),
               (x + lado * 0.20, y + lado)], fill=color)


# ------------------------------------------------------------------ contenido
BENEFICIOS = [
    ('El precio no depende de la memoria del cajero',
     'Pasás el código de barras y el precio sale del catálogo. Nadie regala '
     'mercadería por error.'),
    ('La caja cierra cuadrada',
     'Cada cajero abre y cierra su turno. El sistema dice cuánto debería haber '
     'en el cajón.'),
    ('Sabés qué se está por acabar',
     'Aviso automático cuando un producto baja del mínimo. Se repone antes de '
     'perder la venta.'),
    ('Recibo o factura, sin pensarlo',
     'El sistema elige el documento según el cliente. Ticket de 80 mm u hoja '
     'tamaño carta.'),
    ('Cobrás como el cliente quiera pagar',
     'Efectivo, tarjeta, transferencia, billetera o QR. Y una venta se puede '
     'repartir entre varias formas.'),
    ('Sabés qué vendés y quién vende',
     'Reportes por día, por producto y por cajero. Se bajan a Excel.'),
]


def armar(pagina=A4, margen_mm=15, banda_mm=54, cap_mm=150, aire_pt=17,
          cta_mm=34, pie_mm=15, tam_titular=27):
    """
    La hoja grande, en A4 o en Carta.

    Todo lo que hay que apretar para pasar de una a otra entra por parámetro:
    el alto de la banda, el ancho de la captura —que manda su alto— y el aire
    entre los beneficios. El resto se calcula solo a partir del tamaño de
    hoja, así que no hay dos maquetas que puedan separarse con el tiempo.
    """
    margen = margen_mm * MM
    util = pagina[0] - 2 * margen
    img = Image.new('RGB', pagina, BLANCO)
    d = ImageDraw.Draw(img)

    # ---------------------------------------------------- cabecera verde
    alto_banda = int(banda_mm * MM)
    d.rectangle([0, 0, pagina[0], alto_banda], fill=VERDE)
    d.rectangle([0, alto_banda, pagina[0], alto_banda + int(1.6 * MM)], fill=SALVIA)

    marca = LP.horizontal(int(52 * MM), BLANCO, BLANCO, SALVIA_CLARA, '#B6C2C7')
    img.paste(marca, (int(margen), int(8 * MM)), marca)

    escribir(d, margen, (banda_mm - 19) * MM, 'Tu minimarket,', T.NEGRITA,
             pt(tam_titular), BLANCO, -pt(0.4))
    escribir(d, margen, (banda_mm - 7.5) * MM, 'bajo control', T.NEGRITA,
             pt(tam_titular), SALVIA_CLARA, -pt(0.4))

    # ---------------------------------------------------- bajada
    y = alto_banda + int(11 * MM)
    y = parrafo(d, margen, y,
                'Punto de venta, caja por turno, almacén y comprobantes. Funciona en la '
                'computadora que ya tenés, desde el navegador: no hay que instalar nada en '
                'cada máquina.',
                T.REGULAR, pt(11.5), GRIS, util, interlinea=1.5)

    # ---------------------------------------------------- la captura
    y += int(7 * MM)
    ancho_cap = cap_mm * MM                      # más angosta que el margen: gana alto
    x_cap = (pagina[0] - ancho_cap) / 2
    alto_cap = pegar_captura(img, os.path.join(MARCA, 'captura-pos-recorte.png'),
                             x_cap, y, ancho_cap, 3.5 * MM)
    y += alto_cap + int(3.5 * MM)
    pie_cap = 'La pantalla de cobro, tal como la ve el cajero.'
    escribir(d, (pagina[0] - ancho(pie_cap, T.REGULAR, pt(8), pt(0.2))) / 2, y, pie_cap,
             T.REGULAR, pt(8), '#9AA8A3', pt(0.2))

    # ---------------------------------------------------- beneficios
    y += int(8 * MM)
    col_ancho = (util - 8 * MM) / 2
    y_col = [y, y]
    for i, (titulo, texto) in enumerate(BENEFICIOS):
        c = i % 2
        x = margen + c * (col_ancho + 8 * MM)
        yy = y_col[c]

        # el cuadradito de la marca, como viñeta
        d.rounded_rectangle([x, yy - pt(8.4), x + pt(7), yy - pt(1.4)],
                            radius=pt(1.8), fill=SALVIA)

        yt = parrafo(d, x + pt(12), yy, titulo, T.NEGRITA, pt(10.5), TINTA,
                     col_ancho - pt(12), interlinea=1.34)
        yd = parrafo(d, x + pt(12), yt + pt(14), texto, T.REGULAR, pt(9.3), GRIS,
                     col_ancho - pt(12), interlinea=1.46)
        y_col[c] = yd + pt(aire_pt)

    y = max(y_col)

    # ---------------------------------------------------- el QR, destacado
    y += int(3 * MM)
    alto_caja = int(25 * MM)
    caja_redondeada(d, [margen, y, margen + util, y + alto_caja], 3 * MM,
                    relleno=CREMA)

    sim = LP.marca_cuadrada(int(13 * MM), VERDE, compacto=True)
    img.paste(sim, (int(margen + 6 * MM), int(y + 6 * MM)), sim)

    xt = margen + 26 * MM
    at = util - 32 * MM
    escribir(d, xt, y + 9 * MM, 'COBRO POR QR CON EL MONTO YA PUESTO',
             T.NEGRITA, pt(10.5), VERDE, pt(0.7))
    parrafo(d, xt, y + 14.5 * MM,
            'El cliente escanea y paga exactamente lo que debe: no teclea el importe, '
            'así que no se equivoca. Y la venta se registra recién cuando el pago está '
            'confirmado.',
            T.REGULAR, pt(9.3), GRIS, at, interlinea=1.45)
    y += alto_caja

    # ---------------------------------------------------- qué hace falta
    y += int(6.5 * MM)
    y = parrafo(d, margen, y,
                'Necesitás una computadora con internet. Opcional: lector de código de barras '
                'e impresora de tickets. Queda instalado y funcionando en dos días.',
                T.REGULAR, pt(9.4), GRIS, util, interlinea=1.45)

    # ---------------------------------------------------- llamado a la acción
    alto_cta = int(cta_mm * MM)
    y_cta = pagina[1] - int(pie_mm * MM) - alto_cta

    # La primera versión se desbordaba: el contenido seguía bajando y el bloque
    # del WhatsApp, anclado abajo, lo tapaba. No se veía hasta mirar el PNG, así
    # que ahora falla acá en vez de salir mal impreso.
    if y > y_cta - 5 * MM:
        raise SystemExit(
            'el contenido llega hasta %.0f mm y el llamado empieza en %.0f mm: '
            'se pisan. Acortá los textos o achicá la captura.'
            % (y / MM, y_cta / MM))
    caja_redondeada(d, [margen, y_cta, margen + util, y_cta + alto_cta],
                    3 * MM, relleno=VERDE)

    escribir(d, margen + 9 * MM, y_cta + 11.5 * MM, '¿Querés verlo funcionando?',
             T.NEGRITA, pt(13), BLANCO, -pt(0.2))
    parrafo(d, margen + 9 * MM, y_cta + 18 * MM,
            'Escribime al WhatsApp y coordinamos una demostración, sin compromiso.',
            T.REGULAR, pt(9.8), '#C9DCD4', util - 78 * MM, interlinea=1.4)

    # el número, que es lo único que tiene que quedarse en la cabeza
    bx = margen + util - 62 * MM

    # El control de arriba mira el ALTO. En A5 el fallo fue de ancho: el número,
    # al tamaño que necesita para leerse de lejos, se salía de la caja y pisaba
    # la frase. Eso no se ve hasta abrir el archivo, así que se comprueba acá.
    ancho_numero = ancho('63490075', T.NEGRITA, pt(23), pt(0.4))
    derecha_texto = margen + 9 * MM + (util - 78 * MM)
    if bx + 11 * MM + ancho_numero > margen + util - 4 * MM:
        raise SystemExit('el número se sale de la caja del llamado por %.1f mm'
                         % ((bx + 11 * MM + ancho_numero - (margen + util - 4 * MM)) / MM))
    if bx < derecha_texto + 4 * MM:
        raise SystemExit('el número pisa la frase del llamado: faltan %.1f mm'
                         % ((derecha_texto + 4 * MM - bx) / MM))
    burbuja(d, bx, y_cta + 12 * MM, 8 * MM, SALVIA_CLARA)
    escribir(d, bx + 11 * MM, y_cta + 20.5 * MM, '63490075', T.NEGRITA, pt(23), BLANCO,
             pt(0.4))
    escribir(d, bx + 11 * MM, y_cta + 25.5 * MM, 'WhatsApp', T.REGULAR, pt(9),
             SALVIA_CLARA, pt(1.4))

    # ---------------------------------------------------- pie
    pie = 'innovasoftbo · Sistemas para tu negocio · Santa Cruz de la Sierra, Bolivia'
    w = ancho(pie, T.REGULAR, pt(8), pt(0.3))
    escribir(d, (pagina[0] - w) / 2, pagina[1] - int(7 * MM), pie, T.REGULAR, pt(8),
             '#9AA8A3', pt(0.3))

    return img


# =====================================================================
#  La versión para WhatsApp
# =====================================================================
#
# Es la que más se va a usar: acá los folletos se mandan por WhatsApp antes
# que repartirse en mano. Cambian tres cosas respecto del A4:
#
#   * Formato vertical 1080 × 1350, que es lo que no recorta ni WhatsApp ni
#     Instagram.
#   * Todo más grande. Se mira en un teléfono, muchas veces con el brillo
#     bajo y a un brazo de distancia.
#   * Menos texto. Nadie lee seis puntos en una imagen de chat: van cuatro,
#     y el que quiera el resto pregunta, que es justamente lo que se busca.

WS = (1080, 1350)


def armar_whatsapp():
    esc = WS[0] / (110 * MM / 25.4 * 25.4 / MM)   # se trabaja en px directos
    img = Image.new('RGB', WS, BLANCO)
    d = ImageDraw.Draw(img)

    P = lambda v: v * WS[0] / 1080.0             # por si algún día cambia el ancho
    margen = P(64)
    util = WS[0] - 2 * margen

    # cabecera
    banda = int(P(360))
    d.rectangle([0, 0, WS[0], banda], fill=VERDE)
    d.rectangle([0, banda, WS[0], banda + int(P(8))], fill=SALVIA)

    marca = LP.horizontal(int(P(330)), BLANCO, BLANCO, SALVIA_CLARA, '#B6C2C7')
    img.paste(marca, (int(margen), int(P(54))), marca)

    escribir(d, margen, P(212), 'Tu minimarket,', T.NEGRITA, P(56), BLANCO, -P(1))
    escribir(d, margen, P(280), 'bajo control', T.NEGRITA, P(56), SALVIA_CLARA, -P(1))

    y = banda + P(46)
    y = parrafo(d, margen, y,
                'Punto de venta, caja por turno, almacén y comprobantes. En la computadora '
                'que ya tenés.',
                T.REGULAR, P(26), GRIS, util, interlinea=1.45)

    # la captura
    y += P(28)
    alto_cap = pegar_captura(img, os.path.join(MARCA, 'captura-pos-recorte.png'),
                             margen, y, util, P(14))
    y += alto_cap + P(40)

    # cuatro puntos, no seis: en un chat nadie lee más
    for titulo, _ in BENEFICIOS[:4]:
        d.rounded_rectangle([margen, y - P(20), margen + P(15), y - P(5)],
                            radius=P(4), fill=SALVIA)
        y = parrafo(d, margen + P(30), y, titulo, T.NEGRITA, P(27), TINTA,
                    util - P(30), interlinea=1.3)
        y += P(38)

    # el llamado, abajo y grande
    alto_cta = int(P(215))
    y_cta = WS[1] - int(P(52)) - alto_cta
    if y > y_cta - P(20):
        raise SystemExit('la versión de WhatsApp se desborda: %.0f px sobre %.0f'
                         % (y, y_cta))
    caja_redondeada(d, [margen, y_cta, margen + util, y_cta + alto_cta], P(20), relleno=VERDE)

    escribir(d, margen + P(44), y_cta + P(60), '¿Querés verlo funcionando?',
             T.NEGRITA, P(30), BLANCO, -P(0.4))
    escribir(d, margen + P(44), y_cta + P(96), 'Escribime y coordinamos una demostración.',
             T.REGULAR, P(21), '#C9DCD4')

    burbuja(d, margen + P(44), y_cta + P(124), P(44), SALVIA_CLARA)
    escribir(d, margen + P(104), y_cta + P(170), '63490075', T.NEGRITA, P(54), BLANCO, P(1))

    pie = 'innovasoftbo · Santa Cruz de la Sierra'
    w = ancho(pie, T.REGULAR, P(19), P(0.6))
    escribir(d, (WS[0] - w) / 2, WS[1] - P(20), pie, T.REGULAR, P(19), '#9AA8A3', P(0.6))
    return img


if __name__ == '__main__':
    img = armar()
    destino = L.carpeta()
    png = os.path.join(destino, 'Folleto-Sistema-de-Ventas-A4.png')
    pdf = os.path.join(destino, 'Folleto-Sistema-de-Ventas-A4.pdf')
    img.save(png, dpi=(PPP, PPP))
    img.save(pdf, 'PDF', resolution=PPP)
    print('  %s  (%d x %d px, %d ppp)' % (os.path.basename(png), img.width, img.height, PPP))
    print('  %s' % os.path.basename(pdf))

    # Carta: 17,6 mm más baja que la A4, así que la banda, la captura y el aire
    # entre beneficios se aprietan. Los valores salieron de probar contra el
    # propio control de desborde, no a ojo.
    carta = armar(CARTA, banda_mm=48, cap_mm=148, aire_pt=13, cta_mm=32, pie_mm=12)
    png_c = os.path.join(destino, 'Folleto-Sistema-de-Ventas-Carta.png')
    pdf_c = os.path.join(destino, 'Folleto-Sistema-de-Ventas-Carta.pdf')
    carta.save(png_c, dpi=(PPP, PPP))
    carta.save(pdf_c, 'PDF', resolution=PPP)
    print('  %-46s %d x %d px  (%.0f x %.0f mm)'
          % (os.path.basename(png_c), carta.width, carta.height,
             carta.width / PPP * 25.4, carta.height / PPP * 25.4))
    print('  %s' % os.path.basename(pdf_c))

    ws = armar_whatsapp()
    ruta_ws = os.path.join(destino, 'Folleto-Sistema-de-Ventas-WhatsApp.png')
    ws.save(ruta_ws)
    print('  %s  (%d x %d px)' % (os.path.basename(ruta_ws), ws.width, ws.height))

    print('\nen %s' % destino)