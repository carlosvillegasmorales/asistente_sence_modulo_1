# Trazabilidad: matriz RU/RS/EARS v4 → implementación

Cruce de los **68 requisitos** de `Spec_Asistente_IA_para_IDE.md` v4 (derivado de `Matriz_RU_RS_EARS_Asistente_IA_Modulo1.xlsx` v4) contra el código del Asistente IA (`local_chatbot`) M1-v1.2.0.

La matriz completa (Word/PDF/Excel) sigue siendo la fuente de verdad trazable a los documentos originales de Interfases Consultores. Este archivo dice **dónde vive cada requisito en el código**, y qué queda fuera del plugin y por qué.

**Qué cambió respecto de v2.** La v4 incorpora Doc 3 (Matriz Individual de Brechas) y Doc 5 (Matriz de Diseño del Itinerario Base): 19 requisitos de negocio nuevos, 7 no funcionales y una corrección. Tres de esos cambios contradecían decisiones de M1-v1.1.0 —la derivación a humano, los niveles de orientación y la primera salida del Coach— y se reemplazaron, no se agregaron al lado.

**Convención de estado:**

| | Significado |
|---|---|
| ✅ | Implementado en el plugin, con el lugar indicado |
| 🔒 | Implementado **como código**, no solo como instrucción de prompt: se cumple aunque el modelo alucine |
| ⚠️ | Implementado con un límite, indicado en la fila |
| ❌ | No implementado, con la razón |

**Convención de capas.** Una restricción puede vivir en hasta tres capas, y la fila dice en cuáles:

1. **Contexto** — lo que no debe salir, no entra. La matriz de acceso decide qué objeto de datos ve cada modo.
2. **Prompt** — la instrucción versionada; o una cláusula que el código inyecta siempre, que es más fuerte porque no se puede borrar editando el prompt.
3. **Guardrail** — el filtro que corre sobre la respuesta antes de mostrarla.

---

## 0. Núcleo del asistente

| ID | Patrón | Estado | Dónde |
|---|---|---|---|
| `RU-GEN-001` | Ubicuo | ✅ | Un solo asistente con tres modos y contexto compartido: [`classes/assistant.php`](../classes/assistant.php). El historial se acota al modo activo en [`ajax.php`](../ajax.php) |
| `RU-GEN-002` | Evento | ✅ | Banner permanente de modo en el widget y lista de fuentes bajo cada respuesta, persistida en `local_chatbot_interaction.resources`. Las arma `assistant::build_context()` |
| `RU-GEN-003` | Evento | ✅ | Cinco campos: columnas de `local_chatbot_project_fb` para el Evaluador, y formato obligatorio del Coach **en el seguimiento**. La primera salida del Coach usa la plantilla de `RU-COA-OUT-001`, que el propio spec declara que reemplaza a este requisito para ese caso. Ver ambigüedades del spec al final |

---

## 1. Fase: durante el diagnóstico

No es un cuarto modo: cambia bajo qué reglas opera el asistente. La activa `assistant::resolve_phase()` y **el cliente no puede desactivarla**: solo puede declarar qué etapa está respondiendo, y el servidor comprueba contra la base de datos antes de aplicarla.

| ID | Patrón | Estado | Dónde |
|---|---|---|---|
| `RU-DIAG-001` | Donde | ✅ | Prompt versionado de la fase ([`seeder::default_prompts()`](../classes/seeder.php)). Explica términos con ejemplos generales |
| `RU-DIAG-002` | No deseado | 🔒 | Las tres capas. **Contexto:** fila `PHASE_DIAGNOSTICO` de `ACCESS_MATRIX`, la única enteramente denegada. **Prompt:** `assistant::diagnostic_phase_clause()`, inyectada por código. **Guardrail:** `GR-11`, que bloquea. `correctoption` nunca sale de la capa de datos |
| `RU-DIAG-003` | Donde | ✅ | Prompt de la fase y cláusula inyectada |
| `RU-DIAG-004` | No deseado | ✅ | Solo lo técnico va a soporte humano; las dudas sobre términos o sobre la actividad las resuelve el asistente. **Interpretación declarada:** el texto de este requisito no se actualizó en v4 y sigue diciendo "preguntas que excedan el alcance del instrumento"; se aplica la corrección de `RU-GEN-DERIV-001`, que es la única lectura coherente con el resto de la matriz |

