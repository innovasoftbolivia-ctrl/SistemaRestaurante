<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">

{{-- Color de la barra del navegador en móvil. Arranca según la preferencia del
     sistema y lo reajusta el store `theme` al cambiar de tema a mano.
     #f9fafb es gray-50 (fondo claro) y #060d24 el gray-900 del modo oscuro (el azul marino). --}}
<meta name="theme-color" content="#f9fafb" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#060d24" media="(prefers-color-scheme: dark)">

<title>{{ isset($title) ? $title.' | ' : '' }}{{ config('app.name') }}</title>

<link rel="icon" href="/images/logo/logo-icon.svg?v=innovadevs" type="image/svg+xml">

@vite(['resources/css/app.css', 'resources/js/app.js'])

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.store('theme', {
            theme: 'light',
            init() {
                const savedTheme = localStorage.getItem('theme');
                const systemTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                this.theme = savedTheme || systemTheme;
                this.updateTheme();
            },
            toggle() {
                this.theme = this.theme === 'light' ? 'dark' : 'light';
                localStorage.setItem('theme', this.theme);
                this.updateTheme();
            },
            updateTheme() {
                const html = document.documentElement;
                const body = document.body;
                if (this.theme === 'dark') {
                    html.classList.add('dark');
                    body.classList.add('dark', 'bg-gray-900');
                } else {
                    html.classList.remove('dark');
                    body.classList.remove('dark', 'bg-gray-900');
                }
                // Los <meta theme-color> del head van por preferencia del
                // sistema; si el tema se cambia a mano hay que reescribirlos o
                // la barra del navegador se queda del color contrario.
                const color = this.theme === 'dark' ? '#060d24' : '#f9fafb';
                document.querySelectorAll('meta[name="theme-color"]').forEach((m) => {
                    m.setAttribute('content', color);
                });
            }
        });

        Alpine.store('sidebar', {
            isExpanded: window.innerWidth >= 1280,
            isMobileOpen: false,
            isHovered: false,

            toggleExpanded() {
                this.isExpanded = !this.isExpanded;
                this.isMobileOpen = false;
            },

            toggleMobileOpen() {
                this.isMobileOpen = !this.isMobileOpen;
            },

            setMobileOpen(val) {
                this.isMobileOpen = val;
            },

            setHovered(val) {
                // El hover solo despliega la barra en escritorio y cuando está plegada.
                if (window.innerWidth >= 1280 && !this.isExpanded) {
                    this.isHovered = val;
                }
            }
        });
    });
</script>

{{-- Se aplica el tema antes de pintar, para que no parpadee en blanco. --}}
<script>
    (function() {
        const savedTheme = localStorage.getItem('theme');
        const systemTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        if ((savedTheme || systemTheme) === 'dark') {
            document.documentElement.classList.add('dark');
        }
    })();
</script>

{{-- Lo que una pantalla necesita decidir antes de la primera pintura (la
     cocina: si arranca en pantalla completa, sin barra lateral). --}}
@stack('antes-de-pintar')
