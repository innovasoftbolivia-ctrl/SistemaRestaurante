"""Quita del esquema lo que un hosting compartido no deja crear.

Deja las tablas, sus CHECK y los datos; saca procedimientos, triggers y vistas,
que con LOGICA_EN_PHP=true ejecuta PHP. Es lo que corre en MariaDB (el motor de
InfinityFree) y lo que prueba el trabajo «mariadb» del CI.

    python scripts/esquema-sin-logica.py docs/sql/01_schema_mysql.sql > salida.sql
"""
import io
import re
import sys

texto = io.open(sys.argv[1], encoding='utf-8').read()

# Los bloques con DELIMITER $$ … DELIMITER ; son los procedimientos y triggers.
texto = re.sub(r'(?ms)^DELIMITER \$\$.*?^DELIMITER ;\s*', '', texto)

# Cada vista, hasta el punto y coma que la cierra.
texto = re.sub(r'(?ms)^CREATE (?:OR REPLACE )?VIEW\b.*?;\s*', '', texto)

io.open(sys.stdout.fileno(), 'w', encoding='utf-8', closefd=False).write(texto)
