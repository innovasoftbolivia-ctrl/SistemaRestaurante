#!/usr/bin/env bash
#
# Revisión de salud del Sistema de Restaurante, pensada para correr desde cron.
#
#   ./scripts/revisar-salud.sh
#
# Comprueba las cuatro cosas que dejan al negocio sin poder vender —o sin red
# de seguridad— y que no avisan solas:
#
#   1. Los contenedores están corriendo.
#   2. La aplicación responde Y la base contesta (`/up` comprueba las dos;
#      ver app/Listeners/ComprobarBaseDeDatos.php).
#   3. Queda espacio en disco. Un disco lleno detiene MySQL y de paso hace
#      fallar los respaldos: se pierden las dos cosas a la vez.
#   4. Hay un respaldo reciente. Es el que más silencio hace: un respaldo que
#      dejó de correr hace tres semanas se descubre el día que se necesita.
#
# Sale con código 0 si todo está bien y 1 si algo falla, así que cron avisa
# solo (si el servidor tiene correo). Para que avise por otro medio:
#
#   ALERTA_COMANDO='...' ./scripts/revisar-salud.sh
#
# recibe el resumen por entrada estándar. Ejemplo con Telegram:
#
#   ALERTA_COMANDO='xargs -0 -I{} curl -s -o /dev/null \
#       --data-urlencode "chat_id=<ID>" --data-urlencode "text={}" \
#       https://api.telegram.org/bot<TOKEN>/sendMessage'
#
# Variables que acepta:
#   URL_SALUD         dirección de /up (http://localhost:8100/up)
#   DISCO_MAXIMO      % de uso a partir del cual avisa (85)
#   BACKUP_MAX_HORAS  antigüedad tolerada del último respaldo (30)
#   ALERTA_COMANDO    comando que recibe el resumen por stdin cuando algo falla
#   VENTAS_MYSQL / VENTAS_APP / VENTAS_NGINX   nombres de contenedor (se autodetectan)

set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

URL_SALUD="${URL_SALUD:-http://localhost:8100/up}"
DISCO_MAXIMO="${DISCO_MAXIMO:-85}"
BACKUP_MAX_HORAS="${BACKUP_MAX_HORAS:-30}"
DESTINO="backups"

PROBLEMAS=()
LINEAS=()

anotar() { LINEAS+=("$1"); echo "$1"; }
fallar() { PROBLEMAS+=("$1"); anotar "FALLA  $1"; }
pasar()  { anotar "ok     $1"; }

# Mismo criterio que backup-db.sh: producción primero, después el stack de
# desarrollo del restaurante. Nunca el del sistema de ventas (ventas_mysql).
detectar() {
    local explicito="$1"; shift
    if [ -n "$explicito" ]; then echo "$explicito"; return 0; fi
    # Primero uno que esté CORRIENDO, en el orden de la lista: `docker inspect`
    # también responde por un contenedor detenido, y en una máquina con los
    # dos stacks de desarrollo el de ventas parado le ganaba al de restaurante
    # levantado. Si ninguno corre, el primero que exista, para que el aviso
    # diga de cuál se trata.
    for nombre in "$@"; do
        if [ "$(docker inspect -f '{{.State.Running}}' "$nombre" 2>/dev/null)" = "true" ]; then echo "$nombre"; return 0; fi
    done
    for nombre in "$@"; do
        if docker inspect "$nombre" >/dev/null 2>&1; then echo "$nombre"; return 0; fi
    done
    return 1
}

echo "Revisión de salud — $(date '+%Y-%m-%d %H:%M:%S')"
echo

# --- 1. Contenedores ----------------------------------------------------------
for par in "MySQL:${VENTAS_MYSQL:-}:ventas_mysql_prod:restaurante_mysql" \
           "aplicación:${VENTAS_APP:-}:ventas_app_prod:restaurante_app:ventas_app" \
           "nginx:${VENTAS_NGINX:-}:ventas_nginx_prod:restaurante_nginx:ventas_nginx"; do
    IFS=':' read -r etiqueta explicito n1 n2 n3 <<< "$par"

    if ! nombre="$(detectar "$explicito" "$n1" "$n2" "$n3")"; then
        fallar "no encuentro el contenedor de $etiqueta ($n1, $n2 ni $n3)"
        continue
    fi

    estado="$(docker inspect -f '{{.State.Status}}' "$nombre" 2>/dev/null || echo desconocido)"
    if [ "$estado" = "running" ]; then
        pasar "contenedor de $etiqueta ($nombre) corriendo"
    else
        fallar "el contenedor de $etiqueta ($nombre) está «$estado», no «running»"
    fi
done

