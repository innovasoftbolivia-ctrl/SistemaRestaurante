# -*- coding: utf-8 -*-
"""Arma el manual del Sistema de Ventas en .docx."""

from docx import Document
from docx.shared import Pt, Cm, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_BREAK
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.oxml.ns import qn
from docx.oxml import OxmlElement

AZUL = RGBColor(0x1D, 0x4E, 0xD8)
GRIS = RGBColor(0x55, 0x5B, 0x66)
NEGRO = RGBColor(0x1F, 0x24, 0x2C)

doc = Document()

# ---------------------------------------------------------------- estilos base
normal = doc.styles['Normal']
normal.font.name = 'Calibri'
normal.font.size = Pt(10.5)
normal.font.color.rgb = NEGRO
normal.paragraph_format.space_after = Pt(6)
normal.paragraph_format.line_spacing = 1.15
normal._element.rPr.rFonts.set(qn('w:eastAsia'), 'Calibri')

for nivel, tam in ((1, 18), (2, 13.5), (3, 11.5)):
    est = doc.styles['Heading %d' % nivel]
    est.font.name = 'Calibri'
    est.font.size = Pt(tam)
    est.font.bold = True
    est.font.color.rgb = AZUL if nivel < 3 else NEGRO
    est.paragraph_format.space_before = Pt(16 if nivel == 1 else 12)
    est.paragraph_format.space_after = Pt(6)

for sec in doc.sections:
    sec.top_margin = Cm(2.2)
    sec.bottom_margin = Cm(2.2)
    sec.left_margin = Cm(2.4)
    sec.right_margin = Cm(2.4)


def sombrear(celda, hexcolor):
    tc = celda._tc.get_or_add_tcPr()
    sombra = OxmlElement('w:shd')
    sombra.set(qn('w:val'), 'clear')
    sombra.set(qn('w:fill'), hexcolor)
    tc.append(sombra)


def p(texto='', negrita=False, cursiva=False, tam=None, color=None, espacio=None):
    par = doc.add_paragraph()
    run = par.add_run(texto)
    run.bold = negrita
    run.italic = cursiva
    if tam:
        run.font.size = Pt(tam)
    if color:
        run.font.color.rgb = color
    if espacio is not None:
        par.paragraph_format.space_after = Pt(espacio)
    return par


def rico(partes):
    """partes: lista de (texto, negrita)."""
    par = doc.add_paragraph()
    for tramo in partes:
        run = par.add_run(tramo[0])
        run.bold = tramo[1]
    return par


def vineta(texto, negrita_inicial=None):
    par = doc.add_paragraph(style='List Bullet')
    if negrita_inicial:
        r1 = par.add_run(negrita_inicial)
        r1.bold = True
    par.add_run(texto)
    return par


def numerada(texto):
    return doc.add_paragraph(texto, style='List Number')


def tabla(cabeceras, filas, anchos=None):
    t = doc.add_table(rows=1, cols=len(cabeceras))
    t.style = 'Table Grid'
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    enc = t.rows[0].cells
    for i, texto in enumerate(cabeceras):
        enc[i].text = ''
        run = enc[i].paragraphs[0].add_run(texto)
        run.bold = True
        run.font.size = Pt(9.5)
        run.font.color.rgb = RGBColor(0xFF, 0xFF, 0xFF)
        sombrear(enc[i], '1D4ED8')
    for f, fila in enumerate(filas):
        celdas = t.add_row().cells
        for i, texto in enumerate(fila):
            celdas[i].text = ''
            par = celdas[i].paragraphs[0]
            par.paragraph_format.space_after = Pt(2)
            run = par.add_run(str(texto))
            run.font.size = Pt(9.5)
            if i == 0 and len(fila) > 1:
                run.bold = True
        if f % 2 == 1:
            for c in celdas:
                sombrear(c, 'F1F5F9')
    if anchos:
        for fila in t.rows:
            for i, ancho in enumerate(anchos):
                fila.cells[i].width = Cm(ancho)
    doc.add_paragraph().paragraph_format.space_after = Pt(4)
    return t


def aviso(titulo, texto):
    t = doc.add_table(rows=1, cols=1)
    t.style = 'Table Grid'
    celda = t.rows[0].cells[0]
    # Sin ancho explícito la tabla toma 16,8 cm y se sale del margen derecho.
    celda.width = Cm(15.5)
    celda.text = ''
    par = celda.paragraphs[0]
    r1 = par.add_run(titulo + '  ')
    r1.bold = True
    r1.font.size = Pt(9.5)
    r2 = par.add_run(texto)
    r2.font.size = Pt(9.5)
    sombrear(celda, 'FEF3C7')
    doc.add_paragraph().paragraph_format.space_after = Pt(4)


def salto():
    doc.add_paragraph().add_run().add_break(WD_BREAK.PAGE)


# ===================================================================== PORTADA
enc = doc.add_paragraph()
enc.alignment = WD_ALIGN_PARAGRAPH.CENTER
enc.paragraph_format.space_before = Pt(90)
r = enc.add_run('SISTEMA DE VENTAS')
r.bold = True
r.font.size = Pt(30)
r.font.color.rgb = AZUL

sub = doc.add_paragraph()
sub.alignment = WD_ALIGN_PARAGRAPH.CENTER
r = sub.add_run('Minimarket El Ahorro')
r.font.size = Pt(16)
r.font.color.rgb = GRIS

lin = doc.add_paragraph()
lin.alignment = WD_ALIGN_PARAGRAPH.CENTER
lin.paragraph_format.space_before = Pt(28)
r = lin.add_run('Manual de uso y guía de prueba')
r.bold = True
r.font.size = Pt(15)

lin = doc.add_paragraph()
lin.alignment = WD_ALIGN_PARAGRAPH.CENTER
r = lin.add_run('Punto de venta · Caja · Inventario · Comprobantes · Reportes')
r.font.size = Pt(11)
r.font.color.rgb = GRIS

lin = doc.add_paragraph()
lin.alignment = WD_ALIGN_PARAGRAPH.CENTER
lin.paragraph_format.space_before = Pt(40)
r = lin.add_run('sistemaventas.infinityfreeapp.com')
r.bold = True
r.font.size = Pt(13)
r.font.color.rgb = AZUL

lin = doc.add_paragraph()
lin.alignment = WD_ALIGN_PARAGRAPH.CENTER
r = lin.add_run('Versión de demostración · Septiembre de 2026')
r.font.size = Pt(10)
r.font.color.rgb = GRIS

salto()

# ================================================================ 1. QUÉ ES
doc.add_heading('1. Qué es este sistema', level=1)

p('El Sistema de Ventas es el programa con el que se atiende el mostrador del minimarket y se '
  'lleva el control del negocio. Cubre el ciclo completo de una jornada:')

