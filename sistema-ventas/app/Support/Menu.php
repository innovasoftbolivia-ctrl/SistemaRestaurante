<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * Arma el menú lateral según los permisos del rol de la cuenta:
 * lo que no se puede usar, no se muestra.
 */
class Menu
{
    /**
     * @return array<int, array{title: string, items: array<int, array<string, mixed>>}>
     */
    public static function grupos(): array
    {
        $grupos = [];

        $mostrador = [
            ['icon' => 'inicio', 'name' => 'Inicio', 'path' => '/inicio'],
        ];

        if (self::puede('ventas.registrar')) {
            $mostrador[] = ['icon' => 'pos', 'name' => 'Punto de venta', 'path' => '/pos'];
        }

        if (self::puedeAlguno('caja.abrir', 'caja.cerrar', 'reportes.ver')) {
            $mostrador[] = ['icon' => 'caja', 'name' => 'Caja', 'path' => '/caja'];
        }

        // Las cajas físicas (dar de alta un segundo puesto de cobro) son
        // administración del local, no la operación diaria: por eso van con
        // `configuracion.editar` y no con `caja.abrir`.
        if (self::puede('configuracion.editar')) {
            $mostrador[] = ['icon' => 'cajas', 'name' => 'Cajas del local', 'path' => '/cajas'];
        }

        $grupos[] = ['title' => 'Mostrador', 'items' => $mostrador];

        if (self::puedeAlguno('ventas.registrar', 'reportes.ver')) {
            $ventas = [
                ['icon' => 'ventas', 'name' => 'Ventas', 'path' => '/ventas'],
                ['icon' => 'comprobantes', 'name' => 'Comprobantes', 'path' => '/comprobantes'],
                ['icon' => 'clientes', 'name' => 'Clientes', 'path' => '/clientes'],
            ];

            if (self::puedeAlguno('devoluciones.registrar', 'reportes.ver')) {
                $ventas[] = ['icon' => 'devoluciones', 'name' => 'Devoluciones', 'path' => '/devoluciones'];
            }

            $grupos[] = ['title' => 'Ventas', 'items' => $ventas];
        }

        // El almacén va aparte del catálogo: dar de alta un producto y cargarle
        // stock son dos trabajos distintos, de dos personas distintas y en dos
        // momentos distintos. Quien recibe la mercadería entra por aquí.
        if (self::puedeAlguno('inventario.ingresar', 'inventario.ajustar', 'reportes.ver')) {
            $grupos[] = [
                'title' => 'Almacén',
                'items' => [
                    ['icon' => 'inventario', 'name' => 'Inventario', 'path' => '/inventario'],
                    // Las compras van dentro del almacén y no en un grupo
                    // propio: es la misma persona, en el mismo momento y con la
                    // mercadería en la mano.
                    ['icon' => 'proveedores', 'name' => 'Compras', 'path' => '/compras'],
                    // Las devoluciones al proveedor van bajo Compras y no con
                    // las del cliente: son la mercadería yéndose por donde
                    // vino, no una venta que se deshace.
                    ['icon' => 'devoluciones', 'name' => 'Devoluciones a proveedor', 'path' => '/devoluciones-compra'],
                    ['icon' => 'categorias', 'name' => 'Vencimientos', 'path' => '/vencimientos'],
                    ['icon' => 'kardex', 'name' => 'Movimientos', 'path' => '/inventario/movimientos'],
                ],
            ];
        }

        if (self::puede('reportes.ver')) {
            $grupos[] = [
                'title' => 'Reportes',
                'items' => [
                    ['icon' => 'reportes', 'name' => 'Ventas', 'path' => '/reportes/ventas'],
                    ['icon' => 'inventario', 'name' => 'Productos e inventario', 'path' => '/reportes/productos'],
                ],
            ];
        }

        if (self::puede('empleados.gestionar')) {
            $grupos[] = [
                'title' => 'Personal',
                'items' => [
                    [
                        'icon' => 'empleados',
                        'name' => 'Empleados',
                        'path' => '/empleados',
                    ],
                    [
                        'icon' => 'cargos',
                        'name' => 'Cargos',
                        'path' => '/cargos',
                    ],
                ],
            ];
        }

        if (self::puede('productos.gestionar')) {
            $grupos[] = [
                'title' => 'Catálogo',
                'items' => [
                    [
                        'icon' => 'productos',
                        'name' => 'Productos',
                        'path' => '/productos',
                    ],
                    [
                        'icon' => 'categorias',
                        'name' => 'Categorías',
                        'path' => '/categorias',
                    ],
                    [
                        'icon' => 'unidades',
                        'name' => 'Unidades de medida',
                        'path' => '/unidades',
                    ],
                    [
                        'icon' => 'proveedores',
                        'name' => 'Proveedores',
                        'path' => '/proveedores',
                    ],
                ],
            ];
        }

        if (self::puede('usuarios.gestionar')) {
            $grupos[] = [
                'title' => 'Seguridad',
                'items' => [
                    [
                        'icon' => 'usuarios',
                        'name' => 'Usuarios',
                        'path' => '/usuarios',
                    ],
                    [
                        'icon' => 'roles',
                        'name' => 'Roles y permisos',
                        'path' => '/roles',
                    ],
                ],
            ];
        }

        // Administrar el sistema en sí, no el negocio del día a día.
        if (self::puede('configuracion.editar')) {
            $grupos[] = [
                'title' => 'Sistema',
                'items' => [
                    ['icon' => 'configuracion', 'name' => 'Configuración', 'path' => '/configuracion'],
                ],
            ];
        }

        $grupos[] = [
            'title' => 'Cuenta',
            'items' => [
                [
                    'icon' => 'perfil',
                    'name' => 'Mi perfil',
                    'path' => '/perfil',
                ],
            ],
        ];

        return $grupos;
    }