---

## 2. Modo A — Tutor de aprendizaje

| ID | Patrón | Estado | Dónde |
|---|---|---|---|
| `RU-TUT-001` | Estado | ✅ | Prompt del Tutor |
| `RU-TUT-002` | Estado | ✅ | Prompt + recursos internos (`kb_store`); único modo con `unit_content` |
| `RU-TUT-003` | Estado | ✅ | Prompt |
| `RU-TUT-004` | Ubicuo | 🔒 | Prompt + `assistant::quiz_guard_clause()`, que consulta `{quiz_attempts}` |
| `RU-TUT-005` | Ubicuo | ✅ | Prompt + base documental versionada + catálogo de recursos por nombre |
| `RU-TUT-006` | No deseado | ✅ | Prompt + guardrail `GR-06`, que antepone una corrección ante exigencias normativas no verificadas |
| `RU-TUT-007` | No deseado | 🔒 | Las tres capas: `quiz_pending_items => false` en todos los modos, `quiz_guard_clause()` y `GR-01`, que bloquea |
| `RU-TUT-008` | No deseado | ✅ | Prompt del Tutor + `assistant::authorship_clause()`, inyectada por código donde haya apoyos adaptativos (Tutor y Coach). **Límite:** capa de prompt solamente. No hay guardrail de salida: "una evidencia lista para presentar como propia" no se reconoce por patrón de texto |

---

## 3. Modo B — Coach de desarrollo

### Comportamiento general

| ID | Patrón | Estado | Dónde |
|---|---|---|---|
| `RU-COA-001` | Evento | 🔒 | Perfil y matriz le llegan al Coach solo dentro del objeto de datos (`'contract'` en `ACCESS_MATRIX`). Antes del envío del diagnóstico no hay Coach: `resolve_phase()` deriva a la fase de diagnóstico |
| `RU-COA-002` | Evento | ✅ | `diagnostic::level_group()` agrupa los 4 niveles de Doc 3 en las 3 categorías de este requisito. **Interpretación declarada:** Prioridad alta = brecha prioritaria; En desarrollo = ámbito de profundización; Funcional y Consolidado = fortaleza, porque ambos quedan sobre el punto medio de la escala. La matriz no dice cómo se corresponden |
| `RU-COA-003` | Evento | 🔒 | Tope en `pdp::MAX_PRIORIDADES`, aplicado por `coach_report::validate_payload()` sobre la respuesta del modelo. Los cinco vínculos son columnas de `local_chatbot_pdp_goal` |
| `RU-COA-004` | Ubicuo | ✅ | Las cuatro decisiones como estado de meta y como botones en el PDP y en el widget |
| `RU-COA-005` | Evento | ⚠️ | El Coach ve el PDP vigente, incluidas las metas postergadas, y el motor de apoyos adaptativos propone actualizar o cerrar una meta a partir de evidencia revisada, con confirmación de la persona. **Límite:** no hay re-priorización automática entre metas |
| `RU-COA-006` | No deseado | 🔒 | Prompt + guardrails `GR-02` (bloquea) y `GR-05` (corrige) |
| `RU-COA-007` | Evento | ✅ | `local_chatbot_pdp_history`, solo de escritura. Toda confirmación de un apoyo adaptativo pasa por `pdp::decide_goal()` y queda ahí |

### Motor de la Matriz Individual (Doc 3)

