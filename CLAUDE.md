# Asistente de IA — Módulo 1 (SENCE / Interfases Consultores)

Plugin de Moodle que agrega un asistente de IA (Tutor, Coach, Evaluador
formativo) al Módulo 1 de un programa de formación. Encargado por
**Interfases Consultores**, gobernado por sus documentos de requerimientos
(carpeta `Documentos Cliente/`) y por el `docs/Spec_Asistente_IA_para_IDE.md`
que este equipo redactó a partir de ellos.

**Regla que nunca se relaja:** el asistente no califica ni certifica. No
toca el libro de calificaciones de Moodle. Si una tarea pide que el
asistente ponga notas o certifique competencias, es un malentendido del
alcance — revisar `Documentos Cliente/0. Arquitectura del Módulo 1...docx`
antes de implementar nada así.

## Un solo repositorio

Esta copia trae todo junto: Moodle, infraestructura, documentos, herramientas y el plugin en
`moodle/public/local/chatbot/` como archivos normales, sin historial previo. Un `git push` basta. (En el desarrollo
original el plugin vive en su propio repo, `moodle-chatbot-ia`; aquí no.)

## Arquitectura del plugin

- `classes/assistant.php` — contexto y reglas de cada modo (Tutor/Coach/Evaluador).
- `classes/diagnostic.php` — instrumento de diagnóstico y matriz de brechas.
- `classes/pdp.php` — Plan Personal de Desarrollo: metas, decisiones.
- `classes/adaptive.php` — motor de apoyos adaptativos. `build_record()` es
  función pura (recibe el contexto ya armado, no consulta BD); `store_record()`
  arma ese contexto desde BD y llama a `build_record()`. Implementa el
  registro estructurado del Coach del Anexo F §10 (`RU-ADAPT-007`).
- `classes/trace.php` — códigos y eventos de trazabilidad del **Anexo G**.
  Antes de emitir un evento nuevo, comprobar que el código exista en el
  catálogo del Anexo G — se emitió una vez `EVT-U1-QF-VIEW` inventado, que
  no estaba en el catálogo (el quiz solo tiene START/SUBMIT/REVIEW/COMPLETE).
- `classes/course_bridge.php` — puente entre el asistente y el curso de
  Moodle. Las cinco actividades del recorrido (`M1-PASO-PERFIL`,
  `M1-PASO-DIAG`, `M1-PASO-PDP`, `M1-PASO-PROY`, `M1-PASO-CIERRE`) se crean con
  `cli/preparar_curso.php` y se autocompletan cuando la persona termina ese
  paso en el asistente — no hay que marcarlas a mano. Los códigos son los de
  `course_bridge.php`: dos van abreviados (`-DIAG` y `-PROY`, no
  `-DIAGNOSTICO` ni `-PROYECTO`), y buscar los largos da un falso faltante.
  Desde v1.16.0 la ruta es de **6 etapas** (interruptor `route6`, ajustes del
  cliente del 08-10): la Ruta Formativa Base no tiene actividad del puente (son
  los recursos `U1-LF/CA/ER/QF` de su sección) y el PDP es opcional, así que no
  entra en los criterios de finalización. **`course_bridge::route_model()` es la
  única fuente de la ruta** (barra, barra del curso, widget, próximo paso, lista
  de la página de Perfil, contexto del Tutor): no armar listas de pasos a mano —
  la de Perfil y la exigencia del PDP en `proyecto.php` quedaron con la ruta vieja
  y solo se vieron al probar en el navegador. Los nombres de las etapas son los
  del documento del cliente, textuales (`journey::NAMES6` y `stage6_*`, fijados
  en `verificar_ruta.php`): no acortarlos, porque el cliente pide que la barra
  tenga «la misma estructura».
- `classes/profile.php` — consentimiento versionado (`RU-CONS-002`):
  `has_consent()` compara contra `disclosure_version()` (hash de los 4
  antecedentes de transparencia) y fuerza reconsentimiento si cambian. **No
  debe tener excepción por rol** — ni siquiera para el admin del sitio; ya se
  quitó una vez un `if (is_siteadmin($userid)) return true;` que contradecía
  el requisito.
- `classes/kb_store.php` — versión del recurso citado y de la base de
  conocimiento (`RU-PRIV-006`), se guarda en cada interacción.
