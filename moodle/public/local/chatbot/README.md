# Asistente IA — Módulo 1 (`local_chatbot`)

Plugin de Moodle que implementa la capa de asistente IA y los instrumentos del **Módulo 1** de la suite: *puerta de entrada formativa y adaptativa de las personas facilitadoras*, según el documento de arquitectura de Interfases Consultores (Producto 4).

El Módulo 1 se monta como un **curso Moodle**. Este plugin no es el curso: es lo que el curso no puede hacer solo — el asistente con sus tres modos, el diagnóstico de brechas, el Plan Personal de Desarrollo y la evaluación del proyecto integrador contra rúbrica.

## El nombre y las versiones

**Asistente IA** es el nombre del producto: descriptivo y sin metáfora, dice lo que la persona tiene delante.

Que el nombre no anuncie nada no cambia la restricción de fondo: la ERTF le impone al asistente en GR-05 que ninguna recomendación se presente como obligatoria ni como calificación, y el flujo del Coach obliga a que cada meta se pueda aceptar, ajustar, postergar o descartar. Eso vive en el código y se verifica en `cli/verificar_guardrails.php`, no en el nombre.

El componente interno sigue llamándose `local_chatbot`. Es deuda técnica conocida y declarada: renombrarlo alcanza a 18 tablas, 4 capacidades y los espacios de nombres, y se decidió no pagar ese costo ahora. Ver [docs/DECISIONES.md](docs/DECISIONES.md), DEC-03.

### Cómo leer el número de versión

```
M1-v1.0.0
│  │ │ │
│  │ │ └── parche:  corrección, sin cambio de alcance
│  │ └──── menor:   se agrega un requisito de la ERTF al módulo
│  └────── mayor:   cambio incompatible dentro del módulo
└───────── módulo:  qué alcance funcional trae esta instalación
```

Los tres módulos de la suite viven en el mismo componente (DEC-01), así que el número de módulo es lo que distingue una instalación de otra. Quien da soporte lo lee directo en *Administración del sitio → Plugins*: si el reporte es sobre el Módulo 2 y la instalación dice `M1-`, el problema no es un error, es una entrega que todavía no llega.

La numeración parte en `1.0.0` con la primera entrega del Módulo 1. Las versiones `v1.x` y `v2.x` que aparecen en [CHANGES.md](CHANGES.md) son anteriores a la ERTF: exploración para dimensionar el alcance, no entregas.

## Qué hace este plugin y qué hace Moodle

| Pieza del Módulo 1 | Quién la resuelve |
|---|---|
| Las cuatro unidades (lecturas, casos, ejemplos) | Moodle: Libro / Página |
| Cuestionarios formativos | Moodle: Quiz + banco de preguntas etiquetado |
| Restricciones de progresión y finalización de actividad | Moodle: restricciones de acceso |
| Bienvenida, perfilamiento y consentimiento | **Plugin** (`index.php`) |
| Diagnóstico auto aplicado y matriz de brechas | **Plugin** (`diagnostico.php`) |
| Plan Personal de Desarrollo con trazabilidad | **Plugin** (`pdp.php`) |
| Proyecto integrador y rúbrica visible | **Plugin** (`proyecto.php`) |
| Seguimiento y cierre | **Plugin** (`cierre.php`) |
| Asistente con tres modos funcionales | **Plugin** (widget flotante) |
| Indicadores agregados del módulo | **Plugin** (`indicadores.php`) |

## Documentación técnica

- **[docs/DECISIONES.md](docs/DECISIONES.md)** — decisiones de arquitectura y desvíos declarados respecto de la ERTF, con el mapa de trazabilidad componente ERTF → dónde vive en este plugin.
- **[docs/CUMPLIMIENTO.md](docs/CUMPLIMIENTO.md)** — cruce sección por sección entre el documento de arquitectura y lo implementado, con lo que falta y por qué.
- **[docs/TRAZABILIDAD_RU.md](docs/TRAZABILIDAD_RU.md)** — los 68 requisitos de la matriz `RU/RS/EARS` v4 cruzados uno por uno contra el código, con la capa en que se cumple cada restricción: contexto, prompt o guardrail.
- **[docs/ARQUITECTURA.md](docs/ARQUITECTURA.md)** — recorrido de un mensaje, mapa de clases, modelo de datos, eventos, puntos de extensión y controles de seguridad.

## Verificación

Cinco scripts comprueban las reglas que no pueden depender de que el modelo se porte bien. Ninguno requiere base de datos ni bootstrap de Moodle, y salen con código 1 ante cualquier desvío, de modo que se pueden encadenar a CI:

