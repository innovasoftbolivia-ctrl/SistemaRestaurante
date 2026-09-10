# -*- coding: utf-8 -*-
"""
Tres formas de meter el nombre en la foto de perfil, comparadas al tamaño real.

EL PROBLEMA
WhatsApp recorta la foto en círculo y en la lista de chats la muestra a unos
48 píxeles. Un círculo es la peor forma posible para texto: el ancho útil se
achica hacia arriba y hacia abajo. «innovasoftbo» son doce letras; puestas en
una línea dentro de un círculo de 48 px, cada letra queda de 3 px.

Así que hay que elegir qué se sacrifica. Por eso esto no propone una solución:
dibuja las tres y las muestra a 400 px (cuando alguien abre el perfil), a 96
(la tarjeta de contacto) y a 48 (la lista de chats). Se decide mirando.
"""

import math
import os
from PIL import Image, ImageDraw

import logo as L
import logo_png as LP
import tipografia as T

SS = LP.SS


def _circulo(lado_px, fondo):
    img = Image.new('RGBA', (lado_px * SS, lado_px * SS), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    d.ellipse([0, 0, lado_px * SS, lado_px * SS], fill=fondo)
    return img, d


def _cabe(ancho, alto, radio):
    """¿Entra un rectángulo centrado dentro del círculo?"""
    return math.hypot(ancho / 2, alto / 2) <= radio


def _centrado(d, cy, texto_partes, tam, k, lado):
    """Dibuja las partes (texto, fuente, color) en una línea, centrada."""
    total = sum(T.medir(t, f, tam) for t, f, _ in texto_partes)
    x = (lado - total * k) / 2
    for t, f, c in texto_partes:
        LP.texto(d, x, cy, t, f, tam * k, c, 0)
        x += T.medir(t, f, tam) * k


def opcion_a(lado_px):
    """Símbolo grande arriba, el nombre completo en una línea abajo."""
    img, d = _circulo(lado_px, L.VERDE)
    W = lado_px * SS
    k = W / 100.0                      # se trabaja en una cuadrícula de 100

    sim = LP.marca_cuadrada(int(38 * k / SS) * SS, L.BLANCO, margen=0)
    img.paste(sim, (int((W - sim.width) / 2), int(24 * k)), sim)

    _centrado(d, 76 * k, [('innova', T.REGULAR, L.BLANCO),
                          ('soft', T.NEGRITA, L.BLANCO),
                          ('bo', T.REGULAR, L.SALVIA_CLARA)], 11.5, k, W)
    return img.resize((lado_px, lado_px), Image.LANCZOS)


def opcion_b(lado_px):
    """Símbolo mediano y el nombre partido en dos líneas: letras más grandes."""
    img, d = _circulo(lado_px, L.VERDE)
    W = lado_px * SS
    k = W / 100.0

    sim = LP.marca_cuadrada(int(30 * k / SS) * SS, L.BLANCO, margen=0)
    img.paste(sim, (int((W - sim.width) / 2), int(17 * k)), sim)

    _centrado(d, 66 * k, [('innova', T.REGULAR, L.BLANCO)], 17, k, W)
    _centrado(d, 82 * k, [('soft', T.NEGRITA, L.BLANCO),
                          ('bo', T.REGULAR, L.SALVIA_CLARA)], 17, k, W)
    return img.resize((lado_px, lado_px), Image.LANCZOS)


def opcion_c(lado_px):
    """Sin símbolo: el nombre ocupa todo el círculo. La más legible de lejos."""
    img, d = _circulo(lado_px, L.VERDE)
    W = lado_px * SS
    k = W / 100.0

    _centrado(d, 47 * k, [('innova', T.REGULAR, L.BLANCO)], 25, k, W)
    _centrado(d, 72 * k, [('soft', T.NEGRITA, L.BLANCO),
                          ('bo', T.REGULAR, L.SALVIA_CLARA)], 25, k, W)
    return img.resize((lado_px, lado_px), Image.LANCZOS)


OPCIONES = [
    ('A', opcion_a, 'Símbolo grande, nombre en una línea',
     'El símbolo manda. El nombre está, pero chico.'),
    ('B', opcion_b, 'Símbolo mediano, nombre en dos líneas',
     'Reparte: el nombre gana tamaño, el símbolo lo pierde.'),
    ('C', opcion_c, 'Solo el nombre, en dos líneas',
     'El nombre lo más grande posible. Se pierde el símbolo.'),
]

# 190 y no 400: para juzgar alcanza, y así las tres filas entran en una hoja.
TAMANOS = [(190, 'perfil abierto'), (96, 'tarjeta de contacto'), (48, 'lista de chats')]


def hoja():
    from PIL import ImageDraw as ID
    # La fila tiene que ser MÁS ALTA que la imagen más grande que se pega.
    # La primera versión usaba 250 con imágenes de 400 y se montaban unas sobre
    # otras: se ve enseguida, pero conviene que no vuelva a pasar.
    ANCHO, FILA = 980, 300
    grande = max(l for l, _ in TAMANOS)
    assert FILA - 100 >= grande, ('la fila mide %d y la imagen mayor %d'
                                  % (FILA - 100, grande))
    h = Image.new('RGB', (ANCHO, 90 + FILA * len(OPCIONES)), '#F6F7F9')
    d = ID.Draw(h)

    def txt(x, y, s, fuente, tam, color):
        LP.texto(d, x, y, s, fuente, tam, color, 0)

    txt(36, 40, 'El nombre en la foto de perfil: tres formas', T.NEGRITA, 21, '#1F242C')
    txt(36, 64, 'Cada una al tamaño real en que WhatsApp la muestra.', T.REGULAR, 14, '#6B7280')

    y = 96
    for letra, hacer, titulo, nota in OPCIONES:
        d.rounded_rectangle([24, y, ANCHO - 24, y + FILA - 18], radius=12,
                            fill='#FFFFFF', outline='#E3E5E8')
        txt(44, y + 34, 'OPCIÓN ' + letra, T.NEGRITA, 13, '#14503F')
        txt(120, y + 34, titulo, T.NEGRITA, 14, '#1F242C')
        txt(44, y + 56, nota, T.REGULAR, 12.5, '#6B7280')

        x = 44
        for lado, etq in TAMANOS:
            im = hacer(lado)
            arriba = y + 74 + (190 - lado) // 2
            h.paste(im, (x, arriba), im)
            txt(x, y + FILA - 34, '%d px · %s' % (lado, etq), T.REGULAR, 11, '#9AA1AB')
            x += max(lado, 140) + 54
        y += FILA
    return h


def perfil_final(lado_px):
    """
    La elegida: el nombre en dos líneas, sin símbolo.

    Se entrega CUADRADA y con el verde llenando todo, no como círculo suelto.
    WhatsApp recorta el círculo por su cuenta; pero Facebook y algunas vistas
    de WhatsApp Business muestran el cuadrado, y un círculo pegado sobre nada
    saldría con las esquinas transparentes o blancas.

    El texto va dentro del círculo inscrito, que es lo que sobrevive al
    recorte: se comprueba abajo en vez de confiar en el ojo.
    """
    W = lado_px * SS
    img = Image.new('RGB', (W, W), L.VERDE)
    d = ImageDraw.Draw(img)
    k = W / 100.0

    _centrado(d, 47 * k, [('innova', T.REGULAR, L.BLANCO)], 25, k, W)
    _centrado(d, 72 * k, [('soft', T.NEGRITA, L.BLANCO),
                          ('bo', T.REGULAR, L.SALVIA_CLARA)], 25, k, W)

    # ¿entra el bloque de texto en el círculo que va a quedar?
    ancho_txt = max(T.medir('innova', T.REGULAR, 25),
                    T.medir('soft', T.NEGRITA, 25) + T.medir('bo', T.REGULAR, 25))
    alto_txt = 72 - 47 + 25 * 0.75          # dos líneas más el ojo de la última
    centro_txt = (47 + 72) / 2 - 25 * 0.35
    lejos = math.hypot(ancho_txt / 2, max(abs(centro_txt - 50) + alto_txt / 2, 0))
    if lejos > 50:
        raise SystemExit('el texto se sale del círculo por %.1f de 100' % (lejos - 50))

    return img.resize((lado_px, lado_px), Image.LANCZOS)


if __name__ == '__main__':
    destino = L.carpeta()
    ruta = os.path.join(destino, '_perfil-opciones.png')
    hoja().save(ruta)
    print('  comparación:', ruta)

    final = perfil_final(1000)
    rf = os.path.join(destino, 'innovasoftbo-whatsapp-perfil-nombre.png')
    final.save(rf)
    print('  %-46s %d x %d' % (os.path.basename(rf), final.width, final.height))
