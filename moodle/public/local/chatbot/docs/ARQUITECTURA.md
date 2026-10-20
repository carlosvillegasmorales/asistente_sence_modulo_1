# Arquitectura técnica — Asistente IA (`local_chatbot`) M1-v1.2.0

Documento técnico del plugin que implementa la capa de asistente IA e instrumentos del Módulo 1.

---

## 1. Ubicación en la solución

El Módulo 1 se monta como un **curso Moodle**. El plugin es un `local` plugin que aporta lo que el curso no puede resolver por sí solo.

```
┌─ Curso Moodle "Módulo 1" ───────────────────────────────┐
│                                                          │
│  Sec. 0  Comienza aquí        → enlaza a index.php       │
│  Sec. 1  Diagnóstico          → enlaza a diagnostico.php │
│  Sec. 2-5 Unidades 1 a 4      → Libro / Página / Quiz    │  ← Moodle
│  Sec. 6  Mi Plan              → enlaza a pdp.php         │
│  Sec. 7  Proyecto integrador  → enlaza a proyecto.php    │
│  Sec. 8  Cierre               → enlaza a cierre.php      │
│                                                          │
│  Widget flotante del asistente (3 modos) en toda página  │
└──────────────────────────────────────────────────────────┘
```

El plugin nunca escribe en el libro de calificaciones ni altera la secuencia del curso: la progresión se controla con restricciones de acceso de Moodle.

---

## 2. Recorrido de un mensaje

```
Navegador (js/chatbot-widget.js)
   │  modo + mensaje + adjunto opcional
   ▼
ajax.php
   ├─ require_login + require_sesskey + capability :use
   ├─ ¿consentimiento aceptado?  → si no, corta aquí
   ├─ courseid verificado contra matrícula real
   ├─ assistant::build_context(modo)
   │     ├─ prompt versionado del modo (prompt_store)
   │     ├─ seudónimo de la persona y del curso (pseudonymizer)
   │     ├─ perfil declarado
   │     ├─ recursos internos activos (kb_store)
   │     ├─ contexto propio del modo:
   │     │     tutor     → estado de cuestionarios pendientes
   │     │     coach     → matriz de brechas + PDP vigente
   │     │     evaluador → rúbrica activa + PDP vigente
   │     ├─ ruta de derivación a soporte humano
   │     └─ devuelve: prompt, lista de fuentes, versión de prompt
   ├─ historial de los últimos 10 turnos DEL MISMO MODO
   ├─ pseudonymizer::scrub() sobre todo texto que sale
   ▼
provider_factory::get()->generate_content()
   → Gemini | Claude | OpenAI | Azure   (formato canónico Gemini)
   ▼
ajax.php
   ├─ guarda la respuesta en local_chatbot_messages (con modo)
   ├─ interaction::record() → dispara evento ai_interaction_recorded
   └─ responde: reply + resources + interactionid
   ▼
Navegador
   ├─ sanitiza con DOMPurify antes de innerHTML
   ├─ muestra las fuentes bajo la respuesta
   └─ en coach/evaluador: botones aceptar / modificar / descartar
         └─ ajax_decision.php → interaction::set_decision()
```

---

## 3. Mapa de clases

### Núcleo del asistente

| Clase | Responsabilidad |
|---|---|
| `assistant` | Define los tres modos y la fase de diagnóstico, la matriz de acceso, y arma el prompt y la lista de fuentes de cada uno |
| `coach_context` | Objeto de datos del Coach con el schema de Doc 3 §10.1, y catálogo de apoyos activos |
| `guardrails` | Aislamiento del contenido de la persona y filtros de salida GR-01 a GR-12 |
| `prompt_store` | Prompts versionados por modo. Editar crea versión nueva, nunca sobrescribe |
| `pseudonymizer` | Seudónimos derivados por hash y depuración de correos y RUT |
| `interaction` | Objeto Interacción IA: modo, intención, fuentes, recomendación y decisión |

### Instrumentos del módulo

| Clase | Responsabilidad |
|---|---|
| `profile` | Perfil de facilitación y consentimiento informado |
| `diagnostic` | Instrumento, puntaje compuesto, 4 niveles de orientación, completitud, discrepancia y priorización (funciones puras) |
| `pdp` | Plan de desarrollo, decisión sobre metas, historial, activación con plazos y evidencias |
| `coach_report` | Matriz de desarrollo: primera entrega del Coach con la plantilla de Doc 3 §9.1, impuesta en código |
| `adaptive` | Motor de apoyos adaptativos: propuesta, evidencia, revisión, confirmación y máquina de estados |
| `project` | Proyecto integrador, rúbrica y evaluación del Modo C |
| `closure` | Condiciones de cierre y declaración de continuidad |
| `seeder` | Instrumento base (ítems declarativos y situacionales, criterios formativos, rúbrica, prompts) y actualización de prompts no editados |

### Salidas

| Clase | Responsabilidad |
|---|---|
| `analytics` | Indicadores agregados y anonimizados |
| `suite_export` | Contratos de datos hacia los otros componentes de la suite |
| `kb_store` | Recursos internos del módulo, versionados |

### Proveedores de IA

`classes/ai/` mantiene el formato canónico de Gemini como lingua franca interna. Cada adaptador traduce a su API y devuelve siempre una respuesta con forma de Gemini, de modo que el resto del plugin no distingue proveedores.

```
ai_provider_interface
   ├─ gemini_provider        → envuelve gemini_client
   ├─ anthropic_provider     → Messages API + cache_control
   ├─ openai_provider        → Chat Completions
   └─ azure_foundry_provider → extiende openai_provider (URL + auth)
                provider_factory decide cuál según configuración
```

---

## 4. Modelo de datos — 18 tablas

