# 1. Definición de la Problemática

**Proyecto:** Sistema de Restaurante
**Documento:** 01 — Problemática y planteamiento
**Versión:** 3.0

---

## 1.1 Contexto

El negocio es un restaurante de barrio que atiende **en el mostrador**. El cliente se acerca
a la caja, pide y paga —primero se paga—; se lleva un papel con su número y se sienta donde
quiera, o espera su pedido **para llevar**. Cuando el plato está
listo, alguien lo lleva cantando el número y el cliente lo reconoce por su papel. Sirve
entre 60 y 120 pedidos por día, con una carta de unas decenas de platos repartidos en cuatro
secciones (entradas, platos de fondo, bebidas y postres), y trabaja con tres o cuatro
personas por turno: quien cobra, quien cocina y quien lleva los platos.

Hoy **el servicio entero se sostiene en papel y en la memoria de la gente**:

- El cajero anota el pedido en una libreta y arranca la hoja para la cocina.
- Cuando hay cola, el pedido se canta de viva voz por la ventanilla y no queda escrito.
- El número se lleva de cabeza o con fichas de cartón: se repite, se salta o vuelve a
  empezar a medianoche aunque el local siga abierto.
- La cocina no tiene forma de decir "el siete ya está" salvo gritarlo, y los refrescos
  que no hay que cocinar se mezclan en la misma hoja que los platos.
- La cuenta se suma a mano sobre la misma hoja, con los precios que el cajero recuerda.
- Si un cobro sale mal, se rehace "de palabra", y a veces el pedido vuelve a entrar a la
  cocina con otro número.
- Al cierre se cuenta el efectivo del cajón y se compara con el montón de hojas del día.
- Nadie sabe qué plato se vende más ni a qué hora se llena el local.

## 1.2 Problema Central

> **El restaurante no tiene un registro confiable, oportuno y trazable de lo que cada
> cliente pide y paga, de lo que la cocina prepara ni de a quién se le entrega, lo que
> provoca pedidos perdidos o entregados al cliente equivocado, cuentas mal sumadas, demoras
> en el servicio, descuadres de caja y decisiones tomadas sin datos.**

## 1.3 Causas Identificadas

| #  | Causa | Descripción |
|----|-------|-------------|
| C1 | La comanda vive en papel o en la voz | La hoja se pierde, se moja o se traspapela entre las que la cocina ya despachó; lo que se cantó no queda escrito en ninguna parte. |
| C2 | Mostrador y cocina no comparten un estado | El cajero no sabe si un pedido está pendiente, en preparación o listo, así que va a preguntar; la cocina no sabe si lo que dejó en la barra ya se entregó. |
| C3 | No se sabe de quién es cada plato | Sin un número confiable en el papel del cliente, un plato termina en la persona equivocada y otro queda esperando en la barra. |
| C4 | La cuenta se suma a mano | Sumas y vueltos de cabeza, con precios recordados de memoria y sin impuesto desglosado. |
| C5 | El número del pedido se lleva a mano | Dos "siete" en la misma noche, o el contador que vuelve a 1 a medianoche con el local todavía lleno. |
| C6 | Precios no centralizados | Cada cajero puede cobrar un precio distinto por el mismo plato, y una subida de carta tarda días en llegar a todos. |
| C7 | Sin control de caja por turno | No se sabe quién abrió o cerró el cajón ni cuánto dinero debería haber al cierre. |
| C8 | Sin comprobante formal | No hay documento numerado que entregar al cliente que lo pide, ni orden contable detrás. |
| C9 | Ausencia de reportes | No hay datos de platos más vendidos, ventas por día ni por cajero. |
| C10 | Correcciones informales | Un cobro equivocado se arregla "de palabra", sin que quede quién lo corrigió ni por qué, y el pedido se vuelve a cargar con otro número y otra hoja para la cocina. |

## 1.4 Efectos (Consecuencias)

- **Platos que nunca salen:** la comanda se perdió y el cliente lleva media hora esperando
  algo que la cocina no sabe que tiene que hacer.
- **Platos que salen dos veces:** la misma comanda se canta y se escribe, o el pedido se
  rehace con otro número, y la cocina lo prepara dos veces; la segunda se tira.
- **Platos entregados a quien no era:** dos clientes con el mismo número, o con un número
  que nadie anotó, y el plato llega al primero que levanta la mano.
- **Cuentas mal cobradas:** de menos, y el negocio lo paga; de más, y el cliente no vuelve.
- **Servicio lento:** la cola de la caja crece mientras se buscan precios, se suma a mano y
  se va y viene a la cocina a preguntar.
