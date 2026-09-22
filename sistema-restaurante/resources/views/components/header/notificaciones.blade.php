@php
    $avisos = \App\Support\Notificaciones::para(auth()->user());
@endphp

{{-- La campana: se pide de nuevo cada minuto mientras la pestaña está a la
     vista, y se reemplaza entera (ver NotificacionController). Si abierto el
     desplegable llega una nueva, sigue abierto: el estado vive acá afuera. --}}
@if ($avisos !== null)
    <div class="relative" x-data="{ abierto: false }" @click.away="abierto = false" @keydown.escape="abierto = false"
        x-init="setInterval(async () => {
            if (document.visibilityState !== 'visible') return;
            try {
                const r = await fetch('{{ route('notificaciones') }}', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (r.ok && r.headers.get('X-Notificaciones') === '1') $refs.campana.innerHTML = await r.text();
            } catch (e) {}
        }, 60000)">
        <div x-ref="campana">
            @include('components.header._campana', ['avisos' => $avisos])
        </div>
    </div>
@endif
