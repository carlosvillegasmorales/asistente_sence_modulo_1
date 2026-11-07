# Guía: instalar el Módulo 3 (Asistente de Aseguramiento de la Calidad de los Aprendizajes)

El Módulo 3 viaja en el mismo plugin que los otros (`moodle/public/local/chatbot/`, desde `M1-v1.22.0`). Para dejarlo
funcionando en un Moodle que ya tiene el Módulo 2 hay **cuatro pasos**, que se corren como el usuario del servidor web,
dentro de la carpeta de Moodle. Lo que **no** viaja, a propósito: la clave del proveedor de IA, las claves de los servidores,
las personas y su avance.

| Pieza | Qué es | Dónde está |
|---|---|---|
| 1. El plugin | Páginas de la ficha y de las etapas, clases, idiomas, migración (3 tablas) | `moodle/public/local/chatbot/` |
| 2. La librería | Checklist, ficha, guía y ejemplo de llenado del cliente, más los tres documentos de referencia | `deploy/libreria_m3/` (no viaja con el plugin: se sube aparte) |
| 3. Los botones | Un botón al final de cada una de las cuatro hojas del curso | `cli/configurar_modulo3.php` |
| 4. El curso | El curso «Módulo 3» del cliente, con su contenido (lo carga él) | Ya existe en el servidor (curso 19 en el VPS propio) |

## Qué necesita el Módulo 3 para funcionar

- **El Módulo 2 instalado y con diseños terminados.** La persona elige, de sus diseños del Módulo 2, el que implementó: de ahí
  se recuperan el diseño, el plan formativo y el perfil ocupacional (no se vuelven a cargar). Un diseño se puede evaluar con sus
  **seis etapas aceptadas** y su **plan formativo adjunto**; si no, la página dice por qué no está disponible.
- **El curso con las cuatro hojas**, cuyas secciones se llaman «Bienvenida…», «Fase 1: …», «Fase 2: …» y «Fase 3: …». Los botones se
  ubican por esos nombres, no por id: el mismo guion sirve en cualquier servidor.
- **La clave del proveedor de IA** configurada en el plugin (la misma de los otros módulos).

## Pasos

1. **Instalar el plugin** (respaldo antes, y los verificadores **antes** de migrar; ver «Patrón de despliegue» en `CLAUDE.md`):
   ```bash
   php admin/cli/purge_caches.php --lang          # solo idiomas: así las cadenas nuevas no salen como [[clave]]
   for f in local/chatbot/cli/verificar_*.php; do php $f; done
   php admin/cli/upgrade.php --non-interactive    # crea local_chatbot_impl, local_chatbot_impl_val y local_chatbot_calidad
   php admin/cli/purge_caches.php
   ```
2. **Cargar la librería** en el ámbito `m3` (la carpeta se sube aparte; es idempotente y tiene `--simular`):
   ```bash
   php local/chatbot/cli/cargar_libreria.php --dir=/ruta/a/libreria_m3 --ambito=m3 --simular
   php local/chatbot/cli/cargar_libreria.php --dir=/ruta/a/libreria_m3 --ambito=m3
   ```
   Se carga por esta vía y no por el panel porque la extracción con IA de las fuentes largas puede fallar por `RECITATION`. Cada
   documento va enrutado por tarea (`m3f1`, `m3e1` a `m3e7`): `verificar_libreria_m3.php` comprueba que ninguna tarea pase el
   presupuesto de 220 mil caracteres. La consolidación (etapa 8) no usa librería.
