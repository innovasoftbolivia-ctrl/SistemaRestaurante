# -*- coding: utf-8 -*-
"""Manual de funcionamiento del Sistema de Ventas (.docx)."""

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
    par = doc.add_paragraph()
    for tramo in partes:
        run = par.add_run(tramo[0])
        run.bold = tramo[1]
    return par


def vineta(texto, negrita_inicial=None):
    par = doc.add_paragraph(style='List Bullet')
    if negrita_inicial:
        par.add_run(negrita_inicial).bold = True
    par.add_run(texto)
    return par


def numerada(texto, negrita_inicial=None):
    par = doc.add_paragraph(style='List Number')
    if negrita_inicial:
        par.add_run(negrita_inicial).bold = True
    par.add_run(texto)
    return par


def formula(texto, junto=False):
    """`junto` encadena varias líneas como un solo bloque, sin aire entre ellas."""
    par = doc.add_paragraph()
    par.paragraph_format.left_indent = Cm(0.8)
    par.paragraph_format.space_before = Pt(0 if junto else 4)
    par.paragraph_format.space_after = Pt(0 if junto else 8)
    run = par.add_run(texto)
    run.font.name = 'Consolas'
    run.font.size = Pt(9.5)
    run.font.color.rgb = AZUL
    run.bold = True
    return par


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


def aviso(titulo, texto, color='FEF3C7'):
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
    sombrear(celda, color)
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
r = lin.add_run('Manual de funcionamiento')
r.bold = True
r.font.size = Pt(16)

lin = doc.add_paragraph()
lin.alignment = WD_ALIGN_PARAGRAPH.CENTER
r = lin.add_run('Cómo trabaja el sistema: módulos, campos, estados,')
r.font.size = Pt(11)
r.font.color.rgb = GRIS

lin = doc.add_paragraph()
lin.alignment = WD_ALIGN_PARAGRAPH.CENTER
r = lin.add_run('reglas y fórmulas de cálculo')
r.font.size = Pt(11)
r.font.color.rgb = GRIS

lin = doc.add_paragraph()
lin.alignment = WD_ALIGN_PARAGRAPH.CENTER
lin.paragraph_format.space_before = Pt(50)
r = lin.add_run('Documento de referencia')
r.font.size = Pt(10)
r.font.color.rgb = GRIS

salto()

# ================================================================== 1. CONTENIDO
doc.add_heading('Contenido', level=1)
tabla(['Capítulo', 'De qué trata'],
      [['1. Cómo está organizado', 'Los seis bloques del sistema y qué resuelve cada uno.'],
       ['2. Acceso y seguridad', 'Cuentas, roles, permisos, bloqueo por intentos y auditoría.'],
       ['3. El ciclo de una venta', 'Todo lo que ocurre entre abrir la caja y cerrarla.'],
       ['4. Los módulos en detalle', 'Campo por campo, pantalla por pantalla.'],
       ['5. Los estados', 'Todos los estados posibles y cómo se pasa de uno a otro.'],
       ['6. Cómo se calculan los números', 'Las fórmulas exactas de cada cifra que muestra el sistema.'],
       ['7. Las reglas del sistema', 'Lo que el sistema no deja hacer, y por qué.'],
       ['8. Qué queda registrado', 'La trazabilidad de cada operación.']],
      anchos=[5.2, 10.3])

salto()

# ============================================================ 1. ORGANIZACIÓN
doc.add_heading('1. Cómo está organizado', level=1)

p('El sistema trabaja sobre seis bloques. Cada uno resuelve una parte del negocio y se apoya en '
  'los anteriores.')

tabla(['Bloque', 'Módulos', 'Qué resuelve'],
      [['Mostrador', 'Inicio, Punto de venta, Caja, Cajas del local',
        'Cobrar y controlar el efectivo del turno.'],
       ['Ventas', 'Ventas, Comprobantes, Clientes, Devoluciones',
        'El historial de lo cobrado, los documentos emitidos y sus correcciones.'],
       ['Catálogo', 'Productos, Categorías, Unidades, Proveedores',
        'Qué se vende y a qué precio.'],
       ['Almacén', 'Inventario, Movimientos',
        'Cuánto queda, qué falta reponer y de dónde salió cada unidad.'],
       ['Reportes', 'Reporte de ventas, Productos e inventario',
        'Qué pasó en un período y qué conviene hacer.'],
       ['Personal y accesos', 'Empleados, Cargos, Usuarios, Roles, Perfil',
        'Quién trabaja, quién entra y qué puede hacer cada uno.']],
      anchos=[3.4, 5.4, 6.7])

doc.add_heading('1.1 La idea de fondo', level=2)

p('Tres decisiones explican casi todo el comportamiento del sistema:')

rico([('Nada se borra. ', True),
      ('Una venta equivocada se anula, un comprobante se sustituye, un stock mal contado se '
       'ajusta. En los tres casos queda el registro anterior, el nuevo y el motivo. El historial '
       'se puede leer hacia atrás sin huecos.', False)])

rico([('Los números no se escriben, se calculan. ', True),
      ('Los totales de una venta, el efectivo esperado de un turno y el stock de un producto los '
       'produce el sistema a partir de sus movimientos. No hay ninguna pantalla donde teclear el '
       'resultado directamente.', False)])

rico([('Cada operación tiene responsable. ', True),
      ('Toda venta pertenece a un turno, todo turno a una persona, y todo movimiento de stock o '
       'de dinero guarda quién lo hizo y cuándo.', False)])

salto()

# ============================================================ 2. ACCESO
doc.add_heading('2. Acceso y seguridad', level=1)

doc.add_heading('2.1 Entrar al sistema', level=2)