vineta('abrir el turno con el efectivo con el que se empieza y cerrarlo contando lo que quedó.', 'Caja: ')
vineta('cobrar, elegir el medio de pago, dar el vuelto y emitir el comprobante.', 'Mostrador: ')
vineta('descontar el stock en el mismo momento de la venta, sin que nadie lo toque a mano.', 'Inventario: ')
vineta('devoluciones, anulaciones y sustitución de comprobantes, sin borrar nada.', 'Correcciones: ')
vineta('qué se vendió, quién lo vendió, cuánto se ganó y qué falta reponer.', 'Reportes: ')
vineta('cada persona ve y hace solo lo que le corresponde a su rol.', 'Control de acceso: ')

p('Funciona dentro del navegador. No hay que instalar nada: se entra escribiendo la dirección, '
  'igual que a cualquier página. Sirve desde la computadora del mostrador, desde una laptop o '
  'desde el teléfono.', espacio=10)

doc.add_heading('1.1 Cómo entrar', level=2)

tabla(['Dato', 'Valor'],
      [['Dirección', 'https://sistemaventas.infinityfreeapp.com'],
       ['Navegador', 'Chrome, Edge o Firefox actualizados'],
       ['Instalación', 'Ninguna'],
       ['Moneda', 'Boliviano (Bs)'],
       ['Idioma', 'Español']],
      anchos=[4.0, 11.5])

aviso('Importante:',
      'esta es una demostración. Los productos, los precios, los clientes y las 1.085 ventas que '
      'verás son datos de prueba, inventados para que el sistema se pueda recorrer con contenido '
      'real. Puedes vender, devolver, anular y borrar con total tranquilidad: no hay nada que '
      'arruinar.')

salto()

# ============================================================== 2. LAS CUENTAS
doc.add_heading('2. Las cuentas de acceso', level=1)

p('El sistema trae tres cuentas, una por cada rol. Conviene probarlas las tres: la gracia está '
  'justamente en que cada una ve una cosa distinta.')

tabla(['Usuario', 'Contraseña', 'Persona', 'Cargo', 'Rol', 'Al entrar cae en'],
      [['admin', 'admin123', 'Ana Quispe Torres', 'Gerente', 'Administrador', 'Inicio (el panel)'],
       ['cajero1', 'cajero123', 'Luis Ramos Vega', 'Cajero', 'Cajero', 'Punto de venta'],
       ['almacen', 'almacen123', 'Marta Flores Díaz', 'Almacenero', 'Almacenero', 'Inicio (el panel)']],
      anchos=[2.2, 2.3, 3.4, 2.0, 2.6, 3.0])

p('El cajero cae directo en el mostrador y no en el panel, a propósito: es la pantalla en la que '
  'va a pasar el día.', espacio=12)

doc.add_heading('2.1 Qué puede hacer cada rol', level=2)

p('Esta tabla es el resumen de los permisos reales del sistema. No es una recomendación: si una '
  'celda dice «no», esa persona recibe un aviso de «no tienes acceso» aunque escriba la dirección '
  'a mano en el navegador.')

tabla(['Módulo o acción', 'Administrador', 'Cajero', 'Almacenero'],
      [['Panel de inicio', 'Sí', 'Sí', 'Sí'],
       ['Punto de venta (cobrar)', 'Sí', 'Sí', 'No'],
       ['Abrir turno de caja', 'Sí', 'Sí', 'No'],
       ['Registrar ingreso o egreso de efectivo', 'Sí', 'Sí', 'No'],
       ['Cerrar turno de caja', 'Sí', 'No', 'No'],
       ['Ver ventas y comprobantes', 'Sí', 'Sí', 'Sí'],
       ['Anular una venta', 'Sí', 'No', 'No'],
       ['Sustituir un comprobante', 'Sí', 'No', 'No'],
       ['Ver devoluciones', 'Sí', 'No', 'Sí'],
       ['Registrar una devolución', 'Sí', 'No', 'No'],
       ['Ver clientes', 'Sí', 'Sí', 'Sí'],
       ['Crear o editar clientes', 'Sí', 'Sí', 'No'],
       ['Catálogo: productos, categorías, unidades, proveedores', 'Sí', 'No', 'Sí'],
       ['Almacén: ver existencias y el kardex completo', 'Sí', 'No', 'Sí'],
       ['Ingresar mercadería y ajustar stock', 'Sí', 'No', 'Sí'],
       ['Reportes de ventas e inventario', 'Sí', 'No', 'Sí'],
       ['Cajas del local (crear puestos de cobro)', 'Sí', 'No', 'No'],
       ['Empleados y cargos', 'Sí', 'No', 'No'],
       ['Usuarios y roles', 'Sí', 'No', 'No'],
       ['Cambiar la propia contraseña', 'Sí', 'Sí', 'Sí']],
      anchos=[7.4, 2.8, 2.4, 2.9])

aviso('Detalle que suele llamar la atención:',
      'el cajero puede ABRIR su caja pero no puede CERRARLA. El cierre es el momento en que se '
      'cuenta el dinero y se compara contra lo que el sistema esperaba; que lo haga la misma '
      'persona que manejó el efectivo le quita todo el sentido al control. Por eso el cierre lo '
      'hace el administrador, junto al cajero, y el resumen se imprime para que ambos lo firmen.')

salto()

# ======================================================== 3. RECORRIDO DE PRUEBA
doc.add_heading('3. Recorrido de prueba', level=1)

p('Trece ejercicios, en orden. Cada uno dice qué hacer y qué debería pasar. Si los haces todos, '
  'habrás tocado todos los módulos del sistema. Toma entre 35 y 50 minutos.')

doc.add_heading('Ejercicio 1 — Entrar y leer el panel', level=2)
rico([('Entra como ', False), ('admin / admin123', True), ('.', False)])
p('Verás el panel de inicio. Fíjate en:')
vineta('lo vendido hoy y la comparación contra ayer, con su porcentaje;')
vineta('el ticket promedio y la cantidad de operaciones;')
vineta('el gráfico de los últimos catorce días;')
vineta('las últimas ocho ventas, con su comprobante y su cajero;')
vineta('el bloque «Reponer», con los productos que llegaron a su stock mínimo.')
rico([('Qué comprobar: ', True), ('que las cifras de arriba coinciden con las ventas listadas abajo.', False)])

doc.add_heading('Ejercicio 2 — Abrir la caja', level=2)
p('Sin un turno abierto no se puede cobrar: cada venta tiene que quedar imputada a un turno y a un '
  'responsable. Es lo primero que se hace cada mañana.')
numerada('Ve a Caja en el menú.')
numerada('Pulsa «Abrir caja».')
numerada('Elige «Caja 1» y escribe un monto inicial, por ejemplo 300.')
numerada('Confirma.')
rico([('Qué comprobar: ', True),
      ('aparece «Turno abierto» con la hora y el monto. En la lista de abajo, «Turnos anteriores», '
       'está el historial de los 87 cierres pasados con su diferencia de arqueo.', False)])

