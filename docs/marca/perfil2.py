# -*- coding: utf-8 -*-
"""
Otras tres formas de unir el símbolo con el nombre en la foto de perfil.

La que se descartó antes (símbolo mediano arriba, nombre en dos líneas abajo)
falla porque los dos se REPARTEN el alto del círculo y ninguno queda con
suficiente. Pero repartir no es la única forma de juntarlos:

  D — el símbolo ocupa el MISMO espacio que el nombre, detrás y en un verde
      más claro. No compite por lugar, compite por contraste, que es una
      moneda distinta.
  E — el símbolo cruzado por una banda con el nombre encima. El dibujo se lee
      como forma aunque esté partido; el texto se apoya en la banda.
  F — el símbolo reducido a una firma pequeña debajo del nombre. No pretende
      leerse a 48 px, solo estar cuando la foto se ve grande.

Como siempre: se dibujan y se miran a 190, 96 y 48 px. No se decide en el aire.
"""

import os
from PIL import Image, ImageDraw

import logo as L
import logo_png as LP
import tipografia as T
from perfil import _centrado, opcion_c, SS

VERDE_AGUA = '#1E6A53'      # el verde de la marca, un paso más claro


def _base(lado_px):
    W = lado_px * SS
    img = Image.new('RGB', (W, W), L.VERDE)
    return img, ImageDraw.Draw(img), W, W / 100.0


def opcion_d(lado_px):
    """Símbolo detrás, en verde más claro; el nombre encima, en blanco."""
    img, d, W, k = _base(lado_px)

    sim = LP.marca_cuadrada(int(72 * k / SS) * SS, VERDE_AGUA, margen=0)
    img.paste(sim, (int((W - sim.width) / 2), int((W - sim.height) / 2)), sim)

    d = ImageDraw.Draw(img)
    _centrado(d, 47 * k, [('innova', T.REGULAR, L.BLANCO)], 25, k, W)
    _centrado(d, 72 * k, [('soft', T.NEGRITA, L.BLANCO),
                          ('bo', T.REGULAR, L.SALVIA_CLARA)], 25, k, W)
    return img.resize((lado_px, lado_px), Image.LANCZOS)


def opcion_e(lado_px):
    """Símbolo grande, cruzado por una banda oscura con el nombre."""
    img, d, W, k = _base(lado_px)

    sim = LP.marca_cuadrada(int(76 * k / SS) * SS, L.BLANCO, margen=0)
    img.paste(sim, (int((W - sim.width) / 2), int(12 * k)), sim)

    d = ImageDraw.Draw(img)
    d.rectangle([0, 42 * k, W, 63 * k], fill=L.VERDE)
    _centrado(d, 57 * k, [('innova', T.REGULAR, L.BLANCO),
                          ('soft', T.NEGRITA, L.BLANCO),
                          ('bo', T.REGULAR, L.SALVIA_CLARA)], 14, k, W)
    return img.resize((lado_px, lado_px), Image.LANCZOS)


def opcion_f(lado_px):
    """El nombre manda; el símbolo, chico, firma abajo."""
    img, d, W, k = _base(lado_px)

    _centrado(d, 42 * k, [('innova', T.REGULAR, L.BLANCO)], 23, k, W)
    _centrado(d, 64 * k, [('soft', T.NEGRITA, L.BLANCO),
                          ('bo', T.REGULAR, L.SALVIA_CLARA)], 23, k, W)

    sim = LP.marca_cuadrada(int(16 * k / SS) * SS, L.SALVIA_CLARA, margen=0)
    img.paste(sim, (int((W - sim.width) / 2), int(72 * k)), sim)
    return img.resize((lado_px, lado_px), Image.LANCZOS)


OPCIONES = [
    ('C', opcion_c, 'La actual: solo el nombre',
     'Para comparar contra las nuevas.'),
    ('D', opcion_d, 'Símbolo detrás, en verde más claro',
     'No se reparten el espacio: lo comparten. El símbolo se lee por forma.'),
    ('E', opcion_e, 'Símbolo grande cruzado por la banda del nombre',
     'El dibujo se reconoce partido; el nombre se apoya en la banda.'),
    ('F', opcion_f, 'Nombre grande, símbolo como firma abajo',
     'El símbolo no pretende leerse chico: está para cuando se ve grande.'),
]

TAMANOS = [(190, 'perfil abierto'), (96, 'tarjeta de contacto'), (48, 'lista de chats')]