p('Se entra con usuario y contraseña. El usuario no es el nombre de la persona: es un '
  'identificador corto, en minúsculas, que admite letras, números, punto, guion y guion bajo, '
  'de 3 a 40 caracteres.')

tabla(['Regla', 'Valor'],
      [['Intentos fallidos permitidos', '5'],
       ['Bloqueo tras superarlos', '60 segundos, por usuario y dirección de red'],
       ['Duración de la sesión', '120 minutos de inactividad'],
       ['Longitud mínima de contraseña', '8 caracteres'],
       ['Cuenta desactivada', 'No puede entrar, pero conserva todo su historial']],
      anchos=[7.0, 8.5])

p('El sistema tarda lo mismo en rechazar un usuario que no existe que uno con la contraseña '
  'equivocada. Es deliberado: si respondiera más rápido cuando el usuario no existe, se podría '
  'averiguar desde fuera qué cuentas son reales.', espacio=10)

doc.add_heading('2.2 Roles y permisos', level=2)

p('Un rol es un conjunto de permisos. Cada cuenta tiene un rol, y el rol decide qué módulos ve '
  'y qué acciones puede ejecutar. Los permisos se marcan uno por uno, así que se pueden armar '
  'roles nuevos a la medida del negocio sin tocar el programa.')

p('Estos son los trece permisos del sistema:')

tabla(['Permiso', 'Habilita'],
      [['ventas.registrar', 'Entrar al punto de venta y cobrar. También crear y editar clientes.'],
       ['ventas.descuento', 'Aplicar descuentos por encima del tope configurado.'],
       ['ventas.anular', 'Anular una venta y sustituir un comprobante.'],
       ['devoluciones.registrar', 'Registrar la devolución de una venta.'],
       ['caja.abrir', 'Abrir el turno y registrar ingresos o egresos de efectivo.'],
       ['caja.cerrar', 'Cerrar el turno y hacer el arqueo.'],
       ['productos.gestionar', 'Catálogo: productos, categorías, unidades y proveedores.'],
       ['inventario.ingresar', 'Registrar la entrada de mercadería del proveedor.'],
       ['inventario.ajustar', 'Corregir el stock por conteo físico, merma o rotura.'],
       ['reportes.ver', 'Los reportes, y la lectura de ventas, comprobantes y devoluciones.'],
       ['empleados.gestionar', 'Empleados y cargos.'],
       ['usuarios.gestionar', 'Cuentas de acceso y roles.'],
       ['configuracion.editar', 'Las cajas físicas del local.']],
      anchos=[4.8, 10.7])

p('El sistema trae tres roles armados:')

tabla(['Rol', 'Permisos que tiene'],
      [['Administrador', 'Los trece. Es el único que cierra caja, anula ventas, sustituye comprobantes y registra devoluciones.'],
       ['Cajero', 'ventas.registrar y caja.abrir. Cobra y abre su turno; no cierra, no anula, no ve reportes ni catálogo.'],
       ['Almacenero', 'productos.gestionar, inventario.ingresar, inventario.ajustar y reportes.ver. Maneja el catálogo y el stock; no cobra.']],
      anchos=[3.2, 12.3])

aviso('Cómo se aplica el permiso:',
      'no basta con esconder la opción del menú. El sistema vuelve a verificar el permiso en el '
      'servidor cada vez que se pide una pantalla o se envía un formulario. Escribir la dirección '
      'a mano en el navegador devuelve «no tienes acceso», no la pantalla.')

doc.add_heading('2.3 Dos candados propios de la cuenta', level=2)

vineta('el sistema ignora cualquier intento de cambiar el rol propio o de desactivarse a uno mismo. '
       'Sin esto, un administrador distraído podría dejar el sistema sin ningún administrador.',
       'Nadie se degrada a sí mismo. ')
vineta('quien abre un turno de caja no puede cerrarlo si su rol no tiene caja.cerrar. En la '
       'configuración de fábrica, el cajero abre y el administrador cierra.',
       'El cierre lo hace otro. ')

salto()

# ======================================================= 3. CICLO DE UNA VENTA
doc.add_heading('3. El ciclo de una venta', level=1)

p('Esta es la secuencia completa de un día de mostrador, con lo que hace el sistema en cada paso.')

doc.add_heading('Paso 1 — Se abre el turno', level=2)
p('El cajero elige la caja física y declara el efectivo con el que empieza. El sistema crea una '
  'sesión de caja en estado ABIERTA y la asocia a esa persona.')
p('A partir de aquí, toda venta que registre se imputa a ese turno.')

doc.add_heading('Paso 2 — Se arma el carrito', level=2)
p('Se busca el producto por nombre, por código interno o por código de barras, o se pulsa su '
  'tarjeta. Cada línea guarda producto y cantidad.')
rico([('El precio no viaja desde el navegador. ', True),
      ('Aunque la pantalla lo muestre, el sistema lo vuelve a leer del catálogo al momento de '
       'grabar. Así, una pantalla vieja o manipulada no puede cobrar un precio distinto al '
       'vigente.', False)])
p('Si la unidad de medida del producto no admite decimales, la cantidad tiene que ser entera: '
  '«se vende por unidad entera».')

doc.add_heading('Paso 3 — Se elige el cliente', level=2)
p('Hay tres caminos:')
vineta('la venta sale a nombre del cliente genérico y emite recibo;', 'Sin identificar: ')
vineta('emite recibo a su nombre;', 'Persona natural: ')
vineta('emite factura, con su razón social, su NIT y su dirección fiscal.', 'Persona jurídica: ')
p('El sistema elige el tipo de documento a partir del tipo de persona del cliente. El cajero no '
  'tiene que decidirlo.')