doc.add_heading('Ejercicio 3 — Cobrar a un cliente de paso', level=2)
numerada('Ve a Punto de venta.')
numerada('Haz clic en dos o tres productos: se van sumando al carrito de la derecha.')
numerada('Ajusta cantidades con los botones − y +.')
numerada('Deja el cliente en «Cliente varios».')
numerada('Elige Efectivo y escribe cuánto te dio el cliente, por ejemplo 50.')
numerada('Pulsa «Cobrar».')
rico([('Qué comprobar: ', True),
      ('el sistema calcula el vuelto solo y te lleva a la venta ya emitida, con un comprobante de '
       'la serie R001 (Recibo). El recibo es lo que corresponde a una persona natural.', False)])
p('También puedes buscar por nombre o pasar el lector de código de barras: el cursor ya está en el '
  'buscador y con Enter el producto entra al carrito.', cursiva=True)

doc.add_heading('Ejercicio 3 bis — Cobrar por QR, y partir el pago', level=2)
p('Esta es la forma de cobro nueva, y la que conviene mirar con calma.')
numerada('En Punto de venta, arma un carrito de unos Bs 35.')
numerada('En «Forma de pago» elige «Pago por QR».')
numerada('En «Importe» escribe 20 y pulsa «Generar QR por Bs 20.00».')
rico([('Qué comprobar: ', True),
      ('aparece un código QR de verdad, escaneable, y el importe ya va DENTRO del código. '
       'El cliente apunta el teléfono y paga exactamente Bs 20: no tiene que escribir el monto, '
       'así que no se puede equivocar.', False)])
numerada('Pulsa «+ Dividir el pago en otra forma» y deja la segunda línea en Efectivo.')
rico([('Qué comprobar: ', True),
      ('la segunda línea dice «Cubre Bs 15.00» sola. Una de las líneas puede quedar sin importe '
       'y se lleva lo que falte, para que el cajero no tenga que restar.', False)])
numerada('Fíjate en que el botón «Cobrar» está bloqueado, y dice por qué: «Falta que se confirme el pago por QR».')
numerada('Pulsa «Ya me pagó» en el bloque del QR.')
numerada('En «Efectivo recibido» escribe 20, como si el cliente te diera un billete de Bs 20 por los Bs 15 que faltan.')
rico([('Qué comprobar: ', True),
      ('el vuelto es Bs 5, no Bs 25. El vuelto sale de SU línea: el sistema sabe que los otros '
       'Bs 20 entraron por el banco y no por el cajón.', False)])
numerada('Pulsa «Cobrar» y mira la venta emitida: aparecen las dos formas de pago, con el vuelto.')
aviso('Por qué dice «Sin banco conectado»',
      'El QR es real y escaneable, pero todavía no hay convenio con ningún banco: sin banco, nadie '
      'avisa que el cobro llegó, así que lo confirma el cajero con «Ya me pagó». Está hecho a '
      'propósito para que se vea; un simulador que se pagara solo daría la impresión de que el '
      'cobro funciona de punta a punta. El día que el banco entregue sus credenciales, se cambia '
      'una línea de configuración y el QR pasa a confirmarse solo. El mostrador no cambia.')

doc.add_heading('Ejercicio 4 — Cobrar a una empresa (factura)', level=2)
p('Repite el ejercicio anterior, pero antes de cobrar despliega «Cliente» y elige '
  '«Pensión Doña Martha S.R.L.» o «Comedor Popular El Sabor Ltda.».')
rico([('Qué comprobar: ', True),
      ('el comprobante ya no es un recibo sino una FACTURA, de la serie F001, y sale con el nombre '
       'y el NIT del cliente. El sistema elige solo el tipo de documento según si el cliente es '
       'persona o empresa: el cajero no tiene que acordarse.', False)])

doc.add_heading('Ejercicio 5 — Imprimir el comprobante', level=2)
p('En la venta que acabas de hacer tienes dos botones:')
vineta('el formato angosto de impresora térmica de mostrador;', 'Imprimir ticket: ')
vineta('la misma venta en hoja completa, para archivar o mandar por correo.', 'Ver en A4: ')
rico([('Qué comprobar: ', True),
      ('en el detalle, cada línea muestra el nombre y el precio TAL COMO ESTABAN el día de la '
       'venta. Si mañana cambias el precio del producto, este documento no se altera.', False)])

doc.add_heading('Ejercicio 6 — Sustituir un comprobante', level=2)
p('Caso real: cobraste, saliste con un recibo, y recién entonces el cliente te pide factura a '
  'nombre de su empresa.')
numerada('Abre una venta reciente que tenga recibo.')
numerada('Pulsa «Sustituir comprobante».')
numerada('Elige un cliente con NIT y escribe el motivo.')
rico([('Qué comprobar: ', True),
      ('se emite una factura nueva y el recibo anterior queda marcado como SUSTITUIDO. Los dos '
       'siguen visibles, encadenados, en el bloque «Documentos». Nada se borra: la corrección '
       'queda a la vista.', False)])
p('Solo se puede sustituir dentro del plazo configurado, que en esta demo es de 1 día.', cursiva=True)

doc.add_heading('Ejercicio 7 — Registrar una devolución', level=2)
numerada('Abre una venta y pulsa «Registrar devolución».')
numerada('Indica cuántas unidades devuelve el cliente de cada producto.')
numerada('Marca si la mercadería vuelve al estante o si viene dañada.')
numerada('Escribe el motivo y confirma.')
rico([('Qué comprobar: ', True),
      ('el sistema te dice cuánto dinero hay que entregar. Si marcaste que vuelve al estante, el '
       'stock sube; si estaba dañada, se devuelve el dinero pero el stock NO sube. La venta pasa a '
       '«Devuelta parcial» o «Devuelta total» según cuánto se devolvió.', False)])
p('Se devuelve el precio del día de la venta, no el de hoy.', cursiva=True)

doc.add_heading('Ejercicio 8 — Anular una venta', level=2)
p('Distinto de la devolución: anular es deshacer la venta entera, porque no debió existir.')
numerada('Haz una venta nueva y pequeña.')
numerada('Ábrela y pulsa «Anular venta».')
numerada('Escribe el motivo.')
rico([('Qué comprobar: ', True),
      ('la venta queda tachada como ANULADA, todo el stock vuelve al inventario, el comprobante '
       'queda anulado y el monto deja de contar para el arqueo de la caja y para los reportes. '
       'La venta no desaparece de la lista: queda con su motivo y su responsable.', False)])
p('Solo se puede anular una venta COMPLETADA. Si ya tuvo una devolución, hay que ir por ese camino.',
  cursiva=True)

doc.add_heading('Ejercicio 9 — Mover el inventario', level=2)
rico([('Entra como ', False), ('almacen / almacen123', True),
      (' y ve a Almacén → Inventario.', False)])
p('Esta es la pantalla del depósito. Arriba, cuánto vale el inventario y cuántos productos están '
  'bajo el mínimo o agotados. Abajo, cada producto con su stock y dos botones.')
