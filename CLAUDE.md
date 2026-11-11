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
  **Cierre por fecha** (v1.21.9, interruptor `aperturaruta` + ajuste `aperturafecha`): hasta esa fecha solo la
  Bienvenida y Perfilamiento; `course_bridge::closed_until()` manda en la ruta, las páginas (`require_open()`), el
  widget y las pestañas del curso (restricción «desde la fecha», `sync_opening()`). Quien ignora restricciones (admin,
  profesor con edición) lo ve abierto: para revisar, usar una cuenta de estudiante. Con `perfilm2` + `aperturam2`
  (v1.21.10), al terminar el perfil se matricula en el Módulo 2 y aparece «Ir al Módulo 2». Ambos se aplican con
  `configurar_modulo1.php --apertura=AAAA-MM-DD --modulo2=ID`.
- `classes/profile.php` — consentimiento versionado (`RU-CONS-002`):
  `has_consent()` compara contra `disclosure_version()` (hash de los 4
  antecedentes de transparencia) y fuerza reconsentimiento si cambian. **No
  debe tener excepción por rol** — ni siquiera para el admin del sitio; ya se
  quitó una vez un `if (is_siteadmin($userid)) return true;` que contradecía
  el requisito.
- `classes/kb_store.php` — versión del recurso citado y de la base de
  conocimiento (`RU-PRIV-006`), se guarda en cada interacción.
- `classes/exante.php` + `ficha_exante.php?courseid=ID` — **Módulo 2, Fase 1**: ficha de diagnóstico ex-ante
  (textual del documento del cliente) validada por la IA con el checklist de 33 ítems (versión del 30-10, 5 CRÍTICO:
  `exante::CRITICOS`). La condición final la fija `decide()` con la regla del cliente sobre los ítems, no la que
  propone el modelo: ❌ o ⚠ en un CRÍTICO → No aprobado (salvo diferencia de forma, `"forma": true`); ⚠/❌ en otros →
  con observaciones; todo ✔ → Aprobado (interruptor `reglacriticos`; apagado, la regla de motivos graves de v1.20.12).
  El ítem nuevo de la sección 1 es `c1_3` y va segundo: no renumerar ids, los informes guardados los usan. Con
  Aprobado / Aprobado con observaciones aparece el botón a la sección «Fase 2…» del curso. No es calificación.
  Interruptor `fichaexante`. La **librería del Asistente de Diseño Instruccional** (ámbito `m2` de la base de
  conocimiento) son los siete documentos de `deploy/libreria_m2/*.md` (checklist, ficha, guía, ejemplo de llenado, MCTP
  2017, Manual EDECOMP 2021 y Guía de Planes Formativos, transcritos), cargados con `cli/cargar_libreria.php --dir=...`
  (idempotente, `--simular`): no por el panel, cuya extracción con IA puede fallar por `RECITATION`. La carpeta no viaja
  con el plugin: se sube aparte. `classes/kb_router.php` enruta sus secciones según las marcas `{ruta: ...}` y
  `{nivel: 1..5}` de los títulos y coloca la librería al inicio del pedido (`exante::request_parts()`, interruptor
  `libreriaruta`; la etapa 7 no usa librería).
  `cli/configurar_modulo2.php --curso=ID` deja la pestaña Fase 1 del curso como la pidió el cliente (enlaces a
  los materiales y al checklist, recursos ocultos, condición centrada, botón a la ficha), pone el botón de la
  Fase 2 al final de esa pestaña y quita el recuadro del tablero que antes iba bajo el botón de la Fase 3 (el cliente pidió sacarlo el 02-11); idempotente. **Correrlo de nuevo después de restaurar el curso** (una
  restauración del 21-10 borró los ajustes).
