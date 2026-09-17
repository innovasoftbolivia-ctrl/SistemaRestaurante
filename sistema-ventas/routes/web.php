<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BitacoraController;
use App\Http\Controllers\CajaController;
use App\Http\Controllers\CajaFisicaController;
use App\Http\Controllers\CargoController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CobroQrController;
use App\Http\Controllers\CompraController;
use App\Http\Controllers\ComprobanteController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DevolucionCompraController;
use App\Http\Controllers\DevolucionController;
use App\Http\Controllers\EmpleadoController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\LibroVentasController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\RespaldoController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\TomaInventarioController;
use App\Http\Controllers\UnidadMedidaController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\VencimientoController;
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
    // mostrador, el resto a la portada.
    Route::get('/', fn () => redirect(Menu::inicio()))->name('raiz');

    Route::get('inicio', DashboardController::class)->name('inicio');

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
        Route::post('pos', [PosController::class, 'store'])->name('pos.store');

        // ---- Cobro por QR ----
        // El QR se pide con el carrito armado y ANTES de que exista la venta:
        // si el cliente no llega a pagar, no queda una venta con stock ya
        // descontado. Todo responde JSON porque el cajero espera de pie.
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
    // para que `reportes.ver` por sí solo (p. ej. el Almacenero) no alcance
    // para modificar clientes, igual que ya pasa con devoluciones más abajo.
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

    // ---- Devoluciones ----
    // Consultarlas es información de gestión; registrarlas exige su permiso.
    Route::middleware('permiso:devoluciones.registrar,reportes.ver')->group(function () {
        Route::get('devoluciones', [DevolucionController::class, 'index'])->name('devoluciones.index');
        Route::get('devoluciones/{devolucion}', [DevolucionController::class, 'show'])->name('devoluciones.show');
    });

    Route::middleware('permiso:devoluciones.registrar')->group(function () {
        Route::get('ventas/{venta}/devolver', [DevolucionController::class, 'create'])->name('devoluciones.create');
        Route::post('ventas/{venta}/devolver', [DevolucionController::class, 'store'])->middleware('un.envio')->name('devoluciones.store');
    });

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

        // Libro de Ventas IVA: borrador para el contador (ver App\Services\LibroDeVentas).
        Route::get('reportes/libro-ventas', [LibroVentasController::class, 'index'])->name('reportes.libro-ventas');
        Route::get('reportes/libro-ventas/excel', [LibroVentasController::class, 'excel'])->name('reportes.libro-ventas.excel');
        Route::get('reportes/libro-ventas/pdf', [LibroVentasController::class, 'pdf'])->name('reportes.libro-ventas.pdf');
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
        ->middleware('permiso:caja.abrir')->middleware('un.envio')->name('caja.movimiento');

    Route::post('caja/{sesion}/cerrar', [CajaController::class, 'cerrar'])
        ->middleware('permiso:caja.cerrar')->name('caja.cerrar');

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

    // Respaldos: la base entera, con los hashes de las contraseñas. Permiso
    // propio y nada de nombres de archivo libres (ver Respaldos::ruta()).
    Route::middleware('permiso:respaldos.gestionar')->group(function () {
        Route::get('respaldos', [RespaldoController::class, 'index'])->name('respaldos.index');
        Route::post('respaldos', [RespaldoController::class, 'store'])->name('respaldos.store');
        Route::get('respaldos/{nombre}/descargar', [RespaldoController::class, 'descargar'])
            ->where('nombre', '[^/]+')
            ->name('respaldos.descargar');
    });

    // ---- Catálogo: productos y sus tablas de apoyo ----
    // La ficha con su kardex se lee desde el almacén y los reportes, que la
    // enlazan: la abre cualquiera que trabaje con el inventario. Las acciones
    // de adentro llevan cada una su permiso.
    Route::get('productos/{producto}', [ProductoController::class, 'show'])
        ->middleware('permiso:productos.gestionar,inventario.ingresar,inventario.ajustar,reportes.ver')
        ->whereNumber('producto')
        ->name('productos.show');

    Route::middleware('permiso:productos.gestionar')->group(function () {
        Route::get('productos', [ProductoController::class, 'index'])->name('productos.index');
        Route::get('productos/nuevo', [ProductoController::class, 'create'])->name('productos.create');
        Route::post('productos', [ProductoController::class, 'store'])->name('productos.store');
        Route::get('productos/{producto}/editar', [ProductoController::class, 'edit'])->name('productos.edit');
        Route::put('productos/{producto}', [ProductoController::class, 'update'])->name('productos.update');
        Route::delete('productos/{producto}', [ProductoController::class, 'destroy'])->middleware('permiso:registros.eliminar')->name('productos.destroy');

        Route::get('categorias', [CategoriaController::class, 'index'])->name('categorias.index');
        Route::post('categorias', [CategoriaController::class, 'store'])->name('categorias.store');
        Route::put('categorias/{categoria}', [CategoriaController::class, 'update'])->name('categorias.update');
        Route::delete('categorias/{categoria}', [CategoriaController::class, 'destroy'])->middleware('permiso:registros.eliminar')->name('categorias.destroy');

        Route::get('unidades', [UnidadMedidaController::class, 'index'])->name('unidades.index');
        Route::post('unidades', [UnidadMedidaController::class, 'store'])->name('unidades.store');
        Route::put('unidades/{unidad}', [UnidadMedidaController::class, 'update'])->name('unidades.update');
        Route::delete('unidades/{unidad}', [UnidadMedidaController::class, 'destroy'])->middleware('permiso:registros.eliminar')->name('unidades.destroy');

        Route::get('proveedores', [ProveedorController::class, 'index'])->name('proveedores.index');
        Route::post('proveedores', [ProveedorController::class, 'store'])->name('proveedores.store');
        Route::put('proveedores/{proveedor}', [ProveedorController::class, 'update'])->name('proveedores.update');
        Route::delete('proveedores/{proveedor}', [ProveedorController::class, 'destroy'])->middleware('permiso:registros.eliminar')->name('proveedores.destroy');
    });

    // ---- Movimientos de stock: permisos propios, distintos del catálogo ----
    //
    // Las mismas dos operaciones tienen dos puertas: desde la ficha del
    // producto (cuando ya se está mirando ese producto) y desde el módulo de
    // inventario (cuando se llega con la mercadería en la mano y hay que
    // buscarla). Las dos terminan en `Inventario`, que sigue siendo el único
    // sitio donde cambia el stock.
    Route::post('productos/{producto}/ingreso', [ProductoController::class, 'ingresar'])
        ->middleware('permiso:inventario.ingresar')
        ->middleware('un.envio')->name('productos.ingreso');

    Route::post('productos/{producto}/ajuste', [ProductoController::class, 'ajustar'])
        ->middleware('permiso:inventario.ajustar')
        ->middleware('un.envio')->name('productos.ajuste');

    // ---- Inventario: el almacén como módulo propio ----
    // Se puede mirar con cualquiera de los tres permisos: quien carga, quien
    // ajusta y quien solo consulta los reportes.
    Route::middleware('permiso:inventario.ingresar,inventario.ajustar,reportes.ver')->group(function () {
        Route::get('inventario', [InventarioController::class, 'index'])->name('inventario.index');
        Route::get('inventario/movimientos', [InventarioController::class, 'movimientos'])
            ->name('inventario.movimientos');
    });

    Route::post('inventario/ingreso', [InventarioController::class, 'ingreso'])
        ->middleware('permiso:inventario.ingresar')
        ->middleware('un.envio')->name('inventario.ingreso');

    Route::post('inventario/ajuste', [InventarioController::class, 'ajuste'])
        ->middleware('permiso:inventario.ajustar')
        ->middleware('un.envio')->name('inventario.ajuste');

    // ---- Toma de inventario: contar el local entero ----
    // La mira quien mira el inventario; contar y cerrar es ajustar stock, así
    // que pide el mismo permiso que el ajuste de a un producto.
    Route::middleware('permiso:inventario.ajustar,reportes.ver')->group(function () {
        Route::get('tomas-inventario', [TomaInventarioController::class, 'index'])->name('tomas.index');
        Route::get('tomas-inventario/{toma}', [TomaInventarioController::class, 'show'])->name('tomas.show');
        Route::get('tomas-inventario/{toma}/imprimir', [TomaInventarioController::class, 'imprimir'])->name('tomas.imprimir');
    });

    Route::middleware('permiso:inventario.ajustar')->group(function () {
        Route::post('tomas-inventario', [TomaInventarioController::class, 'store'])->name('tomas.store');
        // `scopeBindings`: la línea tiene que ser de ESA toma. Sin esto, con el
        // id de una línea de una toma cancelada se escribiría en ella.
        Route::post('tomas-inventario/{toma}/lineas/{linea}', [TomaInventarioController::class, 'contar'])
            ->scopeBindings()
            ->name('tomas.contar');
        Route::post('tomas-inventario/{toma}/cerrar', [TomaInventarioController::class, 'cerrar'])->name('tomas.cerrar');
        Route::post('tomas-inventario/{toma}/cancelar', [TomaInventarioController::class, 'cancelar'])->name('tomas.cancelar');
    });

    // ---- Vencimientos: qué caduca y cuándo ----
    // Se mira con los mismos permisos que el inventario: es la misma pregunta
    // sobre el mismo stock, solo que partido por fecha.
    Route::middleware('permiso:inventario.ingresar,inventario.ajustar,reportes.ver')->group(function () {
        Route::get('vencimientos', [VencimientoController::class, 'index'])->name('vencimientos.index');
        Route::get('vencimientos/{producto}', [VencimientoController::class, 'producto'])
            ->name('vencimientos.producto');
    });

    // Dar de baja una tanda vencida es un ajuste de inventario, así que pide el
    // permiso de ajustar y no el de mirar.
    Route::post('vencimientos/{lote}/baja', [VencimientoController::class, 'baja'])
        ->middleware('permiso:inventario.ajustar')
        ->name('vencimientos.baja');

    // ---- Compras: la factura del proveedor, entera ----
    // Sin permiso propio: registrar la compra ES ingresar mercadería, solo que
    // de muchas líneas a la vez. El listado lo abre además quien ve reportes,
    // igual que el inventario.
    Route::middleware('permiso:inventario.ingresar,reportes.ver')->group(function () {
        Route::get('compras', [CompraController::class, 'index'])->name('compras.index');
    });

    Route::middleware('permiso:inventario.ingresar')->group(function () {
        Route::get('compras/nueva', [CompraController::class, 'create'])->name('compras.create');
        Route::get('compras/productos', [CompraController::class, 'buscar'])
            ->middleware('throttle:60,1')
            ->name('compras.productos');
        Route::post('compras', [CompraController::class, 'store'])->middleware('un.envio')->name('compras.store');
    });

    // ---- Devoluciones al proveedor: lo que se va de vuelta ----
    // Se registra desde la compra por la que entró la mercadería: una
    // devolución siempre es «de esta factura», y arrancar eligiendo la factura
    // evita devolver contra el proveedor equivocado. El listado general es para
    // lo otro: mirar cuánto se devolvió y por qué.
    Route::get('devoluciones-compra', [DevolucionCompraController::class, 'index'])
        ->middleware('permiso:inventario.ingresar,reportes.ver')
        ->name('devoluciones-compra.index');

    // Antes del comodín de abajo: `devoluciones-compra/{devolucionCompra}` se
    // tragaría `nueva` y contestaría 404 buscando una devolución con ese id.
    Route::get('devoluciones-compra/nueva', [DevolucionCompraController::class, 'elegirCompra'])
        ->middleware('permiso:inventario.ingresar')
        ->name('devoluciones-compra.elegir');

    Route::get('devoluciones-compra/{devolucionCompra}', [DevolucionCompraController::class, 'show'])
        ->middleware('permiso:inventario.ingresar,reportes.ver')
        ->name('devoluciones-compra.show');

    Route::middleware('permiso:inventario.ingresar')->group(function () {
        Route::get('compras/{compra}/devolucion', [DevolucionCompraController::class, 'create'])
            ->name('devoluciones-compra.create');
        Route::post('compras/{compra}/devolucion', [DevolucionCompraController::class, 'store'])
            ->middleware('un.envio')->name('devoluciones-compra.store');

        // Lo que el proveedor trajo después. Es una entrada de mercadería, así
        // que pide el mismo permiso que cargar una compra.
        Route::post('devoluciones-compra/{devolucionCompra}/reposicion',
            [DevolucionCompraController::class, 'reponer'])
            ->middleware('un.envio')->name('devoluciones-compra.reponer');
    });

    // Va al final del bloque a propósito: `compras/{compra}` es un comodín y,
    // declarado antes, se tragaría `compras/nueva` y `compras/productos`.
    Route::get('compras/{compra}', [CompraController::class, 'show'])
        ->middleware('permiso:inventario.ingresar,reportes.ver')
        ->name('compras.show');

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
