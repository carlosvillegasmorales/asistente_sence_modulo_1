# Decisiones de arquitectura y desvíos declarados respecto de la ERTF

La ERTF v2.0 establece que *"cualquier modificación de un supuesto SUP-nn o una decisión RD-nn requiere revisión del alcance y re-estimación"*. Este documento registra los desvíos adoptados, para que quien audite encuentre una decisión declarada y no una ausencia inexplicada.

---

## DEC-01 — Se implementa como un solo plugin, no como suite de once

**Fecha:** 2026-09-09
**Decide:** contraparte del proyecto
**Desvía de:** RD-01, RD-02 y el inventario §3.2 (P1 a P11)

### Qué dice la ERTF

RD-01 especifica una suite de plugins cooperantes con `local_m1core` como núcleo y plugins de actividad (`mod_*`) sobre él. RD-02 prohíbe que un plugin escriba en tablas de otro.

### Qué se adopta

Todo el Módulo 1 vive en un único plugin local: `local_chatbot`. El principio de fondo de RD-02 —una sola fuente de verdad, sin lógica de negocio duplicada— **sí se respeta**: las páginas no contienen reglas de puntuación ni de adaptación, toda la lógica vive en `classes/` y las páginas solo presentan.

### Por qué

Para el piloto, la separación en once componentes agrega costo de despliegue y de mantención sin cambiar lo que la persona facilitadora experimenta. La funcionalidad ya está construida y operativa en un solo plugin; reescribirla como suite implicaría renombrar el componente, migrar todas las tablas, capabilities y cadenas de idioma —lo que la propia ERTF advierte en RD-03 que no es reversible sin migración.

### Qué se pierde (consecuencias reales, no teóricas)

Dos componentes de la ERTF **no pueden entregarse como están especificados**, por restricción de la arquitectura de Moodle y no por preferencia:

| Componente ERTF | Por qué no se puede | Qué se hace en cambio |
|---|---|---|
| **P9 `availability_m1gap`** — restricción de acceso según brecha diagnóstica | Moodle descubre las condiciones de disponibilidad únicamente desde `availability/condition/`. Un plugin `local` no puede registrar una condición nueva | La progresión se configura con las restricciones nativas de Moodle (finalización de actividad, calificación). La restricción automática *por brecha* no está disponible: hay que definirla a mano por cohorte |
| **P7 `block_m1panel`** — tablero del participante como bloque | Los bloques se descubren desde `blocks/`. Un plugin `local` no puede registrar un bloque | El tablero vive como página (`index.php`), no como bloque arrastrable a la portada del curso. La información es la misma; la ubicación no |

Además, se pierde la capacidad de **instalar el módulo por partes**: no se puede desplegar solo el diagnóstico sin el resto.

### Condición de reversión

Si en una fase posterior se requiere la suite completa, la migración implica: renombrar el componente, migrar 18 tablas con sus datos, redefinir capabilities y reescribir las referencias de cadenas de idioma. Debe planificarse como proyecto propio, no como refactor incremental.

---

## Mapa de trazabilidad: componente ERTF → dónde vive

Para que los identificadores `RF-*` de la ERTF sigan siendo utilizables en backlog, commits y pruebas.

| ERTF | Responsabilidad | Dónde vive en `local_chatbot` | Estado |
|---|---|---|---|
| **P1** `local_m1core` | Modelo de datos, servicios, estados | `classes/` completo | Implementado |
| **P2** `mod_m1onboard` | Consentimiento y perfilamiento | `index.php` + `classes/profile.php` | Implementado (ver DEC-02) |
| **P3** `mod_m1diag` | Diagnóstico y matriz de brechas | `diagnostico.php` + `classes/diagnostic.php` | Implementado |
| **P4** `mod_m1pdp` | Plan Personal de Desarrollo | `pdp.php` + `classes/pdp.php` | Implementado |
| **P5** `mod_m1proj` | Proyecto integrador y rúbrica | `proyecto.php` + `classes/project.php` | Implementado |
| **P6** `local_m1ai` | Gateway de IA, tres modos, guardrails | `classes/assistant.php` + `classes/ai/` + `ajax.php` | Parcial: falta §8.4 |
| **P7** `block_m1panel` | Tablero del participante | `index.php` | Como página, no como bloque |
| **P8** `report_m1analytics` | Indicadores del módulo | `indicadores.php` + `classes/analytics.php` | Parcial |
| **P9** `availability_m1gap` | Restricción por brecha | — | **No entregable** con un solo plugin |
| **P10** `tool_m1setup` | Aprovisionamiento del curso | — | No implementado |
| **P11** `local_m1ws` | Servicios web | `classes/suite_export.php` | Contratos sí, transporte no |

---

## DEC-02 — Consentimiento y perfilamiento conviven en una pantalla

**Desvía de:** CNF-01 y RF-CNS-01

### Qué dice la ERTF

