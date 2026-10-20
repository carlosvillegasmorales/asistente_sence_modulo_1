# Cruce: documento de arquitectura vs. implementación

Verificación sección por sección del documento *Arquitectura del Módulo 1* (Producto 4, Interfases Consultores, septiembre 2026) contra el código del Asistente IA (`local_chatbot`) M1-v1.2.0.

> **Documento hermano:** [TRAZABILIDAD_RU.md](TRAZABILIDAD_RU.md) cruza el mismo código contra la matriz `RU/RS/EARS` v4, requisito por requisito y con identificador estable. Este archivo cruza contra el documento de arquitectura, sección por sección. Se conservan los dos porque responden preguntas distintas: aquí, *qué del documento quedó fuera*; allá, *dónde vive cada requisito*.

**Convención:**
- ✅ Implementado en el plugin
- 🟦 Corresponde a Moodle nativo (configuración de curso), no al plugin
- ⚠️ Implementado parcialmente, con el límite indicado
- ❌ No implementado

---

## 1. Arquitectura pedagógica — los seis componentes

| Componente | Producto / evidencia | Salida para la suite | Estado |
|---|---|---|---|
| **0. Bienvenida y perfilamiento** | Perfil de facilitación + aceptación de condiciones de uso de IA | Segmentación inicial y contexto | ✅ `index.php`, `classes/profile.php`, tabla `local_chatbot_profile` |
| **1. Diagnóstico auto aplicado** | Respuestas estructuradas y perfil de brechas | Matriz individual de brechas | ✅ `diagnostico.php`, `classes/diagnostic.php` |
| **2. Ruta formativa base** | Lecturas, casos, cuestionarios | Evidencia de progreso | 🟦 Moodle (Libro/Página/Quiz). El plugin solo lee el estado de los cuestionarios para la regla de evaluación activa |
| **3. Plan Personal de Desarrollo** | PDP validado o ajustado | Metas, compromisos, seguimiento | ✅ `pdp.php`, `classes/pdp.php`, con historial de cambios |
| **4. Proyecto integrador** | Proyecto, autoevaluación, retroalimentación contra rúbrica | Nivel de integración, brechas persistentes | ✅ `proyecto.php`, `classes/project.php` |
| **5. Seguimiento y cierre** | Plan de continuidad y acciones priorizadas | Datos para analítica, Módulo 2 y 3 | ✅ `cierre.php`, `classes/closure.php`, `classes/suite_export.php` |

**"Activación de ruta adaptativa"** (salida del componente 1): ⚠️ el diagnóstico alimenta al Coach para priorizar metas, pero el plugin no altera la secuencia de actividades del curso. La adaptación de la ruta se configura con restricciones de acceso de Moodle.

**Micro cápsulas** (mencionadas como recurso complementario): ❌ no implementadas como objeto propio. Los recursos complementarios se cargan como recursos internos del módulo o como actividades de Moodle. Lo más cercano es el motor de apoyos adaptativos de M1-v1.2.0, que genera microactividades individuales en la conversación sin crear objetos en el curso.

---

## 2. Reglas de progresión

| Regla del documento | Estado |
|---|---|
| Bienvenida y diagnóstico disponibles desde el primer acceso | ✅ |
| Cuatro unidades como ruta común, cursado secuencial | 🟦 Restricciones de acceso de Moodle |
| Cada unidad se completa con lectura, caso, ejemplo y cuestionario | 🟦 Finalización de actividad de Moodle |
| El cuestionario conecta conceptos con decisiones del caso | 🟦 Diseño de los ítems en el banco de preguntas |
| El PDP se genera tras el diagnóstico y queda visible y editable todo el módulo | ✅ `pdp.php` accesible en todo momento |
| El proyecto se habilita con las cuatro unidades completas **y** una primera versión del PDP | ⚠️ El plugin verifica el PDP (`pdp::has_active_plan()`). Las cuatro unidades se controlan con restricción de acceso de Moodle sobre la sección que enlaza al proyecto |
| El cierre ocurre con proyecto revisado, retroalimentación recibida, reflexión y PDP ajustado | ✅ `closure::get_conditions()` comprueba las cuatro condiciones |