numerada('Pulsa «Solo lo que falta»: quedan a la vista los productos por reponer.')
numerada('En cualquier fila, pulsa «Ingresar»: registra la mercadería que llegó, con cantidad, costo, proveedor y número de factura.')
p('Si el producto viene en caja, ahí no te pide un total: te pide cuántas cajas llegaron y '
  'cuántas unidades vinieron sueltas, y hace la multiplicación a la vista. Es el Ejercicio 9 ter.',
  cursiva=True)
numerada('En otra fila, pulsa «Ajustar»: escribe cuántas unidades contaste de verdad.')
rico([('Qué comprobar en el ajuste: ', True),
      ('antes de guardar, el sistema te muestra la diferencia contra lo que él creía, y si sobra '
       'o falta mercadería. El motivo es obligatorio.', False)])
numerada('Ve a Almacén → Movimientos.')
p('Es el historial de todo el depósito. Filtra por producto, por tipo de movimiento, por '
  'responsable o por fechas, y arriba te dice cuántas unidades entraron y salieron en ese recorte.')
rico([('Qué comprobar: ', True),
      ('el stock NUNCA se escribe a mano. Toda variación deja un movimiento con nombre y motivo, '
       'y no se puede borrar ni editar: una corrección es otro movimiento más. Así el inventario '
       'siempre tiene explicación.', False)])
p('Las mismas dos operaciones están también dentro de la ficha de cada producto, para cuando ya '
  'estás mirando ese producto y no quieres dar el rodeo.', cursiva=True)

doc.add_heading('Ejercicio 9 bis — Qué pasa si lo cargas como producto nuevo', level=2)
p('El error más común del depósito: llega mercadería de algo que ya está en el catálogo y la '
  'persona intenta darlo de alta otra vez.')
numerada('Ve a Productos → Nuevo producto.')
numerada('Escribe un código que ya exista, por ejemplo P-1002, completa el resto y guarda.')
rico([('Qué comprobar: ', True),
      ('el sistema no te deja crear el duplicado, pero tampoco te deja a pie: te dice de qué '
       'producto es ese código y te ofrece un botón para ir a cargarle stock directamente.', False)])

doc.add_heading('Ejercicio 9 ter — Compras por caja y vendes por unidad', level=2)
p('Es lo más común del rubro: el proveedor te trae cajas de 24 y tú despachas de a una. El '
  'sistema no te obliga a elegir. El stock se cuenta SIEMPRE en la unidad con la que vendes, y '
  'la caja es solo la forma de escribir la entrada sin sacar la calculadora.')
numerada('Ve a Productos → Nuevo producto.')
numerada('Pon el nombre y la categoría. El código lo propone el sistema: está abajo, en «Más datos», y no hace falta que lo toques.')
numerada('En «Cómo se vende y cómo se compra», elige la unidad con la que VENDES: Unidad.')
numerada('Marca «Lo compro por caja, saco, bidón o similar» y elige el empaque de la lista: Caja. Si el tuyo no está, elige «Otro…» y escríbelo.')
numerada('En «¿Cuánto trae?» pon 24.')
p('Esto no es solo para lo que se cuenta. Si vendes arroz por kilo y te llega en sacos de 46 kg, '
  'eliges Saco y pones 46; si vendes aceite por litro y te llega en bidones de 20 L, eliges Bidón '
  'y pones 20. Y admite decimales, para los que compran por galón: 3.785.', cursiva=True)
rico([('Qué comprobar: ', True),
      ('debajo aparece la frase completa —compras de a caja de 24 UND y vendes de a unidad— para '
       'que no quede ninguna duda de qué eligió cada cosa.', False)])
numerada('En «Stock inicial», a la derecha, ya no hay una casilla sino dos: cajas y sueltas.')
numerada('Escribe 3 cajas y 5 sueltas, como cuando la última caja vino incompleta.')
rico([('Qué comprobar: ', True),
      ('el sistema te muestra 77 unidades ANTES de guardar. Ese es el número que entra al stock, '
       'y es el que después descuenta el mostrador de a una.', False)])
numerada('Guarda y mira la ficha del producto.')
rico([('Qué comprobar: ', True),
      ('el stock dice 77 y, debajo, «3 cajas y 5 sueltas». Y en el kardex el movimiento no anota '
       'solo el 77: anota «3 cajas de 24 + 5 sueltas», que es como viene escrita la factura del '
       'proveedor y lo único con lo que se puede contrastar el mes que viene.', False)])
p('Esas cajas no están guardadas en ningún lado: se calculan del stock. Por eso bajan solas a '
  'medida que vendes, sin que tengas que tocar nada. Si despachas 24 unidades de un producto que '
  'viene de 24, verás una caja menos. Lo mismo aparece en el catálogo, en el almacén y en el '
  'mostrador, para que puedas mirar el estante y comprobar que cuadra.')
numerada('En «Precio de compra», mira el selector que tiene al lado: dice «por unidad / por caja».')
rico([('Qué comprobar: ', True),
      ('elige «por caja», escribe 96 —lo que te costó la caja entera— y debajo te sale «= Bs 4.00 '
       'por UND, que es lo que se guarda». No tienes que dividir nada: el sistema guarda siempre el '
       'costo por unidad, porque de ahí salen la ganancia y el valor del inventario.', False)])
p('Lo mismo vale al recibir mercadería: si la caja te sube de precio, lo escribes por caja y, '
  'cuando el número cambia, aparece una casilla ya marcada que dice «Actualizar el costo de este '
  'producto (4.00 → 4.50)». Si la dejas marcada, la ganancia deja de mentirte desde ese momento. '
  'Si fue una compra cara por una urgencia y no quieres que cambie el precio de siempre, la '
  'desmarcas: la entrada queda registrada igual con lo que costó de verdad.')
p('Un producto a granel —el arroz por kilo— no marca esa casilla, y su pantalla de ingreso sigue '
  'siendo la de siempre: una sola cantidad.', cursiva=True)

doc.add_heading('Ejercicio 9 quater — Cargar una factura entera', level=2)
p('Cargar producto por producto está bien cuando llegan tres cosas. Cuando el distribuidor te deja '
  'una factura de treinta líneas, se hace de otra manera.')
rico([('Entra como ', False), ('almacen / almacen123', True),
      (' y ve a Almacén → Compras → Registrar compra.', False)])
numerada('Arriba, pon el proveedor y el número de la factura.')
numerada('En el buscador, escribe el nombre o pasa el lector y pulsa Enter: el producto se agrega como una línea.')
p('¿Y si el producto no existe todavía, o es la primera vez que le compras a ese proveedor? No '
  'tienes que salir de aquí. Junto al buscador hay «Darlo de alta aquí», y junto al proveedor, '
  '«Registrar proveedor». Los dos abren una ventanita, guardan, y lo dejan listo en esta misma '
  'compra sin perder las líneas que ya cargaste. El producto entra al catálogo con stock cero: las '
  'unidades se las pone esta compra.', cursiva=True)
