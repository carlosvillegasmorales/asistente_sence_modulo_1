# Changelog

## M1-v1.20.6 (2026-10-29)

**La IA como apoyo real en el Módulo 2** (propuesta del 29-10: la validación castigaba donde no debía y era complaciente
donde debía ayudar). En los dos VPS desde el 29-10; el punto 14 (descarga final siempre disponible) queda
pendiente de la confirmación de Interfases.

Fase 1, validación de la ficha (`classes/exante.php`, prompt `M2-F1-checklist-v2`):
- **Solo se detiene por un motivo grave** (`exante::serious_reasons()`): el aprendizaje esperado no es un aprendizaje,
  los criterios no lo evalúan, no corresponden al plan formativo adjunto, o una contradicción de fondo. Para dejar
  «No aprobado» el modelo nombra el motivo y cita la ficha; el código lo comprueba (`valid_reason()`: código válido y
  cita presente en la ficha) y, si no se sostiene, queda «Aprobado con observaciones». Ni los ítems críticos ni el
  modelo lo dejan en «No aprobado» por sí solos (`decide()`).
- «Aprobado» se acepta aunque haya ítems «parcial»; con algún «no cumple» (sin motivo grave), «con observaciones».
- El prompt ya no castiga lo que no corresponde: la sección 9 no baja dos veces lo ya señalado; las observaciones
  vacías, las decisiones razonables sin explicar, el texto oficial del plan («clara», «respetuosa»), la sinceridad
  (3.3 «parcial» no baja c3_4) y no adjuntar plan o perfil no bajan ítems. Las casillas 4 y 5 de criterios vacías con 3
  o más registrados llegan como «no se usa».
- Cada ítem que no cumple trae una **propuesta** concreta («Cómo mejorarlo»). El resultado separa «Para avanzar debes
  corregir» (solo con motivo grave) de las sugerencias para enriquecer el diseño. Tono nuevo: «Puedes avanzar. Estas
  sugerencias te ayudarán en el diseño».
- **Avisos antes de validar**, sin IA y con reglas fijas (`form_warnings()`): 3.3 «parcial» con 8.2 «No», tiempo 2.3 mayor
  o igual que 2.2, «Otro» sin especificar. Con avisos, «Guardar y validar» guarda y los muestra antes de gastar la
  validación; «Validar de todos modos» valida igual.
- **Temperatura baja** (0,1) en la validación y en la evaluación: `ai_provider_interface::generate_content()` recibe
  `$temperature` (Gemini, OpenAI/Azure, Anthropic). Si el modelo no admite el parámetro, se repite sin él.

- Un motivo grave solo cuenta si el modelo propuso «No aprobado» y su cita está en la ficha tal como la vio el modelo
  (seudonimizada).
- **Interruptores** (`ux::DEFAULTS`, encendidos): `iaapoyo` (regla de motivos graves y, en la Fase 3, el 4 sin
  evidencia; apagado vuelve la regla anterior en el código, las instrucciones al modelo no cambian) y `avisosficha`
  (avisos antes de validar).

Fase 3, evaluación (`classes/diseno.php`, prompt `M2-F2-etapas-v2`): se evalúa con ojo crítico (diseño hecho con apoyo
de IA); un puntaje 4 sin evidencia citada en la justificación baja a 3 (`supported_score()`); se piden al menos 2
recomendaciones aunque el total sea 36 (se reintenta una vez); antes de dar un 4 se comprueban uno por uno los
elementos del descriptor del nivel 4. La rúbrica y los 32 ítems siguen textuales.

Probado con la IA real en el VPS propio (3 corridas por caso): ejemplo del cliente con y sin la observación 2.4 →
Aprobado las 6 veces; aprendizaje que es un tema, criterios sin relación y plan adjunto de otra materia → No aprobado
con su motivo las 9 veces; evaluación de un diseño real de la Fase 3 → 33 a 34 de 36 (antes 36 siempre).

## M1-v1.20.5 (2026-10-29)

**Fase 1: enlaces 4 y 5 en los materiales de apoyo** (Interfases, ajuste 1)

- Después de las tres tarjetas de materiales, dos tarjetas más con el mismo formato: «4 · Plan Formativo»
  (Catálogo Nacional de Planes Formativos, SENCE) y «5 · Perfil Ocupacional» (Catálogo de Perfiles Laborales,
  ChileValora), cada una con su botón «Buscar y descargar» al catálogo, que se abre en otra pestaña.
- `cli/configurar_modulo2.php` toma la URL de las actividades URL del curso («Busca y descarga aquí tu Plan
  Formativo» / «… el Perfil Ocupacional», ya configuradas) y arma las tarjetas con `modulo2_setup::add_catalog_cards()`;
  idempotente: si cambia la URL, rehace la tarjeta sin duplicarla. Sin la actividad URL, no agrega esa tarjeta.

## M1-v1.20.4 (2026-10-27)

**Borrar diseños** (Interfases: que se pueda borrar del registro un diseño que ya no se necesita o no quedó
satisfactorio, para que no se apilen; uno por uno, para quedarse con los que sirven)

- En «Tus diseños» de la ficha, cada diseño tiene su botón «Borrar», con confirmación. Borra solo ese diseño: su ficha,
  sus validaciones, sus etapas, sus adjuntos y su autorrevisión del checklist. Los demás no se tocan; no hay «borrar
  todos». Si era el que estaba en uso, pasa a usarse el más reciente de los que quedan (`exante::next_in_use()`), y
  si era el único, la ficha queda en blanco. No se borra mientras se está validando.
- Los indicadores del Módulo 2 dejan de contar el diseño borrado.

## M1-v1.20.3 (2026-10-27)

- `configurar_modulo2.php`: al final de la pestaña de bienvenida (debajo de «Prepárate para comenzar», en la última
  subsección, «Trabajo con el Asistente IA…») va el botón «Ir a la Fase 1: Diagnóstico y Validación», que abre la
  pestaña de la Fase 1 en su primera parte. La sección y el destino se buscan por el nombre «Fase 1», no por número;
  idempotente.

## M1-v1.20.2 (2026-10-26)

- En los cursos, quien cursa ya no ve la franja Curso / Participantes / Calificaciones / Actividades / Más. La
  conserva quien tiene `moodle/grade:viewall` en el curso: docentes con o sin edición (también el rol consultor),
  gestores y administración. Es solo visual (las páginas siguen abriéndose por su dirección) y va en el código del
  plugin, no en el HTML adicional del sitio, así que vale en cualquier servidor y curso. Interruptor `navestudiante`.

## M1-v1.20.1 (2026-10-26)

**Ficha ex-ante: sin la sección 9 y con observaciones opcionales**

- La ficha en línea queda con 8 secciones: se quitó la tabla «Validación» (fechas y responsables de la revisión, de la
  validación con el equipo y de la aprobación final). El checklist sigue con sus 9 secciones y 32 ítems; el asistente
  sabe que esa tabla no está en la ficha y no la reporta como faltante.
- Todas las observaciones son opcionales (2.4, las «Observaciones breves» de las secciones 3 a 7 y las observaciones
  finales): si traen información el asistente la considera; si vienen vacías llegan como «opcional, sin información» y
  no generan observaciones, debilidades ni sugerencias. En el formulario, 2.4 y las observaciones finales muestran
  «(opcional)».
- Migración: sin las celdas de la sección 9 cambia la huella de toda ficha; las validaciones hechas sobre la ficha tal
  como está se ponen al día para que no queden desactualizadas ni cierren la Fase 2. Los datos guardados no se tocan.
- `configurar_modulo2.php`: el botón «Consultar y descargar el checklist» de la pestaña Fase 1 vuelve a llevar al
  recurso del checklist (oculto en la página del curso), como los materiales. El checklist para marcar en Moodle
  (`checklist_exante.php`) sigue enlazado desde la ficha.

## M1-v1.20.0 (2026-10-26)

**Módulo 2 reutilizable** (Interfases, 26-10-2026: «este módulo debe poder ser utilizado repetidamente en distintos
diseños de experiencias»)

- Varios diseños por persona y curso: cada diseño es una ficha con su validación y sus ocho etapas. En la ficha, «Tus
  diseños» lista los diseños con su estado (fase, etapas aceptadas o «Completado y aprobado (35/36)»), marca el que
  está «En uso» y permite abrir otro, empezar uno en blanco o uno nuevo a partir de la ficha actual (copia datos y
  adjuntos, no la validación ni las etapas). Al aprobar el diseño, la página ofrece empezar otro.
- Ya no se queda atrapado en la etapa 8: sin etapa pedida, la página abre la primera sin aceptar de la Fase 2, o la
  etapa 1 si las seis están aceptadas; el punto «Fase 2» de la ruta lleva ahí, y con el diseño aprobado hay enlaces a
  cada etapa para revisarlo.
- Base: `activa` en `local_chatbot_exante` (sin índice único por persona y curso) y `fichaid` en
  `local_chatbot_diseno` (único por ficha y etapa); la migración asigna las etapas existentes a la ficha de cada
  persona. La autorrevisión del checklist y los indicadores del Módulo 2 pasan a contar por ficha.

## M1-v1.19.18 (2026-10-24)

- Recursos PDF incrustados: el visor del navegador se abre sin el panel de miniaturas y ajustado al ancho
  (`#navpanes=0&view=FitH`); con el visor alineado al encabezado, el panel se comía el espacio y la hoja quedaba
  cortada. Interruptor `pdfvisor`.

## M1-v1.19.17 (2026-10-24)

- Recursos PDF incrustados: el visor queda alineado con el encabezado de la página (830 px, centrado), con borde y
  alto cómodo; antes Moodle lo estiraba a todo el ancho y quedaba descuadrado. Aplica a todo PDF incrustado del sitio.
- `configurar_modulo2.php`: apaga «mostrar tipo» en los recursos del Módulo 2 (el recuadro que solo decía «PDF») y
  renombra sus archivos sin tildes, porque Moodle manda el nombre en bruto y el visor lo mostraba como «DiagnÃ³stico».

## M1-v1.19.16 (2026-10-24)

- `configurar_modulo2.php` sobre los textos rediseñados por el cliente: si la pestaña ya trae su propio botón con
  «#» («Acceder al asistente IA» en la Fase 2, «Revisar y descargar la rúbrica» en la Fase 3), se enlaza ese en vez de
  agregar otro; se quitan todos sus comentarios «… REEMPLAZA # …». Probado sobre el HTML real del curso 4 del cliente.

## M1-v1.19.15 (2026-10-23)

- Módulo 2, ficha ex-ante: no se valida con menos de 3 criterios de evaluación (Prompt Maestro: «No utilizar menos
  de 3 o más de 5 criterios»); antes bastaba uno. El aviso dice cuántos tiene la ficha, la ayuda lo anticipa y una
  validación anterior con menos de 3 tampoco abre la Fase 2. El máximo de 5 lo pone la ficha (cinco casillas).

## M1-v1.19.14 (2026-10-22)

- Indicadores: las pestañas pasan de texto gris a cuatro botones grandes, con el activo relleno de azul y una línea que dice
  para quién es cada una («Para quien gestiona», «Uso y calidad del asistente»…). Dos por fila en celular.

## M1-v1.19.13 (2026-10-22)

**«Lo más importante hoy»: dos conclusiones menos exageradas**

- La dimensión «más débil» solo se señala si está al menos 0,25 puntos (de 5) por debajo del promedio de las demás
  (`analytics::DIM_MIN_GAP`). Con promedios de 2,67 a 2,72 ya no se dice que una dimensión haya que reforzar.
- La acción de «personas sin actividad» deja de decir que son «el grupo con más riesgo de abandonar»: ahora dice
  «escribirles un recordatorio antes de que abandonen».
- Si nadie llegó a la etapa siguiente, la conclusión dice «ninguna ha llegado todavía» y no «solo 0 llegaron (100 % menos)».
- 4 casos nuevos en `verificar_experiencia.php`.

## M1-v1.19.12 (2026-10-22)

- Indicadores: el promedio de la conclusión «dimensión más débil» usa la coma decimal; el encabezado de la tabla de avance
  del informe PDF dice «Etapa».

## M1-v1.19.11 (2026-10-22)

**Indicadores que se entienden y dicen qué hacer**

- «Lo más importante hoy»: hasta 5 conclusiones con reglas fijas (sin IA) y la acción sugerida: personas sin actividad,
  etapa donde más se detienen, mayor caída, metas sin decidir, dimensión más débil y logros. `analytics::insights()`.
- Lenguaje simple: «¿Qué significa?» plegado en cada tarjeta, el Anexo G con su explicación en palabras simples, los
  eventos con su nombre («Consulta al tutor (Unidad 1)»; el código queda como detalle), los modos por para qué sirven y el
  promedio de brechas con el nombre del nivel.
- Cambio de la última semana en las cifras grandes («+2 esta semana»), «Mayor caída» marcada en el avance y gráficos
  semanales desde la primera semana con actividad.
- Informe PDF (`indicadores_informe.php`, `classes/dashboard_report.php`): conclusiones, cifras, avance, dónde se detienen,
  brechas y Módulo 2, solo datos agregados.
- Lista de personas detenidas con enlace para escribirles, detrás del ajuste `dashpeople` (apagado por defecto; la
  insignia de privacidad lo avisa). Nunca va al Excel ni al PDF.
- 24 casos nuevos en `verificar_experiencia.php`.

## M1-v1.19.10 (2026-10-22)

- Indicadores: se quita el aviso «Pocos datos», a pedido del usuario.

## M1-v1.19.9 (2026-10-22)

- Indicadores: la pestaña Módulo 2 queda vacía (sin error) en un servidor que aún no tiene el código o las tablas del
  Módulo 2. Permite llevar los indicadores al VPS del cliente antes del despliegue completo.

## M1-v1.19.8 (2026-10-22)

**Indicadores: pestañas por público, dónde se detienen, período, exportar y Módulo 2**

- Pestañas «Resumen» (gestión: avance, dónde se detienen, metas, brechas), «Asistente» (uso, interacciones, Anexo G,
  quiz), «Técnico» (eventos y salud del servicio) y «Módulo 2». Sin JavaScript se ve todo; la dirección recuerda la pestaña.
- Aviso de pocos datos cuando iniciaron menos de 10 personas.
- «Dónde se detienen las personas»: la primera etapa obligatoria que le falta a cada quien y cuántas llevan más de 7 días
  sin actividad (`analytics::route_stuck()`, `stuck_counts()` pura).
- Período de los gráficos semanales (4, 12, 26 o 52 semanas) y «Exportar a Excel» (CSV con «;» y tildes).
- Módulo 2: fichas ex-ante guardadas y validadas, condición final, ítems del checklist que más fallan (marcando los
  críticos), personas por etapa de diseño aceptada y evaluaciones con la rúbrica (`analytics::module2_overview()`,
  `top_failing()` pura).
- 6 casos nuevos en `verificar_experiencia.php`.

## M1-v1.19.7 (2026-10-22)

**Calidad del asistente (errores vistos en conversaciones reales del cliente, 15 y 16-10)**

- Diagnóstico (RU-DIAG-002): se distinguen los ítems declarativos de los situacionales. En un situacional el asistente
  ya no recuerda «responder según la práctica habitual» ni explica qué enfoque es más adecuado (eso era la respuesta);
  solo define los términos. El botón de ayuda de cada ítem avisa al asistente de qué tipo de pregunta se trata.