---

## 3. Arquitectura del asistente IA

### Estructura

| Requisito | Estado |
|---|---|
| Un solo asistente con tres modos funcionales explícitos | ✅ `classes/assistant.php` |
| El usuario siempre sabe en qué modo está | ✅ Banner permanente en el widget |
| El usuario siempre sabe qué información usa el asistente | ✅ Lista de fuentes bajo cada respuesta, y guardada en `local_chatbot_interaction.resources` |

### Fase de diagnóstico (previa al envío del instrumento)

No aparece en el documento de arquitectura, que describe tres modos. La incorporó la matriz
`RU/RS/EARS` (RU-DIAG-001 a 004): mientras la persona responde el instrumento y no lo envía, el
asistente opera bajo restricciones propias, porque orientar la autoevaluación invalida la línea
base sobre la que se construye todo el resto del módulo.

| Conducta exigida | Estado |
|---|---|
| Explicar términos, funcionamiento de la actividad y diferencias conceptuales, con ejemplos generales | ✅ prompt propio, versionado |
| Invitar a responder según la práctica habitual, no según lo deseable | ✅ prompt + cláusula inyectada por código |
| No sugerir alternativas, interpretar respuestas, sugerir puntajes ni inferir resultados | ✅ **en tres capas**: fila propia de `ACCESS_MATRIX` enteramente denegada, cláusula no editable, y guardrail `GR-11` que bloquea |
| No revelar la respuesta correcta de un ítem situacional | ✅ `correctoption` nunca sale de la capa de datos: el acierto se resuelve en el servidor |
| Derivar a soporte humano solo ante falla técnica; las dudas conceptuales las resuelve el asistente | ✅ mismo mecanismo transversal, corregido en M1-v1.2.0 (RU-GEN-DERIV-001) |

---

### Modo A — Tutor de aprendizaje

| Conducta exigida | Estado |
|---|---|
| Explicar en lenguaje técnico claro, aplicado a personas adultas | ✅ prompt v1 |
| Relacionar lectura, caso, ejemplo y quiz | ✅ prompt v1 |
| Preguntas socráticas antes de responder | ✅ prompt v1 |
| Pedir que la persona explicite su razonamiento | ✅ prompt v1 |
| Distinguir apoyo a la comprensión de resolver una evaluación | ✅ prompt v1 |
| Remitir a recursos internos antes de conocimiento general | ✅ prompt v1 + inyección de la base documental |
| Reconocer incertidumbre y pedir contexto | ✅ prompt v1 |
| Ejemplos pertinentes sin afirmar requisitos sectoriales no verificados | ✅ prompt v1 |
| **Regla esencial:** no indicar la alternativa correcta de un ítem de quiz no respondido | ✅ **en código**, no solo en prompt: `assistant::quiz_guard_clause()` consulta `{quiz_attempts}` y le informa al modelo qué cuestionarios siguen pendientes |

### Modo B — Coach de desarrollo

| Conducta exigida | Estado |
|---|---|
| Interpretación comprensible del perfil de brechas | ✅ |
| Distinguir fortalezas, brechas prioritarias y ámbitos de profundización | ✅ cuatro niveles de orientación de Doc 3 (`diagnostic::level_for_score()`), agrupados en esas tres categorías por `level_group()` |
| Proponer un PDP revisable, aceptable, modificable, **postergable** o rechazable | ✅ metas nacen en estado `propuesto`; las cuatro decisiones existen como estado (RU-COA-004) |
| Máximo de tres prioridades iniciales, cada una con unidad, recurso, microcápsula, actividad y evidencia | ✅ tope en `pdp::MAX_PRIORIDADES`, aplicado recortando la respuesta del modelo; los cinco vínculos son columnas de `local_chatbot_pdp_goal` (RU-COA-003) |
| Vincular cada acción con una brecha y una evidencia | ✅ campos `brecha` y `evidencia` en cada meta |
| Seguimiento iterativo con preguntas breves | ✅ prompt v1 |
| Ajustar prioridades con base en nuevas evidencias | ⚠️ el Coach ve el PDP vigente —incluidas las metas postergadas— y el motor de apoyos adaptativos propone actualizar o cerrar una meta a partir de evidencia revisada, con confirmación de la persona. No hay re-priorización automática entre metas |
| **Recomendar actividades concretas disponibles en Moodle** | ⚠️ desde M1-v1.2.0 el Coach recibe el catálogo de apoyos activos —recursos internos y actividades del curso que la persona puede ver— y un nombre que no esté en él se descarta en código (RU-COA-LIM-001). **Límite:** los nombra, no enlaza a la actividad |
| No emitir diagnósticos psicológicos, laborales ni personales | ✅ prompt v1 |
| No presentar recomendaciones como obligatorias | ✅ prompt v1 + flujo de decisión |