    /**
     * A dónde va cada quien al ingresar: su pantalla de trabajo.
     *
     * El cajero cae directo en el mostrador —es donde pasa el turno y un clic
     * de más en cada venta se nota—; quien lleva la gestión, en la portada.
     * La portada está en el menú para todos, de todas formas.
     */
    public static function inicio(): string
    {
        return match (true) {
            self::puede('reportes.ver') => '/inicio',
            self::puede('ventas.registrar') => '/pos',
            self::puede('empleados.gestionar') => '/empleados',
            self::puede('productos.gestionar') => '/productos',
            self::puede('usuarios.gestionar') => '/usuarios',
            default => '/perfil',
        };
    }

    public static function puede(string $codigo): bool
    {
        return Auth::user()?->tienePermiso($codigo) ?? false;
    }

    public static function puedeAlguno(string ...$codigos): bool
    {
        foreach ($codigos as $codigo) {
            if (self::puede($codigo)) {
                return true;
            }
        }

        return false;
    }

    /** Marca activo también dentro de las subrutas (/empleados/nuevo). */
    public static function esActivo(string $path): bool
    {
        $path = trim($path, '/');

        return request()->is($path) || request()->is($path.'/*');
    }

    public static function icono(string $nombre): string
    {
        return self::ICONOS[$nombre] ?? self::ICONOS['perfil'];
    }