- Tutor con un apoyo adaptativo: ante «¿cómo sé que mi evidencia está lista?» responde primero con la evidencia y los
  criterios de revisión de esa actividad, no con una lista de la lectura.
- Precisión conceptual y de citas (`assistant::concepts_clause()`, Tutor, Coach y propuesta de apoyos): desempeño
  esperado frente a aprendizaje esperado con las definiciones de U1-LF-01 §3.7, y sin atribuir a un apartado listas o
  cantidades que no contiene. La consigna de un apoyo pide todo lo que evalúan sus criterios.
- 5 casos nuevos en `verificar_integridad_quiz.php`.

## M1-v1.19.6 (2026-10-22)

**Indicadores corregidos (cifras falsas o engañosas)**

- «Avance por componente» y «Personas en cada etapa» siguen la ruta vigente (6 etapas, con la Ruta Formativa Base y el PDP
  marcado opcional) y cuentan con el mismo criterio que la barra «Tu ruta» de cada persona (`course_bridge::step_states()`).
  Antes usaban cinco consultas propias con la ruta de 5 y podían pasar de 100 % (Diagnóstico 4 de 3 = 133 %).
- «Personas que iniciaron» = quienes tienen al menos una etapa hecha; ninguna etapa supera el 100 %.
- Metas del asistente: la tarjeta muestra primero cuántas se decidieron («2 de 9 · 7 sin decidir») y luego cuántas se
  mantienen de las decididas; antes un «100 %» sobre 2 de 9 escondía que 7 seguían sin decidir.
- `analytics::route_funnel()`, `route_steps_timeline()`, `funnel_counts()` y `cumulative_weeks()` (las dos últimas puras,
  con 5 casos en `verificar_experiencia.php`). `completion_funnel()` no cambia: la usa la exportación a la Suite.

## M1-v1.19.5 (2026-10-22)

- Indicadores: la lista de cursos sale de `closure::parse_course_ids()`, que ya existía; se quita el duplicado
  `ux::list_ids()` de la v1.19.3. Sin cambios de comportamiento. Permite llevar la página a servidores que aún no
  tienen la v1.19.x.

## M1-v1.19.4 (2026-10-22)

- Indicadores: cuando solo hay un curso en la lista («Asistente solo en estos cursos»), se quita el selector y el nombre
  completo del curso se muestra grande en el encabezado, con su propia tarjeta (24 px en celular: 20). Con varios cursos,
  el selector sigue igual.

## M1-v1.19.3 (2026-10-22)

- Indicadores (`indicadores.php`): con «Asistente solo en estos cursos» escrito, el selector «Ver datos de» lista solo
  esos cursos (sin «Recorrido general» ni cursos de prueba) y la página abre en el primero. Sin la lista, igual que
  antes. `ux::list_ids()` (función pura) con 3 casos nuevos en `verificar_experiencia.php`.

## M1-v1.19.2 (2026-10-21)

- Checklist de la Fase 1 aplicable en Moodle (`checklist_exante.php`): los 32 ítems con ✔ / ⚠ / ❌ para marcar y
  guardar (preferencia de la persona, por curso; no se envía a la IA), los críticos señalados, un resumen con la misma
  regla de decisión del asistente y, si la ficha ya se validó, lo que dijo el asistente en cada ítem. El PDF queda
  como descarga opcional. «Consultar y descargar el checklist» del curso y un enlace en la ficha llevan ahí.

## M1-v1.19.1 (2026-10-21)

- Barra «Tu ruta del Módulo 2» arriba de la ficha y de las páginas de etapas, con el estilo de la ruta del Módulo 1:
  las tres fases (✓ completa, «Estás aquí», 🔒 bloqueada, avance de etapas), un resumen y el próximo paso sugerido
  con su botón «Ir». `classes/ruta_m2.php` (modelo puro probado en `verificar_diseno.php`, 105 casos).

## M1-v1.19.0 (2026-10-21)

**Módulo 2, Fase 3: consolidación y evaluación del diseño** (pedido de Interfases del 21-10-2026; cierra el módulo)

- Etapa 7, consolidación: las seis etapas aceptadas de la Fase 2, tal cual y en orden, en un documento único. **No es
  editable**: para ajustar se vuelve a la etapa de la Fase 2 (hay enlaces a cada una), y entonces hay que confirmar de
  nuevo. Si una etapa cambia después de confirmar, la consolidación y su evaluación dejan de valer.
- Etapa 8, evaluación: la IA aplica la rúbrica de validación técnica del cliente (9 criterios, niveles 1 a 4,
  descriptores textuales): puntaje y justificación por criterio, fortalezas, debilidades y recomendaciones. El total
  y la interpretación los calcula el código con la escala de la rúbrica; se aprueba desde 25 puntos. Una respuesta
  sin los nueve criterios o con un puntaje fuera de 1 a 4 no se usa. Con el diseño aprobado se descarga el documento
  final en Word (diseño consolidado + anexo con la evaluación), titulado con el nombre de la experiencia.
- `configurar_modulo2.php`: la rúbrica queda como recurso oculto, enlazado desde la pestaña Fase 3, y al final de la
  pestaña el botón «Ir a la consolidación y evaluación del diseño · Fase 3».
- Campos `informe` y `huella` en `local_chatbot_diseno`; `cli/verificar_diseno.php` (94 casos).
- `configurar_modulo2.php` pasa a «incrustado» los recursos enlazados que estaban en ventana emergente: abiertos por
  enlace directo mostraban una página intermedia «Haga clic en … para ver el archivo».

## M1-v1.18.0 (2026-10-21)

**Módulo 2, Fase 2: hojas de trabajo del diseño en seis etapas** (pedido de Interfases del 21-10-2026; solo la parte
del asistente, no el formato de la pestaña)

- Página nueva `diseno.php?courseid=ID[&etapa=N]` para las etapas 1 a 6 del Prompt Maestro (análisis diagnóstico,
  diseño de la experiencia, secuencia instruccional, materiales, plan de evaluación, instrumentos). En cada una el
  asistente propone el producto a partir de la ficha validada, sus observaciones, los adjuntos y las etapas ya
  aceptadas; la persona lo edita (editor de Moodle), pide ajustes (el asistente rehace la etapa completa), lo acepta
  y lo descarga en Word (.docx real, con títulos, listas y tablas). Al final: «Regresar al curso» y «Avanzar a la
  etapa siguiente» (solo con la etapa aceptada; en la 6, a la Fase 3).
- Reglas del Prompt Maestro: no se entra sin la ficha validada y vigente (con el texto del prompt), no se salta una
  etapa sin aceptar la anterior, cada etapa pide exactamente lo que el prompt lista y cierra con una verificación de
  calidad; gobernanza (no inventar aprendizajes ni criterios, 3 a 5 criterios, juicios de valor, alineación
  constructiva, lenguaje inclusivo).
- `configurar_modulo2.php` agrega el botón «Ir al Asistente de Diseño · Fase 2» al final de la pestaña Fase 2 (en su
  última subsección).
- Tabla `local_chatbot_diseno`; interruptor `disenofase2`; `classes/diseno.php` y `cli/verificar_diseno.php` (57 casos).

## M1-v1.17.0 (2026-10-21)

**Módulo 2, Fase 1: ficha de diagnóstico ex-ante con validación** (pedido de Interfases del 21-10-2026, punto 4)

- Página nueva `ficha_exante.php?courseid=ID`: la ficha de diagnóstico del cliente (9 secciones, 24 preguntas
  cerradas, textos del documento), con la ayuda de la guía de llenado en cada pregunta, «Guardar borrador» y
  «Guardar y validar», y dos adjuntos opcionales (Plan Formativo y Perfil Ocupacional, PDF o .docx de hasta 10 MB).
- Al validar, el asistente aplica el checklist completo (32 ítems, Etapa 0 del Prompt Maestro) y el informe aparece en
  la misma página: a) resultado por ítem (✔ / ⚠ / ❌ con observación), b) condición y c) debilidades y propuestas de
  mejora. Con Aprobado o Aprobado con observaciones aparece «Continuar a la Fase 2: Diseño de la Experiencia de
  Aprendizaje» (la sección del curso que se llama «Fase 2…»); si se cambia la ficha después, hay que validar de nuevo.
- Control de entrada: sin aprendizaje esperado, un criterio y las secciones 4 y 5 no se llama al modelo y se dice qué
  falta. La condición final es la más estricta entre la del modelo y la regla del checklist: un ítem crítico
  (aprendizaje, criterios, coherencia general) que no se cumple no se aprueba. Un informe al que le falte un ítem no se
  usa. El nombre de la persona facilitadora no viaja al modelo.
- En celular, el resultado del checklist se apila (ítem, evaluación, observación) en vez de una tabla de tres
  columnas con desplazamiento lateral.
- La validación corre en una segunda llamada que la página dispara y consulta sola, para que un corte del servidor web
  con adjuntos grandes no pierda el informe. No es una calificación ni toca el libro de calificaciones.
- Tablas `local_chatbot_exante` y `local_chatbot_exante_val`; interruptor `fichaexante`; `classes/exante.php` y
  `cli/verificar_exante.php` (104 casos).
