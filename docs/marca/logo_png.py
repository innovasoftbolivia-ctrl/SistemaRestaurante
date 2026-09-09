# -*- coding: utf-8 -*-
"""
Exporta el logo a PNG.

El SVG es el original y manda; esto son copias para donde no entra un SVG:
Word, WhatsApp, el favicon del sistema, una imprenta que pide mapa de bits.

Se dibuja a cuatro veces el tamaño y se reduce al final. Es la diferencia
entre un borde escalonado y uno limpio: PIL no suaviza al dibujar, así que el
suavizado se consigue reduciendo.
"""

import math
import os
from PIL import Image, ImageDraw, ImageFont

import logo as L
import tipografia as T

SS = 4          # cuántas veces más grande se dibuja antes de reducir


# ------------------------------------------------------------------ dibujo
def _rr(d, caja, r, color):
    d.rounded_rectangle(caja, radius=r, fill=color)


def _perimetro(x, y, lado, r):
    """Puntos a lo largo del contorno de un cuadrado redondeado."""
    recta = lado - 2 * r
    arco = math.pi * r / 2
    total = 4 * recta + 4 * arco
    pasos = max(int(total), 60)
    pts = []
    for i in range(pasos):
        t = total * i / pasos
        # arriba → derecha → abajo → izquierda, empezando tras la esquina
        for tramo, largo in (('r1', recta), ('a1', arco), ('r2', recta), ('a2', arco),
                             ('r3', recta), ('a3', arco), ('r4', recta), ('a4', arco)):
            if t <= largo:
                if tramo == 'r1':
                    pts.append((x + r + t, y))
                elif tramo == 'a1':
                    ang = -math.pi / 2 + (t / arco) * (math.pi / 2)
                    pts.append((x + lado - r + r * math.cos(ang), y + r + r * math.sin(ang)))
                elif tramo == 'r2':
                    pts.append((x + lado, y + r + t))
                elif tramo == 'a2':
                    ang = (t / arco) * (math.pi / 2)
                    pts.append((x + lado - r + r * math.cos(ang), y + lado - r + r * math.sin(ang)))
                elif tramo == 'r3':
                    pts.append((x + lado - r - t, y + lado))
                elif tramo == 'a3':
                    ang = math.pi / 2 + (t / arco) * (math.pi / 2)
                    pts.append((x + r + r * math.cos(ang), y + lado - r + r * math.sin(ang)))
                elif tramo == 'r4':
                    pts.append((x, y + lado - r - t))
                else:
                    ang = math.pi + (t / arco) * (math.pi / 2)
                    pts.append((x + r + r * math.cos(ang), y + r + r * math.sin(ang)))
                break
            t -= largo
    return pts, total


def _punteado(d, x, y, lado, r, grosor, color, on, off):
    """
    El contorno punteado de la cuarta pieza.

    PIL no sabe dibujar líneas punteadas, así que se recorre el contorno y se
    ponen puntos gordos donde toca. Con el sobremuestreo de 4×, la sucesión de
    puntos se ve como un trazo continuo.
    """
    pts, total = _perimetro(x, y, lado, r)
    ciclo = on + off
    for i, (px, py) in enumerate(pts):
        t = total * i / len(pts)
        if (t % ciclo) < on:
            rr = grosor / 2
            d.ellipse([px - rr, py - rr, px + rr, py + rr], fill=color)


def simbolo(d, x, y, escala, color, compacto=False):
    p = L.PIEZA * escala
    h = L.HUECO * escala
    r = L.RADIO * escala
    t = L.TRAZO * escala
    for cx, cy in ((0, 0), (1, 0), (0, 1)):
        ax, ay = x + cx * (p + h), y + cy * (p + h)
        _rr(d, [ax, ay, ax + p, ay + p], r, color)
    vx, vy = x + p + h, y + p + h
    if compacto:
        _rr(d, [vx, vy, vx + p, vy + p], r, color)
    else:
        _punteado(d, vx + t / 2, vy + t / 2, p - t, max(r - t / 2, 1), t, color,
                  3.4 * escala, 3.6 * escala)


def _fuente(ruta, tam):
    return ImageFont.truetype(ruta, int(round(tam)))


def texto(d, x, base, cadena, ruta, tam, color, tracking=0.0):
    """Dibuja letra por letra, para poder apretar o abrir el espaciado."""
    f = _fuente(ruta, tam)
    for ch in cadena:
        d.text((x, base), ch, font=f, fill=color, anchor='ls')
        x += f.getlength(ch) + tracking
    return x