numerada('En cada línea pon lo que trae el papel. Si el producto viene en cajas, cuentas cajas y sueltas, igual que al ingresar de a uno.')
numerada('Repite con todos los productos de la factura.')
numerada('Abajo, en «Cuadrar con la factura», escribe el total que dice el papel.')
rico([('Qué comprobar: ', True),
      ('si el sistema y la factura no suman lo mismo, te lo dice ahí y te falta lo que falta. Es el '
       'momento de encontrar un cero de más, no el mes que viene.', False)])
numerada('Pulsa «Registrar compra».')
rico([('Qué comprobar: ', True),
      ('el stock de TODOS los productos sube de una vez, y la compra queda como un documento que '
       'puedes volver a abrir con sus líneas y su total. En Almacén → Movimientos, el número de la '
       'factura es un enlace: desde cualquier línea del kardex llegas a la factura completa.', False)])
p('Una compra registrada no se edita ni se borra: ya movió el stock. Si una línea quedó mal, se '
  'corrige con un ajuste de inventario sobre ese producto, que deja la diferencia explicada y con '
  'responsable.', cursiva=True)
p('«Ingresar mercadería» no desaparece: sigue siendo el camino corto cuando llega una caja suelta '
  'y no hay factura que archivar.', cursiva=True)

doc.add_heading('Ejercicio 10 — Ver qué se te vence', level=2)
p('El stock es un solo número por producto, y con un solo número no hay forma de saber qué caduca: '
  'los 262 chizitos pueden ser 200 que vencen en noviembre y 62 que vencieron la semana pasada. El '
  'sistema parte ese saldo por fecha.')
rico([('Entra como ', False), ('almacen / almacen123', True), (' y ve a Almacén → Vencimientos.', False)])
p('Arriba, cuatro cifras: lo ya vencido y cuánta plata tienes parada ahí, lo que caduca en los '
  'próximos 30 días, las unidades que nadie fechó, y cuántos productos llevan control.')
numerada('Cambia la ventana: 7, 15, 30, 60 o 90 días. Lo ya vencido sale siempre, elijas lo que elijas.')
numerada('Mira la última columna de cada fila. No todas ofrecen lo mismo, y eso es a propósito.')
rico([('«Devolver» ', True),
      ('aparece cuando esa tanda entró por una factura a la que todavía le queda algo por devolver. '
       'Te lleva al formulario de esa compra con la cantidad y el motivo ya puestos: no tienes que '
       'acordarte de con qué papel llegó.', False)])
rico([('«Dar de baja» ', True),
      ('aparece en lo ya vencido. Saca esas unidades del inventario de un clic, sin que tengas que '
       'calcular cuánto queda: el sistema descuenta esa tanda y nada más.', False)])
p('Si una fila no ofrece nada es porque todavía no venció y no hay factura contra la que reclamar: '
  'esa mercadería aún se vende.', cursiva=True)
numerada('Pulsa «Dar de baja» en algo vencido. Lee lo que dice el aviso antes de confirmar: qué producto, qué tanda, cuántas unidades y cuánto cuesta al costo.')
rico([('Qué comprobar: ', True),
      ('la fila desaparece de la lista, el stock del producto baja exactamente esa cantidad, y en '
       'Almacén → Movimientos queda un ajuste con el motivo escrito solo: «Baja por vencimiento, '
       'lote L06452, venció el 22/07/2026». No lo tecleas tú.', False)])
aviso('Por qué importa que se dé de baja',
      'Mientras no lo hagas, el sistema cree que esas unidades se pueden vender: el mostrador te '
      'las deja cobrar y el reporte de inventario las sigue valorando. Darlas de baja no es borrar '
      'un número, es dejar escrito qué se tiró, cuándo y quién.')
p('El mostrador despacha siempre del lote que vence antes. Por eso lo que ves aquí es lo que de '
  'verdad queda de esa tanda, y no una lista que se va quedando vieja.', cursiva=True)

doc.add_heading('Ejercicio 11 — Devolverle mercadería al proveedor', level=2)
p('Vino fallado, vino equivocado o se venció en el estante. Antes la única salida era un ajuste con '
  'el motivo a mano, que se mezclaba con la merma y no decía de qué factura salió.')
rico([('Como ', False), ('almacen', True),
      (', ve a Almacén → Devoluciones a proveedor y pulsa «Registrar devolución».', False)])
numerada('Primero te pregunta de qué factura. Busca por número o por proveedor: solo salen las compras a las que todavía les queda algo por devolver.')
numerada('Elige el motivo: vino fallado, vencido, no es lo que se pidió, u otro.')
numerada('Contesta «¿En qué quedaron con el proveedor?». Son tres respuestas distintas y conviene entenderlas.')
tabla(['En qué quedaron', 'Qué hace el sistema'],
      [['Ya lo repuso', 'Sale lo fallado y entra lo repuesto en el mismo documento. El stock queda como estaba, pero el problema queda registrado.'],
       ['Lo va a reponer', 'El stock baja hoy, y la devolución queda DEBIENDO hasta que llegue el reemplazo.'],
       ['Nota de crédito', 'El stock baja y no vuelve nada: queda a cuenta con el proveedor.']],
      anchos=[4.0, 11.5])
numerada('Pon cuánto vuelve de cada producto. Lo que no marques se queda como está.')
p('Si el producto lleva control de vencimiento, puedes elegir DE QUÉ TANDA sale. Es lo que hace que '
  'devolver lo vencido devuelva ese lote y no el que tocaría por orden de salida.', cursiva=True)
numerada('Guarda.')
rico([('Qué comprobar: ', True),
      ('el stock baja, y en Almacén → Movimientos aparece una salida con origen «Devolución al '
       'proveedor» — no un ajuste. Es lo que después permite contar cuánto devolviste y por qué.', False)])

doc.add_heading('Ejercicio 11 bis — Cuando el proveedor trae el cambio', level=2)
p('Si elegiste «Lo va a reponer», el listado de devoluciones te avisa arriba: «2 devoluciones '
  'esperan que el proveedor reponga la mercadería», con un botón para ver solo esas.')
numerada('Abre una de ellas. Verás «El proveedor debe» y, en cada línea, cuánto falta.')
numerada('Abajo hay un formulario: «El proveedor trajo el reemplazo». Pon lo que llegó y el número de la guía.')
rico([('Puede venir en partes: ', True),
      ('si debía 10 y trajo 6, pones 6. La devolución sigue esperando las 4 que faltan, y lo dice.', False)])
numerada('Guarda.')
rico([('Qué comprobar: ', True),
      ('la mercadería entra al stock contra esa misma devolución, y la línea pasa a decir '
       '«completo» cuando ya no falta nada. En el kardex quedan la salida con la nota de crédito y '
       'las entradas con sus guías, todas colgando del mismo documento.', False)])
p('La devolución deja de esperar sola, cuando el saldo llega a cero. No hay un botón de «marcar '
  'como repuesta» que permita cerrarla con mercadería todavía en la calle.', cursiva=True)

