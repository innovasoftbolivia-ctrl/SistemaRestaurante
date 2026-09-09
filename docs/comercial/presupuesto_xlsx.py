# -*- coding: utf-8 -*-
"""
El presupuesto del Sistema de Ventas en Excel, con fórmulas vivas.

Nada está escrito a mano dos veces: se cambia el tipo de cambio, las horas o la
tarifa, y todo el libro se recalcula. Las celdas de fondo amarillo son las
editables; el resto sale de ellas.
"""

import datos as D
import openpyxl
from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
from openpyxl.utils import get_column_letter

AZUL       = 'FF1D4ED8'
AZUL_SUAVE = 'FFDBEAFE'
GRIS_SUAVE = 'FFF1F5F9'
AMARILLO   = 'FFFEF3C7'
VERDE      = 'FFDCFCE7'
BLANCO     = 'FFFFFFFF'

MONEDA_BS  = '"Bs" #,##0.00'
MONEDA_USD = '"US$" #,##0.00'
ENTERO     = '#,##0'
PORC       = '0.0%'

borde = Border(*[Side(style='thin', color='FFCBD5E1')] * 4)

wb = openpyxl.Workbook()


def anchos(ws, valores):
    for i, v in enumerate(valores, start=1):
        ws.column_dimensions[get_column_letter(i)].width = v


def titulo(ws, celda, texto, tam=13):
    ws[celda] = texto
    ws[celda].font = Font(bold=True, size=tam, color=AZUL)


def nota(ws, celda, texto, alto=None, hasta=None):
    ws[celda] = texto
    ws[celda].font = Font(size=9, italic=True, color='FF555B66')
    ws[celda].alignment = Alignment(wrap_text=True, vertical='top')
    if hasta:
        ws.merge_cells('%s:%s' % (celda, hasta))
    if alto:
        ws.row_dimensions[int(''.join(c for c in celda if c.isdigit()))].height = alto


def editable(ws, celda, valor, formato=None):
    ws[celda] = valor
    ws[celda].fill = PatternFill('solid', fgColor=AMARILLO)
    ws[celda].font = Font(bold=True)
    ws[celda].border = borde
    if formato:
        ws[celda].number_format = formato


def calc(ws, celda, formula, formato=None, negrita=False, relleno=None):
    ws[celda] = formula
    ws[celda].border = borde
    if formato:
        ws[celda].number_format = formato
    if negrita:
        ws[celda].font = Font(bold=True)
    if relleno:
        ws[celda].fill = PatternFill('solid', fgColor=relleno)


def etiqueta(ws, celda, texto, negrita=False):
    ws[celda] = texto
    ws[celda].font = Font(bold=negrita)
    ws[celda].alignment = Alignment(wrap_text=True, vertical='top')


def encabezado(ws, fila, valores, desde=1):
    for i, v in enumerate(valores, start=desde):
        c = ws.cell(row=fila, column=i, value=v)
        c.font = Font(bold=True, color=BLANCO, size=10)
        c.fill = PatternFill('solid', fgColor=AZUL)
        c.alignment = Alignment(wrap_text=True, vertical='center')
        c.border = borde
    ws.row_dimensions[fila].height = 28


# ==================================================================== ARANCELES
ws = wb.active
ws.title = 'Aranceles'
anchos(ws, [34, 14, 14, 62])

titulo(ws, 'A1', 'Arancel CITI Santa Cruz — Valor de la hora de servicio profesional')
nota(ws, 'A2', 'La misma referencia que se usó en el presupuesto de Granja Aranjuez. '
               'Verificar contra el arancel oficial del CITI antes de presentarlo a un cliente.',
     alto=26, hasta='D2')

etiqueta(ws, 'B4', 'Tipo de cambio (Bs por US$)', negrita=True)
editable(ws, 'C4', D.TC, '0.00')
nota(ws, 'D4', '← celda editable: cambia el tipo de cambio y se recalcula todo el libro')

etiqueta(ws, 'B5', 'Tarifa que se aplica (Bs/hora)', negrita=True)
editable(ws, 'C5', D.TARIFA_BS, MONEDA_BS)
nota(ws, 'D5', '← celda editable: el escalón del arancel con el que se cotiza')

