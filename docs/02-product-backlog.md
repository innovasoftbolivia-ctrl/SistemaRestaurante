# 2. Product Backlog e Historias de Usuario

**Proyecto:** Sistema de Restaurante
**Documento:** 02 — Product Backlog
**Versión:** 3.0

---

## 2.1 Visión del Producto

> Para el **dueño y el personal de un restaurante de mostrador** que hoy anota el pedido en
> una libreta, lo canta a la cocina, lleva el número de cabeza y suma la cuenta a mano, el
> **Sistema de Restaurante** es una aplicación web que registra cada pedido en el momento en
> que el cliente lo paga en la caja, lo pone en la pantalla —y en la comanda— de la cocina
> con un número corto que se canta al entregar, cobra sin errores de suma y entrega reportes
> de gestión. A diferencia de la libreta y la calculadora, garantiza que ningún plato se
> pierda entre la caja y la cocina, que cada plato llegue a quien lo pidió, que la caja
> cuadre y que las decisiones se tomen con datos.

## 2.2 Épicas

| ID | Épica | Descripción | Valor |
|----|-------|-------------|-------|
| EP1 | Personal y seguridad | Empleados, cargos, autenticación, roles y permisos | Alto |
| EP2 | Menú | Secciones de la carta (categorías), con su paso por la cocina, y platos con su precio | Muy alto |
| EP3 | Punto de venta y comprobantes | Venta de mostrador, cobro, factura y recibo | Crítico |
| EP4 | Pedidos de mostrador | Cada venta es un pedido: comer aquí o para llevar, número de la jornada, cobro del pedido | Crítico |
| EP5 | Clientes | Persona natural y jurídica, historial de consumo | Alto |
| EP6 | Caja y turnos | Apertura, movimientos, cierre y arqueo | Alto |
| EP7 | Anulaciones | Reversión auditable de una venta cobrada y el pedido que se vuelve a cobrar | Alto |
| EP8 | Reportes y dashboard | Información para la gestión | Alto |
| EP9 | Configuración y auditoría | Parámetros del negocio, bitácora y respaldos | Medio |
| EP10 | Cocina | Pantalla de preparación, entrega del pedido y comanda impresa | Crítico |

> **Fuera de alcance.** El restaurante no lleva **inventario** (stock, kardex, ingresos,
> ajustes y alertas de reposición) ni **devoluciones** de cliente: lo que se sirvió no
> vuelve a la carta, y una venta equivocada se anula entera.

> **Cambio respecto de la versión 2** (18/09/2026). El cliente **pide y paga en la caja**
> —primero se paga—, recibe un ticket con el número del pedido, se sienta donde quiera y
> alguien le lleva el plato cantando ese número. EP4 pasa a ser **Pedidos de mostrador**. Se
> retiran HU-45, HU-46, HU-47, HU-51 y HU-53, y entran la venta de mostrador que llega a la
> cocina, comer aquí o para llevar, la comanda impresa, el paso por la cocina, la jornada con
> hora de corte, volver a cobrar tras anular y el cierre de caja con pedidos pendientes
> (HU-55 a HU-61). Como antes, los números retirados no se reutilizan.

## 2.3 Criterios de Priorización

- **MoSCoW:** `M` = Must (imprescindible para el MVP), `S` = Should, `C` = Could, `W` = Won't (esta versión).
- **Estimación:** puntos de historia (Fibonacci: 1, 2, 3, 5, 8, 13).
- **Velocidad estimada del equipo:** 26 puntos por sprint de 2 semanas.

---

## 2.4 Product Backlog

| ID | Épica | Historia (resumen) | Prio | Pts | Sprint |
|------|-----|--------------------------------------------------------|:--:|:--:|:--:|
| HU-01 | EP1 | Iniciar sesión con usuario y contraseña | M | 3 | 1 |
| **HU-44** | EP1 | **Registrar empleados con su cargo y vínculo laboral** | **M** | **5** | **1** |
| HU-02 | EP1 | Crear la cuenta de acceso de un empleado y asignarle un rol | M | 5 | 1 |
| HU-03 | EP1 | Restringir el acceso a los módulos según el rol | M | 3 | 1 |
| HU-04 | EP2 | Registrar y administrar las secciones de la carta (categorías) | M | 2 | 1 |
| HU-05 | EP2 | Registrar y administrar los platos del menú con su precio | M | 5 | 1 |
| HU-06 | EP2 | Buscar platos por nombre o código interno | M | 3 | 1 |
| HU-07 | EP2 | Sacar un plato de la carta sin eliminarlo | S | 2 | 2 |
| HU-25 | EP6 | Abrir caja con un monto inicial | M | 3 | 2 |
| HU-28 | EP6 | Impedir cobrar si el usuario no tiene una caja abierta | M | 2 | 2 |
| HU-08 | EP3 | Agregar platos a un carrito de venta de mostrador | M | 5 | 2 |
| HU-09 | EP3 | Modificar cantidad y quitar un ítem del carrito | M | 3 | 2 |
| HU-10 | EP3 | Calcular automáticamente subtotal, impuesto y total | M | 3 | 2 |
| HU-11 | EP3 | Cobrar la venta y calcular el vuelto | M | 5 | 2 |
| HU-23 | EP5 | Registrar y administrar clientes | M | 3 | 2 |
| **HU-39** | EP5 | **Registrar el cliente como persona natural o jurídica, con sus datos obligatorios** | **M** | **5** | **3** |
| HU-13 | EP3 | Emitir comprobante numerado con serie y correlativo | M | 5 | 3 |
| **HU-40** | EP3 | **Emitir factura a persona jurídica y recibo a persona natural** | **M** | **5** | **3** |
| **HU-43** | EP3 | **Cobrar y emitir recibo sin registrar al cliente** | **M** | **2** | **3** |
| HU-12 | EP3 | Registrar pago con más de un método (pago mixto) | S | 5 | 3 |
| HU-14 | EP3 | Imprimir o descargar el comprobante en PDF o ticket | S | 3 | 3 |
| HU-24 | EP5 | Asociar un cliente a la venta y ver su historial de consumo | S | 3 | 3 |
| **HU-55** | EP4 | **Vender en el mostrador y que el pedido llegue a la cocina** | **M** | **5** | **4** |
| **HU-49** | EP4 | **Cobrar el pedido como una venta normal** | **M** | **3** | **4** |
| **HU-56** | EP4 | **Elegir comer aquí o para llevar, y a nombre de quién** | **S** | **1** | **4** |
| **HU-50** | EP4 | **Numerar los pedidos para cantarlos al entregar** | **M** | **3** | **4** |
| **HU-52** | EP3 | **Identificar el pedido en el comprobante impreso** | **M** | **2** | **4** |
| **HU-48** | EP10 | **Ver en la cocina qué preparar, avanzar cada plato y entregar el pedido** | **M** | **5** | **4** |
| **HU-58** | EP2 | **Marcar qué secciones de la carta pasan por la cocina** | **S** | **2** | **4** |
| **HU-57** | EP10 | **Imprimir la comanda para la cocina y reimprimirla** | **S** | **5** | **4** |
| **HU-59** | EP4 | **Numerar por jornada, con una hora de corte configurable** | **S** | **3** | **4** |
| HU-26 | EP6 | Registrar ingresos y egresos de efectivo durante el turno | S | 3 | 5 |
| HU-27 | EP6 | Cerrar caja con arqueo y cálculo de diferencia | M | 5 | 5 |
| **HU-61** | EP6 | **Cerrar la caja sabiendo qué pedidos quedan para volver a cobrar** | **S** | **2** | **5** |
| HU-29 | EP7 | Anular una venta con motivo, con el turno de caja abierto | M | 5 | 5 |
| **HU-60** | EP7 | **Cobrar de nuevo o cancelar el pedido de una venta anulada** | **M** | **5** | **5** |
| HU-32 | EP8 | Consultar reporte de ventas por rango de fechas con filtros | M | 5 | 5 |
| HU-33 | EP8 | Consultar reporte de platos más vendidos | S | 3 | 5 |
| HU-15 | EP3 | Aplicar un descuento a la venta | S | 5 | 6 |
| **HU-42** | EP3 | **Sustituir el recibo por una factura cuando el cliente la solicita después** | **S** | **5** | **6** |
| HU-31 | EP8 | Ver dashboard con ventas del día, ticket promedio y platos más pedidos | S | 5 | 6 |
| HU-34 | EP8 | Exportar reportes a Excel y PDF | C | 3 | 6 |
| HU-35 | EP9 | Configurar datos del negocio, moneda e impuesto | S | 3 | 6 |
| HU-36 | EP9 | Consultar la bitácora de auditoría de operaciones sensibles | C | 5 | 6 |
| **HU-54** | EP9 | **Respaldar la base cada noche, en el servidor y en Google Drive, sin pantalla** | **C** | **3** | **6** |
| HU-16 | EP3 | Guardar una venta de mostrador en espera y retomarla después | W | 5 | — |
| HU-38 | EP3 | Vender a crédito | W | 13 | — |