- Guion `cli/configurar_modulo2.php --curso=ID [--simular]` (puntos 1 a 3 del mismo pedido, más el botón del punto 4):
  los materiales de apoyo de la Fase 1 (Guía, Ficha de campo, Ejemplo) y «Consultar y descargar el checklist» enlazan
  a sus recursos, que quedan disponibles pero sin mostrarse en la página del curso; la condición enmarcada de los
  recuadros de resultado queda centrada; se quitan las notas provisionales del texto («reemplaza #…») y se agrega al
  final de «Completa y valida el formulario» el botón «Completar y validar la ficha de diagnóstico». Busca recursos y
  secciones por nombre y marcas del texto, no por id; idempotente. Parte pura en `classes/modulo2_setup.php`.

## M1-v1.16.16 (2026-10-20)

- `cli/configurar_modulo1.php` oculta la franja de navegación del curso (Curso, Participantes, Calificaciones,
  Actividades…) en el Módulo 1 para quien no puede editar el curso; quien administra conserva la franja. Es solo
  visual: no cambia permisos.

## M1-v1.16.15 (2026-10-20)

**Configuración del Módulo 1 reproducible**

- Guion `cli/configurar_modulo1.php --curso=ID [--siguientes=A,B] [--sin-sitio] [--simular]`: aplica en otro Moodle la
  configuración que el plugin no trae en su código: ajustes (asistente solo en el curso, PDP automático, sin
  barras de ruta, módulos siguientes), `allowstealth`, el HTML adicional del sitio (texto justificado, manito en las
  pestañas, subsecciones abiertas, salto directo de las URL del recorrido, sin botón manual de consentimiento y sin
  las tarjetas que el cliente pidió ocultar), curso sin «Avisos» y enlaces del asistente reescritos al sitio y curso
  de destino. Idempotente; reemplaza los bloques que se habían puesto a mano sin tocar los ajenos.
- Activa la finalización de las actividades del recorrido (`M1-PASO-*`) y del consentimiento, sin la cual el puente no
  registra ningún avance, y sincroniza a quien ya avanzó.
- `cli/exportar_curso.php`: copia del curso (.mbz) sin datos de personas, para regenerar la copia base.
- `classes/site_setup.php` con la parte pura y `cli/verificar_configuracion.php` con sus casos.

## M1-v1.16.14 (2026-10-20)

- «Asistente solo en estos cursos» ahora también oculta el asistente fuera de los cursos (Área personal,
  portada y páginas generales): con la lista escrita, solo se ve dentro de los cursos listados y en las
  páginas del asistente abiertas desde ellos, para todos los roles. Sin la lista, nada cambia.

## M1-v1.16.13 (2026-10-20)

- Ajuste nuevo «Asistente solo en estos cursos» (`chatonlycourses`): con ids escritos, el asistente y el
  botón de indicadores aparecen únicamente en esos cursos (el Módulo 1) y se ocultan en todos los demás,
  incluidos los que se creen después. Se combina con «Cursos sin asistente». Vacío: sin restricción.

## M1-v1.16.12 (2026-10-20)

**Cursos sin asistente**

- Ajuste nuevo «Cursos sin asistente» (`nochatcourses`): ids de curso, separados por coma, donde no
  aparecen ni el asistente ni el botón flotante de indicadores (por ejemplo, los Módulos 2 y 3). El
  servidor tampoco atiende las consultas que declaran ese curso. Vacío por defecto: nada cambia hasta
  que se complete. Fuera de los cursos (Área personal) el asistente se sigue viendo.

## M1-v1.16.11 (2026-10-17)

- La actividad del curso con la declaración de consentimiento (código `M1-CONSENTIMIENTO` en «Número de
  identificación») se marca completada sola cuando la persona acepta las condiciones en su ficha, junto
  con el paso del perfil. Una marca manual se revierte, como en las etapas.

## M1-v1.16.10 (2026-10-17)

**Ficha de perfil en ventana emergente** (revisión final de Bienvenida y Perfilamiento, 17-10-2026)

- Si la ficha se abre como ventana emergente desde el curso, se ocultan los botones «Regresar al curso»
  y «Tu perfil quedó listo» ofrece «Cerrar» junto a «Ir a la siguiente etapa». «Cerrar» cierra la
  ventana y recarga el curso, que ya muestra la ficha completada. Interruptor `perfilpopup`.

## M1-v1.16.9 (2026-10-16)

**Autodiagnóstico según el acta del 13-10-2026 (primera parte)**

- Escala de respuesta del acta: Nunca o casi nunca · Rara vez · A veces · Con frecuencia · Siempre o casi siempre.
- Texto instructivo en el encabezado: cómo navegar la página y modificar respuestas, con acceso directo al
  cuestionario cuando ya se respondió (interruptor `diagacta`).
- «Tus prioridades» en lugar de «Tus prioridades iniciales», también en las matrices ya guardadas.

## M1-v1.16.8 (2026-10-16)

**Perfilamiento según el acta de la reunión con Interfases del 13-10-2026**

- Las nueve preguntas del acta, con sus alternativas: rol (con «Otro» a especificar), tipo de programa,
  sector, modalidad, experiencia, familiaridad con el enfoque de competencias, curso donde aplicará lo
  aprendido, hasta dos aspectos a fortalecer y tiempo semanal. Las respuestas anteriores pasan a la
  alternativa vigente más próxima (por ejemplo, «Entre 3 y 10 años» a «Entre 4 y 7 años»).
- El Tutor, el Coach y los apoyos reciben el perfil en palabras, con los campos nuevos.
- Página de perfil (interruptor `perfilacta`): título «Tu perfil de persona facilitadora de aprendizajes»,
  sin «Comienza aquí» ni «Estado de tu recorrido», botón «Aceptar las condiciones de uso y guardar» y, al
  terminar, «Tu perfil quedó listo» con «Ir a la siguiente etapa».

## M1-v1.16.7 (2026-10-14)

- Con el PDP automático (`autopdp`), la matriz de desarrollo termina con dos botones: «Ir a la Ruta
  Formativa Base» e «Ir a mi PDP» (antes «Ver mi plan»).

## M1-v1.16.6 (2026-10-14)

**El diagnóstico arma el PDP solo, con versiones del plan** (interruptor `autopdp`, apagado por defecto)

- Al preparar o rehacer la matriz de desarrollo, sus prioridades pasan solas al PDP como propuestas,
  ajustadas al perfilamiento (tiempo disponible, sector, modalidad, tipo de programa y prioridades
  declaradas). El botón de la matriz pasa a ser «Ver mi plan».
- Cada cambio del diagnóstico rehace las metas del asistente, en cualquier estado, en una versión nueva
  del plan. Las anteriores no se borran: quedan guardadas con sus apoyos y evidencias, y el PDP muestra
  «← Versión anterior» y «Versión siguiente →». Las metas propias se ven en todas las versiones.
- Pedir propuestas con el interruptor encendido también crea una versión nueva, en vez de sumar metas
  repetidas. Los indicadores cuentan solo la versión vigente de cada plan.
- Apagado por defecto porque reemplaza metas ya aceptadas, algo que el documento del cliente reserva a
  la decisión de la persona. Encendido solo en el VPS propio.

## M1-v1.16.5 (2026-10-13)

**La matriz de desarrollo sigue al diagnóstico**

- Si la persona cambiaba sus respuestas del diagnóstico, la tabla de brechas se recalculaba, pero la
  matriz de desarrollo (el texto del asistente con síntesis, lectura por ámbito y prioridades) seguía
  hablando del resultado anterior. Las metas que se proponían desde ella también salían del resultado
  anterior.
- Ahora, al guardar respuestas que cambian el resultado, se rehacen la matriz de desarrollo y las
  propuestas de metas que la persona todavía no decidió. Las metas aceptadas, modificadas o postergadas
  no se tocan, y las propuestas reemplazadas quedan en el historial del plan. Guardar sin cambios no
  gasta nada.
- Si la regeneración falla, la página avisa que la matriz corresponde al resultado anterior y ofrece
  «Actualizar mi matriz de desarrollo». Pedir metas con una matriz desactualizada también la rehace
  primero.
- Se apaga con el interruptor `diagrefresh`.

## M1-v1.16.4 (2026-10-09)

**La ruta del curso con la misma estructura que la de las pantallas**

- En la portada del curso y sus actividades, «Tu ruta del Módulo 1» salía en una línea compacta y en las
  pantallas del recorrido con círculos grandes y los nombres debajo: se veían como dos barras distintas.
  Ahora la del curso usa la misma estructura (incluida la vista de celular en dos filas de tres) y agrega
  la línea «Vas en la etapa N de 6: …». Se apaga con el interruptor `coursebarfull` y vuelve la compacta.

## M1-v1.16.3 (2026-10-09)

**El Tutor sabe qué se abre al cerrar el Módulo 1**

- Probado con el modelo real: el Tutor nombraba bien las 6 etapas del documento, pero al preguntarle qué
  pasa al cerrar inventaba una continuación («Unidad 2», «Unidad 3»). Su contexto ahora dice qué
  módulos se abren al completar el Seguimiento y Cierre (los cursos de «Módulos que se abren al
  cerrar»), si la persona ya tiene acceso, y que no mencione otra continuación
  (`journey::next_modules_line()`).

## M1-v1.16.2 (2026-10-08)

**Etapa bloqueada: barra ordenada y botón del aviso legible**

- La etapa abierta que todavía está bloqueada (por ejemplo, el Proyecto Integrador sin el diagnóstico)
  se descuadraba en la barra: el nombre quedaba al lado del círculo y el «Estás aquí» partido. El paso
  sin enlace ahora se dispone en columna como los demás.
- El botón del aviso de etapa bloqueada («Ir a Diagnóstico Autoaplicado») salía con texto café sobre
  azul: el tema tiñe los enlaces de las alertas. Tiene colores propios, los mismos de «Regresar al curso».
- En esa misma etapa, la barra decía «Esta etapa está en curso»; ahora dice que todavía no se abre y que
  primero hay que completar la que falta, igual que el aviso.

## M1-v1.16.1 (2026-10-08)

**La ruta con los nombres del documento del cliente y un «Regresar al curso» que se ve**

- **Nombres de las 6 etapas iguales al documento** («esta estructura debe ser la misma que aparece en
  la viñeta Tu ruta del módulo 1»): Bienvenida y Perfilamiento, Diagnóstico Autoaplicado, Ruta
  Formativa Base, Proyecto Integrador, PDP y Apoyos Adaptativos, Seguimiento y Cierre. En la barra de
  ruta, la barra del curso, la lista de la página de Perfil, el aviso de etapa bloqueada, el widget y
  el contexto del Tutor (`journey::NAMES6`, cadenas `stage6_*`). La barra habla de «etapas». En el
  celular, la barra pasa a dos filas de tres para que quepan los nombres completos. Solo con `route6`:
  apagado, la ruta de 5 conserva sus nombres.
- **«Regresar al curso» destacado** (interruptor `clearback`), arriba y abajo de cada pantalla: botón
  sólido índigo con texto blanco en negrita y el texto del documento del cliente. Antes era un botón
  con borde que con los colores del tema se veía deslavado.
- **Sin «evaluador» a la vista**: con el chat solo Tutor, la retroalimentación del proyecto se titula
  «Retroalimentación formativa de tu proyecto».

## M1-v1.16.0 (2026-10-08)

**Ajustes del cliente, parte 2: ruta de 6 etapas**

Segunda parte del documento «Ajustes al Entorno de Aprendizaje del Módulo 1». Respaldo previo:
etiqueta `respaldo/pre-ruta6-2026-10-08` y `respaldos_plugin/*-pre-ruta6-2026-10-08`. Todo con el
interruptor `route6`: apagado, vuelve la ruta de 5 pasos de la v1.15.0.

- **Seis etapas** (`journey::ROUTE6`): Bienvenida y Perfilamiento, Diagnóstico Autoaplicado,
  **Ruta Formativa Base**, Proyecto Integrador, **PDP y Apoyos Adaptativos (opcional)**, Seguimiento
  y Cierre. La barra de ruta, la barra del curso, el widget, el próximo paso sugerido y el contexto
  del Tutor salen del mismo modelo (`course_bridge::route_model()`).
- **Ruta Formativa Base** hecha cuando la persona abrió la lectura, el caso y el ejemplo de la
  unidad y terminó un intento del quiz (códigos `U[n]-LF|CA|ER|QF` del Anexo G en el «Número de
  identificación»). Abrir uno de esos recursos marca la etapa como pantalla actual. El próximo paso
  dice qué recurso falta («te falta el caso y el quiz») y lleva a la sección del curso.
- **Etapas obligatorias en orden la primera vez**: una etapa con otra obligatoria pendiente antes
  se muestra con candado, sin enlace, y su pantalla avisa qué completar primero
  (`course_bridge::gate_html()`). Lo ya hecho nunca se bloquea: después se puede volver a cualquiera.
  El PDP solo pide el diagnóstico.
- **El PDP ya no condiciona el proyecto ni el cierre** (`proyecto.php`, `closure::get_conditions()`), y
  `cli/preparar_curso.php --criterios` lo deja fuera de los criterios de finalización del curso. En la
  barra se llama «PDP y Apoyos», como en el documento del cliente.
- **La lista de la página de Perfil** sale del mismo modelo de la ruta: tenía sus propios 5 pasos.
- **Módulos 2 y 3 al cerrar** (interruptor `unlockmodules`, ajuste «Módulos que se abren al
  cerrar»): al completar el cierre, la persona queda matriculada como estudiante en esos cursos y la
  pantalla de cierre los enlaza. Un curso oculto se informa como «visible cuando el equipo lo publique».
- Recorrido guiado y textos de la guía del PDP con la numeración de la ruta vigente.

## M1-v1.15.0 (2026-10-08)

**Ajustes del cliente: el chat conversa solo como Tutor y no navega**

A partir del documento «Ajustes al Entorno de Aprendizaje del Módulo 1» que entregó el cliente
tras usar la app (parte 1: lo que no depende de decisiones pendientes). Respaldo previo:
etiqueta `respaldo/pre-ajustes-cliente-2026-10-08` y `respaldos_plugin/*-pre-ajustes-2026-10-08`.

- **Chat solo Tutor** (interruptor `tutoronly`): sin selector de modo, sin la barra «Mi Ruta» ni la
  píldora «Siguiente paso» dentro del chat, y sin el próximo paso en la bienvenida. El Tutor
  responde dudas de contenido y de navegación en la conversación, cita la fuente como respaldo y
  no manda a la persona a otra página, etapa o modo. El Coach y el Evaluador siguen trabajando
  dentro de las páginas (propuestas de metas, apoyos, revisión de evidencias y del proyecto), pero
  la persona no conversa con ellos: se ocultan «Conversar con el Coach sobre mis resultados» y la
  apertura automática del chat al enviar el diagnóstico, «Conversar esta meta con el Coach»,
  «Pregúntale al Coach sobre este paso», la píldora «¿Cómo se conecta con mi meta?» y las
  conversaciones por meta. El aviso de diagnóstico cambiado ofrece pedir nuevas propuestas de
  metas en la misma página.
- **Textos sin «Coach» ni «Evaluador»** donde los ve la persona («el asistente»), incluida la
  descripción de la actividad del PDP en el curso (migración 2026100900) y el recorrido guiado.
- **«Próximo paso sugerido»** en lugar de «Tu próximo paso».
- **«Volver al curso» también arriba** de cada pantalla del recorrido (interruptor `backtocourse`);
  lleva a la sección de la etapa, con las pestañas del curso.
- **Proyecto antes que el PDP** en el próximo paso sugerido (interruptor `projectfirst`).

## M1-v1.14.0 (2026-10-07)

**Mi avance, recordatorios, conversación por meta, borrador guardado y recorrido guiado**

Respaldo previo: etiqueta `respaldo/pre-ux2-2026-10-07` y volcado de la base + copia del plugin
en `respaldos_plugin/*-pre-ux2-2026-10-07` de cada servidor. Cada mejora tiene su interruptor.

- **Mi avance por meta** (`pdp::goal_progress()`): línea de tiempo de la meta (creación,
  apoyo propuesto e iniciado, cada evidencia con su resultado, cierre) y, de la última revisión,
  lo ya logrado y lo que conviene profundizar. Responde «¿se logró mejorar algo?» con lo que la
  persona hizo, sin notas.
- **Recordatorios** (`classes/reminders.php`, tarea diaria a las 9:00): una notificación al día
  por persona con lo pendiente —plazo de una meta en 3 días, un apoyo que lleva 3 días esperando
  evidencia, propuestas sin revisar hace 2 días—, sin repetir un asunto antes de 3 días. Llega por
  la campana de Moodle; el correo queda permitido y apagado por defecto.
- **Conversación por meta** (`classes/thread.php`, campo `goalid` en `local_chatbot_messages`):
  «Conversar esta meta» abre o retoma el hilo de esa meta con el Coach, con una franja para
  volver al chat general. Los mensajes anteriores quedan en el chat general.
- **Borrador de evidencia guardado** en el navegador mientras se escribe; se recupera al recargar
  y se borra al entregar.
- **Recorrido guiado** la primera vez: la ruta, los tres modos y dónde está la ayuda.
- **Brecha sin cambio en una línea** («Funcional · sin cambio»).
- **Tildes** en los nombres del instrumento y la rúbrica por defecto (migración 2026100800).
- **Historial**: al reabrir el widget se muestran los 50 mensajes más recientes; antes, con más
  de 50, se mostraban los 50 primeros y la conversación reciente no aparecía.
- Una sola definición de `window.chatbotAsk` (había dos), y los envíos esperan a que termine de
  cargar el historial para que la carga no borre el mensaje recién enviado.

## M1-v1.13.1 (2026-10-07)

**Una contraseña autocompletada ya no entra al plan ni viaja al proveedor de IA**

En el VPS propio, el navegador autocompletó la contraseña guardada de una cuenta de prueba en
los campos de una meta propia (objetivo, acciones y evidencia). De ahí pasó al apoyo adaptativo
generado para esa meta, que la mostró como «meta vinculada» y como criterio, y viajó al
proveedor de IA. Ahora:

- Los campos de texto libre del recorrido (plan, proyecto, perfil y cierre) llevan
  `autocomplete="off"` y las marcas que respetan los gestores de contraseñas.
- `guardrails::looks_like_password()` reconoce un texto con forma de contraseña (una sola
  palabra de 8 a 64 caracteres con minúscula, mayúscula, dígito y símbolo). Una meta, una
  edición de meta, una evidencia o un mensaje del chat con esa forma no se guardan ni se envían:
  se avisa a la persona.

## M1-v1.13.0 (2026-10-07)

**Moodle y el asistente como una sola cosa, respuestas más rápidas, y cada mejora con su interruptor**

Respaldo previo: etiquetas `respaldo/pre-ux-propio-2026-10-06` y
`respaldo/pre-ux-cliente-2026-10-06`, más volcado de la base y copia del plugin en
`respaldos_plugin/` de cada servidor (`*-pre-ux-2026-10-06`).

- **Interruptores** (`classes/ux.php`, Administración → Plugins locales → Asistente): cada
  mejora de v1.11.0 en adelante se puede apagar sin desplegar nada. Es la forma de retroceder:
  bajar de versión el código no es posible con la base ya migrada.
- **Tu próximo paso** (`journey::next_action()`): una sola acción concreta —revisar
  propuestas, validar el plan, entregar la evidencia del apoyo de la Meta 2, un plazo que
  vence— con un botón al lugar exacto. La misma en la barra de ruta, el widget, el botón
  «Siguiente paso» y el curso.
- **Ruta dentro del curso**: la portada del curso del Módulo 1 y sus actividades muestran la
  ruta, el recurso abierto y el próximo paso.
- **Modo según la pantalla**: el widget abre en Coach en el plan y en Evaluador en el
  proyecto, y lo avisa.
- **Corrección de cumplimiento (RU-DIAG-002)**: por un `isset($PAGE->url)` que siempre daba
  falso, el widget no detectaba que estaba sobre el diagnóstico; las reglas de la fase de
  diagnóstico solo se aplicaban en modo Coach. Ahora se aplican en cualquier modo. No tiene
  interruptor.
- **Píldoras según el recurso**: en lecturas, casos y ejemplos, preguntas sobre ese recurso;
  durante el quiz, aviso de que no da respuestas.
- **Valoración 👍/👎** con motivo de una lista cerrada (tabla `local_chatbot_feedback`, sin
  texto de la persona) e indicador «Respuestas valoradas como útiles» en calidad (§11.4).
- **Reintento**: ante una falla pasajera del servicio el widget ofrece «Reintentar» con la
  misma pregunta, y el mensaje fallido se retira del historial.
- **Base de conocimiento al inicio del contexto**: igual para todas las personas, para que el
  proveedor la reutilice entre mensajes.
- **Respuestas en vivo** (solo Gemini): el texto aparece por frases; cada frase pasa por los
  guardrails de salida antes de mostrarse y, si una regla bloquea, se reemplaza por la
  respuesta segura. Al terminar, la respuesta revisada completa reemplaza a la mostrada.
- La sesión de Moodle se libera antes de llamar al modelo, para que la espera no bloquee otras
  páginas de la misma persona.

## M1-v1.12.3 (2026-10-06)

**El nombre del paso actual en «Mi Ruta» se lee**

La píldora del paso donde está la persona salía con el texto verde de «hecho» sobre fondo
morado y casi no se leía. Ahora el texto es blanco.

## M1-v1.12.2 (2026-10-06)

**Barra de ruta arriba de cada pantalla: «Estás aquí» a la vista**

Un cliente seguía perdiéndose entre las pantallas del recorrido porque saber dónde estaba
dependía de abrir el asistente. Ahora cada pantalla (perfil, diagnóstico, PDP, proyecto y
cierre) abre con una barra de ruta con los cinco pasos: hecho en verde, pendiente en gris, y
el paso abierto más grande, en morado y con la etiqueta «Estás aquí». Debajo, una línea dice
en qué paso estás, si ya está completo y lo que sigue. Cada paso enlaza a su pantalla. En el
widget, el paso actual también se marca con más fuerza (círculo morado ampliado y nombre en
píldora).

## M1-v1.12.1 (2026-10-06)

**«Mi Ruta» marca dónde estás aunque el paso esté hecho, y las píldoras ya no se cortan**

Desde v1.11.0 el widget habla de la pantalla abierta, pero la barra «Mi Ruta» solo
distinguía «hecho» de «pendiente»: un paso ya completo donde la persona estaba trabajando se
veía igual que los demás. Ahora lleva un halo y su nombre subrayado. Las píldoras de acción
rápida («¿Dónde voy?», «¿Qué hace este modo?», «Siguiente paso») bajan de línea en vez de
cortarse al borde de la ventana.

## M1-v1.12.0 (2026-10-06)

**Revisar el borrador de la evidencia con el Tutor antes de entregar**

Bajo el cuadro de evidencia del apoyo adaptativo hay un botón «Revisar mi borrador antes de
entregar». Manda lo escrito al chat con el Tutor, que ya tiene la consigna y sus criterios, y
le pide retroalimentación criterio por criterio con preguntas, sin reescribirlo. Es una
revisión informal: no entrega la evidencia, no cambia el estado del apoyo y no se registra
como revisión. La regla de autoría no cambia: el asistente guía, la evidencia la produce la
persona.

## M1-v1.11.6 (2026-10-06)

**El apoyo adaptativo dice dónde escribir la evidencia**

La consigna que genera la IA pedía redactar la respuesta «en este chat», pero la evidencia
solo se entrega en el cuadro que está bajo la actividad en el PDP: la persona no sabía dónde
escribir. Ahora el prompt de la propuesta indica que la respuesta va en ese cuadro y prohíbe
decir «en este chat», y sobre el cuadro hay una instrucción fija que lo dice (también para
los apoyos ya creados, cuya consigna conserva el texto anterior).

## M1-v1.11.5 (2026-10-06)

**La ayuda dentro del apoyo adaptativo abre al Tutor**

Los tres botones de ayuda del apoyo («¿Cómo la hago?», «Dame un ejemplo», «¿Qué evidencia
presento?») abrían al Coach. Ahora abren al Tutor, que ya recibe la consigna del apoyo en
curso y ayuda a comprenderla y a pensar cómo abordarla, sin producir la evidencia.

## M1-v1.11.4 (2026-10-06)

**El apoyo adaptativo ya no se pierde al pedirlo, y trae ayuda a mano**

El apoyo adaptativo se mostraba al pie de su meta, y al pedirlo la página recargaba arriba:
con varias metas, la persona no lo encontraba. Ahora, tras pedirlo, iniciarlo, entregar
evidencia o confirmar una propuesta, la página se desplaza hasta ese apoyo y lo resalta; la
meta lleva una etiqueta «Apoyo en curso ↓» que enlaza a él, y la guía del PDP lista los
apoyos en curso con un enlace a cada uno. Dentro del apoyo hay tres botones («¿Cómo la
hago?», «Dame un ejemplo», «¿Qué evidencia presento?») que abren al Coach con la consulta
ya escrita sobre esa actividad.

## M1-v1.11.3 (2026-10-06)

**El plan muestra cómo cambió cada brecha desde que se creó la meta**

Cada meta guardaba su brecha como texto fijo, así que al cambiar el diagnóstico el plan no
lo reflejaba. Ahora cada meta muestra «Brecha: En desarrollo → ahora: Funcional ▲ subió de
nivel» (se compara con la matriz más reciente, la de salida si existe), y la guía del PDP
avisa cuando el diagnóstico cambió en alguna meta, con un botón para pedirle al Coach que
revise el plan. Es información de apoyo, no una calificación. El nivel previo se lee del
texto de la meta (`pdp::gap_change()`); las metas escritas a mano sin nivel muestran solo
el nivel actual. Sin cambios de esquema.

## M1-v1.11.2 (2026-10-06)

**El número de cada meta se ve**

Cada meta del plan lleva su número en un círculo, cada grupo (en curso, postergadas,
completadas) muestra cuántas metas tiene, y las propuestas por revisar también van
numeradas («Meta 1», «Meta 2») con su contador.

## M1-v1.11.1 (2026-10-06)

**Las metas del plan se muestran en un orden que se puede seguir**

Las metas salían por orden de creación mezclando estados, así que cada meta nueva o
reactivada caía en cualquier lugar. Ahora van agrupadas: en curso, postergadas y
completadas; dentro de cada grupo, la de plazo más cercano primero (sin plazo, al final),
luego por dimensión y, a igualdad, por antigüedad. Cada meta lleva su número. Las
propuestas por revisar van agrupadas por dimensión. El orden es solo de presentación:
no cambia datos ni lo que recibe el Coach (`pdp::order_goals()`).

## M1-v1.11.0 (2026-10-06)

**El asistente sabe en qué pantalla estás y no se pierde entre el PDP y el resto de la ruta**

Un cliente reportó que, estando en su Plan Personal de Desarrollo, el asistente decía
"estás en el Proyecto Integrador". Pasaba porque «dónde estás» se calculaba como el
primer paso sin completar, y el PDP cuenta como completo apenas hay un plan validado,
aunque la persona siga trabajando ahí.

Ahora manda la pantalla abierta (`classes/journey.php`): el mensaje de bienvenida, la
barra «Mi Ruta» y el botón «Siguiente paso» dicen dónde estás y, aparte, lo que sigue;
un paso ya completo se marca «hecho» sin dejar de ser el lugar actual. El Tutor, el
Coach y el Evaluador reciben la pantalla abierta en su contexto, para no ubicar a la
persona en otro paso. Dentro del PDP hay una guía del paso (pedir propuestas, revisarlas,
validar el plan) con el primer pendiente marcado y un botón para preguntarle al Coach.

## M1-v1.10.1 (2026-09-29)

**Se registra el cierre del Tutor (`EVT-IA-T-U1-CLOSE`, `RU-TRZ-003`)**

Cerrar el widget no llegaba al servidor, así que el Anexo G §7.2 quedaba con un
evento sin registrar. Ahora el widget avisa con `navigator.sendBeacon` al cerrarse
(o al salir de la página) a `ajax_close.php`, que registra el cierre solo si hubo
consultas al Tutor después del último cierre: avisar de más no duplica.
Lleva los datos mínimos del anexo, sin texto de la persona: síntesis (intención y
etiquetas de concepto de la última consulta) y recurso recomendado. El siguiente
paso va vacío: el Tutor no lo guarda de forma estructurada.

## M1-v1.10.0 (2026-09-29)

**El Coach recibe la meta como el objeto del Anexo F §4, no como texto suelto**

El Anexo F define, para cada intervención sobre una meta, un objeto de datos con
la identificación técnica, el origen de la meta, su estado, el contexto
autorizado, los recursos fijos, el progreso y las reglas de privacidad, y
recomienda usarlo como schema de integración (`RU-COA-DATA-002`, `RU-COA-001`).
Hasta ahora el Coach sabía lo mismo, pero repartido en cuatro bloques de texto
armados por separado.

Ahora, con metas vigentes, todo eso llega en un solo objeto por meta
(`classes/coach_goal.php`), con las claves literales del documento. Reemplaza los
bloques de PDP, de apoyo en curso y de patrones del quiz; la matriz, el resultado
agregado del quiz y el estado del proyecto siguen llegando como antes. La
propuesta de apoyo adaptativo, que armaba su propio texto con los mismos datos,
también recibe el objeto.

El objeto se valida antes de viajar: si le falta una clave del documento, trae un
campo de identidad, o un texto lleva un correo o un RUT, se descarta y el Coach no
lo recibe. Los textos libres de la meta pasan siempre por el barrido de datos
personales, porque la persona puede haberlos editado.

Lo que el ejemplo del documento no muestra pero su tabla de campos mínimos sí
(actividad previa, feedback anterior, siguiente acción y decisiones) va bajo
`estado_meta`; lo que el plugin guarda de la meta y el documento no nombra, bajo
`detalle_plugin`. La prioridad de origen del Anexo G no se guarda y viaja en
`null`, en vez de inventarla.

Lo ejercita `cli/verificar_coach_meta.php` (67 casos).

## M1-v1.9.4 (2026-09-29)

**Un crédito agotado se le explicaba a la persona como "revisa tu conexión"**

Cuando el proveedor de IA respondía con un error que no fuera 503 o 429, el
asistente decía "No se pudo conectar con el asistente. Revisa tu conexión e
intenta de nuevo". Con un 402 (crédito agotado) o un 401/403 (credencial
inválida) eso manda a la persona a revisar un internet que funciona, cuando el
problema es del servicio y no lo puede resolver ella.