- `classes/diseno.php` + `diseno.php?courseid=ID&etapa=N` — **Módulo 2, Fase 2**: las 6 etapas del Prompt
  Maestro como hojas de trabajo (propuesta de la IA, edición, ajustes, aceptación, Word). Requiere la ficha
  validada y vigente (`exante::fase1_ok()`); no se salta una etapa sin aceptar la anterior. Interruptor `disenofase2`.
  **Fase 3** en la misma página (`diseno_fase3.php`): etapa 7 consolida las 6 etapas sin editar (se ajusta en la
  Fase 2) y etapa 8 evalúa con la rúbrica del cliente (`diseno::rubrica()`, textual, versión del 30-10 con sus pautas de aplicación; también en la librería como `08_rubrica_validacion_diseno.md`); total y banda los calcula el
  código, aprobado desde 25/36; con aprobación se descarga el documento final. Cambiar una etapa invalida 7 y 8. Toda etapa cierra con «Registro de vacíos…», «Verificación de calidad de la etapa» y «Referencias consultadas» (prompt v11). Desde la v12 (ajuste de Interfases del 10-11, interruptor `controlcambios`) el prompt lleva textuales los tres bloques del «Control de Cambios entre Etapas» (`diseno::BLOQUE_*`): un ajuste que afecta etapas aceptadas se anota en la sección «Registro de ajustes» del producto (la página avisa las filas «Pendiente de actualizar» en la etapa de origen y en cada etapa afectada hasta que esta se reacepta, `pending_changes()`), el asistente ve también las etapas siguientes aceptadas, la consolidación cierra con el anexo «Verificación de consistencia entre etapas y registro de ajustes» (la página avisa las filas «Inconsistente», `inconsistencies()`) y la etapa 8 considera las inconsistencias aceptadas; `diseno::missing_items()` revisa que el producto traiga todo lo que pide su etapa (interruptor `revisioncontenido`) y `complete_consolidation()` repone en la consolidación del asistente los vacíos y la verificación que omitió, copiándolos de cada etapa.
  En la etapa 8, los criterios vinculados a secciones del checklist con ⚠/❌ en la validación vigente tienen tope 3:
  el vínculo sección → criterio es `diseno::rubric_links()` y el tope lo aplica el código (`rubric_limits()`), no el modelo.
- `classes/diagrama.php` — diagramas de cajas y flechas de los productos de las etapas: el modelo entrega un bloque
  «diagrama» (una línea por caja, `modo: ciclo` opcional) y se convierte en tabla con colores (editable y válida en Word);
  los dibujos de caracteres (│ ─ ▼) se vuelven lista. Se aplica en `diseno::product_html()` y en `html_to_docx()`.
  Para el documento final, `art_table_html()` rehace como tabla una tabla dibujada con cajas y `tree_items()` lee un árbol
  (├── └──) con sus niveles.
- `classes/documento_final.php` — **documento final de la Fase 3** con el diseño del ejemplo del cliente («Diseño Final
  Validado - Ejemplo - Interfases»): portada con logo (`pix/logo_interfases.png`, distinto del ícono
  `interfases_logo.png`) y datos de `diseno::final_cover()`, índice con PAGEREF, encabezado y pie, una etapa por página,
  tablas anchas en secciones horizontales. Arma el OOXML a mano desde el HTML de la etapa 7 + el anexo de la etapa 8; lo
  llama la acción `final` de `diseno.php` (interruptor `docfinal`; apagado, `diseno::html_to_docx()`). El Word de cada etapa usa el mismo generador sin portada ni índice (`portada => false`, acción `word`, interruptor `docetapa`), y todo producto pasa antes por `diseno::normalize_product()` (fórmulas LaTeX a texto, bloques preformateados a HTML, tablas ASCII guardadas como viñetas reparadas). Adapta la
  estructura real del consolidado («PARTE N» → «ETAPA N», títulos en mayúsculas, «1.» → «N.1») y los bloques
  preformateados (tablas y árboles dibujados, encabezados de instrumentos como ficha). Revisarlo **abriendo el Word**
  (exportar a PDF con Word por COM y mirar las páginas): así se encontraron filas altas que dejaban media página en
  blanco y títulos solos al pie de página, que el XML no muestra.