doc.add_heading('Paso 4 — Se cobra', level=2)
p('Se elige uno o varios medios de pago. Una venta puede pagarse mitad en efectivo y mitad con '
  'tarjeta, o una parte por QR y el resto en efectivo: se agregan tantas formas de pago como '
  'hagan falta.')
vineta('solo una forma de pago puede quedar sin importe: el sistema le asigna el resto.')
vineta('en efectivo se indica cuánto entregó el cliente, y el sistema calcula el vuelto.')
vineta('el vuelto se calcula sobre SU línea, no sobre el total de la venta. Si el cliente paga '
       'Bs 20 por QR y entrega un billete de Bs 20 por los Bs 15 que faltan, el vuelto es Bs 5.')
vineta('si lo recibido es menor que lo que hay que cobrar, la venta no se registra.')
vineta('si una de las líneas es un cobro por QR, no se puede cobrar hasta que ese pago esté '
       'confirmado. El botón queda bloqueado y dice por qué.')
p('El descuento, si lo hay, se aplica sobre el subtotal y nunca puede superarlo.')

doc.add_heading('Paso 5 — El sistema graba la venta', level=2)
p('Todo lo siguiente ocurre en una sola operación: o pasa entero, o no pasa nada. Si algo falla a '
  'la mitad, no queda una venta cobrada sin stock descontado ni un stock descontado sin venta.')
numerada('Verifica que haya stock suficiente de cada producto.')
numerada('Calcula el importe, el impuesto y el total de cada línea.')
numerada('Suma el subtotal, aplica el descuento y calcula el total de la venta.')
numerada('Descuenta el stock de cada producto y escribe un movimiento en el kardex, con el stock anterior y el resultante.')
numerada('Toma el siguiente número de la serie que corresponda y emite el comprobante.')
numerada('Copia dentro del comprobante el nombre y el precio de cada producto, y los datos del cliente.')
p('Ese último punto es el que hace que el documento no cambie nunca: si mañana sube el precio o '
  'se corrige el nombre del producto, el comprobante emitido hoy sigue diciendo lo mismo.')

doc.add_heading('Paso 6 — Correcciones, si hacen falta', level=2)
tabla(['Situación', 'Qué se usa', 'Efecto'],
      [['El cliente pide factura después de haber recibido un recibo',
        'Sustituir comprobante',
        'Se emite el documento nuevo; el anterior queda SUSTITUIDO y los dos siguen visibles.'],
       ['El cliente devuelve parte o todo lo que llevó',
        'Registrar devolución',
        'Sale el dinero del cajón; el stock vuelve solo si la mercadería está en condiciones.'],
       ['La venta no debió existir',
        'Anular venta',
        'Vuelve todo el stock, se anula el comprobante y el monto deja de contar.']],
      anchos=[5.0, 3.6, 6.9])

doc.add_heading('Paso 7 — Se cierra el turno', level=2)
p('El administrador cuenta el efectivo del cajón y lo declara. El sistema ya sabe cuánto debería '
  'haber, compara, y guarda la diferencia. La sesión pasa a CERRADA y sale el resumen imprimible '
  'con dos líneas de firma.')
p('Una caja cerrada no admite más movimientos: ni ventas, ni devoluciones, ni ingresos ni egresos.')

salto()

# ======================================================= 4. MÓDULOS
doc.add_heading('4. Los módulos en detalle', level=1)

# ---- Inicio
doc.add_heading('4.1 Inicio', level=2)
p('El panel de entrada. Arma sus bloques según lo que el rol puede ver, así que dos personas '
  'distintas ven pantallas distintas.')
tabla(['Bloque', 'Qué muestra', 'Lo ve'],
      [['Mi turno', 'Si hay caja abierta y cuántas ventas lleva.', 'Quien puede vender'],
       ['Lo que llevo vendido hoy', 'Monto y operaciones a nombre propio.', 'Quien puede vender'],
       ['Vendido hoy / Operaciones / Ticket promedio', 'El día completo del negocio, comparado con ayer.', 'Quien ve reportes'],
       ['Últimas dos semanas', 'Gráfico diario; los días sin ventas se dibujan en cero.', 'Quien ve reportes'],
       ['Últimas ventas', 'Las ocho más recientes con su comprobante y su cajero.', 'Quien ve reportes'],
       ['Reponer', 'Productos que llegaron a su stock mínimo.', 'Catálogo o reportes']],
      anchos=[5.0, 7.5, 3.0])

# ---- POS
doc.add_heading('4.2 Punto de venta', level=2)
p('La pantalla de cobro. Está pensada para trabajar sin soltar el lector de código de barras: el '
  'cursor arranca en el buscador y vuelve ahí después de cada producto.')

doc.add_heading('Lo que compone una venta', level=3)
tabla(['Campo', 'Obligatorio', 'Reglas'],
      [['Productos', 'Sí', 'Al menos uno. Cantidad mayor que cero. Debe haber stock. Un producto descatalogado no se puede vender.'],
       ['Cliente', 'No', 'Si se omite, la venta sale al cliente genérico con recibo.'],
       ['Descuento', 'No', 'No puede superar el subtotal. Por encima del tope configurado exige el permiso ventas.descuento.'],
       ['Forma de pago', 'Sí', 'Una o varias. Solo una puede quedar sin importe. Cada importe mayor que cero.'],
       ['Efectivo recibido', 'Si paga en efectivo', 'No puede ser menor que el importe a cobrar.'],
       ['Observación', 'No', 'Hasta 255 caracteres.']],
      anchos=[3.4, 2.6, 9.5])

doc.add_heading('Cobro por QR', level=3)
p('El sistema genera un código QR con el importe ya incluido: el cliente escanea y paga '
  'exactamente lo que debe, sin teclear el monto. El código se genera contra el CARRITO, no '
  'contra una venta.')