**Total planificado (Sprints 1 a 6): 166 puntos. MVP operativo al cierre del Sprint 5: 137 puntos.**

> HU-16 sigue en `W`: el cliente pide y paga en el mismo acto, en la caja, así que no hay una
> venta que dejar a medias. El único pedido que queda sin cobrar es el de una venta anulada
> (HU-60), y ese ya tiene su propio camino.

### Resumen por sprint

| Sprint | Objetivo | Pts |
|:--:|-------------------------------------------------------------|:--:|
| 1 | Base: **empleados y cargos**, seguridad, usuarios y **menú** | 26 |
| 2 | Venta de mostrador end-to-end, con apertura de caja y clientes básicos | 26 |
| 3 | **Clientes natural/jurídica, factura, recibo y cobro sin cliente**, pago mixto e impresión | 28 |
| 4 | **El pedido: la venta de mostrador llega a la cocina, con su número de la jornada, su ticket y su comanda** | 29 |
| 5 | Cierre de caja, anulaciones, **volver a cobrar** y reportes de ventas | 28 |
| 6 | Descuentos, **sustitución de comprobante**, dashboard, exportaciones, configuración, auditoría y respaldos | 29 |

> El Sprint 1 abre con HU-44 porque `empleados` es prerrequisito de `usuarios`: sin la
> persona registrada no hay a quién crearle una cuenta.
>
> El pedido (Sprint 4) va **después** del mostrador y los comprobantes (Sprints 2 y 3) porque
> cobrar un pedido no es un circuito aparte: se traduce a una venta normal (HU-49). Cuando
> el pedido llega, la venta, el comprobante y el arqueo ya existen y están probados.
>
> Volver a cobrar (HU-60) va en el Sprint 5, junto a la anulación (HU-29), que es lo único
> que lo produce; y el aviso del cierre de caja (HU-61), junto al cierre (HU-27).

### Trazabilidad con la versión 1

| Historia v1 | Qué pasó |
|-------------|----------|
| HU-05 "productos con precio y stock" | Se queda como **platos con su precio**: sin stock, sin precio de compra y sin unidad de medida. |
| HU-06 "buscar por nombre, código interno o código de barras" | Se queda **sin código de barras**: un plato no se escanea. |
| HU-08 "agregar al carrito escaneando" | Se queda con la búsqueda por nombre o código interno. |
| HU-17, HU-18, HU-19, HU-20, HU-21, HU-22 | **Retiradas** junto con el inventario. |
| HU-27 "cierre con devoluciones del turno" | Se queda; el esperado ya **no resta devoluciones**. |
| HU-29 "anular una venta del día" | Se queda y pasa a ser la **única** corrección de una venta cobrada; exige turno de caja abierto. |
| HU-30 "devolución total o parcial" | **Retirada**. |
| HU-37 "más de una unidad de medida" | **Retirada**: todo se despacha por porción. |
| HU-41 "nota de crédito por devolución" | **Retirada** junto con las devoluciones. |
| HU-16 "venta en espera" | Pasa a `W`: ver la nota de arriba. |

### Trazabilidad con la versión 2

| Historia v2 | Qué pasó |
|-------------|----------|
| HU-45 | **Retirada**. La reemplaza la venta de mostrador (HU-55), con comer aquí o para llevar (HU-56). |
| HU-46 | **Retirada**. La nota por plato pasa a HU-55. |
| HU-47 | **Retirada**. Cancelar un plato queda en HU-60. |
| HU-51 | **Retirada**. |
| HU-53 | **Retirada**. Cancelar un pedido con su motivo queda en HU-60. |
| HU-49 "cobrar el pedido" | Se queda como el **mecanismo** de cobro: lo usan la venta de mostrador (HU-55) y volver a cobrar (HU-60). Baja a 3 puntos: la parte de pedir en la caja la cuenta HU-55. |
| HU-48 "ver en la cocina qué preparar" | Se queda: el pedido por su **número en grande**, con su destino y sus notas, la entrega del pedido completo, sin bebidas y solo la jornada en curso. |
| HU-50 "numerar los pedidos por día" | Se queda y pasa a `M`: el número es lo que une el ticket con el plato. El día pasa a ser la **jornada** (HU-59). |
| HU-52 "identificar el pedido en el comprobante" | Se queda, pasa a `M` y al Sprint 4: el ticket es el papel con el que el cliente reclama su plato. |

---

## 2.5 Historias de Usuario Más Resaltantes

Se detallan las historias de mayor valor y mayor riesgo técnico. Los criterios de
aceptación se expresan en formato **Gherkin** (Dado / Cuando / Entonces).

---

### HU-44 — Registrar empleados con su cargo y vínculo laboral

> **Como** administrador
> **quiero** registrar a mi personal con su cargo y sus datos laborales, tenga o no cuenta en el sistema
> **para** saber quién trabaja en el restaurante y desde cuándo, aparte de quién usa el sistema.

**Prioridad:** Must · **Puntos:** 5 · **Sprint:** 1

**Criterios de aceptación**

1. **Escenario: alta de empleado**
   - **Cuando** registro a un empleado con documento, nombres, apellidos, cargo y fecha de ingreso
   - **Entonces** queda registrado con estado "Activo"
   - **Y** aparece en el listado de personal con su cargo.

2. **Escenario: empleado sin cuenta de sistema**
   - **Dado** que registro a un ayudante que lleva los platos y no usará el sistema
   - **Cuando** guardo sin crear usuario
   - **Entonces** el empleado queda registrado igual
   - **Y** el listado muestra "Sin cuenta" en la columna de acceso.

3. **Escenario: cargo distinto del rol**
   - **Dado** un empleado con cargo "Ayudante"
   - **Cuando** le creo una cuenta con rol "Cajero"
   - **Entonces** el sistema lo permite
   - **Y** el empleado conserva su cargo de Ayudante.

4. **Escenario: cese**
   - **Cuando** registro el cese de un empleado con su fecha y motivo
   - **Entonces** su estado pasa a "Cesado"
   - **Y** su cuenta de acceso queda desactivada automáticamente
   - **Y** no puede iniciar sesión.

5. **Escenario: el cese exige fecha**
   - **Cuando** intento marcar a alguien como cesado sin indicar la fecha
   - **Entonces** el sistema no lo permite
   - **Y** tampoco acepta una fecha de cese anterior a la de ingreso.

6. **Escenario: lo registrado por el empleado cesado no se pierde**
   - **Dado** un cajero cesado que registró 300 pedidos
   - **Entonces** esos pedidos y las ventas que salieron de ellos siguen mostrando su nombre
   - **Y** los reportes por usuario lo siguen incluyendo.

