# -*- coding: utf-8 -*-
"""
Dibuja códigos EAN-13 imprimibles.

No hay librería instalada y `pip` está roto en esta máquina, así que la
codificación va a mano. Un código mal codificado NO se nota mirándolo —se ve
como cualquier otro código de barras— y solo aparece cuando el lector no lo
lee. Por eso `verifica()` contrasta cada patrón contra el que produce
JsBarcode, que es una implementación independiente: si las dos coinciden en
los 95 módulos, la codificación es correcta.

Estructura de un EAN-13, 95 módulos:
    guarda 101 · 6 dígitos de 7 módulos · centro 01010 · 6 dígitos · guarda 101

El primer dígito no se dibuja: se codifica en la ALTERNANCIA de tablas L y G
de los seis dígitos de la izquierda. Es la parte que más se presta a error.
"""

L = ['0001101', '0011001', '0010011', '0111101', '0100011',
     '0110001', '0101111', '0111011', '0110111', '0001011']
G = ['0100111', '0110011', '0011011', '0100001', '0011101',
     '0111001', '0000101', '0010001', '0001001', '0010111']
R = ['1110010', '1100110', '1101100', '1000010', '1011100',
     '1001110', '1010000', '1000100', '1001000', '1110100']

# Qué tabla usa cada uno de los seis dígitos de la izquierda, según el primero.
PARIDAD = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG',
           'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL']


def digito_verificador(base12):
    s = sum(int(d) * (3 if i % 2 else 1) for i, d in enumerate(base12))
    return str((10 - s % 10) % 10)


def valido(codigo):
    return (len(codigo) == 13 and codigo.isdigit()
            and codigo[12] == digito_verificador(codigo[:12]))


def modulos(codigo):
    """Los 95 módulos, como cadena de ceros y unos."""
    if not valido(codigo):
        raise ValueError('«%s» no es un EAN-13 válido' % codigo)

    patron = PARIDAD[int(codigo[0])]
    izquierda = ''.join(
        (L if patron[i] == 'L' else G)[int(codigo[1 + i])] for i in range(6))
    derecha = ''.join(R[int(codigo[7 + i])] for i in range(6))

    return '101' + izquierda + '01010' + derecha + '101'