encabezado(ws, 7, ['Categoría profesional', 'Bs / hora', 'US$ / hora', 'Cuándo aplica'])
for i, (nom, tarifa, det) in enumerate(D.ARANCEL):
    f = 8 + i
    etiqueta(ws, 'A%d' % f, nom, negrita=(tarifa == D.TARIFA_BS))
    calc(ws, 'B%d' % f, tarifa, MONEDA_BS)
    calc(ws, 'C%d' % f, '=B%d/$C$4' % f, MONEDA_USD)
    etiqueta(ws, 'D%d' % f, det)
    ws['A%d' % f].border = borde
    ws['D%d' % f].border = borde
    if tarifa == D.TARIFA_BS:
        for col in 'ABCD':
            ws['%s%d' % (col, f)].fill = PatternFill('solid', fgColor=AZUL_SUAVE)

nota(ws, 'A13', 'El CITI publica además una tabla de honorarios mensuales por rol (Bs 50/hora). '
                'Esa es la referencia de un empleado en planilla y NO sirve para cotizar por '
                'contrato: no cubre impuestos, herramientas, garantía ni el riesgo de entregar.',
     alto=32, hasta='D13')

# ======================================================================== HORAS
ws = wb.create_sheet('Horas')
anchos(ws, [38, 10, 76])

titulo(ws, 'A1', 'Horas del proyecto — Sistema de Ventas')
nota(ws, 'A2', 'Estimadas por bloque contra el tamaño real del repositorio: 23 controladores, '
               '27 modelos, 12 servicios, 69 vistas, 98 rutas, 275 pruebas y una base de 29 '
               'tablas con 6 procedimientos y 7 disparadores. 33.695 líneas propias.',
     alto=32, hasta='C2')

encabezado(ws, 4, ['Bloque', 'Horas', 'Qué incluye'])
fila = 5
for nom, h, det in D.HORAS:
    etiqueta(ws, 'A%d' % fila, nom)
    editable(ws, 'B%d' % fila, h, ENTERO)
    etiqueta(ws, 'C%d' % fila, det)
    ws['A%d' % fila].border = borde
    ws['C%d' % fila].border = borde
    fila += 1

f_ini, f_fin = 5, fila - 1
etiqueta(ws, 'A%d' % fila, 'TOTAL DE HORAS DEL PRODUCTO', negrita=True)
calc(ws, 'B%d' % fila, '=SUM(B%d:B%d)' % (f_ini, f_fin), ENTERO, negrita=True, relleno=AZUL_SUAVE)
ws['A%d' % fila].fill = PatternFill('solid', fgColor=AZUL_SUAVE)
FILA_TOTAL_HORAS = fila

fila += 2
titulo(ws, 'A%d' % fila, 'Puesta en marcha — lo que se hace para CADA negocio', tam=11)
fila += 1
encabezado(ws, fila, ['Tarea', 'Horas'])
fila += 1
p_ini = fila
for nom, h in D.PUESTA_EN_MARCHA:
    etiqueta(ws, 'A%d' % fila, nom)
    editable(ws, 'B%d' % fila, h, ENTERO)
    ws['A%d' % fila].border = borde
    fila += 1
etiqueta(ws, 'A%d' % fila, 'Total de la puesta en marcha', negrita=True)
calc(ws, 'B%d' % fila, '=SUM(B%d:B%d)' % (p_ini, fila - 1), ENTERO, negrita=True, relleno=AZUL_SUAVE)
ws['A%d' % fila].fill = PatternFill('solid', fgColor=AZUL_SUAVE)
FILA_PUESTA = fila

fila += 2
nota(ws, 'A%d' % fila, 'Las horas del producto ya están gastadas: se reparten entre todos los '
                       'negocios que lo usen. Las de la puesta en marcha se gastan de nuevo con '
                       'cada cliente, así que se cobran enteras cada vez.',
     alto=32, hasta='C%d' % fila)

# ======================================================================= COMPRA
ws = wb.create_sheet('Compra')
anchos(ws, [46, 26, 16, 16, 34])

titulo(ws, 'A1', 'Opción A — Compra del sistema completo')
nota(ws, 'A2', 'El negocio compra el sistema, se instala en su servidor y es suyo. Sin '
               'mensualidad obligatoria.', alto=18, hasta='E2')