p('Ese detalle es el que ordena todo lo demás. La venta se registra recién cuando el pago está '
  'confirmado, porque una venta creada antes de cobrar ya descontó stock y ya emitió comprobante: '
  'si el cliente entonces se arrepiente, quedaría mercadería descontada que nadie se llevó.')
tabla(['Situación', 'Qué hace el sistema'],
      [['El cobro se genera', 'Queda PENDIENTE, con su importe y un vencimiento (10 minutos por omisión). Todavía no existe ninguna venta.'],
       ['El cliente paga', 'Con banco conectado, el aviso del banco lo marca PAGADO solo. Sin banco, lo confirma el cajero con «Ya me pagó», y su nombre queda en la bitácora.'],
       ['Se cobra la venta', 'El cobro se ata a esa venta y no puede volver a usarse en otra. El importe del cobro tiene que coincidir con el de la línea.'],
       ['El cliente se arrepiente', 'Se cancela el cobro y no queda venta, ni stock descontado, ni comprobante emitido.'],
       ['Pasa el tiempo', 'El cobro vence solo y deja de servir para pagar.']],
      anchos=[4.6, 10.9])
aviso('Sin convenio bancario, el pago no llega solo',
      'Mientras no haya acuerdo con un banco, el QR se genera igual —es real y escaneable, con su '
      'importe— pero nadie avisa que el cobro entró: lo confirma el cajero, y la pantalla lo dice '
      'con todas las letras. Está hecho así a propósito: un simulador que se pagara solo daría la '
      'impresión de que el cobro funciona de punta a punta. Confirmar a mano sigue haciendo falta '
      'incluso con el banco conectado, para el día que su servicio se caiga y el cajero tenga que '
      'cobrar mirando el comprobante en el teléfono del cliente.')

doc.add_heading('Atajos de teclado', level=3)
tabla(['Tecla', 'Acción'],
      [['Enter', 'Agrega al carrito el primer resultado de la búsqueda.'],
       ['F2', 'Vuelve el cursor al buscador.'],
       ['F4', 'Cobra.']],
      anchos=[3.0, 12.5])

# ---- Caja
doc.add_heading('4.3 Caja', level=2)
p('El turno de trabajo. Es la unidad que permite responder «cuánto dinero debería haber en este '
  'cajón, ahora mismo».')

doc.add_heading('Apertura', level=3)
p('Pide la caja física y el monto inicial. Una persona no puede tener dos turnos abiertos al mismo '
  'tiempo, y una caja física no puede estar abierta por dos personas a la vez.')

doc.add_heading('Movimientos de caja', level=3)
p('Dinero que entra o sale del cajón sin ser una venta: pagar al proveedor del agua, retirar '
  'efectivo a mitad del turno, reponer sencillo. Cada movimiento pide tipo (ingreso o egreso), '
  'concepto y monto, y queda con su responsable.')

doc.add_heading('Cierre y arqueo', level=3)
p('El administrador declara el efectivo contado. El sistema compara contra lo esperado y guarda la '
  'diferencia; el resumen imprimible sale con las dos firmas.')
aviso('Un detalle fino del arqueo:',
      'de una devolución solo sale del cajón la parte que en su día entró en efectivo. Si la venta '
      'se pagó mitad en efectivo y mitad con tarjeta, al devolverla solo la mitad en efectivo se '
      'descuenta del cajón: lo demás se reembolsa por su propio medio.')

# ---- Cajas del local
doc.add_heading('4.4 Cajas del local', level=2)
p('Los puestos de cobro físicos: Caja 1, Caja 2, la del fondo. Cada una con su nombre y su '
  'ubicación. Sirve cuando el local tiene más de un mostrador y hace falta saber en cuál se cobró.')
p('Una caja que ya tiene turnos registrados no se borra: se desactiva. Así el historial sigue '
  'sabiendo dónde ocurrió cada venta.')

# ---- Ventas
doc.add_heading('4.5 Ventas', level=2)
p('El historial completo, con filtros por fecha, cajero, estado y cliente. Desde el detalle de una '
  'venta se llega a todas sus acciones: imprimir, sustituir el comprobante, devolver y anular.')
p('El detalle muestra las líneas tal como se cobraron, el cliente, la forma de pago con su vuelto, '
  'y la cadena de documentos emitidos.')

# ---- Comprobantes
doc.add_heading('4.6 Comprobantes', level=2)
p('Los documentos emitidos. El sistema maneja tres tipos, cada uno con su serie y su numeración '
  'propia y correlativa:')
tabla(['Tipo', 'Serie', 'A quién se emite', 'Exige'],
      [['Factura', 'F001', 'Persona jurídica', 'Cliente registrado, con documento y dirección'],
       ['Recibo', 'R001', 'Persona natural', 'Nada: sirve para la venta al paso'],
       ['Nota de venta', 'NV01', 'Ambas', 'Nada']],
      anchos=[3.0, 2.2, 4.4, 5.9])

doc.add_heading('Sustitución', level=3)
p('Reemplaza un comprobante por otro sin borrar el primero. Se usa cuando el documento salió mal: '
  'el cliente pide factura después de recibir un recibo, o los datos fiscales estaban equivocados.')
vineta('el comprobante tiene que estar vigente (EMITIDO); uno ya anulado o ya sustituido, no.')
vineta('la venta no puede estar anulada ni devuelta.')
vineta('tiene que estar dentro del plazo configurado, contado desde la fecha de la venta.')
p('El resultado son dos documentos encadenados: el nuevo dice a cuál reemplaza, y el viejo queda '
  'marcado como SUSTITUIDO con su fecha y su motivo.')