Ahora esos tres códigos se explican como lo que son —un problema del servicio,
no de la conexión— y llevan la ruta a soporte que pide RU-PRIV-005. Los demás
códigos conservan su mensaje, y 503 y 429 siguen sin mandar a soporte porque
pasan solos con esperar.

La decisión salió de `ajax.php` a `classes/service_error.php`, una función pura
que ejercita `cli/verificar_errores_servicio.php`.

## M1-v1.9.3 (2026-09-29)

**El panel de indicadores usaba media pantalla y dibujaba tendencias inexistentes**

La página declaraba el layout `standard` de Moodle, que encajona el contenido en
una columna angosta pensada para texto. Un panel de indicadores es una rejilla
de datos: con el layout `report` aprovecha el ancho completo.

Los gráficos semanales, además, se dibujaban con cualquier cantidad de dato
mayor que cero. Con actividad en una sola semana eso producía una línea plana a
lo largo de tres meses con un salto al final, que sugiere que se midió algo y
dio cero durante once semanas, cuando en realidad no había nada que medir.

Ahora, cuando la actividad ocurre en una sola semana, se muestra la tabla con
los números y se explica por qué todavía no hay tendencia. El gráfico aparece
cuando hay dos semanas con registros. El estado de "sin datos" previo no cambia.

## M1-v1.9.2 (2026-09-28)

**El panel mostraba `[[managekb_jobs_title]]` en vez de los textos**

Las diez cadenas agregadas en las versiones anteriores se escribieron solo en
`lang/es`. Moodle arma el conjunto de claves de un componente a partir del
archivo en inglés y encima aplica la traducción, así que una cadena que solo
existe en español no existe para Moodle: la interfaz la muestra en crudo,
entre dobles corchetes.

Las diez ya están en `lang/en`, y `cli/verificar_idiomas.php` compara ambos
archivos para que no vuelva a pasar. El fallo era silencioso —no aparece en el
log ni lo detecta `php -l`—, y por eso llegó a producción.

## M1-v1.9.1 (2026-09-28)

**Cuando el proveedor se niega a reproducir un documento, ahora se dice**

Al cargar el Manual EDECOMP, la extracción terminaba sin texto y el panel lo
reportaba como un PDF ilegible. No lo era: el proveedor respondió
`finishReason: RECITATION`, que es su negativa a reproducir textualmente
material publicado. Le ocurre a los manuales y guías oficiales, y no depende
del tamaño ni del tiempo de espera, así que volver a subirlos no sirve de nada.

Ese caso ahora se identifica y se explica por lo que es, para que nadie pierda
el tiempo intentando reparar un documento que está bien.

## M1-v1.9.0 (2026-09-28)

**Los documentos largos ya se pueden cargar**

Subir un anexo de más de cien páginas fallaba siempre. Esperar la respuesta de
la IA dentro del request web nunca podía funcionar: el modelo no envía nada
hasta terminar de procesar el documento entero, y el servidor web corta la
conexión mucho antes (60 segundos con la configuración por defecto de nginx).
Subir el tiempo de espera del plugin no alcanzaba, porque el que cortaba era el
servidor, no el plugin.

Ahora la carga y la extracción son dos cosas distintas. Al subir el PDF se
guarda y se responde de inmediato; la extracción del texto queda encolada como
tarea y la ejecuta el cron, donde no hay servidor web que corte. Ya no hay que
esperar con la ventana abierta.

El panel muestra los documentos en proceso aparte de los vigentes, con su
estado: en cola, o no se pudo procesar y por qué. Un documento en cola no
alimenta al asistente, y la versión anterior del recurso sigue vigente hasta
que la nueva termina bien — antes, una extracción fallida podía dejar al
recurso sin ninguna versión activa.

El control de `RU-KB-001` se mantiene en el nuevo camino: un documento con
claves de evaluación se rechaza y se explica cuál marca se encontró. Lo cubre
`cli/verificar_kb_carga.php`.

## M1-v1.8.2 (2026-09-28)

**Un documento largo se rechazaba como si estuviera dañado**

Al subir un PDF de muchas páginas, el panel respondía siempre "La IA no
devolvió texto. Es posible que el PDF no sea legible o tenga errores de
formato", con el detalle `transport_error`. El PDF no tenía nada malo: la
llamada al proveedor se cortaba a los 120 segundos exactos sin haber recibido
un solo byte, porque el modelo no envía nada hasta terminar de procesar el
documento completo, y cien páginas tardan más que eso.

El tiempo de espera de la extracción sube a 300 segundos y el límite de PHP a
600, y el aviso previo ya no promete "entre 10 y 60 segundos".

Además, ese caso ahora se explica por lo que es: se distingue el corte por
tiempo de espera de un PDF ilegible, para que nadie pierda la tarde intentando
reparar un documento que está perfecto.

## M1-v1.8.1 (2026-09-28)

**El panel de la base de conocimiento no dejaba ver el PDF original**

Al subir un documento, solo se guardaba el texto que la IA extraía para el
contexto del Tutor — el PDF original se descartaba después de procesarlo, así
que no había nada que abrir desde el listado de "Recursos cargados".

Ahora el PDF se guarda también (Moodle file storage, `kb_document`), y su
nombre en el listado es un enlace que lo abre en una pestaña nueva. Solo lo
puede recuperar quien tiene la capacidad de gestionar el KB, igual que la
página que lo lista. Los documentos migrados desde el flat-file legado, que
nunca tuvieron un PDF de origen, siguen mostrándose como texto simple.

## M1-v1.8.0 (2026-09-26)

**Cada paso empuja al siguiente**

Terminar un paso y no saber qué sigue es donde la gente se detiene. Validabas
el plan y la página no te llevaba a ninguna parte.

Ahora, al completar un paso, aparece una tarjeta que nombra lo hecho, explica
en qué consiste lo que viene y lleva ahí con un botón: "Tu plan está validado →
Ir al proyecto integrador". La tarjeta apunta siempre al primer paso pendiente,
así que quien se saltó algo vuelve a él en vez de avanzar con un hueco.

Cuando los cinco están hechos, la tarjeta cambia por el aviso de recorrido
completo, en vez de dejar la página muda.

## M1-v1.7.5 (2026-09-26)

**El widget mostraba un avance distinto del real en las páginas del sitio**

`SITEID` llega como la cadena `'1'`, así que la comparación estricta
`$courseid === SITEID` nunca se cumplía. Fuera de un curso, el widget tomaba el
curso 1 (el sitio) en vez del recorrido general, con dos consecuencias:

- El mapa "Mi Ruta" mostraba un avance que no correspondía: 20% mientras la
  página del plan decía "validado · versión 2".
- Lo que la persona hacía desde esas páginas quedaba guardado con `courseid=1`,
  separado de su recorrido general.