7. **Escenario: documento duplicado**
   - **Cuando** registro un documento de identidad que ya existe
   - **Entonces** el sistema muestra "Ya existe un empleado con ese documento".

**Definición de Terminado**

- Un empleado no puede tener dos cuentas de usuario (índice único sobre `empleado_id`).
- El estado laboral (`empleados.estado`) y el acceso (`usuarios.activo`) son campos
  distintos; el cese desactiva el acceso, pero desactivar el acceso no cesa a nadie.
- La baja de un empleado es lógica: nunca se elimina, porque su historial de operaciones
  debe seguir siendo consultable.

---

### HU-55 — Vender en el mostrador y que el pedido llegue a la cocina

> **Como** cajero
> **quiero** cobrar lo que el cliente pide en la caja y que eso mismo llegue a la cocina
> **para** no escribir el pedido dos veces ni cantarlo por la ventanilla.

**Prioridad:** Must · **Puntos:** 5 · **Sprint:** 4

**Criterios de aceptación**

1. **Escenario: cada venta es un pedido**
   - **Dado** un carrito con 2 "Pique macho" y 1 "Empanada de queso"
   - **Cuando** cobro
   - **Entonces** se crea un pedido con el número que le toca en la jornada (HU-50), ya cobrado,
     con su venta y su comprobante
   - **Y** los platos aparecen en la pantalla de la cocina en estado `PENDIENTE`.

2. **Escenario: nota por plato**
   - **Cuando** anoto "uno sin locoto" en el "Pique macho" antes de cobrar
   - **Entonces** la línea lleva esa nota
   - **Y** la cocina la ve en su pantalla y en la comanda.

3. **Escenario: el precio se congela al pedir**
   - **Entonces** el pedido guarda una copia del nombre y del precio del menú de ese momento
   - **Y** si el administrador sube el precio después, el pedido ya cobrado no cambia.

4. **Escenario: porción entera**
   - **Cuando** intento vender 1.5 porciones de un plato
   - **Entonces** el sistema lo rechaza: todo se despacha por porción entera.

5. **Escenario: todo o nada**
   - **Dado** que el cobro falla (el pago no alcanza, o no se puede emitir el comprobante)
   - **Entonces** no queda venta, ni pedido, ni número de la jornada gastado
   - **Y** la cocina no ve nada.

6. **Escenario: sin turno de caja**
   - **Dado** que no tengo una caja abierta
   - **Cuando** intento cobrar
   - **Entonces** el sistema me pide abrir caja primero.

7. **Escenario: cobrar no es servir**
   - **Dado** un pedido ya cobrado
   - **Entonces** la cocina lo sigue viendo hasta que se entrega (HU-48).

**Definición de Terminado**

- `Pedidos::venderEnMostrador()` abre el pedido, le carga las líneas y lo cobra con
  `Pedidos::cobrar()` (HU-49) **en una sola transacción**; ante un deadlock reintenta
  entero, igual que `Ventas::registrar()`.
- El mostrador manda una línea por plato (el carrito ya las agrupa, HU-08), cada una con su
  nota.
- Deja en la bitácora `PEDIDO_ABIERTO` y `PEDIDO_COBRADO`; las líneas no escriben una fila
  por plato, porque la venta ya las registra.
- La guarda de "no agregar a un pedido que ya no está abierto" está en la aplicación, con el
  pedido bloqueado, **y** en la base (`trg_pedido_detalle_before_insert`).

---

### HU-56 — Elegir comer aquí o para llevar, y a nombre de quién

> **Como** cajero
> **quiero** indicar si el pedido es para comer aquí o para llevar, y anotar un nombre si hace falta
> **para** que la cocina sepa cómo prepararlo y quien lleva los platos sepa a quién llamar.

**Prioridad:** Should · **Puntos:** 1 · **Sprint:** 4

**Criterios de aceptación**

1. **Escenario: comer aquí por omisión**
   - **Cuando** cobro sin tocar nada
   - **Entonces** el pedido queda como "Comer aquí".

2. **Escenario: para llevar**
   - **Cuando** elijo "Para llevar"
   - **Entonces** el ticket, la pantalla de la cocina y la comanda dicen "Para llevar".

3. **Escenario: a nombre de quién**
   - **Cuando** anoto "Ana"
   - **Entonces** el ticket, la cocina y la comanda muestran "Ana" junto al número
   - **Y** si no anoto nada, el pedido se cobra igual.

**Definición de Terminado**

- `pedidos.tipo` es `LOCAL` ("comer aquí", por omisión) o `LLEVAR`. El cliente se sienta
  donde quiera: lo encuentra el número.
- `pedidos.nombre_cliente` (hasta 80 caracteres) es un texto para llamar al cliente; **no**
  crea un registro en el maestro de clientes.

---

### HU-49 — Cobrar el pedido como una venta normal

> **Como** cajero
> **quiero** que cobrar un pedido sea registrar una venta normal
> **para** que el dinero pase por un solo camino, con su comprobante y su efecto en la caja.

**Prioridad:** Must · **Puntos:** 3 · **Sprint:** 4

**Criterios de aceptación**

1. **Escenario: cobro normal**
   - **Dado** un pedido con 2 "Pique macho" y 3 "Refresco de la casa"
   - **Cuando** lo cobro en efectivo con 50.00
   - **Entonces** se registra una venta con esas líneas, se emite el recibo y se calcula el vuelto
   - **Y** el pedido queda `CERRADO`, apuntando a su venta.

2. **Escenario: el mismo plato en dos líneas**
   - **Dado** un pedido con el mismo plato en dos líneas, a precios distintos
   - **Entonces** la venta lo registra como **una sola línea**
   - **Y** el total cobrado es exactamente la suma de las líneas del pedido.

3. **Escenario: lo cancelado no se cobra**
   - **Dado** un pedido con una línea `CANCELADO`
   - **Entonces** esa línea no entra en la venta ni en el total.

4. **Escenario: pedido vacío**
   - **Dado** un pedido sin platos, o con todos cancelados
   - **Cuando** intento cobrarlo
   - **Entonces** el sistema lo rechaza y sugiere cancelar el pedido.

5. **Escenario: lo que no pasa por la cocina se entrega con el ticket**
   - **Dado** un pedido con 3 "Refresco de la casa", de una sección que no pasa por la cocina (HU-58)
   - **Cuando** se cobra
   - **Entonces** esas líneas quedan `ENTREGADO`: nunca "pendientes" para siempre.

6. **Escenario: doble clic o dos cajeros**
   - **Cuando** se pulsa "Cobrar" dos veces, o dos cajeros vuelven a cobrar el mismo pedido a la vez
   - **Entonces** solo se registra **una** venta; el segundo intento recibe "Ese pedido ya se
     cobró".

**Definición de Terminado**

- Cobrar **no** es un circuito de dinero aparte: el pedido se traduce a una venta normal con
  `Ventas::registrar()`, que ya resuelve descuento, pagos mixtos, cobro por QR, comprobante,
  arqueo y auditoría.
- Todo ocurre en una transacción con el pedido bloqueado: si la venta falla, el pedido queda
  tal como estaba.
- El precio de cada plato agrupado sale de lo que se pidió (`SUM(importe) / SUM(cantidad)`),
  no del menú de hoy.
- "Un pedido se cobra una sola vez" lo garantiza la base con el índice único
  `uq_venta_pedido_cobrado`: la venta guarda su pedido (`ventas.pedido_id`) y solo una venta
  `COMPLETADA` por pedido puede ocuparlo. Una anulada deja el lugar libre para volver a cobrarlo
  y sigue sabiendo de qué pedido era.

---

### HU-50 — Numerar los pedidos para cantarlos al entregar