- **Roces entre caja y cocina:** cuando algo falla, no hay forma de saber si la comanda no
  llegó, llegó tarde o se preparó y nadie la recogió.
- **Descuadres de caja:** al cierre no se puede determinar si la diferencia fue un error de
  suma, un descuento que alguien regaló o un cobro que nunca entró.
- **Decisiones a ciegas:** no se sabe qué plato conviene mantener en la carta, cuál sobra ni
  a qué hora hace falta más gente.

## 1.5 Árbol de Problemas

```
   EFECTOS    Pedidos perdidos, repetidos o entregados a quien no era ·
              Cuentas mal cobradas · Servicio lento · Descuadres de caja ·
              Decisiones sin información
                                     ▲
                                     │
  PROBLEMA    No existe un registro confiable, oportuno y trazable de lo que
              cada cliente pide y paga, de lo que la cocina prepara y de a
              quién se entrega
                                     ▲
                                     │
    CAUSAS    Comanda en papel o de voz · Mostrador y cocina sin estado común ·
              Número llevado a mano · Cuentas sumadas a mano · Precios
              dispersos · Sin control de caja · Sin comprobantes · Sin reportes
```

## 1.6 Solución Propuesta

Desarrollar un **Sistema de Restaurante (aplicación web)** que cubra el ciclo completo del
servicio de mostrador, desde que el cliente pide en la caja hasta que recibe su plato:

1. **Punto de venta de mostrador:** el cajero carga los platos, elige **comer aquí** (lo
   habitual) o **para llevar**, anota si quiere un nombre para llamar al cliente y una
   **nota para la cocina** por plato ("sin cebolla", "término medio"), y cobra. En ese mismo
   acto el sistema crea el **pedido**, lo pone en la cocina y lo cobra: o queda todo, o no
   queda nada.
2. **Número del pedido por jornada:** cada pedido lleva un número corto que empieza en 1 cada
   jornada y es el que se canta al entregar. La jornada empieza a una **hora de corte**
   configurable (las 5 de la mañana por omisión): lo pedido a la 01:30 sigue la numeración
   de la noche anterior. Dos cajeros cobrando a la vez no pueden sacar el mismo número.
3. **Cocina:** una pantalla propia, sin nada de dinero, que muestra cada pedido por su
   **número en grande**, con "comer aquí" o "para llevar" y las notas; cada plato avanza
   `PENDIENTE → EN_PREPARACION → LISTO → ENTREGADO`. Cuando todo el pedido está listo se
   destaca, y quien lleva los platos lo marca **entregado** de un toque. Solo muestra la
   jornada en curso.
4. **Comanda impresa:** un papel de 80 mm (o A4) por pedido para la cocina, sin precios ni
   bebidas, que no repite lo que ya salió, se puede **reimprimir** y **avisa en papel** lo
   que se canceló después de mandarlo.
5. **Menú y precios centralizados:** la carta se administra en un solo lugar, cada plato con
   **un solo precio**, y los descuentos por encima de un umbral requieren autorización. Cada
   sección de la carta dice si **pasa por la cocina**: las bebidas se cobran, pero no llenan
   la pantalla ni la comanda.
6. **Comprobantes numerados:** **factura** y **recibo** con serie y correlativo automático,
   imprimibles en A4 o en ticket de 80 mm. El ticket lleva bien grande `PEDIDO #7` y
   `COMER AQUÍ` o `PARA LLEVAR`: es el papel que el cliente muestra al recibir su plato.
7. **Control de caja por turno:** apertura con monto inicial, ingresos y egresos, cierre con
   arqueo y cálculo de diferencia. Si queda algún pedido para volver a cobrar (el de una
   venta anulada), el cierre lo muestra y exige confirmarlo.
8. **Clientes:** registro **opcional**, diferenciado entre **persona natural** y **persona
   jurídica**. El pedido se cobra y se documenta con recibo sin pedir ningún dato; solo la
   factura exige identificar al cliente.
9. **Anulación auditable:** la única corrección de una venta ya cobrada es **anularla**
   entera, con motivo, responsable y turno de caja abierto. Lo que se sirvió no vuelve a la
   carta, así que no hay devoluciones parciales. Anular no borra el pedido: pasa a **volver
   a cobrar**, con su mismo número y sus platos en la cocina, para rehacer el cobro bien o
   cancelarlo.
10. **Reportes y dashboard:** ventas por período, por plato, por cajero y por método de pago.

## 1.7 Alcance

### Dentro del alcance

