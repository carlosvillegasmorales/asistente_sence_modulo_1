#!/bin/sh
# Instala el hook pre-commit anti-secretos en el repo raíz y en el del plugin.
# Uso (desde la raíz del repo):  sh tools/hooks/instalar.sh
set -e
RAIZ="$(cd "$(dirname "$0")/../.." && pwd)"
for repo in "$RAIZ" "$RAIZ/moodle/public/local/chatbot"; do
  [ -e "$repo/.git" ] || { echo "(se omite $repo: no es un repo git; ¿falta 'git submodule update --init'?)"; continue; }
  destino="$(git -C "$repo" rev-parse --git-path hooks)/pre-commit"
  case "$destino" in /*) ;; *) destino="$repo/$destino" ;; esac
  mkdir -p "$(dirname "$destino")"
  cp "$RAIZ/tools/hooks/pre-commit" "$destino"
  chmod +x "$destino"
  echo "hook instalado en $destino"
done