- `classes/exante.php` + `ficha_exante.php?courseid=ID` — **Módulo 2, Fase 1**: ficha de diagnóstico ex-ante
  (textual del documento del cliente) validada por la IA con el checklist de 32 ítems. La condición final la
  fija `decide()` (la más estricta entre modelo y regla); con Aprobado / Aprobado con observaciones aparece el
  botón a la sección «Fase 2…» del curso. No es calificación. Interruptor `fichaexante`.
  `cli/configurar_modulo2.php --curso=ID` deja la pestaña Fase 1 del curso como la pidió el cliente (enlaces a
  los materiales y al checklist, recursos ocultos, condición centrada, botón a la ficha) y pone el botón de la
  Fase 2 al final de esa pestaña; idempotente. **Correrlo de nuevo después de restaurar el curso** (una
  restauración del 21-10 borró los ajustes).
- `classes/diseno.php` + `diseno.php?courseid=ID&etapa=N` — **Módulo 2, Fase 2**: las 6 etapas del Prompt
  Maestro como hojas de trabajo (propuesta de la IA, edición, ajustes, aceptación, Word). Requiere la ficha
  validada y vigente (`exante::fase1_ok()`); no se salta una etapa sin aceptar la anterior. Interruptor `disenofase2`.
  **Fase 3** en la misma página (`diseno_fase3.php`): etapa 7 consolida las 6 etapas sin editar (se ajusta en la
  Fase 2) y etapa 8 evalúa con la rúbrica del cliente (`diseno::rubrica()`, textual); total y banda los calcula el
  código, aprobado desde 25/36; con aprobación se descarga el documento final. Cambiar una etapa invalida 7 y 8.
- `cli/preparar_curso.php` — crea las actividades del puente en un curso.
  El campo correcto para el código de actividad en `create_module()` es
  **`cmidnumber`**, no `idnumber` (ese se ignora en silencio).
- `cli/verificar_*.php` — la suite de pruebas del proyecto (no hay PHPUnit
  configurado). Correr todos antes de dar por cerrado cualquier cambio:
  ```bash
  for f in local/chatbot/cli/verificar_*.php; do php $f; done
  ```
  Deben pasar sin fallos. Al 21-10-2026 (M1-v1.19.0): **984 casos, 44 celdas
  de la matriz de acceso y 1036 claves de idioma** en 19 scripts.
  `verificar_puente.php` y `verificar_versionado.php` necesitan base de datos:
  solo corren en el servidor. `verificar_idiomas.php` compara `lang/en` con `lang/es`:
  toda cadena nueva va en **los dos**, porque Moodle arma las claves desde el
  inglés y una cadena solo en español se muestra como `[[clave]]`.
- `cli/configurar_modulo1.php` + `classes/site_setup.php` — configuración del Módulo 1 que el plugin no trae en su código
  (ajustes, HTML adicional del sitio, `allowstealth`, curso sin «Avisos», enlaces reescritos al sitio/curso de destino y
  finalización de las actividades del recorrido). Idempotente, con `--simular`. Va junto con la copia base del curso
  (`deploy/curso/Modulo1_curso_base.mbz`, sin datos de personas; se regenera con `cli/exportar_curso.php`). Guía:
  `docs/GUIA_INSTALAR_MODULO1.md`. **Todo cambio de configuración manual en un servidor debe pasar también por este
  guion**, o el siguiente servidor no lo tendrá.
- `classes/ux.php` — **interruptores de las mejoras de experiencia** (v1.11 en
  adelante): ruta, guía del PDP, orden de metas, próximo paso, ruta en el curso,
  respuestas en vivo, etc. Se apagan en la administración del plugin sin
  desplegar nada. Es **la** forma de retroceder una mejora: bajar la versión del
  código no se puede con la base ya migrada. Toda mejora visible nueva lleva su
  interruptor en `ux::DEFAULTS`.
- **Revisión visual**: con Playwright y el Edge instalado (`channel="msedge"`, sin
  descargar navegadores) se pueden tomar capturas reales de escritorio y celular
  iniciando sesión con las cuentas de prueba. Verificar el HTML no alcanza: así se
  encontraron la barra de ruta que ocupaba media pantalla en celular y el choque
  del recorrido guiado con el recorrido propio de Moodle en la portada del curso.
  Ojo con los botones dentro de una alerta: el tema tiñe todo enlace de `.alert-*`
  (incluido un `.btn-primary`) y deja el texto café o gris sobre azul. Para un botón
  dentro de un aviso, usar una clase propia con colores explícitos (`.chatbot-gatebtn`).