# ---- Devoluciones
doc.add_heading('4.7 Devoluciones', level=2)
p('El cliente trae de vuelta parte o todo lo que compró. Se registra línea por línea, indicando la '
  'cantidad de cada producto.')
p('Por cada línea hay que decidir una cosa más: si la mercadería vuelve al estante o no.')
tabla(['Situación', 'Dinero', 'Stock'],
      [['El producto está en condiciones', 'Se devuelve', 'Sube'],
       ['El producto vino dañado o abierto', 'Se devuelve', 'No sube']],
      anchos=[7.5, 4.0, 4.0])
p('Otras reglas del módulo:')
vineta('no se puede devolver más de lo que se vendió, ni más de lo que queda pendiente de una devolución anterior;')
vineta('se devuelve el precio y la tasa de impuesto del día de la venta, no los de hoy;')
vineta('hace falta una caja abierta: el dinero sale del cajón y tiene que quedar imputado a un turno;')
vineta('el motivo es obligatorio y necesita al menos cinco caracteres.')
p('Según cuánto se devolvió, la venta pasa a DEVUELTA_PARCIAL o a DEVUELTA.')
p('Esta versión no emite nota de crédito: la devolución revierte la operación y queda registrada, '
  'pero no genera un documento fiscal propio.', cursiva=True)

# ---- Clientes
doc.add_heading('4.8 Clientes', level=2)
p('El tipo de persona cambia qué campos son obligatorios, porque cambia qué documento se le puede '
  'emitir.')
tabla(['Campo', 'Persona natural', 'Persona jurídica'],
      [['Tipo de documento', 'CI, CE o PAS', 'NIT, obligatoriamente'],
       ['Número de documento', 'Opcional', 'Obligatorio'],
       ['Nombres y apellidos', 'Obligatorios', 'No aplica'],
       ['Razón social', 'No aplica', 'Obligatoria'],
       ['Dirección', 'Opcional', 'Obligatoria (la factura la exige)'],
       ['Nombre comercial, representante legal', 'No aplica', 'Opcionales'],
       ['Teléfono, correo, fecha de nacimiento', 'Opcionales', 'Opcionales'],
       ['Recibe', 'Recibo', 'Factura']],
      anchos=[5.4, 5.0, 5.1])
p('No puede haber dos clientes con el mismo tipo y número de documento. Un cliente con ventas '
  'registradas no se borra: se desactiva.')

# ---- Productos
doc.add_heading('4.9 Productos', level=2)
tabla(['Campo', 'Regla'],
      [['Código interno', 'Obligatorio y único. Letras, números, punto, guion y guion bajo.'],
       ['Código de barras', 'Opcional y único. Solo dígitos.'],
       ['Nombre', 'Obligatorio, hasta 120 caracteres.'],
       ['Categoría y unidad de venta', 'Obligatorias. La unidad de venta es en la que se cuenta el stock.'],
       ['Empaque de compra', 'Opcional. Nombre («Caja») y cuántas unidades trae («24»), mínimo 2. Van juntos o no van.'],
       ['Proveedor', 'Opcional.'],
       ['Precio de compra y de venta', 'Obligatorios. Se guardan SIN impuesto.'],
       ['Afecto a impuesto', 'Define si al precio se le suma la tasa vigente.'],
       ['Stock mínimo', 'Obligatorio. Dispara la alerta de reposición.'],
       ['Foto', 'Opcional. JPG, PNG o WEBP, hasta 2 MB.'],
       ['Activo', 'Un producto inactivo no se puede vender, pero sigue en el historial.']],
      anchos=[5.0, 10.5])

aviso('El stock no está en esta lista, y es a propósito:',
      'no hay ningún campo donde escribir cuántas unidades hay. El stock solo cambia por un '
      'movimiento, y todo movimiento queda en el kardex con su responsable y su motivo. Para '
      'sumarle unidades a un producto que ya existe se usa el módulo de Inventario, no se '
      'vuelve a dar de alta: el sistema rechaza el código repetido y ofrece el atajo.')

doc.add_heading('Las cuatro formas de mover el stock', level=3)
tabla(['Movimiento', 'Origen', 'Efecto', 'Quién'],
      [['Venta', 'El cobro en el mostrador', 'Baja', 'Cajero o administrador'],
       ['Devolución o anulación', 'La corrección de una venta', 'Sube', 'Administrador'],
       ['Ingreso de mercadería', 'La compra al proveedor', 'Sube', 'Almacenero o administrador'],
       ['Ajuste', 'El conteo físico, la merma, la rotura', 'Sube o baja', 'Almacenero o administrador']],
      anchos=[3.8, 4.4, 3.2, 4.1])

p('El ingreso de mercadería pide cantidad y, opcionalmente, proveedor, número de guía o factura y '
  'costo unitario. El ajuste pide el stock realmente contado y un motivo obligatorio: el sistema '
  'calcula solo la diferencia.')

doc.add_heading('Cuando se compra por caja y se vende por unidad', level=3)
p('Un producto con empaque declarado no pide un total al ingresar: pide cuántos empaques enteros '
  'llegaron y cuántas unidades vinieron sueltas. El sistema multiplica, muestra el resultado antes '
  'de guardar y suma ese número al stock.')
aviso('El stock no se cuenta en cajas:',
      'se cuenta siempre en la unidad de venta. El empaque es una equivalencia de entrada, no una '
      'segunda unidad de stock. Por eso el mostrador puede seguir despachando una unidad suelta de '
      'un producto que llegó por caja, y los reportes, las alertas de mínimo y el kardex siguen '
      'hablando el mismo idioma que antes.')
