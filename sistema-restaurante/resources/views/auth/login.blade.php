@extends('layouts.auth')

@php
    $negocio = \App\Support\Config::negocio();
@endphp

@section('content')
    {{-- El inicio de sesión es la puerta del sistema y va siempre en el azul
         marino de la marca, con el tema claro o el oscuro: por eso sus colores
         están escritos sin la variante `dark:`. Arriba, el nombre del negocio
         (Configuración), que es lo que el personal reconoce; abajo, discreto,
         quién desarrolló el sistema. --}}
    <div class="relative flex min-h-screen items-center justify-center overflow-hidden bg-marino-900 px-4 py-10">
        {{-- El fondo: un halo azul detrás de la tarjeta. --}}
        <div aria-hidden="true"
            class="pointer-events-none absolute top-1/2 left-1/2 h-[34rem] w-[34rem] -translate-x-1/2 -translate-y-1/2 rounded-full bg-brand-500/20 blur-3xl">
        </div>

        <div class="relative w-full max-w-sm">
            <div class="rounded-2xl border border-marino-700 bg-marino-800/90 px-6 py-8 shadow-2xl shadow-black/40 backdrop-blur sm:px-8">
                <div class="mb-7 flex flex-col items-center text-center">
                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-500 text-white shadow-lg shadow-brand-500/30">
                        <svg aria-hidden="true" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M19 3v12h-5c-.023-3.681.184-7.406 5-12zm0 12v6h-1v-3M8 4v17M5 4v3a3 3 0 1 0 6 0V4" />
                        </svg>
                    </span>
                    <h1 class="mt-4 text-xl font-semibold text-white" data-nombre-negocio>{{ $negocio }}</h1>
                    <p class="mt-1 text-sm text-gray-400">Ingresa para empezar tu turno</p>
                </div>

                @if ($errors->any())
                    <div role="alert"
                        class="mb-5 rounded-lg border border-error-500/40 bg-error-500/10 px-3.5 py-2.5 text-sm text-error-300">
                        <p class="font-semibold text-error-200">No se pudo ingresar</p>
                        <p class="mt-0.5">{{ $errors->first('usuario') ?: $errors->first() }}</p>
                    </div>
                @endif

                <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
                    @csrf

                    <div>
                        <label for="usuario" class="mb-1.5 block text-sm font-medium text-gray-300">Usuario</label>
                        <div class="relative">
                            <svg aria-hidden="true" class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-gray-500"
                                width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
                                stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21a8 8 0 0 0-16 0" />
                                <circle cx="12" cy="7" r="4" />
                            </svg>
                            <input id="usuario" name="usuario" type="text" value="{{ old('usuario') }}"
                                placeholder="Tu usuario" autocomplete="username" autocapitalize="none" spellcheck="false"
                                autofocus required
                                class="h-12 w-full rounded-lg border border-marino-600 bg-marino-900 py-2.5 pr-4 pl-11 text-sm text-white placeholder:text-gray-500 focus:border-brand-400 focus:ring-3 focus:ring-brand-500/25 focus:outline-hidden" />
                        </div>
                    </div>

                    <div>
                        <label for="password" class="mb-1.5 block text-sm font-medium text-gray-300">Contraseña</label>
                        <div x-data="{ visible: false }" class="relative">
                            <svg aria-hidden="true" class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-gray-500"
                                width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
                                stroke-linecap="round" stroke-linejoin="round">
                                <rect x="4" y="11" width="16" height="10" rx="2" />
                                <path d="M8 11V7a4 4 0 0 1 8 0v4" />
                            </svg>
                            <input :type="visible ? 'text' : 'password'" type="password" id="password" name="password"
                                placeholder="Tu contraseña" autocomplete="current-password" required
                                class="h-12 w-full rounded-lg border border-marino-600 bg-marino-900 py-2.5 pr-12 pl-11 text-sm text-white placeholder:text-gray-500 focus:border-brand-400 focus:ring-3 focus:ring-brand-500/25 focus:outline-hidden" />
                            {{-- Un botón de verdad, no un ícono suelto: se alcanza con
                                 el teclado y un lector de pantalla dice qué hace. --}}
                            <button type="button" @click="visible = !visible"
                                :aria-label="visible ? 'Ocultar la contraseña' : 'Mostrar la contraseña'"
                                :aria-pressed="visible ? 'true' : 'false'" aria-label="Mostrar la contraseña"
                                class="absolute top-1/2 right-2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-md text-gray-400 transition hover:text-white focus-visible:ring-3 focus-visible:ring-brand-500/40 focus-visible:outline-hidden">
                                <svg x-show="!visible" aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                                <svg x-show="visible" x-cloak aria-hidden="true" width="18" height="18" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path d="M3 3l18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.9 5.2A10.4 10.4 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.2M6.6 6.6C3.7 8.4 2 12 2 12s3.5 7 10 7c1.6 0 3-.4 4.3-1" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit"
                        class="mt-2 flex h-12 w-full items-center justify-center rounded-lg bg-brand-500 text-sm font-semibold text-white shadow-lg shadow-brand-500/25 transition hover:bg-brand-600 focus-visible:ring-3 focus-visible:ring-brand-400/50 focus-visible:outline-hidden">
                        Ingresar
                    </button>
                </form>

                <p class="mt-5 text-center text-xs leading-relaxed text-gray-400">
                    ¿Olvidaste tu contraseña? Pídele al administrador que la restablezca.
                </p>
            </div>

            @if (filled(config('restaurante.desarrollado_por')))
                <p class="mt-6 text-center text-xs text-gray-500" data-desarrollado-por>
                    Desarrollado por <span class="font-semibold text-brand-300">{{ config('restaurante.desarrollado_por') }}</span>
                </p>
            @endif
        </div>
    </div>
@endsection