etiqueta(ws, 'A4', 'Entre cuántos negocios se reparte el desarrollo', negrita=True)
editable(ws, 'B4', D.CLIENTES_AMORTIZACION, ENTERO)
nota(ws, 'C4', '← editable: cuantos más negocios, más barato para cada uno', hasta='E4')

encabezado(ws, 6, ['Concepto', 'Cálculo', 'Bs', 'US$', 'Observación'])

etiqueta(ws, 'A7', 'Valor del producto a arancel')
etiqueta(ws, 'B7', 'horas × tarifa')
calc(ws, 'C7', '=Horas!B%d*Aranceles!C5' % FILA_TOTAL_HORAS, MONEDA_BS)
calc(ws, 'D7', '=C7/Aranceles!$C$4', MONEDA_USD)
etiqueta(ws, 'E7', 'Lo que costaría mandarlo a construir desde cero.')

etiqueta(ws, 'A8', 'Parte que le toca a este negocio')
etiqueta(ws, 'B8', 'valor ÷ negocios')
calc(ws, 'C8', '=C7/$B$4', MONEDA_BS)
calc(ws, 'D8', '=C8/Aranceles!$C$4', MONEDA_USD)
etiqueta(ws, 'E8', 'El sistema ya existe: este negocio no paga el desarrollo entero.')

etiqueta(ws, 'A9', 'Puesta en marcha de esta instalación')
etiqueta(ws, 'B9', 'horas × tarifa')
calc(ws, 'C9', '=Horas!B%d*Aranceles!C5' % FILA_PUESTA, MONEDA_BS)
calc(ws, 'D9', '=C9/Aranceles!$C$4', MONEDA_USD)
etiqueta(ws, 'E9', 'Instalar, cargar el catálogo, capacitar y acompañar. Se gasta con cada cliente.')

etiqueta(ws, 'A10', 'COSTO DEL PROYECTO PARA ESTE NEGOCIO', negrita=True)
calc(ws, 'C10', '=C8+C9', MONEDA_BS, negrita=True, relleno=AZUL_SUAVE)
calc(ws, 'D10', '=C10/Aranceles!$C$4', MONEDA_USD, negrita=True, relleno=AZUL_SUAVE)
for col in 'ABE':
    ws['%s10' % col].fill = PatternFill('solid', fgColor=AZUL_SUAVE)

for f in range(7, 11):
    for col in 'ABE':
        ws['%s%d' % (col, f)].border = borde

encabezado(ws, 12, ['Precio', 'US$', 'Bs', '% del valor a arancel', 'Cuándo se usa'])

etiqueta(ws, 'A13', 'Precio de lista')
editable(ws, 'B13', D.COMPRA_LISTA_USD, MONEDA_USD)
calc(ws, 'C13', '=B13*Aranceles!$C$4', MONEDA_BS)
calc(ws, 'D13', '=C13/$C$7', PORC)
etiqueta(ws, 'E13', 'El precio con el que se presenta.')

etiqueta(ws, 'A14', 'Precio de lanzamiento', negrita=True)
editable(ws, 'B14', D.COMPRA_LANZAMIENTO_USD, MONEDA_USD)
calc(ws, 'C14', '=B14*Aranceles!$C$4', MONEDA_BS, negrita=True, relleno=VERDE)
calc(ws, 'D14', '=C14/$C$7', PORC, negrita=True, relleno=VERDE)
etiqueta(ws, 'E14', 'Para el primer cliente. Es el que se ofrece.')
ws['A14'].fill = PatternFill('solid', fgColor=VERDE)
ws['E14'].fill = PatternFill('solid', fgColor=VERDE)

etiqueta(ws, 'A15', 'Piso de negociación')
editable(ws, 'B15', D.COMPRA_PISO_USD, MONEDA_USD)
calc(ws, 'C15', '=B15*Aranceles!$C$4', MONEDA_BS)
calc(ws, 'D15', '=C15/$C$7', PORC)
etiqueta(ws, 'E15', 'Hasta aquí, y no más abajo. Ver la fila de control.')

for f in range(13, 16):
    for col in 'AE':
        ws['%s%d' % (col, f)].border = borde

etiqueta(ws, 'A17', 'Control: negocios necesarios para recuperar el desarrollo al precio del piso',
         negrita=True)
calc(ws, 'C17', '=ROUNDUP($C$7/(C15-$C$9),0)', ENTERO, negrita=True, relleno=AMARILLO)
nota(ws, 'D17', '← si este número sale muy alto, el piso está demasiado abajo', hasta='E17')