Corregido, y migradas las filas afectadas: 2 perfiles, 49 respuestas de
diagnóstico, 2 planes, 1 proyecto, 1 cierre, 28 mensajes, 30 interacciones, 67
trazas, 3 matrices del Coach y 1 apoyo adaptativo pasan del curso 1 al recorrido
general. Nadie tenía datos en ambos lugares, así que no hubo que mezclar nada.

## M1-v1.7.4 (2026-09-26)

**Las acciones que llaman al modelo avisan que están trabajando**

Preparar la matriz de desarrollo, pedir metas al Coach, proponer un apoyo,
revisar una evidencia y entregar el proyecto tardan varios segundos. La página
quedaba igual, parecía colgada, y quien pulsaba de nuevo disparaba la acción dos
veces.

Ahora el botón se deshabilita, muestra un indicador y dice qué está pasando
("El Coach está leyendo tu diagnóstico..."). Si pasan 8 segundos, aparece debajo
una línea que explica en qué sigue trabajando y cuánto puede tardar.

## M1-v1.7.3 (2026-09-26)

**Los botones que abren el asistente con una consulta no hacían nada**

- `window.chatbotAsk` se invocaba en cinco lugares pero **nunca estuvo
  definida**. Afectaba a "Consultar concepto" de cada ítem del diagnóstico, a
  "Consultar al Asistente" y a los tres atajos del mapa de ruta. Ahora existe:
  cambia de modo si hace falta, abre la ventana, escribe la consulta y la envía.
- El botón "Consultar concepto" además tenía el HTML roto: el enunciado se
  interpolaba con comillas dobles dentro de un atributo con comillas dobles, así
  que el atributo se cerraba antes de tiempo y el navegador descartaba el
  manejador. El enunciado viaja ahora en `data-enunciado`, escapado.

Se agregan casos que impiden la reaparición: que `chatbotAsk` exista y que
ninguna página del plugin interpole contenido variable dentro de un `onclick`.

## M1-v1.7.2 (2026-09-26)

**Las cuentas de administración también consienten**

`has_consent` daba por consentida cualquier cuenta de administración. Eso
contradice `RU-CONS-002`: sus consultas viajan al mismo proveedor de IA que las
de cualquier persona, así que también deben aceptar las condiciones. De paso,
su recorrido nunca partía de cero, lo que confundía las pruebas: el mapa "Mi
Ruta" mostraba el paso del perfil hecho sin haberlo hecho.

Quien administre el sitio y quiera usar el asistente acepta las condiciones una
vez, como todo el mundo.

**Preparar cursos**: `cli/preparar_curso.php` acepta `--criterios`, que registra
los cinco pasos como criterios de finalización del curso. Sin ellos Moodle no
calcula progreso y la barra del curso queda vacía aunque los pasos estén
completos.

## M1-v1.7.1 (2026-09-26)

**Tres requisitos parciales, cerrados**

- **El consentimiento vale para lo que se declaró** (`RU-CONS-002`). Cada
  aceptación queda sellada con la versión de lo que se le mostró a la persona.
  Si la institución cambia el proveedor, la región, la conservación o el canal
  de derechos, ese sello deja de coincidir y se vuelve a pedir, explicando por
  qué. Los consentimientos anteriores al versionado no llevan sello y se
  respetan como vigentes: invalidarlos en masa, sin que nadie hubiera cambiado
  la declaración, sería un perjuicio sin fundamento.
- **Con qué material se respondió** (`RU-PRIV-006`). Cada interacción guarda la
  versión del recurso citado y una huella del conjunto de la base de
  conocimiento, para poder reconstruir sobre qué texto se construyó la
  respuesta.
- **Registro estructurado de la intervención del Coach** (`RU-ADAPT-007`). Cada
  revisión de evidencia deja el registro del Anexo F §10 en su propia columna,
  con versiones de plan, prompt, base de conocimiento, consentimiento y
  retención, los cinco campos del feedback, la propuesta y la decisión de la
  persona. Lleva la síntesis de la evidencia, nunca la transcripción.

`build_record` no consulta la base: recibe el contexto ya recogido, así que se
verifica sin arrancar Moodle, como el resto de las funciones puras del plugin.

Verificador nuevo: `cli/verificar_versionado.php`. El total sube a 394 casos.

## M1-v1.7.0 (2026-09-26)

**El asistente y el curso dejan de ser dos mundos**

El Módulo 1 se vivía en dos lugares que no se hablaban: el curso, con su
narrativa y su ruta formativa, y el asistente, donde la persona hace su perfil,
su diagnóstico, su plan, su proyecto y su cierre. El curso no sabía nada de eso:
su barra de progreso no avanzaba, las restricciones de acceso no se desbloqueaban
y los informes de finalización mostraban un recorrido vacío.

- **Las páginas del recorrido se abren dentro del curso.** Con su menú, su índice
  lateral y su barra de progreso. Antes se abrían fuera, y el widget del asistente
  calculaba el avance del sitio mientras la página usaba el del curso: dos cifras
  distintas en la misma pantalla.
- **Un paso del curso por cada paso del recorrido.** Una actividad reconocida por
  su código (`M1-PASO-PERFIL` y siguientes) que enlaza a la página del asistente y
  **se marca sola** cuando el paso está hecho de verdad.
- **La verdad vive en el asistente.** Si alguien marca un paso a mano sin haberlo
  hecho, el puente lo revierte. Si completó pasos antes de que existiera el
  puente, al entrar al curso quedan al día.
- **Vuelta al curso** desde cada página del recorrido.
- **Herramienta de preparación** (`cli/preparar_curso.php`): crea los cinco pasos
  en las secciones que se le indiquen, opcionalmente encadenados, y con `--simular`
  muestra lo que haría sin escribir nada. Es idempotente.

Sin actividades `M1-PASO-*` en el curso, el puente no hace nada y el asistente
funciona igual que siempre: un curso que no las tenga no cambia en absoluto.

Verificador nuevo: `cli/verificar_puente.php`.

## M1-v1.6.3 (2026-09-26)

**Los 25 códigos de evento del Anexo G, homologados**

El plugin registraba 15 de los 25 códigos que define el Anexo G §4. Faltaban los
nueve del recorrido por los recursos fijos, más el cierre del Tutor. Ahora se
emiten escuchando los eventos que Moodle ya dispara:

| Código del Anexo G | Se emite cuando |
|---|---|
| `EVT-U[n]-LF-VIEW` · `CA-VIEW` · `ER-VIEW` | La persona abre el recurso |
| `EVT-U[n]-LF-COMPLETE` · `CA-COMPLETE` · `ER-COMPLETE` | La actividad pasa a completada |
| `EVT-U[n]-QF-START` | Inicia un intento del quiz |
| `EVT-U[n]-QF-REVIEW` | Consulta la retroalimentación del intento |
| `EVT-U[n]-QF-COMPLETE` | El quiz queda completado |

La actividad se reconoce por su **idnumber** (`U1-LF-01` y similares), no por su
nombre: una actividad sin código no genera registros, que es justo lo que evita
ensuciar los indicadores con actividades ajenas al módulo.

Sigue sin emitirse `EVT-IA-T-U1-CLOSE`: cerrar la ventana del asistente no llega
al servidor. Es el único desvío declarado del catálogo.

## M1-v1.6.2 (2026-09-25)

**El panel muestra el registro, no solo sus indicadores**

- **Catálogo de eventos.** Cada código del Anexo G con su total, agrupado por
  familia (Tutor, Coach, PDP, sistema). El §11 ya daba indicadores derivados;
  faltaba la capa de abajo, que es la que sirve para auditar: qué se registró y
  cuántas veces.
- **Respuestas del quiz por subdimensión.** `local_chatbot_quiz_pattern` se
  llenaba desde la v1.6.0 y ninguna pantalla la leía.
- **Salud del servicio.** Llamadas correctas, con error y bloqueadas, tiempo de
  respuesta por modo y texto procesado. Sin esto, una degradación del proveedor
  solo se notaba porque alguien reclamaba.

**Correcciones de textos que prometían algo distinto de lo que ocurre**

- El consentimiento afirmaba que el historial "viaja cifrado". El plugin no
  cifra y el sitio aún no tiene HTTPS: ahora dice que solo la propia persona
  puede leerlo desde su cuenta. **Pendiente real: habilitar HTTPS.**
- El aviso del proyecto integrador pedía "al menos una meta vigente"; la
  condición que evalúa el código es el plan validado.
- En la barra de decisión del chat, el botón decía "Guardar con mis cambios",
  pero ahí no hay formulario que editar: ahora dice "Modificar".
- El título del botón de borrar la conversación estaba sin tilde y sin traducir.

**Entrada al recorrido**

- El enlace "Mi Módulo 1" ya existía como texto, pero ningún menú lo usaba: la
  única puerta era el widget. Se agrega a la navegación para quien tenga la
  capacidad `local/chatbot:use`.

## M1-v1.6.0 (2026-09-21)

**Trazabilidad del Anexo G**

- **Registro del Tutor (Anexo E §11).** Cada interacción guarda su código
  (`IA-T-U1-000001`), el recurso desde el que se abrió el asistente (el widget
  envía la actividad actual), las etiquetas de concepto de la consulta, las
  fuentes que citó la respuesta y si se aplicó la regla del quiz. Nunca el texto
  de la persona.
- **Catálogo de eventos (§7).** Se registra en `local_chatbot_trace` y en el log
  de Moodle (`\local_chatbot\event\trace_recorded`):
  - Tutor: OPEN, CONSULT, QUIZ-GUARD, TRANSFER, COACH-REF.
  - Coach: OPEN, PROPOSE, DECISION, EVIDENCE, VERIFY, UPDATE-PROPOSE,
    UPDATE-DECISION, GOAL-CLOSE-PROPOSE y GOAL-CLOSE-CONFIRM.
  - Además: decisiones y consulta del historial del PDP, incidencias técnicas y
    los eventos de calidad IA.
  - CLOSE del Tutor no se registra: cerrar el widget no llega al servidor.
- **Códigos (§2.1).** `EV-U1-…`, `VI-U1-…`, `PDP-UPD-…` y `U1-D1-AA-…`.
- **Patrones del quiz (§5.1, §8.3).** Un observador de `attempt_graded` guarda el
  resultado por pregunta, identificada por su idnumber (`U1-QF-01-P04`). Las
  reglas de activación llegan al Coach como evidencia complementaria, solo
  después de un intento enviado. Se escucha `attempt_graded` porque en Moodle 5
  el envío y la calificación van separados.
- **Indicadores (§11).** Tutoría, desarrollo adaptativo y PDP, y coherencia y
  calidad IA en el tablero de indicadores. Incluyen recursos inventados por la
  IA y cambios sin confirmación (que debe ser 0).

Nuevo verificador: `cli/verificar_trazabilidad.php`.

## M1-v1.5.0 (2026-09-21)

**Prompts operativos de los Anexos E y F**

- **Tutor: Anexo E §10.** Se agregan las fuentes y su jerarquía (§3), las
  definiciones operativas (§4), la transferencia sectorial pidiendo
  antecedentes (§5.3), la secuencia de respuesta (§6.1) y los patrones de error
  (§8).
- **Coach: Anexo F §9.** Se agregan las metas tipo (§2), la plantilla de
  propuesta (§5.3), los apoyos por nivel de orientación (§6) y los estados de
  meta (§8). Se mantienen las reglas de la matriz de Doc 3.
- **Carga segura.** Viven en `classes/operative_prompts.php` y se cargan como
  versión nueva solo donde nadie editó el prompt desde la interfaz.
- **Plantilla de propuesta (RU-COA-010).** La propuesta de apoyo adaptativo se
  arma en código con los nueve elementos del Anexo F §5.3. Los criterios de
  revisión quedan entre 2 y 4, tomados de los configurados, y el recurso previo
  se resuelve contra el catálogo.

## M1-v1.4.0 (2026-09-21)

Tres requisitos del SPEC v5 que la prueba automática dejó abiertos.

**Feedback de cinco campos (RU-GEN-003, RU-ADAPT-007)**

La revisión de una evidencia tiene ahora el formato de Doc 5 §9.3 y Anexo F §7.1:
fortaleza observada, aspecto a profundizar, por qué importa, siguiente acción
sugerida y propuesta de actualización de la meta. Siempre cierra pidiendo una
decisión a la persona. El tipo de propuesta (`profundizar`, `registrar_avance`,
`actualizar_meta` o `proponer_cierre`) se deriva del estado de la revisión y no
lo decide el modelo. Los cinco campos se guardan en columnas propias de
`local_chatbot_adapt_review` y salen en el registro estructurado. Las claves
anteriores del modelo siguen aceptándose.

**Los 12 estados de meta (RU-ADAPT-006)**

`pdp::goal_state_v5()` traduce lo que ya se guarda (la decisión de la persona,
el plan validado, el último apoyo adaptativo y las propuestas pendientes) al
vocabulario del SPEC v5: propuesta, activa, en desarrollo, evidencia
presentada, evidencia revisada por IA, requiere profundización, avance
registrado, en revisión, postergada, propuesta de cierre, cerrada y rechazada.
Es una capa derivada, no una columna nueva: los planes existentes se muestran
con los 12 estados sin migrar datos y sin cambiar la lógica de decisión. El PDP
y el contexto del Coach usan este vocabulario.

**Datos de terceros detectados sin modelo (RU-PRIV-008)**

RUT, teléfonos y correos en una evidencia se detectan antes de llamar al
modelo, así que no viajan al proveedor. La evidencia no se registra y se pide
una versión anonimizada. Antes el rechazo dependía del juicio del modelo, y en
la re-prueba una evidencia con nombre, RUT y teléfono pasó sin aviso.

Verificadores: `verificar_apoyos.php` suma los casos del formato v5, de los
datos de terceros y de los 12 estados.

## M1-v1.3.1 (2026-09-21)

Correcciones que salieron de la prueba automática del flujo completo contra el
SPEC v5 (`docs/RESULTADOS_PRUEBAS_AUTOMATICAS.md` del repositorio del proyecto).

**Integridad del cuestionario (RU-TUT-007, RU-KB-001)**

El Tutor nunca decía la letra, pero dejaba la alternativa correcta deducible:
citaba las alternativas y guiaba el descarte. Y con el primer intento enviado,
`quiz_guard_clause` le avisaba al modelo que podía "explicar los contenidos
evaluados con mayor detalle", aunque quedara un segundo intento. Ahora
`assistant::quiz_key_state()` distingue cuatro estados por cuestionario: sin
intentos, intento en curso, entre intentos y clave liberada. Se considera
liberada solo cuando la configuración de revisión de Moodle muestra la
respuesta correcta o no quedan intentos. Mientras no lo esté, el prompt prohíbe
indicar, descartar, calificar o comparar alternativas, reescribir la pregunta y
resolver su razonamiento.

`kb_store::add_document()` rechaza documentos con marcas de clave
("Alternativa correcta: C", "Clave: B", "SOLO CONFIGURACIÓN"), y la pantalla de
la base de conocimiento explica por qué no lo incorporó. El Tutor recibe la base
completa en su contexto, así que una clave que entra ahí puede salir.

Nuevo verificador: `cli/verificar_integridad_quiz.php`.

**Lo que el asistente dice sobre los datos (RU-PRIV-009, RU-PRIV-010)**