| ID | Patrón | Estado | Dónde |
|---|---|---|---|
| `RU-COA-DATA-001` | Ubicuo | 🔒 | [`coach_context::build()`](../classes/coach_context.php) arma el objeto con las claves literales del schema de Doc 3 §10.1. Viaja seudonimizado y con el texto libre depurado; nunca con respuestas crudas. Lo que agrega el plugin va aparte, bajo `reglas_sistema`. **Límite:** `subdimensiones` viaja vacío y `respuestas_abiertas_autorizadas` en `null`: el instrumento cargado no tiene ninguna de las dos |
| `RU-COA-OUT-001` | Evento | 🔒 | [`coach_report`](../classes/coach_report.php): la plantilla de Doc 3 §9.1 es código. El modelo solo redacta las celdas, en JSON, y el servidor arma encabezado, síntesis, tabla por ámbito, prioridades y pregunta de cierre. Se muestra en el diagnóstico y queda como primer mensaje del hilo del Coach |
| `RU-COA-LIM-001` | No deseado | 🔒 | **Contexto:** el Coach nunca recibe las claves de los ítems situacionales. **Código:** `coach_context::match_catalog()` descarta todo recurso que no esté en el catálogo de apoyos activos. **Guardrail:** `GR-05` (calificación global) y `GR-10` (competente, apta y variantes) |
| `RU-COA-NIVEL-001` | Ubicuo | ✅ | `diagnostic::LEVEL_FLOORS`, con los cortes de Doc 3 §6.5 transcritos tal cual (2,25 · 3,25 · 4,25). La clasificación se hace sobre el valor redondeado, para que ningún puntaje caiga en el hueco entre 2,24 y 2,25. Reemplaza los 3 niveles en tercios de M1-v1.1.0 |
| `RU-COA-SUBDIM-001` | Donde | ❌ | **Bloqueado por contenido del cliente.** Requiere que el instrumento tenga subdimensiones (D1.1, D1.2…), y su estructura real está en Doc 3, que no se entregó. El schema del contrato ya reserva la clave |
| `RU-COA-PRIOR-002` | Ubicuo | ⚠️ | `diagnostic::rank_priorities()` aplica en código los criterios 1 (menor compuesto), 2 (discrepancia relevante, como desempate) y 7 (una por dimensión). **Límite:** los criterios 3 a 6 —necesidades declaradas, relevancia contextual, proyecto integrador y tiempo— requieren leer texto libre y quedan al Coach, que recibe la lista ordenada y solo puede reordenarla justificándolo. **Supuesto declarado:** el umbral de discrepancia relevante (1,0 punto) no está en la matriz |
| `RU-COA-PRIOR-003` | No deseado | ⚠️ | Una prioridad por dimensión, garantizado por `validate_payload()`; el prompt prioriza recursos disponibles en el catálogo. **Límite:** la regla D3.1/D3.2 necesita subdimensiones (ver `SUBDIM-001`) |
| `RU-COA-COMPLET-001` | No deseado | 🔒 | `diagnostic::completeness()`: 80% de declarativos **y** todos los situacionales. Una dimensión preliminar se marca en pantalla y en el contrato, no es candidata a meta, y con dos o más preliminares `priority_limit()` baja el tope a una |
| `RU-COA-REDAC-001` | Ubicuo | 🔒 | Prompt del Coach + `GR-10`, que ahora detecta "no sabes", "fallaste" y "tu nivel es bajo". "Debes corregir" queda solo en el prompt: como patrón daría falsos positivos en la retroalimentación legítima del Evaluador |
| `RU-COA-PDP-FLOW-001` | Evento | 🔒 | La meta se compone en código (`coach_report::compose_goal()`: verbo + capacidad + contexto), con evidencia y **plazo** como campos propios. `pdp::validate()` no activa el plan si a una meta aceptada le falta objetivo, acciones, plazo o evidencia, y dice cuál falta en cada una. `has_active_plan()` exige plan validado |

---

## 4. Modo C — Evaluador formativo

| ID | Patrón | Estado | Dónde |
|---|---|---|---|
| `RU-EVA-001` | Ubicuo | 🔒 | Fila `MODE_EVALUADOR` de `ACCESS_MATRIX`; sin catálogo ni apoyos adaptativos |
| `RU-EVA-002` | Evento | ✅ | Salida JSON por criterio de la rúbrica versionada, en [`classes/project.php`](../classes/project.php) |
| `RU-EVA-003` | Evento | ✅ | Prompt |
| `RU-EVA-004` | Evento | ⚠️ | Campo `pdpvinculo` por criterio. **Límite por diseño:** el Evaluador no escribe en el PDP; ninguna escritura al plan sin decisión de la persona |
| `RU-EVA-005` | No deseado | ✅ | Campo `needshuman` por criterio. Es revisión humana de la evaluación, no derivación de dudas: no choca con `RU-GEN-DERIV-001` |
| `RU-EVA-006` | No deseado | 🔒 | Prompt + `GR-05` + regla estructural `GR-09`: el plugin no invoca ninguna API de calificaciones |