nota(ws, 'A19', 'Por debajo del piso el negocio deja de cerrar: lo que sobra después de pagar la '
                'puesta en marcha ya no alcanza para recuperar el desarrollo en un número '
                'razonable de clientes.', alto=32, hasta='E19')

# ================================================================== SUSCRIPCIÓN
ws = wb.create_sheet('Suscripción')
anchos(ws, [46, 18, 18, 60])

titulo(ws, 'A1', 'Opción B — Suscripción de uso')
nota(ws, 'A2', 'El negocio no compra nada: paga por usar el sistema, alojado y mantenido por '
               'nosotros.', alto=18, hasta='D2')

etiqueta(ws, 'A4', 'Meses que dura un cliente (permanencia estimada)', negrita=True)
editable(ws, 'B4', D.MESES_PERMANENCIA, ENTERO)
nota(ws, 'C4', '← editable: sobre cuántos meses se reparte el desarrollo', hasta='D4')

etiqueta(ws, 'A5', 'Costo de infraestructura por negocio (US$/mes)', negrita=True)
editable(ws, 'B5', 7, MONEDA_USD)
nota(ws, 'C5', '← el servidor se comparte entre varios negocios', hasta='D5')

etiqueta(ws, 'A6', 'Horas de soporte al mes', negrita=True)
editable(ws, 'B6', 1, '0.0')
nota(ws, 'C6', '← editable', hasta='D6')

encabezado(ws, 8, ['Componente del costo mensual', 'US$ / mes', 'Bs / mes', 'De dónde sale'])

etiqueta(ws, 'A9', 'Infraestructura compartida')
calc(ws, 'B9', '=$B$5', MONEDA_USD)
calc(ws, 'C9', '=B9*Aranceles!$C$4', MONEDA_BS)
etiqueta(ws, 'D9', 'Servidor, dominio, certificado HTTPS y respaldos.')

etiqueta(ws, 'A10', 'Soporte y actualizaciones')
calc(ws, 'B10', '=$B$6*Aranceles!C5/Aranceles!$C$4', MONEDA_USD)
calc(ws, 'C10', '=B10*Aranceles!$C$4', MONEDA_BS)
etiqueta(ws, 'D10', 'Horas de soporte a la tarifa del arancel.')

etiqueta(ws, 'A11', 'Parte del desarrollo')
calc(ws, 'B11', "=Compra!$D$7/(Compra!$B$4*$B$4)", MONEDA_USD)
calc(ws, 'C11', '=B11*Aranceles!$C$4', MONEDA_BS)
etiqueta(ws, 'D11', 'Valor del producto ÷ (negocios × meses de permanencia).')

etiqueta(ws, 'A12', 'COSTO MENSUAL', negrita=True)
calc(ws, 'B12', '=SUM(B9:B11)', MONEDA_USD, negrita=True, relleno=AZUL_SUAVE)
calc(ws, 'C12', '=SUM(C9:C11)', MONEDA_BS, negrita=True, relleno=AZUL_SUAVE)
ws['A12'].fill = PatternFill('solid', fgColor=AZUL_SUAVE)
ws['D12'].fill = PatternFill('solid', fgColor=AZUL_SUAVE)

for f in range(9, 13):
    for col in 'AD':
        ws['%s%d' % (col, f)].border = borde

encabezado(ws, 14, ['Precio al cliente', 'US$', 'Bs', 'Detalle'])

etiqueta(ws, 'A15', 'Instalación (una sola vez)')
editable(ws, 'B15', D.INSTALACION_SUSC_USD, MONEDA_USD)
calc(ws, 'C15', '=B15*Aranceles!$C$4', MONEDA_BS)
etiqueta(ws, 'D15', 'Cubre parte de la puesta en marcha; el resto sale del margen mensual.')

etiqueta(ws, 'A16', 'Suscripción mensual', negrita=True)
editable(ws, 'B16', D.SUSCRIPCION_MES_USD, MONEDA_USD)
calc(ws, 'C16', '=B16*Aranceles!$C$4', MONEDA_BS, negrita=True, relleno=VERDE)
etiqueta(ws, 'D16', 'Se paga por adelantado, mes a mes.')
ws['A16'].fill = PatternFill('solid', fgColor=VERDE)