### Modo C — Evaluador formativo

| Conducta exigida | Estado |
|---|---|
| Leer solo los campos y evidencias autorizadas | ✅ solo se envían los campos del proyecto |
| Aplicar la rúbrica criterio por criterio | ✅ salida JSON por criterio |
| Identificar evidencia explícita, ausencia e inconsistencias | ✅ prompt v1 |
| Comentarios descriptivos, accionables y respetuosos | ✅ prompt v1 |
| Diferenciar observación, inferencia y recomendación | ✅ prompt v1 |
| Sugerir ajustes específicos en el proyecto | ✅ campo `accion` |
| Proponer la actualización del PDP | ⚠️ entrega el vínculo en el campo `pdpvinculo`, pero no crea ni modifica metas por sí solo (por diseño: ninguna escritura al PDP sin decisión de la persona) |
| Indicar cuándo se requiere revisión humana | ✅ campo `needshuman` por criterio |

### Formato estándar de salida

Las cinco partes exigidas están como columnas de `local_chatbot_project_fb` y se muestran así en pantalla:

| Parte | Campo |
|---|---|
| Fortaleza observada | `fortaleza` ✅ |
| Aspecto a mejorar | `mejora` ✅ |
| Por qué importa | `porque` ✅ |
| Acción sugerida | `accion` ✅ |
| Vínculo con el PDP | `pdpvinculo` ✅ |

---

## 4. Arquitectura Moodle

Las nueve secciones recomendadas (0 a 8) son configuración del curso. El plugin aporta las páginas de las secciones 0, 1, 6, 7 y 8; las unidades (2 a 5) son contenido de Moodle.

| Configuración clave | Estado |
|---|---|
| Finalización de actividad | 🟦 Moodle |
| Restricciones de acceso | 🟦 Moodle |
| Libro de calificaciones (separar formativo de integrador) | 🟦 Moodle. El plugin no escribe calificaciones: su retroalimentación es formativa |
| Rúbrica visible y parametrizada | ✅ rúbrica propia del plugin, versionada y visible antes de entregar. Ver nota abajo |
| Banco de preguntas etiquetado | 🟦 Moodle |
| Competencias Moodle | 🟦 Moodle |
| **Logs y eventos** (incluye hitos del PDP e interacción con IA) | ✅ cuatro eventos en `classes/event/`: diagnóstico completado, decisión sobre meta del PDP, interacción con el asistente y proyecto evaluado |
| Grupos y cohortes | 🟦 Moodle |
| Notificaciones | 🟦 Moodle |

**Nota sobre la rúbrica:** el documento sugiere usar la rúbrica nativa de Moodle. La rúbrica se implementó dentro del plugin porque la gobernanza exige versionarla, y la rúbrica nativa de Moodle no versiona. Si el curso además califica con la rúbrica nativa, ambas deben mantenerse alineadas a mano.

---

## 5. Objetos de datos