doc.add_heading('Ejercicio 12 — Los reportes', level=2)
rico([('Con ', False), ('admin', True), (' o ', False), ('almacen', True), (', ve a Reportes.', False)])
p('En «Ventas», pon el rango del 01/06/2026 a hoy y aplica. Verás los tres meses y medio de '
  'operación cargados en la demo:')
vineta('cuánto se vendió y cuánto se ganó aproximadamente;')
vineta('operaciones, ticket promedio, devoluciones y anulaciones;')
vineta('el desglose por método de pago, marcando cuáles pasan por el cajón;')
vineta('quién vendió cuánto, cajero por cajero;')
vineta('el detalle día por día.')
p('En «Productos e inventario» verás qué se vende más, qué capital está inmovilizado y qué falta '
  'reponer.')
rico([('Qué comprobar: ', True),
      ('los botones Excel y PDF de arriba a la derecha. Descargan el reporte del rango que tengas '
       'puesto en pantalla, listo para pasar al contador.', False)])

doc.add_heading('Ejercicio 13 — Cerrar la caja y hacer el arqueo', level=2)
rico([('Esto solo lo puede hacer ', False), ('admin', True), ('.', False)])
numerada('Ve a Caja y entra al turno abierto.')
numerada('Mira «Efectivo esperado»: es el monto inicial, más las ventas en efectivo, menos las devoluciones, más o menos los movimientos de caja.')
numerada('Pulsa «Cerrar caja» y escribe el efectivo realmente contado.')
rico([('Qué comprobar: ', True),
      ('sale el resumen de cierre imprimible, con la diferencia calculada y dos líneas de firma, '
       'una para el cajero y otra para el supervisor. Si pones un monto distinto al esperado, la '
       'diferencia aparece marcada: así se detecta un faltante el mismo día.', False)])
p('Prueba también «Registrar movimiento» antes de cerrar: sirve para el dinero que entra o sale '
  'del cajón sin ser una venta, como pagarle al del agua o retirar efectivo a mitad del turno.',
  cursiva=True)

doc.add_heading('Ejercicio 14 — Probar que los permisos funcionan', level=2)
p('Este es el ejercicio que más tranquiliza a un dueño.')
rico([('Entra como ', False), ('cajero1 / cajero123', True), (' e intenta:', False)])
vineta('ver los reportes → no aparecen en el menú;')
vineta('entrar a Productos, Usuarios o Empleados → tampoco;')
vineta('cerrar la caja → no existe el botón;')
vineta('escribir a mano la dirección /reportes/ventas en el navegador → sale «No tienes acceso».')
rico([('Qué comprobar: ', True),
      ('el bloqueo no es solo que el menú esconda las opciones. El sistema vuelve a verificar el '
       'permiso en el servidor cada vez, así que no se puede rodear escribiendo direcciones.', False)])

salto()

# ============================================================ 4. LOS MÓDULOS
doc.add_heading('4. Los módulos, uno por uno', level=1)

doc.add_heading('4.1 Mostrador', level=2)
tabla(['Módulo', 'Para qué sirve'],
      [['Inicio', 'El panel. Lo vendido hoy contra ayer, el gráfico de dos semanas, las últimas ventas y las alertas de reposición. Cada bloque aparece solo si el rol puede verlo.'],
       ['Punto de venta', 'La pantalla de cobro. Buscador con lector de código de barras, carrito, elección de cliente, descuento, medio de pago y cálculo del vuelto. Una venta puede repartirse entre varias formas de pago —parte por QR, el resto en efectivo—, y el cobro por QR genera el código con el importe ya puesto.'],
       ['Caja', 'El turno propio: apertura, ventas del turno, movimientos de efectivo y cierre con arqueo.'],
       ['Cajas del local', 'Los puestos de cobro físicos. Permite tener Caja 1, Caja 2, etc., cada una con su ubicación.']],
      anchos=[3.6, 11.9])

doc.add_heading('4.2 Ventas', level=2)
tabla(['Módulo', 'Para qué sirve'],
      [['Ventas', 'El historial completo, con filtros. Desde aquí se abre una venta para verla, anularla, devolverla o sustituir su comprobante.'],
       ['Comprobantes', 'Los documentos emitidos: recibos, facturas y notas de venta, con su serie, su correlativo y su estado.'],
       ['Clientes', 'Personas y empresas. El tipo de documento define qué comprobante recibe cada uno.'],
       ['Devoluciones', 'Las devoluciones registradas, con qué volvió al estante y qué no.']],
      anchos=[3.6, 11.9])

doc.add_heading('4.3 Reportes', level=2)
tabla(['Módulo', 'Para qué sirve'],
      [['Reporte de ventas', 'Por rango de fechas: montos, ganancia aproximada, ticket promedio, desglose por método de pago, ranking de cajeros y detalle diario. Exporta a Excel y PDF.'],
       ['Productos e inventario', 'Qué se vende más, valor del inventario al precio de compra y qué productos hay que reponer. También exporta.']],
      anchos=[3.6, 11.9])

doc.add_heading('4.4 Almacén', level=2)
tabla(['Módulo', 'Para qué sirve'],
      [['Inventario', 'La pantalla del depósito: qué hay, qué falta y qué se agotó. Desde cada fila se ingresa mercadería o se ajusta el stock por conteo, sin tener que entrar al producto.'],
       ['Compras', 'La factura del proveedor, entera: todas sus líneas de una vez, con el total para cuadrar contra el papel antes de guardar. Queda como documento y cada línea deja su kardex.'],
       ['Devoluciones a proveedor', 'Lo que se va de vuelta: fallado, vencido o equivocado. Guarda el motivo, la tanda que salió y en qué se quedó con el proveedor, incluido lo que todavía le debe reponer.'],
       ['Vencimientos', 'Qué caduca y cuándo, con el valor parado ahí. Desde cada fila se devuelve la tanda contra su factura o se da de baja lo ya vencido.'],
       ['Movimientos', 'El historial completo del almacén: cada entrada, salida y ajuste, con su responsable. Filtra por producto, tipo, responsable y fechas.']],
      anchos=[3.6, 11.9])

doc.add_heading('4.5 Catálogo', level=2)
tabla(['Módulo', 'Para qué sirve'],
      [['Productos', 'El catálogo: código, código de barras, nombre, foto, categoría, unidad de venta, empaque en el que se compra, proveedor, precio de compra, precio de venta y stock mínimo. Cada producto tiene su kardex.'],
       ['Categorías', 'Cómo se agrupan los productos. Ordena el catálogo y el mostrador.'],
       ['Unidades de medida', 'Unidad, paquete, kilo, litro. Define si el producto admite decimales.'],
       ['Proveedores', 'A quién se le compra. Se usa al registrar ingresos de mercadería.']],
      anchos=[3.6, 11.9])

