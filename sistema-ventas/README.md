# Sistema de Restaurante

Aplicación web del sistema descrito en [`../docs`](../docs). Nació como el punto de venta de un
minimarket y hoy es el sistema de un **restaurante**: la carpeta se sigue llamando
`sistema-ventas` y la base `ventas_db`, porque cambiarles el nombre obligaba a tocar scripts,
parches y pruebas sin ganar nada.

El local es un **restaurante de mostrador con número de pedido**. El cliente **pide y paga en la
caja** —primero se paga—, se lleva un ticket con el número del pedido bien grande y se sienta
donde quiera; la cocina prepara, y alguien le lleva el plato **cantando el número**, que el
cliente confirma con su ticket. Lo entregado hasta ahora:

- **Punto de venta, pedidos y cocina:** el cajero toma el pedido y lo cobra en el mismo acto,
  para comer aquí o para llevar, con una nota por plato para la cocina; la cocina lo ve en su
  pantalla (y en una comanda impresa, si hace falta) y avisa cuando está listo para cantarlo.
  Ver [Pedidos, punto de venta y cocina](#pedidos-punto-de-venta-y-cocina).
- **Personal, seguridad y usuarios:** ingreso al sistema, empleados, cargos, cuentas, roles y permisos.
- **Menú:** los platos con foto y un solo precio, agrupados por categoría; cada categoría dice si
  pasa por la cocina (las bebidas, no).
- **Cobro:** descuento, cobro y vuelto en el punto de venta; caja por turno con arqueo;
  comprobantes (factura y recibo) imprimibles; clientes; anulación de ventas. Una venta puede
  **repartirse entre varias formas de pago** —una parte por QR, el resto en efectivo—, y al
  arqueo entra solo lo que pasó por el cajón.
- **Cobro por QR:** el código se genera con el **importe ya puesto**, así el cliente escanea y
  paga lo que debe sin teclear nada. La venta se registra recién cuando el pago está confirmado:
  un cliente que se arrepiente no deja una venta ni un comprobante emitido. Sin convenio con
  el banco corre en modo simulado y lo confirma el cajero — ver el paso 9 de
  [Nueva instalación](#nueva-instalación-un-cliente).
- **Sustitución de comprobante:** recibo → factura (y al revés) sin tocar la venta.
  La parte **tributaria** (tasa e identificación fiscal) está marcada como en construcción.
- **Reportes:** ventas por día, forma de pago y cajero; los platos más vendidos.
- **Portada:** turno propio y resumen del día, con los bloques que cada rol puede ver.

Lo que **no** hace, a propósito: no lleva inventario. No hay stock, ni kardex, ni compras a
proveedor; el sistema del minimarket los tenía y se retiraron al pasar a restaurante (ver
[Actualizar una base del sistema de ventas](#actualizar-una-base-del-sistema-de-ventas)).

**Stack:** Laravel 13 · Blade · Alpine.js · Tailwind CSS 4 · MySQL 8
La interfaz usa la plantilla [TailAdmin](https://tailadmin.com/) para Laravel.

> Al ingresar, cada cuenta va a **su pantalla de trabajo** (`App\Support\Menu::inicio()`): quien
> lleva la gestión, a la portada; el cajero, directo al punto de venta (`/pos`), que es el centro
> del sistema; la cocina, a su pantalla.

---

## Puesta en marcha

Hay dos formas, y basta con una. **Con Docker** no hay que instalar PHP ni Node en la máquina;
**a mano** es más ligero si ya los tienes y prefieres los procesos a la vista.

### Opción A — todo con Docker (recomendada)

En la raíz del repositorio (un nivel arriba de esta carpeta) hay **dos** stacks de desarrollo, y
conviene no confundirlos:

| Archivo | Proyecto | Para qué | Aplicación | MySQL |
|---------|----------|----------|------------|-------|
| `docker-compose.restaurante.yml` | `restaurante-dev` | **el sistema de restaurante**: es el que se usa | <http://localhost:8200> | `127.0.0.1:13311` |
| `docker-compose.yml` | `ventas-dev` | el sistema de ventas anterior, con su propia base | <http://localhost:8100> | `127.0.0.1:13310` |

Los dos llevan contenedores, puertos y volumen de datos propios, así que pueden estar levantados
a la vez y levantar uno no toca la base del otro. Pero un `docker compose up` **a secas** lee
`docker-compose.yml` y levanta el de ventas: para el restaurante hay que nombrar el archivo cada
vez.

```bash
docker compose -f docker-compose.restaurante.yml up -d --build
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
propia máquina de desarrollo. Los dos stacks comparten ese mismo `.env.docker`; el de restaurante
solo pisa `APP_URL` (su puerto es otro) y el puerto de Vite.

Y ya está: la aplicación queda en <http://localhost:8200>. La primera vez tarda unos minutos,
porque construye la imagen de PHP, carga `docs/sql` en MySQL e instala las dependencias de
Composer y npm dentro de los contenedores.

| Servicio | Dónde | Qué es |
|----------|-------|--------|
| Aplicación | <http://localhost:8200> | nginx + php-fpm |
| Adminer | <http://localhost:8201> | explorar la base; **no** arranca solo, ver abajo |
| MySQL | `127.0.0.1:13311` | `root` / la de tu `.env`, base `ventas_db`. Solo desde esta máquina |
| Vite | puerto 5175 | lo consume el navegador solo; no se abre a mano |

Los puertos van en el rango 82xx para no chocar con los otros proyectos del repositorio, entre
ellos el stack de ventas (81xx, 13310 y 5174). El de Vite lo decide la variable `VITE_PUERTO`,
que `vite.config.js` lee (5174 si no está) y que el compose de restaurante pone en 5175: los dos
stacks montan el mismo código, y sin esa variable los dos Vite pelearían por el mismo puerto.

Adminer queda detrás de un perfil aparte: es un cliente de base de datos sin login propio, y no
tiene sentido dejarlo escuchando todo el tiempo en un servidor real.

```bash
docker compose -f docker-compose.restaurante.yml --profile tools up -d adminer
```

El contenedor `app` se encarga solo del `composer install`, del `storage:link` y del seeder de
credenciales, así que no hay ningún paso manual detrás. Para seguir el arranque:

```bash
docker compose -f docker-compose.restaurante.yml logs -f app
```

Órdenes de artisan dentro del contenedor:

```bash
docker compose -f docker-compose.restaurante.yml exec app php artisan route:list
```

`down` detiene y conserva los datos; `down -v` los borra (siempre con el mismo `-f`).

> La configuración del entorno Docker vive en `sistema-ventas/.env.docker` (plantilla:
> `.env.docker.example`, no se sube al repositorio). Llega a los contenedores como variables de
> entorno reales y Laravel les da prioridad sobre el `.env` del proyecto, que sigue siendo el
> bueno para la opción B.

### Opción B — a mano

#### 1. Base de datos

El esquema y los datos de ejemplo viven en `docs/sql` y los carga Docker al primer arranque.
Desde la **raíz del repositorio** (un nivel arriba de esta carpeta):

```bash
docker compose -f docker-compose.restaurante.yml up -d mysql
```

Deja MySQL en `127.0.0.1:13311` (usuario `root`, la contraseña de tu `.env` — `ventas123` si no
tienes uno, ver [Opción A](#opción-a--todo-con-docker-recomendada)). Adminer se levanta aparte,
como arriba, y queda en <http://localhost:8201>.

Para volver a cargar el esquema a mano:

```bash
docker exec -i restaurante_mysql mysql --default-character-set=utf8mb4 -uroot -pventas123 < docs/sql/01_schema_mysql.sql
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

Encuentra solo el contenedor de MySQL, en este orden: el de producción (`ventas_mysql_prod`), el
del stack de restaurante (`restaurante_mysql`) y el del sistema de ventas anterior
(`ventas_mysql`), y **prefiere el que esté corriendo**: en una máquina con los dos stacks de
desarrollo, el de ventas detenido ya no le gana al de restaurante levantado. Lo mismo hacen
`backup-db.sh`, `restore-db.sh` y `revisar-salud.sh` (`crear-usuario-app.sh` busca en el mismo
orden, pero solo entre los que están corriendo), así que para la base de desarrollo del
restaurante no hay que nombrar nada. `VENTAS_MYSQL=...` queda solo para un contenedor con otro
nombre.

Para otra base que no sea `ventas_db` (por ejemplo, la de un cliente instalado aparte):

```bash
BASE=ventas_db_cliente2 ./scripts/aplicar-parches.sh --aplicar
```

Ojo con esto si tienes una copia vieja del script: los parches empiezan con `USE ventas_db;`, y
antes esa línea mandaba sobre `BASE`: el parche se aplicaba a `ventas_db` y quedaba anotado como
aplicado en la otra base, que se quedaba sin él. Ahora el script cambia ese `USE` por la base
pedida antes de pasar cada parche.

Los cuatro parches del 2026-08-23 (`abarrotes_catalogo_real`, `bebidas_catalogo_real`,
`categoria_cigarrillos`, `sin_impuesto`) quedan fuera **siempre**. No corregían nada del esquema:
cargaban el catálogo del **minimarket** de referencia, sobre columnas que el restaurante ya no
tiene, y los que no fallaran le meterían al menú la góndola de otro negocio. `--catalogo`, que
antes los dejaba entrar, ahora se rechaza con una explicación y sin tocar nada. Los archivos se
quedan en `docs/sql/parches` porque son historia: las bases instaladas antes de la conversión los
tienen anotados en `parches_aplicados`. El menú se arma desde la pantalla **Menú**.

#### Actualizar una base del sistema de ventas

Diecinueve parches, en cuatro tandas, llevan una instalación que corría el sistema de ventas hasta el
restaurante de hoy. Se aplican con `aplicar-parches.sh` como cualquier otro y en orden alfabético,
que es el que necesitan, así que el script lo respeta solo. Una base creada hoy con
`01_schema_mysql.sql` no necesita ninguno: ya los trae anotados.

Los seis del **2026-09-17** convierten el minimarket en un restaurante:

| Parche | Qué hace |
|---------|----------|
| `eliminar_inventario_y_devoluciones` | Borra el inventario entero —stock, kardex, lotes, compras y devoluciones a proveedor, tomas de inventario, proveedores— y las devoluciones de cliente, con sus triggers y vistas. Retira el rol Almacenero y los permisos `inventario.*`. `sp_cerrar_caja` deja de restar devoluciones |
| `mesas_y_pedidos` | Crea `pedidos` y `pedido_detalle` con sus dos triggers, los permisos `pedidos.registrar` y `cocina.ver`, el rol Cocina y el cargo Cocinero. Crea además una estructura intermedia que deshacen `sin_mesas` y `sin_mozos`, del 18/09: sin efecto en una base del sistema de ventas |
| `numero_diario_de_pedido` | Agrega el número del día a cada pedido y numera los que ya existían, día por día y en orden de apertura |
| `sin_codigo_de_barras` | Quita `productos.codigo_barras`. El código interno se queda |
| `sin_costo_de_compra` | Quita `productos.precio_compra` y `venta_detalle.costo_unitario`, y rehace `v_productos_mas_vendidos` sin margen |
| `sin_unidades_de_medida` | Quita la tabla `unidades_medida`, la columna del producto y la copia de la unidad en cada línea vendida |

Los seis del **2026-09-18** la llevan al local de mostrador con número de pedido:

| Parche | Qué hace |
|---------|----------|
| `comanda` | Agrega `pedido_detalle.comandado_en` y `cancelacion_comandada_en`, para saber qué ya salió en la [comanda impresa](#la-comanda-impresa). Las líneas que ya existen quedan en `NULL`: nunca salieron en papel |
| `jornada_del_pedido` | Agrega `configuracion.hora_corte_jornada` (5) y convierte `pedidos.fecha_dia`, que era una columna generada, en `pedidos.jornada`, una columna normal. `uq_pedido_numero_dia` pasa a ser `(jornada, numero_dia)` |
| `pasa_por_cocina` | Agrega `categorias.pasa_por_cocina` (en 0 para «Bebidas», si existe) y `pedido_detalle.pasa_por_cocina`, y alinea con su categoría las líneas que ya existen |
| `permiso_del_menu` | Solo el texto: el permiso `productos.gestionar` pasa a leerse «Menú · Crear y editar los platos del menú y sus precios» en la matriz de roles. El código no cambia |
| `sin_mesas` | Deja `pedidos.tipo` en `ENUM('LOCAL','LLEVAR')` («comer aquí» o «para llevar») y borra la estructura intermedia de `mesas_y_pedidos` (una tabla, columnas, índices y un CHECK de `pedidos`), que en una base del sistema de ventas está vacía |
| `sin_mozos` | Deshace el rol y el cargo intermedios de `mesas_y_pedidos`: sin efecto en una base del sistema de ventas |

Los seis del **2026-09-19** normalizan lo que quedó de la conversión. Van numerados porque el
orden importa:

| Parche | Qué hace |
|---------|----------|
| `1_logica_igual_en_las_dos_vias` | Iguala la base con su réplica en PHP: un valor vacío en `configuracion` cuenta como ausente, y `sp_anular_venta` rechaza anular una venta de un turno ya cerrado. No toca datos |
| `2_la_venta_guarda_su_pedido` | Cambia de lado la relación: fuera `pedidos.venta_id`, entra `ventas.pedido_id`, con un índice único que deja **una sola venta vigente por pedido**. Quita `pedidos.cliente_id` (el cliente es de la venta) y agrega los CHECK de coherencia de estados en ventas, turnos de caja, comprobantes, cobros QR y líneas del pedido |
| `3_configuracion_y_limpieza` | La serie por omisión de cada tipo de comprobante pasa a `tipos_comprobante.serie_por_omision_id`, con FK; salen de `configuracion` `serie_factura`, `serie_recibo` y `moneda_simbolo`. CHECK por clave en `configuracion`. Quita `pedidos.telefono_cliente`, `comprobantes.archivo_pdf`, cuatro vistas y un índice que nadie usaba. `v_ventas_por_dia` agrupa por jornada. El detalle de ventas y pedidos deja de borrarse en cascada |
| `4_emisor_documentos_y_detalle` | El comprobante congela los datos del negocio al emitir (`emisor_*`). Sale `venta_detalle.descuento`, que valía siempre 0. Los documentos de identidad pasan a la tabla `tipos_documento` |
| `5_identificadores_sin_tope` | `cajas`, `roles` y `cargos` pasan de `TINYINT` a `INT`: el `AUTO_INCREMENT` no vuelve atrás cuando una transacción se deshace, y el tope de 255 se alcanzaba sin tener 255 filas |
| `6_sin_pantalla_de_respaldos` | Quita el permiso `respaldos.gestionar`: la pantalla de Respaldos ya no existe; los respaldos corren por debajo (ver [Copias de seguridad](#copias-de-seguridad)) |

El del **2026-09-20** (`lo_cerrado_no_se_toca`) cierra lo que la base todavía dejaba hacer por
fuera de la aplicación: borrar comprobantes o el detalle de una venta, agregarle líneas o pagos
a una venta ya documentada, meter movimientos en un turno cerrado. Suma ocho `CHECK` (entre
ellos, que el número impreso del comprobante nunca se corte), pasa `metodos_pago.id` a `INT` y
hace que el reporte por forma de pago corte por jornada, como el resto. Igual que los del 19/09,
revisa antes los datos y aborta sin tocar nada si alguno no cumple.

Lo que quien los aplica tiene que saber, porque cada parche lo avisa pero es fácil no leerlo:

- **Borran datos.** Cuatro de los seis del 17/09 tiran columnas o tablas enteras: el historial
  de compras, lotes, kardex y devoluciones, el costo de cada producto y el costo congelado de
  cada línea vendida, los códigos de barras y las unidades. Los del 18/09 no pierden nada de una
  base del sistema de ventas. Haz un respaldo antes (`php artisan respaldo:crear` en el
  contenedor de la aplicación, o `scripts/backup-db.sh`).
- **Las cuentas con rol Almacenero pasan a Cajero.** El rol desaparece, y seguir pudiendo entrar
  importa más que el permiso exacto. Pero el Cajero **cobra y abre caja**: revisa después en
  **Seguridad → Usuarios** si a esa persona le corresponde otro rol, o desactiva la cuenta si ya
  no la usa.
- **El número de los pedidos viejos no se recalcula.** `jornada_del_pedido` deja a cada pedido
  existente con la jornada igual al día de su apertura, que es con lo que se numeró. Recalcularla
  con la hora de corte haría caer en la misma jornada un «pedido 1» de las 23:00 y otro de la
  01:00 del día siguiente —legítimos con la regla vieja— y rompería el índice único; renumerarlos
  tampoco se puede, porque esos números están impresos en tickets que el cliente se llevó. Si se
  aplica a mitad de un servicio que ya pasó la medianoche, el primer pedido nuevo sigue el número
  del último de la jornada de ayer: no se repite ninguno.
- **Las bebidas de un pedido abierto dejan de verse en la cocina** al aplicar `pasa_por_cocina`,
  porque las líneas que ya existen se alinean con su categoría. Eso pasa una sola vez: una segunda
  pasada no pisa lo que el negocio haya cambiado después en Categorías.
- **Las ventas marcadas como devueltas vuelven a «completada».** Siguen siendo ventas cobradas
  con su comprobante emitido, y el estado `DEVUELTA` ya no existe. Es pérdida de historial: si
  alguna se devolvió entera y el negocio no quiere contarla, hay que anularla a mano después.
  Los **arqueos ya firmados no se recalculan**: `sesiones_caja` guarda su `monto_esperado` y su
  `monto_declarado` como números, así que un cierre viejo que restó una devolución sigue diciendo
  lo mismo que el día que se firmó. Lo que cambia es la fórmula de los cierres de aquí en adelante.
- **Los del 19/09 revisan antes de tocar.** `2`, `3` y `4` comprueban primero que los datos
  cumplan lo nuevo (los CHECK, que las series por omisión sean de su tipo, que ninguna línea
  vendida tenga descuento, que cada documento de identidad valga para quien lo tiene). Si algo
  no cumple, lo muestran y **abortan sin cambiar nada**: hay que corregirlo a mano, con criterio
  porque son datos del negocio, y volver a aplicar.
- **Los comprobantes viejos toman los datos del negocio de hoy.** El día en que se emitieron no
  se guardó en ningún lado; `4_emisor_documentos_y_detalle` los llena con lo que dice
  `configuracion` al aplicarlo. Si el negocio cambió de nombre o de NIT, salen con el nuevo, igual
  que hasta ahora. Desde ahí, cada comprobante se imprime con los datos del día en que se emitió.
- **Cada venta anulada recupera su pedido.** `2_la_venta_guarda_su_pedido` lo toma de
  `pedidos.venta_id` o, para las que ya lo habían perdido al anularse, de la bitácora de la
  reapertura. Las ventas de antes de los pedidos quedan sin pedido.

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

Ese enlace es el que hace visibles las fotos de los platos. Se crea una sola vez.

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

| Usuario   | Contraseña  | Rol           | Empleado          | Al entrar cae en |
|-----------|-------------|---------------|-------------------|------------------|
| `admin`   | `admin123`  | Administrador | Ana Quispe Torres | `/inicio` |
| `cajero1` | `cajero123` | Cajero        | Luis Ramos Vega   | `/pos` |
| `cocina1` | `cocina123` | Cocina        | Raúl Choque Apaza | `/cocina` |

La base de demostración trae además doce platos en cuatro categorías: Entradas, Platos de fondo,
Bebidas y Postres. Bebidas viene marcada como que **no pasa por la cocina**.

---

## Cómo está organizado

### Los tres conceptos que no se mezclan

El esquema separa a propósito tres cosas que suelen confundirse:

| Concepto     | Tabla       | Qué representa                                                   |
|--------------|-------------|------------------------------------------------------------------|
| **Cargo**    | `cargos`    | La función laboral: Gerente, Cajero, Cocinero, Ayudante.          |
| **Empleado** | `empleados` | La persona y su vínculo laboral: ingreso, contrato, cese.         |
| **Usuario**  | `usuarios`  | La cuenta con la que se entra al sistema, y su rol de acceso.     |

De ahí salen dos reglas que el código respeta en todas partes:

- Un empleado **puede no tener cuenta** (Jorge, el ayudante, trabaja sin usar el sistema).
- El **cargo no determina el rol**: la gerente Ana tiene rol Administrador, pero un cajero de
  confianza también podría tenerlo. Y el cargo se llama Cocinero mientras el rol se llama
  Cocina: uno es lo que dice el contrato, el otro lo que la cuenta puede hacer.

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

Los tres roles de fábrica, y lo que cambia entre ellos en el día a día:

| Rol | Permisos | Qué hace |
|-----|----------|----------|
| Administrador | todos | Todo, incluido cerrar la caja de otro, anular ventas y cancelar un pedido que la cocina ya empezó |
| Cajero | `ventas.registrar`, `caja.abrir`, `pedidos.registrar` | Abre su turno, toma los pedidos y los cobra en el punto de venta: el que está en la caja es quien atiende al cliente, y el cliente paga al pedir. **No cierra** su propio turno: el arqueo lo hace quien no tuvo la mano en el cajón |
| Cocina | `cocina.ver` | Ve lo que hay que preparar, lo avanza, marca el pedido entregado e imprime la comanda. Nada de dinero |

Los números del rol 3 y del cargo 3 quedan sin usar en `02_datos_iniciales.sql`, para que los de
los demás sigan siendo los mismos en todas las bases.

`pedidos.registrar` ya no sirve para tomar pedidos —eso lo hace el punto de venta con
`ventas.registrar`—: hoy **solo habilita cancelar** el pedido de una venta anulada, o uno de sus
platos, mientras espera volver a cobrarse (ver
[el camino de corrección](#volver-a-cobrar-el-camino-de-corrección)).

Dos permisos más son solo del Administrador: `clientes.editar` (el cajero da de alta clientes al
vuelo, pero corregir uno ya registrado es cosa del administrador) y `registros.eliminar`, que
protege toda ruta que borra
—platos, categorías, clientes, cajas, cargos, empleados, usuarios y roles—. Y quien no tiene
`reportes.ver` solo ve **sus propias** ventas y comprobantes.

### El menú lateral

`App\Support\Menu::grupos()` arma la barra en este orden, y cada entrada aparece solo si la
cuenta tiene el permiso:

| Grupo | Entradas |
|-------|----------|
| Pedidos | Cocina |
| Mostrador | Inicio, Punto de venta, Caja, Cajas del local |
| Ventas | Ventas, Comprobantes |
| Menú | Menú, Categorías |
| Reportes | Ventas, Más vendidos |
| Seguridad | Usuarios, Roles y permisos |
| Sistema | Bitácora, Configuración |
| Administración | Clientes, Empleados, Cargos |
| Cuenta | Mi perfil |

**Pedidos** tiene una sola entrada, **Cocina**, y solo la ve quien tiene `cocina.ver` (la cocina
y el administrador). Va primero para ellos porque es su pantalla de trabajo, y la de quien lleva
los platos. Los pedidos se toman y se cobran en el **Punto de venta**, dentro de Mostrador: se
cobra al pedir. Los pocos que hay que volver a cobrar —los de una venta anulada— aparecen en el
propio punto de venta, en «Volver a cobrar».

**Cajas del local** pide `configuracion.editar` y no `caja.abrir`: dar de alta un segundo puesto
de cobro es administrar el local, no la operación del día (por eso también `/cajas` va aparte de
`/caja`).

**Administración** va en último lugar a propósito. Son pantallas que el restaurante casi no abre,
pero que el sistema sigue necesitando: el ingreso exige un empleado detrás de cada cuenta, la
factura exige un cliente identificado y de Cargos salen los cargos con los que se da de alta al
personal. Configuración, en Sistema, guarda la moneda, el impuesto, la hora de corte de la
jornada y las series de los comprobantes. Quitarlas del menú no las haría menos necesarias: solo
obligaría a entrar por SQL el día que haya que tocarlas.

### El «Menú» es la tabla `productos`

En toda la interfaz se dice **Menú** —el grupo del menú lateral, el título de las pantallas, la
URL `/menu`—, porque el sistema habla el idioma del local. Por dentro, en cambio, todo sigue
diciendo **producto**:

| Qué | Nombre |
|-----|--------|
| URL | `/menu`, `/menu/nuevo`, `/menu/{id}` |
| Nombres de ruta | `productos.index`, `productos.show`, `productos.store`… |
| Tabla y modelo | `productos`, `App\Models\Producto`, `ProductoController` |
| Vistas | `resources/views/productos/` |
| Permiso | `productos.gestionar` |

No es un descuido: cambiar la tabla, el modelo, las rutas y el permiso por un cambio de etiqueta
habría tocado el esquema, los parches, media aplicación y la matriz de permisos de cada
instalación, para que el negocio viera exactamente lo mismo. Quien lea el código tiene que saber
que **un plato es un `Producto`**.

### Precios: uno solo por plato

El plato tiene **un precio**: lo que paga el cliente. No hay precio de compra —en un restaurante
el plato se hace ahí, no se compra para revenderlo—, y con el costo se fueron el margen por plato
y la ganancia de los reportes. Reportes dice cuánto se vendió, no cuánto se ganó.

Ese precio se guarda en `productos.precio_venta`. Cuando el negocio no desglosa impuesto
(`precios_incluyen_impuesto` o tasa en cero, según **Impuesto y precios** en Configuración), es
lo que paga el cliente tal cual. Si el negocio carga el impuesto encima, `precio_venta` es la base
y el precio de estante se calcula al vuelo con la tasa vigente en `configuracion`:

```
precio_estante = precio_venta * (1 + tasa_impuesto)      // si el plato está afecto
```

El formulario del plato calcula en ambos sentidos: escribes la base y sale el estante, o escribes
el estante y sale la base. Así los precios quedan redondos en la carta sin sacar la calculadora.
Todo cambio de `precio_venta` se audita con la acción `CAMBIO_PRECIO` (causa C3 del análisis:
precios no centralizados).

`App\Support\Config` lee esos parámetros del negocio (tasa, moneda, nombre del local) una sola
vez por petición.

Tampoco hay **código de barras** ni **unidad de medida**. Nadie escanea un plato, así que el
buscador del punto de venta encuentra por nombre o por el código interno (`P-0004`), que es lo
que se teclea. Y todo se despacha por porción: la cantidad es
siempre entera y la valida la aplicación (`Ventas` y `Pedidos`). La columna `cantidad` sigue
siendo `DECIMAL(12,3)` porque ahí quedan las ventas viejas del minimarket, pesadas al gramo, y
reescribirlas a entero les cambiaría el importe.

### Colores y marca

El sistema sale con los colores de **InnovaDevs**, la empresa que lo desarrolla: el azul del logo
(`#0a5cff`) en botones, menú y enlaces, y el azul marino (`#060d24`) de fondo en el modo oscuro.
Para un cliente que quiera sus propios colores, todo está en `resources/css/app.css`:

- la escala `--color-brand-25` … `--color-brand-950` del bloque `@theme` es el color de la marca.
  `brand-500` lleva texto blanco encima: tiene que tener un contraste de 4.5:1 o más;
- la escala `--color-marino-*` es el azul marino: el fondo del inicio de sesión, que es oscuro
  siempre, y el del modo oscuro. El bloque `.dark { … }` al final del archivo la aplica al modo
  oscuro; borrarlo lo deja en el gris neutro de la plantilla;
- fuera del CSS repiten el azul el ícono `public/images/logo/logo-icon.svg` (la pestaña del navegador), la primera serie de los
  gráficos (`resources/js/graficos.js`), el PDF de los reportes y el botón «Imprimir» de las
  vistas de impresión, y el `theme-color` del `<head>`.

Después, `npm run build`. Si cambias los logos, cambia también el `?v=` con el que se piden, para
que los navegadores no sigan mostrando el viejo.

El inicio de sesión es una tarjeta centrada sobre el azul marino, con el **nombre del negocio**
de Configuración (`Config::negocio()`) arriba: es lo que el personal reconoce. En el inicio de sesión y al pie del menú va una línea discreta, **«Desarrollado por
InnovaDevs»**. El nombre sale de `DESARROLLADO_POR` en el `.env`, y vacío no se muestra.

### La moneda

Sale de una sola clave de `configuracion`, `moneda_codigo` (`BOB`), el código ISO que se congela
en cada comprobante emitido. Lo que se muestra en pantalla (`Bs`) no se guarda aparte: lo traduce
`Config::simbolo($codigo)` y, si no conoce el código, lo muestra tal cual —mejor «CLP 1.200» que
un símbolo equivocado—. Antes había también una clave `moneda_simbolo`, y nada impedía que dijera
`$` con el código en `BOB`; el parche `3_configuracion_y_limpieza` la quitó.

Para cambiarla basta actualizar esa fila (la base exige tres letras mayúsculas). Ojo con una cosa:
`comprobantes.moneda` guarda el código **del momento de emitir**, así que un documento viejo sigue
mostrando su símbolo aunque el negocio cambie de moneda —es una foto, no una referencia—.

> La **tasa de impuesto** es un parámetro aparte (`tasa_impuesto`) y no se toca al cambiar de
> moneda: hacerlo alteraría el precio de estante de todo el menú.

### Lo tributario está en construcción

Los documentos ya usan la nomenclatura boliviana —**CI** para personas y **NIT** para empresas—,
pero el régimen tributario en sí sigue sin cerrar y el sistema no se integra con Impuestos
Nacionales. Mientras el negocio no factura, las pantallas no hablan de impuesto ni de facturas
(`MOSTRAR_FACTURACION=false`, el valor por omisión); con la facturación a la vista, el sistema
dice que está en construcción en vez de aparentar lo contrario:

- el listado de comprobantes lleva arriba un aviso «Régimen tributario en construcción»;
- el formulario del plato marca la tasa como provisional junto a la casilla de impuesto.

Lo que **sí** está terminado es la mecánica: series y correlativos con bloqueo de fila, desglose
del impuesto por línea, sustitución y anulación de documentos, y la trazabilidad completa. Cerrar
la parte tributaria es fijar `configuracion.tasa_impuesto`, el rótulo del documento fiscal y —si
se quiere— los documentos de identidad, que están en la tabla `tipos_documento` (cada uno dice si
vale para personas, empresas o empleados); después se puede quitar el aviso, que vive en
el componente `<x-ui.en-construccion>`.

La facturación electrónica ante la administración tributaria queda fuera de alcance en la versión
1, como dice `docs/01-problematica.md`.

### Fotos de los platos

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
todas las fotos de plato vienen recortadas sobre blanco.

Se sube desde el formulario del plato (JPG, PNG o WEBP, hasta 2 MB) con vista previa antes de
guardar, y se puede quitar. Al reemplazar o quitar una foto, **el archivo anterior se borra del
disco**: si no, la carpeta se llena de imágenes que ya no referencia nadie.

La foto aparece en el listado del menú, en la ficha del plato y —donde más se nota— en las
tarjetas del punto de venta. Lo que no tiene foto muestra un marcador del mismo tamaño, para que
la cuadrícula no se descuadre.

> `Producto::imagen_url` usa `asset()` y **no** `Storage::url()`. El segundo arma la dirección con
> `APP_URL`, y como el sistema se abre desde varias máquinas de la red del negocio (RNF3), con
> `APP_URL=http://localhost` las fotos se romperían en todas menos en el servidor. `asset()` toma
> el host de la petición en curso.

### Pedidos, punto de venta y cocina

Es el corazón del sistema. El recorrido de un pedido:

1. **El cliente pide y paga en la caja.** El cajero, en `/pos`, elige **«Comer aquí»** (por
   omisión) o **«Para llevar»**, carga los platos —cada uno con su **nota para la cocina**, «sin
   cebolla», «término medio»—, anota si quiere un **nombre para llamar al cliente** y cobra.
2. **El cliente se lleva su ticket** con el número del pedido, lo más grande del papel, y se
   sienta donde quiera.
3. **La cocina lo ve** en `/cocina` —y en una comanda impresa, si hace falta— y avanza cada plato:
   `PENDIENTE → EN_PREPARACION → LISTO`.
4. **Cuando el pedido está listo entero**, la pantalla lo destaca: «¡Listo! Canta el 7». Quien
   lleva los platos canta el número, el cliente lo confirma con su ticket, y un toque en
   **«Entregado»** cierra el pedido entero en la cocina.

Quien está en la caja es quien atiende, y se paga primero: nada llega a la cocina sin cobrar.
Un pedido para comer aquí no sabe dónde se sentó el cliente; lo encuentra el número.

| Tabla | Qué guarda |
|-------|------------|
| `pedidos` | El pedido: comer aquí o para llevar (`tipo`, `ENUM('LOCAL','LLEVAR')`), su número y su jornada, a nombre de quién, quién lo tomó y su estado (`ABIERTO`, `CERRADO`, `CANCELADO`). No guarda importes ni cliente: eso es de la venta |
| `pedido_detalle` | Cada plato pedido, con su precio, su nota, si pasa por la cocina, su estado en ella y cuándo salió en la comanda |

El código vive en `App\Services\Pedidos` (toda la lógica), `PosController` (tomar el pedido y
cobrarlo), `PedidoController` (solo volver a cobrar el pedido de una venta anulada, o cancelarlo),
`CocinaController` (la pantalla de la cocina) y `ComandaController` con `App\Services\Comandas`
(la comanda impresa), con sus vistas en `resources/views/pos/`, `pedidos/`, `cocina/` y
`comandas/`.

#### El punto de venta es el centro

Todo lo que se pide en el local entra por `/pos`, y el cajero cae ahí al ingresar. El buscador
encuentra por nombre o por código interno, con fichas por categoría para elegir de un toque;
**Enter** agrega el primero, **F2** vuelve al buscador y **F4** cobra. El carrito vive en el
navegador hasta que se cobra: nada llega a la cocina antes de estar pagado, porque en este local
se paga al pedir.

Cobrado el pedido, la pantalla pasa a la ficha de la venta, con el número del pedido en grande,
el comprobante para imprimir y, si el pedido tiene algo para la cocina, el botón **«Imprimir
comanda»**. Lo que no tiene es lector de código de barras: se fue con el minimarket.

#### Pedir y cobrar, en una sola transacción

`Pedidos::venderEnMostrador()` abre el pedido con su número de la jornada, le carga los platos y
lo cobra con `Pedidos::cobrar()`, que a su vez usa `Ventas::registrar()`. Todo **en una sola
transacción**: o queda el pedido cobrado con su venta y su comprobante, o no queda nada —ni un
pedido abierto huérfano, ni un número de la jornada gastado—. Ante un deadlock se reintenta
entero; la transacción de `Ventas::registrar()` queda anidada y ya no reintenta por su cuenta.

¿Por qué no registrar la venta suelta, como hacía el minimarket? Porque el pedido es lo que
necesitan la cocina y el cliente: sin él no hay platos en la pantalla de la cocina ni número que
cantar. Y ¿por qué no cobrarlo con un circuito propio? Porque pasando por `cobrar()` →
`Ventas::registrar()` el pedido hereda, sin escribir nada de nuevo, el descuento con el tope del
cajero, los pagos mixtos, el cobro por QR, el control de `totalEsperado` (si el total que vio el
cajero no cuadra con el del servidor, no se cobra), el comprobante y la auditoría.

#### Cobrar no es un circuito de dinero aparte

Es la decisión más importante del módulo. `Pedidos::cobrar()` **no** calcula totales, ni emite
comprobantes, ni toca la caja: traduce el pedido a una venta normal y se la pasa a
`Ventas::registrar()`. Ese servicio ya resuelve los pagos mixtos, el cobro por QR, el descuento,
el comprobante, el arqueo y la auditoría, por sus dos vías (los procedimientos de la base o
`ReglasEnPhp`, ver [La venta](#la-venta-qué-hace-la-aplicación-y-qué-hace-la-base)), y la venta
nace apuntando a su pedido (`ventas.pedido_id`). Después solo cierra el pedido.

Escribir un cobro propio para los pedidos habría creado una **segunda fuente de verdad para el
dinero**: dos lugares donde se calcula un total, dos donde se emite un comprobante, dos formas de
que el arqueo cuadre. El día que alguien corrigiera el redondeo del impuesto en uno, el otro
seguiría con el viejo. Por el mismo motivo `pedidos` no guarda importes: mientras está abierto, el
total es la suma de su detalle; en cuanto se cobra, el dinero vive en la `venta`.

La relación vive en la venta y no en el pedido porque un pedido puede tener **varias** ventas: la
que se anuló y la que lo volvió a cobrar. Cuando la llevaba el pedido (`pedidos.venta_id`), anular
la dejaba en `NULL` y la venta anulada perdía de qué pedido era. Lo que sí es único es la venta
**vigente**: `ventas.pedido_cobrado_uk` vale `pedido_id` solo mientras la venta está `COMPLETADA`,
y su índice único impide que dos cajeros que pulsen «Cobrar» a la vez dejen dos ventas vivas del
mismo pedido. La ruta para volver a cobrar un pedido lleva además el middleware
`un.envio` contra el doble clic, y el servicio bloquea la fila del pedido mientras cobra: es lo
que cierra la carrera con la cocina, que puede estar cancelando un plato justo en ese momento y
lo dejaba cancelado y cobrado a la vez.

#### Volver a cobrar: el camino de corrección

En este local se cobra al pedir, y ninguna pantalla abre un pedido para cobrarlo más tarde. Un
pedido solo vuelve a quedar `ABIERTO` por un único camino: el de corregir un cobro que se anuló.

El caso: el cajero cobra el pedido 7 con la forma de pago equivocada y un administrador anula la
venta para rehacerla. En ese momento **el cliente ya tiene su ticket con el 7 y la cocina ya lo
está cocinando**. Si anular cancelara el pedido y hubiera que cargarlo de nuevo en el punto de
venta, saldría un «pedido 8» con los mismos platos repetidos en la cocina, y un cliente con un
ticket que ya no dice nada. Por eso anular **reabre el pedido** (`Pedidos::reabrirTrasAnular`):
vuelve a `ABIERTO` con su número y sus platos tal cual —también lo que la cocina ya empezó o
entregó, que queda sin pagar hasta rehacer el cobro— y sigue en la pantalla de la cocina,
marcado «Cobro anulado».

- Aparece arriba de todo en el punto de venta, en **«Volver a cobrar»**, y el botón «Cobrar o
  cancelar» lleva a `pedidos/{id}/cobrar`, donde se cobra de nuevo **con el mismo número**. El
  aviso lo dice con todas las letras: no hay que volver a cargarlo como venta nueva.
- También se puede **cancelar**, con su motivo obligatorio. Si la cocina ya empezó, terminó o
  entregó algún plato, cancelarlo es dejar sin cobrar lo consumido —la misma pérdida que anular
  una venta— y por eso exige además `ventas.anular` (C1): sin esa regla, la de los platos se
  saltaba cancelando el pedido entero.
- Un plato suelto se puede cancelar mientras la cocina no lo terminó: el cliente ya no lo quiere
  al rehacer el cobro.
- `reabrirTrasAnular` corre dentro de la transacción de `Ventas::anular()`, con la venta ya
  anulada, y no dentro de `sp_anular_venta`: así vale igual con y sin los procedimientos de la
  base.
- Quien cierra la caja ve los pedidos para volver a cobrar y tiene que confirmar que cierra igual (ver
  [Caja](#caja)).

El permiso `pedidos.registrar` hoy **solo habilita esas cancelaciones**; cobrar el pedido pide
`ventas.registrar` y un turno de caja abierto, igual que cualquier venta.

#### El número del pedido y la jornada

El pedido se identifica en voz alta con un número que **empieza en 1 cada jornada** —«pedido
7»—, y no con su `id`. El `id` es global y creciente: a los pocos meses de servicio va por «4812»,
y eso no se canta en la barra. El `id` sigue siendo la clave y lo que va en las URLs;
`pedidos.numero_dia` es lo que lee una persona.

Es **un solo contador**, para comer aquí o para llevar: el ticket ya dice cuál es cuál, y dos
numeraciones en paralelo harían que en el mismo turno convivan dos «pedido 7».

El contador **no vuelve a 1 a medianoche**, sino a la **hora de corte**
(`configuracion.hora_corte_jornada`, 5 por omisión, de 0 a 12), porque el local cierra pasada la
medianoche: con el corte a las 5, el pedido de la 01:30 del 19/09 es de la jornada del 18/09 y
sigue su numeración, y a las 05:00 empieza otra vez el 1. Se cambia en **Sistema →
Configuración**, tarjeta «Número del pedido». Conviene una hora en la que el local ya esté
cerrado: si cae en pleno servicio, esa noche habría dos «pedido 1».

La jornada la escribe `Pedidos::abrir()` en `pedidos.jornada` (`Config::jornadaDe()`: la fecha
menos las horas del corte). Antes era `fecha_dia`, una columna generada con el día de la
apertura; dejó de serlo porque una columna generada no puede leer la configuración, y cambió de
nombre porque ya no es la fecha de la apertura: leerla como tal llevaría a error. La unicidad la
pone la base: `uq_pedido_numero_dia (jornada, numero_dia)` impide que dos cajas que cobran en el
mismo segundo saquen el mismo número. `Pedidos::abrir()` toma el siguiente con `FOR UPDATE` —el
mismo criterio de `sp_siguiente_comprobante` con el correlativo— y, si aun así choca, reintenta
como `Ventas::registrar()` ante un deadlock.

Cambiar la hora de corte no renumera nada: los pedidos ya abiertos conservan su número, y el
parche que introdujo la jornada no recalculó el historial (ver
[Actualizar una base del sistema de ventas](#actualizar-una-base-del-sistema-de-ventas)).

#### Los estados de la cocina, y cancelar

- El camino normal es `PENDIENTE → EN_PREPARACION → LISTO → ENTREGADO`, un toque por paso
  (`PedidoDetalle::TRANSICIONES`).
- No hay vuelta atrás. Si la cocina se adelantó, se cancela el plato y se pide de nuevo, y así
  queda escrito lo que pasó.
- **Nada se borra.** Un plato se **cancela**: queda en el pedido con su estado `CANCELADO` —el
  rastro de que se pidió y de que alguien pudo estar cocinándolo— y **no se cobra**.
- Solo se cancela mientras el pedido espera **volver a cobrarse** y el plato no salió `LISTO`. En
  un pedido ya cobrado, que es lo normal, un plato no se cancela: el dinero ya entró, y cancelar
  no lo devuelve. Si no se va a servir, se anula la venta, y el pedido pasa a volver a cobrar.
- **Lo que la cocina ya está preparando solo lo cancela un administrador**, con su motivo
  (`Pedidos::autorizarCancelacion`): cancelarlo es dejar sin cobrar algo que ya se empezó. Un plato
  que la cocina todavía no tocó lo cancela la caja. Lo mismo con el pedido entero: si tiene algún
  plato empezado o entregado, solo lo cancela un administrador.

Dos triggers dejan esas reglas también en la base, para lo que entre por fuera de la aplicación:

| Trigger | Qué impide |
|---------|------------|
| `trg_pedido_detalle_before_insert` | Agregar platos a un pedido que ya no está `ABIERTO` |
| `trg_pedidos_before_delete` | Borrar un pedido: se cancelan con su motivo, igual que las ventas se anulan |

Ninguno de los dos tiene espejo en `ReglasEnPhp`, por el mismo criterio que
`trg_ventas_before_delete`: la aplicación nunca borra un pedido, y el primero lo valida ya
`Pedidos::agregarLinea()` —con el pedido bloqueado— antes de insertar. Con `LOGICA_EN_PHP=true`
esa validación de PHP es la única defensa, y por eso está hecha dentro de la transacción y no con
un `SELECT` suelto.

#### Lo que no pasa por la cocina

Desde que todo lo que se vende entra por el punto de venta, la pantalla de la cocina y la comanda
se llenaban de gaseosas y botellas que nadie cocina. `categorias.pasa_por_cocina` lo resuelve: lo
de una categoría marcada en «no» —Bebidas, de fábrica— **se cobra igual y sigue en el pedido,
pero no va a la pantalla de la cocina ni a la comanda**. Se edita en **Menú → Categorías**.

La línea copia el valor de su categoría al pedirse, como copia el precio
(`pedido_detalle.pasa_por_cocina`): cambiar la categoría después no hace aparecer ni desaparecer
de la cocina lo que ya se pidió. En el pedido esas líneas se leen **«Sin cocina»** y, al
cobrarse, pasan a `ENTREGADO`: se entregan con el ticket, y no se quedan «pendientes» para
siempre. Mientras el pedido espera volver a cobrarse siguen en `PENDIENTE` a propósito: así se pueden
cancelar sin permiso especial y no cuentan como platos empezados.

#### Al cobrar, las líneas del mismo plato se juntan

`venta_detalle` exige una línea por producto (`uq_detalle_venta_producto`), y `pedido_detalle`, a
propósito, no: dos porciones del mismo plato pueden llevar notas distintas y avanzar cada una por
su lado en la cocina. `Pedidos::lineasAgrupadas()` junta las líneas del mismo plato antes de
pasarlas a la venta, y deja fuera lo cancelado. El precio unitario del agrupado **sale de lo
pedido, no del menú de hoy**:

```
precio_unitario = ROUND(SUM(importe) / SUM(cantidad), 2)
```

El punto de venta ya manda una sola línea por plato, así que en la práctica el agrupado no
cambia nada: es una salvaguarda. Si un pedido llegara a tener dos líneas del mismo plato a
**precios distintos**, esa división redondeada y la multiplicación que hace después la venta
pueden dejar **un céntimo** de diferencia con la suma de las líneas. Es a propósito y está fijado en `CobroDePedidoTest` (la prueba admite hasta un
céntimo): las alternativas eran cobrar al precio del menú de ahora, que se desvía mucho más, o
partir la venta en una línea por tanda, que `venta_detalle` no admite.

Lo que se muestra de un pedido para volver a cobrar —en el punto de venta, en su pantalla de cobro, en el
cierre de caja— usa el mismo agrupado (`Pedidos::totalesDe()`), para que el total que se promete
sea el que después cobra la venta.

#### La pantalla de la cocina

`/cocina` se mira de lejos y con las manos ocupadas, y la usan la cocina y quien lleva los platos.
Por eso es un **tablero de tres columnas** —**Por hacer**, **Cocinando** y **Para entregar**— y
cada pedido se lee por su **número, en grande** —el que se canta y el que el cliente tiene en el
ticket—, con «Comer aquí» o «Para llevar», el nombre si lo hay y las notas destacadas en su
propio recuadro.

- **Un botón por pedido**, no por plato: **Empezar** lo pasa a Cocinando, **Listo** a Para
  entregar (también lo que ni se había empezado: un plato rápido va de pendiente a listo) y
  **Entregado** lo saca del tablero. Es `Pedidos::avanzarTodo()`, que pasa cada plato por las
  mismas reglas y el mismo rastro que si se tocara uno por uno. Tocar un plato suelto lo avanza
  solo a él, para cuando uno sale antes que los demás.
- La columna la decide el estado de sus platos: todo pendiente → Por hacer; todo listo → Para
  entregar, con **«¡Listo! Canta el 7»**; cualquier mezcla → Cocinando.
- **Un reloj por pedido**, desde su plato más viejo, que se pone ámbar a los 10 minutos y rojo a
  los 20 (`CocinaController::MINUTOS_AVISO` y `MINUTOS_TARDE`). Cuenta en el navegador con la
  hora del servidor como referencia, porque la tablet puede tener la hora corrida.
- Arriba, cuántos pedidos hay en cada columna, y **Pantalla completa**: esconde el menú y la
  cabecera para que en la tablet entren más pedidos. Se recuerda en esa tablet y se aplica antes
  de pintar, sin que asome la barra lateral.

Dos cosas que no son obvias:

- **Muestra también los platos de pedidos ya cobrados.** Manda el estado del plato, no el del
  pedido: el punto de venta cobra al pedir, así que casi todo lo que la cocina tiene por delante
  ya está pagado. Solo quedan fuera los platos de un pedido cancelado, que nadie va a comer, y lo
  que no pasa por la cocina.
- **Muestra solo la jornada en curso** (`Config::jornadaActual()`). Un plato que nadie marcó como
  entregado se quedaba en pantalla para siempre, y los de ayer ya no los va a preparar nadie. La
  jornada cambia a la hora de corte, con el local cerrado.

**Se refresca sola cada 10 segundos** (`CocinaController::SEGUNDOS_REFRESCO`): pregunta a
`/cocina/pendientes`, que devuelve el mismo listado en JSON, y si algo cambió recarga la página.
Es un sondeo con `fetch` y no WebSockets, a propósito: el local tiene una pantalla en la cocina y
nada más, y un servidor de eventos sería una pieza más que mantener en un hosting donde ni los
procedimientos de MySQL se pueden dar por seguros. Y recargar la página entera en vez de
redibujar la lista en el navegador deja una sola versión de la pantalla —la del servidor— en vez
de dos que puedan acabar diciendo cosas distintas.

#### La comanda impresa

La pantalla no alcanza siempre: si la cocina no tiene pantalla o se cae la red, no quedaba nada en
papel. La comanda (`App\Services\Comandas`, `ComandaController`, vista
`resources/views/comandas/imprimir.blade.php`) es la hoja para la cocina, en ticket de 80 mm o en
A4: el número del pedido, comer aquí o para llevar, la hora y cada plato con su nota. **Sin
precios**, que a la cocina no le importan, y **sin lo que no pasa por la cocina**. Se imprime
desde la ficha de la venta, justo después de cobrar, y desde la tarjeta de cada pedido en
`/cocina`; queda en la bitácora como `COMANDA_IMPRESA`, con quién la pidió.

Cada línea recuerda cuándo salió en papel (`pedido_detalle.comandado_en`), así que imprimir de
nuevo trae solo lo que falta: como los platos entran todos de una vez por el punto de venta, lo
normal es **una comanda por pedido**. Si se perdió el papel, **«Reimprimir comanda»** trae la
completa, marcada como reimpresión, y no marca nada.

El caso que obliga a llevar la cuenta: un plato que ya estaba en papel en la cocina y **se
cancela después** —un plato del pedido que se vuelve a cobrar, o el pedido entero—. Si nadie se lo dice en
papel, el cocinero lo prepara igual. Por eso sale en la comanda siguiente como «CANCELADO: 1 ×
…», una sola vez (`pedido_detalle.cancelacion_comandada_en`), y el punto de venta ofrece
imprimir ese aviso en cuanto se cancela.

Imprimir y reimprimir son `POST`, porque marcan y dejan rastro; la hoja en sí es un `GET` aparte
que no toca nada, para que recargarla o volver a abrirla no mande otra vez lo mismo a la cocina ni
ensucie la bitácora.

### Corregir una venta: solo se anula

No hay devoluciones. Lo que se sirvió no vuelve a la carta, así que **la única forma de corregir
una venta cobrada es anularla entera** (`ventas.anular`), y de ahí volver a cobrar bien si hace
falta. Anular deja la venta en `ANULADA` y su comprobante anulado con el correlativo intacto;
nada se borra. Si la venta cobró un pedido, el pedido pasa a **volver a cobrar** con su mismo
número, y se cobra de nuevo desde el punto de venta (ver
[el camino de corrección](#volver-a-cobrar-el-camino-de-corrección)). Lo que se
cobró por QR, tarjeta o transferencia no sale del cajón: la ficha de la venta avisa que eso se
devuelve por el banco.

Anular exige además que **el turno de caja de esa venta siga abierto**. El dinero ya se contó en
el arqueo de un turno cerrado, y cambiarlo después dejaría un cierre firmado diciendo una cifra
que la base ya no respalda. Cerrado el turno, **no hay corrección desde el sistema**: se explica
en la diferencia del arqueo o se resuelve fuera. El servicio bloquea el turno mientras anula,
para que una anulación y el cierre de esa misma caja no puedan cruzarse.

### La venta: qué hace la aplicación y qué hace la base

Este módulo delega a propósito en el esquema. `App\Services\Ventas` abre una transacción y
orquesta; el resto ya vive en `docs/sql`:

| Paso | Quién lo hace |
|------|---------------|
| Copiar el régimen y la tasa de impuesto a cada línea | trigger `trg_venta_detalle_before_insert` |
| Exigir un turno de caja abierto | trigger `trg_ventas_before_insert` |
| Calcular subtotal e impuesto desde el detalle | `sp_recalcular_venta` |
| Tomar el correlativo con bloqueo de fila | `sp_siguiente_comprobante` |
| Emitir el documento y congelar los datos del cliente | `sp_emitir_comprobante` |
| Comprobar que el tipo de documento corresponde al cliente y que lo pagado cuadra | trigger `trg_comprobantes_before_insert` |
| Anular la venta y su documento | `sp_anular_venta` |
| Sustituir el documento por otro (recibo → factura) | `sp_sustituir_comprobante` |
| Calcular el efectivo esperado al cerrar caja | `sp_cerrar_caja` |
| Impedir que se borre una venta | trigger `trg_ventas_before_delete` |

Reescribir eso en PHP habría dado dos fuentes de verdad que se contradicen con el tiempo.

La excepción es un hosting que no deja crear procedimientos ni triggers. Para ese caso,
`LOGICA_EN_PHP=true` (ver `config/ventas.php`) hace que `App\Services\ReglasEnPhp` ejecute esas
mismas reglas, paso por paso y en el mismo orden, dentro de la misma transacción. Las dos vías
corren la misma batería de pruebas, justamente para que no se separen. Los triggers que solo
**impiden** algo que la aplicación nunca hace —borrar una venta, borrar un pedido— no tienen
espejo: con la lógica en PHP esa guarda simplemente no existe.

Un detalle del orden de operaciones: el descuento de cabecera **no** se puede guardar al crear la
venta, porque la base exige `descuento <= subtotal` y el subtotal todavía es cero. Por eso la
secuencia es insertar la venta sin descuento → insertar el detalle → recalcular → aplicar el
descuento → recalcular otra vez (el impuesto baja en la misma proporción).

### Comprobantes

El tipo de documento lo decide **la serie**, y cada tipo tiene la suya por omisión en
`tipos_comprobante.serie_por_omision_id` (se elige en **Sistema → Configuración**):
persona jurídica → factura; el resto → recibo; y sin facturación a la vista, la empresa recibe
nota de venta (`Ventas::seriePara`). Una FK compuesta contra `(id, tipo_comprobante_id)` impide
que la serie por omisión de las facturas sea una de recibos. Por eso `comprobantes` no guarda
`tipo_comprobante_id`: sería una segunda fuente de verdad.

Cada documento congela una foto del cliente, del negocio (`emisor_nombre`, `emisor_documento`,
`emisor_direccion`, `emisor_telefono`) y de los importes al emitirlo: reimprimir un comprobante
viejo lo saca con el nombre y el NIT del día en que se emitió, no con los de hoy. Nada se borra: un
documento se anula (conservando su correlativo) o se sustituye. Se imprime en ticket de 80 mm o
en A4 desde la misma vista, sin la barra lateral, y los anulados y sustituidos salen con su sello.

El ticket es con lo que el cliente reclama su plato: quien lo lleva canta el número y el cliente
lo compara con su papel. Por eso, bajo la fecha, va un recuadro con **el número del pedido como lo
más grande del papel** —más que el total y que el número de documento—: `PEDIDO`, `#7` y debajo
`COMER AQUÍ` o `PARA LLEVAR`, más el nombre para llamarlo si se dio uno. Es el número de la
jornada, el que se canta; el `id` del pedido queda en un atributo del HTML, que no lee nadie, por
si hay que rastrearlo. Toda venta del punto de venta trae su pedido; solo las ventas viejas, de
antes de los pedidos, no imprimen el recuadro (ni dejan un hueco). El listado de ventas tiene una
columna **Pedido** con el mismo destino, y ahí el número va con la fecha de su jornada: el listado
mezcla días, y «pedido 7» a secas sería el de hoy y el de la semana pasada a la vez.

**Sustitución (HU-42).** El caso típico: se entregó un recibo y el cliente vuelve pidiendo
factura. La venta no se toca —el dinero ya se cobró—: solo cambia el documento.
`sp_sustituir_comprobante` marca el anterior como `SUSTITUIDO`, lo que libera el índice de
«documento vigente», y emite el reemplazo con su propio correlativo, enlazado por `sustituye_a` y
con el motivo en `motivo_emision`. La cadena queda a la vista en la ficha de la venta.

Las condiciones las pone la base y la aplicación las repite para poder ocultar el botón antes de
intentarlo: el documento debe estar vigente, la venta `COMPLETADA` (no anulada) y dentro del plazo
de `configuracion.dias_max_sustitucion`. Ese plazo se cuenta en **días de calendario**, con el
mismo criterio que el `DATEDIFF` del procedimiento, no en períodos de 24 horas.

El tipo del reemplazo lo decide el cliente que se le asigne, igual que en la venta: persona
jurídica → factura, el resto → recibo. Funciona en ambos sentidos, porque una factura emitida por
error también hay que poder corregirla. Sustituir es corregir un documento ya entregado, así que
pide el mismo permiso que anular una venta (`ventas.anular`).

### Caja

Una venta necesita un turno abierto: es donde se imputa el dinero. La base impide con un índice
único que una caja tenga dos turnos abiertos a la vez. Al cerrar:

```
esperado = monto_inicial + cobrado en efectivo + ingresos − egresos
```

Solo cuentan los métodos de pago con `afecta_caja = 1`: la tarjeta y el QR no dejan dinero en el
cajón. Las ventas anuladas no suman, y por eso no hay nada más que restar: una venta mal cobrada
se anula mientras el turno sigue abierto y su efectivo deja de contarse solo. La diferencia es
una columna generada, así que no puede desincronizarse; no se corrige, se explica.

Hasta el sistema de ventas la fórmula restaba también las devoluciones. Los arqueos cerrados
entonces **no se recalculan**: `sesiones_caja` guarda su `monto_esperado` como número, la foto
del día en que se firmó.

**Cerrar con pedidos para volver a cobrar pide confirmación.** Si quedó alguno —el de una
venta anulada que todavía no se volvió a cobrar ni se canceló—, el cierre los lista con su total y
no se firma hasta que quien cierra marca que cierra igual. No se prohíbe, porque el turno siguiente
puede cobrarlos; lo que se evita es cerrar con platos servidos y sin cobrar sin que nadie se
entere. Se listan todos, no solo los de ese turno: un pedido no pertenece a una caja hasta que se
cobra. Y se revisa al firmar, no solo al abrir la pantalla, porque una venta puede anularse
mientras se cuenta el cajón.

### Descuentos

`configuracion.descuento_max_cajero` fija el umbral (10%). Por encima hace falta el permiso
`ventas.descuento`, y el punto de venta lo avisa antes de dejar cobrar. Todo descuento queda en la
venta con el nombre de quien la registró (objetivo O4).

### La portada

`/inicio` está abierta a todos, pero **cada bloque se arma solo si el rol puede verlo**, y el
controlador ni siquiera consulta lo que no se va a mostrar:

| Bloque | Quién lo ve |
|--------|-------------|
| Mi turno de caja | quien puede vender o abrir caja |
| Lo que llevo vendido hoy | quien puede vender — es su trabajo, no información de gestión |
| Vendido hoy frente a ayer, gráfico de dos semanas, últimas ventas | `reportes.ver` |

Un cajero abre la aplicación y cae en el punto de venta, no aquí: es donde pasa el turno, toma el
pedido y lo cobra, y un clic de más en cada venta se nota. La cocina cae en su pantalla, por el
mismo motivo. La portada les queda en el menú para consultar cómo va el día.

La comparación con ayer se omite cuando ayer no hubo ventas, en vez de mostrar un porcentaje
inventado: no se puede dividir por cero.

### Reportes

Dos pantallas, ambas con el mismo filtro de período (por defecto, los últimos 30 días), y las dos
se descargan en Excel y PDF con el rango que se esté viendo:

- **Ventas:** vendido, ticket promedio e impuesto; evolución diaria; desglose por forma de pago
  y por cajero; detalle día a día.
- **Más vendidos:** el ranking de platos del período, por unidades e importe.

No hay margen ni ganancia —no hay costo del que sacarlos—, ni valor de inventario ni alertas de
reposición.

Todo va **por jornada**, no por día de calendario: el local cierra pasada la medianoche, y lo
vendido a la 01:30 es de la noche anterior, con el mismo corte que numera los pedidos
(`configuracion.hora_corte_jornada`). El período del reporte elige jornadas.

Los agregados salen de las vistas que ya define `docs/sql` —`v_ventas_por_dia`, que también
agrupa por jornada, y `v_ventas_por_metodo_pago`—, que son la definición oficial de cada cifra.

Con una salvedad, anotada en el código: **el ranking de más vendidos se repite en PHP.**
`v_productos_mas_vendidos` agrega todo el histórico y no admite rango de fechas, así que el
reporte reproduce sus mismas fórmulas con un filtro. Para que no se separen con el tiempo, una
prueba compara ambos columna por columna: si alguien cambia la vista, la prueba falla.

El importe del ranking va **neto del descuento de la venta**, repartido entre sus líneas en
proporción a su importe, el mismo criterio con el que `sp_recalcular_venta` prorratea el
impuesto; así el descuento se reparte solo y no hay que repetir su fórmula.

La serie por jornada **rellena con cero las jornadas sin ventas**: si no, el gráfico uniría dos
jornadas lejanas con una recta y aparentaría ventas que no existieron.

Los gráficos son ApexCharts y se cargan bajo demanda: `resources/js/graficos.js` solo importa la
librería si la página trae algún `[data-apexchart]`. La plantilla pone ahí los datos en JSON
—nunca funciones, que no sobreviven a `json_encode`— y el módulo arma las opciones, incluidos los
formateadores de moneda y el tema. Un `MutationObserver` redibuja los gráficos cuando se cambia
entre claro y oscuro.

### Auditoría

`App\Services\Auditor` escribe en la tabla `auditoria` los ingresos, los intentos fallidos y toda
alta, baja o modificación de empleados, cuentas, cargos y roles. Los pedidos dejan su propio
rastro: abrirlo, cada cambio de estado en la cocina, cobrarlo, reabrirlo al anular su venta,
cancelarlo e imprimir su comanda (`PEDIDO_ABIERTO`, `PEDIDO_LINEA_ESTADO`, `PEDIDO_COBRADO`,
`PEDIDO_REABIERTO`, `PEDIDO_CANCELADO`, `COMANDA_IMPRESA`). Los platos que carga el punto de venta
no dejan una fila por plato: quedan en la venta y en el pedido cobrado, y una fila por plato solo
tapaba las que importan. Nada se borra en silencio: cuando un registro tiene
historia asociada (un cargo con empleados, una cuenta con operaciones), el sistema lo
**desactiva** en lugar de eliminarlo.

### Se adapta a la pantalla

Probado de 375&nbsp;px (teléfono) a 1440&nbsp;px, sin desbordes horizontales de página en ninguna
pantalla. Lo que cambia según el tamaño:

- **Barra lateral:** completa en escritorio, plegable a iconos, y fuera de pantalla con menú
  hamburguesa por debajo de 1280&nbsp;px. Viene de la plantilla.
- **Tablas:** van dentro de un contenedor con desplazamiento propio, así que nunca desbordan la
  página. Además, en las más anchas (ventas y menú) las columnas secundarias se ocultan por
  tramos —`sm`, `md`, `lg`— y quedan las que identifican la fila y su importe; el resto está a un
  toque, en el detalle.
- **Punto de venta:** en escritorio el carrito acompaña el desplazamiento a la derecha. En el teléfono
  queda debajo de la cuadrícula, así que aparece una **barra flotante** con el número de artículos
  y el total, que lleva al carrito de un toque. Sin ella, el cajero tendría que recorrer todo el
  menú para ver cuánto lleva.
- **Modales:** todos con `max-h-[90vh]` y desplazamiento interno. Sin eso, en una pantalla baja
  —un teléfono en horizontal— la parte de arriba de un formulario largo queda inalcanzable, porque
  el centrado con flex recorta hacia arriba.
- **Fotos de los platos:** ver [más arriba](#fotos-de-los-platos); entran enteras sea cual sea su
  proporción.

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
  de un dato, se guarda la clase entera en el arreglo o en la condición (ver `<x-ui.estado>`, o
  los estados de cocina en `cocina/index`).
- **Ojo con los alias de un `selectRaw` sobre un modelo.** Si el alias coincide con el nombre de
  un accesor (`precio_estante` en `Producto`, por ejemplo), Eloquent devuelve lo que calcula el
  accesor sobre una fila vacía en vez de la suma. Para agregados conviene el query builder
  (`DB::table(...)`).

---

## Pruebas

Las pruebas corren contra una copia real de la base, porque el esquema usa columnas generadas,
`ENUM` y triggers que SQLite no reproduce. Esa copia es la base `ventas_db_test` del MySQL del
stack de restaurante (`restaurante_mysql`, `127.0.0.1:13311`), como fija `phpunit.xml`. Se crea
una sola vez, con el stack levantado y desde la raíz del repositorio:

```bash
sed 's/ventas_db/ventas_db_test/g' docs/sql/01_schema_mysql.sql | docker exec -i restaurante_mysql mysql --default-character-set=utf8mb4 -uroot -pventas123
```

```bash
sed 's/ventas_db/ventas_db_test/g' docs/sql/02_datos_iniciales.sql | docker exec -i restaurante_mysql mysql --default-character-set=utf8mb4 -uroot -pventas123
```

Después, desde esta carpeta y con el PHP de la máquina (no el del contenedor, que no alcanza el
13311):

```bash
php -d memory_limit=2G vendor/bin/phpunit
```

Con `php artisan test` la batería se queda sin memoria en la prueba que genera el Excel del
reporte: el límite por omisión no alcanza para la hoja más grande. Hoy son 578 pruebas. Con
`LOGICA_EN_PHP=true` pasan 574 y se saltan 4: las que comprueban algo que solo existe en la base
—sus procedimientos, triggers o vistas— y que en esa vía no hay contra qué probar.

`phpunit.xml` fija la conexión con `force="true"` a propósito: el contenedor de la aplicación ya
trae `DB_HOST` y `DB_DATABASE` de la base de desarrollo como variables de entorno reales, y sin
`force` las de las pruebas perdían en silencio y la batería terminaba escribiendo en `ventas_db`.

Cada prueba corre dentro de una transacción que se revierte al terminar, así que los catálogos
quedan intactos. Lo que no vuelve atrás es el `AUTO_INCREMENT`: cada corrida gasta números aunque
no deje filas. Por eso ningún identificador es `TINYINT` (con 255 como tope, `cajas` se agotó
sola en la base de pruebas), y `IdentificadoresTest` lo vigila.

Una base de pruebas creada antes de un parche nuevo se pone al día igual que cualquier otra,
desde la raíz del repositorio:

```bash
BASE=ventas_db_test ./scripts/aplicar-parches.sh --aplicar
```

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

   Ojo con esto: ni `docker compose up` a secas (`docker-compose.yml`) ni
   `docker-compose.restaurante.yml` sirven aquí. Los dos son entornos de **desarrollo**: montan el
   código por bind mount, corren como root, dejan la base escuchando en un puerto de la máquina y
   muestran los errores de PHP en pantalla. Sirven para trabajar en el proyecto, no para el
   servidor de un negocio. La versión de producción (`docker-compose.prod.yml` +
   `Dockerfile.prod`) hornea el código en la imagen, corre como usuario sin privilegios y no
   publica la base hacia fuera.

5. **Primer acceso.** La base de producción se crea con `docs/sql/produccion/02_datos_base.sql`:
   sin platos, clientes ni empleados de ejemplo —solo las cuatro categorías de partida del menú,
   con Bebidas marcada como que no pasa por la cocina—, y con una sola cuenta, `admin`. Su
   contraseña es aleatoria y queda en un archivo dentro del contenedor:

   ```bash
   docker compose -f docker-compose.prod.yml exec app cat storage/app/respaldos/PRIMER-ACCESO.txt
   ```

   La contraseña **no** sale en el log del contenedor a propósito: ahí quedaría guardada para
   siempre. Borra el archivo en cuanto entres:

   ```bash
   docker compose -f docker-compose.prod.yml exec app rm storage/app/respaldos/PRIMER-ACCESO.txt
   ```

   Al entrar, el sistema obliga a cambiarla antes de hacer cualquier otra cosa.

6. **Datos del negocio y las cuentas reales.** En **Sistema → Configuración**: nombre, NIT,
   dirección, teléfono, moneda y topes del cajero; en su tarjeta «Número del pedido», la hora a la
   que empieza la jornada (5 de fábrica: tiene que caer con el local cerrado). En **Impuesto y
   precios**: si el negocio cobra IVA, la tasa y si los precios ya lo incluyen (una instalación
   nueva arranca sin IVA y con los precios como los ve el cliente; al activarlo con el precio
   incluido, lo que paga el cliente no cambia). Al cambiar de modo, el sistema ofrece ajustar los
   precios del menú para que el cliente siga pagando lo mismo. En **Menú**: las categorías —con
   cuáles pasan por la cocina— y los platos. En **Administración → Empleados** y **Seguridad →
   Usuarios**: el personal y sus cuentas, con el rol que corresponda (Cajero o Cocina). Cada
   cuenta nueva, y cada contraseña que restablece un administrador, se cambia al primer ingreso.

7. **Backup programado.** Sin esto, un disco dañado se lleva el negocio entero — ver
   [Copias de seguridad](#copias-de-seguridad) más abajo. No lo dejes para después.

8. **Adminer no existe en producción.** `docker-compose.prod.yml` directamente no lo declara: un
   cliente de base de datos sin autenticación propia no tiene por qué estar instalado en el
   servidor de un negocio. (En desarrollo sigue disponible con `--profile tools`.)

9. **Cobro por QR**, si el negocio lo va a usar. De fábrica queda en `QR_PASARELA=simulado`:
   el punto de venta genera un QR **real y escaneable, con el importe ya puesto**, pero sin banco
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
      saltándose el HTTPS. En un local sin proxy, donde las cajas y la cocina entran por la red,
      se deja 8100.

    Después de cambiar `.env.docker` o `.env`: `docker compose -f docker-compose.prod.yml up -d`
    (recrea los contenedores con los valores nuevos; `restart` no los vuelve a leer).

---

## Copias de seguridad

**En producción ya hay un respaldo automático**: el contenedor `programador` corre
`respaldo:crear` todas las noches a la 01:00, lo guarda en el volumen `ventas_respaldos` (14
días) y lo sube a **Google Drive**.

**No tiene pantalla, a propósito.** Un respaldo es la base entera —clientes, ventas y los hashes
de las contraseñas—, y restaurarlo es trabajo de quien mantiene el sistema, no del local: nadie,
ni el administrador, lo ve ni lo descarga desde el navegador. Corre por debajo, y lo que pasa
queda en la bitácora (`RESPALDO_CREADO`, o `RESPALDO_NUBE_FALLIDA` con el motivo).

### Google Drive

Cada respaldo se sube con [rclone](https://rclone.org) a la carpeta de Drive del restaurante,
uno por uno y por su nombre, nunca la carpeta entera (en ella también vive `PRIMER-ACCESO.txt`,
con la contraseña inicial del administrador). Allá se borran solos los de más de 14 días, y solo
si el nuevo subió: sin internet no se tira nada. Si la subida falla, el respaldo queda en el
servidor, el comando termina con error y queda en la bitácora; se reintenta la noche siguiente.

Se conecta **una vez por servidor**:

1. En una computadora con navegador (puede no ser el servidor), instala rclone
   (`winget install Rclone.Rclone` en Windows) y corre `rclone authorize "drive"`. Entra con la
   cuenta de Google dueña de la carpeta (o con permiso de editor en ella) y acepta. Copia la
   línea que imprime, la que empieza con `{"access_token"`. Ese texto da acceso a ese Drive: no
   se manda por chat ni por correo.
2. En el servidor, con la aplicación levantada:

   ```bash
   bash scripts/conectar-drive.sh
   ```

   Pide la línea (no se ve al pegarla), deja el remoto `drive` apuntando a la carpeta —ya trae
   su id; para otra: `CARPETA=<enlace o id>`— y prueba subir, leer y borrar un archivo.
3. En `sistema-ventas/.env.docker`, `RESPALDOS_NUBE=drive:`, y `up -d` para que lo lean la
   aplicación y el programador. Para probar sin esperar a la noche:

   ```bash
   docker exec -u www-data ventas_app_prod php artisan respaldo:crear
   ```

La conexión vive en el volumen `ventas_rclone` (`storage/app/rclone`), no en la imagen:
reconstruirla no desconecta Drive. Google retira el permiso si se cambia la contraseña de la
cuenta, si se revoca el acceso o tras seis meses sin usarse; entonces la bitácora empieza a
mostrar `RESPALDO_NUBE_FALLIDA` y basta con repetir los pasos 1 y 2. La carpeta tiene que quedar
**restringida** en Drive (no «cualquier persona con el enlace»).

Para que además quede una copia **fuera del servidor**, monta un disco externo o una carpeta sincronizada:

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
- `ventas_fotos_<fecha>.tar.gz` — las fotos de los platos. Van aparte porque no viven en la base:
  la base solo guarda el nombre del archivo. Respaldar únicamente el SQL deja un menú entero
  sin imágenes el día que haya que recuperar.

Borra solas las copias de más de 14 días (`RETENTION_DAYS` para cambiarlo). Encuentra solo los
contenedores —el de producción, el del stack de restaurante o el del sistema de ventas anterior,
en ese orden y prefiriendo el que esté corriendo—, igual que `restore-db.sh`; `VENTAS_MYSQL` y
`VENTAS_APP` quedan para un contenedor con otro nombre.

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

`scripts/revisar-salud.sh` revisa las cuatro cosas que dejan al negocio sin cobrar —o sin red
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
`URL_SALUD` (`http://localhost:8100/up`, el puerto de producción).

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
    PosController.php            el punto de venta: búsqueda, carrito, pedido y cobro
    PedidoController.php         volver a cobrar el pedido de una venta anulada, o cancelarlo
    CocinaController.php         el tablero de la cocina, su sondeo en JSON y el botón de cada pedido
    ComandaController.php        la comanda impresa: imprimir, reimprimir y la hoja
    ProductoController.php       el menú: platos, precio y foto (URL /menu)
    CategoriaController.php      categorías del menú y si pasan por la cocina
    VentaController.php          listado, ficha y anulación
    CajaController.php           apertura, movimientos y cierre del turno
    CajaFisicaController.php     los puestos de cobro del local
    ComprobanteController.php    listado, impresión (ticket 80 mm / A4) y sustitución
    ClienteController.php        persona natural y persona jurídica
    ReporteController.php        reportes de ventas y más vendidos, en pantalla, Excel y PDF
    DashboardController.php      la portada, por bloques según el rol
    CobroQrController.php        cobro por QR: generar, consultar, confirmar y el aviso del banco
    BitacoraController.php       la bitácora, solo lectura
    ConfiguracionController.php  datos del negocio, impuesto y precios, series
  Http/Middleware/
    VerificarPermiso.php         corta por permiso de rol
    VerificarCuentaVigente.php   corta si la cuenta dejó de tener acceso
  Models/                        Cargo, Empleado, Rol, Permiso, Usuario, Auditoria,
                                 Categoria, Producto, Cliente, Caja, SesionCaja,
                                 MovimientoCaja, Venta, VentaDetalle, VentaPago,
                                 Comprobante, SerieComprobante, TipoComprobante,
                                 MetodoPago, CobroQr, Pedido, PedidoDetalle
  Services/
    Auditor.php                  bitácora
    Pedidos.php                  vender en el punto de venta, cocina, cobrar, reabrir, cancelar
    Comandas.php                 qué falta mandar a la cocina en papel, y marcarlo
    Ventas.php                   registrar y anular, sobre los procedimientos
    ReglasEnPhp.php              los procedimientos y triggers, en PHP (LOGICA_EN_PHP=true)
    Cajas.php                    abrir, mover efectivo y cerrar el turno
    Comprobantes.php             sustituir el documento de una venta
    CobrosQr.php                 el cobro por QR, de generarlo a atarlo a su venta
    Qr/PasarelaQr.php            lo que tiene que saber hacer un banco
    Qr/QrSimulado.php            QR real sin banco detrás: lo confirma el cajero
    Qr/QrBaneco.php              Banco Económico (BEC QR Connect)
    Qr/QrBanco.php               la plantilla a completar cuando haya convenio
  Support/
    Menu.php                     barra lateral y pantalla de inicio según permisos
    Config.php                   parámetros del negocio (tasa, moneda, formatos, jornada)

resources/views/
  layouts/                       app (con barra lateral) y auth (pantalla completa)
  errors/                        404/403/419/500/503 — layout auth, sin sesión ni datos
  components/                    ui.*, form.*, common.*, header.*, tabla.*
  auth/ empleados/ cargos/ usuarios/ roles/ perfil/
  pos/                           el punto de venta, con «Volver a cobrar»
  pedidos/                       volver a cobrar o cancelar el pedido de una venta anulada
  cocina/ comandas/              la pantalla de la cocina y la comanda impresa
  productos/ categorias/         el menú
  dashboard.blade.php            la portada
  ventas/ caja/ cajas/ comprobantes/ clientes/ reportes/
  bitacora/ configuracion/
```

## Lo que sigue

La facturación electrónica ante la administración tributaria está fuera de alcance en la versión 1, como dice
`docs/01-problematica.md`: el modelo queda preparado, pero no se integra el servicio del
organismo.
