# Especificación de Requerimientos Técnico-Funcionales (ERTF) v3.1
## Gateway y Orquestador de IA para Moodle (`local_m1ai` / `local_chatbot`)

| Campo | Valor |
|---|---|
| Producto | Gateway de IA y Orquestador Multimodo — Módulo 1 (Asistente IA) |
| Tipo de documento | Especificación Técnica y Arquitectura de Integración |
| Versión | 3.1 — Consolidada: On-Premise, Soberanía de Datos y Cumplimiento Moodle |
| Estado | Implementado / Vigente |

---

## 1. Objetivo y Alcance

Construir y operar la capa de Gateway y Orquestador de IA para el Módulo 1. Actúa como mediador seguro entre la interfaz formativa de Moodle y los motores de inferencia (locales on-premise vía LiteLLM/vLLM o proveedores cloud).

### 1.1 Capacidades Centrales
1. **Orquestación en tres modos pedagógicos:** Tutor socrático (Modo A), Coach de desarrollo (Modo B) y Evaluador formativo (Modo C).
2. **Motor de Guardrails en dos capas:** Filtros heurisiticos de entrada (prevención de injection) y salida (prevención de filtraciones, alucinaciones y diagnósticos clínicos).
3. **Control de Cuotas y Rate Limiting:** Protección de infraestructura mediante Moodle Cache API (MUC) sin sobrecarga en la base de datos relacional.
4. **Telemetría y Auditoría Estructurada:** Registro persistente de tokens de entrada/salida, latencia en milisegundos, modelo, seudónimo y estado de ejecución (`ok`, `error`, `timeout`, `blocked`, `ratelimit`).
5. **Política de Retención y Purga:** Retención configurable del historial conversacional (12 meses por defecto) ejecutada por tarea programada (`\local_chatbot\task\purge_messages_task`), preservando intacta la auditoría analítica.

---

## 2. Arquitectura de Inferencia y Soberanía On-Premise

### 2.1 Presupuesto de Hardware para Modelo 70B en 2x NVIDIA A40
* **Capacidad instalada:** 2 GPUs NVIDIA A40 con 48 GB GDDR6 cada una = **96 GB VRAM total**.
* **Restricción física:** Un modelo de 70B parámetros en precisión completa (FP16) requiere ~140 GB solo para pesos en reposo (supera la memoria disponible).
* **Especificación obligatoria de cuantización:**
  * Se requiere inferencia distribuida con **vLLM (Tensor Parallelism = 2)** utilizando cuantización **AWQ (4-bit)** o **GPTQ (4-bit)**.
  * Consumo de pesos: ~38 a 42 GB VRAM.
  * Memoria remanente para KV Cache: ~54 GB VRAM, permitiendo concurrencia simultánea con un *context length* de hasta 8.192 tokens.
* **Capa de Abstracción:** Las solicitudes HTTP se dirigen al proxy **LiteLLM**, el cual balancea y aísla la carga de cómputo hacia el nodo de inferencia dedicado.

---

## 3. Pipeline de una Interacción

Toda consulta procesada por el Gateway sigue la siguiente secuencia garantizada:

```
[Usuario / Widget JS]
       │
       ▼
 1. Autenticación, Sesskey y Verificación de Consentimiento (consent_ai = 1)
 2. Rate Limiting local vía MUC (20 msgs/usuario/hora, 1.000 msgs/sitio/hora)
 3. Registro inicial del mensaje del usuario (local_chatbot_messages)
 4. Ensamblado de Contexto: Matriz de Acceso estricta según Modo (A, B o C)
 5. Pseudonimización PII: Reemplazo por FAC-hash y depuración de RUT, Email y Teléfono
 6. Guardrail de Entrada (GR-07): Aislamiento en bloque no ejecutable
       │
       ▼ [Llamada HTTP a LiteLLM / Inferencia Local]
       │
 7. Invocación con timeout (60s) y política de reintento (máx. 2 reintentos)
 8. Medición de latencia (latency_ms) y conteo/estimación de tokens
 9. Guardrails de Salida: Detección y bloqueo/anotación (GR-01 a GR-06, GR-10)
10. Persistencia de la respuesta tratada en el historial
11. Registro del Objeto Interacción en tabla de auditoría (local_chatbot_interaction)
12. Disparo de evento nativo de Moodle (\local_chatbot\event\ai_interaction_recorded)
13. Retorno de payload JSON al navegador con fuentes declaradas
```

---

## 4. Matriz de Guardrails (Seguridad y Ética Pedagógica)