---

## 5. Motor de apoyos adaptativos (transversal Tutor/Coach)

[`classes/adaptive.php`](../classes/adaptive.php). No es un cuarto modo: a partir de una meta aceptada del PDP, propone una microactividad, recibe la evidencia, la revisa contra criterios configurados y propone continuar, profundizar, actualizar o cerrar.

| ID | Patrón | Estado | Dónde |
|---|---|---|---|
| `RU-ADAPT-001` | No deseado | 🔒 | **Estructural**: el motor no contiene código capaz de crear una actividad de Moodle, y `cli/verificar_apoyos.php` lo comprueba sobre la fuente. Moodle registra el evento `adaptive_support_updated` en cada cambio de estado |
| `RU-ADAPT-002` | Evento | ✅ | `adaptive::proposal_prompt()` arma los siete insumos: dimensión y nivel, meta vigente, contexto autorizado, avance en recursos fijos (finalización de actividades), resultados de cuestionarios, criterios y evidencias previas de la misma meta. **Límite:** sin subdimensiones |
| `RU-ADAPT-003` | Donde | ⚠️ | Acepta texto, PDF o ambos, sin actividad de Moodle; el PDF va al modelo y no se guarda. **Límite:** la evidencia se entrega en la página del PDP y queda reflejada en el hilo del Coach; el widget de chat no la recibe directamente |
| `RU-ADAPT-004` | Evento | 🔒 | `adaptive::validate_review()`: el modelo **propone** estado y acción, el código decide. Un estado fuera del vocabulario cae en *requiere profundización*, nunca en avance. Toda actualización o cierre de meta queda pendiente hasta que la persona confirma (`adaptive::confirm()`) |
| `RU-ADAPT-005` | No deseado | 🔒 | `adaptive::render_review()` lo declara en cada revisión, y el texto pasa por `GR-05` antes de mostrarse |
| `RU-ADAPT-006` | Ubicuo | 🔒 | Los nueve estados del documento como constantes; `TRANSITIONS` es una tabla, y `set_state()` rechaza cualquier valor o salto que no esté en ella |
| `RU-ADAPT-007` | Evento | ✅ | `adaptive::structured_record()`, a partir de `local_chatbot_adapt` y `local_chatbot_adapt_review`: los 17 campos del requisito. Se guarda síntesis, nunca el texto ni el archivo entregado |

---

## 6. Gobernanza y límites

| ID | Patrón | Estado | Dónde |
|---|---|---|---|
| `RU-GOB-001` | No deseado | 🔒 | `GR-09` + la advertencia que acompaña al puntaje en prompt, pantalla, matriz de desarrollo y consentimiento |
| `RU-GOB-002` | Evento | ✅ | Rúbrica versionada y visible sobre el formulario de entrega en [`proyecto.php`](../proyecto.php) |
| `RU-GOB-003` | Ubicuo | ✅ | Cuatro decisiones sobre metas y recomendaciones; confirmación explícita en los apoyos adaptativos |
| `RU-GOB-004` | Ubicuo | 🔒 | Niveles de orientación presentados como "orientación de este momento" + `GR-10` |

---

## 7. Protección de datos y uso responsable de IA

Implementado como middleware, no como instrucción de prompt, salvo donde se indica.

