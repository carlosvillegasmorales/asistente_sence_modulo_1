# Guía: instalar el Módulo 1 completo en otro Moodle (VPS o PC)

Dejar un Moodle igual a los dos servidores de producción tiene **tres piezas**. Las dos primeras viajan con
este repositorio; la tercera depende de cada servidor.

| Pieza | Qué es | Dónde está |
|---|---|---|
| 1. El plugin | El asistente (código, idiomas, migraciones, ajustes por defecto) | `moodle/public/local/chatbot/` o el zip `asistente_ia_M1-vX.Y.Z.zip` |
| 2. El curso | Textos, etiquetas, recursos `U1-*`, quiz, actividades del recorrido, URL oculta. **Sin datos de personas** | `deploy/curso/Modulo1_curso_base.mbz` |
| 3. La configuración | Ajustes del plugin, HTML adicional del sitio, enlaces del curso | `cli/configurar_modulo1.php` (la aplica en un comando) |

Lo que **no** viaja, a propósito: la clave del proveedor de IA, las claves de los servidores, las personas
y su avance. Cada servidor pone lo suyo.

## Pasos

Requisitos: Moodle **5.2.2** (o la versión de tu repo) con PHP 8.3. Los comandos se corren como el usuario del
servidor web, dentro de la carpeta de Moodle.

1. **Instalar el plugin.** Con el repo clonado, el plugin ya está en `moodle/public/local/chatbot/`. En otro
   Moodle: copia esa carpeta a `local/chatbot/` o sube el zip en *Administración → Plugins → Instalar plugins*.
   Luego `php admin/cli/upgrade.php --non-interactive` y `php admin/cli/purge_caches.php`.
2. **Clave del proveedor de IA.** *Administración → Plugins → Plugins locales → Asistente IA*: elige proveedor
   y pega tu clave. Es la única configuración que hay que hacer a mano.
3. **Restaurar el curso.** *Administración → Cursos → Restaurar un curso* y sube
   `deploy/curso/Modulo1_curso_base.mbz` (o por consola:
   `php admin/cli/restore_backup.php --file=RUTA/Modulo1_curso_base.mbz --categoryid=1`, que imprime el id del
   curso nuevo). Anota ese **id**.
4. **Aplicar la configuración.** Primero en simulación, para ver qué cambiaría:
   ```bash
   php local/chatbot/cli/configurar_modulo1.php --curso=ID --siguientes=ID_MODULO2,ID_MODULO3 --simular
   php local/chatbot/cli/configurar_modulo1.php --curso=ID --siguientes=ID_MODULO2,ID_MODULO3
   ```
   Deja el asistente **solo** en ese curso (también fuera de los cursos, para todos los roles), activa el PDP
   automático, apaga las barras de ruta, reescribe los enlaces del curso al sitio y al id nuevos, escribe el HTML
   adicional del sitio y activa `allowstealth`. Se puede repetir sin duplicar nada. Si todavía no existen los
   Módulos 2 y 3, omite `--siguientes` y agrégalo después.
5. **Instrumento y base de conocimiento.** Desde el plugin: `instrumento.php` (diagnóstico `DIAG-M1-v1.0`) y
   `manage_kb.php` (documentos U1-LF, U1-CA, U1-ER, U1-QF y las fuentes de referencia, que están en
   `Documentos Cliente/`). Lo de las fuentes 7 a 9 está explicado en `CLAUDE.md`.
6. **Probar.** Con una cuenta de estudiante matriculada en el curso: ver el asistente solo dentro del Módulo 1,
   abrir el botón verde de la ficha, aceptar las condiciones (la actividad de consentimiento se marca sola) y
   comprobar que «Siguiente paso» lleva al diagnóstico.

## Qué hace cada ajuste (y cómo retroceder)

- **Asistente solo en estos cursos** (`chatonlycourses`): vacío = el asistente se ve en todas partes.
- **Cursos sin asistente** (`nochatcourses`): oculta además esos cursos.
- **PDP automático** (`ux_autopdp`), **barra de ruta** (`ux_routebar`) y **barra del curso** (`ux_coursebar`):
  interruptores en *Administración del plugin*. Retroceder = apagar, nunca bajar la versión del código.
- El HTML adicional lleva marcas `chatbot-m1:` en cada bloque: para quitar uno basta borrarlo en
  *Administración → Apariencia → HTML adicional*.

## Verificación sin base de datos

```bash
cd moodle/public/local/chatbot
for f in cli/verificar_*.php; do php $f; done   # verificar_puente y verificar_versionado necesitan un Moodle real
```

## Actualizar la copia del curso

Si el curso cambia en el servidor de origen (textos, recursos, etiquetas), se regenera la copia base, sin datos de
personas, y se reemplaza `deploy/curso/Modulo1_curso_base.mbz`:

```bash
php local/chatbot/cli/exportar_curso.php --curso=ID --destino=/tmp/Modulo1_curso_base.mbz
```

La copia trae las direcciones y los ids del servidor de origen y las actividades del recorrido con la finalización
apagada: `configurar_modulo1.php` lo corrige al restaurarla. El archivo pesa unos 3,5 MB; el hook anti-secretos
rechaza archivos de más de 5 MB.