CNF-01 resuelve un conflicto entre los dos documentos fuente a favor del Componente 0: consentimiento y perfilamiento deben ser **dos actividades distintas, con dos tablas distintas**, y el consentimiento debe **preceder** al perfilamiento. La razón declarada es sustantiva: *"una casilla de aceptación embebida en la ficha de perfilamiento mezcla la manifestación de voluntad con respuestas de contexto y debilita la evidencia de consentimiento"*.

### Qué existe hoy

`index.php` presenta ambos en la misma pantalla y `local_chatbot_profile` guarda ambos en la misma tabla, con `consentaccepted` y `consenttime` como columnas del perfil.

### Estado

**Desvío no resuelto.** Es el más significativo del conjunto, porque la ERTF lo declara requisito de licitud, no de diseño: *"ningún dato identificable asociado a la persona puede almacenarse antes de la aceptación del consentimiento"*.

Corregirlo requiere: tabla `policy` con versión y hash del texto, tabla `consent` append-only con encadenamiento de revocaciones, y separar la pantalla en dos pasos. No depende de la decisión DEC-01: se puede hacer dentro del plugin único.

**Pendiente de decidir con la contraparte** si entra en el alcance del piloto.

---

## DEC-03 — El producto se llama Asistente IA; el componente sigue siendo `local_chatbot`

**Desvía de:** nada de la ERTF. Es una decisión de identidad y de operación de soporte.

### El nombre

El plugin nació como un chatbot general y conservó ese nombre después de reorientarse al Módulo 1. El producto que se entrega ya no es un chatbot: es un asistente con tres modos, un diagnóstico de brechas, un plan de desarrollo y una evaluación contra rúbrica.

**Nombre vigente: `Asistente IA`** (2026-09-10, por decisión de la contraparte). Es descriptivo y sin metáfora: dice lo que la persona tiene delante.

**Lo que reemplaza, y por qué se deja constancia.** Entre M1-v1.0.0 y M1-v1.1.0 el producto se llamó **Brújula Formativa**. La elección respondía a la restricción que la ERTF §8.4 le impone al asistente en GR-05 —ninguna recomendación puede presentarse como obligatoria ni como calificación— y el flujo del Coach ya obliga a que cada meta se pueda aceptar, ajustar o descartar: una brújula orienta y quien camina decide.

Queda escrito porque el argumento sigue siendo válido aunque el nombre no se haya adoptado: **lo que garantiza esa conducta es GR-05 y el flujo de decisión del PDP, no el nombre.** Un nombre neutro no debilita la garantía; simplemente deja de anunciarla. Si en el futuro alguien busca por qué el producto no se llama de un modo que comunique "orienta, no manda", la respuesta es que se prefirió claridad sobre metáfora, y que la restricción vive en el código y se verifica en `cli/verificar_guardrails.php`.

### Por qué el componente no se renombra

Renombrar `local_chatbot` alcanzaba a 794 ocurrencias en 55 archivos cuando se midió en M1-v1.0.0 —hoy es más— e incluye las 18 tablas, las 4 capacidades, los espacios de nombres PSR-4 y las URL públicas del plugin.

Hoy ese cambio es barato: nada está en producción, así que no habría datos que migrar. Aun así se decidió no hacerlo, para no introducir riesgo de regresión en la misma ventana en que se prepara la primera entrega.

**La consecuencia hay que decirla completa:** después de la primera instalación en la contraparte, el mismo cambio deja de ser un renombrado y pasa a ser una migración de datos, con script de `upgrade.php`, ventana de mantenimiento y riesgo real sobre las tablas del piloto. Si el nombre interno va a cambiar alguna vez, el momento es antes de esa instalación.

Mientras tanto, la separación se sostiene sola: todo lo que se ve pasa por `get_string('pluginname')` o `get_string('botname')`, así que el nombre visible es dos cadenas de idioma y no está esparcido por el código.

El cambio de *Brújula Formativa* a *Asistente IA* lo confirmó en la práctica: fueron esas dos cadenas por idioma, más las menciones en documentación. Ninguna línea de lógica, ninguna tabla, ninguna URL. Esa es exactamente la propiedad que esta decisión buscaba conservar, y la razón por la que renombrar el producto es barato y renombrar el componente no lo es.

### El esquema de versiones

El release lleva el módulo adelante: `M1-v1.0.0`.

DEC-01 puso los tres módulos de la suite en un solo componente. Eso deja al número de versión como el único lugar donde se distingue una instalación de otra, y el semver por sí solo no alcanza: `v2.4.0` no dice si esa instalación tiene el Módulo 2 o solo el 1. Con el prefijo, quien da soporte lo lee en *Administración del sitio → Plugins* sin abrir el repositorio.

La numeración parte en `1.0.0` con la primera entrega. Las versiones `v1.x` y `v2.x` del changelog son anteriores a la ERTF —exploración para dimensionar el alcance— y entregar una `v2.4` como primera versión invita a la pregunta de qué pasó con la v1. `$plugin->version`, en cambio, sigue subiendo de forma monótona: es lo único que Moodle compara y no puede retroceder.