| ID | Patrón | Estado | Dónde |
|---|---|---|---|
| `RU-PRIV-001` | Ubicuo | 🔒 | [`pseudonymizer`](../classes/pseudonymizer.php) en todo lo que sale; el texto libre del perfil se depura antes de entrar al objeto del Coach |
| `RU-PRIV-002` | Ubicuo | ✅ | Capabilities separadas; indicadores agregados; identidad, analítica y contenido en tablas distintas |
| `RU-PRIV-003` | No deseado | 🔒 | Instrucción común + `GR-04`, que bloquea |
| `RU-PRIV-004` | Evento | ✅ | `local_chatbot_interaction` con tipo de consulta clasificado por regla (`interaction::classify_intent()`). **Límite:** heurística de palabras clave; cae en `otro` si no reconoce el caso |
| `RU-PRIV-005` | No deseado | ✅ | **Corregido en v4.** Solo lo técnico va a soporte humano. Ante una falla técnica del proveedor, el mensaje a la persona incluye la ruta de soporte configurada, en vez de solo "intenta de nuevo" |
| `RU-PRIV-006` | Ubicuo | ✅ | Prompts versionados por modo y por fase. `seeder::refresh_system_prompts()` lleva a la versión vigente los prompts que ninguna persona editó, creando versión nueva y conservando la anterior |
| `RU-GEN-DERIV-001` | No deseado | ✅ | `assistant::scope_and_derivation_clause()`, inyectada por código en los tres modos: resuelve lo conceptual, redirige lo ajeno al módulo, deriva solo lo técnico. Va siempre, haya o no contacto de soporte configurado. **Límite:** capa de prompt; no hay guardrail que detecte una derivación indebida en la respuesta |

---

## 8. Consentimiento y habilitación de acceso

| ID | Patrón | Estado | Dónde |
|---|---|---|---|
| `RU-CONS-001` | Evento | ✅ | Declaración completa sobre el formulario de perfilamiento en [`index.php`](../index.php) |
| `RU-CONS-002` | No deseado | 🔒 | `profile::has_consent()` como condición de entrada en todos los puntos que usan el asistente |
| `RU-CONS-003` | Evento | 🔒 | La matriz de acceso decide qué se envía; el contenido del proyecto solo viaja en `project::evaluate()` |
| `RU-CONS-004` | Evento | ✅ | `profile::data_disclosure()`: proveedor, región, conservación y canal de derechos, desde la configuración del sitio; lo no declarado se muestra en rojo |

---

## 9. Backend — Puntaje compuesto de orientación

| ID | Patrón | Estado | Dónde |
|---|---|---|---|
| `RU-CALC-001` | Ubicuo | ✅ | `diagnostic::composite_score()`, función pura: `PCd = 0,7 × PDd + 0,3 × CSd`, con `CSd = 1 + 4 × P_situacional`. Verificada en [`cli/verificar_calculo.php`](../cli/verificar_calculo.php) |

---

## 10. Requisitos no funcionales

> El spec marca esta sección como **recomendaciones que no provienen de los documentos del cliente**, y pide validarlas antes de tratarlas como definitivas. Se implementaron porque son defensivas y reversibles; su validación con el cliente sigue pendiente.

| ID | Estado | Dónde |
|---|---|---|
| `NFR-SEC-001` | 🔒 | Texto de la persona aislado entre delimitadores (`GR-07`); jerarquía de instrucciones declarada en `guardrails::injection_clause()` |
| `NFR-SEC-002` | ✅ | `scope_and_derivation_clause()`: lo ajeno al módulo se redirige, no se resuelve ni se deriva. Capa de prompt, inyectada por código |
| `NFR-SEC-003` | ✅ | `guardrails::attachment_notice()` antes de cada PDF, en el chat y en las evidencias de apoyos. Un PDF no se puede envolver en delimitadores de texto; lo que se garantiza es que nunca llega al modelo sin la marca que lo declara material a evaluar |
| `NFR-SEC-004` | ✅ | `rate_limiter`; cada caso que alcanza el límite queda en `local_chatbot_interaction` con estado `ratelimit` |
| `NFR-SEC-005` | ⚠️ | El motor rechaza evidencia ofensiva, con datos de terceros o que intenta explotar el sistema: no entra al registro estructurado, queda el motivo en categoría cerrada y se registra `GR-12`. **Límite:** "notificar según el protocolo institucional" depende de un protocolo que aún no existe; el evento de Moodle queda como punto de enganche |
| `NFR-ARCH-001` | ✅ | [`provider_factory::catalog()`](../classes/ai/provider_factory.php): Gemini, Claude, OpenAI y Azure AI Foundry, intercambiables desde la configuración |
| `NFR-ARCH-002` | ✅ | Prompts, guardrails y contratos viven en el plugin, independientes del proveedor; el formato de mensajes es canónico y cada adaptador lo traduce |

