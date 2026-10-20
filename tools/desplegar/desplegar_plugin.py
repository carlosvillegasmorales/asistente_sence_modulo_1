"""Despliega el plugin local_chatbot a un VPS, con el mismo patron disciplinado
que se ha usado a mano en esta sesion y que ya evito un desastre una vez:

  modo mantenimiento -> respaldo -> subir -> verificar sintaxis -> instalar
  -> comprobar que la version en base sea la esperada -> si no calza, revertir

La version esperada NUNCA se escribe a mano en este script: se lee del propio
version.php del plugin en este repo. Un intento anterior comparaba contra una
version fija de un despliegue viejo, dio por fallida una instalacion correcta
y disparo una reversion innecesaria — ver CLAUDE.md, seccion "Gotcha
recurrente".

Este script no lleva ninguna credencial. Las lee de tools/desplegar/servidores.json
(gitignored), con la forma que muestra servidores.example.json.

Uso:
    python tools/desplegar/desplegar_plugin.py propio
    python tools/desplegar/desplegar_plugin.py cliente
    python tools/desplegar/desplegar_plugin.py cliente --simular   (no cambia nada, solo informa)
"""
import argparse
import json
import os
import re
import sys

import paramiko

RAIZ_REPO = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
PLUGIN_LOCAL = os.path.join(RAIZ_REPO, "moodle", "public", "local", "chatbot")
CONFIG_SERVIDORES = os.path.join(os.path.dirname(__file__), "servidores.json")
OMITIR = {".git", "__pycache__", "node_modules", ".idea", ".github"}
EXTENSIONES_TEXTO = (".php", ".js", ".css", ".xml", ".md", ".txt", ".json")

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")


def log(msg):
    print(f"[*] {msg}", flush=True)


def leer_version_local():
    """Version y release del plugin tal como estan en el repo, ahora mismo."""
    contenido = open(os.path.join(PLUGIN_LOCAL, "version.php"), encoding="utf-8").read()
    version = re.search(r"\$plugin->version\s*=\s*(\d+);", contenido).group(1)
    release = re.search(r"\$plugin->release\s*=\s*'([^']+)'", contenido).group(1)
    return version, release


def cargar_servidor(nombre):
    if not os.path.exists(CONFIG_SERVIDORES):
        sys.exit(
            f"No existe {CONFIG_SERVIDORES}.\n"
            "Copia servidores.example.json como servidores.json y completa los datos reales."
        )
    servidores = json.load(open(CONFIG_SERVIDORES, encoding="utf-8"))
    if nombre not in servidores:
        sys.exit(f"'{nombre}' no esta en servidores.json. Opciones: {list(servidores.keys())}")
    cfg = servidores[nombre]
    faltantes = [k for k, v in cfg.items() if v == "REEMPLAZAR"]
    if faltantes:
        sys.exit(f"servidores.json todavia tiene valores sin completar para '{nombre}': {faltantes}")
    return cfg


def prefijos(cfg):
    """El unico dato que de verdad distingue Docker de un servidor directo:
    como se invoca PHP para correr admin/cli/* y los verificadores. La subida
    de archivos es igual en los dos casos porque ambos montan el codigo del
    plugin como una carpeta real del sistema de archivos (bind mount en
    Docker), no algo enterrado dentro de la imagen."""
    if cfg["modo"] == "docker":
        cli = f"docker exec -u {cfg['propietario'].split(':')[0]} {cfg['contenedor']} php"
    elif cfg["modo"] == "directo":
        cli = f"cd {os.path.dirname(os.path.dirname(cfg['config_remoto']))} && sudo -u {cfg['propietario'].split(':')[0]} php"
    else:
        sys.exit(f"modo desconocido: {cfg['modo']!r} (usa 'docker' o 'directo')")
    return cli


def ejecutar(ssh, cmd, timeout=1800, mostrar=True, corte=1200):
    _, stdout, stderr = ssh.exec_command(cmd, timeout=timeout)
    salida = stdout.read().decode("utf-8", "ignore") + stderr.read().decode("utf-8", "ignore")
    if mostrar and salida.strip():
        print("   ", salida.strip()[:corte].replace("\n", "\n    "))
    return salida


def php(ssh, cli, script, timeout=900):
    """Corre un script PHP por stdin, para no tener que subirlo antes."""
    stdin, stdout, stderr = ssh.exec_command(cli, timeout=timeout)
    stdin.write(script)
    stdin.channel.shutdown_write()
    err = stderr.read().decode("utf-8", "ignore")
    if err.strip() and "chdir" not in err:
        print("    [stderr]", err.strip()[:250])
    return stdout.read().decode("utf-8", "ignore").strip()


def subir_plugin(sftp, destino):
    log(f"Subiendo el plugin completo a {destino} ...")
    n = 0
    for raiz, dirs, archivos in os.walk(PLUGIN_LOCAL):
        dirs[:] = [d for d in dirs if d not in OMITIR]
        rel = os.path.relpath(raiz, PLUGIN_LOCAL).replace("\\", "/")
        remoto_dir = destino if rel == "." else f"{destino}/{rel}"
        if rel != ".":
            sftp.mkdir(remoto_dir)
        for nombre in archivos:
            datos = open(os.path.join(raiz, nombre), "rb").read()
            if nombre.endswith(EXTENSIONES_TEXTO):
                datos = datos.replace(b"\r\n", b"\n")
            with sftp.open(f"{remoto_dir}/{nombre}", "wb") as f:
                f.write(datos)
            n += 1
    log(f"{n} archivos subidos.")


