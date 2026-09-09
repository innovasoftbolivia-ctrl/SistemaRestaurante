# -*- coding: utf-8 -*-
"""
El logo de Innovasoftbo — juego completo de variantes en SVG.

EL SÍMBOLO
Cuatro piezas en cuadrícula: tres puestas y una todavía por poner. Es lo que
vende la empresa —sistemas armados por módulos— y la pieza vacía deja la frase
a mano: «y si te falta una, se agrega».

DOS DECISIONES QUE PARECEN DETALLE Y NO LO SON

  1. Ninguna pieza usa transparencia. En la maqueta las del medio estaban al
     55 %, y se veía bien en pantalla; fotocopiado eso sale gris sucio o
     directamente desaparece. Un logo que va en folletos repartidos a mano
     tiene que aguantar una fotocopiadora de barrio.

  2. El texto va convertido a curvas, no como <text>. Con <text>, el logo se
     dibuja con la tipografía de la máquina que lo abre: en la de la imprenta
     puede salir otra cosa, y nadie se entera hasta tener los folletos
     impresos.
"""

import os
import tipografia as T

# --------------------------------------------------------------- los colores
#
# Verde profundo, no el azul de la primera versión. Dos razones, y ninguna es
# de gusto:
#
#   * El azul de aquella era el que trae Tailwind por defecto —el mismo de
#     medio internet— y se había elegido para que la marca combinara con la
#     interfaz del Sistema de Ventas. Eso estaba mal de raíz: la empresa va a
#     vender varios sistemas y no puede vestirse del color de uno.
#   * En software casi nadie usa verde. Entre diez folletos, este se reconoce.
#
VERDE = '#14503F'          # el principal: símbolo y nombre
SALVIA = '#4E8C74'         # el acento: el «bo» de Bolivia
SALVIA_CLARA = '#7FC3A4'   # el mismo acento, legible sobre fondo oscuro
GRIS = '#7B8794'           # la bajada
BLANCO = '#FFFFFF'
NEGRO = '#000000'          # solo para la versión de una tinta

# --------------------------------------------------------------- el símbolo
PIEZA = 20.0        # lado de cada bloque
HUECO = 4.0         # separación entre bloques
RADIO = 5.0         # redondeo
TRAZO = 2.6         # grosor del contorno de la pieza vacía
LADO = PIEZA * 2 + HUECO    # 44: el símbolo es cuadrado


def simbolo(color, x=0.0, y=0.0, escala=1.0, compacto=False):
    """
    Las cuatro piezas.

    `compacto` llena también la cuarta: es la versión para tamaños chicos
    —favicon, foto de perfil—, donde un contorno punteado se convierte en una
    mancha.
    """
    p = PIEZA * escala
    h = HUECO * escala
    r = RADIO * escala
    t = TRAZO * escala
    partes = []

    llenas = [(0, 0), (1, 0), (0, 1)]
    for cx, cy in llenas:
        partes.append(
            '<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="%.2f" fill="%s"/>'
            % (x + cx * (p + h), y + cy * (p + h), p, p, r, color))

    vx, vy = x + (p + h), y + (p + h)
    if compacto:
        partes.append(
            '<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="%.2f" fill="%s"/>'
            % (vx, vy, p, p, r, color))
    else:
        # El contorno se dibuja hacia adentro: si se centrara en el borde, la
        # pieza vacía ocuparía más que las llenas y la cuadrícula quedaría coja.
        partes.append(
            '<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="%.2f" '
            'fill="none" stroke="%s" stroke-width="%.2f" stroke-dasharray="%.2f %.2f"/>'
            % (vx + t / 2, vy + t / 2, p - t, p - t, max(r - t / 2, 1), color, t,
               3.4 * escala, 3.6 * escala))

    return '\n  '.join(partes)


# --------------------------------------------------------------- el nombre
def nombre(x, base, tam, color_texto, color_bo, tracking=None):
    """«innova» fino + «soft» grueso + «bo» en color. Devuelve (svg, ancho)."""
    if tracking is None:
        tracking = -tam * 0.022      # DejaVu es ancha; se aprieta un poco

    d1, w1 = T.contornos('innova', T.REGULAR, tam, x=x, y=base, tracking=tracking)
    d2, w2 = T.contornos('soft', T.NEGRITA, tam, x=x + w1 + tracking, y=base, tracking=tracking)
    d3, w3 = T.contornos('bo', T.REGULAR, tam, x=x + w1 + w2 + 2 * tracking, y=base,
                         tracking=tracking)

    svg = ('<path d="%s" fill="%s"/>\n  <path d="%s" fill="%s"/>\n  <path d="%s" fill="%s"/>'
           % (d1, color_texto, d2, color_texto, d3, color_bo))
    return svg, w1 + w2 + w3 + 2 * tracking


def bajada(x, base, tam, color, texto='Sistemas para tu negocio'):
    tracking = tam * 0.06            # la bajada se abre, para que respire
    d, w = T.contornos(texto, T.REGULAR, tam, x=x, y=base, tracking=tracking)
    return '<path d="%s" fill="%s"/>' % (d, color), w


# --------------------------------------------------------------- armado
def envolver(ancho, alto, cuerpo, titulo):
    return (
        '<?xml version="1.0" encoding="UTF-8"?>\n'
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %.2f %.2f" '
        'width="%.0f" height="%.0f" role="img" aria-label="%s">\n'
        '  <title>%s</title>\n  %s\n</svg>\n'
        % (ancho, alto, ancho, alto, titulo, titulo, cuerpo))