p('El desglose se guarda junto al motivo del movimiento —«3 cajas de 24 + 5 sueltas»— porque la '
  'factura del proveedor viene expresada en cajas, y un «77» pelado no se puede contrastar con '
  'ella. Por lo mismo, el costo se puede escribir por caja: el sistema lo divide entre el '
  'contenido y guarda siempre el costo por unidad.')

doc.add_heading('El kardex', level=3)
p('Cada producto lleva su historia completa: fecha, tipo de movimiento, origen, cantidad, stock '
  'anterior, stock resultante y responsable. No se edita ni se borra. Una corrección es otro '
  'movimiento más, encima del anterior.')

# ---- Auxiliares
doc.add_heading('4.10 Inventario', level=2)
p('El almacén como pantalla propia. La ficha del producto sirve cuando ya se sabe cuál se busca; '
  'el trabajo del depósito es al revés: llega la mercadería y todavía hay que encontrarla. Por eso '
  'este módulo parte del stock y no del catálogo.')

doc.add_heading('Existencias', level=3)
p('Muestra cuántos productos hay, cuánto vale el inventario al precio de compra, cuántos están '
  'bajo el mínimo y cuántos agotados. Se filtra por búsqueda, categoría, proveedor y estado de '
  'stock, con un atajo directo a «solo lo que falta».')
p('Cada fila trae el stock actual, el mínimo, cuánto falta para llegar a él y cuánto capital hay '
  'inmovilizado, y dos acciones: ingresar mercadería y ajustar por conteo. Al pie, las últimas '
  'entradas y ajustes, para confirmar de un vistazo que lo del día ya se cargó.')

doc.add_heading('Movimientos', level=3)
p('El kardex de todo el almacén, no el de un producto suelto. Filtra por producto, tipo de '
  'movimiento, responsable y rango de fechas, y suma cuántas unidades entraron y salieron en ese '
  'recorte. Responde lo que la ficha de un producto no puede: qué se movió ayer, qué cargó esta '
  'persona, cuántos ajustes hubo este mes.')

aviso('Dos puertas, una sola regla:',
      'ingresar y ajustar se puede desde aquí y también desde la ficha del producto. Las dos '
      'terminan en el mismo sitio, con las mismas validaciones: no entran decimales en una unidad '
      'entera, ni cantidades en cero, ni productos descatalogados, y el motivo del ajuste sigue '
      'siendo obligatorio.')

doc.add_heading('4.11 Categorías, unidades y proveedores', level=2)
tabla(['Módulo', 'Qué define', 'Nota'],
      [['Categorías', 'Cómo se agrupan los productos.', 'Ordena el catálogo y los filtros del mostrador.'],
       ['Unidades de medida', 'Unidad, paquete, kilo, litro.', 'Define si el producto admite cantidades con decimales.'],
       ['Proveedores', 'A quién se le compra.', 'Se usa al registrar ingresos de mercadería.']],
      anchos=[3.6, 5.4, 6.5])
p('En los tres casos, un registro que ya está en uso no se borra: se desactiva.')

# ---- Reportes
doc.add_heading('4.12 Reportes', level=2)

doc.add_heading('Reporte de ventas', level=3)
p('Se pide un rango de fechas, con períodos rápidos para hoy, los últimos 7 días, los últimos 30, '
  'este mes y el mes pasado. Devuelve:')
vineta('lo vendido y la ganancia aproximada del período;')
vineta('operaciones, ticket promedio, devoluciones y ventas anuladas;')
vineta('el desglose por método de pago, marcando cuáles pasan por el cajón;')
vineta('cuánto vendió cada cajero, con su ticket promedio;')
vineta('el detalle día por día.')

doc.add_heading('Productos e inventario', level=3)
vineta('qué productos se venden más, en unidades y en dinero;')
vineta('el valor del inventario al precio de compra: cuánto capital está inmovilizado;')
vineta('qué productos llegaron al mínimo y conviene reponer.')

p('Los dos reportes se descargan en Excel y en PDF, con el mismo rango que esté en pantalla.')

# ---- Personal
doc.add_heading('4.13 Empleados y cargos', level=2)
p('El empleado es la persona; el usuario es su llave para entrar. Son cosas distintas: puede haber '
  'un empleado sin cuenta —alguien que trabaja pero no usa el sistema— y no al revés.')
tabla(['Campo', 'Regla'],
      [['Documento', 'Obligatorio y único por tipo de documento.'],
       ['Nombres y apellidos', 'Obligatorios.'],
       ['Cargo', 'Obligatorio.'],
       ['Fecha de ingreso', 'Obligatoria.'],
       ['Tipo de contrato', 'Indefinido, plazo fijo, parcial o prácticas.'],
       ['Estado', 'Activo, suspendido o cesado.'],
       ['Fecha y motivo de cese', 'Obligatorios si el estado es cesado. La fecha no puede ser anterior al ingreso.']],
      anchos=[5.0, 10.5])
p('Al cesar a un empleado, su cuenta de acceso se desactiva sola. Un empleado cesado se puede '
  'reactivar más adelante sin perder su historial.')

doc.add_heading('4.14 Usuarios y roles', level=2)
tabla(['Campo', 'Regla'],
      [['Empleado', 'Obligatorio, tiene que estar activo, y no puede tener ya otra cuenta.'],
       ['Nombre de usuario', 'De 3 a 40 caracteres, en minúsculas, único en el sistema.'],
       ['Contraseña', 'Mínimo 8 caracteres, con confirmación.'],
       ['Rol', 'Obligatorio.'],
       ['Activo', 'Una cuenta desactivada no entra, pero conserva su historial.']],
      anchos=[5.0, 10.5])
p('En Roles se marcan los permisos uno por uno. Un rol que ya tiene cuentas asignadas no se borra.')

