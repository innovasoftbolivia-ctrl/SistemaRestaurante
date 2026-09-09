# -*- coding: utf-8 -*-
"""
Una sola hoja con todas las variantes de la marca, para mirarlas juntas.

Se arma como un SVG que embebe los otros: así se ve igual en cualquier
programa, se puede ampliar sin que se pixele, y sirve para revisar de una
mirada que las ocho piezas son la misma marca.
"""

import io
import os
import re
import logo
import tipografia as T

M = logo.carpeta()
VERDE = logo.VERDE


def cuerpo(archivo):
    """El contenido de un SVG, sin su envoltorio, y su tamaño original."""
    svg = io.open(os.path.join(M, archivo), encoding='utf-8').read()
    vb = re.search(r'viewBox="0 0 ([\d.]+) ([\d.]+)"', svg)
    ancho, alto = float(vb.group(1)), float(vb.group(2))
    dentro = svg.split('>', 2)[2].rsplit('</svg>', 1)[0]
    dentro = re.sub(r'<title>.*?</title>', '', dentro, flags=re.S)
    return dentro, ancho, alto


def pieza(archivo, x, y, alto_objetivo, etiqueta, fondo=None):
    d, w, h = cuerpo(archivo)
    k = alto_objetivo / h
    partes = []
    if fondo:
        partes.append('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="10" fill="%s"/>'
                      % (x - 16, y - 16, w * k + 32, alto_objetivo + 32, fondo))
    partes.append('<g transform="translate(%.2f %.2f) scale(%.4f)">%s</g>' % (x, y, k, d))
    # Con fondo, la etiqueta tiene que salir por debajo de la caja, no encima.
    bajo = alto_objetivo + (38 if fondo else 20)
    et, _ = T.contornos(etiqueta, T.REGULAR, 10, x=x, y=y + bajo, tracking=0.4)
    partes.append('<path d="%s" fill="#9AA1AB"/>' % et)
    return '\n'.join(partes), w * k


def titulo(texto, x, y):
    d, _ = T.contornos(texto, T.NEGRITA, 13, x=x, y=y, tracking=0.2)
    return '<path d="%s" fill="#1F242C"/>' % d


def sub(texto, x, y):
    d, _ = T.contornos(texto, T.REGULAR, 10.5, x=x, y=y, tracking=0.15)
    return '<path d="%s" fill="#6B7280"/>' % d


ANCHO = 940
piezas = ['<rect width="%d" height="1080" fill="#F6F7F9"/>' % ANCHO]
piezas.append(titulo('Innovasoftbo — juego de marca', 40, 46))
piezas.append(sub('Ocho archivos. El texto va convertido a curvas: se ve igual en cualquier '
                  'computadora e imprenta.', 40, 66))

# --- fila 1: las dos principales
piezas.append(titulo('Para usar todos los días', 40, 118))
p, w = pieza('innovasoftbo-horizontal.svg', 40, 140, 56,
             'horizontal — encabezado del folleto, facturas, correo')
piezas.append(p)
p2, w2 = pieza('innovasoftbo-vertical.svg', 40 + w + 90, 140, 118,
               'vertical — folleto y redes')
piezas.append(p2)

# --- fila 2: fondos difíciles
piezas.append(titulo('Cuando el fondo no ayuda', 40, 330))
p, w = pieza('innovasoftbo-negativo.svg', 56, 356, 56,
             'negativo — sobre oscuro o sobre foto', fondo='#0F1626')
piezas.append(p)
p2, w2 = pieza('innovasoftbo-una-tinta.svg', 56 + w + 100, 356, 56,
               'una tinta — fotocopias, sellos, diarios')
piezas.append(p2)

# --- fila 3: el símbolo solo
piezas.append(titulo('El símbolo solo', 40, 500))
p, w = pieza('innovasoftbo-simbolo.svg', 40, 524, 76, 'símbolo')
piezas.append(p)
p2, w2 = pieza('innovasoftbo-simbolo-compacto.svg', 40 + w + 190, 524, 76,
               'compacto — para 32 px o menos')
piezas.append(p2)
p3, w3 = pieza('innovasoftbo-avatar.svg', 40 + w + w2 + 400, 524, 76,
               'avatar — WhatsApp y redes')
piezas.append(p3)

# --- fila 4: la prueba que importa
piezas.append(titulo('La prueba que de verdad importa: chiquito', 40, 690))
piezas.append(sub('Si a 20 píxeles se convierte en una mancha, no sirve como foto de perfil.',
                  40, 710))
x = 40
for lado, etq in ((64, '64 px'), (48, '48 px'), (32, '32 px'), (20, '20 px')):
    p, w = pieza('innovasoftbo-avatar.svg', x, 730 + (64 - lado), lado, etq)
    piezas.append(p)
    x += lado + 46

piezas.append(sub('Y el mismo, con la cuarta pieza punteada, para comparar:', 40, 856))
x = 40
for lado, etq in ((64, '64 px'), (48, '48 px'), (32, '32 px'), (20, '20 px')):
    p, w = pieza('innovasoftbo-simbolo.svg', x, 876 + (64 - lado), lado, etq)
    piezas.append(p)
    x += lado + 46

# --- fila 5: la marca en su tamaño chico
piezas.append(titulo('La marca completa a 200 px: la normal y la compacta', 40, 1010))
d, w0, h0 = cuerpo('innovasoftbo-horizontal.svg')
k = 200.0 / w0
piezas.append('<g transform="translate(40 1024) scale(%.4f)">%s</g>' % (k, d))
d2, w2b, h2b = cuerpo('innovasoftbo-horizontal-compacta.svg')
k2 = 200.0 / w2b
piezas.append('<g transform="translate(300 1024) scale(%.4f)">%s</g>' % (k2, d2))
et, _ = T.contornos('punteada — se ensucia', T.REGULAR, 9, x=40, y=1068, tracking=0.3)
piezas.append('<path d="%s" fill="#9AA1AB"/>' % et)
et2, _ = T.contornos('compacta — se lee', T.REGULAR, 9, x=300, y=1068, tracking=0.3)
piezas.append('<path d="%s" fill="#9AA1AB"/>' % et2)

svg = ('<?xml version="1.0" encoding="UTF-8"?>\n'
       '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d 1080" width="%d" height="1080" '
       'role="img" aria-label="Hoja de marca de Innovasoftbo">\n%s\n</svg>\n'
       % (ANCHO, ANCHO, '\n'.join(piezas)))

destino = os.path.join(M, 'hoja-de-marca.svg')
io.open(destino, 'w', encoding='utf-8', newline='\n').write(svg)
print('hoja de marca:', destino, '(%.0f KB)' % (len(svg) / 1024))
