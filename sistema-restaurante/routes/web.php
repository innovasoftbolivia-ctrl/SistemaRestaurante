<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BitacoraController;
use App\Http\Controllers\CajaController;
use App\Http\Controllers\CajaFisicaController;
use App\Http\Controllers\CargoController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CobroQrController;
use App\Http\Controllers\CocinaController;
use App\Http\Controllers\ComandaController;
use App\Http\Controllers\CompraController;
use App\Http\Controllers\ComprobanteController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DevolucionCompraController;
use App\Http\Controllers\EmpleadoController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\NotificacionController;
use App\Http\Controllers\PedidoController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\TomaInventarioController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\VentaController;
use App\Support\Menu;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Acceso
|------------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    // `throttle` por dirección de origen, además del freno por cuenta que hace
    // LoginController: sin esto, probar UNA contraseña contra las cuarenta
    // cuentas del negocio no gastaba el cupo de ninguna.
    Route::post('login', [LoginController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('login.store');
});

Route::post('logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
|------------------------------------------------------------------------------
| Módulo de personal, seguridad y usuarios
|------------------------------------------------------------------------------
| `cuenta.vigente` corta la sesión si la cuenta dejó de tener acceso mientras
| el usuario seguía navegando (por ejemplo, si se cesa al empleado).
*/

// `auth.session`: si la contraseña cambia, las demás sesiones abiertas de esa
// cuenta se cierran en su próxima petición. Una contraseña filtrada se cambia
// para echar a quien la tiene, no para que siga dentro dos horas más.
Route::middleware(['auth', 'auth.session', 'cuenta.vigente', 'password.propia'])->group(function () {
    // La raíz manda a cada quien a su pantalla de trabajo: el cajero al
    // mostrador, la cocina a su pantalla, el resto a la portada.
    Route::get('/', fn () => redirect(Menu::inicio()))->name('raiz');

    Route::get('inicio', DashboardController::class)->name('inicio');

    // La campana de la cabecera, para refrescarla sin recargar la página.
    Route::get('notificaciones', NotificacionController::class)
        ->middleware('throttle:30,1')->name('notificaciones');

    Route::get('perfil', [PerfilController::class, 'edit'])->name('perfil.edit');
    Route::put('perfil/password', [PerfilController::class, 'actualizarPassword'])->name('perfil.password');

    // ---- Empleados y cargos ----
    Route::middleware('permiso:empleados.gestionar')->group(function () {
        Route::get('empleados', [EmpleadoController::class, 'index'])->name('empleados.index');
        Route::get('empleados/nuevo', [EmpleadoController::class, 'create'])->name('empleados.create');
        Route::post('empleados', [EmpleadoController::class, 'store'])->name('empleados.store');
        Route::get('empleados/{empleado}', [EmpleadoController::class, 'show'])->name('empleados.show');
        Route::get('empleados/{empleado}/editar', [EmpleadoController::class, 'edit'])->name('empleados.edit');
        Route::put('empleados/{empleado}', [EmpleadoController::class, 'update'])->name('empleados.update');
        Route::delete('empleados/{empleado}', [EmpleadoController::class, 'destroy'])->middleware('permiso:registros.eliminar')->name('empleados.destroy');
        Route::post('empleados/{empleado}/reactivar', [EmpleadoController::class, 'reactivar'])->name('empleados.reactivar');

        Route::get('cargos', [CargoController::class, 'index'])->name('cargos.index');
        Route::post('cargos', [CargoController::class, 'store'])->name('cargos.store');
        Route::put('cargos/{cargo}', [CargoController::class, 'update'])->name('cargos.update');
        Route::delete('cargos/{cargo}', [CargoController::class, 'destroy'])->middleware('permiso:registros.eliminar')->name('cargos.destroy');
    });

    // ---- Punto de venta ----
    // Registrar la venta y consultarla son permisos distintos: el cajero vende,
    // pero el listado general de ventas es información de gestión.
    Route::middleware('permiso:ventas.registrar')->group(function () {
        Route::get('pos', [PosController::class, 'index'])->name('pos.index');
        Route::get('pos/productos', [PosController::class, 'buscar'])
            ->middleware('throttle:60,1')
            ->name('pos.productos');
        Route::get('pos/precios', [PosController::class, 'precios'])
            ->middleware('throttle:60,1')
            ->name('pos.precios');
        Route::post('pos', [PosController::class, 'store'])->middleware('un.envio')->name('pos.store');

        // ---- Cobro por QR ----
        // El QR se pide con la cuenta armada y ANTES de que exista la venta:
        // si el cliente no llega a pagar, no queda una venta ni un comprobante
        // de algo que nadie pagó. Todo responde JSON porque el cajero espera
        // de pie.
        Route::post('pos/qr', [CobroQrController::class, 'crear'])
            ->middleware('throttle:30,1')->name('qr.crear');
        Route::get('pos/qr/{cobro}', [CobroQrController::class, 'consultar'])
            // El mostrador pregunta cada pocos segundos mientras el cliente
            // escanea: el tope va holgado para que no lo corte a mitad.
            ->middleware('throttle:240,1')->name('qr.consultar');
        Route::post('pos/qr/{cobro}/confirmar', [CobroQrController::class, 'confirmar'])
            ->middleware('throttle:30,1')->name('qr.confirmar');
        Route::post('pos/qr/{cobro}/anular', [CobroQrController::class, 'anular'])
            ->middleware('throttle:30,1')->name('qr.anular');
    });

    /*
     * ---- Volver a cobrar: el camino de corrección ----
     *
     * Los pedidos se toman y se cobran en el mostrador (`pos`), en el mismo
     * acto. Solo queda uno sin cobrar cuando se anula su venta: el cliente ya
     * tiene su ticket y la cocina ya lo prepara, así que el pedido vuelve a
     * quedar abierto con su número y se cobra de nuevo o se cancela desde aquí.
     * Cobrar pide `ventas.registrar` (y turno abierto); cancelar el pedido o
     * uno de sus platos, `pedidos.registrar` —y, si la cocina ya empezó algo,
     * `ventas.anular` (C1, en `Pedidos::cancelar`)—.
     */
    Route::middleware('permiso:pedidos.registrar')->group(function () {
        Route::post('pedidos/{pedido}/lineas/{linea}/cancelar', [PedidoController::class, 'cancelarLinea'])
            ->name('pedidos.lineas.cancelar');
        Route::post('pedidos/{pedido}/cancelar', [PedidoController::class, 'cancelar'])
            ->name('pedidos.cancelar');
    });

    Route::middleware('permiso:ventas.registrar')->group(function () {
        Route::get('pedidos/{pedido}/cobrar', [PedidoController::class, 'cobrarForm'])->name('pedidos.cobrar');
        // `un.envio`: un doble clic en «Cobrar» no puede dejar dos ventas del
        // mismo pedido. El índice único de `ventas.pedido_cobrado_uk` (una sola
        // venta vigente por pedido) lo remata en la base.
        Route::post('pedidos/{pedido}/cobrar', [PedidoController::class, 'cobrar'])
            ->middleware('un.envio')->name('pedidos.cobrar.store');
    });

    // ---- La comanda impresa ----
    // La imprime la caja al cobrar y la cocina desde su pantalla. Sin precios:
    // no hay nada de dinero que proteger, pero sí un rastro de quién la
    // imprimió (ver `App\Services\Comandas`).
    Route::middleware('permiso:ventas.registrar,pedidos.registrar,cocina.ver')->group(function () {
        Route::get('pedidos/{pedido}/comanda', [ComandaController::class, 'ver'])->name('pedidos.comanda');
        Route::post('pedidos/{pedido}/comanda', [ComandaController::class, 'imprimir'])->name('pedidos.comanda.imprimir');
        Route::post('pedidos/{pedido}/comanda/reimprimir', [ComandaController::class, 'reimprimir'])->name('pedidos.comanda.reimprimir');
    });

    // ---- Cocina ----
    // Sin nada de dinero: la cocina solo ve qué preparar y mueve su estado.
    // `cocina.entregar` es para quien lleva los platos desde el mostrador: ve
    // la misma pantalla y entrega lo listo, pero no toca la preparación.
    Route::middleware('permiso:cocina.ver,cocina.entregar')->group(function () {
        Route::get('cocina', [CocinaController::class, 'index'])->name('cocina.index');
        // La pantalla pregunta cada pocos segundos: el tope va holgado.
        Route::get('cocina/pendientes', [CocinaController::class, 'pendientes'])
            ->middleware('throttle:120,1')->name('cocina.pendientes');
        // Un plato suelto: con `cocina.entregar` solo a ENTREGADO (lo decide
        // el controlador, que es quien sabe a qué estado va).
        Route::post('cocina/lineas/{linea}', [CocinaController::class, 'actualizarEstado'])
            ->name('cocina.estado');
        // Quien lleva los platos entrega el pedido entero de un toque.
        Route::post('cocina/pedidos/{pedido}/entregar', [CocinaController::class, 'entregar'])
            ->middleware('un.envio')->name('cocina.entregar');
    });

    // Empezar y marcar listo es de la cocina.
    Route::post('cocina/pedidos/{pedido}/avanzar', [CocinaController::class, 'avanzar'])
        ->middleware(['permiso:cocina.ver', 'un.envio'])->name('cocina.avanzar');

    Route::middleware('permiso:ventas.registrar,reportes.ver')->group(function () {
        Route::get('ventas', [VentaController::class, 'index'])->name('ventas.index');
        Route::get('ventas/{venta}', [VentaController::class, 'show'])->name('ventas.show');

        Route::get('comprobantes', [ComprobanteController::class, 'index'])->name('comprobantes.index');
        Route::get('comprobantes/{comprobante}/imprimir', [ComprobanteController::class, 'imprimir'])
            ->name('comprobantes.imprimir');

        Route::get('clientes', [ClienteController::class, 'index'])->name('clientes.index');
        Route::get('clientes/buscar', [ClienteController::class, 'buscar'])
            ->middleware('throttle:60,1')
            ->name('clientes.buscar');
    });

    // Crear/editar/borrar clientes es una acción de venta (se dan de alta al
    // vuelo en el mostrador), no de reportes: separado del grupo de arriba
    // para que `reportes.ver` por sí solo no alcance
    // para modificar clientes.
    Route::middleware('permiso:ventas.registrar')->group(function () {
        Route::post('clientes', [ClienteController::class, 'store'])->name('clientes.store');
        // Registrar un cliente es del mostrador; corregirlo o borrarlo, del
        // administrador.
        Route::put('clientes/{cliente}', [ClienteController::class, 'update'])
            ->middleware('permiso:clientes.editar')->name('clientes.update');
        Route::delete('clientes/{cliente}', [ClienteController::class, 'destroy'])
            ->middleware('permiso:registros.eliminar')->name('clientes.destroy');
    });

    Route::post('ventas/{venta}/anular', [VentaController::class, 'anular'])
        ->middleware('permiso:ventas.anular')
        ->name('ventas.anular');

    // Sustituir un documento ya emitido es corregir algo entregado al cliente:
    // se pide el mismo permiso que para anular una venta.
    Route::post('comprobantes/{comprobante}/sustituir', [ComprobanteController::class, 'sustituir'])
        ->middleware('permiso:ventas.anular')
        ->name('comprobantes.sustituir');

    // ---- Reportes ----
    Route::middleware('permiso:reportes.ver')->group(function () {
        Route::get('reportes/ventas', [ReporteController::class, 'ventas'])->name('reportes.ventas');
        Route::get('reportes/productos', [ReporteController::class, 'productos'])->name('reportes.productos');

        // Los mismos reportes para llevar, respetando el rango de fechas que se
        // esté viendo: se descarga lo que hay en pantalla.
        Route::get('reportes/ventas/excel', [ReporteController::class, 'ventasExcel'])->name('reportes.ventas.excel');
        Route::get('reportes/productos/excel', [ReporteController::class, 'productosExcel'])->name('reportes.productos.excel');
        Route::get('reportes/ventas/pdf', [ReporteController::class, 'ventasPdf'])->name('reportes.ventas.pdf');
        Route::get('reportes/productos/pdf', [ReporteController::class, 'productosPdf'])->name('reportes.productos.pdf');
    });

    // ---- Caja ----
    Route::middleware('permiso:caja.abrir,caja.cerrar,reportes.ver')->group(function () {
        Route::get('caja', [CajaController::class, 'index'])->name('caja.index');
        Route::get('caja/{sesion}', [CajaController::class, 'show'])->name('caja.show');
        Route::get('caja/{sesion}/imprimir', [CajaController::class, 'imprimir'])->name('caja.imprimir');
    });

    Route::post('caja/abrir', [CajaController::class, 'abrir'])
        ->middleware('permiso:caja.abrir')->name('caja.abrir');

    Route::post('caja/{sesion}/movimiento', [CajaController::class, 'movimiento'])
        // Quien abrió el turno, o quien puede cerrarlo (el administrador
        // registra en el turno del cajero el egreso que supera su tope): la
        // ruta exige lo mismo que el controlador, y no solo `caja.abrir`.
        ->middleware('permiso:caja.abrir,caja.cerrar')->middleware('un.envio')->name('caja.movimiento');

    // `caja.cerrar` cierra cualquier turno; quien solo tiene `caja.abrir`
    // cierra el suyo si el negocio lo encendió en Configuración. El
    // controlador decide cuál es el caso (`Cajas::puedeCerrar`).
    Route::post('caja/{sesion}/cerrar', [CajaController::class, 'cerrar'])
        ->middleware('permiso:caja.abrir,caja.cerrar')->name('caja.cerrar');

    // Las cajas FÍSICAS del local, no los turnos. En plural (`/cajas`) para no
    // chocar con `caja/{sesion}` de arriba, y detrás de `configuracion.editar`
    // porque dar de alta un puesto de cobro es administrar el local, no la
    // operación diaria del cajero.
    Route::middleware('permiso:configuracion.editar')->group(function () {
        Route::get('cajas', [CajaFisicaController::class, 'index'])->name('cajas.index');
        Route::post('cajas', [CajaFisicaController::class, 'store'])->name('cajas.store');
        Route::put('cajas/{caja}', [CajaFisicaController::class, 'update'])->name('cajas.update');
        Route::delete('cajas/{caja}', [CajaFisicaController::class, 'destroy'])->middleware('permiso:registros.eliminar')->name('cajas.destroy');

        // Los datos del negocio y los parámetros del sistema. Hasta que hubo
        // pantalla se cambiaban por SQL.
        Route::get('configuracion', [ConfiguracionController::class, 'edit'])->name('configuracion.edit');
        Route::put('configuracion', [ConfiguracionController::class, 'update'])->name('configuracion.update');
        Route::post('configuracion/deshacer-conversion', [ConfiguracionController::class, 'deshacerConversion'])
            ->name('configuracion.deshacer-conversion');
    });

    // La bitácora, solo para leer. Permiso propio: quién hizo qué es
    // información sensible y no tiene por qué ir con administrar el local.
    Route::get('bitacora', [BitacoraController::class, 'index'])
        ->middleware('permiso:bitacora.ver')
        ->name('bitacora.index');

    // Los respaldos no tienen pantalla: los hace el programador de tareas
    // cada noche y los sube a la nube (ver App\Services\Respaldos). La base
    // entera no sale por el navegador para nadie.

    // ---- El menú: los platos de la carta y sus tablas de apoyo ----
    // La URL dice `menu` porque es lo que el negocio ve; los nombres de ruta,
    // la tabla y el permiso siguen diciendo `productos` para no tocar el
    // esquema ni media aplicación por un cambio de etiqueta.
    // La ficha se lee también desde los reportes, que la enlazan. Las acciones
    // de adentro llevan cada una su permiso.
    Route::get('menu/{producto}', [ProductoController::class, 'show'])
        ->middleware('permiso:productos.gestionar,reportes.ver')
        ->whereNumber('producto')
        ->name('productos.show');

    Route::middleware('permiso:productos.gestionar')->group(function () {
        Route::get('menu', [ProductoController::class, 'index'])->name('productos.index');
        Route::get('menu/nuevo', [ProductoController::class, 'create'])->name('productos.create');
        Route::post('menu', [ProductoController::class, 'store'])->name('productos.store');
        Route::get('menu/{producto}/editar', [ProductoController::class, 'edit'])->name('productos.edit');
        Route::put('menu/{producto}', [ProductoController::class, 'update'])->name('productos.update');
        Route::delete('menu/{producto}', [ProductoController::class, 'destroy'])->middleware('permiso:registros.eliminar')->name('productos.destroy');

        Route::get('categorias', [CategoriaController::class, 'index'])->name('categorias.index');
        Route::post('categorias', [CategoriaController::class, 'store'])->name('categorias.store');
        Route::put('categorias/{categoria}', [CategoriaController::class, 'update'])->name('categorias.update');
        Route::delete('categorias/{categoria}', [CategoriaController::class, 'destroy'])->middleware('permiso:registros.eliminar')->name('categorias.destroy');
    });

    // ---- Inventario: lo que se compra hecho (las bebidas embotelladas) ----
    // Todo con `inventario.gestionar`, que por omisión tiene el Administrador.
    Route::middleware('permiso:inventario.gestionar')->group(function () {
        Route::get('inventario', [InventarioController::class, 'index'])->name('inventario.index');
        Route::get('inventario/{producto}/kardex', [InventarioController::class, 'kardex'])
            ->whereNumber('producto')->name('inventario.kardex');
        Route::post('inventario/{producto}/ajuste', [InventarioController::class, 'ajuste'])
            ->whereNumber('producto')->middleware('un.envio')->name('inventario.ajuste');

        Route::get('proveedores', [ProveedorController::class, 'index'])->name('proveedores.index');
        Route::post('proveedores', [ProveedorController::class, 'store'])->name('proveedores.store');
        Route::put('proveedores/{proveedor}', [ProveedorController::class, 'update'])->name('proveedores.update');
        Route::delete('proveedores/{proveedor}', [ProveedorController::class, 'destroy'])
            ->middleware('permiso:registros.eliminar')->name('proveedores.destroy');

        Route::get('compras', [CompraController::class, 'index'])->name('compras.index');
        Route::get('compras/nueva', [CompraController::class, 'create'])->name('compras.create');
        Route::get('compras/sugerida', [CompraController::class, 'sugerida'])->name('compras.sugerida');
        Route::post('compras', [CompraController::class, 'store'])->middleware('un.envio')->name('compras.store');
        Route::get('compras/{compra}', [CompraController::class, 'show'])->whereNumber('compra')->name('compras.show');

        Route::get('devoluciones-proveedor', [DevolucionCompraController::class, 'index'])->name('devoluciones-compra.index');
        Route::get('compras/{compra}/devolucion', [DevolucionCompraController::class, 'create'])
            ->whereNumber('compra')->name('devoluciones-compra.create');
        Route::post('compras/{compra}/devolucion', [DevolucionCompraController::class, 'store'])
            ->whereNumber('compra')->middleware('un.envio')->name('devoluciones-compra.store');
        Route::get('devoluciones-proveedor/{devolucion}', [DevolucionCompraController::class, 'show'])
            ->whereNumber('devolucion')->name('devoluciones-compra.show');
        Route::post('devoluciones-proveedor/{devolucion}/reposicion', [DevolucionCompraController::class, 'reponer'])
            ->whereNumber('devolucion')->middleware('un.envio')->name('devoluciones-compra.reponer');

        Route::get('toma-inventario', [TomaInventarioController::class, 'index'])->name('tomas.index');
        Route::post('toma-inventario', [TomaInventarioController::class, 'store'])->name('tomas.store');
        Route::get('toma-inventario/{toma}', [TomaInventarioController::class, 'show'])->whereNumber('toma')->name('tomas.show');
        Route::post('toma-inventario/{toma}/conteo', [TomaInventarioController::class, 'contar'])
            ->whereNumber('toma')->name('tomas.contar');
        Route::post('toma-inventario/{toma}/cerrar', [TomaInventarioController::class, 'cerrar'])
            ->whereNumber('toma')->middleware('un.envio')->name('tomas.cerrar');
        Route::post('toma-inventario/{toma}/cancelar', [TomaInventarioController::class, 'cancelar'])
            ->whereNumber('toma')->name('tomas.cancelar');
    });

    // ---- Usuarios y roles ----
    Route::middleware('permiso:usuarios.gestionar')->group(function () {
        Route::get('usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
        Route::get('usuarios/nuevo', [UsuarioController::class, 'create'])->name('usuarios.create');
        Route::post('usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
        Route::get('usuarios/{usuario}/editar', [UsuarioController::class, 'edit'])->name('usuarios.edit');
        Route::put('usuarios/{usuario}', [UsuarioController::class, 'update'])->name('usuarios.update');
        Route::patch('usuarios/{usuario}/acceso', [UsuarioController::class, 'alternarAcceso'])->name('usuarios.acceso');
        Route::delete('usuarios/{usuario}', [UsuarioController::class, 'destroy'])->middleware('permiso:registros.eliminar')->name('usuarios.destroy');

        Route::get('roles', [RolController::class, 'index'])->name('roles.index');
        Route::post('roles', [RolController::class, 'store'])->name('roles.store');
        Route::put('roles/{rol}', [RolController::class, 'update'])->name('roles.update');
        Route::delete('roles/{rol}', [RolController::class, 'destroy'])->middleware('permiso:registros.eliminar')->name('roles.destroy');
    });
});

/*
|------------------------------------------------------------------------------
| Aviso de pago del banco (webhook del QR)
|------------------------------------------------------------------------------
| Fuera de toda sesión y fuera de CSRF, porque quien llama es el banco: no
| inicia sesión ni tiene un token de formulario. Eso la deja como una dirección
| pública, así que la ÚNICA defensa es la firma del aviso, que comprueba la
| pasarela antes de tocar nada (ver `QrBanco::verificarAviso`). Y aunque la
| firma pase, el aviso no marca nada por sí solo: dispara una consulta al banco
| y vale lo que el banco conteste (ver `CobrosQr::procesarAviso`).
|
| La exención de CSRF está en bootstrap/app.php.
*/

Route::post('qr/aviso', [CobroQrController::class, 'aviso'])
    ->middleware('throttle:120,1')
    ->name('qr.aviso');

// La misma entrada en la dirección que Banco Económico espera del comercio.
Route::post('api/qrsimple/notifyPaymentQR', [CobroQrController::class, 'aviso'])
    ->middleware('throttle:120,1')
    ->name('qr.aviso.baneco');