### Gotcha recurrente: `$PAGE` sin `__isset`

`moodle_page` define `__get` pero **no** `__isset`: `isset($PAGE->url)` da
siempre falso y `empty($PAGE->cm)` siempre verdadero, sin error ni aviso. Ya
causó tres fallas silenciosas: el widget nunca supo en qué página estaba (abría
siempre en Tutor), las reglas de la fase de diagnóstico (`RU-DIAG-002`) solo se
aplicaban en modo Coach, y el recurso de origen del Anexo E §11 nunca se
registró. Usar `$PAGE->has_set_url()` para la URL y leer `$PAGE->cm` directo
(devuelve null fuera de una actividad).

### Gotcha recurrente: `SITEID`

`SITEID` en Moodle es un **string `'1'`**, no un entero. Una comparación
`$courseid === SITEID` nunca se cumple si `$courseid` viene de la BD como
int — usar `(int) $courseid === (int) SITEID`. Este bug ya causó que el
widget tomara datos de "curso 1" en vez del recorrido general (`courseid=0`)
y obligó a migrar filas en producción. Si algo del asistente actúa raro
"fuera de un curso", sospechar primero de esta comparación.

## Códigos del Anexo G

El cliente identifica cada recurso de una unidad con un código fijo
(`U1-LF-01` lectura, `U1-CA-01` caso, `U1-ER-01` ejemplo, `U1-QF-01` quiz,
`U1-QF-01-P01`…`P10` preguntas). Estos van en el campo **"Número de
identificación"** de la actividad en Moodle. Sin ese código, el asistente
funciona pero esa actividad no genera eventos de trazabilidad Anexo G. Al
tocar algo de trazabilidad, el catálogo completo de 25 códigos está en
`Documentos Cliente/.../6.7. ... Anexo G ....docx` y replicado en
`docs/Spec_Asistente_IA_para_IDE.md`.

## Fuente de verdad para requisitos

`docs/Spec_Asistente_IA_para_IDE.md` — 111 requisitos, cada uno con RU
(Requerimiento de Usuario), RS (Requerimiento de Sistema), redacción EARS,
caso de prueba y **estado de implementación** (verificado / implementado /
parcial / bloqueado). Se regenera con:

```bash
python tools/matriz/generar_matriz_v5.py   # SPEC + matriz Word/Excel
python tools/manual/generar_manual.py      # manual de facilitadores
```

Los datos fuente están en `tools/matriz/datos_v5.py` (diccionarios `RU` y
`EST`) — se edita ahí, nunca a mano en el `.md` generado. `docs/ESTADO_TRABAJO.md`
es el checkpoint de trabajo en curso: revisar su fecha antes de asumir que
está al día, porque se actualiza a mano en cada sesión y puede quedar atrás
de lo que ya se hizo.

Si vas a implementar algo nuevo, parte por el SPEC: cita el requisito
(`RU-TUT-007`) en el commit y en el verificador que le corresponda.

## Los dos VPS

Hay un VPS propio y un VPS del cliente, y **deben mantenerse en la misma
versión de plugin y de Moodle** (5.2.2 al momento de este documento), con
el mismo instrumento de diagnóstico cargado y los mismos duplicados
ocultados en el curso. Los datos de conexión (IP, puerto, usuario,
contraseña) están **solo** en `tools/operacion/deploy_vps.py`, que está en `.gitignore` a
propósito — nunca lo comitees, nunca lo subas a un Drive compartido, nunca
lo pegues en un documento que salga del equipo. Si hace falta compartir el
proceso de despliegue con alguien externo, describe el patrón (respaldo →
subir → `php -l` → `admin/cli/upgrade.php --non-interactive` → purgar
cachés → correr verificadores → comprobar HTTP) sin los valores reales.

Los demás scripts de operación (`tools/operacion/`: `apply_hardening.py`,
`audit_security.py`, `check_courses_status.py`, etc.) también están en
`.gitignore` por la misma razón: llevan o pueden llevar credenciales del
servidor en texto plano.