Sin instrucciones, el asistente afirmaba que solo se guardaban resúmenes y que
no se almacenaban nombres. Ahora recibe una cláusula con lo que realmente se
registra: el historial completo por el plazo de retención configurado, el
registro estructurado sin texto, la síntesis de evidencias y el seudónimo hacia
el proveedor. También indica el canal de derechos si está configurado, y si no,
que aún no está publicado.

**Coach**

- Ante "cierra mi meta" sin evidencia revisada, ya no ofrece el cierre: explica
  que se requiere evidencia y ofrece una actividad breve, postergar o rechazar
  (RU-COA-016).
- Las metas se leían sin preposición ("…manuales de procedimientos talleres
  presenciales"): `compose_goal()` antepone "en" cuando el contexto no trae
  conector. También corrige "disenar" → "diseñar", que el modelo copiaba del
  propio prompt.
- No se piden ni se guardan microcápsulas (RU-COA-003 v5).
- El catálogo de apoyos ya no incluye etiquetas ni el banco de preguntas.
- El mensaje ante evidencia con datos de terceros pide una versión anonimizada,
  en vez del aviso genérico de rechazo (RU-PRIV-008).

**Tutor**

Cuando la persona quiere trabajar una meta del PDP, el Tutor da una orientación
breve y la dirige explícitamente al Coach (RU-GEN-006).

**Historial del chat**

El asistente a veces volvía a responder la pregunta anterior. El historial se
filtraba por segundo (`timecreated <`): si la persona escribía en el mismo
segundo en que llegó la respuesta, esa respuesta quedaba fuera. Ahora se filtra
por `id`.

## M1-v1.3.0 (2026-09-15)

**Los indicadores dejan de estar escondidos**

El panel de indicadores solo se alcanzaba desde Administración del sitio >
Plugins > Plugins locales, o abriendo el asistente y su cajón de perfiles. Las
dos rutas exigían ser administrador del sitio, pese a que la capability
`local/chatbot:viewanalytics` está pensada para managers. Ahora hay un botón
flotante permanente, abajo a la izquierda, que lo ve cualquiera con esa
capability y en cualquier página. No amplía quién puede ver los datos: solo
acorta el camino.

**El panel pasa a ser un tablero**

`indicadores.php` usaba el layout de administración, así que se leía como una
pantalla de configuración de Moodle, con las pestañas General / Usuarios /
Cursos encima. Ahora tiene encabezado propio, cuatro indicadores clave arriba y
tarjetas en grilla. Cada tarjeta dice qué mide y, cuando no hay datos, cuándo
van a aparecer, en vez de un "no hay datos suficientes" sin salida.

Los gráficos se dibujan en HTML y SVG, sin librerías: cada uno lleva su valor
escrito junto a la marca o en una tabla desplegable, y el tooltip solo
complementa. La paleta se validó para daltonismo y contraste sobre fondo
blanco. Los niveles de brecha van en una rampa azul y no en rojo/verde: la
gobernanza del módulo pide evitar clasificaciones estigmatizantes, y "prioridad
alta" en rojo lo sería.

**Dos indicadores nuevos, semana a semana**

Todas las tablas del plugin guardan `timecreated` y nadie lo estaba usando. Se
agregan `analytics::usage_timeline()` y `analytics::route_timeline()`: las
interacciones por modo funcional y las personas acumuladas en cada hito del
recorrido, en las últimas doce semanas. Responden si el módulo se está usando
*ahora*, que es lo que ningún indicador contestaba. El último valor de cada
línea del recorrido coincide con el embudo.

**Correcciones de indicadores que perdían datos**

- Las metas **postergadas** no se contaban en `pdp_goal_outcomes()`:
  desaparecían del indicador y los totales no cuadraban con los PDP.
- `interactions_by_mode()` solo devolvía aceptadas y descartadas. Ahora también
  modificadas, postergadas y sin respuesta, así la suma da el total.
- El filtro "Todo el sitio" nunca sumó todos los cursos: filtra `courseid = 0`,
  o sea el recorrido hecho fuera de un curso. Ahora se llama "Recorrido general
  (sin curso)".

Las claves que ya consumía `suite_export` no cambian; las nuevas se agregan al
final.

**Verificación**

```bash
php cli/verificar_matriz_acceso.php
php cli/verificar_guardrails.php
php cli/verificar_calculo.php
php cli/verificar_coach.php
php cli/verificar_apoyos.php
```

Además, el tablero se generó con datos reales y con datos de prueba, revisando
que no queden avisos de PHP ni cadenas sin traducir, en escritorio y en
teléfono.

**Antes de instalar**

Esta versión agrega cadenas de idioma y CSS: hay que **purgar las cachés**
después de actualizar, o los textos nuevos aparecen como `[[nombre_cadena]]`.
No hay cambios en la base de datos.

**Pendiente conocido**

`lib.php` usa `isset($PAGE->url)` en dos puntos del widget del asistente
(detección del modo según la página y etapa del diagnóstico). `url` es una
propiedad mágica de `moodle_page`, que define `__get` pero no `__isset`, así que
esa condición siempre es falsa: el asistente nunca cambia solo a Coach o
Evaluador según la página. Se corrigió en el botón nuevo, no en el widget,
porque cambia el comportamiento del asistente para las personas participantes y
corresponde probarlo aparte.

## M1-v1.2.0 (2026-09-11)

**El plugin se alinea a la matriz RU/RS/EARS v4 (Doc 3 y Doc 5)**

La matriz pasó de 42 a 68 requisitos. Incorporó dos documentos del cliente
que antes no estaban —la Matriz Individual de Brechas (Doc 3) y la Matriz de
Diseño del Itinerario Base (Doc 5)— y una sección de requisitos no funcionales
que el propio spec marca como recomendaciones pendientes de validar con el
cliente. El cruce completo está en `docs/TRAZABILIDAD_RU.md`: 61 cumplidos, 6
parciales con el límite dicho y 1 bloqueado por contenido que no se entregó.

**Tres decisiones de M1-v1.1.0 se reemplazaron, no se complementaron**

Eran fieles a la matriz v2 y la v4 las contradice:

- **La derivación a soporte humano.** El prompt mandaba derivar "una
  incertidumbre metodológica relevante". Doc 5 §13.3 prohíbe exactamente eso:
  las dudas conceptuales y metodológicas las resuelve el asistente, y el
  soporte humano queda para incidencias técnicas. Derivarlas convierte al
  soporte técnico en tutoría por otro canal. La cláusula ahora distingue tres
  caminos —resolver, redirigir lo ajeno al módulo, derivar lo técnico— y la
  inyecta el código en todos los modos. De paso, ante una falla técnica del
  proveedor la persona recibe la ruta de soporte, no solo "intenta de nuevo".
- **Los niveles de orientación.** Eran tres, con cortes en tercios de la
  escala, porque v2 no fijaba cortes y había que elegir alguno. Doc 3 fija
  cuatro —Prioridad alta, En desarrollo, Funcional, Consolidado— con cortes en
  2,25, 3,25 y 4,25. Se transcribieron tal cual. **La clasificación de las
  personas cambia con esta versión**: una dimensión con compuesto 2,30 pasa de
  "brecha prioritaria" a "en desarrollo".
- **La primera salida del Coach.** Imponía los cinco campos genéricos. Doc 3
  §9.1 define una plantilla propia —encabezado, síntesis, tabla por ámbito,
  hasta tres prioridades y una pregunta de cierre— y ahora la plantilla es
  código: el modelo redacta celdas en JSON y el servidor arma la estructura.

**El Coach recibe un objeto de datos, no bloques de texto (RU-COA-DATA-001)**

`coach_context` arma el contrato de Doc 3 §10.1 con sus claves literales:
completitud, estado de la matriz, discrepancias, omisiones, catálogo de apoyos
activos. Es más estrecho que antes —la experiencia declarada ya no llega al
Coach, porque RU-COA-001 no la autoriza— y la matriz de acceso lo expresa como
alcance `'contract'`, no como un condicional aparte.

Con el catálogo se cierra, además, un límite que `CUMPLIMIENTO.md` venía
declarando: el Coach nombraba actividades desde su propio conocimiento y podía
nombrar una que no existiera. Ahora solo puede nombrar lo que está en el curso,
y un nombre fuera del catálogo se descarta en código antes de guardarse.

**Reglas de Doc 3 aplicadas en código, no pedidas en el prompt**

- *Completitud* (RU-COA-COMPLET-001): una dimensión con menos del 80% de ítems
  declarativos o con algún situacional sin responder es preliminar. Se marca en
  pantalla, no genera metas, y si hay dos o más el tope baja a una prioridad.
- *Priorización* (RU-COA-PRIOR-002): menor compuesto y discrepancia relevante
  se calculan; los criterios que requieren leer texto libre quedan al Coach,
  que recibe la lista ya ordenada.
- *Forma de la meta* (RU-COA-PDP-FLOW-001): verbo + capacidad + contexto, con
  evidencia y **plazo**, un campo nuevo. El plan ya no se activa con metas
  incompletas: `pdp::validate()` dice qué le falta a cada una, y
  `has_active_plan()` exige plan validado.

**Motor de apoyos adaptativos (RU-ADAPT-001 a 007)**

Subsistema nuevo. A partir de una meta aceptada, el asistente propone una
microactividad individual, recibe la evidencia en texto o PDF, la revisa
contra criterios formativos configurados por dimensión y propone continuar,
profundizar, actualizar o cerrar la meta. Tres garantías de diseño:

- Ningún apoyo crea una actividad de Moodle. Es una propiedad estructural: el
  código capaz de hacerlo no existe, y un verificador lo comprueba.
- El estado es vocabulario controlado y las transiciones son una tabla. El
  modelo propone un estado; el código decide si lo acepta. Ante una respuesta
  ambigua elige *requiere profundización*, porque registrar un avance que no
  ocurrió es peor que pedir una evidencia más.
- Nada toca el PDP sin la persona. Actualizar o cerrar una meta queda pendiente
  hasta que ella confirma, y pasa por el historial del plan.

Se guarda una síntesis de cada evidencia, nunca el texto ni el archivo. Una
evidencia ofensiva, con datos de terceros o que intenta explotar el sistema se
rechaza sin entrar al registro (`GR-12`).

**Seguridad (sección 10 del spec, pendiente de validar con el cliente)**

Los PDF adjuntos ahora llegan al modelo precedidos de una marca que los declara
material a evaluar, igual que el texto de la persona. Las solicitudes ajenas al
módulo se redirigen. Nuevas reglas de guardrail para calificación global,
etiquetas de aptitud ("eres apta") y juicios absolutos ("no sabes").

**Otros cambios**

- Tiempo disponible en el perfil: RU-COA-001 lo lista como dato autorizado del
  Coach y el perfil nunca lo preguntaba.
- Los prompts que ninguna persona editó se actualizan a la versión vigente
  (`seeder::refresh_system_prompts()`), con versión nueva y la anterior
  conservada. **Consecuencia que conviene saber:** las mejoras de prompt de
  M1-v1.1.0 nunca habían llegado a las instalaciones existentes, porque la
  carga inicial solo agrega lo que falta. Llegan con esta versión.
- Corrige que el promedio de grupo en `indicadores.php` se mostraba "/ 4"
  desde que la escala pasó a 1-5.

**Verificación**

```bash
php cli/verificar_matriz_acceso.php   # 44 celdas
php cli/verificar_guardrails.php      # 26 casos
php cli/verificar_calculo.php         # 42 casos
php cli/verificar_coach.php           # 23 casos (nuevo)
php cli/verificar_apoyos.php          # 39 casos (nuevo)
```

Además, 62 comprobaciones de los flujos contra la base de datos de la
instalación local, con un usuario de prueba y sin llamar al proveedor de IA.

**Antes de instalar**

- Doc 3 trae subdimensiones que el instrumento cargado no tiene. Hasta cargarlas,
  `RU-COA-SUBDIM-001` no se puede cumplir.
- Los criterios formativos por dimensión son contenido base genérico: hay que
  revisarlos en `instrumento.php` antes del pilotaje, porque contra ellos se
  revisan las evidencias.
- Un plan que ya estaba validado sigue activo aunque sus metas no tengan plazo.
  Los planes nuevos no se activan sin plazos.


## M1-v1.1.0 (2026-09-10)

**El plugin se cruza contra la matriz RU/RS/EARS, no solo contra la ERTF**

Llegó `Spec_Asistente_IA_para_IDE.md`, derivado de la matriz de requisitos
`RU/RS/EARS` v2. Es la primera vez que el asistente tiene requisitos con
identificador estable y caso de prueba en Gherkin, así que se pudo cruzar
requisito por requisito en vez de sección por sección. El resultado del cruce
está en `docs/TRAZABILIDAD_RU.md`: 42 requisitos, 40 cubiertos y 2 parciales
con el límite declarado.

Aparecieron siete desvíos reales. Todos están cerrados en esta versión.

**1. No existía la fase de diagnóstico (RU-DIAG-001 a 004)**

Mientras la persona respondía el instrumento, el asistente operaba con las
reglas del modo que tuviera elegido. Podía interpretarle respuestas, insinuar
alternativas o adelantarle cómo iba quedando el perfil. La especificación es
clara en que esa fase "no es un modo con interfaz propia, pero tiene reglas de
conducta propias y muy estrictas", y la razón importa: si el asistente orienta
la autoevaluación, el perfil deja de medir la práctica de la persona y pasa a
medir la sugerencia del asistente. Todo lo que viene después —el PDP, el
proyecto, el contraste de salida— se apoya en esa línea base.

Ahora existe `assistant::PHASE_DIAGNOSTICO`, con las tres capas:

- **Contexto:** su propia fila en `ACCESS_MATRIX`, la única enteramente en
  `false`. No ve ni siquiera los recursos internos del módulo, porque un
  ejemplo tomado de la lectura de una unidad puede orientar la respuesta de un
  ítem.
- **Prompt:** versionado como los demás, más una cláusula que inyecta el código
  y que no se puede editar desde la interfaz.
- **Guardrail:** `GR-11`, que bloquea la respuesta.

La fase la resuelve el servidor (`resolve_phase()`). La página declara desde
dónde se conversa, pero eso solo puede endurecer las reglas: el servidor
comprueba contra la base de datos antes de hacerle caso.

**2. El puntaje compuesto de orientación no existía (RU-CALC-001)**

El diagnóstico era solo autodeclaración promediada. El requisito pide ponderar
70% la práctica declarada y 30% la resolución de ítems situacionales, porque la
autodeclaración sola es frágil: se responde lo deseable, no lo habitual.

Consecuencias, que no son menores:

- **La escala pasó de 1–4 a 1–5.** El componente situacional se define en 1 a 5,
  y ponderar dos números en escalas distintas da un resultado sin significado.
  Las respuestas anteriores se reescalan linealmente en el upgrade. **El
  redondeo a entero pierde los valores intermedios exactos:** 3 en la escala
  vieja queda en 4, y ningún registro migrado queda en 3. Por eso, de aquí en
  adelante, cada respuesta deja registrado con qué tope de escala se dio.
- **Los cortes de nivel ahora son tercios del recorrido de la escala**, no
  números sueltos. Valían 2,0 y 3,0; ahora valen 2,33 y 3,67. La clasificación
  de una persona no cambia por la migración, que era el punto.
- **Aparecieron los ítems situacionales:** `kind`, `options` y `correctoption`.
  La respuesta correcta nunca viaja al navegador ni al modelo; el acierto se
  resuelve en el servidor.

`composite_score()` es función pura, como pide el requisito, y se verifica en
`cli/verificar_calculo.php` sin base de datos ni modelo.

**3. El Coach proponía hasta cuatro prioridades (RU-COA-003)**

El requisito fija el máximo en tres. El prompt pedía "entre 2 y 4". Ahora el
tope es `pdp::MAX_PRIORIDADES` y se aplica **recortando la respuesta del
modelo**, no solo pidiéndoselo: más de tres frentes abiertos a la vez es
justamente lo que el módulo quiere evitarle a una persona que además trabaja.

Cada prioridad además debía asociarse a una unidad, un recurso, una
microcápsula, una actividad y una evidencia. Faltaban los cuatro primeros: son
columnas nuevas de `local_chatbot_pdp_goal`.

**4. No se podía postergar una recomendación (RU-COA-004)**

Las decisiones eran aceptar, modificar y rechazar. El requisito enumera cuatro,
y postergar no es rechazar a medias: la meta sigue viva y el Coach la ve como
candidata a retomar cuando cambie el tiempo disponible o la evidencia. Existe
como `GOAL_POSTERGADO`, como botón en el PDP y en la barra de decisión del
widget, y entra al contexto del Coach marcada como vigente.

**5. El consentimiento no era informado del todo (RU-CONS-004)**

Decía "un proveedor externo de IA", sin nombrarlo, sin región de procesamiento,
sin plazo de conservación y sin canal para ejercer derechos. Los cuatro se
configuran ahora por sitio y se muestran literalmente antes de aceptar. Lo que
el sitio todavía no declaró aparece en rojo como pendiente en vez de omitirse
en silencio, y `profile::disclosure_is_complete()` permite comprobarlo antes de
la puesta en producción.

De paso, `message_retention_months` pasó a ser un ajuste real: la tarea de
purga ya lo leía, pero no había dónde configurarlo.

**6. El "tipo de consulta" eran los primeros 100 caracteres del mensaje (RU-PRIV-004)**

Eso no es un tipo: es una copia parcial del texto de la persona dentro de la
tabla de auditoría, que es la que más gente puede leer para calcular
indicadores. Ahora `interaction::classify_intent()` devuelve una etiqueta
cerrada. Es un clasificador por palabras clave, no comprensión semántica:
acierta en la formulación frecuente y cae en `otro` cuando no reconoce el caso.
Se resolvió así a propósito, porque una segunda llamada al modelo para
etiquetar la primera volvería a enviar el texto que este cambio existe para no
guardar.

**7. El formato de cinco campos solo regía para el Evaluador (RU-GEN-003)**

El requisito lo exige para Coach y Evaluador. Ahora está en ambos prompts v1.

**El producto pasa a llamarse Asistente IA**

Por decisión de la contraparte, que pidió un nombre más simple. Reemplaza a
*Brújula Formativa*, adoptado en M1-v1.0.0.

El argumento de aquel nombre —una brújula orienta y quien camina decide, que es
lo que GR-05 y el flujo del PDP obligan— sigue siendo cierto sobre el
comportamiento del asistente. Lo que cambia es que el nombre ya no lo anuncia:
la garantía vive en el código y se verifica en `cli/verificar_guardrails.php`,
no en la marca. Queda escrito en `docs/DECISIONES.md`, DEC-03, para que dentro
de un año se entienda por qué el producto no se llama de un modo que comunique
esa restricción.

**El componente interno sigue siendo `local_chatbot`.** El cambio fueron dos
cadenas de idioma por idioma más las menciones en documentación: ninguna línea
de lógica, ninguna tabla, ninguna URL. Es exactamente la propiedad que DEC-03
buscaba conservar, y la razón por la que renombrar el producto es barato y
renombrar el componente no lo es.

**Verificación**

Tres scripts, ninguno requiere base de datos ni bootstrap de Moodle:

```bash
php cli/verificar_matriz_acceso.php   # 36 celdas, 0 desvíos
php cli/verificar_guardrails.php      # 20 casos,  0 fallidos
php cli/verificar_calculo.php         # 18 casos,  0 fallidos
```

**Antes de instalar esta versión**

El upgrade reescala respuestas de diagnóstico existentes. Si hay datos de
pilotaje que no se puedan perder, respaldar `local_chatbot_diag_response`
primero. Y los cinco ítems situacionales que se cargan son contenido base
genérico: hay que reemplazarlos por los del pilotaje real antes de producción.


## M1-v1.0.0 (2026-09-09)

**Nombre de producto y esquema de versiones**

El plugin pasa a llamarse **Brújula Formativa**. El nombre no es cosmético: una
brújula orienta y quien camina decide, que es la restricción que la ERTF §8.4
le impone al asistente en GR-05 —ninguna recomendación puede presentarse como
obligatoria ni como calificación— y el flujo del Coach ya obliga a que cada
meta se pueda aceptar, ajustar o descartar.

El componente interno **sigue siendo `local_chatbot`**. Renombrarlo alcanza a
794 ocurrencias en 55 archivos, incluidas las 15 tablas y las 4 capacidades.
Hoy sería barato porque nada está en producción; después de la primera
instalación en la contraparte deja de ser un renombrado y pasa a ser una
migración con ventana de mantenimiento. Queda declarado como deuda en
`docs/DECISIONES.md`, DEC-03, con esa consecuencia dicha completa.

**El release ahora lleva el módulo adelante: `M1-v1.0.0`.**

DEC-01 puso los tres módulos de la suite en un solo componente, así que el
número de versión es el único lugar donde se distingue una instalación de
otra. `v2.4.0` no dice si trae el Módulo 2; `M1-v1.0.0` sí. Quien da soporte
lo lee en *Administración del sitio → Plugins* sin abrir el repositorio.

**Sobre el salto de v2.3.0 a M1-v1.0.0:** no es un retroceso. Las versiones
`v1.x` y `v2.x` de abajo son anteriores a la ERTF —exploración para dimensionar
el alcance, no entregas al cliente— y se conservan en este changelog como tales.
La numeración de entregas parte acá. `$plugin->version` (2026090904) sigue
subiendo de forma monótona, que es lo único que Moodle compara.

Sin cambios funcionales: el código del asistente, la matriz de acceso y los
guardrails son los mismos que se verificaron en v2.3.0.


## v2.3.0 (2026-09-09)

**Los perfiles ahora separan datos, no solo comportamiento (ERTF §7.3 y §8.4)**

Llegó la ERTF v2.0, que integra la arquitectura del Módulo 1 con el documento
del Componente 0. Dos hallazgos al cruzarla contra el código:

- **Los tres perfiles recibían el mismo contexto.** Se comportaban distinto
  solo porque el prompt se los pedía. La ERTF §7.3 lo prohíbe explícitamente:
  esa tabla "es una regla de seguridad, no una sugerencia". Ahora la matriz
  vive como constante declarativa `assistant::ACCESS_MATRIX` y lo que no
  autoriza queda denegado por defecto. El Tutor ya no ve las brechas ni el
  plan; el Evaluador ya no ve la necesidad declarada; los recursos internos
  del módulo son solo del Tutor.
- **Los guardrails eran instrucciones de prompt, no código.** Se agregó
  `classes/guardrails.php` con las reglas de §8.4: bloqueo para GR-01
  (revelar respuesta de quiz pendiente), GR-02 (diagnóstico clínico) y GR-04
  (pedir datos sensibles); anotación correctiva para GR-05, GR-06 y GR-10; y
  aislamiento del contenido de la persona en bloque no ejecutable para GR-07.
  GR-08 y GR-09 se cumplen por construcción y quedan verificados por ausencia
  de código, no por afirmación.

Cada activación se registra con el identificador de la regla en la nueva
columna `local_chatbot_interaction.guardrail` (RF-GRD-02).

**Dos verificadores ejecutables, sin base de datos:**

- `cli/verificar_matriz_acceso.php` — contrasta la implementación contra la
  tabla §7.3 transcrita del documento: 27 celdas.
- `cli/verificar_guardrails.php` — un caso por regla (RF-GRD-01) más casos de
  falso positivo, porque un filtro que bloquea todo también incumple.

Ambos salen con código 1 ante cualquier desvío, para encadenarlos a CI. El
segundo detectó un fallo real durante el desarrollo: el patrón de GR-10 no
cubría la contracción "por debajo **del** promedio".

**Decisión registrada:** `docs/DECISIONES.md` documenta DEC-01 (un solo plugin
en vez de la suite de once de RD-01) con sus dos consecuencias duras, y DEC-02
(consentimiento y perfilamiento aún no separados), aparcado por ahora.

## v2.2.0 (2026-09-09)

**Sistema interactivo de Perfiles / Roles de Acompañamiento (Página 6 de la Arquitectura)**

Se transformó el selector de modos del asistente en un sistema visual de perfiles y roles pedagógicos conmutables, alineado exactamente con la tabla de modos del documento de arquitectura:

- **Catálogo de perfiles enriquecido:** `assistant::get_profiles_catalog()` expone letra (A, B, C), nombre sugerido oficial, momento de uso y propósito pedagógico.
  - **Modo A:** *Tutor de aprendizaje* (Durante las cuatro unidades - Explicar, preguntar, modelar razonamiento y apoyar comprensión sin resolver evaluaciones por la persona).
  - **Modo B:** *Coach de desarrollo* (Después del diagnóstico y durante el PDP - Interpretar brechas, priorizar metas, proponer actividades y retroalimentar avances iterativamente).
  - **Modo C:** *Evaluador formativo del proyecto* (En la entrega y revisión del proyecto integrador - Analizar el proyecto contra la rúbrica, explicar hallazgos y proponer ajustes al producto y al PDP).
- **Selector y Drawer interactivo en el widget:** Se reemplazó el `<select>` plano por un botón de cabecera con distintivo (`Modo A/B/C`) y un panel/drawer desplegable con tarjetas informativas de cada rol.
- **Conmutación contextual inteligente:** El widget detecta la página actual y preselecciona o sugiere el rol adecuado (Coach en diagnóstico y PDP, Evaluador en proyecto integrador, Tutor en las unidades), manteniendo la libertad del facilitador para cambiar en cualquier momento.
- **Separación estricta de hilos y banner informativo:** Cambiar de perfil actualiza instantáneamente el banner, carga el historial aislado de ese rol y enfoca el área de consulta.

## v2.1.0 (2026-09-09)

**Cierre de las brechas detectadas al cruzar la v2.0.0 contra el documento**

Se revisó el documento de arquitectura sección por sección contra el código y
aparecieron cinco cosas que el documento exige y la v2.0.0 no cubría. El
cruce completo quedó documentado en `docs/CUMPLIMIENTO.md`.

- **Componente 5 (Seguimiento y cierre)** estaba a medias: existía el
  diagnóstico de salida pero no el plan de continuidad, la reflexión ni las
  acciones priorizadas. Se agregó `cierre.php` y `classes/closure.php`, que
  además comprueba las cuatro condiciones de cierre que fija el documento
  —proyecto entregado, retroalimentación recibida, reflexión escrita y PDP
  con metas vigentes— en vez de dejarlo a criterio de quien mira la pantalla.
- **Ruta de derivación a soporte humano**: la gobernanza la exige y no
  existía. Ahora es configurable en Site administration y se inyecta en los
  tres prompts, para que el asistente derive cuando el caso excede su alcance.
- **Eventos en el log de Moodle**: el documento pide registrar "hitos del PDP
  e interacción con IA" y el plugin solo escribía en sus propias tablas. Se
  agregaron cuatro eventos en `classes/event/`. Al log va el hecho y sus
  metadatos, nunca el texto de la conversación.
- **Versionado de rúbrica y de bases documentales**: la gobernanza pide
  versionar "prompts, rúbricas, reglas de recomendación y bases
  documentales". Solo los prompts versionaban. Ahora reusar el código de un
  criterio o resubir un documento con el mismo nombre crea la versión
  siguiente y retira la anterior sin borrarla.
- **Archivo de respaldo del proyecto**: el objeto de datos del documento
  lista "archivo" y la columna existía sin uso. Se implementó la subida, con
  validación por contenido real y servido solo al dueño.

**Nuevo:** `classes/suite_export.php` define los contratos de datos hacia los
seis destinos de la sección "Integración con la suite". Están los contratos,
no el transporte: cuando exista la interfaz real de los otros módulos se
conecta sin rediseñar qué se envía.

**Documentación técnica nueva:** `docs/CUMPLIMIENTO.md` (cruce documento vs.
implementación, con lo que falta y por qué) y `docs/ARQUITECTURA.md`
(recorrido de un mensaje, mapa de clases, modelo de datos, eventos, puntos de
extensión y controles de seguridad).

## v2.0.0 (2026-09-09)

**Reenfoque completo hacia el Módulo 1 (Producto 4)**

El plugin deja de ser un chatbot tutor de propósito general con funciones
administrativas anexas y pasa a implementar exactamente lo que especifica el
documento *Arquitectura del Módulo 1* de Interfases Consultores: la capa de
asistente IA y los instrumentos de la puerta de entrada formativa de las
personas facilitadoras.

Es una versión mayor porque cambia el propósito del plugin, no solo su
alcance. Se retiró todo lo que el documento no contempla y se construyó lo
que exige.

### Retirado

- **Checklist de cumplimiento documental** (`checklist.php`,
  `checklist_admin.php`, `checklist_manager`, sus dos tablas). Era un proceso
  administrativo distinto —Inscripción, Asistencia, Certificación— que el
  Módulo 1 no contempla en ninguno de sus componentes.
- **Alertas de sentimiento** (`local_chatbot_alerts` y la instrucción de
  prompt que las generaba). Etiquetaban a una persona como
  `"Frustración/Deserción detectada"` y se lo mostraban al facilitador: es
  justo la clasificación estigmatizante y el diagnóstico personal que las
  reglas de gobernanza del módulo prohíben de forma explícita.
- **Dashboards de reportes** (`dashboard.php`, `dashboard_facilitador.php`,
  `ajax_summary.php`, `grade_converter`). Exponían nombres, correos y notas
  individuales. El documento destina a gestión institucional "datos agregados
  y anonimizados", que es lo que ahora entrega `indicadores.php`.
- **Modos OpenMAIC** (debate multi-agente, mapa mental, presentación,
  quiz interactivo) y **búsqueda web con grounding**. Ninguno aparece en el
  documento, y la búsqueda web además contradice la instrucción de remitir a
  los recursos internos del módulo antes que a conocimiento general.
- **Volcado de datos de terceros al prompt**: el modo facilitador enviaba
  nombres, correos y notas de alumnos al proveedor de IA.

### Construido

- **Asistente con tres modos funcionales explícitos** (`classes/assistant.php`):
  Tutor de aprendizaje, Coach de desarrollo y Evaluador formativo. Un solo
  asistente con contexto compartido, como pide el documento, no tres bots.
  Cada modo abre su propio hilo y muestra un banner permanente con el modo
  activo.
- **Regla de evaluación activa**: el Tutor consulta los intentos de quiz del
  curso y, si la persona no ha respondido un cuestionario, recibe la
  instrucción de guiar con preguntas y referencias en vez de entregar la
  alternativa correcta.
- **Pseudonimización** (`classes/pseudonymizer.php`): al proveedor de IA no
  sale el nombre real, el correo ni el nombre del curso. La persona viaja
  como identificador derivado por hash, y el texto se limpia de correos y RUT.
- **Diagnóstico y matriz de brechas** (`classes/diagnostic.php`,
  `diagnostico.php`): 5 dimensiones, 20 ítems, escala de dominio, aplicación
  de entrada y de salida con contraste de variación.
- **Plan Personal de Desarrollo** (`classes/pdp.php`, `pdp.php`): las metas
  que propone el Coach nacen en estado `propuesto` y no forman parte del plan
  hasta que la persona acepta, edita o descarta. Cada cambio deja fila en
  `local_chatbot_pdp_history` con qué cambió, por qué y con qué evidencia.
- **Proyecto integrador y rúbrica** (`classes/project.php`, `proyecto.php`):
  campos estructurados según la cadena de alineamiento del documento, rúbrica
  visible antes de entregar, y retroalimentación en el formato estándar de
  salida —fortaleza, aspecto a mejorar, por qué importa, acción sugerida,
  vínculo con el PDP— con declaración explícita de cuándo hace falta revisión
  humana.
- **Objeto Interacción IA** (`classes/interaction.php`): modo, intención,
  recursos referidos, recomendación y aceptación del usuario, más la versión
  de prompt con que se generó. Es lo que permite medir cuántas
  recomendaciones se aceptan, se modifican y se descartan.
- **Prompts versionados** (`classes/prompt_store.php`): editar no
  sobrescribe, crea la versión siguiente.
- **Indicadores del módulo** (`classes/analytics.php`, `indicadores.php`):
  embudo de avance, distribución de brechas, variación entrada/salida,
  destino de las metas propuestas, interacciones por modo y criterios de
  rúbrica donde se concentran las observaciones. Todo agregado.
- **Perfilamiento y consentimiento informado** (`classes/profile.php`,
  `index.php`): sin aceptación de las condiciones de uso el asistente no
  opera.
- **Configuración del instrumento** (`instrumento.php`): prompts, rúbrica,
  dimensiones e ítems, bajo la capability `manageinstrument`.

### Migración

Las 14 tablas se crean en la actualización; se eliminan las de checklist y
alertas. Se conservan el historial de conversación (ahora etiquetado por
modo) y los documentos de la base de conocimiento, que pasan a llamarse
recursos internos del módulo.

Las capabilities cambian: `view`, `viewreports` y `managechecklist` se
reemplazan por `use`, `viewanalytics` y `manageinstrument`.

## v1.3.5 (2026-09-06)

**Seguridad: 3 fallas de control de acceso entre cursos (IDOR)**

Revisión previa a una prueba de seguridad externa sobre el plugin. Encontró
tres puntos donde un id recibido por parámetro nunca se cruzaba contra el
curso que el usuario realmente tiene permiso para tocar — el patrón clásico
de IDOR (insecure direct object reference).

- **`dashboard_facilitador.php` (alta):** la vista de alumnos/notas/alertas
  de sentimiento leía `courseid` de la URL sin verificar que perteneciera a
  `$teacher_courses` (la lista de cursos donde el usuario realmente dicta) —
  solo la acción de resolver alertas hacía ese chequeo. Cualquier usuario con
  rol de profesor en un solo curso podía ver el listado completo de alumnos,
  notas y alertas de frustración de **cualquier otro curso** cambiando el
  parámetro en la URL. Se agregó la verificación en la vista principal.
- **`checklist_manager::delete_stage()` / `override_status()` (alta):**
  ninguna de las dos validaba que la etapa o la entrega perteneciera al curso
  del facilitador que hacía la llamada. Un facilitador con permiso de
  checklist en su propio curso podía borrar etapas (y las entregas de
  alumnos) o forzar el veredicto de cumplimiento de **cualquier otro curso**,
  adivinando el id. Ambos métodos ahora exigen `courseid` y lo cruzan contra
  el registro antes de tocarlo.
- **`ajax.php` (baja):** el nombre de un curso se exponía en el contexto del
  prompt sin comprobar que el usuario estuviera matriculado — filtraba el
  nombre de cursos ocultos a quien adivinara el id. Ahora se exige
  `is_enrolled()`.
- **`checklist.php` (baja, integridad de datos):** un alumno podía subir un
  documento contra el id de una etapa de un curso donde no está matriculado.
  Ahora se verifica que la etapa pertenezca al curso actual antes de aceptar
  la subida.

## v1.3.4 (2026-09-06)

**Nuevo: botón para borrar el historial de la conversación**

El widget no tenía forma de que un alumno empezara de cero: el historial
(`local_chatbot_messages`) crecía indefinidamente y siempre se recargaba
entero al reabrir el chat. Se agregó un botón (ícono de papelera) en el
encabezado del widget, junto a pantalla completa y cerrar.

- Nuevo endpoint `ajax_clear_history.php`: borra únicamente los mensajes
  del **usuario y curso actuales** (nunca los de otro usuario) y los
  archivos adjuntos que tuvieran guardados vía Moodle File API, con la
  misma protección `require_login()` + `require_sesskey()` que el resto
  de los endpoints AJAX del plugin.
- El frontend pide confirmación (`confirm()`, mismo patrón ya usado en
  `manage_kb.php` para eliminar documentos) antes de borrar — no hay
  deshacer.
- No toca la base de conocimiento, el checklist de cumplimiento ni las
  alertas de sentimiento: son tablas y flujos completamente separados.

## v1.3.3 (2026-09-01)

**Cost: el checklist ya no hereda el modelo del tutor**

`checklist_manager::validate_with_ai()` llamaba a `generate_content()` pasando
`provider_factory::get_configured_model()` — el mismo modelo elegido para las
conversaciones del tutor. Si un sitio configura un modelo premium para mejorar
la calidad de esas conversaciones, el veredicto de cumplimiento (un JSON corto
tipo `{"cumple": true/false, "motivo": "..."}` que de todas formas siempre pasa
por revisión humana del facilitador vía `override_status()`) pagaba ese mismo
precio sin necesitarlo.

Ahora la llamada no pasa ningún modelo, así que cada proveedor cae en su
propio nivel económico por defecto (`gemini-3.5-flash-lite` /
`claude-haiku-4-5` / `gpt-4o-mini`), sin tocar el modelo configurado para el
resto del plugin. No tiene efecto en Azure AI Foundry, donde el modelo lo fija
el *deployment* configurado, no el campo `model` de la petición.

## v1.3.2 (2026-08-24)

**Cost: prompt caching for the knowledge base**

The system prompt + full knowledge base (the block `ajax.php` builds as the
first turn of every conversation) is identical on every message a student
sends, and — now that v1.3.1 makes an attached document persist across a
conversation too — that first turn isn't the only large repeated block
anymore either.

- **Gemini and OpenAI needed no code changes**: both cache matching prefixes
  automatically (Gemini's implicit caching on 2.5+ models, OpenAI's
  automatic caching above 1,024 tokens), and this plugin's requests already
  put the stable content first and the variable content last — exactly
  what both providers need to get a cache hit.
- **Claude required an explicit marker**: `anthropic_provider.php` now sets
  `cache_control: {type: "ephemeral"}` on the first turn's content block.
  Anthropic bills a one-time ~1.25x write the first time that block is
  sent, then ~10% of the normal input price every time it repeats within
  the cache's TTL — instead of full price on every single message.
- Azure AI Foundry deployments generally mirror OpenAI's automatic caching
  when the underlying model/API version supports it, but this wasn't
  independently verified per-deployment — worth checking your own
  deployment's behavior if cost matters there specifically.

## v1.3.1 (2026-08-24)

**Fix: an attached document only survived one message**

A PDF attached to a chat message (the 📎 clip, outside the knowledge base
and outside the checklist) was sent to the AI once and then discarded —
`local_chatbot_messages` only ever stored the text of a turn, never the
file. A follow-up question about that same document in the next message
("¿y la sección 3?", "compará este PDF con el que subiste antes") got
answered blind, from the AI's own earlier reply about it, not from the
document itself.

Attached files are now stored (Moodle File API, keyed by the message id)
and re-attached whenever they fall inside the last-10-messages history
window `ajax.php` already sends on every request — the same mechanism now
just carries attachments along with the text, so multiple documents
uploaded across a conversation stay available for genuine comparison
between them. New nullable columns `attachment_filename` /
`attachment_mimetype` on `local_chatbot_messages`.

## v1.3.0 (2026-08-23)

Security hardening + provider independence, ahead of external release.

**Security**
- Fixed a stored XSS reachable through prompt injection: course names (set
  by any teacher/manager) flowed unescaped into the "Auditoría IA" prompt,
  which explicitly asks the model for raw HTML, and the reply was inserted
  via `innerHTML` with no sanitization — a crafted course name could
  execute JS in a manager's browser. `ajax_summary.php` now runs the AI's
  reply through Moodle's own `clean_text($summary, FORMAT_HTML)`
  (HTMLPurifier) before returning it.
- The chat widget now sanitizes every AI-generated reply with DOMPurify
  before inserting it via `innerHTML` (`marked.parse()` does not strip raw
  HTML embedded in markdown on its own). Inline `onclick` handlers on the
  slide-carousel and quiz-submit buttons — which DOMPurify strips by
  design, since it's the same vector being closed — were replaced with
  `data-*` attributes wired up via `addEventListener`. Mermaid's
  `securityLevel` set to `strict` for the same reason (diagram text is
  also AI-generated).
- Pinned every previously-unversioned CDN script (`marked`, `mermaid`,
  `apexcharts`×2) to an exact version — no more silently picking up
  upstream's latest release on every page load.

**New: works with any AI provider, not just Gemini**
- Added a `classes/ai/` adapter layer (`ai_provider_interface` +
  `gemini_provider`, `anthropic_provider`, `openai_provider`,
  `azure_foundry_provider`) behind a `provider_factory`. Every call site
  (`ajax.php`, `ajax_summary.php`, `manage_kb.php`, `checklist_manager`)
  now goes through the factory instead of calling `gemini_client` directly.
- New admin setting "Proveedor de IA": Gemini, Claude (Anthropic), OpenAI,
  or an Azure AI Foundry deployment, each with its own API key (and, for
  Azure, endpoint/deployment/api-version) and an optional model override.
  Switching providers needs no code changes and no site upgrade.
- PDF/image documents (knowledge base uploads, checklist submissions) are
  sent natively to whichever provider is active — Claude via `document`/
  `image` content blocks, OpenAI/Azure via `file`/`image_url` content
  parts — with no separate text-extraction step, matching how Gemini
  already worked.
- Web-search grounding stays Gemini-only (its own built-in tool); other
  providers silently ignore the setting rather than erroring.

## v1.2.0 (2026-08-23)

**Branding**
- Chat widget avatar and the plugin's admin-list icon (`pix/icon.png`, replacing
  `pix/icon.svg`) now use the Interfases Consultores logo instead of a
  generic bot image.