doc.add_heading('4.6 Personal y accesos', level=2)
tabla(['Módulo', 'Para qué sirve'],
      [['Empleados', 'Las personas del negocio, con su documento y su cargo. Un empleado puede existir sin tener cuenta en el sistema.'],
       ['Cargos', 'Gerente, cajero, almacenero, etc.'],
       ['Usuarios', 'Las cuentas de acceso. Cada una pertenece a un empleado y tiene un rol. Se pueden bloquear sin borrarlas.'],
       ['Roles', 'Qué puede hacer cada rol. Los permisos se marcan uno por uno, así que se pueden armar roles a la medida del negocio.'],
       ['Mi perfil', 'Donde cada persona cambia su propia contraseña.']],
      anchos=[3.6, 11.9])

salto()

# =========================================================== 5. REGLAS
doc.add_heading('5. Las reglas del sistema', level=1)

p('Hay cosas que el sistema no deja hacer. No son fallas: son las reglas que hacen que los números '
  'cierren. Conviene conocerlas antes de probar, para no confundir un candado con un error.')

doc.add_heading('5.1 Sobre el dinero', level=2)
vineta('el sistema calcula los totales; el cajero no puede escribirlos a mano.',
       'Los totales no se escriben. ')
vineta('un cajero puede aplicar hasta 10 % de descuento. Más que eso requiere un rol con permiso.',
       'El descuento tiene tope. ')
vineta('solo el efectivo entra al arqueo. Tarjeta, transferencia, billetera digital y QR se '
       'registran como venta, pero no como dinero en el cajón. Si el cierre les reclamara ese '
       'dinero al cajero, saldría un faltante todos los días por algo que nunca estuvo ahí.',
       'No todo pasa por la caja. ')
vineta('una venta puede cobrarse entre varias formas de pago, y el vuelto se calcula sobre la '
       'línea en efectivo, no sobre el total. Si el cliente paga Bs 20 por QR y da un billete de '
       'Bs 20 por los Bs 15 que faltan, el vuelto es Bs 5.',
       'El pago se puede partir. ')
vineta('la venta por QR se registra recién cuando el pago está confirmado. Un cliente que se '
       'arrepiente no deja mercadería descontada ni comprobante emitido.',
       'Primero cobra, después vende. ')
vineta('las ventas anuladas no suman en ningún reporte ni en ningún arqueo.',
       'Lo anulado no cuenta. ')

doc.add_heading('5.2 Sobre el inventario', level=2)
vineta('no hay ninguna pantalla donde escribir «ahora hay 40». El stock cambia solo por una venta, '
       'una devolución, un ingreso de mercadería o un ajuste, y siempre queda el movimiento.',
       'El stock no se edita. ')
vineta('todo movimiento guarda el stock anterior y el resultante, así que la cuenta se puede '
       'rehacer desde el principio.',
       'El kardex es la verdad. ')
vineta('un ajuste sin explicación no se puede guardar.', 'Un ajuste exige motivo. ')
vineta('en los productos que llevan fecha, el stock está repartido en tandas y siempre se despacha '
       'la que vence antes. Por eso «me quedan 40 por vencer» quiere decir algo.',
       'Sale primero lo que caduca antes. ')
vineta('no se puede devolver más de lo que trajo esa factura, ni reponer más de lo que se devolvió. '
       'El sistema lleva la cuenta y te frena.',
       'Las devoluciones tienen tope. ')

doc.add_heading('5.3 Sobre los documentos', level=2)
vineta('un comprobante se anula o se sustituye, y la cadena queda visible. Nunca desaparece.',
       'Nada se borra. ')
vineta('el nombre y el precio quedan copiados en el documento. Cambiar el catálogo después no '
       'reescribe el pasado.',
       'Los precios se congelan. ')
vineta('empresa con NIT recibe factura; persona natural recibe recibo. El sistema lo decide solo.',
       'El tipo de documento sale del cliente. ')
vineta('cada serie lleva su propio correlativo, sin saltos.', 'La numeración es correlativa. ')

doc.add_heading('5.4 Sobre las personas', level=2)
vineta('no puede tener dos turnos abiertos al mismo tiempo.', 'Un cajero, un turno. ')
vineta('quien contó el dinero no puede ser quien aprueba el conteo.', 'El cierre lo hace otro. ')
vineta('las cuentas se bloquean, no se borran, para que el historial siga teniendo responsable.',
       'Nadie se borra. ')
vineta('quién entró, quién anuló, quién ajustó el stock y cuándo.', 'Todo queda registrado: ')

salto()

# =========================================================== 6. DATOS DEMO
doc.add_heading('6. Los datos que ya trae la demo', level=1)

p('Para que el sistema se pueda recorrer con contenido y no con pantallas vacías, viene cargado '
  'con más de tres meses de operación, desde principios de junio hasta hoy.')

tabla(['Qué', 'Cuánto'],
      [['Ventas registradas', '1.089, repartidas en tres meses y medio'],
       ['Comprobantes emitidos', '1.089 (recibos y facturas)'],
       ['Turnos de caja cerrados', '87, con su arqueo y su diferencia'],
       ['Devoluciones de cliente', '25'],
       ['Compras al proveedor', '8 facturas, repartidas en las últimas seis semanas'],
       ['Devoluciones al proveedor', '5 — una por cada motivo, y dos esperando reposición'],
       ['Tandas con fecha de vencimiento', '34, algunas ya vencidas y otras por vencer'],
       ['Movimientos de inventario', '2.617'],
       ['Productos', '14, todos con foto real'],
       ['Clientes con historial', '6 (4 personas y 2 empresas)'],
       ['Empleados', '3'],
       ['Proveedores', '2'],
       ['Cajas del local', '1']],
      anchos=[6.0, 9.5])

p('El catálogo trae además cientos de productos bolivianos reales —arroz, aceites, gaseosas, '
  'cervezas— que están descatalogados en esta demostración porque todavía no tienen foto. Se ven '
  'poniendo el filtro «Estado» en «Descatalogados», dentro de Catálogo → Productos.', cursiva=True)

doc.add_heading('6.1 El catálogo cargado', level=2)
p('Todos con marcas bolivianas y foto real. La columna «Vence» dice cuáles llevan control por '
  'tandas: los comestibles sí, la limpieza y la higiene no, porque el detergente no caduca y '
  'pedirle una fecha cada vez que llega es la forma más rápida de que alguien escriba cualquier '
  'cosa con tal de seguir.')