Patrón de despliegue seguro ya probado: activar modo mantenimiento, leer la
versión esperada del propio `version.php` local con regex (no hardcodearla
— una versión vieja hardcodeada ya hizo revertir una instalación correcta
dándola por fallida), instalar, y si la versión en base no calza con la
esperada, revertir automáticamente. Nunca borrar una carpeta montada de
Docker para revertir — reemplazar su contenido con rsync/copia; borrar el
punto de montaje ya rompió el bind mount una vez y tumbó el sitio.

**Respaldos fuera del directorio web.** Una copia `chatbot.bak-*` dentro de
`public/local/` queda servida por el sitio y Moodle la tiene delante al escanear
plugins. Van en `/opt/moodle_project/respaldos_plugin/` (VPS propio) y
`/root/respaldos_plugin/` (VPS del cliente). Tampoco se revierte el código
después de correr `upgrade.php`: dejaría la base por delante y Moodle se negaría
a arrancar. Por eso los verificadores (puros, no tocan la BD) se corren **antes**
de migrar. Antes de correrlos hay que purgar **solo la caché de idiomas**
(`admin/cli/purge_caches.php --lang`, no toca la base). Si no se purga, una cadena
nueva sale como `[[clave]]` y `verificar_puente.php` falla. Eso revirtió el primer
despliegue de la v1.16.4 al cliente el 09-10, que venía de la v1.14.0 y no tenía
las cadenas `stage6_*`.

**Estado real de `tools/desplegar/desplegar_plugin.py`.** No se ha corrido nunca
con datos reales: su `servidores.json` es la plantilla sin completar. Además, en
modo `docker` sube por SFTP a la ruta *del contenedor* (que en el host no existe)
y corre `php -l` en el host, donde no hay PHP. Revisarlo y probarlo antes de
confiar en él. El 29-09 se desplegó con scripts propios que no quedaron
versionados.

Los dos servidores tienen el plugin idéntico al repo en contenido (verificado
archivo por archivo el 29-09). El VPS propio conserva CRLF en unos 44 archivos,
que no cambia nada; el del cliente, LF.

## Credenciales y harness del repo

- **Nunca** una clave literal en un archivo versionado: variables de entorno y plantillas `*.example`
  (`.env.example`, `deploy/.env.example`, `deploy/config.php.example`, `moodle/config.php.example`).
  `config.php`, `.env` y todo `tools/operacion/` están en `.gitignore`.
- **Hook pre-commit** (`tools/hooks/pre-commit`, se instala con `sh tools/hooks/instalar.sh`): bloquea secretos, claves privadas, `.env`/`config.php`, volcados y archivos de más de
  5 MB, y nunca imprime el valor encontrado. `git commit --no-verify` solo para un falso positivo comprobado.
- **CI**: corre `tools/hooks/escanear_arbol.sh`, compila `tools/` y ejecuta los verificadores del plugin.

## Convenciones de código

La lógica que se puede probar sin base de datos vive en **funciones puras**
(reciben todo lo que necesitan como parámetro, no consultan `$DB`), y cada
verificador en `cli/verificar_*.php` las ejercita directamente. Si agregas
lógica nueva, separa el cálculo puro de la consulta a BD siguiendo ese
mismo patrón — es lo que permite tener 500+ casos de prueba sin PHPUnit ni
un Moodle de pruebas levantado.

## Idioma

Todo el trabajo de este proyecto es en **español** — commits, documentos,
comentarios de cara al cliente, esta guía. Nunca portugués, ni siquiera en
modos de respuesta comprimidos.

## Qué falta y no es código

HTTPS en ambos servidores, tres de los cuatro datos de transparencia del
consentimiento (proveedor, región y canal de derechos; la conservación ya está —
dependen del cliente, y es el único incumplimiento conocido), la rúbrica
oficial del proyecto integrador, y accesibilidad (requiere prueba humana con
teclado/lector de pantalla). Las fuentes 7-9 de la base de conocimiento ya están
cargadas en ambos servidores desde el 29-09: no se pueden subir por el panel
(el proveedor de IA se niega a reproducirlas, `RECITATION`), se cargaron
extrayendo el texto aparte. Detalle completo y priorizado en
`docs/ESTADO_TRABAJO.md` y en la sección de estado de cada requisito del SPEC.
