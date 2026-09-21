import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// El contenedor de Vite exporta DOCKER=true (ver docker-compose.yml).
// Fuera de Docker esta rama no se activa y `npm run dev` se comporta igual que
// siempre: puerto 5173 en localhost.
const inDocker = process.env.DOCKER === 'true';

// Cada stack de Docker necesita el suyo para poder levantar dos a la vez: el de
// ventas usa el 5174 y el de restaurante el 5175 (VITE_PUERTO en su compose).
const puerto = Number(process.env.VITE_PUERTO ?? 5174);

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],

    server: inDocker
        ? {
              // En loopback el puerto publicado por Docker no llega: hay que
              // escuchar en todas las interfaces.
              host: '0.0.0.0',
              port: puerto,
              strictPort: true,
              // Imprescindible, y por partida doble: además de decirle al
              // cliente de HMR a dónde conectarse, es lo que laravel-vite-plugin
              // escribe en public/hot. Sin esto heredaría el host de arriba y
              // dejaría ahí "http://0.0.0.0:5174", que el navegador no resuelve.
              hmr: { host: 'localhost' },
              // Los bind mounts de Windows no propagan eventos inotify a Linux,
              // así que sin polling Vite no detecta ningún cambio.
              watch: {
                  usePolling: true,
                  interval: 1000,
                  binaryInterval: 2000,
                  ignored: ['**/node_modules/**', '**/vendor/**', '**/storage/**'],
              },
          }
        : undefined,
});