> **Como** cajero
> **quiero** que cada pedido tenga un número corto que empiece en 1 cada jornada
> **para** que quien lleva los platos lo cante ("¡el siete!") y el cliente lo reconozca en su ticket.

**Prioridad:** Must · **Puntos:** 3 · **Sprint:** 4

**Criterios de aceptación**

1. **Escenario: numeración de la jornada**
   - **Dado** que en la jornada ya se cobraron 6 pedidos, para comer aquí o para llevar
   - **Cuando** se cobra el siguiente
   - **Entonces** recibe el número 7.

2. **Escenario: vuelve a empezar**
   - **Cuando** se cobra el primer pedido de la jornada siguiente (HU-59)
   - **Entonces** recibe el número 1.

3. **Escenario: un solo contador**
   - **Entonces** comer aquí y para llevar comparten la numeración: en la misma jornada no
     conviven dos "pedido 7".

4. **Escenario: cobros simultáneos**
   - **Dado** que dos cajeros cobran un pedido en el mismo segundo
   - **Entonces** cada uno recibe un número distinto.

5. **Escenario: el número se conserva**
   - **Dado** que se anula la venta del pedido 7 (HU-60)
   - **Entonces** el pedido sigue siendo el 7 cuando se cobra de nuevo.

**Definición de Terminado**

- La unicidad la garantiza la base con `uq_pedido_numero_dia (jornada, numero_dia)`. La
  aplicación toma el siguiente número con `SELECT ... FOR UPDATE` y reintenta (hasta 3
  veces) si aun así choca.
- El `id` del pedido sigue siendo la clave y el que va en las direcciones; `numero_dia` es lo
  único que lee una persona.

---

### HU-59 — Numerar por jornada, con una hora de corte configurable

> **Como** administrador
> **quiero** que el número del pedido vuelva a 1 a una hora que yo elijo, y no a medianoche
> **para** que la noche que pasa de las doce no tenga dos "pedido 1".

**Prioridad:** Should · **Puntos:** 3 · **Sprint:** 4

**Criterios de aceptación**

1. **Escenario: pasada la medianoche**
   - **Dado** la hora de corte en 5
   - **Cuando** se cobra un pedido el 19/09 a la 01:30
   - **Entonces** pertenece a la jornada del 18/09 y sigue su numeración.

2. **Escenario: después del corte**
   - **Cuando** se cobra el primer pedido del 19/09 a las 05:00 o más tarde
   - **Entonces** pertenece a la jornada del 19/09 y recibe el número 1.

3. **Escenario: se configura**
   - **Cuando** cambio la hora en *Sistema → Configuración*, "Número del pedido"
   - **Entonces** vale para los pedidos siguientes
   - **Y** solo se admite una hora entre 0 y 12.

4. **Escenario: la cocina, solo la jornada en curso**
   - **Dado** un plato de ayer que nadie marcó como entregado
   - **Entonces** no aparece en la pantalla de la cocina de hoy.

5. **Escenario: el historial no se renumera**
   - **Cuando** cambio la hora de corte
   - **Entonces** los pedidos ya numerados conservan su jornada y su número: ya están impresos
     en tickets que el cliente se llevó.

**Definición de Terminado**

- `configuracion.hora_corte_jornada` (5 por omisión).
- `pedidos.jornada` es una columna **normal** que escribe la aplicación al abrir el pedido
  (fecha y hora de apertura menos las horas del corte). No es columna generada porque una
  columna generada no puede leer la configuración.

---

### HU-52 — Identificar el pedido en el comprobante impreso

> **Como** cajero
> **quiero** que el ticket muestre bien grande el número del pedido y si es para comer aquí o para llevar
> **para** que el cliente lo muestre al recibir su plato.

**Prioridad:** Must · **Puntos:** 2 · **Sprint:** 4

**Criterios de aceptación**

1. **Escenario: para comer aquí**
   - **Entonces** el comprobante lleva, recuadrado y en mayúsculas, `PEDIDO` `#7` `COMER AQUÍ`,
     con el número como lo más grande del papel.

2. **Escenario: para llevar, a nombre de alguien**
   - **Entonces** lleva `PEDIDO` `#8` `PARA LLEVAR` y, debajo, "Ana".

3. **Escenario: una venta que no salió de un pedido**
   - **Dado** una venta registrada sin pedido (no tiene `pedido_id`)
   - **Entonces** el comprobante no imprime ninguna línea de pedido.

**Definición de Terminado**

- El número impreso es el `numero_dia` (HU-50), no el `id`.
- El comprobante de un pedido que se cobró de nuevo tras anular (HU-60) lleva el mismo número.

---

### HU-48 — Ver en la cocina qué preparar, avanzar cada plato y entregar el pedido

> **Como** cocinero, y como quien lleva los platos
> **quiero** ver en una pantalla los pedidos por su número, con sus platos en orden de llegada y con su nota
> **para** preparar lo que corresponde y entregar cada pedido sin depender de hojas ni de gritos.

**Prioridad:** Must · **Puntos:** 5 · **Sprint:** 4

**Criterios de aceptación**

1. **Escenario: la pantalla de la cocina**
   - **Cuando** abro la pantalla de cocina
   - **Entonces** veo cada pedido con platos `PENDIENTE`, `EN_PREPARACION` o `LISTO`, con su
     **número en grande**, "Comer aquí" o "Para llevar", el nombre si lo hay y la nota de
     cada plato
   - **Y** primero el pedido que lleva más tiempo esperando.

2. **Escenario: se actualiza sola**
   - **Dado** que el cajero cobra un pedido
   - **Entonces** aparece en la pantalla de la cocina sin recargarla, en el siguiente refresco
     (cada 10 segundos).

3. **Escenario: avanzar el estado**
   - **Cuando** toco un plato `PENDIENTE`
   - **Entonces** pasa a `EN_PREPARACION`; el siguiente toque lo pasa a `LISTO`, y el siguiente
     a `ENTREGADO`, que lo saca de la pantalla.

4. **Escenario: el pedido listo se destaca**
   - **Dado** que todos los platos del pedido 7 están `LISTO`
   - **Entonces** el pedido se resalta en la pantalla: es el momento de cantarlo.

5. **Escenario: entregar el pedido entero**
   - **Cuando** quien lleva los platos pulsa "Entregado" en el pedido 7
   - **Entonces** todo lo que estaba `LISTO` pasa a `ENTREGADO` de una vez
   - **Y** cada plato deja su rastro como si se hubiera tocado uno por uno.

6. **Escenario: lo cobrado sigue en pantalla**
   - **Dado** que el pedido ya se cobró
   - **Entonces** la cocina lo sigue viendo hasta entregarlo
   - **Y** un pedido cancelado desaparece de la pantalla.

7. **Escenario: sin bebidas**
   - **Entonces** lo de las secciones que no pasan por la cocina (HU-58) no aparece.

8. **Escenario: solo la jornada en curso**
   - **Entonces** solo aparecen los pedidos de la jornada actual (HU-59).

9. **Escenario: no se vuelve atrás**
   - **Cuando** intento pasar un plato `LISTO` a `EN_PREPARACION`
   - **Entonces** el sistema lo rechaza: si la cocina se adelantó, queda escrito lo que pasó.

10. **Escenario: sin dinero**
    - **Dado** que tengo el rol Cocina
    - **Entonces** no veo precios, totales, ventas ni la caja.

**Definición de Terminado**

- Transiciones permitidas: `PENDIENTE → EN_PREPARACION | LISTO | CANCELADO`,
  `EN_PREPARACION → LISTO | CANCELADO`, `LISTO → ENTREGADO`. `ENTREGADO` y `CANCELADO` son
  finales.
- Un plato solo se cancela mientras su pedido espera **volver a cobrarse** (HU-60): de un pedido ya
  cobrado no se cancela nada, porque el dinero ya entró; eso es anular la venta.