- `classes/tablero_m2.php` + `tablero_m2.php?courseid=ID` — **tablero del Módulo 2** (propuesta de Interfases v3): vistas
  «Mi avance» (la persona, solo sus fichas, con los 6 avisos y la encuesta de cierre), «Programa» (`viewanalytics`,
  agregados A–F y H, sin nombres) y «Calidad del agente» (`managekb`, revisión experta de una muestra, indicador G). El
  cálculo es puro (`facts()` por ficha → `program()`, `agent()`, `notices()`); los avisos salen por la tarea
  `send_m2_notices` (proveedor `avisom2`). Datos propios: envíos al control de entrada (`m2_envio`, se registran en
  `ficha_exante.php`), revisión experta, encuesta y `diseno.rondas`. Interruptores `tablerom2` y `avisosm2`; plazos «X»
  en los ajustes `m2plazocorreccion` y `m2plazodetenido`.
- `classes/perfiles_m2.php` + `perfiles_m2.php` — **«Panorama integral»** (v1.21.8 en adelante): cruza el perfil del Módulo 1 con
  el uso del Módulo 2 (dimensiones, sectores, ruta por módulos, diferencias entre grupos). Es de solo lectura: **no escribe en
  ninguna tabla** y se calcula en cada carga, así que sigue los datos si cambian. Lo estadístico es puro (`aggregate()`, `cross()`,
  `compare()`, `solid()`: una diferencia es «sólida» solo con prueba de dos proporciones o Welch al 95 %); mínimo `MINIMO = 3`
  personas por grupo y `MINIMO_CONCLUSION = 5` para llamarla conclusión; «otro», «nr» y «sin perfil» no entran en las
  comparaciones. `demo(90)` genera una cohorte ficticia para el modo demostración: **la cohorte de prueba solo existe en el VPS
  propio, nunca en el del cliente**. Botón flotante sobre el de informes (`local_chatbot_get_profiles_button_html()`). Interruptor
  `perfilesm2`. Verificador `verificar_perfiles_m2.php` (169 casos).
- `classes/implementacion.php` + `ficha_implementacion.php?courseid=ID` — **Módulo 3, Fase 1** (v1.22.0): ficha de registro de la
  implementación, textual de los documentos de Interfases (`Documentacion modulo 3/`), validada por la IA con el checklist de 34
  ítems en 8 secciones (8 CRÍTICO: `implementacion::CRITICOS`). Mismo patrón que `exante`, con dos diferencias. (1) La persona **elige
  uno de sus diseños terminados del Módulo 2** (`list_designs()` / `get_design()`: seis etapas aceptadas y plan formativo adjunto):
  de ahí salen el diseño, el plan y el perfil, y no se cargan de nuevo. (2) Los ítems aritméticos los resuelve el código, no el
  modelo (`POR_CALCULO`: c1_3, c1_4, c4_1, c4_3, c4_4; `system_checks()` y `apply_system_checks()`: presentes por criterio, tiempos
  contra el diseño, suma por nivel, logro %). `decide()` aplica la regla de los CRÍTICO; tiempo restante = total − utilizado. La huella
  de la validación incluye ficha y diseño (`fingerprint()`): cambiar cualquiera la deja «desactualizada» y cierra la Fase 2. No
  renumerar ids de ítems (los informes guardados los usan). Interruptores `fichaimpl`, `bienvenidam3` y `avisosimpl`.