- Registro de personal: empleados con su cargo y vínculo laboral (ingreso, contrato, cese).
- Autenticación, cuentas de usuario y roles de acceso (Administrador, Cajero, **Cocina**).
  El **cargo** del empleado y el **rol** de su cuenta son independientes.
- **Menú:** secciones de la carta (categorías), con la marca de si pasan por la cocina, y
  platos con su precio único.
- **Punto de venta de mostrador:** carrito, comer aquí o para llevar, nombre para llamar al
  cliente, nota por plato, cobro, comprobante, pago mixto y cobro por QR. Cada venta es un
  **pedido** con su número de la jornada.
- **Cocina:** pantalla de preparación, avance de estado por plato, entrega del pedido
  completo y **comanda impresa** con su reimpresión.
- **Volver a cobrar:** el pedido cuya venta se anuló, que se cobra de nuevo con su mismo
  número o se cancela con su motivo.
- Clientes persona natural y persona jurídica.
- Emisión de factura y recibo con series y correlativos independientes.
- Sustitución del comprobante cuando el cliente pide factura después de recibido el recibo.
- Caja: apertura, movimientos, cierre y arqueo.
- Anulación de ventas.
- Reportes operativos y dashboard.
- Auditoría de operaciones sensibles y respaldos de la base.

### Fuera del alcance (versión 1)

- **Cobrar después del servicio.** Se cobra al pedir: el cliente paga en la caja antes de
  que el pedido llegue a la cocina, y el plato se entrega cantando el número del ticket.
- **Inventario de insumos.** El sistema no lleva stock, ni kardex, ni compras a proveedor, ni
  lotes con fecha de vencimiento. Un restaurante de este tamaño compra en el día y no lleva
  existencias de despensa en el sistema.
- **Recetas y escandallo.** No se registra de qué está hecho un plato ni cuánto cuesta
  producirlo, así que el sistema informa **cuánto se vendió, no cuánto se ganó**.
- **Delivery.** Ni reparto propio ni integración con plataformas de pedidos en línea. El
  pedido **para llevar** existe, pero se retira en el local.
- **Reservas.** El cliente se sienta donde quiera: no hay nada que apartar para las nueve.
- **Multi-sucursal.** Se asume un único local.
- Facturación electrónica ante la administración tributaria. El modelo queda **preparado**
  (tipo de documento, serie, correlativo, datos fiscales del cliente) pero no se integra el
  servicio web del organismo.
- Nota de crédito por la anulación de una factura: la anulación se registra y el comprobante
  queda `ANULADO`, pero no genera un documento tributario asociado en esta versión.
- Contabilidad general, planillas y libros contables. El sistema registra al empleado y su
  vínculo laboral, pero **no** calcula sueldos, asistencia ni horarios.
- Propinas y su reparto entre el personal.
- Aplicación móvil nativa. La pantalla de la cocina es la misma aplicación web en una
  tableta.

## 1.8 Objetivos

### Objetivo General

Implementar un sistema web de restaurante que registre cada pedido en el momento en que se
cobra en el mostrador, mantenga a caja y cocina mirando el mismo estado, entregue cada plato
a quien lo pidió sin sumar a mano y entregue información confiable para la gestión del
negocio.

### Objetivos Específicos

| #  | Objetivo | Indicador de éxito |
|----|----------|--------------------|
| O1 | Que ninguna comanda se pierda | 0 pedidos servidos que no estén registrados en el sistema |
| O2 | Acortar el camino caja ↔ cocina | La cocina ve el pedido en su pantalla a más tardar 10 segundos después de que el cajero lo cobra (la pantalla se refresca sola cada 10 s) |
| O3 | Eliminar el error de suma | 0 pedidos cobrados con un total distinto de la suma de sus líneas |
| O4 | Agilizar el cobro | Tiempo desde que el cliente termina de pedir hasta el ticket impreso menor a 1 minuto |
| O5 | Garantizar el cuadre de caja | 100% de los turnos con cierre registrado y diferencia justificada |
| O6 | Centralizar precios | 0 pedidos cobrados con un precio distinto al del menú sin autorización registrada |
| O7 | Disponer de información de gestión | Reporte de ventas y de platos más vendidos disponible en línea en todo momento |
| O8 | Garantizar trazabilidad | Todo pedido, cancelación, cobro, anulación, comanda y sustitución de comprobante identifica usuario, fecha, hora y motivo |
| O9 | Entregar cada plato a quien lo pidió | 0 números de pedido repetidos en una misma jornada y 0 pedidos con dos ventas asociadas (garantizado por la base de datos) |

## 1.9 Actores del Sistema