def hoja():
    ANCHO, FILA = 980, 300
    assert FILA - 100 >= max(l for l, _ in TAMANOS)
    h = Image.new('RGB', (ANCHO, 90 + FILA * len(OPCIONES)), '#F6F7F9')
    d = ImageDraw.Draw(h)

    def txt(x, y, s, f, tam, c):
        LP.texto(d, x, y, s, f, tam, c, 0)

    txt(36, 40, 'Unir el símbolo con el nombre: tres caminos más', T.NEGRITA, 21, '#1F242C')
    txt(36, 64, 'La columna de 48 px es la que decide.', T.REGULAR, 14, '#6B7280')

    y = 96
    for letra, hacer, titulo, nota in OPCIONES:
        d.rounded_rectangle([24, y, ANCHO - 24, y + FILA - 18], radius=12,
                            fill='#FFFFFF', outline='#E3E5E8')
        txt(44, y + 34, 'OPCIÓN ' + letra, T.NEGRITA, 13, '#14503F')
        txt(124, y + 34, titulo, T.NEGRITA, 14, '#1F242C')
        txt(44, y + 56, nota, T.REGULAR, 12.5, '#6B7280')
        x = 44
        for lado, etq in TAMANOS:
            im = hacer(lado).convert('RGBA')
            m = Image.new('L', (lado * 4, lado * 4), 0)
            ImageDraw.Draw(m).ellipse([0, 0, lado * 4, lado * 4], fill=255)
            im.putalpha(m.resize((lado, lado), Image.LANCZOS))
            h.paste(im, (x, y + 74 + (190 - lado) // 2), im)
            txt(x, y + FILA - 34, '%d px · %s' % (lado, etq), T.REGULAR, 11, '#9AA1AB')
            x += max(lado, 140) + 54
        y += FILA
    return h


def final(lado_px):
    """
    La elegida: nombre grande y el símbolo abajo, como firma.

    Se probaron tres formas de unirlos y las otras dos se cayeron en la
    columna de 48 px, que es donde WhatsApp muestra la foto casi siempre:
    la banda cruzada deja el nombre ilegible, y el símbolo de fondo ensucia
    las letras. Esta no toca el nombre: solo le agrega el símbolo debajo.

    Cuadrada y con el verde llenando todo, no como círculo suelto: WhatsApp
    recorta por su cuenta, pero Facebook muestra el cuadrado y un círculo
    pegado sobre nada saldría con las esquinas transparentes.
    """
    import math
    W = lado_px * SS
    img = Image.new('RGB', (W, W), L.VERDE)
    d = ImageDraw.Draw(img)
    k = W / 100.0

    _centrado(d, 42 * k, [('innova', T.REGULAR, L.BLANCO)], 23, k, W)
    _centrado(d, 64 * k, [('soft', T.NEGRITA, L.BLANCO),
                          ('bo', T.REGULAR, L.SALVIA_CLARA)], 23, k, W)

    sim = LP.marca_cuadrada(int(16 * k / SS) * SS, L.SALVIA_CLARA, margen=0)
    img.paste(sim, (int((W - sim.width) / 2), int(72 * k)), sim)

    # Todo tiene que entrar en el círculo inscrito, que es lo que sobrevive
    # al recorte. Se comprueba el punto más lejano, que es la esquina de
    # abajo del símbolo.
    ancho_txt = max(T.medir('innova', T.REGULAR, 23),
                    T.medir('soft', T.NEGRITA, 23) + T.medir('bo', T.REGULAR, 23))
    puntos = [(ancho_txt / 2, 42 - 50 - 23 * 0.72),   # arriba del nombre
              (ancho_txt / 2, 64 - 50),               # base de la 2ª línea
              (8, 72 + 16 - 50)]                      # esquina del símbolo
    lejos = max(math.hypot(px, py) for px, py in puntos)
    if lejos > 50:
        raise SystemExit('algo se sale del círculo por %.1f de 100' % (lejos - 50))

    return img.resize((lado_px, lado_px), Image.LANCZOS)


if __name__ == '__main__':
    destino = L.carpeta()
    ruta = os.path.join(destino, '_perfil-union.png')
    hoja().save(ruta)
    print('  comparación:', ruta)

    f = final(1000)
    rf = os.path.join(destino, 'innovasoftbo-whatsapp-perfil-nombre.png')
    f.save(rf)
    print('  %-46s %d x %d  (reemplaza a la anterior)'
          % (os.path.basename(rf), f.width, f.height))