```
local_chatbot_profile ──────── perfil + consentimiento (1 por persona/curso)

local_chatbot_dimension ─┬──── local_chatbot_diag_item
                         └──── local_chatbot_diag_response  (entrada | salida)

local_chatbot_pdp ───────┬──── local_chatbot_pdp_goal       (con plazo)
                         └──── local_chatbot_pdp_history    (solo escritura)

local_chatbot_coach_report ──── matriz de desarrollo (plantilla de Doc 3)
local_chatbot_adapt ─────────── local_chatbot_adapt_review  (una por evidencia:
                                                             sintesis, nunca el original)

local_chatbot_criterion ─────── rúbrica versionada (active = vigente)
local_chatbot_project ───────── local_chatbot_project_fb    (5 campos del
                                                             formato estándar)
local_chatbot_closure ───────── reflexión + continuidad + acciones

local_chatbot_messages ──────── conversación, etiquetada por modo
local_chatbot_interaction ───── auditoría del asistente
local_chatbot_prompt ────────── prompts versionados
local_chatbot_kb_documents ──── recursos internos versionados
```

**Convenciones:**
- Todo lo que la persona produce está acotado por `userid + courseid`.
- `active = 0` retira sin borrar: aplica a rúbrica y recursos internos, para no perder el rastro de qué estaba vigente cuando se generó una respuesta.
- El historial del PDP no se actualiza ni se elimina.

### Áreas de archivo

| Área | itemid | Quién puede leer |
|---|---|---|
| `chat_attachment` | id del mensaje | Solo quien lo subió |
| `project_attachment` | id del proyecto | Solo el dueño del proyecto |

Ambas se sirven por `local_chatbot_pluginfile()` con verificación de propiedad.

---

## 5. Eventos

| Evento | Cuándo | Qué lleva |
|---|---|---|
| `diagnostic_completed` | Al guardar una aplicación del diagnóstico | Etapa y cantidad de ítems |
| `pdp_goal_decided` | Al aceptar, modificar, descartar o completar una meta | Decisión y origen de la meta |
| `ai_interaction_recorded` | En cada interacción con el asistente | Modo y versión de prompt |
| `project_evaluated` | Al emitir retroalimentación del proyecto | Si requiere revisión humana |
| `adaptive_support_updated` | En cada cambio de estado de un apoyo adaptativo | Estado del vocabulario controlado |

Al log de Moodle va el hecho y sus metadatos, nunca el contenido de la conversación: ese ya vive en las tablas del plugin.

---

## 6. Puntos de extensión

**Agregar una dimensión o ítem al diagnóstico** — desde *Instrumento del Módulo 1*, o programáticamente en `seeder::default_dimensions()` para nuevas instalaciones.

**Cambiar el comportamiento de un modo** — editar su prompt en *Instrumento del Módulo 1*. Queda como versión nueva y las interacciones posteriores registran esa versión.

**Instrumento por curso** — las tablas `dimension` y `criterion` tienen `courseid`. Con `courseid = 0` es el instrumento base del sitio; si existe uno del curso, tiene prioridad. La interfaz actual solo administra el base.

**Conectar la integración con la suite** — implementar `db/services.php` o una tarea programada que consuma `suite_export`. Los contratos ya están definidos.

---

## 7. Seguridad

| Control | Implementación |
|---|---|
| Autenticación | `require_login()` en toda página y endpoint |
| Autorización | Cuatro capabilities; cada página exige la suya |
| CSRF | `require_sesskey()` / `confirm_sesskey()` en toda acción que cambia estado |
| Aislamiento entre personas | Toda consulta acotada por `$USER->id`; nunca se acepta un `userid` por parámetro |
| Suplantación de curso | `is_enrolled()` sobre el `courseid` recibido |
| XSS | `s()` en salida de páginas, DOMPurify antes de `innerHTML` en el widget |
| Archivos | Validación por contenido real (`mime_content_type`), no por MIME del navegador |
| Fuga de datos personales | Pseudonimización y depuración de texto antes de salir al proveedor |
| Secretos | API keys en `admin_setting_configpasswordunmask` |

**Pendiente conocido:** no hay rate limiting en `ajax.php`.

---

## 8. Costo

Cada mensaje reenvía el prompt del modo más los recursos internos. Ese bloque es estable dentro de una conversación, así que:

- **Gemini y OpenAI** cachean el prefijo repetido automáticamente.
- **Claude** lo marca explícitamente con `cache_control` en `anthropic_provider`.
- **Azure** depende del despliegue; no verificado.

El orden del prompt está armado para eso: contenido estable primero, variable al final.

Si los recursos internos crecen mucho, el siguiente paso es búsqueda selectiva por fragmentos (RAG) en vez de reenviar todo. No implementado.

---

## 9. Estado de verificación

| Verificación | Estado |
|---|---|
| Sintaxis PHP de todos los archivos | ✅ |
| Sintaxis del JS del widget | ✅ |
| `install.xml` contra `xmldb.xsd` de Moodle | ✅ 18 tablas |
| Cadenas de idioma completas y ES/EN alineadas | ✅ 445 en cada idioma |
| Upgrade en base de datos real | ✅ M1-v1.2.0 aplicado en la instalación local con `admin/cli/upgrade.php` |
| Flujos contra la base de datos, sin modelo | ✅ 62 comprobaciones con un usuario de prueba (contrato, PDP, apoyos, analítica) |
| Verificadores puros (`cli/`) | ✅ 174 casos en cinco scripts |
| Matriz de desarrollo y apoyos adaptativos con un modelo real | ❌ nunca ejercitados de punta a punta |
| Gemini de punta a punta con clave real | ✅ (versión anterior) |
| Claude, OpenAI y Azure con clave real | ❌ nunca ejercitados |