- `classes/calidad.php` + `calidad.php?courseid=ID&etapa=N` — **Módulo 3, Fases 2 y 3**: el Prompt Maestro del Módulo 3 como hojas
  de trabajo (propuesta de la IA, edición, ajustes, aceptación, Word): etapas 1 a 3 en la Fase 2 y 4 a 8 en la Fase 3. Requiere la
  ficha validada y vigente (`implementacion::fase1_ok()`) y no se salta una etapa sin aceptar la anterior. Interruptor `calidadfase2`.
  `calidad::analysis()` **calcula** lo medible y se lo entrega al modelo sin que lo recalcule: logro por criterio ((Adecuado +
  Avanzado) / presentes), promedio, cobertura evaluativa, criticidad (<30 alta, 30–<60 media, 60–<90 baja, ≥90 sin brecha), personas
  a nivelar y a profundizar y la **decisión mínima** (acciones remediales / rediseño parcial / rediseño total; prevalece la más
  exigente). El modelo declara su decisión con la marca `[[AJUSTE: ...]]` y **no puede ser menos exigente** que ese mínimo
  (`decision_warning()`, `decision_valid()`, `allowed_adjustments()`; `decided_adjustment()` da null si es menor): la etapa 3 no se
  acepta sin un ajuste válido, que la persona puede elegir o agravar con `set_adjustment()` pero no bajar. La etapa 4 depende del
  ajuste (`stage_def()`) y la suma de su tabla «Distribución del tiempo restante» se revisa contra el tiempo restante
  (`time_check()`). La etapa 8 consolida la Etapa 0 y las etapas 1 a 7 en Word (`consolidate()`, `final_cover()`; interruptor
  `consolidacionm3`; sin librería).
- `classes/modulo3_setup.php` + `cli/configurar_modulo3.php --curso=ID [--simular]` — **botones del curso del Módulo 3** (pedido del
  cliente del 06-11): al final de cada una de sus cuatro hojas, ubicadas por el nombre de la sección y no por id, una etiqueta con
  el botón y el texto exacto del cliente. «Ir a la Fase 1: Registro y Validación» → pestaña Fase 1 del curso; «Completar y validar
  la ficha de implementación» → `ficha_implementacion.php`; «Acceder al asistente IA» → `calidad.php`; «Ir al ajuste de la
  experiencia de aprendizaje - Fase 3» → `calidad.php?etapa=4`; «Ir a evaluación ex-post» (hoja «Fase 4: …», desde v1.23.0) → `ficha_expost.php`. Idempotente: reconoce su etiqueta por el comentario
  `<!-- BOTON M3 ... -->` y, si la hoja ya trae un botón del cliente con ese texto, enlaza ese en vez de agregar otro. El contenido de
  las hojas es del cliente y el guion no lo toca. **Correrlo de nuevo después de restaurar el curso.** `classes/ruta_m3.php` es la
  barra de las cuatro fases. La **librería del Asistente de Aseguramiento de la Calidad** (ámbito `m3` de la base de conocimiento)
  son los siete documentos de `deploy/libreria_m3/*.md`: el checklist y la ficha salen de las clases (si se cambia el código o el
  archivo, `verificar_libreria_m3.php` avisa del desfase), la guía y el ejemplo de llenado están transcritos de los PDF del cliente y
  los tres de referencia son los del Módulo 2 con las marcas de ruta reasignadas. Se enruta por tarea (`kb_router::TAREAS_M3`: `m3f1`
  y `m3e1`…`m3e7`) dentro del presupuesto de 220 mil caracteres por pedido y se carga con
  `cli/cargar_libreria.php --dir=... --ambito=m3` (idempotente, `--simular`); interruptor `libreriam3`. Guía de instalación y de
  prueba: `docs/GUIA_INSTALAR_MODULO3.md`. Lo que **no** hay, porque no se pidió: tablero del Módulo 3 ni autorrevisión del checklist
  en pantalla.