| Objeto | Campos esenciales del documento | Estado |
|---|---|---|
| **Perfil de facilitador/a** | Rol, contexto, experiencia, modalidad, sector, necesidad declarada | ✅ los seis, en `local_chatbot_profile` |
| **Resultado diagnóstico** | Dimensión, indicador, puntaje, nivel, fecha, evidencia de respuesta | ✅ en `local_chatbot_diag_response`. El puntaje es el compuesto de orientación (70% práctica declarada + 30% componente situacional, RU-CALC-001) y el nivel se deriva de él |
| **Unidad formativa** | Estado, tiempo, avance, quiz y actividades realizadas | 🟦 Moodle: finalización de actividad y logs |
| **Resultado de quiz** | Ítem, dimensión, respuesta, intento, retroalimentación consultada | ⚠️ Moodle guarda todo salvo la **dimensión**: el banco de preguntas no está mapeado a las dimensiones del diagnóstico. Requiere etiquetar el banco y un cruce posterior |
| **Interacción IA** | Modo, fecha, intención, recursos referidos, recomendación, aceptación del usuario | ✅ los seis, en `local_chatbot_interaction`, más la versión de prompt. La intención es una etiqueta clasificada por regla, no un recorte del mensaje (RU-PRIV-004) |
| **PDP** | Brecha, objetivo, acciones, evidencia, estado, revisión y versión | ✅ los siete, entre `local_chatbot_pdp`, `_pdp_goal` y `_pdp_history` |
| **Proyecto integrador** | Campos estructurados, archivo, versión, rúbrica, feedback, mejora | ✅ los seis, en `local_chatbot_project` y `_project_fb` |
| **Cierre** | Diagnóstico de salida, reflexión y PDP final | ✅ diagnóstico de salida en `_diag_response` (etapa `salida`), reflexión y continuidad en `local_chatbot_closure` |

---

## 6. Indicadores iniciales

| Indicador | Estado |
|---|---|
| % que completa diagnóstico, ruta base, PDP y proyecto | ⚠️ el embudo cubre perfilamiento, diagnóstico, PDP, proyecto y cierre. La **ruta base** depende de finalización de actividad de Moodle |
| Distribución de brechas por dimensión | ✅ |
| Variación entre diagnóstico inicial y contraste de salida | ✅ |
| Nivel de logro por unidad y error recurrente en quizzes | ❌ requiere el mapeo banco de preguntas ↔ dimensiones |
| Tiempo y secuencia de navegación por componente | ❌ derivable de los logs de Moodle, no calculado por el plugin |
| Actividades del PDP recomendadas, aceptadas, modificadas y completadas | ✅ |
| Interacciones con el asistente por modo funcional | ✅ |
| Recursos de mayor uso y utilidad percibida | ❌ el plugin no registra apertura de recursos ni recoge utilidad percibida |
| Calidad de los proyectos según criterios de rúbrica | ⚠️ se reporta dónde se concentran las observaciones de mejora, no un puntaje de calidad |
| Brechas persistentes tras la retroalimentación | ✅ |
| Diferencias entre grupo prueba y grupo control | ❌ requiere definir la asignación de grupos del pilotaje (cohortes de Moodle) |

---

## 7. Reglas de gobernanza

### Límites pedagógicos y evaluativos — 7 de 7

| Regla | Estado |
|---|---|
| La autoevaluación no se usa para consecuencias administrativas ni certificadoras | ✅ declarado en el consentimiento; el plugin no escribe en el libro de calificaciones |
| La IA no sustituye decisiones humanas formales | ✅ declarado en el consentimiento y en cada pantalla de retroalimentación |
| Rúbrica visible antes de entregar | ✅ se muestra completa sobre el formulario |
| Criterios de ajuste del PDP trazados: qué, por qué, con qué evidencia | ✅ `local_chatbot_pdp_history`, solo de escritura |
| Toda recomendación permite revisión, aceptación, edición o rechazo | ✅ metas del PDP y respuestas de coach/evaluador |
| Evitar clasificaciones estigmatizantes | ✅ niveles de *brecha prioritaria / profundización / fortaleza*. Se eliminaron las alertas de "Frustración/Deserción detectada" de la versión anterior |
| Explicitar qué información usa el asistente | ✅ fuentes declaradas bajo cada respuesta |

### Protección y uso responsable de IA — 6 de 7

