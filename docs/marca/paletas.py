# -*- coding: utf-8 -*-
"""
El mismo logo en cinco paletas, para elegir mirando y no imaginando.

El azul de la primera versión (#1D4ED8) es el que trae Tailwind por defecto.
Se eligió para que la marca combinara con la interfaz del Sistema de Ventas,
y ese fue el error: un color de producto no sirve como color de empresa. La
empresa va a vender varios sistemas; no puede vestirse del color de uno.

Cada paleta se muestra tres veces: grande, chica y en una sola tinta. Un color
puede verse muy bien a 300 px y desarmarse en una tarjeta personal.
"""

import io
import os
import logo
import tipografia as T

M = logo.carpeta()

# nombre, símbolo, texto, acento («bo»), bajada, comentario
PALETAS = [
    ('La primera versión — azul Tailwind (descartada)',
     '#1D4ED8', '#111827', '#1D4ED8', '#6B7280',
     'Brillante y muy visto. Se descartó por eso.'),

    ('Azul marino y acero',
     '#16355F', '#16355F', '#5B7DA6', '#7B8794',
     'El azul de las consultoras y los estudios. Sobrio sin ser apagado.'),

    ('Grafito y ámbar',
     '#1F2328', '#1F2328', '#B4762E', '#7B8794',
     'Casi negro con un acento cálido. Moderno y seguro de sí mismo.'),

    ('Verde profundo — LA ELEGIDA',
     '#14503F', '#14503F', '#4E8C74', '#7B8794',
     'Serio pero poco usado en software: se reconoce entre otros folletos.'),

    ('Marino y ladrillo',
     '#16355F', '#16355F', '#A63D2F', '#7B8794',
     'Marino con acento cálido. Es la combinación de las firmas establecidas.'),
]


def titulo(texto, x, y, tam=13, color='#1F242C', negrita=True):
    d, _ = T.contornos(texto, T.NEGRITA if negrita else T.REGULAR, tam, x=x, y=y, tracking=0.2)
    return '<path d="%s" fill="%s"/>' % (d, color)


def muestra(x, y, color, lado=26):
    return ('<rect x="%.1f" y="%.1f" width="%d" height="%d" rx="6" fill="%s"/>'
            % (x, y, lado, lado, color))


ANCHO = 980
FILA = 168
piezas = ['<rect width="%d" height="%d" fill="#F6F7F9"/>' % (ANCHO, 120 + FILA * len(PALETAS))]
piezas.append(titulo('Innovasoftbo — cinco paletas sobre el mismo dibujo', 40, 46, tam=15))
piezas.append(titulo('Grande, chico y en una tinta. Un color puede lucir a 300 px y desarmarse '
                     'en una tarjeta.', 40, 68, tam=10.5, color='#6B7280', negrita=False))

y = 104
for nombre, c_sim, c_txt, c_bo, c_baj, comentario in PALETAS:
    piezas.append('<rect x="24" y="%d" width="%d" height="%d" rx="12" fill="#fff" '
                  'stroke="#E3E5E8"/>' % (y, ANCHO - 48, FILA - 20))

    piezas.append(titulo(nombre, 44, y + 28, tam=12))
    piezas.append(titulo(comentario, 44, y + 46, tam=9.5, color='#6B7280', negrita=False))

    # las muestras de color
    piezas.append(muestra(44, y + 58, c_sim))
    piezas.append(muestra(76, y + 58, c_bo))
    piezas.append(muestra(108, y + 58, c_baj))

    # el logo grande
    svg = logo.horizontal(c_sim, c_txt, c_bo, c_baj)
    d = svg.split('>', 2)[2].rsplit('</svg>', 1)[0]
    import re
    vb = re.search(r'viewBox="0 0 ([\d.]+) ([\d.]+)"', svg)
    w0, h0 = float(vb.group(1)), float(vb.group(2))
    d = re.sub(r'<title>.*?</title>', '', d, flags=re.S)

    k = 52.0 / h0
    piezas.append('<g transform="translate(210 %.1f) scale(%.4f)">%s</g>' % (y + 44, k, d))

    # el mismo, chico
    k2 = 150.0 / w0
    piezas.append('<g transform="translate(%d %.1f) scale(%.4f)">%s</g>'
                  % (210 + int(w0 * k) + 60, y + 52, k2, d))

    # y en una tinta, para la fotocopia
    svg_t = logo.horizontal('#000000', '#000000', '#000000', '#000000')
    d_t = re.sub(r'<title>.*?</title>', '',
                 svg_t.split('>', 2)[2].rsplit('</svg>', 1)[0], flags=re.S)
    piezas.append('<g transform="translate(%d %.1f) scale(%.4f)">%s</g>'
                  % (210 + int(w0 * k) + 60 + 175, y + 52, k2, d_t))

    y += FILA

piezas.append(titulo('a una tinta el dibujo es el mismo en las cinco: lo que cambia es el color',
                     210 + 300, y - 6, tam=9, color='#9AA1AB', negrita=False))

svg = ('<?xml version="1.0" encoding="UTF-8"?>\n'
       '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" '
       'role="img" aria-label="Cinco paletas para la marca Innovasoftbo">\n%s\n</svg>\n'
       % (ANCHO, 120 + FILA * len(PALETAS), ANCHO, 120 + FILA * len(PALETAS), '\n'.join(piezas)))

destino = os.path.join(M, 'paletas.svg')
io.open(destino, 'w', encoding='utf-8', newline='\n').write(svg)
print('paletas:', destino, '(%.0f KB)' % (len(svg) / 1024))