| ID | Regla | Capa | Mecanismo de Control |
|---|---|---|---|
| **GR-01** | Revelación de respuestas de quiz pendiente | Salida | Heurística regex sobre alternativas; bloqueo y sustitución por mensaje socrático. |
| **GR-02** | Emisión de diagnósticos clínicos o psicológicos | Salida | Detección de términos de patología mental/laboral; bloqueo y sustitución. |
| **GR-03** | Recomendación de recursos inexistentes | Salida | Verificación contra base de conocimiento interna (`kb_documents`). |
| **GR-04** | Solicitud de datos sensibles innecesarios | Salida | Detección de patrones de petición de documentos o datos privados; bloqueo. |
| **GR-05** | Recomendación presentada como obligatoria/nota | Salida | Anotación de advertencia antepuesta (autonomía del participante). |
| **GR-06** | Afirmaciones normativas/sectoriales no verificadas | Salida | Anotación de advertencia antepuesta respecto a requerimientos legales. |
| **GR-07** | *Prompt Injection* desde la entrada del usuario | Entrada | Bloque delimitado `<<<CONTENIDO_DE_LA_PERSONA` con cláusula en prompt. |
| **GR-08** | Filtración de datos de otros participantes | Contexto | Estructural: consultas PHP acotadas estrictamente a `$USER->id`. |
| **GR-09** | Prohibición de escritura en libro de calificaciones | Backend | Estructural: el plugin no invoca APIs de gradebook de Moodle. |
| **GR-10** | Lenguaje estigmatizante o comparativo con pares | Salida | Detección de adjetivos denigrantes o comparaciones; anotación correctiva. |

---

## 5. Modelo de Datos (Cumplimiento Frankenstyle Moodle)

### 5.1 Tabla de Auditoría Estructurada: `local_chatbot_interaction`
Persiste los metadatos de telemetría y sobrevive a las purgas de retención.
* `id` (INT 10, PK)
* `userid` (INT 10, FK)
* `courseid` (INT 10)
* `mode` (CHAR 20): `tutor`, `coach`, `evaluador`
* `intent` (CHAR 100): Intención o fragmento inicial
* `resources` (TEXT): Fuentes de contexto declaradas
* `recommendation` (TEXT): Recomendación generada (si aplica)
* `useraccepted` (INT 2): `-1` (pendiente), `0` (descartada), `1` (aceptada), `2` (modificada)
* `guardrail` (CHAR 100): Identificadores activados (ej. `GR-01,GR-05`)
* `pseudoid` (CHAR 30): Seudónimo estable enviado al modelo (ej. `FAC-9f3a1c22`)
* `model` (CHAR 100): Nombre del modelo usado
* `input_tokens` (INT 10): Tokens de entrada
* `output_tokens` (INT 10): Tokens generados
* `latency_ms` (INT 10): Tiempo de respuesta en ms
* `status` (CHAR 20): `ok`, `error`, `timeout`, `blocked`, `ratelimit`
* `timecreated`, `timemodified` (INT 10)

### 5.2 Tabla de Historial Conversacional: `local_chatbot_messages`
Contenido textual para la experiencia del participante.
* Sujeto a purga automática a los 12 meses por la tarea programada `\local_chatbot\task\purge_messages_task`.

---

## 6. Contratos de Datos por Modo

### 6.1 Modo A — Tutor de Aprendizaje
* **Formato:** Texto Markdown pedagógico y socrático.
* **Comportamiento:** Acompaña lecturas y casos. Si hay cuestionarios pendientes, el contexto omite las preguntas y activa el guardrail GR-01.

### 6.2 Modo B — Coach de Desarrollo
* **Formato:** Respuestas orientativas con estructura de metas para el Plan Personal de Desarrollo (PDP):
  * `brecha`: Dimensión o indicador diagnosticado.
  * `meta`: Objetivo de mejora sugerido.
  * `accion`: Práctica concreta propuesta.
  * `evidencia`: Verificador de cumplimiento.

### 6.3 Modo C — Evaluador Formativo
* **Formato:** JSON estructurado contra rúbrica oficial:
```json
{
  "mode": "C",
  "criteria": [
    {
      "criterion_code": "C1",
      "level_code": "L3",
      "confidence": 0.88,
      "evidence_status": "complete",
      "observation": "El proyecto define claramente el público objetivo y la estrategia de facilitación.",
      "inference": "Demuestra comprensión sólida de las necesidades del grupo.",
      "recommendation": "Incorporar indicadores cuantitativos de impacto en la evaluación.",
      "evidence_ref": {"field": "destinatarios", "excerpt_start": 0, "excerpt_len": 120}
    }
  ],
  "requires_human_review": false,
  "human_review_reason": null
}
```