3. **Poner los botones en el curso** (simulación primero):
   ```bash
   php local/chatbot/cli/configurar_modulo3.php --curso=ID --simular
   php local/chatbot/cli/configurar_modulo3.php --curso=ID
   ```
   Agrega, al final de cada hoja, una etiqueta con el botón y el texto que pidió el cliente:

   | Hoja | Botón | Lleva a |
   |---|---|---|
   | Bienvenida e Instrucciones Generales | Ir a la Fase 1: Registro y Validación | la pestaña «Fase 1» del curso |
   | Fase 1: Registro y Validación | Completar y validar la ficha de implementación | `ficha_implementacion.php` |
   | Fase 2: Evaluación de la Implementación | Acceder al asistente IA | `calidad.php` (etapas 1 a 3) |
   | Fase 3: Ajuste de la Experiencia de Aprendizaje | Ir al ajuste de la experiencia de aprendizaje - Fase 3 | `calidad.php?etapa=4` |

   Los textos de las hojas son del cliente y el guion no los toca. Si una hoja ya trae un botón con **ese mismo texto** (con
   «#» provisional), se enlaza ese en vez de agregar otro. Es idempotente: una segunda pasada no cambia nada, y si cambia la URL
   del sitio actualiza la etiqueta propia (se reconoce por su comentario `<!-- BOTON M3 ... -->`). **Correrlo de nuevo después de
   restaurar el curso.**
4. **Probar con una cuenta de estudiante** (el administrador ignora restricciones y ve todo abierto): ver la lista de abajo.

## Qué hace cada fase

- **Fase 1, ficha** (`ficha_implementacion.php`). Elegir el diseño; completar las 6 secciones (tabla 4.1 con una fila por criterio,
  suma por nivel y logro %; 4.2 opcional); «Guardar y validar». El control de entrada del Prompt Maestro pide lo que falte. La
  validación aplica el checklist de 34 ítems (8 CRÍTICO) y entrega: resultados por sección, decisión con la regla aplicada, tiempo
  restante (total − utilizado), debilidades, sugerencias y registro de observaciones no resueltas. **La condición la fija el
  código**, y los ítems aritméticos (presentes, tiempos contra el diseño, fila por criterio, suma por nivel, logro %) también:
  aparecen marcados «calculado» y prevalecen sobre lo que diga el modelo. Con Aprobado o Aprobado con observaciones se abre la Fase 2.
- **Fase 2, etapas 1 a 3** (`calidad.php`): análisis de la implementación, brechas y pendientes, y decisión del tipo de ajuste. La
  página muestra los **datos que calcula la plataforma** (logro por criterio, promedio, cobertura evaluativa, criticidad, personas a
  nivelar y a profundizar, tiempo restante y la decisión que indican los datos medibles) y se los entrega al modelo sin que los
  recalcule. El ajuste que decide el asistente **no puede ser menos exigente** que ese mínimo; la persona puede agravarlo con su
  juicio pero no bajarlo. Sin tipo de ajuste aceptado no se abre la Fase 3.
- **Fase 3, etapas 4 a 8**: acciones remediales o plan de rediseño (según el ajuste decidido; la suma de la tabla «Distribución del
  tiempo restante» se revisa contra el tiempo restante), mejora del diseño y buenas prácticas, experiencia de continuidad (dos
  trayectorias, de nivelación y de profundización), síntesis y consolidación (la Etapa 0 más las etapas 1 a 7, en Word).
- Cada etapa: el asistente propone, la persona edita, pide ajustes y acepta; no se avanza sin aceptar la anterior; la revisión
  automática avisa lo que falta; cada etapa se descarga en Word.
- La persona puede tener **varias evaluaciones** (una por experiencia implementada), igual que en el Módulo 2.

## Interruptores (Administración → Plugins → Plugins locales → Asistente IA)

`fichaimpl`, `calidadfase2`, `libreriam3`, `bienvenidam3`, `consolidacionm3` y `avisosimpl`, todos encendidos por defecto. Apagar uno
es la forma de retroceder una mejora sin desplegar (bajar la versión del código no se puede con la base ya migrada).

## Lista de prueba con una cuenta de estudiante

1. En el Módulo 2, tener un diseño con las seis etapas aceptadas y su plan formativo adjunto.
2. Matricular a la persona en el curso del Módulo 3. Abrir la hoja «Fase 1» y pulsar el botón «Completar y validar la ficha de
   implementación»: debe cargar la ficha con el diseño ofrecido en la lista.
3. Guardar con un campo obligatorio vacío y validar: el control de entrada debe pedir lo que falta, sin gastar un intento de IA.
4. Completar la ficha con el ejemplo del cliente (`deploy/libreria_m3/04_ejemplo_llenado.md`) y validar: debe quedar «Aprobado con
   observaciones» o «Aprobado», con tiempo restante de 180 minutos.
5. Cambiar una suma por nivel para que no coincida con los presentes y validar: debe quedar «No aprobado» por el ítem 4.3, aunque el
   modelo no lo haya visto.
6. Recorrer las etapas 1 a 8 (con la IA real, cada una tarda unos minutos), aceptando cada una; descargar el Word de una etapa y el
   informe final.
7. Con la ficha ya validada, cambiar cualquier campo: la validación debe quedar «desactualizada» y la Fase 2 cerrada hasta validar de
   nuevo.

## Limitaciones conocidas

- **No hay tablero del Módulo 3** (el del Módulo 2, «Panorama integral», no lo incluye): el cliente no lo pidió.
- **No hay checklist de autorrevisión en pantalla** (el del Módulo 2, `checklist_exante.php`, deja marcar el checklist antes de validar).
- El contenido de las hojas del curso es del cliente. Sus enlaces «Abrir guía» y «Abrir ejemplo» (`URL_GUIA` y `URL_EJEMPLO`) quedan
  para cuando suba esos recursos; este guion solo pone los cuatro botones.
- Con el formato de curso por pestañas (`multitopic`) el botón queda al final de la sección de cada pestaña. Probado en pruebas locales
  sobre una copia del curso con el formato `topics` (el plugin `multitopic` no está en el repositorio).