doc.add_heading('4.15 Mi perfil', level=2)
p('Donde cada persona cambia su propia contraseña. Pide la contraseña actual antes de aceptar la '
  'nueva.')

salto()

# ======================================================= 5. ESTADOS
doc.add_heading('5. Los estados', level=1)

p('Casi todo en el sistema tiene un estado, y el estado decide qué se puede hacer a continuación. '
  'Estas son todas las combinaciones posibles.')

doc.add_heading('5.1 Estado de una venta', level=2)
tabla(['Estado', 'Significa', 'Qué admite todavía'],
      [['COMPLETADA', 'La venta se cobró y está vigente.', 'Devolver, anular, sustituir el comprobante'],
       ['DEVUELTA_PARCIAL', 'Se devolvió una parte.', 'Devolver el resto'],
       ['DEVUELTA', 'Se devolvió todo.', 'Nada más'],
       ['ANULADA', 'La venta se deshizo entera.', 'Nada. No cuenta en reportes ni en arqueos']],
      anchos=[3.8, 5.4, 6.3])

doc.add_heading('5.2 Estado de un comprobante', level=2)
tabla(['Estado', 'Significa'],
      [['EMITIDO', 'Vigente. Es el documento válido de la venta.'],
       ['SUSTITUIDO', 'Fue reemplazado por otro. Sigue visible, encadenado al nuevo.'],
       ['ANULADO', 'Su venta fue anulada.']],
      anchos=[4.0, 11.5])

doc.add_heading('5.3 Estado de un turno de caja', level=2)
tabla(['Estado', 'Significa'],
      [['ABIERTA', 'Admite ventas, devoluciones y movimientos de efectivo.'],
       ['CERRADA', 'Ya se hizo el arqueo. No admite ningún movimiento más.']],
      anchos=[4.0, 11.5])

doc.add_heading('5.4 Tipos de movimiento de inventario', level=2)
tabla(['Tipo', 'Orígenes posibles'],
      [['ENTRADA', 'COMPRA, DEVOLUCION, ANULACION, INICIAL'],
       ['SALIDA', 'VENTA'],
       ['AJUSTE', 'AJUSTE']],
      anchos=[4.0, 11.5])

doc.add_heading('5.5 Otros estados', level=2)
tabla(['Qué', 'Estados posibles'],
      [['Cobro por QR', 'PENDIENTE, PAGADO, EXPIRADO, ANULADO'],
       ['Empleado', 'ACTIVO, SUSPENDIDO, CESADO'],
       ['Devolución', 'TOTAL o PARCIAL'],
       ['Movimiento de caja', 'INGRESO o EGRESO'],
       ['Cliente', 'NATURAL o JURIDICA (tipo de persona)'],
       ['Producto, cliente, proveedor, caja, cuenta', 'Activo o inactivo']],
      anchos=[5.4, 10.1])

salto()

# ======================================================= 6. FÓRMULAS
doc.add_heading('6. Cómo se calculan los números', level=1)

p('Todas las cifras que muestra el sistema salen de estas fórmulas. Ninguna se escribe a mano.')

doc.add_heading('6.1 Una línea de la venta', level=2)
formula('importe        =  cantidad × precio unitario − descuento de línea')
formula('impuesto línea =  importe × tasa   (0 si el producto no está afecto)')
formula('total línea    =  importe + impuesto línea')

doc.add_heading('6.2 La venta completa', level=2)
formula('subtotal  =  suma de los importes de todas las líneas')
formula('impuesto  =  suma de los impuestos de todas las líneas')
formula('total     =  subtotal − descuento + impuesto')
formula('vuelto    =  efectivo recibido − total')

doc.add_heading('6.3 El arqueo de caja', level=2)
formula('esperado  =     monto inicial', junto=True)
formula('             +  ventas cobradas en medios que afectan caja', junto=True)
formula('             +  ingresos de efectivo', junto=True)
formula('             −  egresos de efectivo', junto=True)
formula('             −  parte en efectivo de las devoluciones')
formula('diferencia  =  efectivo contado − efectivo esperado')
p('Una diferencia negativa es un faltante; una positiva, un sobrante. Las ventas anuladas quedan '
  'fuera del cálculo.')
p('«Medios que afectan caja» es solo el efectivo: la tarjeta, la transferencia, la billetera y el '
  'QR se registran como venta, pero ese dinero no está en el cajón. Lo mismo con las devoluciones, '
  'que salen prorrateadas: de una venta cobrada mitad por QR y mitad en efectivo, el cajón devuelve '
  'solo la mitad que en su día entró en efectivo. Si no fuera así, el cierre le reclamaría al '
  'cajero un dinero que nunca pasó por sus manos.')

doc.add_heading('6.4 El producto', level=2)
formula('precio de estante  =  precio de venta × (1 + tasa)   si está afecto')
formula('margen             =  precio de venta − precio de compra')
formula('margen %           =  margen ÷ precio de venta × 100')
formula('valor inventario   =  stock actual × precio de compra')

doc.add_heading('6.5 El reporte de ventas', level=2)
formula('ticket promedio  =  monto vendido ÷ cantidad de operaciones')
formula('costo            =  suma de (cantidad vendida − cantidad devuelta) × precio de compra')
formula('ganancia aprox.  =  vendido − costo − devoluciones')
p('La ganancia es aproximada porque usa el precio de compra actual del producto, no el que tenía '
  'el día de cada venta.', cursiva=True)

salto()

# ======================================================= 7. REGLAS
doc.add_heading('7. Las reglas del sistema', level=1)

p('Lo que el sistema no deja hacer, y el aviso exacto que muestra. No son fallas: cada una está '
  'ahí para que los números cierren.')