- Cada cambio de estado guarda quién lo hizo (`pedido_detalle.actualizado_por`) y queda en la
  bitácora (`PEDIDO_LINEA_ESTADO`).
- La pantalla exige solo el permiso `cocina.ver`. Quien lleva los platos no tiene cuenta
  propia: usa la pantalla de la cocina.

---

### HU-58 — Marcar qué secciones de la carta pasan por la cocina

> **Como** administrador
> **quiero** indicar en cada sección de la carta si se prepara en la cocina
> **para** que las bebidas se cobren pero no llenen la pantalla ni la comanda.

**Prioridad:** Should · **Puntos:** 2 · **Sprint:** 4

**Criterios de aceptación**

1. **Escenario: las bebidas no pasan por la cocina**
   - **Dado** que "Bebidas" está marcada como que no pasa por la cocina
   - **Cuando** se cobran 2 "Refresco de la casa" y 1 "Pique macho"
   - **Entonces** la cocina y la comanda solo muestran el "Pique macho"
   - **Y** el ticket cobra los tres.

2. **Escenario: se entrega con el ticket**
   - **Entonces** al cobrarse, los refrescos quedan `ENTREGADO`
   - **Y** mientras un pedido espera volver a cobrarse se leen "Sin cocina", no "Pendiente".

3. **Escenario: el cambio no mueve lo ya pedido**
   - **Cuando** cambio la marca de una sección
   - **Entonces** lo que ya se pidió sigue como estaba; el cambio vale para los pedidos siguientes.

4. **Escenario: un pedido solo de bebidas**
   - **Entonces** no aparece en la cocina ni tiene nada que imprimir en la comanda.

**Definición de Terminado**

- `categorias.pasa_por_cocina` (1 por omisión; "Bebidas" en 0 en los datos de ejemplo), que
  se edita en *Menú → Categorías*.
- Cada línea lo copia al pedirse (`pedido_detalle.pasa_por_cocina`), igual que copia el
  precio.

---

### HU-57 — Imprimir la comanda para la cocina y reimprimirla

> **Como** cajero, o como cocinero
> **quiero** un papel por pedido con lo que hay que preparar
> **para** que la cocina trabaje aunque no tenga pantalla o se caiga la red.

**Prioridad:** Should · **Puntos:** 5 · **Sprint:** 4

**Criterios de aceptación**

1. **Escenario: la comanda del pedido**
   - **Cuando** cobro un pedido
   - **Entonces** se me ofrece "Imprimir comanda": un papel de 80 mm con el número en grande,
     "Comer aquí" o "Para llevar", el nombre si lo hay, la hora, y cada plato con su cantidad
     y su nota
   - **Y** sin precios ni lo que no pasa por la cocina.

2. **Escenario: no se repite lo que ya salió**
   - **Cuando** imprimo la comanda de un pedido que ya salió en papel
   - **Entonces** trae solo lo que todavía no salió.

3. **Escenario: reimpresión**
   - **Dado** que se perdió el papel
   - **Cuando** pido "Reimprimir comanda"
   - **Entonces** sale la comanda completa con el sello "REIMPRESIÓN"
   - **Y** no cuenta como enviada: no cambia qué salió en papel.

4. **Escenario: aviso de cancelación**
   - **Dado** un plato que ya salió en papel y después se cancela (HU-60)
   - **Entonces** la siguiente comanda dice "CANCELADO: 1 × …", una sola vez
   - **Y** si se canceló el pedido entero, sale con el sello "PEDIDO CANCELADO · NO PREPARAR".

5. **Escenario: desde la cocina**
   - **Entonces** la pantalla de la cocina ofrece imprimir o reimprimir la comanda de cada pedido.

6. **Escenario: en A4**
   - **Entonces** la misma comanda se puede ver e imprimir en A4.

**Definición de Terminado**

- Cada línea guarda cuándo salió en papel (`pedido_detalle.comandado_en`) y cuándo salió el
  aviso de su cancelación (`cancelacion_comandada_en`). Imprimir bloquea el pedido: dos
  pantallas que imprimen a la vez no mandan dos veces lo mismo.
- Toda impresión, también la reimpresión, queda en la bitácora (`COMANDA_IMPRESA`).
- La comanda exige `ventas.registrar`, `pedidos.registrar` o `cocina.ver`.

---

### HU-08 — Agregar platos al carrito de venta de mostrador

> **Como** cajero
> **quiero** agregar platos al carrito buscándolos por nombre o por código
> **para** cobrar rápido lo que el cliente pide en la caja.

**Prioridad:** Must · **Puntos:** 5 · **Sprint:** 2

**Criterios de aceptación**

1. **Escenario: agregar por nombre**
   - **Dado** que escribo al menos 3 caracteres en el campo de búsqueda
   - **Cuando** el sistema muestra la lista de coincidencias y selecciono una
   - **Entonces** el plato se agrega al carrito con cantidad 1 y su precio vigente.

2. **Escenario: agregar por código interno**
   - **Cuando** tecleo el código "P-0010" y confirmo
   - **Entonces** se agrega "Pique macho" al carrito.

3. **Escenario: plato repetido**
   - **Dado** que "Empanada de queso" ya está en el carrito con cantidad 2
   - **Cuando** la agrego nuevamente
   - **Entonces** la línea existente pasa a cantidad 3 y no se crea una línea duplicada.

4. **Escenario: plato inexistente o fuera de carta**
   - **Cuando** busco un código que no existe o corresponde a un plato inactivo
   - **Entonces** el sistema muestra "Producto no encontrado o inactivo" y no agrega nada.

**Definición de Terminado**

- El precio se toma siempre del menú, nunca se digita.
- La operación es 100% operable con teclado.
- Un plato ocupa una sola línea por venta (índice único `uq_detalle_venta_producto`).
- Desde el Sprint 4, lo que se cobra en el carrito se vuelve un pedido que llega a la cocina
  (HU-55).

---

### HU-11 — Cobrar la venta y calcular el vuelto

> **Como** cajero
> **quiero** registrar el cobro de la venta y ver el vuelto calculado
> **para** cerrar la operación sin errores de aritmética.

**Prioridad:** Must · **Puntos:** 5 · **Sprint:** 2

**Criterios de aceptación**

1. **Escenario: cobro en efectivo con vuelto**
   - **Dado** un carrito con total 45.50 y método de pago "Efectivo"
   - **Cuando** ingreso 50.00 como monto recibido y confirmo
   - **Entonces** el sistema registra la venta, muestra vuelto = 4.50 y limpia el carrito.

2. **Escenario: monto insuficiente**
   - **Dado** un carrito con total 45.50
   - **Cuando** ingreso 40.00 como monto recibido
   - **Entonces** el sistema muestra "El monto recibido es menor al total" y **no** registra la venta.

3. **Escenario: carrito vacío**
   - **Cuando** intento cobrar sin ítems en el carrito
   - **Entonces** el botón de cobro está deshabilitado.

4. **Escenario: atomicidad**
   - **Dado** que ocurre un error al emitir el comprobante durante el cobro
   - **Entonces** la venta completa se revierte (no queda venta, ni detalle, ni pagos)
   - **Y** se muestra un mensaje de error al usuario.

**Definición de Terminado**

- La venta, su detalle, sus pagos y su comprobante se guardan dentro de una única
  transacción de base de datos.
- La venta queda asociada al usuario, a la sesión de caja y a la fecha/hora del servidor.
- Con la configuración por omisión (`precios_incluyen_impuesto = 0`) el precio del plato es
  la base imponible y el impuesto se suma aparte (`total = subtotal − descuento +
  impuesto`); con precios con impuesto incluido, el total es lo que dice la carta.

---

### HU-13 — Emitir comprobante numerado con serie y correlativo

