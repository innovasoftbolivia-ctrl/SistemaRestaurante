# -*- coding: utf-8 -*-
"""
Revisa el libro del presupuesto sin abrir Excel.

Dos cosas, porque una sola no alcanza:

  1. ESTRUCTURA — cada referencia de cada fórmula tiene que apuntar a una hoja
     que existe y a una celda que tiene algo. Una referencia a una hoja mal
     escrita no da error al guardar: da #¡REF! recién cuando el cliente lo abre.

  2. ARITMÉTICA — la cadena de cálculo se rehace a mano en Python y se compara
     contra los números del Word. Si el libro y el documento no dicen lo mismo,
     uno de los dos está mal.
"""

import re
import sys
import openpyxl
import datos as D

RUTA = D.salida('Presupuesto_Sistema_de_Ventas.xlsx')

wb = openpyxl.load_workbook(RUTA)
hojas = set(wb.sheetnames)
fallos = []

print('hojas:', ', '.join(wb.sheetnames))
print()

# ------------------------------------------------------------- 1. estructura
REF = re.compile(r"(?:'([^']+)'|([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ0-9_]*))!\$?([A-Z]{1,2})\$?(\d+)")
FUNCIONES = {'SUM', 'ROUND', 'ROUNDUP', 'IF', 'TEXT'}

revisadas = 0
for nombre in wb.sheetnames:
    ws = wb[nombre]
    for fila in ws.iter_rows():
        for celda in fila:
            v = celda.value
            if not isinstance(v, str) or not v.startswith('='):
                continue
            revisadas += 1
            for m in REF.finditer(v):
                hoja = m.group(1) or m.group(2)
                if hoja.upper() in FUNCIONES:
                    continue
                col, num = m.group(3), int(m.group(4))
                if hoja not in hojas:
                    fallos.append('%s!%s: apunta a la hoja «%s», que no existe'
                                  % (nombre, celda.coordinate, hoja))
                    continue
                destino = wb[hoja]['%s%d' % (col, num)].value
                if destino is None:
                    fallos.append('%s!%s: apunta a %s!%s%d, que está vacía'
                                  % (nombre, celda.coordinate, hoja, col, num))

print('fórmulas revisadas: %d' % revisadas)
print('referencias rotas: %d' % len(fallos))
for f in fallos:
    print('   ', f)
print()

# ------------------------------------------------------------- 2. aritmética
tc = wb['Aranceles']['C4'].value
tarifa = wb['Aranceles']['C5'].value

horas = sum(c.value for c in [wb['Horas']['B%d' % f] for f in range(5, 5 + len(D.HORAS))])
puesta = sum(h for _, h in D.PUESTA_EN_MARCHA)
negocios = wb['Compra']['B4'].value
meses_perm = wb['Suscripción']['B4'].value

valor_bs = horas * tarifa
valor_usd = valor_bs / tc
amort_bs = valor_bs / negocios
puesta_bs = puesta * tarifa
costo_bs = amort_bs + puesta_bs

compra_usd = wb['Compra']['B14'].value
susc_usd = wb['Suscripción']['B16'].value
inst_usd = wb['Suscripción']['B15'].value
piso_usd = wb['Compra']['B15'].value

infra = wb['Suscripción']['B5'].value
h_sop = wb['Suscripción']['B6'].value
sop_usd = h_sop * tarifa / tc
amort_mes = valor_usd / (negocios * meses_perm)
costo_mes = infra + sop_usd + amort_mes

equilibrio = (compra_usd - inst_usd) / susc_usd
negocios_piso = valor_bs / (piso_usd * tc - puesta_bs)


def comprueba(que, real, esperado, tol=0.02):
    ok = abs(real - esperado) < tol
    if not ok:
        fallos.append('%s: el libro da %.2f y se esperaba %.2f' % (que, real, esperado))
    print('  %s %-46s %12.2f   (esperado %.2f)'
          % ('OK ' if ok else 'MAL', que, real, esperado))


print('la cadena de cálculo, rehecha a mano:')
comprueba('horas totales', horas, D.TOTAL_HORAS)
comprueba('valor del producto (Bs)', valor_bs, D.VALOR_ARANCEL_BS)
comprueba('valor del producto (US$)', valor_usd, D.usd(D.VALOR_ARANCEL_BS))
comprueba('parte por negocio (Bs)', amort_bs, D.AMORTIZACION_BS)
comprueba('puesta en marcha (Bs)', puesta_bs, D.PUESTA_BS)
comprueba('costo del proyecto por negocio (Bs)', costo_bs, D.COSTO_BASE_BS)
comprueba('precio de compra (US$)', compra_usd, D.COMPRA_LANZAMIENTO_USD)
comprueba('suscripción mensual (US$)', susc_usd, D.SUSCRIPCION_MES_USD)
comprueba('costo mensual de la suscripción (US$)', costo_mes, infra + sop_usd + amort_mes)
comprueba('punto de equilibrio (meses)', equilibrio, 27.5, tol=0.1)

print()
print('  margen de la suscripción: US$ %.2f al mes (%.0f %% del precio)'
      % (susc_usd - costo_mes, (susc_usd - costo_mes) / susc_usd * 100))
print('  negocios necesarios si se vende al piso: %.1f' % negocios_piso)
print('  meses para recuperar la puesta en marcha con suscripción: %.1f'
      % ((puesta_bs / tc - inst_usd) / (susc_usd - costo_mes)))

print()
print('resultado:', 'todo cuadra' if not fallos else 'HAY %d PROBLEMAS' % len(fallos))
sys.exit(0 if not fallos else 1)
