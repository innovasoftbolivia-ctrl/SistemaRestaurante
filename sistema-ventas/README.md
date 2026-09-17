# Sistema de Venta de Productos

Aplicación web del sistema descrito en [`../docs`](../docs). Lo entregado hasta ahora:

- **Personal, seguridad y usuarios:** ingreso al sistema, empleados, cargos, cuentas, roles y permisos.
- **Catálogo:** productos con foto, precios y stock; categorías, unidades de medida y
  proveedores, con kardex por producto.
- **Punto de venta:** mostrador con lector de código de barras, carrito, descuento, cobro y
  vuelto; caja por turno con arqueo; comprobantes (factura y recibo) imprimibles; clientes;
  anulación de ventas. Una venta puede **repartirse entre varias formas de pago** —una parte por
  QR, el resto en efectivo—, y al arqueo entra solo lo que pasó por el cajón.
- **Cobro por QR:** el código se genera con el **importe ya puesto**, así el cliente escanea y
  paga lo que debe sin teclear nada. La venta se registra recién cuando el pago está confirmado:
  un cliente que se arrepiente no deja stock descontado ni comprobante emitido. Sin convenio con
  el banco corre en modo simulado y lo confirma el cajero — ver el paso 9 de
  [Nueva instalación](#nueva-instalación-un-cliente).
- **Almacén:** existencias con alerta de faltantes, ingreso de mercadería a un producto que ya
  existe (sin darlo de alta otra vez), ajustes por merma y kardex global de movimientos.
- **Devoluciones:** totales y parciales, con reingreso selectivo de stock.
- **Sustitución de comprobante:** recibo → factura (y al revés) sin tocar la venta.
  La parte **tributaria** (tasa e identificación fiscal) está marcada como en construcción.
- **Reportes:** ventas por día, método de pago y cajero; ranking de productos y alertas de stock.
- **Portada:** turno propio, resumen del día y alertas, con los bloques que cada rol puede ver.

**Stack:** Laravel 13 · Blade · Alpine.js · Tailwind CSS 4 · MySQL 8
La interfaz usa la plantilla [TailAdmin](https://tailadmin.com/) para Laravel.

> Al ingresar, cada cuenta va a **su pantalla de trabajo** (`App\Support\Menu::inicio()`): quien
> lleva la gestión, a la portada; el cajero, directo al mostrador.

---

## Puesta en marcha

Hay dos formas, y basta con una. **Con Docker** no hay que instalar PHP ni Node en la máquina;
**a mano** es más ligero si ya los tienes y prefieres los procesos a la vista.

### Opción A — todo con Docker (recomendada)

Desde la **raíz del repositorio** (un nivel arriba de esta carpeta):

```bash
docker compose up -d --build
```

Antes del primer arranque, copia las plantillas de configuración (una vez por instalación —
desarrollo o cliente— no por cada `docker compose up`):

```bash
cp .env.example .env                                    # una carpeta arriba de esta
cp sistema-ventas/.env.docker.example sistema-ventas/.env.docker
```

Y completa en `sistema-ventas/.env.docker` la `APP_KEY` (`php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`)
y una contraseña en `DB_PASSWORD` —la misma que pongas en el `.env` de la raíz—. Sin este paso,
Docker sigue arrancando con una clave y contraseña de repaso que **no** deben usarse fuera de tu
propia máquina de desarrollo.

Y ya está: la aplicación queda en <http://localhost:8100>. La primera vez tarda unos minutos,
porque construye la imagen de PHP, carga `docs/sql` en MySQL e instala las dependencias de
Composer y npm dentro de los contenedores.

| Servicio | Dónde | Qué es |
|----------|-------|--------|
| Aplicación | <http://localhost:8100> | nginx + php-fpm |
| Adminer | <http://localhost:8101> | explorar la base; **no** arranca solo, ver abajo |
| MySQL | `127.0.0.1:13310` | `root` / la de tu `.env`, base `ventas_db`. Solo desde esta máquina |
| Vite | puerto 5174 | lo consume el navegador solo; no se abre a mano |

Los puertos van en el rango 81xx para no chocar con los otros proyectos del repositorio.

Adminer queda detrás de un perfil aparte: es un cliente de base de datos sin login propio, y no
tiene sentido dejarlo escuchando todo el tiempo en un servidor real.

```bash
docker compose --profile tools up -d adminer
```

El contenedor `app` se encarga solo del `composer install`, del `storage:link` y del seeder de
credenciales, así que no hay ningún paso manual detrás. Para seguir el arranque:

```bash
docker compose logs -f app
```

Órdenes de artisan dentro del contenedor:

```bash
docker compose exec app php artisan route:list
```

`docker compose down` detiene y conserva los datos; `docker compose down -v` los borra.

> La configuración del entorno Docker vive en `sistema-ventas/.env.docker` (plantilla:
> `.env.docker.example`, no se sube al repositorio). Llega a los contenedores como variables de
> entorno reales y Laravel les da prioridad sobre el `.env` del proyecto, que sigue siendo el
> bueno para la opción B.

### Opción B — a mano

#### 1. Base de datos

El esquema y los datos de ejemplo viven en `docs/sql` y los carga Docker al primer arranque.
Desde la **raíz del repositorio** (un nivel arriba de esta carpeta):

```bash
docker compose up -d mysql adminer
```

Deja MySQL en `127.0.0.1:13310` (usuario `root`, la contraseña de tu `.env` — `ventas123` si no
tienes uno, ver [Opción A](#opción-a--todo-con-docker-recomendada)) y Adminer en
<http://localhost:8101>.

Para volver a cargar el esquema a mano:

```bash
docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 -uroot -pventas123 < docs/sql/01_schema_mysql.sql
```

> El `--default-character-set=utf8mb4` no es opcional: sin él los acentos entran doblemente
> codificados y «Díaz» se guarda como «DÃ­az».

Una base creada antes del 2026-08-21 necesita los parches de `docs/sql/parches` (los instaladores
nuevos no: `01_schema_mysql.sql` ya incorpora los que corrigen el esquema). `scripts/aplicar-parches.sh`
lleva la cuenta de cuáles ya corrieron en cada base, en la propia tabla `parches_aplicados`, así
que no hay que acordarse a mano:

```bash
./scripts/aplicar-parches.sh              # solo muestra qué falta, no toca nada
./scripts/aplicar-parches.sh --aplicar    # aplica lo pendiente, en el orden correcto
```

Encuentra solo el contenedor de MySQL, de producción (`ventas_mysql_prod`) o de desarrollo
(`ventas_mysql`); si usa otro nombre, `VENTAS_MYSQL=...`. Los cuatro parches de catálogo del
2026-08-23 quedan fuera siempre, salvo que se pida `--catalogo`.

Para otra base que no sea `ventas_db` (por ejemplo, la de un cliente instalado aparte):

```bash
BASE=ventas_db_cliente2 ./scripts/aplicar-parches.sh --aplicar
```

**Ojo con los cuatro del 2026-08-23**: no corrigen nada del esquema, cargan el catálogo real de
*este* negocio de referencia (70 productos en «Abarrotes», 70 en «Bebidas», la categoría
«Cigarrillos», y `tasa_impuesto` en 0). Con los 12 productos de demostración el sistema funciona
igual, y una instalación para un cliente nuevo casi seguro **no** los quiere —le meterían el
catálogo de otro negocio—. Revisa la lista de pendientes antes de correr `--aplicar` a ciegas.

Si igual hace falta reponer este catálogo de referencia (por ejemplo, reconstruyendo el entorno
de desarrollo), el orden de esos cuatro importa: los de catálogo escriben los precios y el último
(`sin_impuesto`) alinea el resto sobre ellos — pone `tasa_impuesto` en 0, así que el precio que se
carga en el producto es el que paga el cliente, sin recargo. Las vistas se adaptan solas a ese
cero: el POS deja de mostrar la línea «Impuesto», y la ficha del producto pide un único precio en
vez de base y estante. Si algún día hace falta desglosar el IVA (13% en Bolivia), basta con volver
a poner la tasa en `configuracion` y recalcular los precios base; los comprobantes ya emitidos no
cambian, porque cada línea de venta congela su propia tasa.

El parche `2026_08_26_devolucion_solo_efectivo.sql` corrige el arqueo cuando se devuelve una venta
que **no** se cobró en efectivo: el procedimiento de cierre descontaba del cajón el importe
íntegro de toda devolución, así que reembolsar por tarjeta dejaba al cajero con un sobrante a su
nombre. Ahora descuenta solo la parte que en su día entró en efectivo. **Las sesiones ya
cerradas no se tocan**: su `monto_esperado` es el arqueo firmado de aquel turno.

#### 2. Aplicación

```bash
composer install
```

```bash
npm install
```

```bash
php artisan db:seed --class=CredencialesSeeder
```

El seeder es necesario porque `docs/sql/02_datos_iniciales.sql` deja un hash de ejemplo que no
corresponde a ninguna contraseña real.

```bash
php artisan storage:link
```

Ese enlace es el que hace visibles las fotos de los productos. Se crea una sola vez.

Y, **solo para una base de demostración**, la historia del almacén:

```bash
php artisan db:seed --class=DemostracionSeeder
```

El catálogo de `02_datos_iniciales.sql` deja los productos con stock pero sin pasado: cero
compras, cero devoluciones y ningún lote, con lo que las tres pantallas del almacén se ven
vacías. Este seeder enciende el control de vencimiento en las categorías que vencen —no en
limpieza ni higiene—, reparte el stock que ya existe en tandas con fechas escalonadas (incluidas
algunas ya vencidas y algunas sin fecha), y registra ocho facturas de proveedor del último mes y
medio con cinco devoluciones, una por cada motivo. Todo pasa por los servicios, así que el kardex
y los lotes cuadran. No va en `DatabaseSeeder` a propósito: una instalación real no quiere
inventario inventado.

#### 3. Levantar

En dos terminales:

```bash
php artisan serve
```

```bash
npm run dev
```

La aplicación queda en <http://localhost:8000> y Vite en el 5173. Para trabajar sin Vite en
marcha, `npm run build` y sirve los assets ya compilados.

> Nada de esto lo levanta Docker en la opción B: ahí Docker solo aporta la base de datos. Si
> `localhost:8000` rechaza la conexión, es que falta `php artisan serve`.

### Cuentas de desarrollo

| Usuario   | Contraseña   | Rol           | Empleado           |
|-----------|--------------|---------------|--------------------|
| `admin`   | `admin123`   | Administrador | Ana Quispe Torres  |
| `cajero1` | `cajero123`  | Cajero        | Luis Ramos Vega    |
| `almacen` | `almacen123` | Almacenero    | Marta Flores Díaz  |

---

## Cómo está organizado

### Los tres conceptos que no se mezclan

El esquema separa a propósito tres cosas que suelen confundirse:

| Concepto     | Tabla       | Qué representa                                              |
|--------------|-------------|-------------------------------------------------------------|
| **Cargo**    | `cargos`    | La función laboral: Gerente, Cajero, Almacenero, Ayudante.   |
| **Empleado** | `empleados` | La persona y su vínculo laboral: ingreso, contrato, cese.    |
| **Usuario**  | `usuarios`  | La cuenta con la que se entra al sistema, y su rol de acceso.|

De ahí salen dos reglas que el código respeta en todas partes:

- Un empleado **puede no tener cuenta** (Jorge, el ayudante, trabaja sin usar el sistema).
- El **cargo no determina el rol**: la gerente Ana tiene rol Administrador, pero un cajero de
  confianza también podría tenerlo.

Y una consecuencia importante: `empleados.estado` (vínculo laboral) manda sobre `usuarios.activo`
(acceso). Al cesar o suspender a un empleado, un trigger de la base desactiva su cuenta. Lo
contrario no ocurre: quitarle el acceso a alguien no lo despide.

### Autenticación

- Se entra con **nombre de usuario**, no con correo: la cuenta vive en `usuarios` y la persona en
  `empleados`.
- `App\Models\Usuario` mapea la columna `password_hash` mediante `getAuthPassword()`, así que el
  guard de sesión estándar de Laravel funciona sin cambios.
- El acceso exige dos condiciones: la cuenta activa **y** el empleado con vínculo vigente
  (`Usuario::puedeIngresar()`).
- Tras cinco intentos fallidos, ese usuario e IP quedan bloqueados un minuto.
- `VerificarCuentaVigente` corta la sesión si la cuenta se deshabilita mientras la persona navega.

### Permisos

Cada rol agrupa permisos (`rol_permiso`). Las rutas se protegen con el middleware `permiso`:

```php
Route::middleware('permiso:usuarios.gestionar')->group(function () { ... });
```

En las vistas, la directiva `@puede('usuarios.gestionar')` esconde lo que la cuenta no puede usar,
y `App\Support\Menu` arma con esos mismos códigos la barra lateral.

### Precios: qué se guarda y qué ve el cliente

`productos.precio_compra` y `productos.precio_venta` se guardan **sin impuesto**. El precio de
estante —lo que paga el cliente— se calcula al vuelo con la tasa vigente en `configuracion`:

```
precio_estante = precio_venta * (1 + tasa_impuesto)      // si el producto está afecto
```

El formulario de producto calcula en ambos sentidos: escribes la base y sale el estante, o
escribes el estante y sale la base. Así los precios quedan redondos en la etiqueta sin sacar la
calculadora. Todo cambio de `precio_venta` se audita con la acción `CAMBIO_PRECIO` (causa C3 del
análisis: precios no centralizados).

`App\Support\Config` lee esos parámetros del negocio (tasa, moneda, nombre del local) una sola
vez por petición.

### La moneda

Sale de `configuracion` y no está escrita en ningún sitio del código:

| Clave | Valor | Para qué |
|-------|-------|----------|
| `moneda_simbolo` | `Bs` | lo que se muestra en pantalla |
| `moneda_codigo` | `BOB` | lo que se congela en cada comprobante emitido |

Para cambiarla basta actualizar esas dos filas. Ojo con una cosa: `comprobantes.moneda` guarda el
código **del momento de emitir**, así que un documento viejo sigue mostrando su símbolo aunque el
negocio cambie de moneda —es una foto, no una referencia—. De eso se encarga
`Config::simbolo($codigo)`, que traduce el código ISO y, si no lo conoce, lo muestra tal cual:
mejor «CLP 1.200» que un símbolo equivocado.

> La **tasa de impuesto** es un parámetro aparte (`tasa_impuesto`) y no se toca al cambiar de
> moneda: hacerlo alteraría el precio de estante de todo el catálogo.

### Lo tributario está en construcción

Los documentos ya usan la nomenclatura boliviana —**CI** para personas y **NIT** para empresas—,
pero el régimen tributario en sí sigue sin definir: la tasa de impuesto está en **0 %** y el
sistema no se integra con Impuestos Nacionales. Está **a propósito sin cerrar**, y el sistema lo
dice en vez de aparentar lo contrario:

- cada documento sale impreso con un recuadro «EN CONSTRUCCIÓN — sin validez tributaria»;
- el listado de comprobantes lleva el mismo aviso arriba;
- el formulario del producto marca la tasa como provisional junto a la casilla de impuesto.

Lo que **sí** está terminado es la mecánica: series y correlativos con bloqueo de fila, desglose
del impuesto por línea, sustitución y anulación de documentos, y la trazabilidad completa. Cerrar
la parte tributaria es cambiar `configuracion.tasa_impuesto`, el rótulo del documento fiscal y —si
se quiere— el `ENUM` de `clientes.tipo_documento`; después se puede quitar el aviso, que vive en
el componente `<x-ui.en-construccion>`.

La facturación electrónica ante la administración tributaria queda fuera de alcance en la versión
1, como dice `docs/01-problematica.md`.

### Fotos de los productos

`productos.imagen` guarda la **ruta relativa** dentro del disco `public`
(`productos/xxx.jpg`), nunca la URL: así el archivo se sigue encontrando aunque cambie el dominio.
Requiere el enlace simbólico de Laravel, que se crea una sola vez:

```bash
php artisan storage:link
```

La foto entra **entera, sin recortar**: el recuadro tiene medida fija —para que la cuadrícula no
se descuadre— y la imagen se acomoda dentro con `object-scale-down`. Da igual si es vertical,
apaisada o cuadrada; y una imagen pequeña no se agranda hasta verse pixelada, cosa que
`object-contain` sí haría. El fondo del recuadro se mantiene claro en ambos temas, porque casi
todas las fotos de producto vienen recortadas sobre blanco.

Se sube desde el formulario del producto (JPG, PNG o WEBP, hasta 2 MB) con vista previa antes de
guardar, y se puede quitar. Al reemplazar o quitar una foto, **el archivo anterior se borra del
disco**: si no, la carpeta se llena de imágenes que ya no referencia nadie.

La foto aparece en el listado del catálogo, en la ficha del producto y —donde más se nota— en las
tarjetas del mostrador. Lo que no tiene foto muestra un marcador del mismo tamaño, para que la
cuadrícula no se descuadre.

> `Producto::imagen_url` usa `asset()` y **no** `Storage::url()`. El segundo arma la dirección con
> `APP_URL`, y como el sistema se abre desde varias máquinas de la red del negocio (RNF3), con
> `APP_URL=http://localhost` las fotos se romperían en todas menos en el servidor. `asset()` toma
> el host de la petición en curso.

### Stock: nunca se escribe a mano

`productos.stock_actual` **no es un campo editable**. Cambia únicamente a través de
`App\Services\Inventario`, que en una transacción bloquea la fila del producto, actualiza el
saldo y escribe un movimiento en `movimientos_inventario` con el stock antes y después, el
responsable y el motivo. De ahí salen tres operaciones:

| Operación | Origen en el kardex | Permiso |
|-----------|---------------------|---------|
| Stock inicial al crear el producto | `INICIAL` | `productos.gestionar` |
| Ingreso de mercadería, una línea | `COMPRA` | `inventario.ingresar` |
| Registro de una compra completa (varias líneas, con su documento) | `COMPRA` + `compra_id` | `inventario.ingresar` |
| Ajuste por conteo físico (motivo obligatorio) | `AJUSTE` | `inventario.ajustar` |

El ajuste se registra indicando **cuántas unidades hay realmente**; el sistema calcula la
diferencia. Si el conteo coincide con el sistema, no se escribe ningún movimiento.

La unidad de medida manda sobre la cantidad: si `permite_decimal` es falso, el sistema rechaza
un ingreso de 2.5 unidades. Y ningún movimiento puede dejar el stock en negativo.

### Vencimiento: el stock partido por fecha

`productos.stock_actual` es un solo número, y con un solo número no hay forma de saber qué caduca:
los 245 chocolates pueden ser 120 que vencen el 15/10 y 125 que vencen el 30/11. La tabla `lotes`
parte ese saldo por fecha, y `productos.controla_vencimiento` decide qué productos lo llevan — el
detergente no vence, y pedirle una fecha cada vez que llega es la forma más rápida de que alguien
escriba cualquier cosa con tal de seguir.

**Los lotes no son una segunda contabilidad.** `stock_actual` sigue mandando: es el saldo que
valida la venta y el que sale en los reportes, y la suma de `lotes.cantidad_actual` tiene que dar
exactamente esa cifra. Si un día no diera, la de `productos` sería la buena.

**Se despacha lo que vence antes (FEFO).** Es lo que hace que la alerta sirva: si el mostrador
descontara de cualquier lote, «quedan 40 por vencer» no querría decir nada. Los lotes sin fecha van
al final, porque algo que no se sabe cuándo vence no puede pasar delante de algo que sí.

Dónde se engancha: en los cuatro sitios de PHP por los que se mueve stock —la venta, la devolución,
la anulación y el inventario—. **No en los triggers**, a propósito: el sistema tiene dos vías para
descontar stock (el trigger en un servidor propio, `ReglasEnPhp` en un hosting sin triggers) y las
dos pasan por ese mismo código PHP. Escribirlo ahí es una sola implementación de FEFO en vez de dos
que habría que mantener iguales a mano.

`fecha_vencimiento` admite NULL a propósito: el stock que ya existía cuando se encendió el control
y lo que devuelve un cliente días después no tienen fecha conocida, y decir «sin fecha registrada»
—que la pantalla muestra aparte— es más honesto que inventarla. Si al encender el control quedaron
200 unidades sin fechar, «no tengo nada por vencer» no significa que todo esté bien, significa que
el control todavía no está completo.

`/vencimientos` lista lo vencido y lo que caduca dentro de 7, 15, 30, 60 o 90 días, con el valor
inmovilizado; desde cada fila se llega a las tandas de ese producto y, desde cada tanda, a la
factura por la que entró.

### Devolver al proveedor, y el cambio

Vino fallado, vino equivocado o se venció en el estante. Hasta aquí la única salida era un ajuste
de inventario con el motivo escrito a mano: bajaba el stock, sí, pero quedaba mezclado con la merma
y la rotura, no se sabía de qué factura había salido y nadie podía responder después **cuánto le
devolví a este proveedor**.

Se registra **desde la compra**, no desde un menú suelto: una devolución siempre es «de esta
factura», y arrancar eligiendo la factura evita devolverle al proveedor equivocado. `compra_detalle`
lleva el acumulado `cantidad_devuelta`, así que no se pueden devolver 30 unidades de una línea que
trajo 24 — y el tope lo comprueba tanto el servicio como un `CHECK`.

**El cambio no es otro módulo, es una pregunta: «¿en qué quedaron?».** `espera` tiene tres valores
porque los finales de una devolución son tres:

| `espera` | Qué pasó | Qué hace el sistema |
|---|---|---|
| `REPUESTO` | Se lo cambió en el momento | Sale lo fallado y entra lo repuesto en el mismo documento: el stock queda como estaba, pero registrado |
| `PENDIENTE` | Se llevó la mercadería y traerá el reemplazo | El stock baja hoy, y la devolución queda **debiendo** hasta que llegue |
| `NOTA_CREDITO` | No repone: queda a cuenta | El stock baja y no vuelve nada |

El de en medio es el que hacía falta. Era un booleano, y con un sí o un no «me lo trae la semana que
viene» era indistinguible de «no me trae nada»: la mercadería salía, el reemplazo terminaba cargado
como un ingreso suelto, y nadie podía responder **qué le deben todavía**.

Ahora `/devoluciones-compra` avisa cuántas esperan reposición, y desde cada una se anota lo que el
proveedor va trayendo. **Puede llegar en partes** —trae 6 de las 10 que debe— porque así llega:
`devolucion_compra_detalle.cantidad_repuesta` acumula, y la devolución deja de esperar cuando el
saldo llega a cero, no cuando alguien lo dice. Lo repuesto entra por el mismo
`Inventario::entradaPorReposicion()` que el cambio inmediato —para el inventario son el mismo hecho
y lo único que las distingue es la fecha— y, si trae otra fecha de vencimiento, abre su propia
tanda.

El motivo es un ENUM (`DEFECTO`, `VENCIMIENTO`, `ERROR`, `OTRO`) y no texto libre porque es lo que
después permite contar: «este trimestre devolví Bs 900 por vencimiento» es una conversación con el
proveedor distinta de «devolví Bs 900 porque vino fallado», y sumarlas las haría desaparecer a las
dos. `/devoluciones-compra` muestra ese corte.

En el kardex es una **salida con origen propio** (`DEVOLUCION_COMPRA`), no un ajuste: un ajuste
explica un descuadre —merma, rotura, conteo— y esto explica que algo se fue de vuelta por donde
vino. Cuando el producto lleva vencimiento se puede elegir **de qué tanda** sale: lo vencido se
devuelve de SU lote y no del que tocaría por orden de salida.

Se llega por tres caminos, todos al mismo formulario: «Registrar devolución» en el listado —que
empieza preguntando de qué factura, y solo ofrece las que tienen algo pendiente—, el botón dentro
de la propia compra, y **el atajo desde vencimientos**. Ese último usa `lotes.compra_detalle_id`
para saltar de la tanda caducada a la factura por la que entró, con la cantidad y el motivo ya
puestos: ver el problema y resolverlo sin tener que acordarse del papel.

La tanda que no vino de ninguna compra —el stock que ya estaba cuando se encendió el control— no
ofrece devolver sino **dar de baja**, que es lo honesto: no hay factura contra la que reclamarle a
nadie.

### Dar de baja lo vencido

Lo vencido **sigue contando como stock** hasta que alguien lo dice: el mostrador lo dejaría vender
y el reporte lo sigue valorando. «Dar de baja» lo saca, y es un `AJUSTE` como cualquier otro —con
su movimiento en el kardex, su responsable y su motivo—, no un borrado.

Lo que cambia frente al ajuste normal es quién hace la resta. El ajuste pide el stock contado y
obliga a calcular a mano «24 en total menos 4 vencidas = 20», que es justo donde alguien escribe
`0` y se lleva por delante las 20 buenas. Aquí la cantidad sale de la propia tanda, y se descuenta
de **esa** tanda y no de la que tocaría por orden de salida: se está tirando un lote concreto
porque venció, no descontando a ciegas.

El motivo lo arma el servidor —«Baja por vencimiento, lote L06452, venció el 22/07/2026»— y solo se
puede añadir una observación. Es la diferencia entre un kardex que se puede leer dentro de seis
meses y uno donde cada quien escribió lo que le pareció.

Solo se ofrece para lo **ya vencido**. Lo que caduca la semana que viene todavía se vende o se
devuelve, y ofrecer tirarlo sería invitar a tirar mercadería buena.

### Comprar por caja y vender por unidad

El negocio compra cajas de 24 y despacha gaseosas de a una. Eso no son dos unidades de stock:
`stock_actual` se cuenta **siempre** en la unidad de venta, y el empaque es solo la equivalencia
con la que se escribe la entrada. Lo definen dos columnas de `productos`, que van juntas o no van:

| Columna | Ejemplo | Qué es |
|---------|---------|--------|
| `contenido_empaque` | `24` | Cuántas unidades de venta trae un empaque. NULL = el producto no viene en empaque |
| `nombre_empaque` | `Caja` | Cómo se llama: caja, paquete, plancha, fardo |

El empaque **no depende de la unidad de venta**, y sirve igual para lo que se cuenta, lo que se
pesa y lo que se mide:

| Se vende en | Llega en | `contenido_empaque` |
|---|---|---|
| Unidad | caja de 24 gaseosas | `24` |
| Kilogramo | saco de 46 kg de arroz | `46` |
| Litro | bidón de 20 L de aceite | `20` |
| Litro | galón de 3.785 L | `3.785` |

Por eso `contenido_empaque` es `DECIMAL(10,3)` y no un entero: con un entero, quien compra por
galón tendría que redondear a 4, y ese redondeo se le iría derecho al stock —10 galones cargados
como 40 litros contra los 37.85 que entraron de verdad—.

El nombre del empaque se elige de una lista (caja, paquete, bolsa, saco, fardo, plancha, docena,
bidón, turril, balde, blíster) con un «Suelto — igual que lo vendo» al principio y un «Otro…» al
final. La lista **no** tiene una casilla delante que haya que marcar: la tuvo, y quien se la
saltaba terminaba buscando la caja en el desplegable de unidades de venta, que es la pregunta
equivocada —esa decide lo que se despacha en el mostrador—. Las dos preguntas van juntas y en el
orden en que se piensan:

    ¿Cómo te lo entrega el proveedor?      ¿Cómo lo vendes en el mostrador?
    [ Caja        ▾ ]                      [ Unidad      ▾ ]
    ¿Cuánto trae cada uno?  [ 24 ] UND por caja

    → Compras de a caja de 24 UND y vendes de a unidad.

Con eso, la pantalla de ingreso deja de pedir un total y pide lo que se cuenta en el depósito:
cuántas cajas enteras llegaron y cuántas unidades vinieron sueltas. La multiplicación la hace el
sistema y la muestra antes de guardar. El caso que motivó todo esto es el de la caja incompleta:
**3 cajas y 5 sueltas** entran como 77 unidades, sin calculadora.

El desglose no se pierde al guardar. El kardex anota «3 cajas de 24 + 5 sueltas» junto al motivo,
porque la factura del proveedor está expresada en cajas y el número solo no se puede contrastar
con ella. Por el mismo motivo el costo se puede escribir **por caja**: el sistema divide entre el
contenido y guarda siempre el costo por unidad, que es como lo lee el resto del sistema.

#### El precio, que se escribe en cajas y se guarda en unidades

`precio_compra` y `precio_venta` son **siempre por unidad de venta**: de ahí salen el margen, el
valor del inventario y los reportes. Pero la factura del proveedor viene en cajas, así que las dos
pantallas donde se escribe un costo aceptan un selector **«por unidad / por caja»** y dividen entre
`contenido_empaque` antes de guardar. Escribir 96 por una caja de 24 guarda 4.00.

Y el costo que se carga al recibir mercadería puede **actualizar el del producto**, con una casilla
marcada por defecto que solo aparece cuando el número cambió. No se hace solo, a propósito: una
compra puntual más cara —una urgencia, un flete— no siempre debe volverse el costo de referencia.
Cuando se hace, se audita como `CAMBIO_COSTO`, porque mueve el margen de todos los reportes.

Sin esto el costo se quedaba solo en el kardex: el proveedor subía la caja de 96 a 108, el
almacenero lo cargaba bien, y el sistema seguía diciendo que se ganaba Bs 2.00 por unidad cuando
se ganaban 1.50.

#### Las cajas bajan solas

El desglose —«3 cajas y 5 sueltas»— **se calcula del stock, no se guarda**. Por eso no hace falta
tocar nada cuando se vende: despachar 24 unidades de un producto que viene de 24 descuenta una caja
entera sola. Se muestra en las cuatro pantallas donde se mira stock —catálogo, inventario, ficha y
mostrador— y por debajo de un empaque completo dice solo las sueltas, porque «0 cajas y 5 sueltas»
es la misma información con una cifra de más.

Nada de esto llega al cobro: el punto de venta sigue vendiendo y descontando en unidades de
venta, sin enterarse de que el producto vino en caja. Un producto a granel —el arroz por kilo—
deja las dos columnas en NULL y su pantalla de ingreso es la de siempre, con una sola casilla.

La cuenta vive en un solo sitio (`App\Http\Controllers\Concerns\IngresaPorEmpaque`), que usan
las tres puertas por las que entra stock: el alta del producto, la ficha y el almacén. Si se
repitiera en cada una, un día el almacén cargaría distinto que la ficha.

Nótese que gestionar el catálogo y mover stock son permisos distintos: el almacenero puede hacer
ambas cosas, pero un rol podría crear productos sin poder tocar el inventario.

Las dos operaciones tienen **dos puertas**, y las dos terminan en el mismo servicio:

- la ficha del producto (`/productos/{id}`), para cuando ya se está mirando ese producto;
- el módulo de inventario (`/inventario`), que parte del stock —qué falta, qué se agotó— y deja
  ingresar y ajustar desde cada fila. Es el camino del almacenero, que llega con la mercadería
  en la mano y todavía tiene que encontrarla.

### Compras: la factura del proveedor, entera

Las dos operaciones de arriba cargan **un** producto. El caso habitual es el otro: el distribuidor
deja una factura con treinta líneas. `/compras/nueva` la registra de una vez —proveedor, número de
documento y las líneas, cada una con sus cajas y sus sueltas— y muestra el total en vivo para
**cuadrarlo contra el papel antes de guardar**, que es el control que antes no existía.

Lo importante de cómo está hecho: **no es una segunda puerta al stock**. Cada línea pasa por
`Inventario::ingreso()`, el mismo servicio de siempre, con el `compra_id` de la cabecera. El kardex
sigue siendo la única verdad sobre las existencias; lo único que cambia es que ahora cada
movimiento sabe de qué documento vino, y desde una línea del kardex se llega a la factura completa.

| Tabla | Qué guarda |
|-------|------------|
| `compras` | La cabecera: proveedor, documento, fecha, quién la registró |
| `compra_detalle` | Lo que decía el papel: producto, cantidad, costo unitario, importe (columna generada) |
| `movimientos_inventario.compra_id` | El efecto en el estante, colgado de su documento |

Todo va en una transacción: o entra la factura entera, o no entra nada. Una compra a medias —diez
líneas cargadas y veinte no— sería peor que no haberla cargado, porque nadie sabría dónde se cortó.

**El producto y el proveedor se pueden dar de alta ahí mismo.** Que un producto llegue hoy por
primera vez, o que sea la primera factura de un proveedor nuevo, es el caso normal de una compra y
no la excepción; mandar a la persona a otra pantalla le costaría todas las líneas que ya tecleó.
Los dos atajos van por `fetch` contra `productos.store` y `proveedores.store` —el mismo recurso y la
misma validación de siempre, solo que la respuesta vuelve en JSON— y por eso siguen exigiendo
`productos.gestionar`: quien no lo tiene ni siquiera ve el atajo. El producto nace con **stock
cero**, porque las unidades las pone la línea de esa misma compra; cargarlas también en el alta las
contaría dos veces. Y el código interno ya no es obligatorio: si no se escribe, el sistema asigna el
correlativo que de todos modos venía proponiendo.

No hay permiso propio para la compra: registrarla **es** ingresar mercadería, solo que de muchas
líneas a la vez, así que usa `inventario.ingresar`. Y «Ingresar mercadería» se queda donde estaba: cuando
llega una caja suelta no hay factura que archivar. El esquema previó las dos vías desde el
principio, y por eso `movimientos_inventario` guarda `proveedor_id` y `documento_externo` sueltos
además de `compra_id`.

Una compra registrada no se edita ni se borra —ya movió el stock—. Si una línea se cargó mal, se
corrige con un ajuste de inventario, que deja la diferencia explicada y con responsable.

`/inventario/movimientos` es el kardex de todo el almacén, con filtros por producto, tipo de
movimiento, responsable y rango de fechas. Responde lo que la ficha de un producto no puede:
qué se movió ayer, qué cargó esta persona, cuántos ajustes hubo este mes.

Por eso, cuando alguien intenta dar de alta un producto con un código que ya existe, el aviso no
se limita a decir «ya existe»: nombra al producto y ofrece el enlace para cargarle stock. Ese
error casi siempre lo comete quien quería reponer, no crear.

### La venta: qué hace la aplicación y qué hace la base

Este módulo delega a propósito en el esquema. `App\Services\Ventas` abre una transacción y
orquesta; el resto ya vive en `docs/sql`:

| Paso | Quién lo hace |
|------|---------------|
| Validar stock, descontarlo y escribir el kardex | trigger `trg_venta_detalle_after_insert` |
| Copiar el régimen y la tasa de impuesto a cada línea | trigger `trg_venta_detalle_before_insert` |
| Calcular subtotal e impuesto desde el detalle | `sp_recalcular_venta` |
| Tomar el correlativo con bloqueo de fila | `sp_siguiente_comprobante` |
| Emitir el documento y congelar los datos del cliente | `sp_emitir_comprobante` |
| Comprobar que el tipo de documento corresponde al cliente | trigger `trg_comprobantes_before_insert` |
| Revertir stock y anular el documento | `sp_anular_venta` |
| Sustituir el documento por otro (recibo → factura) | `sp_sustituir_comprobante` |
| Copiar la tasa de impuesto a la línea devuelta | trigger `trg_devolucion_detalle_before_insert` |
| Acumular lo devuelto y reingresar stock | trigger `trg_devolucion_detalle_after_insert` |
| Calcular el efectivo esperado al cerrar caja | `sp_cerrar_caja` |

Reescribir eso en PHP habría dado dos fuentes de verdad que se contradicen con el tiempo.

Un detalle del orden de operaciones: el descuento de cabecera **no** se puede guardar al crear la
venta, porque la base exige `descuento <= subtotal` y el subtotal todavía es cero. Por eso la
secuencia es insertar la venta sin descuento → insertar el detalle → recalcular → aplicar el
descuento → recalcular otra vez (el impuesto baja en la misma proporción).

### Comprobantes

El tipo de documento lo decide **la serie**, y la serie sale de `configuracion`:
persona jurídica → `serie_factura`; el resto → `serie_recibo`. Por eso `comprobantes` no guarda
`tipo_comprobante_id`: sería una segunda fuente de verdad.

Cada documento congela una foto del cliente y de los importes al emitirlo. Nada se borra: un
documento se anula (conservando su correlativo) o se sustituye. Se imprime en ticket de 80 mm o
en A4 desde la misma vista, sin la barra lateral, y los anulados y sustituidos salen con su sello.

**Sustitución (HU-42).** El caso típico: se entregó un recibo y el cliente vuelve pidiendo
factura. La venta no se toca —el dinero ya se cobró, el stock ya salió—: solo cambia el
documento. `sp_sustituir_comprobante` marca el anterior como `SUSTITUIDO`, lo que libera el índice
de «documento vigente», y emite el reemplazo con su propio correlativo, enlazado por
`sustituye_a` y con el motivo en `motivo_emision`. La cadena queda a la vista en la ficha de la
venta.

Las condiciones las pone la base y la aplicación las repite para poder ocultar el botón antes de
intentarlo: el documento debe estar vigente, la venta `COMPLETADA` (ni anulada ni con
devoluciones) y dentro del plazo de `configuracion.dias_max_sustitucion`. Ese plazo se cuenta en
**días de calendario**, con el mismo criterio que el `DATEDIFF` del procedimiento, no en períodos
de 24 horas.

El tipo del reemplazo lo decide el cliente que se le asigne, igual que en la venta: persona
jurídica → factura, el resto → recibo. Funciona en ambos sentidos, porque una factura emitida por
error también hay que poder corregirla. Sustituir es corregir un documento ya entregado, así que
pide el mismo permiso que anular una venta (`ventas.anular`).

### Caja

Una venta necesita un turno abierto: es donde se imputa el dinero. La base impide con un índice
único que una caja tenga dos turnos abiertos a la vez. Al cerrar:

```
esperado = monto_inicial + cobrado en efectivo + ingresos − egresos − devoluciones
```

Solo cuentan los métodos de pago con `afecta_caja = 1`: la tarjeta no deja dinero en el cajón. La
diferencia es una columna generada, así que no puede desincronizarse; no se corrige, se explica.

### Devoluciones

Se registran desde la venta original y admiten devolver solo parte. Otra vez el trigger hace el
trabajo: `trg_devolucion_detalle_after_insert` acumula lo devuelto en la línea de venta,
recalcula el total, mueve la venta a `DEVUELTA_PARCIAL` o `DEVUELTA` y reingresa el stock.

Dos cosas propias de este módulo:

- **`reingresa_stock` por línea.** Lo que vuelve al estante suma inventario; lo que llegó roto o
  vencido se paga igual pero no se puede volver a vender, así que el stock no sube.
- **La devolución se ata a la caja de quien la registra**, no a la de la venta original: el dinero
  sale del cajón de hoy, y así lo descuenta el arqueo.

**El importe devuelto lleva impuesto**, porque es lo que el cliente pagó. `devolucion_detalle`
guarda el mismo desglose que `venta_detalle` —`afecto_impuesto`, `tasa_impuesto` y las columnas
generadas `impuesto_linea` y `total_linea`—, la tasa la copia de la línea de venta original un
trigger BEFORE INSERT (la de aquel día, no la de hoy), y el total de la devolución suma
`total_linea`. Así `sp_cerrar_caja` puede restarlo tal cual del efectivo esperado y la caja cuadra
sola: una venta de S/ 10.90 devuelta por completo deja el cajón como estaba.

> Esto se corrigió el 2026-08-21. Antes el total sumaba solo la base y el arqueo quedaba con un
> sobrante igual al impuesto devuelto. `docs/sql/01_schema_mysql.sql` ya está corregido para las
> instalaciones nuevas; una base que venga de antes se pone al día con
> `docs/sql/parches/2026_08_21_devolucion_con_impuesto.sql`, que además rellena las devoluciones
> ya registradas.

La nota de crédito por devolución de una factura queda fuera de alcance en la versión 1, como dice
`docs/01-problematica.md`.

### Descuentos

`configuracion.descuento_max_cajero` fija el umbral (10%). Por encima hace falta el permiso
`ventas.descuento`, y el mostrador lo avisa antes de dejar cobrar. Todo descuento queda en la
venta con el nombre de quien la registró (objetivo O4).

### La portada

`/inicio` está abierta a todos, pero **cada bloque se arma solo si el rol puede verlo**, y el
controlador ni siquiera consulta lo que no se va a mostrar:

| Bloque | Quién lo ve |
|--------|-------------|
| Mi turno de caja | quien puede vender o abrir caja |
| Lo que llevo vendido hoy | quien puede vender — es su trabajo, no información de gestión |
| Vendido hoy frente a ayer, gráfico de dos semanas, últimas ventas | `reportes.ver` |
| Alertas de reposición | `productos.gestionar` o `reportes.ver` |

Un cajero abre la aplicación y cae en el mostrador, no aquí: es donde pasa el turno y un clic de
más en cada venta se nota. La portada le queda en el menú para consultar cómo va su caja.

La comparación con ayer se omite cuando ayer no hubo ventas, en vez de mostrar un porcentaje
inventado: no se puede dividir por cero.

### Reportes

Dos pantallas, ambas con el mismo filtro de período (por defecto, los últimos 30 días):

- **Ventas:** vendido, neto de devoluciones, ticket promedio e impuesto; evolución diaria;
  desglose por método de pago y por cajero; detalle día a día.
- **Productos e inventario:** valor del stock, alertas de reposición y ranking de más vendidos.

Los agregados salen de las vistas que ya define `docs/sql` —`v_ventas_por_dia`,
`v_ventas_por_metodo_pago`, `v_alertas_stock`—, que son la definición oficial de cada cifra.

Con una salvedad, anotada en el código: **el ranking de más vendidos se repite en PHP.**
`v_productos_mas_vendidos` agrega todo el histórico y no admite rango de fechas, así que el
reporte reproduce sus mismas fórmulas con un filtro. Para que no se separen con el tiempo, una
prueba compara ambos —incluyendo un producto con devolución, que es donde se separarían sin que
nadie lo note— columna por columna: si alguien cambia la vista, la prueba falla.

Las tres cifras del ranking van **netas de devoluciones**. El importe de cada línea se prorratea
por la fracción que el cliente se quedó, el mismo criterio con el que `sp_recalcular_venta`
prorratea el impuesto; así el descuento de línea se reparte solo y no hay que repetir su fórmula.

La serie diaria **rellena con cero los días sin ventas**: si no, el gráfico uniría dos días
lejanos con una recta y aparentaría ventas que no existieron.

Los gráficos son ApexCharts y se cargan bajo demanda: `resources/js/graficos.js` solo importa la
librería si la página trae algún `[data-apexchart]`. La plantilla pone ahí los datos en JSON
—nunca funciones, que no sobreviven a `json_encode`— y el módulo arma las opciones, incluidos los
formateadores de moneda y el tema. Un `MutationObserver` redibuja los gráficos cuando se cambia
entre claro y oscuro.

### Auditoría

`App\Services\Auditor` escribe en la tabla `auditoria` los ingresos, los intentos fallidos y toda
alta, baja o modificación de empleados, cuentas, cargos y roles. Nada se borra en silencio: cuando
un registro tiene historia asociada (un cargo con empleados, una cuenta con operaciones), el
sistema lo **desactiva** en lugar de eliminarlo.

### Se adapta a la pantalla

Probado de 375&nbsp;px (teléfono) a 1440&nbsp;px, sin desbordes horizontales de página en ninguna
pantalla. Lo que cambia según el tamaño:

- **Barra lateral:** completa en escritorio, plegable a iconos, y fuera de pantalla con menú
  hamburguesa por debajo de 1280&nbsp;px. Viene de la plantilla.
- **Tablas:** van dentro de un contenedor con desplazamiento propio, así que nunca desbordan la
  página. Además, en las más anchas (ventas y productos) las columnas secundarias se ocultan por
  tramos —`sm`, `md`, `lg`— y quedan las que identifican la fila y su importe; el resto está a un
  toque, en el detalle.
- **Mostrador:** en escritorio el carrito acompaña el desplazamiento a la derecha. En el teléfono
  queda debajo de la cuadrícula, así que aparece una **barra flotante** con el número de artículos
  y el total, que lleva al carrito de un toque. Sin ella, el cajero tendría que recorrer todo el
  catálogo para ver cuánto lleva.
- **Modales:** todos con `max-h-[90vh]` y desplazamiento interno. Sin eso, en una pantalla baja
  —un teléfono en horizontal— la parte de arriba de un formulario largo queda inalcanzable, porque
  el centrado con flex recorta hacia arriba.
- **Fotos de producto:** ver más abajo; entran enteras sea cual sea su proporción.

### Notas sobre la interfaz

- El tema claro/oscuro y el plegado de la barra lateral son stores de Alpine definidos en
  `resources/views/layouts/partials/head.blade.php`.
- Los componentes reutilizables están en `resources/views/components`: `ui.*` (botón, alerta,
  etiqueta de estado, avatar de iniciales), `form.*` (campo, input, select, textarea, casilla) y
  `common.*` (tarjeta, migas, paginación, avisos flash).
- **Cuidado con `@js()` dentro de atributos de un componente Blade**: no se compila y Alpine
  recibe el texto literal. En esos casos hay que usar un `<button>` normal.
- **Las clases de Tailwind van completas y literales.** Tailwind rastrea el texto de la
  plantilla, así que un `text-{{ $color }}-500` no genera ninguna clase. Cuando el color depende
  de un dato, se guarda la clase entera en el arreglo (ver el resumen de `productos/index`).
- **Ojo con los alias de un `selectRaw` sobre un modelo.** Si el alias coincide con el nombre de
  un accesor (`bajo_minimo` en `Producto`), Eloquent devuelve lo que calcula el accesor sobre una
  fila vacía en vez de la suma. Para agregados conviene el query builder (`DB::table(...)`).

---

## Pruebas

Las pruebas corren contra una copia real de la base, porque el esquema usa columnas generadas,
`ENUM` y triggers que SQLite no reproduce. Se crea una sola vez, desde la raíz del repositorio:

```bash
sed 's/ventas_db/ventas_db_test/g' docs/sql/01_schema_mysql.sql | docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 -uroot -pventas123
```

```bash
sed 's/ventas_db/ventas_db_test/g' docs/sql/02_datos_iniciales.sql | docker exec -i ventas_mysql mysql --default-character-set=utf8mb4 -uroot -pventas123
```

Después:

```bash
php artisan test
```

Cada prueba corre dentro de una transacción que se revierte al terminar, así que los catálogos
quedan intactos.

---

## Nueva instalación (un cliente)

Checklist para poner el sistema en el servidor de un negocio nuevo. El objetivo es que cada
instalación tenga sus propias credenciales — para que filtrar una no comprometa a las demás — y
sus propios datos de negocio.

1. **Copiar el proyecto** al servidor y, desde la raíz del repositorio:

   ```bash
   cp .env.example .env
   cp sistema-ventas/.env.docker.example sistema-ventas/.env.docker
   ```

2. **Clave y contraseña propias**, en `sistema-ventas/.env.docker`:

   ```bash
   php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"   # -> APP_KEY
   ```

   Pon una contraseña nueva en `DB_PASSWORD` (ese mismo archivo) y en `.env` (raíz) — tienen que
   coincidir, es la misma base.

   **Cuenta de base de datos de la aplicación.** Pon otra contraseña, distinta, en `DB_APP_PASSWORD`
   del `.env` de la raíz, y en `sistema-ventas/.env.docker` cambia a `DB_USERNAME=ventas_app` con
   esa misma contraseña en `DB_PASSWORD`. Al primer arranque, MySQL crea `ventas_app` con permisos
   mínimos (leer y escribir datos, llamar a los procedimientos): la aplicación no se conecta como
   `root`, y un fallo de seguridad en ella no tendría el servidor de base de datos entero. En una
   instalación que ya corría con `root`: `./scripts/crear-usuario-app.sh`. La contraseña de `root`
   (`DB_PASSWORD` de la raíz) la siguen usando los parches, el respaldo y la restauración por
   consola. Revisa también `APP_URL` (el dominio real del cliente) y que
   `APP_DEBUG=false` (ya viene así en la plantilla: no lo cambies salvo para depurar algo puntual).

3. **Compilar los assets** (una vez, y cada vez que cambie el código del frontend). No se
   compilan dentro de la imagen: se generan aquí y la imagen los recoge, para que nginx —que los
   sirve directo del disco, sin pasar por PHP— y la aplicación vean exactamente los mismos:

   ```bash
   cd sistema-ventas && npm ci && npm run build && cd ..
   ```

4. **Levantar, con la vía de producción**:

   ```bash
   docker compose -f docker-compose.prod.yml up -d --build
   ```

   Ojo con esto: `docker compose up` a secas levanta el entorno de **desarrollo**
   (`docker-compose.yml`), que monta el código por bind mount, corre como root, deja la base
   escuchando en un puerto de la máquina y muestra los errores de PHP en pantalla. Sirve para
   trabajar en el proyecto, no para el servidor de un negocio. La versión de producción
   (`docker-compose.prod.yml` + `Dockerfile.prod`) hornea el código en la imagen, corre como
   usuario sin privilegios y no publica la base hacia fuera.

5. **Primer acceso.** La base de producción se crea con `docs/sql/produccion/02_datos_base.sql`:
   sin productos, clientes ni empleados de ejemplo, y con una sola cuenta, `admin`. Su contraseña
   es aleatoria y queda en un archivo dentro del contenedor:

   ```bash
   docker compose -f docker-compose.prod.yml exec app cat storage/app/respaldos/PRIMER-ACCESO.txt
   ```

   La contraseña **no** sale en el log del contenedor a propósito: ahí quedaría guardada para
   siempre. Borra el archivo en cuanto entres:

   ```bash
   docker compose -f docker-compose.prod.yml exec app rm storage/app/respaldos/PRIMER-ACCESO.txt
   ```

   Al entrar, el sistema obliga a cambiarla antes de hacer cualquier otra cosa.

6. **Datos del negocio y cuentas reales.** En **Sistema → Configuración**: nombre, NIT,
   dirección, teléfono, moneda y topes del cajero. En **Impuesto y precios**: si el negocio cobra IVA,
   la tasa y si los precios de venta ya lo incluyen (una instalación nueva arranca sin IVA y con los
   precios como los ve el cliente; al activarlo con el precio incluido, lo que paga el cliente no
   cambia). Al cambiar de modo, el sistema ofrece ajustar los precios del catálogo para que el
   cliente siga pagando lo mismo. En **Personal → Empleados y
   Usuarios**: el personal y sus cuentas. Cada cuenta nueva, y cada contraseña que restablece un
   administrador, se cambia al primer ingreso.

7. **Backup programado.** Sin esto, un disco dañado se lleva el negocio entero — ver
   [Copias de seguridad](#copias-de-seguridad) más abajo. No lo dejes para después.

8. **Adminer no existe en producción.** `docker-compose.prod.yml` directamente no lo declara: un
   cliente de base de datos sin autenticación propia no tiene por qué estar instalado en el
   servidor de un negocio. (En desarrollo sigue disponible con `--profile tools`.)

9. **Cobro por QR**, si el negocio lo va a usar. De fábrica queda en `QR_PASARELA=simulado`:
   el mostrador genera un QR **real y escaneable, con el importe ya puesto**, pero sin banco
   detrás el pago no llega solo — lo confirma el cajero con «Ya me pagó», y la pantalla lo avisa
   con todas las letras. Se hizo así a propósito: un simulador que se pagara solo daría la falsa
   impresión de que el cobro funciona.

   Con **Banco Económico** («BEC QR Connect»), cuando entregue las credenciales, en
   `sistema-ventas/.env.docker` (nunca en el repositorio):

   ```
   QR_PASARELA=baneco
   QR_BANECO_URL=https://...        # la de PRODUCCIÓN que entregue el banco
   QR_BANECO_USUARIO=...
   QR_BANECO_PASSWORD=...
   QR_BANECO_LLAVE=...
   QR_BANECO_CUENTA=...
   QR_BANECO_SUCURSAL=...
   ```

   **Ojo con `QR_BANECO_URL`: si se deja vacía, apunta a certificación** (`apimktdesa`), donde
   los pagos no son reales. El aviso de pago se le da al banco como
   `https://<servidor>/api/qrsimple/notifyPaymentQR`; no trae firma, así que el sistema solo lo usa
   para ir a consultar al banco, nunca lo da por bueno. Después de cambiar `.env.docker`,
   `docker compose -f docker-compose.prod.yml up -d` (un `restart` no relee el archivo).

   Para otro banco, `QR_PASARELA=banco`:

   ```
   QR_PASARELA=banco
   QR_URL_BASE=https://...
   QR_TOKEN=...
   QR_SECRETO_WEBHOOK=...
   ```

   Lo que hay que pedirle al banco está anotado en la cabecera de
   `app/Services/Qr/QrBanco.php`. El aviso de pago del banco entra sin sesión y sin CSRF —el
   banco no inicia sesión—, así que **la firma es su única defensa**: sin `QR_SECRETO_WEBHOOK`
   se rechaza todo aviso. Falla cerrado, no abierto.

   Confirmar a mano sigue haciendo falta con el banco conectado: si su API se cae, el cajero
   tiene que poder cobrar mirando el comprobante en el celular del cliente. Queda con su nombre
   en la bitácora, porque es el punto por donde se colaría un cobro que nunca entró.

10. **HTTPS**, si el servidor es accesible por internet (no solo en la red del local): un proxy
    (nginx, Caddy, un balanceador del proveedor) delante del contenedor `nginx`, con su
    certificado. Este proyecto no lo resuelve por sí solo. Una vez que HTTPS esté activo, pon
    también en `sistema-ventas/.env.docker`:
    - `APP_URL=https://…` — con eso la cookie de sesión pasa sola a viajar solo cifrada
      (`SESSION_SECURE_COOKIE` ya no hace falta; si tu `.env.docker` es viejo y la tiene en
      `false`, bórrala). La cabecera `Strict-Transport-Security` ya la manda el nginx del proyecto.
    - `TRUSTED_PROXIES=172.16.0.0/12` si el proxy corre en el mismo servidor: la aplicación no ve
      llegar la IP del proxy sino la de la red interna de Docker. Sin esto la bitácora registra
      la IP del proxy en vez de la del cliente, y las URLs que arma Laravel salen en `http://`
      aunque el visitante haya entrado por `https://`.
    - En el `.env` de la raíz, `APP_PUERTO=127.0.0.1:8100`: así nadie entra por el puerto 8100
      saltándose el HTTPS. En un local sin proxy, donde las cajas entran por la red, se deja 8100.

    Después de cambiar `.env.docker` o `.env`: `docker compose -f docker-compose.prod.yml up -d`
    (recrea los contenedores con los valores nuevos; `restart` no los vuelve a leer).

---

## Copias de seguridad

**En producción ya hay un respaldo automático**: el contenedor `programador` corre
`respaldo:crear` todas las noches a la 01:00 y lo guarda en el volumen `ventas_respaldos`
(se ve y se descarga en **Sistema → Respaldos**). Para que además quede una copia **fuera del
servidor**, monta un disco externo o una carpeta sincronizada:

```bash
# en el servidor: la carpeta, escribible por www-data del contenedor (uid 82)
sudo mkdir -p /media/usb/respaldos-ventas && sudo chown 82:82 /media/usb/respaldos-ventas
```

y en el `.env` de la raíz `RESPALDOS_COPIA_SERVIDOR=/media/usb/respaldos-ventas`, en
`sistema-ventas/.env.docker` `RESPALDOS_COPIA=/respaldos-copia`, y `up -d`. Si la copia falla, el
respaldo nocturno queda como fallido en la bitácora y en el log del programador.

`scripts/revisar-salud.sh` da por bueno un respaldo reciente tanto en `backups/` como en ese
volumen. Para restaurar uno del volumen, primero sácalo al servidor:

```bash
docker cp ventas_app_prod:/var/www/html/storage/app/respaldos/<archivo>.sql.gz backups/
```

Además, `scripts/backup-db.sh` hace una copia desde el servidor, con cron:

`scripts/backup-db.sh`, desde la raíz del repositorio, guarda **dos** archivos en `backups/`
(que no se sube al repositorio), con la fecha y hora en el nombre:

- `ventas_db_<fecha>.sql.gz` — la base completa, incluidos los procedimientos almacenados y los
  triggers, que un `mysqldump` sin las banderas correctas deja fuera en silencio.
- `ventas_fotos_<fecha>.tar.gz` — las fotos de producto. Van aparte porque no viven en la base:
  la base solo guarda el nombre del archivo. Respaldar únicamente el SQL deja un catálogo entero
  sin imágenes el día que haya que recuperar.

Borra solas las copias de más de 14 días (`RETENTION_DAYS` para cambiarlo). Detecta por su
cuenta si los contenedores son los de producción (`ventas_mysql_prod`) o los de desarrollo.

```bash
./scripts/backup-db.sh
```

Antes de dar una copia por buena, el script comprueba que el volcado no esté corrupto ni
truncado (busca la marca final que escribe `mysqldump`): un respaldo a medias, de esos que
deja un disco lleno, es peor que ninguno, porque da tranquilidad hasta el día que hace falta.

Para restaurar (**sobrescribe** la base actual; pide confirmación). Repone también las fotos si
encuentra el `.tar.gz` de la misma fecha al lado:

```bash
./scripts/restore-db.sh backups/ventas_db_20260901_010000.sql.gz
```

En un servidor real, prográmalo con `cron`:

```
0 1 * * *  cd /ruta/al/proyecto && ./scripts/backup-db.sh >> backups/backup.log 2>&1
```

**Y saca las copias del servidor.** Un respaldo guardado en el mismo disco que la base no
protege del caso más común de todos: que ese disco muera. Con `DESTINO_EXTERNO` apuntando a un
disco USB montado o a una carpeta de red, cada respaldo se duplica ahí:

```
0 1 * * *  cd /ruta/al/proyecto && DESTINO_EXTERNO=/mnt/respaldos ./scripts/backup-db.sh >> backups/backup.log 2>&1
```

Y de cuando en cuando, prueba que una copia efectivamente restaura —una copia que nunca se
probó a restaurar no es una copia de seguridad, es una promesa. Esta ya se probó: se tiró la
base entera y las fotos, y se recuperó todo desde una copia con un solo comando. De ahí salió,
justamente, el arreglo de que `restore-db.sh` ahora cree la base si no existe: hasta entonces
la restauración moría con «Unknown database» en el único escenario que importa, el del
servidor perdido.

---

## Monitoreo

`scripts/revisar-salud.sh` revisa las cuatro cosas que dejan al negocio sin vender —o sin red
de seguridad— y que no avisan solas:

1. **Los contenedores están corriendo.**
2. **La aplicación responde y la base contesta.** Consulta `/up`, que no es el de fábrica: se le
   enganchó una consulta real a la base (`app/Listeners/ComprobarBaseDeDatos.php`). El `/up` que
   trae Laravel responde 200 en cuanto el framework arranca, sin tocar MySQL — o sea que decía
   «todo bien» con la base caída y nadie pudiendo cobrar.
3. **Queda espacio en disco.** Un disco lleno detiene MySQL y de paso hace fallar los respaldos:
   se pierden las dos cosas a la vez.
4. **Hay un respaldo reciente y no está vacío.** Es el que más silencio hace: un respaldo que
   dejó de correr hace tres semanas se descubre el día que se necesita.

```bash
./scripts/revisar-salud.sh
```

Sale con código 0 si todo está bien y 1 si algo falla, así que desde `cron` avisa solo si el
servidor tiene correo configurado. Cada cinco minutos:

```
*/5 * * * *  cd /ruta/al/proyecto && ./scripts/revisar-salud.sh >> backups/salud.log 2>&1
```

Para que avise por otro medio, `ALERTA_COMANDO` recibe el resumen por entrada estándar. Con un
bot de Telegram —lo más práctico para que le llegue al celular del encargado:

```
*/5 * * * *  cd /ruta/al/proyecto && ALERTA_COMANDO='xargs -0 -I{} curl -s -o /dev/null --data-urlencode "chat_id=<ID>" --data-urlencode "text={}" https://api.telegram.org/bot<TOKEN>/sendMessage' ./scripts/revisar-salud.sh >> backups/salud.log 2>&1
```

Umbrales, por si el servidor pide otra cosa: `DISCO_MAXIMO` (85%), `BACKUP_MAX_HORAS` (30) y
`URL_SALUD` (`http://localhost:8100/up`).

---

## Mapa del código

```
app/
  Http/Controllers/
    Auth/LoginController.php     ingreso, salida y bloqueo por intentos
    EmpleadoController.php       alta, edición, cese y reactivación
    CargoController.php          catálogo de cargos
    UsuarioController.php        cuentas: alta, rol, acceso, contraseña
    RolController.php            roles y su matriz de permisos
    PerfilController.php         la cuenta propia
    ProductoController.php       catálogo, precios, ingresos y ajustes de stock
    CategoriaController.php      categorías del catálogo
    UnidadMedidaController.php   unidades de venta
    ProveedorController.php      quién abastece el negocio
    PosController.php            el mostrador: búsqueda, carrito y cobro
    VentaController.php          listado, ficha y anulación
    CajaController.php           apertura, movimientos y cierre del turno
    CajaFisicaController.php     los puestos de cobro del local
    InventarioController.php     el almacén: existencias y kardex global
    ComprobanteController.php    listado, impresión (ticket 80 mm / A4) y sustitución
    ClienteController.php        persona natural y persona jurídica
    ReporteController.php        reportes de ventas, productos e inventario
    DashboardController.php      la portada, por bloques según el rol
    DevolucionController.php     devoluciones totales y parciales
    CobroQrController.php        cobro por QR: generar, consultar, confirmar y el aviso del banco
  Http/Middleware/
    VerificarPermiso.php         corta por permiso de rol
    VerificarCuentaVigente.php   corta si la cuenta dejó de tener acceso
  Models/                        Cargo, Empleado, Rol, Permiso, Usuario, Auditoria,
                                 Categoria, UnidadMedida, Proveedor, Producto,
                                 MovimientoInventario, Cliente, Caja, SesionCaja,
                                 MovimientoCaja, Venta, VentaDetalle, VentaPago,
                                 Comprobante, SerieComprobante, TipoComprobante,
                                 MetodoPago, Devolucion, DevolucionDetalle, CobroQr
  Services/
    Auditor.php                  bitácora
    Inventario.php               único punto por el que cambia el stock
    Ventas.php                   registrar y anular, sobre los procedimientos
    Cajas.php                    abrir, mover efectivo y cerrar el turno
    Devoluciones.php             devolver mercadería de una venta cobrada
    Comprobantes.php             sustituir el documento de una venta
    CobrosQr.php                 el cobro por QR, de generarlo a atarlo a su venta
    Qr/PasarelaQr.php            lo que tiene que saber hacer un banco
    Qr/QrSimulado.php            QR real sin banco detrás: lo confirma el cajero
    Qr/QrBanco.php               la plantilla a completar cuando haya convenio
  Support/
    Menu.php                     barra lateral y pantalla de inicio según permisos
    Config.php                   parámetros del negocio (tasa, moneda, formatos)

resources/views/
  layouts/                       app (con barra lateral) y auth (pantalla completa)
  errors/                        404/403/419/500/503 — layout auth, sin sesión ni datos
  components/                    ui.*, form.*, common.*, header.*
  auth/ empleados/ cargos/ usuarios/ roles/ perfil/
  productos/ categorias/ unidades/ proveedores/
  dashboard.blade.php            la portada
  pos/ ventas/ caja/ cajas/ comprobantes/ clientes/ devoluciones/ reportes/
  inventario/                    existencias y kardex del almacén
```

## Lo que sigue

Del esquema quedan sin explotar `v_kardex` (el kardex se arma hoy desde la ficha del producto),
`v_comprobantes_sustituidos` y `v_empleados`. La facturación electrónica ante la administración
tributaria está fuera de alcance en la versión 1, como dice `docs/01-problematica.md`: el modelo
queda preparado, pero no se integra el servicio del organismo.