tabla(['Código', 'Producto', 'Precio', 'Stock', 'Mínimo', 'Vence'],
      [['P-1001', 'Chocolate Breick Azúcar 0% con Leche 100 g', 'Bs 12,00', '137', '6', 'Sí'],
       ['P-1002', 'Chocolate El Ceibo con Pasas de Uva 100 g', 'Bs 18,00', '62', '6', 'Sí'],
       ['P-1003', 'Chocolate Sublime Clásico con Maní 26 g', 'Bs 3,50', '147', '12', 'Sí'],
       ['P-1004', 'Bombones Para Ti con Crema 150 g', 'Bs 35,00', '107', '3', 'Sí'],
       ['P-1005', 'Chizitos La Estrella Queso 65 g', 'Bs 3,50', '262', '12', 'Sí'],
       ['P-1006', 'Jabón Lux Rosas Francesas 125 g', 'Bs 5,50', '66', '9', 'No'],
       ['P-1007', 'Papel Higiénico Elite Dúo x4 rollos', 'Bs 14,00', '70', '6', 'No'],
       ['P-1008', 'Shampoo Sedal Rizos Definidos 340 ml', 'Bs 28,00', '153', '4', 'No'],
       ['P-1009', 'Pasta Dental Colgate Triple Acción 90 g', 'Bs 14,50', '19', '6', 'No'],
       ['P-1010', 'Detergente Patito Limón 1600 g', 'Bs 22,00', '122', '4', 'No'],
       ['P-1011', 'Lavavajillas OLA Limón 800 ml', 'Bs 14,00', '72', '6', 'No'],
       ['P-1012', 'Crema Lavavajilla Sapolio Limón 360 g', 'Bs 11,00', '169', '6', 'No'],
       ['P-1013', 'Papaya Salvietti 2 L', 'Bs 12,00', '128', '9', 'Sí'],
       ['P-1014', 'Papaya Salvietti 500 ml', 'Bs 5,00', '114', '12', 'Sí']],
      anchos=[1.8, 7.0, 2.0, 1.6, 1.6, 1.5])

p('El stock que ves aquí es el del día en que se armó esta demostración: al recorrer los '
  'ejercicios lo vas a mover, y eso está bien.', cursiva=True)

doc.add_heading('6.2 Los clientes de prueba', level=2)
tabla(['Cliente', 'Tipo', 'Documento', 'Recibe'],
      [['Rosa Mamani Quispe', 'Persona', 'CI 4821567', 'Recibo'],
       ['Carlos Villarroel Soto', 'Persona', 'CI 6193842', 'Recibo'],
       ['Elena Choque Apaza', 'Persona', 'CI 3745129', 'Recibo'],
       ['Juan Ticona Flores', 'Persona', 'CI 5028471', 'Recibo'],
       ['Pensión Doña Martha S.R.L.', 'Empresa', 'NIT 1023456789', 'Factura'],
       ['Comedor Popular El Sabor Ltda.', 'Empresa', 'NIT 1098765432', 'Factura']],
      anchos=[6.2, 2.4, 3.9, 3.0])

p('Quien no se identifica se cobra como «Cliente varios» y recibe recibo. Estos seis son los que '
  'tienen historial; el catálogo de clientes trae además cinco fichas de la instalación base, sin '
  'ventas todavía.', cursiva=True)

doc.add_heading('6.3 Medios de pago', level=2)
tabla(['Medio de pago', '¿Entra al arqueo de caja?'],
      [['Efectivo', 'Sí — es el único que queda en el cajón'],
       ['Tarjeta débito/crédito', 'No'],
       ['Transferencia bancaria', 'No'],
       ['Billetera digital', 'No'],
       ['Pago por QR', 'No — el dinero cae en la cuenta del banco']],
      anchos=[7.0, 8.5])
p('Una misma venta puede usar varios de estos a la vez: el sistema reparte el importe y solo suma '
  'al arqueo la parte en efectivo.', cursiva=True)

salto()

# ======================================================= 7. LÍMITES
doc.add_heading('7. Qué tener en cuenta de esta versión', level=1)

p('La demo está publicada en un hosting gratuito. El sistema es el mismo que iría al servidor '
  'definitivo, pero el alojamiento impone algunos límites que conviene conocer antes de la '
  'presentación.')

doc.add_heading('7.1 Del alojamiento', level=2)
vineta('el hosting gratuito corta el sitio si se superan muchas visitas en un día. Se recupera '
       'solo, al día siguiente.',
       'Hay un tope de visitas diarias. ')
vineta('en el servidor definitivo el respaldo corre solo, todas las noches. Aquí habría que '
       'exportarlo a mano si se quisiera conservar algo.',
       'No hay respaldo automático. ')
vineta('la primera carga del día puede tardar unos segundos más que las siguientes.',
       'Puede ir algo más lento. ')

doc.add_heading('7.2 Del contenido', level=2)
vineta('el nombre, el NIT, la dirección y el teléfono que salen en los comprobantes son de '
       'ejemplo. Se reemplazan por los del negocio real.',
       'Los datos del negocio son de relleno. ')
vineta('en esta demo la tasa está en 0 %, así que la columna «Impuesto» dice «exonerado». El '
       'cálculo está hecho y funciona: se activa poniendo la tasa que corresponda.',
       'El impuesto está en cero. ')
vineta('el nombre del negocio, la tasa de impuesto y el tope de descuento se guardan en la base '
       'de datos, pero todavía no hay una pantalla para editarlos desde el sistema.',
       'Falta la pantalla de configuración. ')

doc.add_heading('7.3 Lo que sí está resuelto', level=2)
p('Para que quede claro qué es límite del alojamiento y qué es del sistema:')
vineta('probado con varios cajeros cobrando al mismo tiempo sobre los mismos productos, sin que '
       'se cruce el stock.',
       'Ventas simultáneas: ')
vineta('probado un desastre completo, y la restauración devolvió todo, fotos incluidas.',
       'Respaldo y recuperación: ')
vineta('239 pruebas automáticas que se corren con cada cambio.', 'Pruebas: ')
vineta('cada acción sensible queda registrada con su responsable y su fecha.', 'Auditoría: ')

salto()

# ======================================================= 8. SI ALGO FALLA
doc.add_heading('8. Si algo no sale como esperabas', level=1)

tabla(['Lo que ves', 'Qué pasa'],
      [['«No tienes acceso»',
        'La cuenta con la que entraste no tiene ese permiso. Es el comportamiento correcto: entra como admin para esa acción.'],
       ['«No tienes una caja abierta»',
        'No se puede cobrar sin turno. Ve a Caja y ábrelo.'],
       ['No aparece el botón «Cerrar caja»',
        'Estás como cajero. El cierre lo hace el administrador, a propósito.'],
       ['«Solo se puede anular una venta completada»',
        'Esa venta ya tuvo una devolución. Se corrige por devolución, no por anulación.'],
       ['El sitio no carga',
        'Puede ser el tope diario de visitas del hosting gratuito. Vuelve a intentar más tarde.'],
       ['La sesión se cerró sola',
        'Por seguridad la sesión caduca a las dos horas de inactividad. Vuelve a entrar.']],
      anchos=[5.4, 10.1])

doc.add_heading('Para cerrar', level=2)
p('Cualquier duda sobre el funcionamiento, o cualquier cosa que quieras que el sistema haga y '
  'todavía no haga, anótala mientras pruebas y la revisamos juntos.')

doc.save(r'C:\Universidad\vivecoding\SistemaVentas\despliegue-demo\Manual-Sistema-de-Ventas.docx')
print('listo')