doc.add_heading('7.1 Al cobrar', level=2)
tabla(['Si ocurre', 'El sistema responde'],
      [['No hay turno abierto', '«Necesitas una caja abierta para registrar la venta.»'],
       ['El carrito está vacío', '«La venta no tiene productos.»'],
       ['No se indicó cómo se pagó', '«La venta no tiene forma de pago.»'],
       ['Falta stock de un producto', '«No hay stock suficiente de …»'],
       ['El producto está desactivado', '«… está descatalogado y no se puede vender.»'],
       ['Se pide una fracción de un producto entero', '«… se vende por unidad entera.»'],
       ['El descuento supera el subtotal', '«El descuento no puede superar el subtotal de la venta.»'],
       ['El descuento supera el tope sin permiso', 'Indica el porcentaje pedido, el máximo permitido y pide que lo registre un administrador.'],
       ['El efectivo recibido no alcanza', '«El efectivo recibido es menor que el importe a cobrar.»'],
       ['Más de una forma de pago sin importe', '«Solo una forma de pago puede quedar sin importe.»'],
       ['Se cobra con un QR todavía sin pagar', '«El cobro por QR todavía no está pagado.» La venta no se registra.'],
       ['Se reutiliza un QR ya usado', '«Ese cobro por QR ya se usó en otra venta.»'],
       ['El importe del QR no coincide con el de la línea', '«El importe del cobro por QR no coincide con el de la venta.»'],
       ['Se intenta usar el QR de otro cajero', '«Ese cobro por QR no es tuyo.»']],
      anchos=[6.4, 9.1])

doc.add_heading('7.2 En la caja', level=2)
tabla(['Si ocurre', 'El sistema responde'],
      [['Ya se tiene un turno abierto', '«Ya tienes una caja abierta. Ciérrala antes de abrir otra.»'],
       ['La caja física está tomada', '«La … ya está abierta por otro usuario.»'],
       ['Se intenta cerrar dos veces', '«Esta caja ya fue cerrada.»'],
       ['Se registra algo en un turno cerrado', '«La caja ya está cerrada: no admite más movimientos.»'],
       ['El monto declarado es negativo', '«El efectivo contado no puede ser negativo.»']],
      anchos=[6.4, 9.1])

doc.add_heading('7.3 En las correcciones', level=2)
tabla(['Si ocurre', 'El sistema responde'],
      [['Se anula algo que no está completado', '«Solo se puede anular una venta completada.»'],
       ['Se sustituye un comprobante ya reemplazado', '«Solo se puede sustituir un comprobante vigente.»'],
       ['Se sustituye el de una venta anulada o devuelta', '«No se sustituye el comprobante de una venta anulada o devuelta.»'],
       ['Se sustituye fuera de plazo', '«La venta excede el plazo permitido para sustituir su comprobante.»'],
       ['Se devuelve sin caja abierta', '«Necesitas una caja abierta: el dinero de la devolución sale del cajón.»'],
       ['Se devuelve sin indicar cantidades', '«No se indicó ninguna cantidad a devolver.»'],
       ['Se devuelve una fracción de un producto entero', '«… se devuelve por unidad entera.»']],
      anchos=[6.4, 9.1])

doc.add_heading('7.4 En el inventario y el catálogo', level=2)
vineta('«Un ajuste sin motivo es un descuadre sin responsable: explica la diferencia.»',
       'Ajuste sin motivo: ')
vineta('el código interno y el código de barras no se pueden repetir.', 'Códigos duplicados: ')
vineta('un producto, cliente, proveedor o categoría que ya está en uso se desactiva, no se borra.',
       'Borrar algo en uso: ')

doc.add_heading('7.5 En las cuentas', level=2)
vineta('el sistema ignora el cambio en silencio.', 'Cambiarse el propio rol: ')
vineta('igual, se ignora.', 'Desactivarse a uno mismo: ')
vineta('«Ya existe un empleado con ese tipo y número de documento.»', 'Documento repetido: ')
vineta('un empleado solo puede tener una cuenta.', 'Dos cuentas para una persona: ')

salto()

# ======================================================= 8. TRAZABILIDAD
doc.add_heading('8. Qué queda registrado', level=1)

p('El sistema guarda tres rastros paralelos. Entre los tres se puede reconstruir cualquier '
  'operación pasada.')

tabla(['Rastro', 'Qué guarda', 'Sirve para'],
      [['Kardex de inventario',
        'Cada movimiento de stock con su fecha, tipo, origen, cantidad, stock anterior, stock resultante y responsable.',
        'Explicar por qué hay las unidades que hay.'],
       ['Historial de caja',
        'Cada turno con su apertura, su cierre, quién lo abrió, quién lo cerró, lo esperado, lo contado y la diferencia.',
        'Saber quién manejó el efectivo y cuánto faltó o sobró.'],
       ['Bitácora de auditoría',
        'Ingresos al sistema, anulaciones, sustituciones, ajustes de stock y cambios de cuentas, con usuario y fecha.',
        'Responder «quién hizo esto y cuándo».']],
      anchos=[3.6, 7.4, 4.5])

doc.add_heading('8.1 Lo que nunca se pierde', level=2)
vineta('quedan en la lista, tachadas, con su motivo y su responsable.', 'Las ventas anuladas ')
vineta('siguen visibles, encadenados al documento que los reemplazó.', 'Los comprobantes sustituidos ')
vineta('conservan el nombre y el precio del día en que se emitieron.', 'Los documentos ')
vineta('no se editan ni se borran; una corrección es un movimiento nuevo.', 'Los movimientos de stock ')
vineta('se desactivan; su historial queda intacto.', 'Las cuentas y los empleados ')

doc.save(r'C:\Universidad\vivecoding\SistemaVentas\despliegue-demo\Manual-Funcionamiento.docx')
print('listo')
