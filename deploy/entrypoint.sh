#!/bin/bash
set -e

: "${MOODLE_DB_PASSWORD:?definir MOODLE_DB_PASSWORD en el entorno del contenedor}"

# Inicializar MariaDB si el directorio esta vacio
if [ ! -d "/var/lib/mysql/mysql" ]; then
    echo "==> Inicializando MariaDB data directory..."
    mysql_install_db --user=mysql --datadir=/var/lib/mysql > /dev/null

    # Arrancar temporalmente mysqld para crear usuarios
    mysqld_safe --datadir=/var/lib/mysql &
    PID=$!

    echo "==> Esperando que MariaDB inicie..."
    for i in {30..0}; do
        if mysqladmin ping --silent; then
            break
        fi
        sleep 1
    done

    echo "==> Configurando base de datos Moodle y usuario..."
    mysql -e "CREATE DATABASE IF NOT EXISTS moodle DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER IF NOT EXISTS 'moodleuser'@'localhost' IDENTIFIED BY '${MOODLE_DB_PASSWORD}';"
    mysql -e "CREATE USER IF NOT EXISTS 'moodleuser'@'127.0.0.1' IDENTIFIED BY '${MOODLE_DB_PASSWORD}';"
    mysql -e "GRANT ALL PRIVILEGES ON moodle.* TO 'moodleuser'@'localhost';"
    mysql -e "GRANT ALL PRIVILEGES ON moodle.* TO 'moodleuser'@'127.0.0.1';"
    mysql -e "FLUSH PRIVILEGES;"

    if [ -f "/docker-entrypoint-initdb.d/moodle_produccion_dump.sql" ]; then
        echo "==> Importando volcado SQL inicial de Moodle..."
        mysql -u root moodle < /docker-entrypoint-initdb.d/moodle_produccion_dump.sql || true
        echo "==> Volcado SQL importado con exito."
    fi

    echo "==> Deteniendo MariaDB temporal..."
    mysqladmin shutdown
    wait $PID
fi

chown -R mysql:mysql /var/lib/mysql
chown -R www-data:www-data /var/www/moodledata 2>/dev/null || true

echo "==> Iniciando Supervisord (Apache + MariaDB)..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