| Actor | Descripción | Responsabilidad principal |
|-------|-------------|---------------------------|
| **Administrador** | Dueño o gerente del restaurante | Administra el menú, los precios, los usuarios y los parámetros (entre ellos la hora de corte de la jornada); consulta reportes; cierra la caja; autoriza descuentos, anulaciones y la cancelación de un pedido que la cocina ya empezó. |
| **Cajero** | Atiende el mostrador | Abre su turno de caja, **toma el pedido y lo cobra en el mismo acto**, elige comer aquí o para llevar, emite comprobantes, imprime la comanda y registra ingresos y egresos. Vuelve a cobrar o cancela el pedido de una venta anulada. **No** cierra la caja. |
| **Cocina** | Prepara los platos | Ve en su pantalla qué hay que preparar, pedido por pedido y por su número, y mueve cada plato por sus estados; puede imprimir o reimprimir la comanda. **No ve dinero:** ni precios, ni ventas, ni caja. |
| **Quien lleva los platos** | Ayudante del local | Lleva el pedido listo **cantando su número**; el cliente lo confirma con su ticket. **No tiene cuenta en el sistema:** marca el pedido como entregado desde la pantalla de la cocina. |
| **Cliente persona natural** | Comensal individual | Pide y paga en la caja y recibe un **recibo** con el número de su pedido. Su registro es opcional: la mayoría de los pedidos se cobran sin identificarlo y el recibo sale a nombre de "Cliente varios". |
| **Cliente persona jurídica** | Empresa o institución | Recibe una **factura** a nombre de su razón social; debe estar registrado con NIT y dirección fiscal. |

## 1.10 Requisitos No Funcionales

| # | Requisito | Criterio |
|---|-----------|----------|
| RNF1 | Rendimiento | La pantalla de venta y la de cobro responden en menos de 1 segundo con la carta completa cargada. |
| RNF2 | Usabilidad | Un pedido de un plato se cobra en tres toques: plato, forma de pago, cobrar ("comer aquí" viene elegido). La cocina cambia un estado con un solo toque, y quien lleva los platos entrega el pedido entero con otro. |
| RNF3 | Disponibilidad | Opera en la red local del restaurante; no depende de conexión a internet permanente (salvo el cobro por QR, que sí la necesita). Si la pantalla de la cocina falla, la comanda impresa la reemplaza. |
| RNF4 | Seguridad | Contraseñas cifradas (hash), sesiones con expiración y control de acceso por rol y por permiso. |
| RNF5 | Integridad | Registrar un pedido se ejecuta en una sola transacción: o se guarda el pedido cobrado con su venta, su comprobante y sus pagos, o no se guarda nada —ni un pedido huérfano en la cocina ni un número de la jornada gastado—. |
| RNF6 | Auditabilidad | Ninguna venta, comprobante ni pedido se borra físicamente; las correcciones se hacen por anulación, cancelación o sustitución del documento. |
| RNF7 | Concurrencia | Dos cajeros que cobran en el mismo segundo no pueden sacar el mismo número de la jornada, y dos que vuelven a cobrar a la vez el mismo pedido no pueden dejar dos ventas. Lo garantiza la base de datos, no la aplicación. |
| RNF8 | Compatibilidad | Funciona en navegadores modernos, en equipos de gama baja (4 GB RAM) y en la tableta de la cocina. |
| RNF9 | Respaldo | Respaldo automático diario de la base en la instalación real, guardado en el servidor y en una carpeta de Google Drive. No tiene pantalla: el personal del local, incluido el administrador, no ve ni descarga la base. |

## 1.11 Restricciones y Supuestos

**Restricciones**

- Base de datos: **MySQL 8** con motor InnoDB y codificación `utf8mb4`.
- Aplicación web accesible desde navegador dentro de la red local del restaurante, incluida
  la tableta de la cocina.
- El presupuesto contempla una impresora térmica de 80 mm en caja (ticket y comanda) y una
  pantalla o tableta en la cocina.
- No hay lector de código de barras: **un plato no se escanea**. El buscador funciona por
  nombre y por código interno.

**Supuestos**

- El restaurante hará una carga inicial de la carta con sus precios antes de empezar.
- Cada usuario tiene su propia cuenta; no se comparten credenciales entre cajeros. La
  pantalla de la cocina usa la cuenta de la cocina, también para quien lleva los platos.
- Existe un único local, que atiende en el mostrador, y una única caja física en esta versión.
- La moneda es única y se define como parámetro del sistema.
- Todo se despacha **por porción**: no hay nada que se cobre por peso ni por volumen, así que
  las cantidades son siempre enteras.
- El local cierra antes de la hora de corte de la jornada: a esa hora no hay pedidos en
  curso.
