# -*- coding: utf-8 -*-
"""
Los números de la propuesta, en un solo lugar.

Todo sale de dos fuentes y ninguna es una corazonada:

  * las HORAS se estimaron por bloque contra el tamaño real del repositorio
    (23 controladores, 27 modelos, 12 servicios, 69 vistas, 275 pruebas,
    33.695 líneas propias);
  * la TARIFA es el arancel del CITI Santa Cruz 2022, escalón «técnico
    superior», el mismo que se usó en el presupuesto de Granja Aranjuez.

El tipo de cambio es 7 Bs por dólar: los precios se piensan en dólares y se
convierten, no al revés.
"""

import os
import sys

TC = 7.0                     # Bs por US$

# --------------------------------------------------- el arancel del CITI
ARANCEL = [
    ('Técnico medio',       70,  'Trabajo operativo, ejecución bajo supervisión.'),
    ('Técnico superior',    120, 'Desarrollo profesional a cargo del proyecto. Es la banda que corresponde a este trabajo.'),
    ('Otras consultorías',  220, 'Consultoría especializada, asesoría técnica.'),
    ('Peritaje en sistemas', 280, 'Peritaje judicial e informes periciales.'),
]
TARIFA_BS = 120              # técnico superior

# --------------------------------------------------- horas por bloque
HORAS = [
    ('Base de datos',            85, '29 tablas, 369 columnas, 16 generadas, 49 claves foráneas, 22 restricciones CHECK, 6 procedimientos, 7 disparadores y 9 vistas.'),
    ('Punto de venta',           95, 'Búsqueda con lector de código de barras, carrito, descuentos con tope, cobro, vuelto y pago repartido entre varias formas.'),
    ('Cobro por QR',             40, 'Código con el importe incluido, pasarela intercambiable, simulador, plantilla de banco, aviso firmado y confirmación manual.'),
    ('Caja y arqueo',            55, 'Turnos por cajero, movimientos de efectivo, cierre con efectivo esperado y resumen imprimible para firmar.'),
    ('Comprobantes',             50, 'Series y correlativos con bloqueo de fila, impresión en ticket de 80 mm y en A4, y sustitución recibo↔factura.'),
    ('Catálogo',                 55, 'Productos con foto, precios, categorías, unidades de medida y proveedores.'),
    ('Almacén',                  40, 'Existencias con alerta de faltantes, ingreso a producto existente, ajustes por merma y kardex global.'),
    ('Ventas, devoluciones y anulaciones', 60, 'Historial con filtros, devoluciones totales y parciales con reingreso selectivo de stock, y anulación con reversión.'),
    ('Clientes',                 20, 'Persona natural y jurídica; el tipo define si recibe recibo o factura.'),
    ('Reportes',                 60, 'Ventas por día, método de pago y cajero; ranking de productos y alertas de stock, con exportación a Excel y PDF.'),
    ('Personal, usuarios y permisos', 55, 'Empleados, cargos, cuentas, roles y matriz de permisos por módulo.'),
    ('Portada y navegación',     25, 'Panel por rol, con los bloques que cada cuenta puede ver.'),
    ('Reglas replicadas en PHP', 50, 'Las mismas reglas de la base ejecutadas por la aplicación, para poder correr en hosting sin procedimientos ni disparadores.'),
    ('Pruebas automatizadas',    80, '275 pruebas y 826 aserciones, que corren contra una copia real de la base en las dos modalidades.'),
    ('Infraestructura',          55, 'Entornos Docker de desarrollo y de producción, integración continua, respaldos, revisión de salud y aplicación de parches.'),
    ('Documentación',            40, 'Manual de uso, manual de funcionamiento y guía técnica del repositorio.'),
]
TOTAL_HORAS = sum(h for _, h, _ in HORAS)          # 865

# --------------------------------------------------- puesta en marcha por cliente
PUESTA_EN_MARCHA = [
    ('Instalación en el servidor, dominio, HTTPS y respaldos', 8),
    ('Carga del catálogo inicial y datos del negocio',        10),
    ('Capacitación: dos sesiones con el personal',             6),
    ('Acompañamiento durante el primer mes',                   8),
    ('Ajustes menores a la forma de trabajar del negocio',     8),
]
HORAS_PUESTA = sum(h for _, h in PUESTA_EN_MARCHA)  # 40

# --------------------------------------------------- lo derivado
CLIENTES_AMORTIZACION = 12      # entre cuántos negocios se reparte el desarrollo
MESES_PERMANENCIA = 60          # cuánto dura un cliente de suscripción

VALOR_ARANCEL_BS = TOTAL_HORAS * TARIFA_BS                 # 103.800
PUESTA_BS        = HORAS_PUESTA * TARIFA_BS                # 4.800
AMORTIZACION_BS  = VALOR_ARANCEL_BS / CLIENTES_AMORTIZACION  # 8.650
COSTO_BASE_BS    = AMORTIZACION_BS + PUESTA_BS             # 13.450

# --------------------------------------------------- precios (pensados en US$)
COMPRA_LISTA_USD      = 2500
COMPRA_LANZAMIENTO_USD = 2000
COMPRA_PISO_USD       = 1600

SUSCRIPCION_MES_USD   = 60
SUSCRIPCION_ANIO_USD  = 600      # paga 10 meses, usa 12
INSTALACION_SUSC_USD  = 350
SOPORTE_OPCIONAL_USD  = 25       # mensual, después de comprar

def bs(usd):
    return usd * TC

def usd(bolivianos):
    return bolivianos / TC

def m(usd_):
    """US$ 1.234,56"""
    return 'US$ %s' % ('{:,.2f}'.format(usd_).replace(',', '@').replace('.', ',').replace('@', '.'))

def b(bs_):
    """Bs 8.640,00"""
    return 'Bs %s' % ('{:,.2f}'.format(bs_).replace(',', '@').replace('.', ',').replace('@', '.'))

def ambos(usd_):
    return '%s  /  %s' % (m(usd_), b(bs(usd_)))


def pct(x, decimales=1):
    """13,5 % — con coma, como el resto de las cifras del documento."""
    return ('%.*f' % (decimales, x)).replace('.', ',') + ' %'


def salida(nombre):
    """
    Dónde se escribe el entregable.

    La carpeta vive FUERA del repositorio, porque el paquete de despliegue
    lleva el `.env` de la demo y las fotos de los productos, que no se
    versionan. Por omisión se busca al lado del repositorio; desde un worktree
    hay que decirle dónde está:

        SALIDA=/ruta/a/despliegue-demo python propuesta.py
    """
    raiz = os.environ.get('SALIDA')

    if not raiz:
        aqui = os.path.dirname(os.path.abspath(__file__))     # docs/comercial
        raiz = os.path.join(aqui, '..', '..', '..', 'despliegue-demo')

    raiz = os.path.abspath(raiz)

    if not os.path.isdir(raiz):
        raise SystemExit(
            'no encuentro la carpeta de salida:\n'
            '    %s\n'
            'si el repositorio está en un worktree, pásale la ruta:\n'
            '    SALIDA=/ruta/a/despliegue-demo python %s'
            % (raiz, os.path.basename(sys.argv[0])))

    return os.path.join(raiz, nombre)