    private const ICONOS = [
        'empleados' => '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 11.25C11.0711 11.25 12.75 9.57107 12.75 7.5C12.75 5.42893 11.0711 3.75 9 3.75C6.92893 3.75 5.25 5.42893 5.25 7.5C5.25 9.57107 6.92893 11.25 9 11.25Z" stroke="currentColor" stroke-width="1.5"/><path d="M2.25 19.5C2.25 16.1863 5.27208 13.5 9 13.5C12.7279 13.5 15.75 16.1863 15.75 19.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M16.5 11.25C18.1569 11.25 19.5 9.90685 19.5 8.25C19.5 6.59315 18.1569 5.25 16.5 5.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M18 19.5C18 16.9463 17.0102 14.7533 15.5977 13.8887C17.3556 13.4653 19.0212 13.7681 20.2266 14.6602C21.432 15.5522 21.75 16.9482 21.75 18.375" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',

        'cargos' => '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.75 8.25C3.75 7.42157 4.42157 6.75 5.25 6.75H18.75C19.5784 6.75 20.25 7.42157 20.25 8.25V18C20.25 18.8284 19.5784 19.5 18.75 19.5H5.25C4.42157 19.5 3.75 18.8284 3.75 18V8.25Z" stroke="currentColor" stroke-width="1.5"/><path d="M9 6.75V5.625C9 4.79657 9.67157 4.125 10.5 4.125H13.5C14.3284 4.125 15 4.79657 15 5.625V6.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M3.75 12.375C6.32 13.5 9.09 14.0625 12 14.0625C14.91 14.0625 17.68 13.5 20.25 12.375" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M11.25 13.5H12.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',

        'usuarios' => '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M15.75 9.75C15.75 11.8211 14.0711 13.5 12 13.5C9.92893 13.5 8.25 11.8211 8.25 9.75C8.25 7.67893 9.92893 6 12 6C14.0711 6 15.75 7.67893 15.75 9.75Z" stroke="currentColor" stroke-width="1.5"/><path d="M12 2.25C6.61522 2.25 2.25 6.61522 2.25 12C2.25 17.3848 6.61522 21.75 12 21.75C17.3848 21.75 21.75 17.3848 21.75 12C21.75 6.61522 17.3848 2.25 12 2.25Z" stroke="currentColor" stroke-width="1.5"/><path d="M5.25 19.125C5.86 16.6 8.66 15 12 15C15.34 15 18.14 16.6 18.75 19.125" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',

        'roles' => '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2.75L4.5 5.75V11.3C4.5 15.85 7.7 20.1 12 21.25C16.3 20.1 19.5 15.85 19.5 11.3V5.75L12 2.75Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M9.25 11.75L11.25 13.75L15 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',

        'inicio' => '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.75 10.5 12 3.75l8.25 6.75v8.25a1.5 1.5 0 0 1-1.5 1.5h-3.5v-6h-6.5v6h-3.5a1.5 1.5 0 0 1-1.5-1.5V10.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>',

        'pos' => '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2.75 4.75h1.6a1 1 0 0 1 .98.8l.42 2.1m0 0 1.5 7.1a1.5 1.5 0 0 0 1.47 1.2h8.06a1.5 1.5 0 0 0 1.4-.96l2.32-5.83a1 1 0 0 0-.93-1.37H5.75Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9" cy="19.5" r="1.4" stroke="currentColor" stroke-width="1.5"/><circle cx="17" cy="19.5" r="1.4" stroke="currentColor" stroke-width="1.5"/></svg>',

        'caja' => '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2.75" y="7.75" width="18.5" height="12.5" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M2.75 11.75h18.5" stroke="currentColor" stroke-width="1.5"/><path d="M6.75 7.75l1.6-3.2a1.5 1.5 0 0 1 1.34-.8h4.62a1.5 1.5 0 0 1 1.34.8l1.6 3.2" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10.25 16h3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
        'cajas' => '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2.75" y="9.75" width="11.5" height="10.5" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M2.75 13.25h11.5" stroke="currentColor" stroke-width="1.5"/><path d="M9.75 9.75V5.25a1.5 1.5 0 0 1 1.5-1.5h8a1.5 1.5 0 0 1 1.5 1.5v9a1.5 1.5 0 0 1-1.5 1.5h-2.5" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M6.75 16.75h3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',

        'ventas' => '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4.75 20.25V10.5M9.75 20.25V6.75M14.75 20.25v-6M19.75 20.25V4.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M2.75 20.25h18.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',

        'comprobantes' => '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6 2.75h12a1 1 0 0 1 1 1v17.5l-2.5-1.5-2.5 1.5-2.5-1.5-2.5 1.5-2.5-1.5-1.5.9V3.75a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M8.75 8.25h6.5M8.75 12h6.5M8.75 15.5h3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',

        'reportes' => '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.75 20.25h16.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M6.75 16.75V11m4.5 5.75V6.25m4.5 10.5v-7.5m4.5 7.5V4.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',

        'inventario' => '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.75 7.25 12 3.5l8.25 3.75-8.25 3.75L3.75 7.25Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M3.75 12 12 15.75 20.25 12M3.75 16.75 12 20.5l8.25-3.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',

        // Kardex: entra y sale. Las dos flechas dicen de qué va la pantalla sin
        // tener que leer la etiqueta.
        'kardex' => '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.75 8.25h11.5m0 0-3-3m3 3-3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M20.25 15.75H8.75m0 0 3-3m-3 3 3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',

        'devoluciones' => '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M20.25 12a8.25 8.25 0 1 1-2.42-5.83" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M20.25 4.5V10h-5.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.5 12h5M12 9.5v5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',

        'clientes' => '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="8" r="3.75" stroke="currentColor" stroke-width="1.5"/><path d="M4.75 20.25c0-3.6 3.25-6.5 7.25-6.5s7.25 2.9 7.25 6.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',

        'productos' => '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M20.25 7.5 12 3.75 3.75 7.5 12 11.25l8.25-3.75Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M3.75 7.5v9L12 20.25l8.25-3.75v-9" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M12 11.25v9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="m7.875 5.625 8.25 3.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',

        'categorias' => '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3.75" y="3.75" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.5"/><rect x="13.25" y="3.75" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.5"/><rect x="3.75" y="13.25" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.5"/><rect x="13.25" y="13.25" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.5"/></svg>',

        'unidades' => '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2.75" y="8.25" width="18.5" height="7.5" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M7 8.25v3M11 8.25v2M15 8.25v3M19 8.25v2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',

        'proveedores' => '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2.75 8.25h10.5v8.5H2.75z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M13.25 11.25h3.9l3.1 3v2.5h-7z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="6.75" cy="17.75" r="1.75" stroke="currentColor" stroke-width="1.5"/><circle cx="16.75" cy="17.75" r="1.75" stroke="currentColor" stroke-width="1.5"/></svg>',

        'configuracion' => '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" stroke="currentColor" stroke-width="1.5"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',

        'perfil' => '<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M12 3.5C7.30558 3.5 3.5 7.30558 3.5 12C3.5 14.1526 4.3002 16.1184 5.61936 17.616C6.17279 15.3096 8.24852 13.5955 10.7246 13.5955H13.2746C15.7509 13.5955 17.8268 15.31 18.38 17.6167C19.6996 16.119 20.5 14.153 20.5 12C20.5 7.30558 16.6944 3.5 12 3.5ZM17.0246 18.8566V18.8455C17.0246 16.7744 15.3457 15.0955 13.2746 15.0955H10.7246C8.65354 15.0955 6.97461 16.7744 6.97461 18.8455V18.856C8.38223 19.8895 10.1198 20.5 12 20.5C13.8798 20.5 15.6171 19.8898 17.0246 18.8566ZM2 12C2 6.47715 6.47715 2 12 2C17.5228 2 22 6.47715 22 12C22 17.5228 17.5228 22 12 22C6.47715 22 2 17.5228 2 12ZM11.9991 7.25C10.8847 7.25 9.98126 8.15342 9.98126 9.26784C9.98126 10.3823 10.8847 11.2857 11.9991 11.2857C13.1135 11.2857 14.0169 10.3823 14.0169 9.26784C14.0169 8.15342 13.1135 7.25 11.9991 7.25ZM8.48126 9.26784C8.48126 7.32499 10.0563 5.75 11.9991 5.75C13.9419 5.75 15.5169 7.32499 15.5169 9.26784C15.5169 11.2107 13.9419 12.7857 11.9991 12.7857C10.0563 12.7857 8.48126 11.2107 8.48126 9.26784Z" fill="currentColor"/></svg>',
    ];
}