def verificar_sintaxis(ssh, destino):
    salida = ejecutar(
        ssh,
        f"find {destino} -name '*.php' -print0 | xargs -0 -n1 php -l 2>&1 "
        "| grep -v 'No syntax errors' | head -5; echo FIN",
        mostrar=False,
    )
    if "error" in salida.lower() and "No syntax" not in salida:
        print(salida)
        return False
    return True


def desplegar(nombre, simular):
    version_local, release_local = leer_version_local()
    cfg = cargar_servidor(nombre)
    cli = prefijos(cfg)
    raiz = cfg["raiz_remota"]
    raiz_nueva = raiz + ".nuevo"
    raiz_vieja = raiz + ".viejo"

    log(f"Servidor: {nombre} ({cfg['modo']}) · version a instalar: {version_local} ({release_local})")
    if simular:
        log("Modo --simular: no se conecta ni se cambia nada.")
        return

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(cfg["host"], port=cfg["port"], username=cfg["usuario"], password=cfg["clave"], timeout=60)

    log("[1] estado antes de tocar nada")
    ejecutar(ssh, f"{cli} -r \"define('CLI_SCRIPT',true);require('{cfg['config_remoto']}');"
                  "echo 'version en base: ', get_config('local_chatbot','version'), PHP_EOL;\"")

    log("[2] subir el plugin a una carpeta nueva, sin tocar la que esta sirviendo")
    ejecutar(ssh, f"rm -rf {raiz_nueva} && mkdir -p {raiz_nueva}", mostrar=False)
    sftp = ssh.open_sftp()
    subir_plugin(sftp, raiz_nueva)
    sftp.close()

    log("[3] verificar sintaxis antes de instalar nada")
    if not verificar_sintaxis(ssh, raiz_nueva):
        log("Sintaxis invalida. No se reemplaza nada. Abortando.")
        ejecutar(ssh, f"rm -rf {raiz_nueva}", mostrar=False)
        ssh.close()
        sys.exit(1)

    log("[4] modo mantenimiento, reemplazar y actualizar")
    dueno = cfg["propietario"]
    ejecutar(ssh, f"{cli} admin/cli/maintenance.php --enable 2>&1 | tail -1")
    ejecutar(
        ssh,
        f"rm -rf {raiz_vieja} && mv {raiz} {raiz_vieja} && mv {raiz_nueva} {raiz} && "
        f"chown -R {dueno} {raiz} && find {raiz} -type d -exec chmod 755 {{}} + && "
        f"find {raiz} -type f -exec chmod 644 {{}} +",
        mostrar=False,
    )
    ejecutar(ssh, f"{cli} admin/cli/upgrade.php --non-interactive 2>&1 | tail -5", timeout=3600)

    log("[5] comprobar que la version instalada sea la esperada")
    instalada = php(ssh, cli, f"<?php define('CLI_SCRIPT',true);require('{cfg['config_remoto']}');"
                               "echo get_config('local_chatbot','version');")
    log(f"version en base tras instalar: {instalada}")

    if instalada != version_local:
        log(f"[X] se esperaba {version_local}: revirtiendo al plugin anterior.")
        ejecutar(ssh, f"rm -rf {raiz} && mv {raiz_vieja} {raiz} && chown -R {dueno} {raiz}")
        ejecutar(ssh, f"{cli} admin/cli/purge_caches.php >/dev/null 2>&1")
        ejecutar(ssh, f"{cli} admin/cli/maintenance.php --disable 2>&1 | tail -1")
        ssh.close()
        sys.exit(1)

    log("[6] purgar cachés y salir de mantenimiento")
    ejecutar(ssh, f"{cli} admin/cli/purge_caches.php 2>&1 | tail -1")
    ejecutar(ssh, f"{cli} admin/cli/maintenance.php --disable 2>&1 | tail -1")
    ejecutar(ssh, f"rm -rf {raiz_vieja}", mostrar=False)

    log("[7] correr los verificadores contra este servidor (con base de datos real, corren los 9)")
    ejecutar(
        ssh,
        f"for f in {raiz}/cli/verificar_*.php; do {cli} $f 2>&1 | "
        "grep -E 'Casos verificados|Celdas verificadas|FALLA'; done",
        corte=1500,
    )

    log("[8] comprobación HTTP")
    ejecutar(ssh, "curl -s -o /dev/null -w 'sitio HTTP %{http_code}\\n' http://localhost/login/index.php")

    ssh.close()
    log(f"Listo: {nombre} quedó en {version_local} ({release_local}).")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("servidor", help="nombre tal como aparece en servidores.json (p.ej. propio, cliente)")
    parser.add_argument("--simular", action="store_true", help="solo muestra qué versión se instalaría, no toca nada")
    args = parser.parse_args()
    desplegar(args.servidor, args.simular)