def horizontal(color_simbolo, color_texto, color_bo, color_bajada,
               con_bajada=True, compacto=False):
    """Símbolo a la izquierda, nombre a la derecha. La versión de trabajo."""
    tam_nombre = 38.0
    tam_bajada = 11.5
    sep = 18.0                       # aire entre símbolo y texto

    x_texto = LADO + sep
    base_nombre = 30.0
    base_bajada = base_nombre + 17.0

    n_svg, n_w = nombre(x_texto, base_nombre, tam_nombre, color_texto, color_bo)

    piezas = [simbolo(color_simbolo, 0, 0, compacto=compacto), n_svg]
    ancho = x_texto + n_w

    if con_bajada:
        b_svg, b_w = bajada(x_texto + 1.5, base_bajada, tam_bajada, color_bajada)
        piezas.append(b_svg)
        ancho = max(ancho, x_texto + b_w)
        alto = base_bajada + 4.0
    else:
        alto = LADO

    # El símbolo se centra contra el alto real del bloque de texto.
    if alto > LADO:
        piezas[0] = simbolo(color_simbolo, 0, (alto - LADO) / 2, compacto=compacto)

    return envolver(ancho + 2, alto, '\n  '.join(piezas), 'Innovasoftbo')


def vertical(color_simbolo, color_texto, color_bo, color_bajada):
    """Símbolo arriba, nombre abajo. Para el folleto y las redes."""
    tam_nombre = 34.0
    tam_bajada = 11.0
    escala = 1.45                    # el símbolo manda en esta composición
    lado = LADO * escala

    n_w = T.medir('innova', T.REGULAR, tam_nombre) + \
        T.medir('soft', T.NEGRITA, tam_nombre) + \
        T.medir('bo', T.REGULAR, tam_nombre) - tam_nombre * 0.022 * 12
    b_w = T.medir('Sistemas para tu negocio', T.REGULAR, tam_bajada) + tam_bajada * 0.06 * 23

    ancho = max(lado, n_w, b_w) + 4
    cx = ancho / 2

    base_nombre = lado + 34.0
    base_bajada = base_nombre + 18.0

    n_svg, n_w2 = nombre(cx - n_w / 2, base_nombre, tam_nombre, color_texto, color_bo)
    b_svg, b_w2 = bajada(cx - b_w / 2, base_bajada, tam_bajada, color_bajada)

    cuerpo = '\n  '.join([
        simbolo(color_simbolo, cx - lado / 2, 0, escala=escala),
        n_svg, b_svg])
    return envolver(ancho, base_bajada + 4, cuerpo, 'Innovasoftbo')


def solo_simbolo(color, compacto=False, fondo=None, radio_fondo=None):
    margen = 8.0
    lado = LADO + margen * 2
    piezas = []
    if fondo:
        piezas.append('<rect x="0" y="0" width="%.2f" height="%.2f" rx="%.2f" fill="%s"/>'
                      % (lado, lado, radio_fondo or lado * 0.22, fondo))
    piezas.append(simbolo(color, margen, margen, compacto=compacto))
    return envolver(lado, lado, '\n  '.join(piezas), 'Innovasoftbo')


# --------------------------------------------------------------- salida
def carpeta():
    raiz = os.environ.get('SALIDA_LOGO')
    if not raiz:
        raiz = os.path.join('C:', os.sep, 'Universidad', 'vivecoding',
                            'SistemaVentas', 'marca')
    os.makedirs(raiz, exist_ok=True)
    return raiz


VARIANTES = [
    ('innovasoftbo-horizontal.svg',
     lambda: horizontal(VERDE, VERDE, SALVIA, GRIS),
     'La principal: encabezado del folleto, facturas, correo.'),

    ('innovasoftbo-horizontal-sin-bajada.svg',
     lambda: horizontal(VERDE, VERDE, SALVIA, None, con_bajada=False),
     'Cuando el contexto ya dice a qué se dedica.'),

    ('innovasoftbo-horizontal-compacta.svg',
     lambda: horizontal(VERDE, VERDE, SALVIA, GRIS, compacto=True),
     'La misma, con la cuarta pieza llena: por debajo de 150 px de ancho.'),

    ('innovasoftbo-vertical.svg',
     lambda: vertical(VERDE, VERDE, SALVIA, GRIS),
     'Para el folleto y las redes, donde el ancho es poco.'),

    ('innovasoftbo-negativo.svg',
     # Sobre oscuro el verde profundo desaparece: el símbolo y el nombre van
     # en blanco, y el acento sube a la salvia clara para seguir viéndose.
     lambda: horizontal(BLANCO, BLANCO, SALVIA_CLARA, '#B6C2C7'),
     'Sobre fondo oscuro o sobre una foto.'),

    ('innovasoftbo-una-tinta.svg',
     # Negro puro y no el verde de la marca: un sello de goma, una
     # fotocopiadora o un diario no reproducen matices, y el verde sale gris.
     lambda: horizontal(NEGRO, NEGRO, NEGRO, NEGRO),
     'Fotocopias, sellos, fax, diarios. Todo en negro puro.'),

    ('innovasoftbo-simbolo.svg',
     lambda: solo_simbolo(VERDE),
     'El símbolo solo, en tamaños medianos y grandes.'),

    ('innovasoftbo-simbolo-compacto.svg',
     lambda: solo_simbolo(VERDE, compacto=True),
     'Para 32 px o menos: la cuarta pieza va llena, o se ensucia.'),

    ('innovasoftbo-avatar.svg',
     lambda: solo_simbolo(BLANCO, compacto=True, fondo=VERDE),
     'Foto de perfil de WhatsApp y redes: sobre teja verde.'),
]


if __name__ == '__main__':
    destino = carpeta()
    for archivo, hacer, para_que in VARIANTES:
        ruta = os.path.join(destino, archivo)
        with open(ruta, 'w', encoding='utf-8', newline='\n') as f:
            f.write(hacer())
        print('  %-42s %s' % (archivo, para_que))
    print('\nen %s' % destino)