> **Como** cajero
> **quiero** que cada venta genere un comprobante con serie y número correlativo
> **para** entregar al cliente un documento formal y mantener el orden contable.

**Prioridad:** Must · **Puntos:** 5 · **Sprint:** 3

**Criterios de aceptación**

1. **Escenario: correlativo automático**
   - **Dado** que la serie "R001" de recibos tiene como último número el 000125
   - **Cuando** se emite el comprobante de una nueva venta con ese tipo de documento
   - **Entonces** el comprobante queda con número "R001-000126"
   - **Y** el correlativo de la serie se incrementa.

2. **Escenario: sin saltos ni duplicados**
   - **Dado** que dos cajeros confirman una venta en el mismo instante
   - **Entonces** cada comprobante obtiene un número distinto y consecutivo, sin huecos.

3. **Escenario: la serie determina el documento**
   - **Dado** que cada serie pertenece a un tipo de comprobante (F001 → Factura, R001 → Recibo)
   - **Cuando** el cajero elige el tipo de documento
   - **Entonces** el sistema usa la serie configurada para ese tipo
   - **Y** no es posible emitir una factura numerada con una serie de recibos.

4. **Escenario: contenido del comprobante**
   - **Entonces** el comprobante muestra: datos del negocio, tipo y número de documento,
     fecha y hora, cajero, cliente (o "Cliente varios"), el número del pedido y si es para
     comer aquí o para llevar (HU-52), detalle de ítems con cantidad, precio unitario e importe, subtotal, impuesto,
     descuento, total, método de pago y vuelto.

**Definición de Terminado**

- La asignación del correlativo se realiza con bloqueo de fila (`SELECT ... FOR UPDATE`)
  dentro de la transacción de la venta.
- Una venta no puede tener más de un comprobante vigente.
- Lo pagado tiene que sumar el total de la venta antes de emitir: lo comprueba
  `trg_comprobantes_before_insert`.

---

### HU-39 — Registrar el cliente como persona natural o jurídica

> **Como** administrador
> **quiero** registrar a mis clientes diferenciando persona natural de persona jurídica
> **para** pedirle a cada uno los datos que corresponden y poder emitirle el documento correcto.

**Prioridad:** Must · **Puntos:** 5 · **Sprint:** 3

**Criterios de aceptación**

1. **Escenario: alta de persona natural**
   - **Dado** que elijo el tipo de persona "Natural"
   - **Entonces** el formulario pide **nombres, apellidos** y documento CI, CE o pasaporte
     (o NIT, si la persona factura como unipersonal)
   - **Y** oculta razón social, nombre comercial y representante legal
   - **Cuando** guardo con nombres y apellidos completos
   - **Entonces** el cliente queda registrado y se muestra como "Carlos Mendoza Ríos".

2. **Escenario: alta de persona jurídica**
   - **Dado** que elijo el tipo de persona "Jurídica"
   - **Entonces** el formulario pide **razón social, NIT y dirección fiscal** como obligatorios,
     y opcionalmente nombre comercial y representante legal
   - **Y** oculta nombres, apellidos y fecha de nacimiento
   - **Cuando** guardo, el cliente se muestra por su razón social.

3. **Escenario: datos incompletos**
   - **Cuando** intento guardar una persona jurídica sin NIT o sin dirección fiscal
   - **Entonces** el sistema no guarda y señala los campos faltantes.

4. **Escenario: documento duplicado**
   - **Cuando** registro un NIT o CI que ya existe
   - **Entonces** el sistema muestra "Ya existe un cliente con ese documento".

5. **Escenario: búsqueda unificada**
   - **Cuando** busco un cliente desde la pantalla de cobro
   - **Entonces** puedo encontrarlo por documento o por nombre, sin importar su tipo de persona
   - **Y** el resultado muestra una etiqueta que indica si es Natural o Jurídica.

**Definición de Terminado**

- Las reglas de obligatoriedad se validan en el cliente, en el servidor **y** en la base de
  datos (`ck_clientes_natural`, `ck_clientes_juridica`).
- El listado de clientes permite filtrar por tipo de persona.
- Registrar un cliente es del mostrador; corregirlo o eliminarlo exige `clientes.editar` o
  `registros.eliminar`, que por omisión solo tiene el administrador.

---

### HU-40 — Emitir factura a persona jurídica y recibo a persona natural

> **Como** cajero
> **quiero** que el sistema emita el documento que corresponde al tipo de cliente
> **para** entregar factura a las empresas y recibo a los clientes comunes, sin equivocarme.

**Prioridad:** Must · **Puntos:** 5 · **Sprint:** 3

**Criterios de aceptación**

1. **Escenario: cliente jurídico → factura**
   - **Dado** que la venta tiene asociado un cliente de tipo Jurídica
   - **Cuando** confirmo la venta
   - **Entonces** el sistema propone **Factura** con la serie F001
   - **Y** el documento se emite con la razón social, el NIT, la dirección fiscal y el
     desglose de impuesto por ítem.

2. **Escenario: cliente natural → recibo**
   - **Dado** que la venta tiene asociado un cliente de tipo Natural, o ningún cliente
   - **Cuando** confirmo la venta
   - **Entonces** el sistema propone **Recibo** con la serie R001
   - **Y** el documento se emite con el nombre completo del cliente, o "Cliente varios" si no
     se registró ninguno.

3. **Escenario: combinación inválida**
   - **Cuando** intento emitir una factura a un cliente de tipo Natural sin NIT
   - **Entonces** el sistema rechaza la emisión con el mensaje "El tipo de comprobante no
     corresponde al tipo de persona del cliente"
   - **Y** no se consume el correlativo de la serie.

4. **Escenario: persona natural con NIT**
   - **Dado** un cliente persona natural registrado con NIT (unipersonal)
   - **Entonces** sí puede recibir factura a su nombre.

5. **Escenario: factura sin cliente identificado**
   - **Cuando** intento emitir una factura sin haber seleccionado un cliente
   - **Entonces** el sistema exige seleccionar o registrar un cliente con NIT.

6. **Escenario: datos congelados en el documento**
   - **Dado** una factura ya emitida a "Servicios Generales del Oriente S.R.L."
   - **Cuando** después se corrige la razón social o la dirección de ese cliente
   - **Entonces** la factura emitida conserva los datos que tenía al momento de emitirse.

7. **Escenario: anulación**
   - **Cuando** se anula la venta
   - **Entonces** el comprobante queda en estado "Anulado", conserva su número y no se
     reutiliza el correlativo.

**Definición de Terminado**

- La correspondencia documento ↔ tipo de persona se valida en la base de datos
  (`trg_comprobantes_before_insert`), no solo en la interfaz.
- Una venta no puede tener dos comprobantes vigentes (índice único sobre `venta_vigente_uk`).
- La emisión ocurre dentro de la misma transacción de la venta.

---

### HU-43 — Cobrar y emitir recibo sin registrar al cliente

> **Como** cajero
> **quiero** cobrar y entregar el recibo sin tener que registrar al cliente
> **para** no demorar el cobro, que es el caso de casi todos los pedidos.

**Prioridad:** Must · **Puntos:** 2 · **Sprint:** 3

**Criterios de aceptación**

1. **Escenario: cobro sin cliente (camino por defecto)**
   - **Dado** un carrito con platos y **ningún** cliente seleccionado
   - **Cuando** cobro
   - **Entonces** el sistema registra la venta sin cliente y emite el **recibo**
   - **Y** el recibo sale a nombre de "Cliente varios"
   - **Y** en ningún momento se me pide un dato del cliente.

2. **Escenario: el campo cliente nunca bloquea**
   - **Dado** que estoy en la pantalla de cobro
   - **Entonces** el campo de cliente aparece vacío con el texto "Cliente varios"
   - **Y** el botón de cobro está habilitado sin haberlo tocado.