etiqueta(ws, 'A17', 'Suscripción anual')
editable(ws, 'B17', D.SUSCRIPCION_ANIO_USD, MONEDA_USD)
calc(ws, 'C17', '=B17*Aranceles!$C$4', MONEDA_BS)
calc(ws, 'D17', '="Paga 10 meses y usa 12: US$ "&TEXT(B17/12,"0.00")&" por mes."')

etiqueta(ws, 'A19', 'Margen sobre el costo mensual', negrita=True)
calc(ws, 'B19', '=B16-B12', MONEDA_USD, negrita=True)
calc(ws, 'C19', '=B19*Aranceles!$C$4', MONEDA_BS, negrita=True)
calc(ws, 'D19', '="= "&TEXT(B19/B16,"0%")&" del precio"')

etiqueta(ws, 'A20', 'Meses hasta recuperar la puesta en marcha', negrita=True)
calc(ws, 'B20', '=ROUNDUP((Horas!B%d*Aranceles!C5/Aranceles!C4-B15)/B19,0)' % FILA_PUESTA,
     ENTERO, negrita=True, relleno=AMARILLO)
nota(ws, 'C20', '← lo que no cubre la instalación se recupera con el margen', hasta='D20')

for f in (15, 16, 17, 19, 20):
    ws['A%d' % f].border = borde

# =================================================================== COMPARACIÓN
ws = wb.create_sheet('Comparación')
anchos(ws, [14, 16, 16, 16, 16, 16, 16, 30])

titulo(ws, 'A1', 'Compra o suscripción: cuál sale mejor, y a partir de cuándo')
nota(ws, 'A2', 'Lo que el negocio habría desembolsado al cabo de cada plazo, con las dos '
               'opciones. Todo sale de las hojas anteriores.', alto=18, hasta='H2')

encabezado(ws, 4, ['Al cabo de', 'Compra US$', 'Compra Bs', 'Suscripción US$',
                   'Suscripción Bs', 'Diferencia US$', 'Diferencia Bs', 'Cuál conviene'])

fila = 5
for meses in (6, 12, 18, 24, 30, 36, 48, 60):
    calc(ws, 'A%d' % fila, meses, ENTERO)
    ws['A%d' % fila] = '%d meses' % meses
    ws['A%d' % fila].border = borde
    calc(ws, 'B%d' % fila, '=Compra!$B$14', MONEDA_USD)
    calc(ws, 'C%d' % fila, '=B%d*Aranceles!$C$4' % fila, MONEDA_BS)
    calc(ws, 'D%d' % fila,
         "='Suscripción'!$B$15+'Suscripción'!$B$16*%d" % meses, MONEDA_USD)
    calc(ws, 'E%d' % fila, '=D%d*Aranceles!$C$4' % fila, MONEDA_BS)
    calc(ws, 'F%d' % fila, '=D%d-B%d' % (fila, fila), MONEDA_USD)
    calc(ws, 'G%d' % fila, '=F%d*Aranceles!$C$4' % fila, MONEDA_BS)
    calc(ws, 'H%d' % fila, '=IF(D%d<B%d,"suscripción",IF(D%d>B%d,"compra","se igualan"))'
         % (fila, fila, fila, fila))
    fila += 1

fila += 1
etiqueta(ws, 'A%d' % fila, 'Punto de equilibrio (meses)', negrita=True)
calc(ws, 'C%d' % fila,
     "=ROUND((Compra!$B$14-'Suscripción'!$B$15)/'Suscripción'!$B$16,1)", '0.0',
     negrita=True, relleno=VERDE)
nota(ws, 'D%d' % fila, '← antes de este plazo conviene suscribirse; después, comprar',
     hasta='H%d' % fila)

fila += 2
nota(ws, 'A%d' % fila, 'La comparación no incluye el soporte opcional posterior a la compra '
                       '(hoja Compra), porque no es obligatorio: terminada la garantía, el '
                       'sistema sigue funcionando igual sin pagar nada.',
     alto=32, hasta='H%d' % fila)

# ------------------------------------------------------------------ congelar
for hoja in wb.sheetnames:
    wb[hoja].sheet_view.showGridLines = False

wb.save(D.salida('Presupuesto_Sistema_de_Ventas.xlsx'))
print('excel listo')