```bash
php cli/verificar_matriz_acceso.php   # qué objeto de datos ve cada modo y la fase de diagnóstico
php cli/verificar_guardrails.php      # activación de cada regla de filtro, y sus falsos positivos
php cli/verificar_calculo.php         # fórmula del puntaje compuesto, niveles de Doc 3, completitud y priorización
php cli/verificar_coach.php           # matriz de desarrollo del Coach y reglas impuestas al modelo
php cli/verificar_apoyos.php          # motor de apoyos adaptativos: estados, transiciones y revisión
```

Los casos de guardrails incluyen respuestas legítimas a propósito: un filtro que bloquea todo también incumple, solo que en silencio.

## Requisitos

- Moodle 4.3 o superior.
- PHP con las extensiones `curl` y `fileinfo`.
- Certificado CA válido para cURL/OpenSSL (`curl.cainfo` / `openssl.cafile` en `php.ini`). En Windows no siempre se detecta solo: si las llamadas a la IA fallan con error de conexión, es la causa más probable.
- Una API Key del proveedor de IA que vayas a usar.

## El asistente: un solo bot, tres modos

El documento es explícito: *"un solo asistente con tres modos funcionales explícitos, en lugar de tres bots desconectados"*. El widget tiene un selector de modo y cada modo abre su propio hilo de conversación.

| Modo | Cuándo | Qué hace |
|---|---|---|
| **Tutor de aprendizaje** | Durante las cuatro unidades | Explica, pregunta socráticamente, remite a los recursos internos. **No revela la alternativa correcta de un cuestionario que la persona aún no ha respondido** — el plugin consulta los intentos de quiz del curso para saberlo. |
| **Coach de desarrollo** | Después del diagnóstico | Interpreta la matriz de brechas y propone metas de PDP. Ninguna meta entra al plan sin que la persona la acepte. |
| **Evaluador formativo** | En el proyecto integrador | Aplica la rúbrica criterio por criterio y responde en el formato estándar: *fortaleza observada · aspecto a mejorar · por qué importa · acción sugerida · vínculo con el PDP*. Declara cuándo hace falta revisión humana. |

Bajo cada respuesta el widget muestra **qué información usó** el asistente (perfil, recursos internos, matriz de brechas, PDP, rúbrica, avance en cuestionarios), porque la gobernanza del módulo lo exige.

## Reglas de gobernanza implementadas

Estas no son buenas intenciones documentadas: están en el código.

- **Pseudonimización.** Al proveedor de IA nunca se le envía el nombre real, el correo ni el nombre del curso. La persona viaja como `FAC-xxxxxxxx`, derivado por hash del identificador del sitio. El texto se limpia además de correos y RUT antes de salir (`classes/pseudonymizer.php`).
- **Consentimiento previo.** Sin aceptación informada el asistente no opera: el widget enlaza a la página de bienvenida en vez de abrir un chat.
- **Toda recomendación es revisable.** Las metas propuestas por la IA nacen en estado `propuesto`; la persona acepta, edita o descarta, y esa decisión se registra.
- **Trazabilidad del PDP.** Cada cambio deja fila en el historial: qué cambió, por qué y con qué evidencia. El historial no se sobrescribe ni se borra.
- **Prompts versionados.** Editar el prompt de un modo crea una versión nueva; cada interacción registra con qué versión se generó, para poder rastrear de dónde salió una recomendación.
- **Sin clasificaciones estigmatizantes.** El diagnóstico usa los cuatro niveles de orientación de Doc 3 —*Prioridad alta*, *En desarrollo*, *Funcional*, *Consolidado*— presentados como una lectura de ese momento, nunca como etiquetas sobre la persona.
- **Indicadores anonimizados.** La página de indicadores entrega conteos, distribuciones y promedios. No expone a ninguna persona individual.
- **Recursos internos versionados.** Volver a subir un documento con el mismo nombre no lo pisa: crea la versión siguiente y retira la anterior sin borrarla, para poder explicar después con qué versión se generó una respuesta. Lo mismo aplica a la rúbrica.
- **Ruta de derivación a soporte humano.** Configurable en Site administration e inyectada en los tres prompts, para que el asistente derive en vez de solo decir que no puede ayudar.
- **Eventos en el log de Moodle.** Diagnóstico completado, decisión sobre una meta del PDP, interacción con el asistente y proyecto evaluado. Al log va el hecho y sus metadatos, nunca el contenido de la conversación.

## Instalación