---

## Resumen

| Bloque | Requisitos | ✅/🔒 | ⚠️ | ❌ |
|---|---|---|---|---|
| 0. Núcleo | 3 | 3 | — | — |
| 1. Fase diagnóstico | 4 | 4 | — | — |
| 2. Tutor | 8 | 8 | — | — |
| 3. Coach | 17 | 13 | 3 | 1 |
| 4. Evaluador | 6 | 5 | 1 | — |
| 5. Apoyos adaptativos | 7 | 6 | 1 | — |
| 6. Gobernanza | 4 | 4 | — | — |
| 7. Protección de datos | 7 | 7 | — | — |
| 8. Consentimiento | 4 | 4 | — | — |
| 9. Cálculo | 1 | 1 | — | — |
| 10. No funcionales | 7 | 6 | 1 | — |
| **Total** | **68** | **61** | **6** | **1** |

**Lo que no está completo, y por qué:**

- ❌ `RU-COA-SUBDIM-001` — necesita las subdimensiones reales del instrumento, que están en Doc 3.
- ⚠️ `RU-COA-PRIOR-002` y `PRIOR-003` — cuatro de siete criterios requieren leer texto libre, y la regla D3.1/D3.2 necesita subdimensiones.
- ⚠️ `RU-COA-005` — no hay re-priorización automática entre metas.
- ⚠️ `RU-EVA-004` — el Evaluador no escribe en el PDP, por diseño.
- ⚠️ `RU-ADAPT-003` — la evidencia se entrega en la página del PDP, no en el widget.
- ⚠️ `NFR-SEC-005` — falta el protocolo institucional de notificación.

## Ambigüedades del propio spec

1. **La corrección de derivación quedó a medias.** `RU-GEN-DERIV-001` corrige a `RU-PRIV-005`, pero `RU-DIAG-004` y el prompt de la fase de diagnóstico (línea 82 del spec) siguen hablando de derivar "preguntas fuera del alcance del instrumento". Se aplicó la corrección también ahí.
2. **`RU-GEN-003` no se actualizó.** La nota de `RU-COA-OUT-001` dice que queda vigente "para el Evaluador"; el prompt del Coach dice "Coach de seguimiento / Evaluador". Se implementó la segunda lectura: plantilla de Doc 3 en la primera salida, cinco campos en el seguimiento.
3. **`RU-TUT-008` aparece dos veces** con el mismo texto (secciones 2 y 5). Se cuenta una vez.
4. **Cuatro niveles contra tres categorías.** `RU-COA-NIVEL-001` define cuatro; `RU-COA-002` sigue hablando de tres. Ver la fila de `RU-COA-002`.

---

## Verificación ejecutable

Cinco verificadores sin base de datos ni bootstrap de Moodle, encadenables a CI (salen con código 1 ante cualquier desvío):

```bash
php cli/verificar_matriz_acceso.php   # 44 celdas: qué ve cada modo y la fase de diagnóstico
php cli/verificar_guardrails.php      # 26 casos: activación de cada regla y sus falsos positivos
php cli/verificar_calculo.php         # 42 casos: fórmula, 4 niveles, completitud, discrepancia, priorización
php cli/verificar_coach.php           # 23 casos: matriz de desarrollo y reglas impuestas al modelo
php cli/verificar_apoyos.php          # 39 casos: vocabulario, transiciones, revisión y RU-ADAPT-001
```

Los que prueban reglas impuestas al modelo le entregan respuestas que las violan todas a la vez y comprueban que lo que queda es conforme. Los de guardrails incluyen falsos positivos a propósito: un filtro que bloquea todo también incumple, solo que en silencio.

**Lo que estas verificaciones no cubren:** las llamadas reales al modelo. Los flujos contra la base de datos —contrato del Coach, activación del PDP, estados y confirmación de apoyos, analítica— se probaron sobre la instalación local con un usuario de prueba, sin enviar datos a un proveedor. Las reglas de salida siguen siendo heurísticas de patrón: atrapan la formulación frecuente, no toda formulación posible. La defensa es en capas y ninguna se sostiene sola.
