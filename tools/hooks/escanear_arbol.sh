#!/bin/sh
# Escanea TODO el árbol versionado (no solo el diff) en busca de secretos y archivos prohibidos.
# Lo usa el CI; también sirve a mano:  sh tools/hooks/escanear_arbol.sh
# Excluye el núcleo de Moodle (fixtures y constantes que no son credenciales).
cd "$(git rev-parse --show-toplevel)" || exit 1
EXCLUIR=':!moodle/public :!moodle/vendor :!moodle/.github :!moodle/npm-shrinkwrap.json'
fallo=0
patrones='(password|passwd|contrasena|contraseña|secret|api[_-]?key|token|bearer|pw)["'"'"']?[[:space:]]*[:=][[:space:]]*["'"'"'][A-Za-z0-9/+_.!*$-]{8,}["'"'"']'
# shellcheck disable=SC2086
hallazgos=$(git grep -n -I -E -i "$patrones" -- . $EXCLUIR | grep -viE '(example|ejemplo|placeholder|xxx+|<.*>|tu[_-]|your[_-]|change[_-]?me|getenv|\$\{|os\.environ|\$_ENV|\$argv)' | cut -d: -f1,2)
if [ -n "$hallazgos" ]; then echo "SECRETOS (archivo:línea):"; echo "$hallazgos"; fallo=1; fi
# shellcheck disable=SC2086
if git grep -l -I -E 'BEGIN (RSA |OPENSSH |EC |DSA |PGP )?PRIVATE KEY' -- . $EXCLUIR | grep -q .; then echo "CLAVE PRIVADA versionada"; fallo=1; fi
# shellcheck disable=SC2086
prohibidos=$(git ls-files -- . $EXCLUIR | grep -iE '(^|/)\.env($|\.)|\.(pem|key|p12|pfx|dmp|bak|sql)$|\.(zip|tar\.gz|tgz|7z|rar)$|(^|/)config\.php(\.prod)?$|(^|/)deploy_vps\.py$|(^|/)servidores\.json$' | grep -viE '\.(example|ejemplo)$')
if [ -n "$prohibidos" ]; then echo "ARCHIVOS PROHIBIDOS versionados:"; echo "$prohibidos"; fallo=1; fi
[ "$fallo" -eq 0 ] && echo "Árbol limpio: sin secretos ni archivos prohibidos."
exit "$fallo"