# ------------------------------------------------------------------ armado
def horizontal(ancho_px, c_sim, c_txt, c_bo, c_baj, compacto=False, fondo=None):
    """Mismo trazado que la versión SVG, en píxeles."""
    tam_nombre, tam_bajada, sep = 38.0, 11.5, 18.0
    x_texto = L.LADO + sep
    base_nombre, base_bajada = 30.0, 47.0

    ancho_u = x_texto + (
        T.medir('innova', T.REGULAR, tam_nombre) +
        T.medir('soft', T.NEGRITA, tam_nombre) +
        T.medir('bo', T.REGULAR, tam_nombre) - tam_nombre * 0.022 * 12) + 2
    alto_u = base_bajada + 4.0

    k = ancho_px * SS / ancho_u
    W, H = int(ancho_px * SS), int(round(alto_u * k))

    img = Image.new('RGBA', (W, H), fondo or (0, 0, 0, 0))
    d = ImageDraw.Draw(img)

    simbolo(d, 0, (alto_u - L.LADO) / 2 * k, k, c_sim, compacto=compacto)

    tr = -tam_nombre * k * 0.022
    x = x_texto * k
    x = texto(d, x, base_nombre * k, 'innova', T.REGULAR, tam_nombre * k, c_txt, tr)
    x = texto(d, x + tr, base_nombre * k, 'soft', T.NEGRITA, tam_nombre * k, c_txt, tr)
    texto(d, x + tr, base_nombre * k, 'bo', T.REGULAR, tam_nombre * k, c_bo, tr)

    if c_baj:
        texto(d, (x_texto + 1.5) * k, base_bajada * k, 'Sistemas para tu negocio',
              T.REGULAR, tam_bajada * k, c_baj, tam_bajada * k * 0.06)

    return img.resize((ancho_px, int(round(H / SS))), Image.LANCZOS)


def marca_cuadrada(lado_px, color, compacto=True, fondo=None):
    margen = 8.0
    lado_u = L.LADO + margen * 2
    k = lado_px * SS / lado_u
    W = int(lado_px * SS)
    img = Image.new('RGBA', (W, W), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    if fondo:
        _rr(d, [0, 0, W, W], W * 0.22, fondo)
    simbolo(d, margen * k, margen * k, k, color, compacto=compacto)
    return img.resize((lado_px, lado_px), Image.LANCZOS)


# ------------------------------------------------------------------ salida
SALIDAS = [
    ('innovasoftbo-horizontal-1200.png',
     lambda: horizontal(1200, L.VERDE, L.VERDE, L.SALVIA, L.GRIS),
     'Para Word, propuestas y facturas.'),
    ('innovasoftbo-horizontal-600.png',
     lambda: horizontal(600, L.VERDE, L.VERDE, L.SALVIA, L.GRIS),
     'Firma de correo y encabezados chicos.'),
    ('innovasoftbo-horizontal-compacta-300.png',
     lambda: horizontal(300, L.VERDE, L.VERDE, L.SALVIA, L.GRIS, compacto=True),
     'Tarjeta personal y cualquier uso por debajo de 150 px.'),
    ('innovasoftbo-negativo-1200.png',
     lambda: horizontal(1200, L.BLANCO, L.BLANCO, L.SALVIA_CLARA, '#B6C2C7'),
     'Sobre fondo oscuro o sobre una foto.'),
    ('innovasoftbo-una-tinta-1200.png',
     lambda: horizontal(1200, L.NEGRO, L.NEGRO, L.NEGRO, L.NEGRO),
     'Original para fotocopias y sellos.'),
    ('innovasoftbo-avatar-512.png',
     lambda: marca_cuadrada(512, L.BLANCO, fondo=L.VERDE),
     'Foto de perfil de WhatsApp, Facebook e Instagram.'),
    ('innovasoftbo-avatar-256.png',
     lambda: marca_cuadrada(256, L.BLANCO, fondo=L.VERDE), 'La misma, más chica.'),
    ('innovasoftbo-favicon-64.png',
     lambda: marca_cuadrada(64, L.BLANCO, fondo=L.VERDE), 'Pestaña del navegador.'),
    ('innovasoftbo-favicon-32.png',
     lambda: marca_cuadrada(32, L.BLANCO, fondo=L.VERDE), 'Pestaña del navegador, chica.'),
    ('innovasoftbo-simbolo-512.png',
     lambda: marca_cuadrada(512, L.VERDE, compacto=False),
     'El símbolo suelto, sin teja, con fondo transparente.'),
]


if __name__ == '__main__':
    destino = L.carpeta()
    for archivo, hacer, para_que in SALIDAS:
        img = hacer()
        ruta = os.path.join(destino, archivo)
        img.save(ruta)
        print('  %-42s %4dx%-4d  %s' % (archivo, img.width, img.height, para_que))
    print('\nen %s' % destino)