| Regla | Estado |
|---|---|
| Minimizar la información personal compartida con el modelo | ✅ |
| Identificadores pseudonimizados y datos estrictamente necesarios | ✅ `classes/pseudonymizer.php`; además se depuran correos y RUT del texto |
| Separar identidad, analítica y contenido pedagógico según permisos | ✅ cuatro capabilities; los indicadores son agregados |
| Evitar que el bot solicite datos sensibles innecesarios | ✅ instrucción explícita en el marco común de los tres modos |
| Registro de interacciones, monitoreo de errores y revisión de respuestas problemáticas | ⚠️ registro ✅ y eventos ✅; **no hay** panel de revisión de respuestas problemáticas ni alertas de tasa de error |
| Ruta de derivación a soporte humano | ✅ configurable en Site administration, inyectada en los tres prompts |
| Versionar prompts, rúbricas, reglas de recomendación y bases documentales | ✅ prompts, rúbrica y recursos internos versionan. Las "reglas de recomendación" viven dentro de los prompts versionados |

---

## 8. Integración con la suite

Los seis contratos están definidos como estructuras de datos en `classes/suite_export.php`:

| Destino | Método | Estado |
|---|---|---|
| Capa usuaria | `for_user_layer()` | ✅ contrato definido |
| Módulo 2 | `for_module_two()` | ✅ contrato definido |
| Módulo 3 | `for_module_three()` | ✅ contrato definido |
| Capa analítica | `for_analytics_layer()` | ✅ contrato definido |
| Gestión institucional | `for_institutional_management()` | ✅ contrato definido |
| FAQ y soporte | — | ❌ el plugin no captura preguntas recurrentes ni dificultades de navegación de forma estructurada |

**Límite importante:** están los *contratos*, no el *transporte*. No hay web services (`db/services.php`) ni tarea programada que empuje estos datos. Cuando exista el transporte real de la suite, se conecta sin rediseñar qué se envía.

---

## Resumen

| Bloque | Cumplido | Moodle nativo | Parcial | No implementado |
|---|---|---|---|---|
| Componentes pedagógicos (6) | 5 | 1 | — | — |
| Reglas de progresión (7) | 2 | 4 | 1 | — |
| Conductas del asistente (31) | 28 | — | 3 | — |
| Formato estándar de salida (5) | 5 | — | — | — |
| Objetos de datos (8) | 6 | 1 | 1 | — |
| Indicadores (11) | 5 | — | 2 | 4 |
| Gobernanza (14) | 13 | — | 1 | — |
| Integración con la suite (6) | 5 | — | — | 1 |

**Lo que falta y por qué:**

1. **Mapeo banco de preguntas ↔ dimensiones del diagnóstico.** Desbloquea dos indicadores (error recurrente por unidad, nivel de logro por dimensión). Requiere una decisión de diseño instruccional sobre cómo etiquetar el banco, no solo código.
2. **Enlace directo a la actividad recomendada por el Coach.** Desde M1-v1.2.0 el Coach solo nombra recursos que existen en el curso; falta que la recomendación enlace a la actividad.
3. **Panel de revisión de respuestas problemáticas y monitoreo de tasa de error.** Los datos ya se registran; falta la vista de operación.
4. **Transporte real de la integración con la suite.** Depende de que se defina la interfaz de los otros módulos.
5. **Indicadores de navegación, uso de recursos y grupo control.** Dependen de los logs de Moodle y de la definición del diseño del pilotaje.
6. **Contenido real de los ítems situacionales.** RU-CALC-001 trajo el mecanismo y se cargó un ítem situacional genérico por dimensión como punto de partida. Escribir los del pilotaje es diseño instruccional, no código: cada uno necesita una situación verosímil del oficio y un distractor que no sea obviamente descartable, o el 30% del puntaje compuesto mide la capacidad de eliminar alternativas absurdas.
7. **Antecedentes de tratamiento de datos por declarar.** RU-CONS-004 exige proveedor, región de procesamiento, conservación y canal de derechos. El plugin los expone y marca en rojo los que faltan, pero los valores dependen del contrato con el proveedor de IA. Comprobar con `profile::disclosure_is_complete()` antes de producción.
