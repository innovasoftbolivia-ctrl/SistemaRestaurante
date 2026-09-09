# -*- coding: utf-8 -*-
"""
Convierte texto en contornos SVG.

Por qué no se deja el texto como <text>: un logo con <text> se dibuja con la
tipografía de la máquina que lo abre. En la tuya se ve bien; en la de la
imprenta, con otra tipografía, sale distinto y nadie se entera hasta que están
impresos los mil folletos. Convertido a curvas es un dibujo: se ve igual en
todos lados, para siempre.

La tipografía es DejaVu Sans, que viene con dompdf en el propio proyecto. Su
licencia (Bitstream Vera, y los cambios de DejaVu en dominio público) permite
usarla, modificarla y vender lo que se haga con ella — a diferencia de las de
Windows, atadas a productos de Microsoft.
"""

import os
import re
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.misc.transform import Transform

# Relativa al repositorio, para que funcione desde cualquier clon.
_AQUI = os.path.dirname(os.path.abspath(__file__))               # docs/marca
FUENTES = os.path.abspath(os.path.join(
    _AQUI, '..', '..', 'sistema-ventas', 'vendor', 'dompdf', 'dompdf', 'lib', 'fonts'))

REGULAR = os.path.join(FUENTES, 'DejaVuSans.ttf')
NEGRITA = os.path.join(FUENTES, 'DejaVuSans-Bold.ttf')

_cache = {}


def _fuente(ruta):
    if ruta not in _cache:
        _cache[ruta] = TTFont(ruta)
    return _cache[ruta]


def medir(texto, ruta, tam, tracking=0.0):
    """Ancho que ocupará el texto, en las mismas unidades que `tam`."""
    f = _fuente(ruta)
    upm = f['head'].unitsPerEm
    hmtx = f['hmtx']
    cmap = f.getBestCmap()
    ancho = 0
    for ch in texto:
        g = cmap.get(ord(ch))
        if g is None:
            continue
        ancho += hmtx[g][0]
    return ancho * tam / upm + tracking * max(len(texto) - 1, 0)


def contornos(texto, ruta, tam, x=0.0, y=0.0, tracking=0.0):
    """
    Devuelve (path_d, ancho) con el texto ya dibujado como curvas.

    `y` es la línea de base, como en tipografía: el eje del SVG apunta hacia
    abajo y el de la fuente hacia arriba, así que la transformación invierte
    la Y. Si no se invirtiera, las letras saldrían de cabeza.
    """
    f = _fuente(ruta)
    upm = f['head'].unitsPerEm
    escala = tam / upm
    glifos = f.getGlyphSet()
    hmtx = f['hmtx']
    cmap = f.getBestCmap()

    partes = []
    avance = 0.0

    for ch in texto:
        nombre = cmap.get(ord(ch))
        if nombre is None:
            continue
        pluma = SVGPathPen(glifos)
        transformada = TransformPen(
            pluma, Transform(escala, 0, 0, -escala, x + avance, y))
        glifos[nombre].draw(transformada)
        d = pluma.getCommands()
        if d:
            partes.append(d)
        avance += hmtx[nombre][0] * escala + tracking

    return _redondear(' '.join(partes)), avance - (tracking if texto else 0)


_NUMERO = re.compile(r'-?\d+\.\d+')


def _redondear(d, decimales=2):
    """
    Recorta los decimales del contorno.

    Sin esto cada coordenada sale con ocho decimales («3.76953125») y el SVG
    del logo pesa 33 KB de puro ruido. A dos decimales, sobre un dibujo de 40
    unidades de alto, la diferencia es invisible incluso ampliado a un cartel,
    y el archivo baja a menos de la mitad.
    """
    def corta(m):
        v = round(float(m.group()), decimales)
        s = ('%.*f' % (decimales, v)).rstrip('0').rstrip('.')
        return s if s not in ('', '-0') else '0'
    return _NUMERO.sub(corta, d)