3. **Escenario: el cliente pide factura**
   - **Cuando** cambio el tipo de documento a Factura
   - **Entonces** recién ahí el sistema exige seleccionar o registrar un cliente con NIT y
     dirección fiscal
   - **Y** si cancelo, la venta vuelve a recibo sin cliente y puedo cobrar de inmediato.

4. **Escenario: cliente opcional identificado**
   - **Cuando** sí selecciono un cliente persona natural
   - **Entonces** el recibo sale a su nombre y la venta aparece en su historial de consumo.

5. **Escenario: reportes**
   - **Entonces** las ventas sin cliente se contabilizan normalmente en los totales del día
   - **Y** no aparece ningún "cliente" ficticio encabezando el reporte de mejores clientes.

**Definición de Terminado**

- La venta sin cliente se guarda con `cliente_id = NULL`; **no** existe un registro de
  cliente genérico en el maestro.
- El texto impreso proviene de `configuracion.cliente_generico_nombre`, es editable y no
  está escrito en el código.
- Todas las consultas que muestran el cliente de una venta usan `LEFT JOIN`.

---

### HU-42 — Sustituir el recibo por una factura cuando el cliente la solicita después

> **Como** cajero
> **quiero** reemplazar el recibo ya entregado por una factura a nombre de la empresa del cliente
> **para** resolver el pedido sin anular una venta que está correcta.

**Prioridad:** Should · **Puntos:** 5 · **Sprint:** 6

**Contexto:** el pedido se cobró sin cliente y salió con recibo; el comensal vuelve y dice que
era un almuerzo de trabajo y necesita factura. La comida se sirvió y el dinero entró: la venta
está bien, lo único que cambia es el documento.

**Criterios de aceptación**

1. **Escenario: sustitución exitosa**
   - **Dado** una venta cobrada con el recibo "R001-000002" emitido hoy
   - **Cuando** busco la venta, elijo "Cambiar comprobante", selecciono Factura y registro o
     selecciono al cliente con NIT
   - **Entonces** se emite la factura "F001-000002" a nombre de la empresa
   - **Y** el recibo queda en estado "Sustituido" y deja de ser el documento vigente
   - **Y** la venta queda asociada a ese cliente.

2. **Escenario: la venta no se toca**
   - **Entonces** los pagos **no** se modifican y el total de ventas del día **no** cambia
   - **Y** la venta sigue contando una sola vez en los reportes.

3. **Escenario: correlativo nuevo**
   - **Entonces** la factura toma el siguiente número de su propia serie
   - **Y** el número del recibo sustituido no se reutiliza ni se borra.

4. **Escenario: motivo y trazabilidad obligatorios**
   - **Cuando** confirmo la sustitución
   - **Entonces** el sistema exige un motivo
   - **Y** registra en la bitácora quién la hizo, cuándo, qué documento reemplazó a cuál.

5. **Escenario: fuera de plazo**
   - **Dado** que la venta es de hace más de los días permitidos en la configuración
   - **Cuando** intento sustituir el comprobante
   - **Entonces** el sistema lo rechaza indicando que la venta excede el plazo permitido.

6. **Escenario: venta anulada**
   - **Cuando** intento sustituir el comprobante de una venta anulada
   - **Entonces** el sistema lo rechaza: primero se corrige la venta, no el documento.

7. **Escenario: sustituir dos veces**
   - **Cuando** intento sustituir un comprobante que ya fue sustituido
   - **Entonces** el sistema lo rechaza; solo el documento vigente puede reemplazarse
   - **Y** sí puedo sustituir el nuevo documento vigente si hiciera falta.

8. **Escenario: consulta del historial**
   - **Cuando** abro el detalle de la venta
   - **Entonces** veo el documento vigente y, debajo, el historial de documentos anteriores
     con su motivo y su fecha de sustitución.

**Definición de Terminado**

- La operación completa ocurre en una transacción; si la emisión de la factura falla (por
  ejemplo, el cliente no tiene NIT), el recibo original **sigue siendo el vigente**.
- El invariante "un solo comprobante vigente por venta" lo garantiza la base de datos
  (`uq_comprobante_vigente`), no la aplicación.
- Exige el permiso `ventas.anular`, el mismo que anular una venta.
- Ningún comprobante se elimina ni se edita: la cadena queda consultable en
  `v_comprobantes_sustituidos`.

---

### HU-27 — Cerrar caja con arqueo y cálculo de diferencia

> **Como** administrador
> **quiero** cerrar el turno de caja declarando el efectivo contado junto al cajero
> **para** que quede registrada la diferencia respecto de lo que el sistema esperaba.

**Prioridad:** Must · **Puntos:** 5 · **Sprint:** 5

**Criterios de aceptación**

1. **Escenario: cierre cuadrado**
   - **Dado** un turno con monto inicial 100.00, cobros en efectivo por 850.00, ingresos por 0.00 y egresos por 50.00
   - **Cuando** declaro 900.00 como efectivo contado
   - **Entonces** el sistema calcula esperado = 900.00, diferencia = 0.00 y marca el cierre como "Cuadrado".

2. **Escenario: faltante**
   - **Cuando** declaro 880.00 sobre un esperado de 900.00
   - **Entonces** la diferencia es -20.00, el cierre se marca como "Faltante" y se exige un comentario obligatorio.

3. **Escenario: los cobros que no son efectivo no cuentan**
   - **Dado** que en el turno se cobraron 300.00 con tarjeta y 120.00 por QR
   - **Entonces** esos montos aparecen en el resumen pero **no** suman al efectivo esperado.

4. **Escenario: las anulaciones no cuentan**
   - **Dado** una venta de 60.00 en efectivo anulada durante el turno
   - **Entonces** su efectivo deja de sumar al esperado.

5. **Escenario: caja ya cerrada**
   - **Cuando** intento cobrar con una sesión de caja cerrada
   - **Entonces** el sistema me obliga a abrir una nueva caja.

6. **Escenario: resumen del turno**
   - **Entonces** el cierre muestra el total cobrado desglosado por método de pago, la
     cantidad de ventas y las anulaciones del turno.

**Definición de Terminado**

- `esperado = monto inicial + cobrado en efectivo + ingresos − egresos`. No hay devoluciones
  que restar: una venta mal cobrada se anula y su efectivo deja de contarse.
- El cajero abre su turno pero **no** lo cierra: por omisión solo el Administrador tiene
  `caja.cerrar`. El arqueo lo hace quien no tuvo la mano en el cajón durante el turno.
- Si quedan pedidos para volver a cobrar, el cierre los muestra y exige confirmarlo (HU-61).

---

### HU-61 — Cerrar la caja sabiendo qué pedidos quedan para volver a cobrar

> **Como** administrador
> **quiero** ver, al cerrar el turno, los pedidos de una venta anulada que falta volver a cobrar
> **para** no cerrar la caja con platos servidos sin cobrar sin que nadie se entere.

**Prioridad:** Should · **Puntos:** 2 · **Sprint:** 5

**Criterios de aceptación**

1. **Escenario: sin pendientes**
   - **Dado** que no hay pedidos para volver a cobrar
   - **Entonces** el cierre es el de siempre (HU-27).

2. **Escenario: con pendientes**
   - **Dado** que el pedido 12 quedó para volver a cobrar tras anular su venta (HU-60)
   - **Cuando** voy a cerrar la caja
   - **Entonces** el cierre lista los pedidos para volver a cobrar
   - **Y** no cierra hasta que marco que cierro con ellos pendientes.

3. **Escenario: el turno siguiente los cobra**
   - **Dado** que cerré con el pedido 12 pendiente
   - **Entonces** sigue en "Volver a cobrar" del punto de venta para el turno siguiente.

**Definición de Terminado**