# --- 2. Aplicación y base ------------------------------------------------------
# Sin `|| echo 000`: cuando la conexión falla, curl ya escribe `000` por
# `-w`, y el respaldo lo duplicaba dejando «000000» — que no coincidía con
# ninguna rama y terminaba dando un mensaje equivocado. El `:-000` cubre el
# caso de que curl no imprima nada en absoluto.
# 30s y no 15: con MySQL caído, PHP tarda en darse por vencido al conectar, y
# con un margen corto el chequeo se queda con un tiempo agotado en vez del 500
# que dice mucho más. El aviso salta igual en ambos casos, pero el 500 apunta
# directo a la base.
CODIGO="$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$URL_SALUD" 2>/dev/null)"
CODIGO="${CODIGO:-000}"

if [ "$CODIGO" = "200" ]; then
    pasar "la aplicación y la base responden ($URL_SALUD)"
elif [ "$CODIGO" = "000" ]; then
    fallar "$URL_SALUD no respondió (aplicación caída, o esperando a una base que no contesta)"
else
    fallar "$URL_SALUD respondió HTTP $CODIGO (500 suele ser la base caída)"
fi

# --- 3. Disco ------------------------------------------------------------------
# Del sistema de ficheros donde vive el proyecto, que es donde están los
# respaldos y —salvo que se haya movido— también los datos de MySQL.
USO="$(df -P . 2>/dev/null | awk 'NR==2 {gsub(/%/,"",$5); print $5}')"
if [ -z "$USO" ]; then
    fallar "no pude leer el uso de disco"
elif [ "$USO" -ge "$DISCO_MAXIMO" ]; then
    fallar "disco al ${USO}% (límite ${DISCO_MAXIMO}%). Con el disco lleno MySQL se detiene y los respaldos fallan"
else
    pasar "disco al ${USO}% (límite ${DISCO_MAXIMO}%)"
fi

# --- 4. Respaldo reciente -------------------------------------------------------
# `find -mmin` en vez de comparar fechas a mano: no depende de la zona
# horaria ni del formato de `date` del servidor.
ULTIMO="$(find "$DESTINO" -maxdepth 1 -name 'ventas_db_*.sql.gz' -mmin "-$((BACKUP_MAX_HORAS * 60))" 2>/dev/null | sort | tail -1)"

# Los respaldos nocturnos del programador viven en el volumen de la aplicación,
# no en backups/. Antes el chequeo solo miraba aquí y, sin un cron aparte,
# avisaba siempre «no hay respaldos» aunque se hicieran todas las noches.
if [ -z "$ULTIMO" ] && APP_RESPALDOS="$(detectar "${VENTAS_APP:-}" ventas_app_prod restaurante_app ventas_app)"; then
    EN_APP="$(docker exec "$APP_RESPALDOS" find storage/app/respaldos -maxdepth 1 -name 'ventas_db_*.sql.gz' -size +1k -mmin "-$((BACKUP_MAX_HORAS * 60))" 2>/dev/null | sort | tail -1)"
    if [ -n "$EN_APP" ]; then
        pasar "respaldo reciente del programador: $(basename "$EN_APP")"
        ULTIMO="programador"
    fi
fi

if [ -z "$ULTIMO" ]; then
    RECIENTE="$(find "$DESTINO" -maxdepth 1 -name 'ventas_db_*.sql.gz' 2>/dev/null | sort | tail -1)"
    if [ -n "$RECIENTE" ]; then
        fallar "el último respaldo ($(basename "$RECIENTE")) tiene más de ${BACKUP_MAX_HORAS}h. ¿Sigue corriendo el cron?"
    else
        fallar "no hay ningún respaldo en $DESTINO/ ni en el volumen del programador. Ver «Copias de seguridad» en el README"
    fi
elif [ "$ULTIMO" != "programador" ]; then
    # Un archivo que existe pero pesa nada es un respaldo fallido disfrazado
    # de respaldo hecho.
    BYTES="$(wc -c < "$ULTIMO" 2>/dev/null || echo 0)"
    if [ "$BYTES" -lt 1024 ]; then
        fallar "el último respaldo ($(basename "$ULTIMO")) pesa $BYTES bytes: está vacío o cortado"
    else
        pasar "respaldo reciente: $(basename "$ULTIMO") ($(du -h "$ULTIMO" | cut -f1))"
    fi
fi

# --- Resumen --------------------------------------------------------------------
echo
if [ ${#PROBLEMAS[@]} -eq 0 ]; then
    echo "Todo en orden."
    exit 0
fi

RESUMEN="Sistema de Restaurante — ${#PROBLEMAS[@]} problema(s) el $(date '+%Y-%m-%d %H:%M'):"
for p in "${PROBLEMAS[@]}"; do
    RESUMEN="${RESUMEN}"$'\n'"- ${p}"
done

echo "$RESUMEN" >&2

if [ -n "${ALERTA_COMANDO:-}" ]; then
    if printf '%s' "$RESUMEN" | sh -c "$ALERTA_COMANDO"; then
        echo "(aviso enviado)" >&2
    else
        echo "(no se pudo enviar el aviso con ALERTA_COMANDO)" >&2
    fi
fi

exit 1