1. **Extensiones locales → Instalar módulos**, sube el `.zip` (o copia la carpeta a `local/chatbot`).
2. Confirma *"Actualizar base de datos Moodle ahora"*. Se crean las 18 tablas y se carga el instrumento base: 5 dimensiones con 20 ítems declarativos, 5 situacionales y sus criterios formativos, 6 criterios de rúbrica, y la versión 1 de los prompts de los tres modos y de la fase de diagnóstico.

## Configuración

**Administración del sitio → Extensiones locales**:

- **Asistente IA** — proveedor de IA (Gemini, Claude, OpenAI o Azure AI Foundry) y su API Key. El campo *Modelo* es opcional: vacío usa el nivel económico de cada proveedor.
- **Instrumento del Módulo 1** — prompts versionados por modo, criterios de la rúbrica, dimensiones e ítems del diagnóstico.
- **Recursos internos del módulo** — los PDFs que el asistente debe citar antes de recurrir a conocimiento general.
- **Indicadores del Módulo 1** — el tablero agregado.

## Permisos

| Capability | Habilita | Por defecto |
|---|---|---|
| `local/chatbot:use` | Usar el asistente y trabajar el propio recorrido | Todos los usuarios autenticados |
| `local/chatbot:managekb` | Cargar y retirar recursos internos | Managers |
| `local/chatbot:manageinstrument` | Configurar diagnóstico, rúbrica y prompts | Managers |
| `local/chatbot:viewanalytics` | Ver los indicadores agregados | Managers |

## Costo y caché de prompts

Cada mensaje reenvía el prompt del modo más los recursos internos. Para no pagar ese bloque completo en cada turno:

- **Gemini y OpenAI** cachean el prefijo repetido automáticamente, sin configuración.
- **Claude** lo hace explícito vía `cache_control`, ya implementado en `classes/ai/anthropic_provider.php`.
- **Azure AI Foundry** generalmente hereda el comportamiento de OpenAI según el despliegue; no verificado de forma independiente.

## Datos que produce el módulo

Los objetos de datos del documento de arquitectura, con su tabla:

| Objeto | Tabla |
|---|---|
| Perfil de facilitador/a | `local_chatbot_profile` |
| Resultado diagnóstico | `local_chatbot_diag_response` (+ `_dimension`, `_diag_item`) |
| PDP | `local_chatbot_pdp`, `_pdp_goal`, `_pdp_history` |
| Proyecto integrador | `local_chatbot_project`, `_project_fb`, `_criterion` |
| Cierre | `local_chatbot_closure` |
| Interacción IA | `local_chatbot_interaction` |
| Conversación | `local_chatbot_messages` |
| Recursos internos | `local_chatbot_kb_documents` |
| Prompts versionados | `local_chatbot_prompt` |

Los contratos de entrega hacia el resto de la suite (capa usuaria, Módulo 2, Módulo 3, capa analítica y gestión institucional) están definidos en `classes/suite_export.php`.

## Estado y límites conocidos

- Solo **Gemini** está probado de punta a punta con una clave real. Claude, OpenAI y Azure están implementados y pasan verificación de sintaxis, pero no se han ejercitado contra sus APIs.
- **La instalación no está probada contra una base de datos real.** El `install.xml` valida contra el esquema oficial de Moodle, pero falta correr `admin/cli/upgrade.php`.
- **No hay rate limiting** en el endpoint del chat. Antes de operar con volumen real conviene resolverlo.
- **El banco de preguntas no está mapeado a las dimensiones del diagnóstico.** Sin ese cruce no se pueden calcular dos indicadores del documento: nivel de logro por dimensión y error recurrente por unidad.
- **El Coach no recomienda actividades concretas de Moodle**, porque no tiene visibilidad del catálogo del curso; propone acciones metodológicas.
- **La integración con la suite tiene los contratos, no el transporte.** No hay web services ni tarea programada que empuje los datos a los otros módulos.
- Ver [docs/CUMPLIMIENTO.md](docs/CUMPLIMIENTO.md) para el detalle completo de qué queda fuera y por qué.
- La habilitación del proyecto integrador se condiciona a tener un PDP con metas vigentes. El requisito de haber completado las cuatro unidades se configura con **restricciones de acceso de Moodle** sobre la sección que enlaza al proyecto.
- La rúbrica vive en el plugin (versionada) y no se sincroniza con la rúbrica nativa de Moodle. Es una decisión deliberada para poder versionarla como exige la gobernanza; si el curso usa además la rúbrica nativa para calificar, hay que mantenerlas alineadas a mano.
- El cumplimiento de la **Ley 19.628** para el envío de datos a un proveedor de IA externo es una definición institucional pendiente, más allá de la pseudonimización ya implementada.

## Licencia

GPLv3 — ver `LICENSE`.