- `classes/expost.php` + `ficha_expost.php?courseid=ID` + `expost.php?courseid=ID&etapa=N` — **Módulo 3, Fase 4: evaluación ex post** (v1.23.0, prompt
  complementario y documentos de Interfases del 07-11): cierra el ciclo con las etapas 9 a 15. **Activación** (`activation()`): Fases 1 a 3
  completas y consolidadas (`load()['fases']`: etapa 8 vigente) y experiencia de continuidad confirmada por la persona; si cambia una etapa
  anterior la Fase 4 se cierra. **Etapa 9:** `preload()` (puro) arma identificación, tabla 2.1 (todos los criterios del diseño con logro inicial;
  pendientes en blanco, cruzando 2.1 de la ficha con el diseño por `match_criteria()`) y pendientes; las etiquetas de 3.1, 3.2 y 6.4 las prepara
  la IA (`build_labels_prompt()`, `parse_labels()` impone máx. 10 palabras y 4 por tabla). `refresh_preload()` rehace la precarga si cambia su
  huella. **Etapa 10:** ficha de 7 secciones (`sections()`, `field_keys()`; las tablas 2.1, 2.2, 3.1, 3.2 y 6.4 se arman con la precarga) y
  checklist de 25 ítems en 8 secciones (3 CRÍTICO: `c2_1` suma por nivel, `c2_2` no regresión, `c8_1` coherencia global). Mismo patrón que
  `implementacion`: `missing_inputs()` es el control de entrada; `system_checks()` resuelve por cálculo (`POR_CALCULO`: c1_2, c2_1 a c2_4, c5_2) y
  manda sobre el modelo; `decide()` aplica la regla del cliente (un resultado desfavorable no rechaza la ficha). La validación guarda un
  `snapshot` con ficha, precarga y etiquetas: las etapas 11 a 15 usan esa versión. **Etapas 11 a 14** como hojas de trabajo sobre
  `local_chatbot_calidad` (etapas 11 a 15; `calidad::run()` despacha a `expost::generate()` con `is_phase4()`): `results()` calcula logro
  inicial y final, variación (pp), promedios, cobertura evaluativa y movimientos de nivel y se entrega al modelo (`results_text()`); nivelación
  = aumento de personas en Adecuado o Avanzado sobre las que estaban en Insuficiente o Básico y profundización = aumento en Avanzado: son
  **estimaciones con los totales por nivel** (la ficha no tiene a cada persona). **Etapa 15:** consolidación (partes 10 a 14, huella
  `stages_fingerprint()`). Pendientes «Parcialmente» o «No» quedan sin niveles (`normalize_pending()`). Tablas `local_chatbot_expost`
  (una por ficha de implementación; `delete_ficha()` la borra) y `local_chatbot_expost_val`. Interruptor `fichaexpost`. La librería de la Fase 4
  son `deploy/libreria_m3/08` a `11` (`08` y `09` salen del código: `verificar_libreria_m3.php` avisa del desfase), tareas `m3x9` a `m3x14`.
  `verificar_expost.php` prueba con el caso del ejemplo del cliente. El quinto botón de `modulo3_setup` es «Ir a evaluación ex-post»
  (`ficha_expost.php`), en la hoja «Fase 4: …».
- `cli/preparar_curso.php` — crea las actividades del puente en un curso.
  El campo correcto para el código de actividad en `create_module()` es
  **`cmidnumber`**, no `idnumber` (ese se ignora en silencio).
- `cli/verificar_*.php` — la suite de pruebas del proyecto (no hay PHPUnit
  configurado). Correr todos antes de dar por cerrado cualquier cambio:
  ```bash
  for f in local/chatbot/cli/verificar_*.php; do php $f; done
  ```
  Deben pasar sin fallos. Al 10-11-2026 (M1-v1.24.6): **44 celdas de la matriz de acceso y 2006 claves de idioma**;
  `verificar_exante.php` 191 casos, `verificar_diseno.php` 257, `verificar_libreria_ruta.php` 84, `verificar_tablero.php` 99,
  `verificar_documento_final.php` 110 y, del Módulo 3, `verificar_implementacion.php` 129, `verificar_calidad.php` 114,
  `verificar_libreria_m3.php` 129, `verificar_modulo3.php` 26 y, de la Fase 4, `verificar_expost.php` 95, entre otros, `verificar_perfiles_m2.php` 169, en 28 scripts.
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

**Avisos de seguridad del sistema.** CVE = número de una falla conocida; «EXPLOTADA»/KEV = ya se usa en ataques
reales. Prioridad: explotadas, luego severidad alta, luego el resto. Se aplican con `dnf upgrade -y <paquete>` en cada
VPS (primero `--assumeno` para ver qué toca) y se comprueba que Nginx/PHP-FPM/MariaDB o los contenedores sigan arriba.

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