**New feature: document-compliance checklist (SENCE pilot)**
- A facilitator defines an ordered list of process stages for their course
  (e.g. Inscripción, Asistencia, Evaluación, Certificación), each with a
  plain-language requirement.
- Students upload the corresponding document per stage from a new page
  (`checklist.php`); Gemini reads it directly (PDF or image, no separate
  extraction step) and returns a compliant/non-compliant verdict with a
  short reason, so a document is checked the moment it's submitted instead
  of waiting on a manual review queue.
- Facilitators see every enrolled student against every stage in one matrix
  (`checklist_admin.php`), can open the original uploaded document, and can
  override the AI's verdict by hand at any time — the AI never has the
  final say.
- New capability `local/chatbot:managechecklist`, declared at course context
  (not site-wide) so a teacher's normal course role grants it only for the
  courses they actually teach.
- New tables `local_chatbot_checklist_stage` and
  `local_chatbot_checklist_submission`; uploaded documents are stored via
  Moodle's File API (not inline in the database) and served through a
  scoped `pluginfile.php` callback that only the uploading student or that
  course's facilitator can reach.

## v1.1.0 (2026-08-22)

Professional restructure ahead of external distribution, plus features
inspired by [OpenMAIC](https://github.com/THU-MAIC/OpenMAIC) (Tsinghua
University's open-source multi-agent classroom project).

**Security**
- Re-enabled TLS certificate verification on every Gemini API call
  (previously `CURLOPT_SSL_VERIFYPEER` was disabled).
- The knowledge base is no longer a single flat file living in the plugin's
  public web directory — see "Knowledge base" below.
- `manage_kb.php` now validates uploaded PDFs by their real content
  (`mime_content_type()`) instead of trusting the browser-supplied MIME type.
- `gemini_api_key` is now stored as a masked password field instead of plain
  text in the admin settings screen.
- Knowledge base management and the analytics dashboards now use their own
  capabilities (`local/chatbot:managekb`, `local/chatbot:viewreports`)
  instead of requiring full `moodle/site:config`, so access can be delegated.
- Fixed a CSRF gap and a cross-course authorization gap on the facilitator
  dashboard's "resolve alert" action; escaped student name/email output that
  was previously printed unescaped.

**Knowledge base (new)**
- Replaced the old single append-only `knowledge_base.txt` file with a
  proper `local_chatbot_kb_documents` database table: every uploaded PDF is
  its own row, listed individually with size/date, and can be **deleted**
  — previously the knowledge base could only grow, with no way to remove a
  document uploaded by mistake or gone out of date.
- `manage_kb.php` now explains, in plain language, what the page does and
  that the knowledge base is site-wide (not per-course) — this was
  previously unclear from the page itself.
- Existing installs migrate their old flat-file knowledge base into the new
  table automatically on first access.

**New feature: web search grounding (OpenMAIC-inspired)**
- The tutor agent can optionally use Gemini's built-in Google Search
  grounding to answer with current information instead of relying only on
  training data or uploaded PDFs, and cites its sources in the reply.
  Off by default (admin opt-in via Site administration), since it sends
  part of the conversation to Google.

**Restored/fixed OpenMAIC-style modes**
- Debate mode (two virtual peer students), presentation mode (slide
  carousel), mind-map mode (Mermaid diagrams) and the interactive quiz UI
  are backend-prompted in `ajax.php` but had no matching frontend renderer
  in the version that shipped — restored the full rendering logic
  (multi-agent avatars, slide carousel, Mermaid, radio-button quiz options)
  and added quick-prompt buttons so students can actually reach these modes.

**Structure**
- Added `classes/` with `gemini_client`, `grade_converter` and `kb_store`,
  removing three duplicated copies of the Gemini HTTP call and two
  duplicated copies of the Chilean grade-scale conversion.
- Moved the chatbot widget's JavaScript out of an inline `<script>` block
  embedded in a PHP heredoc (`lib.php`) into a static `js/chatbot-widget.js`,
  loaded via a normal `<script src>` tag.
- Removed dead/debug files that shipped alongside the plugin:
  `test.js`, `test_php.php`, `force_install.php`, `install.ps1`,
  `install.sh`, `js/script.js` (an unused, out-of-date duplicate of the
  widget script).
- **Removed ~150 lines of unscoped global CSS from `styles.css`** (bare
  `body`, `h1`-`h5`, `.card`, `.btn`, `a`, `.form-control`, `.breadcrumb-item`
  selectors with `!important`) that a chat-widget stylesheet should never
  have contained — it was silently repainting the *entire site's* navbar,
  cards, buttons, links and headings on every logged-in page, fighting
  whatever theme was installed. A widget's stylesheet should only ever
  affect its own DOM subtree.
- Added `pix/icon.svg`, `LICENSE` (GPLv3), `db/upgrade.php`.

## v1.0 (2026-07-27)

Initial internal release: chatbot widget with 3 agent profiles, PDF
knowledge base, analytics dashboard, facilitator dashboard with Chilean
grading scale and AI sentiment alerts.