- No se prohíbe cerrar: se exige saberlo. La confirmación se comprueba en el servidor
  (`Cajas::cerrar()`), con el turno bloqueado, y no solo en la pantalla.
- La lista son todos los pedidos para volver a cobrar del local, no solo los del turno que se cierra.

---

### HU-29 — Anular una venta con motivo

> **Como** administrador
> **quiero** anular una venta mal cobrada, dejando su motivo
> **para** corregirla sin borrar nada y con un responsable identificado.

**Prioridad:** Must · **Puntos:** 5 · **Sprint:** 5

**Criterios de aceptación**

1. **Escenario: anulación**
   - **Dado** una venta `COMPLETADA` de un turno de caja todavía abierto
   - **Cuando** la anulo con el motivo "Se cobró con tarjeta y pagó en efectivo"
   - **Entonces** la venta queda `ANULADA` con mi usuario, la fecha y el motivo
   - **Y** su comprobante queda `ANULADO`, conservando su correlativo.

2. **Escenario: turno cerrado**
   - **Dado** una venta cuyo turno de caja ya cerró
   - **Cuando** intento anularla
   - **Entonces** el sistema lo rechaza: ese dinero ya se contó en su arqueo.

3. **Escenario: motivo obligatorio**
   - **Cuando** intento anular sin motivo, o con un motivo de menos de 5 caracteres
   - **Entonces** el sistema no lo permite.

4. **Escenario: anular dos veces**
   - **Cuando** intento anular una venta ya anulada
   - **Entonces** el sistema lo rechaza.

5. **Escenario: sin devolución parcial**
   - **Dado** que el cliente reclama un solo plato de un pedido de cinco
   - **Entonces** no existe la devolución parcial: se anula la venta entera, el pedido pasa a
     volver a cobrar (HU-60), se cancela ese plato y se cobra de nuevo lo que corresponde.

**Definición de Terminado**

- La anulación es la **única** corrección de una venta cobrada.
- Exige el permiso `ventas.anular`; un rol hecho a medida que lo tenga sin ver todas las
  ventas solo anula las suyas.
- `sp_anular_venta` escribe en la bitácora (`ANULAR_VENTA`).
- Si la venta cobró un pedido, en la misma transacción el pedido pasa a volver a cobrar
  (HU-60).

---

### HU-60 — Cobrar de nuevo o cancelar el pedido de una venta anulada

> **Como** cajero
> **quiero** que al anular una venta su pedido quede para volver a cobrarlo, con el mismo número
> **para** rehacer el cobro sin que la cocina reciba otro pedido por los mismos platos.

**Prioridad:** Must · **Puntos:** 5 · **Sprint:** 5

**Contexto:** el cajero cobró el pedido 7 con la forma de pago equivocada. En ese momento el
cliente ya tiene su ticket con el 7 y la cocina ya lo está preparando. Si hubiera que cargar
el pedido de nuevo en el mostrador, saldría un "pedido 8" con los mismos platos repetidos en
la cocina.

**Criterios de aceptación**

1. **Escenario: anular deja el pedido para volver a cobrar**
   - **Dado** el pedido 7, cobrado
   - **Cuando** el administrador anula su venta (HU-29)
   - **Entonces** el pedido 7 vuelve a quedar abierto, con sus platos tal como estaban
   - **Y** sigue en la pantalla de la cocina
   - **Y** aparece en "Volver a cobrar" del punto de venta
   - **Y** la cocina lo ve marcado "Cobro anulado".

2. **Escenario: se cobra de nuevo**
   - **Cuando** lo cobro desde "Volver a cobrar" con la forma de pago correcta
   - **Entonces** se registra una venta nueva, con su comprobante nuevo
   - **Y** el pedido sigue siendo el 7.

3. **Escenario: cancelar un plato**
   - **Dado** que el cliente ya no quiere el postre, que la cocina no terminó
   - **Cuando** lo cancelo
   - **Entonces** queda `CANCELADO`, visible en el pedido, y no se cobra
   - **Y** un plato `LISTO` o `ENTREGADO` ya no se cancela: ya está hecho.

4. **Escenario: cancelar el pedido**
   - **Cuando** cancelo el pedido 7 con el motivo "El cliente se retiró"
   - **Entonces** queda `CANCELADO`, con fecha de cierre y mi usuario, y sale de la cocina
   - **Y** si ya había salido en la comanda, se me ofrece imprimir el aviso de cancelación (HU-57).

5. **Escenario: la cocina ya empezó**
   - **Dado** que la cocina ya empezó, terminó o entregó algún plato del pedido
   - **Cuando** un cajero intenta cancelarlo
   - **Entonces** el sistema lo rechaza: cancelarlo es dejar sin cobrar lo consumido, y solo
     lo hace quien puede anular ventas (`ventas.anular`, por omisión el administrador).

6. **Escenario: motivo obligatorio**
   - **Cuando** intento cancelar el pedido sin motivo
   - **Entonces** el sistema lo rechaza.

7. **Escenario: no se le agregan platos**
   - **Entonces** a un pedido con el cobro anulado no se le agrega nada: se cobra lo que ya tenía.

8. **Escenario: los pedidos no se borran**
   - **Entonces** no existe forma de eliminar un pedido: la base rechaza el `DELETE`
     (`trg_pedidos_before_delete`).

**Definición de Terminado**

- `Pedidos::reabrirTrasAnular()` corre dentro de la transacción de `Ventas::anular()`, y no
  en `sp_anular_venta`, para que valga igual con y sin los procedimientos de la base. Deja
  `PEDIDO_REABIERTO` en la bitácora; cancelar deja `PEDIDO_CANCELADO`.
- Cobrar exige `ventas.registrar` y un turno de caja abierto (HU-49); cancelar el pedido o uno
  de sus platos, `pedidos.registrar` (Cajero y Administrador).
- Es un camino de corrección, no una forma de cobrar después: el pedido ya se pagó una vez,
  y el único que espera cobro es el de una venta anulada.

---

### HU-32 — Reporte de ventas por rango de fechas

> **Como** administrador
> **quiero** consultar las ventas de un período con filtros
> **para** conocer el desempeño del restaurante y detectar irregularidades.

**Prioridad:** Must · **Puntos:** 5 · **Sprint:** 5

**Criterios de aceptación**

1. **Escenario: consulta por período**
   - **Cuando** selecciono un rango de fechas
   - **Entonces** veo el listado de ventas con fecha, comprobante, cliente, cajero, método de pago, estado y total
   - **Y** veo los totales: número de ventas, monto total, ticket promedio y monto anulado.

2. **Escenario: filtros combinados**
   - **Cuando** filtro además por usuario y por método de pago
   - **Entonces** los resultados y los totales reflejan solo las ventas que cumplen todos los filtros.

3. **Escenario: ventas anuladas**
   - **Entonces** las ventas anuladas se muestran marcadas y **no** se suman al monto total vendido.

4. **Escenario: sin ganancia**
   - **Entonces** el reporte dice **cuánto se vendió**, no cuánto se ganó: el plato se prepara
     en la casa y no hay costo de compra con el que calcular un margen.

5. **Escenario: sin resultados**
   - **Cuando** no hay ventas en el período
   - **Entonces** se muestra "No se encontraron ventas para los filtros seleccionados".

---

## 2.6 Definición de Terminado (global)

Una historia se considera terminada cuando:

1. Cumple todos sus criterios de aceptación.
2. El código fue revisado por otro integrante del equipo.
3. Tiene pruebas automatizadas de la lógica de negocio y estas pasan.
4. Valida los datos tanto en el cliente como en el servidor.
5. Respeta el control de acceso por rol y por permiso.
6. Las operaciones que tocan dinero o el estado de un pedido se ejecutan en transacción.
7. Está documentada y desplegada en el ambiente de pruebas.
