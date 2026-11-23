<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Modulo 2, Fase 2: diseno de la experiencia de aprendizaje en seis etapas (Prompt Maestro, etapas 1 a 6).
 *
 * Pedido de Interfases del 21-10-2026: cada etapa es una pagina del asistente donde la IA propone el producto
 * de la etapa a partir de la ficha validada en la Fase 1 y de las etapas ya aceptadas; la persona lo edita, pide
 * ajustes, lo acepta y lo descarga en Word. No se avanza a la etapa siguiente sin aceptar la anterior («no
 * avanzar automaticamente entre etapas»), ni se entra sin la ficha validada («este asistente no trabaja sin ficha»).
 *
 * El texto de cada etapa es el del Prompt Maestro del cliente. Lo que no lleva prefijo de BD es funcion pura y lo
 * ejercita cli/verificar_diseno.php.
 */
class diseno {

    /** @var string Version del prompt de diseno: se guarda con cada producto (RU-PRIV-006). */
    const PROMPT_VERSION = 'M2-F2-etapas-v15';

    /** @var int Desde esta version del prompt cada etapa cierra con vacios, verificacion y referencias (revision del 02-11). */
    const PROMPT_CIERRE = 11;

    /** @var string Secciones con que cierra toda etapa, como en el ejemplo del cliente («Diseño Final Validado»). */
    const SECCION_VACIOS = 'Registro de vacíos de información y propuestas de solución';
    const SECCION_VERIFICACION = 'Verificación de calidad de la etapa';
    const SECCION_REFERENCIAS = 'Referencias consultadas';

    /** @var string Regla de los prompts: formulas en texto, no en LaTeX (Moodle no lo dibuja; pedido del cliente del 02-11). */
    const REGLA_FORMULAS = '- Fórmulas: escríbelas en texto corriente, con los signos × ÷ = y %, por ejemplo «Porcentaje final de '
        . 'logro = (% Instrumento 1 × 0,20) + (% Instrumento 3 × 0,60) + (% Instrumento 5 × 0,20)». Nunca uses LaTeX ni '
        . 'signos $ o $$, \\text, \\times o \\frac: la plataforma no los dibuja.'
        . "\n" . '- Instrumentos y formularios (listas de cotejo, escalas, pautas, hojas de registro, encabezados con nombre, '
        . 'RUT y fecha): escríbelos con tablas Markdown (una fila por ítem y una columna por opción, con ☐ en cada casilla) '
        . 'y texto normal. Nunca en bloques de código, con líneas de puntos (.....), separadores (-----, =====) ni '
        . 'columnas alineadas con espacios: en la página y en el Word se desarman. No uses emojis ni íconos (la plataforma no '
        . 'los guarda y aparecen como «?»): para marcar, usa ☐.';

    /** @var string Seccion del producto con el registro de ajustes entre etapas (control de cambios del 10-11-2026). */
    const SECCION_AJUSTES = 'Registro de ajustes';

    /**
     * @var string[] Bloque 1 del documento «Ajuste Prompt Maestro M2 - Control de Cambios entre Etapas» (Interfases,
     * 10-11-2026), textual, para las etapas 1 a 6. Va a continuacion de las reglas de gobernanza.
     */
    const BLOQUE_CONTROL_CAMBIOS = [
        'CONTROL DE CAMBIOS ENTRE ETAPAS:',
        'Las etapas del diseño son interdependientes: un ajuste en una etapa puede afectar a las anteriores y a las '
            . 'siguientes. Debes aplicar este control cada vez que la persona facilitadora solicite un ajuste en la etapa '
            . 'en curso o en una etapa ya aprobada, o entregue contenido modificado por ella fuera del asistente.',
        'Elementos de dependencia que debes revisar en cada ajuste:',
        '- Número, nombre y orden de las fases, actividades, estaciones o sesiones.',
        '- Organización de las personas participantes (subgrupos, duplas, turnos y rotaciones).',
        '- Distribución de los tiempos y su suma respecto del tiempo asignado a la experiencia.',
        '- Criterios de evaluación abordados en cada fase, actividad o estación.',
        '- Evidencias, instrumentos y momentos de evaluación asociados a cada criterio.',
        '- Recursos, materiales y condiciones de implementación que dependen de los elementos anteriores.',
        'Procedimiento obligatorio:',
        '1. Identifica qué elementos de dependencia cambia el ajuste solicitado.',
        '2. Identifica en qué etapas ya aprobadas, anteriores o siguientes, aparecen esos elementos.',
        '3. Informa a la persona facilitadora, antes de aplicar el ajuste, qué etapas se verán afectadas y qué cambiará '
            . 'en cada una.',
        '4. Aplica el ajuste en la etapa solicitada y actualiza todas las etapas afectadas, entregando la versión '
            . 'corregida de cada una.',
        '5. Registra el ajuste en el registro de ajustes: etapa de origen, cambio realizado, etapas actualizadas y fecha '
            . 'o turno de la conversación.',
        '6. No avances a la etapa siguiente mientras alguna etapa afectada no esté actualizada y aprobada por la persona '
            . 'facilitadora.',
        'Si la persona facilitadora decide no actualizar una etapa afectada, regístralo en el registro de ajustes como '
            . 'inconsistencia aceptada, explica su efecto en la implementación y mantenla visible hasta la Etapa 8.',
        'En la verificación de calidad de cada etapa, no declares que un ajuste fue «integrado» o «incorporado '
            . 'plenamente» si no verificaste que los elementos de dependencia coinciden con las etapas ya aprobadas.',
    ];

    /**
     * @var string Como se cumple el bloque 1 en la plataforma: cada respuesta trae solo el producto de la etapa en curso,
     * y cada etapa se ajusta y se aprueba en su propia hoja. El registro de ajustes vive en una seccion del producto.
     */
    const CONTROL_CAMBIOS_PLATAFORMA = 'CÓMO SE APLICA EN ESTA PLATAFORMA: en esta respuesta entregas solo el producto de '
        . 'esta etapa; cada etapa se ajusta y se aprueba en su propia hoja de trabajo. Los pasos 3 a 5 se cumplen así: si '
        . 'el ajuste (o el contenido que modificó la persona) afecta otras etapas ya aprobadas, el producto incluye, antes '
        . 'de «### Registro de vacíos de información y propuestas de solución», la sección «### Registro de ajustes» con una '
        . 'tabla de columnas «Etapa de origen», «Cambio realizado», «Etapas afectadas», «Corrección que corresponde», '
        . '«Estado» y «Fecha». En «Corrección que corresponde» escribe, para cada etapa afectada, qué cambiar y cómo, para '
        . 'que la persona lo aplique al ajustar esa etapa; en «Estado» escribe «Pendiente de actualizar», «Actualizada» '
        . '(cuando el producto de esa etapa ya lo refleja) o «Inconsistencia aceptada» (si la persona decidió no '
        . 'actualizarla, con su efecto en la implementación). Si el producto actual ya trae un registro de ajustes, '
        . 'conserva sus filas y actualiza su estado; revisa también los registros de ajustes de las etapas aceptadas. Si '
        . 'ningún ajuste afecta otras etapas, no agregues la sección. La plataforma le muestra a la persona las etapas '
        . 'pendientes de actualizar.';

    /** @var string[] Bloque 2, textual: se agrega al control de calidad de cada etapa, despues de detectar inconsistencias. */
    const BLOQUE_CALIDAD_DEPENDENCIA = [
        '- Verificar que los elementos de dependencia de la etapa (fases, actividades o estaciones; organización del grupo; '
            . 'tiempos; criterios; evidencias e instrumentos) coinciden con los de las etapas ya aprobadas.',
        '- Revisar el registro de ajustes y confirmar que ningún ajuste quedó sin propagar a las etapas afectadas.',
    ];

    /** @var string[] Bloque 3, textual: al inicio de la etapa 7, antes de consolidar. */
    const BLOQUE_CONSISTENCIA = [
        'VERIFICACIÓN DE CONSISTENCIA ENTRE ETAPAS:',
        'Antes de consolidar, debes realizar una verificación de consistencia entre las Etapas 2 a 6 y entregarla a la '
            . 'persona facilitadora en una tabla con las siguientes filas:',
        '- Fases, actividades o estaciones: número y nombre en cada etapa.',
        '- Organización del grupo: subgrupos, duplas y rotaciones en cada etapa.',
        '- Tiempos: tiempo por fase y suma total en cada etapa, comparados con el tiempo asignado a la experiencia.',
        '- Criterios de evaluación: criterios abordados por fase, actividad o estación en cada etapa; todos los criterios '
            . 'del plan formativo deben estar cubiertos.',
        '- Evidencias e instrumentos: instrumento y momento de evaluación de cada criterio en las Etapas 5 y 6, y su '
            . 'correspondencia con las actividades de la Etapa 3.',
        'Para cada fila indica «Consistente» o «Inconsistente» y, si es inconsistente, en qué etapas aparece la diferencia '
            . 'y cuál es.',
        'Aplicación:',
        '- Si todas las filas son consistentes, informa el resultado y continúa con la consolidación.',
        '- Si hay al menos una inconsistencia, detén la consolidación, propone la corrección de cada etapa afectada y '
            . 'espera la aprobación de la persona facilitadora. Después de corregir, repite la verificación.',
        '- Si la persona facilitadora decide mantener una inconsistencia, regístrala como inconsistencia aceptada.',
        'El documento consolidado debe incluir, como anexo previo a la evaluación técnica, la tabla de verificación de '
            . 'consistencia y el registro de ajustes.',
        'Las inconsistencias aceptadas que permanezcan en el documento consolidado deben señalarse en las debilidades y '
            . 'recomendaciones de la Etapa 8, y considerarse al asignar el puntaje de los criterios de la rúbrica que '
            . 'correspondan, sin modificar la rúbrica.',
    ];

    /** @var string Titulos del anexo de la consolidacion con la verificacion de consistencia y el registro de ajustes. */
    const ANEXO_CONSISTENCIA = 'Anexo: Verificación de consistencia entre etapas y registro de ajustes';
    const SECCION_CONSISTENCIA = 'Verificación de consistencia entre etapas';

    /**
     * @var string Como se cumple el bloque 3 en la plataforma: la consolidacion se genera de una vez y la evaluacion tecnica
     * la agrega la plataforma despues; la decision sobre las inconsistencias la toma la persona en la pagina.
     */
    const CONSISTENCIA_PLATAFORMA = 'CÓMO SE APLICA EN ESTA PLATAFORMA: la consolidación se genera en un solo paso y la '
        . 'evaluación técnica la agrega la plataforma después del documento. Haz la verificación con los productos '
        . 'aceptados de abajo y entrega la consolidación completa, que termina, después de «Referencias consultadas», con '
        . 'el anexo «# Anexo: Verificación de consistencia entre etapas y registro de ajustes». Ese anexo tiene dos '
        . 'secciones: «## Verificación de consistencia entre etapas», una tabla con una fila por cada elemento de la lista '
        . 'y las columnas «Elemento», «Resultado» («Consistente» o «Inconsistente»), «Etapas y diferencia» y «Corrección '
        . 'propuesta»; y «## Registro de ajustes», con las filas de los registros de ajustes de las etapas reunidas en una '
        . 'sola tabla (si ninguna etapa trae registro, dilo en una línea). Si hay inconsistencias, no corrijas tú las '
        . 'etapas ni las ocultes: anota en «Corrección propuesta» la corrección de cada etapa afectada. La plataforma se lo '
        . 'muestra a la persona facilitadora, que decide corregir esas etapas y volver a consolidar, o confirmar la '
        . 'consolidación manteniéndolas como inconsistencias aceptadas.';

    /** @var string Regla de la etapa 8 sobre las inconsistencias aceptadas (ultimo parrafo del bloque 3). */
    const REGLA_INCONSISTENCIAS = 'INCONSISTENCIAS ACEPTADAS: si el diseño consolidado trae, en su anexo de verificación '
        . 'de consistencia entre etapas, filas «Inconsistente», o inconsistencias aceptadas en el registro de ajustes, son '
        . 'inconsistencias aceptadas por la persona facilitadora. Señálalas en las debilidades y en las recomendaciones, y '
        . 'considéralas al asignar el puntaje de los criterios de la rúbrica que correspondan, sin modificar la rúbrica. Si '
        . 'todas las filas son «Consistente», no hay inconsistencias que considerar.';

    /**
     * @var string[] Bloque 4 del documento «Ajuste Prompt Maestro M2 - Reglas de Consistencia del Diseño» (Interfases,
     * 19-11-2026, version generica), textual, para las etapas 1 a 6. Va a continuacion de «CONTROL DE CAMBIOS ENTRE ETAPAS».
     */
    const BLOQUE_REGLAS_CONSISTENCIA = [
        'REGLAS DE CONSISTENCIA DEL DISEÑO:',
        'REGLA 1. FUENTE ÚNICA DE CADA ELEMENTO',
        'Cada elemento del diseño se define en una sola etapa, y las demás lo citan por su nombre sin redefinirlo:',
        '- Fases, tiempos por fase y organización del grupo: se definen en la Etapa 2 y se desglosan en la Etapa 3, que no '
            . 'puede cambiarlos.',
        '- Instrumentos de evaluación: se definen en la Etapa 5 y se desarrollan en la Etapa 6. La Etapa 3 solo los nombra '
            . 'como evidencias.',
        '- No incluyas ejemplos, borradores ni versiones resumidas de instrumentos fuera de la Etapa 6.',
        'REGLA 2. REGISTRO DE INSTRUMENTOS',
        'En la Etapa 5 debes crear un registro de instrumentos con una fila por instrumento y estas columnas: número, '
            . 'nombre, tipo, criterios que evalúa, momento y actividad de aplicación, unidad evaluada (persona o dupla), '
            . 'puntaje máximo, umbral de aprobación, ponderación y formato (por ejemplo, número de casos o de ítems).',
        '- La Etapa 6 debe desarrollar exactamente los instrumentos del registro, con el mismo número, nombre, puntaje, '
            . 'umbral y formato.',
        '- Toda mención posterior a un instrumento debe usar su número y nombre del registro.',
        'REGLA 3. UMBRAL ÚNICO',
        'Cada umbral de aprobación se declara una sola vez, en el registro de instrumentos o en la regla de juicio '
            . 'evaluativo. Cualquier otra mención debe reproducir el mismo valor. Si en una etapa posterior se modifica un '
            . 'umbral, aplica el control de cambios entre etapas.',
        'REGLA 4. VERIFICACIÓN ARITMÉTICA',
        'Antes de cerrar las Etapas 3, 5 y 6, verifica y muestra el cálculo de:',
        '- La suma de los tiempos de las actividades de cada fase y del total, respecto del tiempo asignado a la '
            . 'experiencia.',
        '- La suma de los puntajes de cada instrumento respecto de su puntaje máximo.',
        '- La suma de los porcentajes de las tablas de especificaciones y de las ponderaciones, que debe ser 100 %.',
        '- La equivalencia entre el umbral en puntos y el umbral en porcentaje. Si el porcentaje declarado no corresponde '
            . 'a un número entero de puntos, declara el puntaje mínimo redondeado hacia arriba y su porcentaje real.',
        'Si un cálculo no cuadra, corrígelo antes de entregar la etapa.',
        'REGLA 5. EVALUACIÓN INDIVIDUAL DEL DESEMPEÑO',
        'Aunque las actividades se realicen en duplas o subgrupos, las evidencias sumativas se registran y califican por '
            . 'persona. Indica cómo se individualiza la observación (por ejemplo, rol rotativo o turno individual de '
            . 'ejecución) y no describas la unidad evaluada como «la dupla» en ninguna etapa.',
        'REGLA 6. COHERENCIA DE LOS AGRUPAMIENTOS',
        'Verifica que el número de participantes permita formar las duplas y los subgrupos declarados sin separar duplas. '
            . 'Si la división no es exacta, explicita la solución (por ejemplo, un trío o subgrupos de distinto tamaño) en '
            . 'la Etapa 2 y mantenla en las demás etapas.',
        'REGLA 7. FACTIBILIDAD DE LA OBSERVACIÓN',
        'Cuando dos o más estaciones o subgrupos trabajen en paralelo:',
        '- Indica quién acompaña cada estación.',
        '- Calcula el tiempo de observación disponible por persona (tiempo de la estación ÷ personas observadas) en cada '
            . 'instrumento de desempeño.',
        '- Si una sola persona facilitadora debe evaluar en una estación y acompañar otra al mismo tiempo, o si el tiempo '
            . 'por persona es insuficiente para aplicar el instrumento completo, regístralo como restricción y propone una '
            . 'solución (persona de apoyo, guía autónoma o más tiempo).',
        'REGLA 8. VACÍOS DE INFORMACIÓN Y SOLUCIONES',
        'Una propuesta de solución no puede contradecir los valores vigentes del diseño. Si la solución modifica tiempos, '
            . 'actividades u organización, aplícala en la etapa correspondiente mediante el control de cambios entre etapas, '
            . 'o redáctala como medida condicional que indique cuándo se activa y qué valores reemplaza.',
        'REGLA 9. NUMERACIÓN',
        'Numera las subsecciones de cada etapa según la estructura definida en este prompt, sin repetir números ni abrir '
            . 'una numeración nueva dentro de una etapa.',
        'REGLA 10. COMPATIBILIDAD CON EL MÓDULO DE ASEGURAMIENTO DE LA CALIDAD',
        'Los niveles de cada instrumento deben poder traducirse a los cuatro niveles de la ficha de registro de la '
            . 'implementación: Insuficiente, Básico, Adecuado y Avanzado. En cada instrumento de la Etapa 6 incluye la regla '
            . 'de equivalencia (rangos de puntaje por nivel), incluidas las condiciones críticas que llevan a Insuficiente. '
            . 'Si el instrumento ya usa esos cuatro niveles, indícalo.',
    ];

    /**
     * @var string[] Bloque 5, textual: ampliacion de la tabla de verificacion de consistencia del Bloque 3 (Etapa 7),
     * despues de «Evidencias e instrumentos».
     */
    const BLOQUE_CONSISTENCIA_EXTRA = [
        '- Registro de instrumentos: número, nombre, puntaje máximo, umbral, ponderación, formato y unidad evaluada de '
            . 'cada instrumento, comparados entre las Etapas 3, 5 y 6.',
        '- Verificación aritmética: tiempos, puntajes, porcentajes y umbrales, según la regla 4.',
        '- Agrupamientos y observación: duplas, subgrupos, persona que acompaña cada estación y tiempo de observación por '
            . 'persona, según las reglas 6 y 7.',
        '- Vacíos de información: propuestas de solución que contradicen valores vigentes, según la regla 8.',
        '- Equivalencia de niveles: regla de equivalencia a los cuatro niveles de la ficha de registro, según la regla 10.',
    ];

    /** @var string[] Bloque 6, textual: se agrega al final del control de calidad interno de cada etapa. */
    const BLOQUE_CALIDAD_CONSISTENCIA = [
        '- Verificar el cumplimiento de las reglas de consistencia del diseño que aplican a la etapa.',
        '- No declarar en la verificación de calidad que algo «coincide exactamente» o tiene «alineación absoluta» sin '
            . 'haber hecho la comparación o el cálculo correspondiente.',
    ];

    /**
     * @var string[] Bloque 7 del documento «Ajuste Prompt Maestro M2 - Materiales Producibles y Economía de Recursos IA»
     * (Interfases, 21-11-2026), textual, para la Etapa 4 (y referencia en la verificacion de consistencia de la Etapa 7).
     * Va como nueva seccion a continuacion de «REGLAS DE CONSISTENCIA DEL DISEÑO».
     */
    const BLOQUE_CATALOGO_PRODUCCION = [
        'CATÁLOGO DE CAPACIDADES DE PRODUCCIÓN:',
        'Para proponer los materiales instruccionales que se producirán con IA debes usar el siguiente catálogo. Cada '
            . 'material se clasifica en uno de tres modos de producción:',
        '- Modo A · Producción completa con IA: el asistente genera el contenido completo del material (textos, tablas, '
            . 'casos, guías, fichas, cuestionarios, guiones, diagramas descritos en texto o código) y lo entrega listo '
            . 'para copiar y pegar o en código HTML.',
        '- Modo B · Producción con IA y servicios de la plataforma: el asistente genera el contenido y un servicio de '
            . 'conversión de la plataforma lo entrega como archivo descargable y editable (documento Word o PDF, planilla '
            . 'Excel o presentación).',
        '- Modo C · Curaduría de recursos existentes con IA: cuando un material no puede generarse con calidad suficiente '
            . '(por ejemplo, un video de un procedimiento real), el asistente busca en internet recursos abiertos ya '
            . 'publicados (por ejemplo, videos en YouTube) y recomienda los mejor contextualizados al aprendizaje '
            . 'esperado, para que la persona facilitadora los seleccione.',
        'Formatos de salida',
        '- Ningún material se carga ni se publica directamente en una plataforma: los materiales se entregan listos para '
            . 'copiar y pegar, en código HTML o como archivo descargable.',
        '- Los materiales pueden usarse en el Moodle del organismo ejecutor, en otra plataforma o en formato impreso o '
            . 'digital con las personas participantes, sin depender de un LMS.',
        '- Los cuestionarios se especifican para su configuración más rápida: formato GIFT para importar en Moodle, script '
            . 'de Google Apps Script para crear un formulario de Google Forms o versión impresa con pauta de respuestas.',
        'Tipos de material y costo relativo de producción con IA, de menor a mayor:',
        '1. Texto y datos estructurados (guías, casos, fichas, glosarios, planillas, plantillas, cuestionarios).',
        '2. Presentaciones, esquemas y diagramas generados a partir de texto o código.',
        '3. Imágenes generadas.',
        '4. Audio generado.',
        '5. Video generado.',
        'La curaduría (Modo C) tiene un costo similar al del tipo 1, porque produce una ficha de recomendación en texto, y '
            . 'suele ser preferible a generar audio o video.',
        'Fuera del catálogo quedan los recursos del entorno formativo, que son siempre recursos físicos: máquinas, '
            . 'equipos, herramientas, materiales e insumos de consumo, elementos de protección personal, talleres, '
            . 'laboratorios y espacios de práctica habilitados. Son indispensables para el desarrollo de competencias, '
            . 'suelen ser la mayor parte de los recursos de una experiencia y los proporciona el organismo ejecutor. Se '
            . 'definen según el plan formativo y la ficha de diagnóstico, y se registran en los recursos del diseño sin '
            . 'las restricciones de este catálogo.',
    ];

    /**
     * @var string[] Bloque 8, textual: al inicio de «ETAPA 4: MATERIALES INSTRUCCIONALES DE APOYO AL APRENDIZAJE».
     * Reglas 11, 12 y 13 y registro obligatorio en la Etapa 4 (4.1, 4.2 y 4.3).
     */
    const BLOQUE_REGLAS_PRODUCCION = [
        'REGLA 11. MATERIALES PRODUCIBLES CON IA',
        '- Distingue en la Etapa 4 dos grupos: los materiales instruccionales producidos con IA y los recursos del entorno '
            . 'formativo que proporciona el organismo ejecutor.',
        '- Propón cada material producido con IA en un solo modo (A, B o C). Si un material requiere partes en distintos '
            . 'modos (por ejemplo, una selección de videos y un dossier de casos), divídelo en dos materiales, cada uno '
            . 'con su modo.',
        '- No propongas materiales cuya producción dependa de herramientas, plataformas de autoría o servicios externos '
            . 'que no figuren en el catálogo (por ejemplo, editores de presentaciones interactivas o plataformas de video '
            . 'interactivo). Si un material de ese tipo sería deseable, propón la alternativa producible equivalente.',
        '- Los materiales producidos con IA apoyan la práctica con recursos reales; nunca la reemplazan. No sustituyas una '
            . 'práctica con equipos, herramientas o espacios reales por un material generado.',
        '- No incluyas en la lista de producción los instrumentos de evaluación, que se desarrollan en la Etapa 6.',
        '- Si un material deseable no puede generarse con calidad suficiente, busca alternativas en este orden: (1) un '
            . 'material equivalente en Modo A o B; (2) la curaduría de recursos existentes en Modo C; (3) si ninguna de '
            . 'las anteriores es viable, reformula la actividad o el material para trabajar el criterio con un material '
            . 'producible en los Modos A, B o C. Los materiales instruccionales siempre se resuelven en estos tres modos: '
            . 'nunca se registran como requerimiento al organismo ejecutor.',
        '- Cuando propongas un material en Modo C, especifica la búsqueda: tipo de recurso, número de opciones que se '
            . 'recomendarán (hasta 5), criterio o actividad a la que sirve, idioma, duración máxima y criterios de '
            . 'selección (pertinencia con el aprendizaje esperado y el contexto laboral, procedimientos correctos y '
            . 'seguros, fuente confiable, acceso libre y vigente).',
        'REGLA 12. ECONOMÍA DE RECURSOS DE IA',
        'Entre las alternativas de materiales producidos con IA que permiten lograr los criterios de evaluación con la '
            . 'misma calidad, elige la de menor costo de producción con IA:',
        '- Prefiere el tipo de material de menor costo relativo del catálogo. Si propones un tipo de mayor costo, '
            . 'justifica qué criterio o qué característica del grupo lo exige.',
        '- Diseña la menor cantidad de materiales que cubra todas las actividades: un mismo material puede servir a '
            . 'varias actividades o fases.',
        '- No repitas contenidos entre materiales ni con los instrumentos de la Etapa 6; remite al material o instrumento '
            . 'que ya los contiene.',
        '- Prefiere plantillas reutilizables que se completen con datos de cada actividad, en lugar de materiales '
            . 'distintos para cada variante.',
        '- Ajusta la extensión de cada material al tiempo de uso previsto en la secuencia instruccional.',
        '- Especifica las versiones impresa y digital de un material como salidas de una sola fuente.',
        'La economía de recursos nunca puede reducir la cobertura de los criterios de evaluación ni la seguridad de las '
            . 'actividades.',
        'REGLA 13. ESPECIFICACIÓN LISTA PARA PRODUCIR',
        'Cada material producido con IA debe quedar especificado con todo lo necesario para que el centro de producción lo '
            . 'genere sin tomar decisiones de diseño:',
        '- Código del material (M1, M2…), que se mantiene en todas las etapas.',
        '- Destinatarios.',
        '- Propósito y actividad o fase en que se usa.',
        '- Contenidos y su secuencia, con los criterios de evaluación a los que sirve.',
        '- Extensión (por ejemplo, número de páginas, tarjetas, preguntas o minutos de uso).',
        '- Estructura (secciones o partes).',
        '- Formato de salida (texto para copiar y pegar, código HTML o archivo descargable, y versión impresa o digital) '
            . 'y, en cuestionarios, la plataforma de uso (Moodle, Google Forms o impreso).',
        '- Tipo de material y modo de producción.',
        '- En Modo C, la especificación de la búsqueda.',
        'REGISTRO OBLIGATORIO EN LA ETAPA 4',
        '- En la descripción de cada material producido con IA (4.1), registra todos los campos de la regla 13 y, cuando '
            . 'corresponda, la justificación de un tipo de costo mayor que el texto.',
        '- Presenta la síntesis de materiales (4.2) en dos listas: la lista de producción, con las columnas «Código», '
            . '«Material», «Fase y actividad», «Tipo de material», «Modo de producción», «Formato de salida» y «Criterios '
            . 'de evaluación», que se envía al centro de producción; y la lista de recursos del entorno formativo, a cargo '
            . 'del organismo ejecutor. Esta segunda lista solo puede incluir recursos físicos para talleres, laboratorios '
            . 'o espacios de práctica: máquinas, equipos y herramientas, materiales e insumos de consumo, elementos de '
            . 'protección personal y espacios físicos habilitados.',
        '- En el análisis de factibilidad (4.3), agrega la factibilidad de producción con IA: número de materiales por '
            . 'modo, tipos de mayor costo utilizados y su justificación, y materiales que debieron reformularse para '
            . 'quedar en los Modos A, B o C.',
    ];

    /** @var string[] Bloque 9 (Etapa 4), textual: verificaciones adicionales del control de calidad interno de la Etapa 4. */
    const BLOQUE_CALIDAD_PRODUCCION = [
        '- Verificar que cada material producido con IA tenga un solo modo y que su tipo y formato de salida correspondan '
            . 'al catálogo.',
        '- Verificar que cada material de la lista de producción cumpla todos los campos de la regla 13.',
        '- Verificar que ningún material producido con IA reemplace una práctica con recursos reales prevista en el diseño.',
        '- Verificar que la lista de producción no incluya instrumentos de evaluación ni herramientas fuera del catálogo.',
        '- Verificar que la lista de recursos del entorno formativo contenga solo recursos físicos, sin materiales '
            . 'instruccionales.',
        '- Verificar que no haya materiales con contenidos repetidos entre sí ni con los instrumentos de la Etapa 6.',
    ];

    /**
     * @var string Forma de cumplir en la plataforma la regla 13 («Código del material (M1, M2…), que se mantiene en todas las
     * etapas»): el Prompt Maestro es un solo texto, pero aqui cada etapa tiene su pedido y la regla va solo en la Etapa 4.
     * Va en las Etapas 3, 5 y 6 cuando la Etapa 4 ya esta aceptada, porque la Etapa 7 compara esos codigos en las
     * Etapas 3 y 5 (fila «Producción de materiales»).
     */
    const CODIGOS_MATERIALES_PLATAFORMA = '- Materiales instruccionales: cuando nombres un material de la Etapa 4, cítalo '
        . 'por su código y nombre tal como aparecen en su lista de producción (por ejemplo, «M3 · Dossier de casos»): el '
        . 'código del material se mantiene en todas las etapas (regla 13).';

    /** @var string Bloque 9 (Etapa 7), textual: fila adicional en la tabla de verificacion de consistencia del Bloque 3. */
    const BLOQUE_CONSISTENCIA_PRODUCCION = '- Producción de materiales: código, tipo, modo y formato de salida de cada '
        . 'material producido con IA en la Etapa 4, comparados con el catálogo de capacidades, y referencias a los mismos '
        . 'códigos en las Etapas 3 y 5.';

    /**
     * @var string[] Regla 14 (23-11-2026): calculo ascendente desde las tablas, sin fijar totales antes de sumar.
     */
    const REGLA_14_CALCULO_ASCENDENTE = [
        'REGLA 14. CÁLCULO ASCENDENTE',
        'El tiempo de cada fase es el resultado de sumar sus actividades, no un valor fijado antes. Escribe la suma '
            . 'copiando los valores exactos desde la tabla de actividades (por ejemplo: 30 + 150 + 150 + 30 = 360 min) y '
            . 'verifica que cuadre con el subtotal de la fase y con el tiempo asignado a la experiencia (2.3). Lo mismo '
            . 'aplica a puntajes máximos, tablas de especificaciones y ponderaciones en las Etapas 5 y 6. En la Etapa 7 '
            . 'todo se recalcula desde las tablas, sin copiar las verificaciones anteriores.',
    ];

    /**
     * @var string[] Regla 15 (23-11-2026): persona concreta que aplica cada instrumento o parte; acompanar != evaluar.
     */
    const REGLA_15_QUIEN_APLICA = [
        'REGLA 15. QUIÉN APLICA CADA INSTRUMENTO',
        'Agrega al registro de instrumentos de la Etapa 5 la columna «Persona que aplica». Cada parte de un instrumento '
            . '(sección, estación o bloque de conductas) debe tener una persona concreta que la aplique en ese momento. '
            . '«Acompañar» o «supervisar» no cuenta como aplicar un instrumento de evaluación.',
    ];

    /**
     * @var string[] Regla 16 (23-11-2026): desglose de tiempo cuando una actividad combina varias tareas.
     */
    const REGLA_16_VARIAS_TAREAS = [
        'REGLA 16. ACTIVIDADES CON VARIAS TAREAS',
        'Si una actividad incluye varias tareas (como traslado, visita, regreso y prueba), indica el tiempo estimado en '
            . 'minutos de cada una y verifica que su suma no supere la duración declarada de la actividad.',
    ];

    /**
     * @var string[] Regla 17 (23-11-2026): una fila por cada aplicacion de un instrumento en la Etapa 5.
     */
    const REGLA_17_UNA_FILA_POR_APLICACION = [
        'REGLA 17. UNA FILA POR APLICACIÓN',
        'Cada fila del registro de instrumentos de la Etapa 5 corresponde a una sola aplicación. Si un instrumento se '
            . 'aplica en dos momentos distintos, regístralo en dos filas separadas (una por aplicación, con su momento, '
            . 'actividad y persona que aplica).',
    ];

    /** @var string Ampliacion de la Regla 1 a materiales e instrumentos (23-11-2026). */
    const REGLA_1_AMPLIACION_MATERIALES = '- Materiales e instrumentos: los materiales instruccionales se definen solo en '
        . 'la Etapa 4, con su código (M1, M2…). La Etapa 3 no puede incluir un catálogo propio de materiales y, cuando '
        . 'exista la Etapa 4, los menciona por su código. Toda pauta, lista de cotejo, escala de apreciación, rúbrica o '
        . 'prueba —formativa o sumativa— es un instrumento de la Etapa 5 y nunca un material ni un ítem de la lista de '
        . 'producción.';

    /** @var string Formatos de salida cerrados en la Etapa 4 (23-11-2026). */
    const REGLA_FORMATOS_CERRADOS = 'FORMATOS DE SALIDA CERRADOS: el formato de salida de cada material solo puede ser uno '
        . 'de los definidos en el catálogo (texto para copiar y pegar, código HTML, archivo descargable Word/PDF/Excel/'
        . 'presentación, cuestionario en GIFT/Google Apps Script/impreso con pauta, o ficha de recomendación Modo C). '
        . '«Markdown» o «tabla Markdown» es una forma de escribir el texto, nunca un formato de salida.';

    /** Estado del producto. */
    const BORRADOR = 'borrador';
    const ACEPTADO = 'aceptado';

    /** @var int Tope de caracteres de cada etapa previa que viaja al modelo. */
    const PREVIA_MAX = 20000;

    /**
     * Las seis etapas de la Fase 2, con lo que el Prompt Maestro pide que incluya cada una.
     *
     * @return array numero => ['titulo' => string, 'archivo' => string, 'incluye' => string[], 'nota' => string].
     */
    public static function etapas(): array {
        return [
            1 => ['titulo' => 'Análisis diagnóstico', 'archivo' => 'Etapa_1_Analisis_diagnostico',
                'incluye' => ['Síntesis diagnóstica',
                    'Resultado de la validación (Etapa 0) y observaciones no resueltas, incorporadas como restricciones o '
                        . 'prioridades de diseño',
                    'Síntesis de la observación obligatoria 2.4 (pertinencia y factibilidad de los criterios) y de las '
                        . 'observaciones finales de la ficha',
                    'Prioridades de diseño', 'Restricciones', 'Oportunidades', 'Recomendación didáctica',
                    'Nota de uso de documentos complementarios (plan formativo y perfil ocupacional)'],
                'nota' => ''],
            2 => ['titulo' => 'Diseño de la experiencia', 'archivo' => 'Etapa_2_Diseno_de_la_experiencia',
                'incluye' => ['Nombre de la experiencia de aprendizaje', 'Propósito', 'Aprendizaje esperado',
                    'Criterios de evaluación', 'Contexto de aplicación', 'Modalidad', 'Estrategia didáctica', 'Recursos',
                    'Condiciones de implementación'],
                'nota' => 'El nombre de la experiencia debe ser claro, breve y representativo del desempeño esperado, '
                    . 'estar alineado con el aprendizaje esperado y ser utilizable como título formal del diseño.'],
            3 => ['titulo' => 'Secuencia instruccional', 'archivo' => 'Etapa_3_Secuencia_instruccional',
                'incluye' => ['Inicio / Desarrollo / Cierre',
                    'Alcance de cada fase',
                    'Actividades (persona facilitadora / participantes)', 'Recursos',
                    'Tiempo estimado en minutos por actividad y por fase; la suma total no debe superar el tiempo asignado '
                        . 'a la experiencia de aprendizaje (ficha, subsección 2.3)',
                    'Evidencias parciales'],
                // Indicaciones del Prompt Maestro que van solo al pedido a la IA, no a la lista que ve la persona.
                'indicaciones' => ['Alcance de cada fase: descripción de lo que se espera lograr en la fase, sin '
                    . 'formularlo como objetivo específico.'],
                // Lo que la revision automatica busca en el producto (titulos, columnas de tabla o rotulos en negrita).
                'comprobar' => ['Inicio', 'Desarrollo', 'Cierre', 'Alcance', 'Actividades', 'Recursos', 'Minutos',
                    'Evidencias parciales'],
                'nota' => ''],
            4 => ['titulo' => 'Materiales instruccionales de apoyo al aprendizaje',
                'archivo' => 'Etapa_4_Materiales_instruccionales',
                'incluye' => ['Materiales por fase', 'Alcance', 'Contenidos', 'Formato', 'Uso didáctico',
                    'Evidencias asociadas', 'Tabla de síntesis', 'Factibilidad', 'Vacíos y soluciones'],
                'nota' => 'No incluye instrumentos de evaluación: estos se desarrollan en la Etapa 6.'],
            5 => ['titulo' => 'Plan de evaluación', 'archivo' => 'Etapa_5_Plan_de_evaluacion',
                'incluye' => ['Tabla con: aprendizaje esperado, criterio de evaluación, evidencia, instrumento, '
                    . 'indicador de logro, momento de evaluación y responsable'],
                'comprobar' => ['Aprendizaje esperado', 'Criterio de evaluación', 'Evidencia', 'Instrumento',
                    'Indicador de logro', 'Momento de evaluación', 'Responsable'],
                'nota' => 'Una fila por criterio de evaluación.'],
            6 => ['titulo' => 'Instrumentos evaluativos', 'archivo' => 'Etapa_6_Instrumentos_evaluativos',
                'incluye' => ['Rúbricas', 'Listas de cotejo', 'Escalas de apreciación', 'Pautas de observación',
                    'Pruebas aplicadas'],
                // Los tipos de instrumento dependen del plan de evaluacion: no se exige cada uno.
                'comprobar' => [],
                'nota' => 'Desarrolla solo los instrumentos que el plan de evaluación (etapa 5) definió. Todos los '
                    . 'instrumentos deben estar redactados y listos para implementación.'],
        ];
    }

    /** Etapas de la Fase 3 (Prompt Maestro, etapas 7 y 8). */
    const CONSOLIDACION = 7;
    const EVALUACION = 8;

    /** @var int Puntaje desde el que el diseño queda aprobado (rubrica: 25 a 31 «Aprobado con ajustes menores»). */
    const APROBADO_DESDE = 25;

    /** @var int Segundos que puede tardar la consolidacion con el asistente (un documento largo, en extenso). */
    const CONSOLIDACION_MAX = 900;

    /** @var float Parte minima del texto de las etapas que debe conservar la consolidacion para darla por completa. */
    const CONSOLIDACION_MIN = 0.5;

    /** @var int Recomendaciones que se piden siempre, aunque el total sea 36. */
    const RECOMENDACIONES_MIN = 2;

    /** @var int Largo minimo de la justificacion de un puntaje 4: sin evidencia citada, baja a 3. */
    const EVIDENCIA_MIN = 60;

    /**
     * Las etapas de la Fase 3: consolidacion (no editable) y evaluacion con la rubrica.
     *
     * @return array Mismo formato que etapas().
     */
    public static function etapas_fase3(): array {
        return [
            self::CONSOLIDACION => ['titulo' => 'Consolidación del diseño', 'archivo' => 'Diseno_consolidado',
                'incluye' => ['Análisis', 'Diseño', 'Secuencia', 'Materiales', 'Evaluación', 'Instrumentos'],
                'nota' => 'Todos los entregables anteriores, en extenso, en un documento único. El diseño consolidado '
                    . 'no es editable: si quieres ajustar algo, hazlo en la etapa que corresponda de la Fase 2.'],
            self::EVALUACION => ['titulo' => 'Evaluación del diseño', 'archivo' => 'Diseno_final_validado',
                'incluye' => ['Puntaje por criterio, indicando para cada criterio limitado la sección y el ítem del '
                    . 'checklist que originan el límite', 'Puntaje total (máx. 36) y puntaje máximo alcanzable',
                    'Fortalezas', 'Debilidades', 'Recomendaciones'],
                'nota' => 'El asistente aplica la rúbrica de validación técnica del diseño. Con 25 puntos o más el '
                    . 'diseño queda aprobado. El documento final lleva impresos el puntaje y su interpretación.'],
        ];
    }

    /**
     * Las ocho etapas del Prompt Maestro.
     *
     * @return array
     */
    public static function todas(): array {
        return self::etapas() + self::etapas_fase3();
    }

    /**
     * Rubrica de validacion tecnica del diseno, con el texto del documento del cliente (version del 30-10-2026,
     * «Rúbrica Validación Técnica Diseño - Interfases»). El nivel 3 de los criterios 1 a 8 incluye el caso «O bien»
     * del limite por observaciones no resueltas; el informe lo muestra solo en un criterio limitado.
     *
     * @return array id => ['nombre' => string, 'niveles' => [4 => string, 3 =>, 2 =>, 1 =>]].
     */
    public static function rubrica(): array {
        return [
            'r1' => ['nombre' => 'Coherencia con la ficha diagnóstica', 'niveles' => [
                4 => 'El diseño incorpora la información relevante de la ficha validada (características y '
                    . 'necesidades del grupo, contexto y decisiones de diseño) y responde a las observaciones 2.4 y '
                    . 'finales. Las diferencias con la ficha están justificadas.',
                3 => 'Incorpora la información de la ficha con omisiones menores; las decisiones principales son '
                    . 'coherentes con ella. O bien: cumple el nivel 4, pero el criterio está limitado por una '
                    . 'observación no resuelta del diagnóstico (secciones 1, 4 y 9 del checklist).',
                2 => 'Usa la ficha de forma parcial: omite información relevante del grupo o del contexto, o se '
                    . 'aparta de sus decisiones sin justificarlo.',
                1 => 'No considera la ficha o la contradice.']],
            'r2' => ['nombre' => 'Alineación con aprendizaje esperado y criterios', 'niveles' => [
                4 => 'Todas las actividades y evidencias se vinculan con el aprendizaje esperado y con cada criterio '
                    . 'utilizado (al menos 3 del plan formativo, o todos si declara menos), sin modificaciones.',
                3 => 'Se alinea con el aprendizaje esperado y con la mayoría de los criterios utilizados; algún '
                    . 'criterio tiene una vinculación débil con actividades o evidencias. Sin modificaciones. O '
                    . 'bien: cumple el nivel 4, pero el criterio está limitado por una observación no resuelta del '
                    . 'diagnóstico (sección 2 del checklist).',
                2 => 'Alineación incompleta: uno o más criterios sin actividades o evidencias asociadas, o se usan '
                    . 'menos de 3 criterios cuando el plan formativo declara más.',
                1 => 'No hay alineación, o se modifican, omiten o inventan el aprendizaje esperado o los criterios.']],
            'r3' => ['nombre' => 'Calidad de la experiencia de aprendizaje', 'niveles' => [
                4 => 'Nombre claro, breve y representativo del desempeño; propósito preciso; modalidad y estrategia '
                    . 'coherentes con la sección 7 de la ficha o con un cambio justificado.',
                3 => 'Experiencia bien formulada; uno de sus elementos (nombre, propósito, modalidad o estrategia) '
                    . 'requiere una mejora menor. O bien: cumple el nivel 4, pero el criterio está limitado por una '
                    . 'observación no resuelta del diagnóstico (secciones 3, 5 y 7 del checklist).',
                2 => 'Dos o más elementos presentan debilidades de claridad o pertinencia, o la modalidad o '
                    . 'estrategia difieren de la ficha sin justificación.',
                1 => 'Experiencia mal formulada o no pertinente para el aprendizaje esperado.']],
            'r4' => ['nombre' => 'Secuencia instruccional', 'niveles' => [
                4 => 'Progresión clara de inicio, desarrollo y cierre; cada fase tiene alcance, actividades, '
                    . 'recursos, tiempo en minutos y evidencias parciales. El tiempo total no supera el asignado '
                    . '(2.3).',
                3 => 'Secuencia clara y completa, con ajustes menores en actividades, tiempos o evidencias; tiempo '
                    . 'total dentro de lo asignado. O bien: cumple el nivel 4, pero el criterio está limitado por '
                    . 'una observación no resuelta del diagnóstico (sección 2 (ítem de tiempo) y sección 4 del '
                    . 'checklist).',
                2 => 'Desajustes en la progresión, fases incompletas o tiempo total que supera el asignado.',
                1 => 'Sin progresión clara o secuencia incoherente con el aprendizaje esperado.']],
            'r5' => ['nombre' => 'Materiales instruccionales', 'niveles' => [
                4 => 'Materiales definidos por fase (alcance, contenidos, formato y uso), pertinentes, alineados con '
                    . 'las evidencias y factibles con los recursos de la ficha.',
                3 => 'Materiales pertinentes y alineados, con mejoras menores en su definición o factibilidad. O '
                    . 'bien: cumple el nivel 4, pero el criterio está limitado por una observación no resuelta del '
                    . 'diagnóstico (sección 6 del checklist).',
                2 => 'Materiales genéricos, poco claros o sin vínculo explícito con las evidencias.',
                1 => 'Materiales no pertinentes, desconectados de la secuencia o ausentes.']],
            'r6' => ['nombre' => 'Plan de evaluación', 'niveles' => [
                4 => 'Cada criterio utilizado tiene evidencia, instrumento, indicador de logro, momento de '
                    . 'evaluación y responsable, con relaciones claras entre ellos.',
                3 => 'Plan coherente; algún elemento requiere un ajuste menor sin afectar la cobertura de los '
                    . 'criterios. O bien: cumple el nivel 4, pero el criterio está limitado por una observación no '
                    . 'resuelta del diagnóstico (secciones 2 y 7 del checklist).',
                2 => 'Relaciones incompletas o poco claras: uno o más criterios sin evidencia, instrumento o '
                    . 'indicador.',
                1 => 'Plan desalineado con el aprendizaje esperado y los criterios, o ausente.']],
            'r7' => ['nombre' => 'Instrumentos evaluativos', 'niveles' => [
                4 => 'Un instrumento por cada evidencia del plan, coherente con su tipo, con indicadores, escala y '
                    . 'pautas de uso; redactado y listo para aplicar.',
                3 => 'Instrumentos coherentes con el plan, con ajustes menores de redacción o escala. O bien: cumple '
                    . 'el nivel 4, pero el criterio está limitado por una observación no resuelta del diagnóstico '
                    . '(sección 7 del checklist).',
                2 => 'Instrumentos poco claros o incompletos (sin escala, indicadores o pautas), o alguna evidencia '
                    . 'del plan sin instrumento.',
                1 => 'Instrumentos no válidos para las evidencias, mal formulados o ausentes.']],
            'r8' => ['nombre' => 'Factibilidad de implementación', 'niveles' => [
                4 => 'Implementable con los recursos, el tiempo y el contexto registrados en la ficha, y aborda los '
                    . 'riesgos identificados en la sección 8.',
                3 => 'Viable con ajustes menores (por ejemplo, reorganizar espacios o turnos) que no requieren '
                    . 'recursos adicionales relevantes. O bien: cumple el nivel 4, pero el criterio está limitado '
                    . 'por una observación no resuelta del diagnóstico (sección 2 (ítem de tiempo) y secciones 5, 6 '
                    . 'y 8 del checklist).',
                2 => 'Factibilidad limitada: requiere recursos, tiempo o condiciones no disponibles según la ficha, '
                    . 'sin proponer alternativas.',
                1 => 'No viable en el contexto real registrado en la ficha.']],
            'r9' => ['nombre' => 'Calidad formal del documento', 'niveles' => [
                4 => 'Documento único, ordenado y sin repeticiones, con lenguaje claro e inclusivo y terminología de '
                    . 'formación por competencias; listo para uso institucional.',
                3 => 'Presentación adecuada, con mejoras menores de formato o redacción.',
                2 => 'Problemas de claridad, organización o terminología que dificultan su uso.',
                1 => 'Documento deficiente, incompleto o poco usable.']],
        ];
    }

    /**
     * Criterios de la rubrica vinculados a cada item del checklist de la Etapa 0, para el limite por observaciones no
     * resueltas (Etapa 8 del Prompt Maestro, ajuste del cliente del 30-10-2026). Por seccion, salvo el item de tiempo
     * (c2_4: criterios 4 y 8, en lugar de 2 y 6) y la seccion 7, que va por item. El criterio 9 no se limita. Funcion pura.
     *
     * @return array id de item => ids de criterio.
     */
    public static function rubric_links(): array {
        $porseccion = [1 => ['r1'], 2 => ['r2', 'r6'], 3 => ['r3'], 4 => ['r1', 'r4'], 5 => ['r3', 'r8'],
            6 => ['r5', 'r8'], 8 => ['r8'], 9 => ['r1']];
        // Seccion 7: modalidad o estrategia metodologica → 3; evidencia → 6; instrumento → 7.
        $poritem = ['c2_4' => ['r4', 'r8'], 'c7_1' => ['r3'], 'c7_2' => ['r3'], 'c7_3' => ['r6'], 'c7_4' => ['r7']];
        $vinculos = [];
        foreach (exante::checklist() as $n => $seccion) {
            foreach (array_keys($seccion['items']) as $id) {
                $vinculos[$id] = $poritem[$id] ?? ($porseccion[$n] ?? []);
            }
        }
        return $vinculos;
    }

    /**
     * Criterios con puntaje maximo 3 por las observaciones no resueltas de la Etapa 0 (items ⚠ o ❌ de la validacion
     * vigente: la que habilito la Fase 2), con los items que originan cada limite. Cada criterio se limita una sola vez.
     * Funcion pura.
     *
     * @param array $validacion Informe de la validacion (['items' => [id => ['estado' => ...]]]).
     * @return array id de criterio => ids de item que lo limitan, en el orden de la rubrica.
     */
    public static function rubric_limits(array $validacion): array {
        $vinculos = self::rubric_links();
        $limites = [];
        foreach (array_keys(exante::unresolved_items($validacion['items'] ?? [])) as $id) {
            foreach ($vinculos[$id] ?? [] as $criterio) {
                $limites[$criterio][] = $id;
            }
        }
        $ordenado = [];
        foreach (array_keys(self::rubrica()) as $criterio) {
            if (isset($limites[$criterio])) {
                $ordenado[$criterio] = $limites[$criterio];
            }
        }
        return $ordenado;
    }

    /**
     * Seccion e item del checklist que originan un limite, como texto. Funcion pura.
     *
     * @param string[] $ids Items del checklist.
     * @return string Por ejemplo «Sección 2, «El tiempo para ...»; Sección 6, «...»».
     */
    public static function limit_origins(array $ids): string {
        $textos = [];
        foreach (exante::checklist() as $n => $seccion) {
            foreach ($seccion['items'] as $id => $texto) {
                if (in_array($id, $ids, true)) {
                    $textos[] = 'Sección ' . $n . ', «' . $texto . '»';
                }
            }
        }
        return implode('; ', $textos);
    }

    /**
     * Interpretacion del puntaje total, con el texto de la rubrica.
     *
     * @param int $total
     * @return array ['clave' => alto|menores|importantes|no, 'texto' => string, 'aprobado' => bool].
     */
    public static function band(int $total): array {
        if ($total >= 32) {
            return ['clave' => 'alto', 'texto' => 'Aprobado con alto estándar técnico', 'aprobado' => true];
        }
        if ($total >= self::APROBADO_DESDE) {
            return ['clave' => 'menores', 'texto' => 'Aprobado con ajustes menores', 'aprobado' => true];
        }
        if ($total >= 18) {
            return ['clave' => 'importantes', 'texto' => 'Requiere ajustes importantes antes de aprobación',
                'aprobado' => false];
        }
        return ['clave' => 'no', 'texto' => 'No aprobado', 'aprobado' => false];
    }

    /**
     * Huella de lo que se consolida: el contenido de las etapas 1 a 6 aceptadas. Si cambia, hay que consolidar
     * y evaluar de nuevo.
     *
     * @param \stdClass[] $filas etapa => fila.
     * @return string
     */
    public static function stages_fingerprint(array $filas): string {
        $partes = [];
        foreach (array_keys(self::etapas()) as $n) {
            $fila = $filas[$n] ?? null;
            $partes[$n] = ($fila && $fila->estado === self::ACEPTADO) ? (string) $fila->contenido : '';
        }
        return sha1(json_encode($partes, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Nombre de la experiencia, tomado del diseno (etapa 2), para el titulo del documento final.
     *
     * @param string $texto Etapa 2 como texto.
     * @return string Vacio si no se encuentra.
     */
    public static function experience_name(string $texto): string {
        if (preg_match('/Nombre de la experiencia(?: de aprendizaje)?\s*:?\**\s*:?\s*\n*\s*([^\n]{4,160})/iu', $texto, $m)) {
            // Sin las acotaciones que a veces agrega el modelo al final («(7 palabras).»).
            $nombre = preg_replace('/\s*\([^()]*\)\s*\.?\s*$/u', '', trim($m[1]));
            return trim($nombre, " \t*:.\"«»");
        }
        return '';
    }

    /**
     * Documento consolidado: las seis etapas aceptadas, tal cual, en orden («transcribir en extenso»).
     *
     * @param string[] $contenidos etapa => HTML del producto aceptado.
     * @return string HTML.
     */
    public static function consolidate(array $contenidos): string {
        ksort($contenidos);
        $html = '';
        foreach ($contenidos as $n => $contenido) {
            if (isset(self::etapas()[$n])) {
                $html .= "\n" . trim((string) $contenido) . "\n<hr>";
            }
        }
        return trim($html);
    }

    /**
     * Instrucciones para la etapa 8: aplicar la rubrica al diseno consolidado.
     *
     * @param string $diseno Diseno consolidado como texto.
     * @param string $ficha Ficha como texto.
     * @param string[] $adjuntos
     * @param bool $conlibreria Si la libreria del rol viene en el pedido.
     * @param bool $conbusqueda Si el modelo puede buscar en la web.
     * @param array $validacion Informe de la validacion de la ficha (items con estado y observacion), para que la
     *        evaluacion sea consistente con la calidad del diagnostico.
     * @param bool $control Inconsistencias aceptadas del control de cambios (interruptor «controlcambios»).
     * @param string[] $aceptadas Inconsistencias aceptadas registradas en las etapas (accepted_inconsistencies()).
     * @return string
     */
    public static function build_eval_prompt(string $diseno, string $ficha, array $adjuntos, bool $conlibreria = false,
            bool $conbusqueda = false, array $validacion = [], bool $control = false, array $aceptadas = []): string {
        $rubrica = [];
        foreach (self::rubrica() as $id => $c) {
            $rubrica[] = $id . '. ' . $c['nombre'];
            foreach ($c['niveles'] as $nivel => $texto) {
                $rubrica[] = '   Nivel ' . $nivel . ' (' . $nivel . ' pts): ' . $texto;
            }
        }
        // Limite por observaciones no resueltas: lo calcula el codigo con el vinculo seccion → criterio del cliente.
        $limites = self::rubric_limits($validacion);
        $limitados = [];
        foreach ($limites as $criterio => $items) {
            $limitados[] = '- ' . $criterio . ' (' . self::rubrica()[$criterio]['nombre'] . '): ' . self::limit_origins($items);
        }
        $limite = $limitados
            ? "En este diseño, los criterios con puntaje máximo 3 son:\n" . implode("\n", $limitados) . "\n"
                . 'El límite es un máximo, no un puntaje asignado: si el diseño merece un puntaje menor, asigna el que '
                . 'corresponda según la rúbrica. En la justificación de cada criterio limitado indica la sección y el ítem '
                . 'del checklist que originan el límite. Puntaje máximo alcanzable: ' . (36 - count($limites)) . ' de 36.'
            : 'En este diseño ningún criterio queda limitado: el puntaje máximo alcanzable es 36.';
        return implode("\n", [
            'Eres el ' . exante::ROL . ': un asistente experto en diseño instruccional con enfoque de competencias. Tu '
                . 'comportamiento es estricto, estructurado y orientado a asegurar calidad.',
            '',
            'TAREA: ETAPA 8, EVALUACIÓN DEL DISEÑO. Evalúa cada criterio (1 a 4) utilizando la rúbrica de validación '
                . 'técnica del diseño: asigna a cada criterio el puntaje del nivel cuyo descriptor mejor corresponda, con '
                . 'una justificación breve y específica que cite el diseño. No reescribas el diseño.',
            'Usa la ficha de diagnóstico validada como referencia para la coherencia y la factibilidad.'
                . ($adjuntos ? ' Se adjuntan ' . implode(' y ', $adjuntos) . ' para validar la alineación.' : ''),
            'Usa terminología de formación por competencias y lenguaje inclusivo con perspectiva de género. Evalúas el '
                . 'diseño, no a la persona.',
            exante::NO_PEDAGOGIA,
            '',
            'LÍMITE POR OBSERVACIONES NO RESUELTAS: la evaluación del diseño debe ser consistente con la calidad del '
                . 'diagnóstico validado. Se consideran observaciones no resueltas los ítems evaluados ⚠ o ❌ en la Etapa 0 '
                . 'que no fueron corregidos en la ficha antes de iniciar la Etapa 1, según el registro de la Etapa 0. Cuando '
                . 'una sección del checklist tiene al menos una observación no resuelta, el puntaje máximo de los criterios '
                . 'de la rúbrica vinculados a esa sección es 3; cada criterio se limita una sola vez, y el criterio 9 '
                . '(Calidad formal del documento) no se limita. ' . self::pending_observations($validacion),
            $limite,
            '',
            $control ? self::REGLA_INCONSISTENCIAS . ($aceptadas ? "\nInconsistencias aceptadas registradas en las etapas "
                . "(considéralas aunque el anexo no las muestre):\n- " . implode("\n- ", $aceptadas) : '') . "\n" : '',
            'EVALÚA CON OJO CRÍTICO: este diseño se elaboró con apoyo de una IA, así que no lo des por bueno porque '
                . 'esté bien redactado: revísalo como lo haría una persona revisora externa. Cada puntaje 4 debe '
                . 'citar, en la justificación, la evidencia concreta del diseño que lo prueba (el elemento o fragmento '
                . 'textual); si no puedes citarla, el puntaje es 3. Antes de dar un 4, comprueba uno por uno los elementos del '
                . 'descriptor del nivel 4 contra el diseño: si falta alguno, el puntaje es 3 y la justificación dice cuál '
                . 'falta. El 4 es el nivel de excelencia: no es el valor por defecto. Aunque el total llegue a 36, entrega al menos 2 '
                . 'recomendaciones concretas de mejora.',
            '',
            implode("\n", exante::knowledge_rules($conlibreria, $conbusqueda)),
            '',
            'PAUTAS DE APLICACIÓN DE LA RÚBRICA:',
            '1. Asignación del nivel: se asigna el nivel cuyo descriptor se cumple íntegramente. Si el diseño cumple '
                . 'solo parte del nivel 4, corresponde el nivel 3 o inferior.',
            '2. Ajuste u omisión «menor»: no afecta la alineación con el aprendizaje esperado y los criterios ni la '
                . 'factibilidad, y puede corregirse sin rediseñar la experiencia. Es «importante» cuando exige rediseñar '
                . 'una fase, actividad, evidencia o instrumento.',
            '3. Criterios limitados: cuando corresponda aplicar el límite por observaciones no resueltas del '
                . 'diagnóstico, el puntaje se informa como «3 (limitado)», con la sección y el ítem del checklist que lo '
                . 'originan. Si el desempeño corresponde a un nivel inferior, se asigna ese nivel. (En el JSON el puntaje '
                . 'va como número: 3; el informe agrega «(limitado)».)',
            '',
            'RÚBRICA (id. criterio):',
            implode("\n", $rubrica),
            '',
            guardrails::injection_clause(),
            '',
            'FORMATO DE RESPUESTA: únicamente un objeto JSON válido, sin texto antes ni después y sin bloques de código:',
            '{"criterios": {"r1": {"puntaje": 1-4, "justificacion": "..."}, ... un elemento por cada criterio r1 a r9 ...}, '
                . '"fortalezas": ["máximo 6"], "debilidades": ["máximo 6"], "recomendaciones": ["máximo 6, concretas, '
                . 'indicando la etapa de la Fase 2 donde aplicarlas"]}',
            '',
            'FICHA DE DIAGNÓSTICO VALIDADA:',
            guardrails::isolate_user_content($ficha),
            '',
            'DISEÑO CONSOLIDADO A EVALUAR:',
            guardrails::isolate_user_content($diseno),
        ]);
    }

    /**
     * Lee la evaluacion del modelo. Sin los nueve criterios con puntaje de 1 a 4, no sirve. El total y la
     * interpretacion los calcula el codigo, no el modelo.
     *
     * @param string|null $texto
     * @param bool $exigeevidencia Un 4 sin evidencia citada cuenta como 3 (interruptor «iaapoyo»).
     * @param array $limites Criterios con tope 3 por observaciones no resueltas (rubric_limits()): id => items.
     * @return array|null criterios (id => [puntaje, justificacion]), total, banda, limitados (ids con tope 3), limites
     *         (id => items que lo originan), maximo (36 menos los limitados), fortalezas, debilidades, recomendaciones.
     */
    public static function parse_eval(?string $texto, bool $exigeevidencia = true, array $limites = []): ?array {
        $json = exante::decode_json($texto);
        if (!$json || !isset($json['criterios']) || !is_array($json['criterios'])) {
            return null;
        }
        // Etapa 8 del Prompt Maestro (ajuste del 30-10-2026): el tope 3 lo fija el codigo, no lo que diga el modelo.
        $limites = array_intersect_key($limites, self::rubrica());
        $limitados = array_keys($limites);
        $criterios = [];
        $total = 0;
        foreach (array_keys(self::rubrica()) as $id) {
            $c = $json['criterios'][$id] ?? null;
            $p = is_array($c) && isset($c['puntaje']) && is_numeric($c['puntaje']) ? (int) $c['puntaje'] : 0;
            if ($p < 1 || $p > 4 || (float) $c['puntaje'] !== (float) $p) {
                return null;
            }
            $justificacion = \core_text::substr(self::strip_citations(trim((string) ($c['justificacion'] ?? ''))), 0, 800);
            $p = $exigeevidencia ? self::supported_score($p, $justificacion) : $p;
            if (in_array($id, $limitados, true)) {
                $p = min($p, 3);
            }
            $criterios[$id] = ['puntaje' => $p, 'justificacion' => $justificacion];
            $total += $p;
        }
        $lista = static function($v) {
            $salida = [];
            foreach ((array) $v as $t) {
                $t = is_scalar($t) ? \core_text::substr(self::strip_citations(trim((string) $t)), 0, 600) : '';
                if ($t !== '') {
                    $salida[] = $t;
                }
            }
            return array_slice($salida, 0, 6);
        };
        return ['criterios' => $criterios, 'total' => $total, 'banda' => self::band($total), 'limitados' => $limitados,
            'limites' => $limites, 'maximo' => 36 - count($limitados),
            'fortalezas' => $lista($json['fortalezas'] ?? []), 'debilidades' => $lista($json['debilidades'] ?? []),
            'recomendaciones' => $lista($json['recomendaciones'] ?? [])];
    }

    /**
     * Si se puede descargar el documento final. Con el interruptor «descargafinal» (punto 14, 29-10-2026), siempre que
     * haya una evaluacion vigente: el documento lleva impresos el puntaje y la banda, y los 25 puntos quedan como
     * informacion. Apagado, solo con el diseno aprobado (25 puntos o mas), como antes.
     *
     * @param array|null $evaluacion Evaluacion vigente (current_evaluation()).
     * @param bool $siempre Interruptor «descargafinal».
     * @return bool
     */
    public static function can_download(?array $evaluacion, bool $siempre): bool {
        if ($evaluacion === null) {
            return false;
        }
        return $siempre || !empty($evaluacion['banda']['aprobado']);
    }

    /**
     * Items del checklist de la validacion de la ficha que no quedaron «cumple», para la evaluacion de la etapa 8.
     * Funcion pura.
     *
     * @param array $validacion Informe de la validacion (['items' => [id => ['estado', 'observacion']]]).
     * @return string
     */
    public static function pending_observations(array $validacion): string {
        $lineas = [];
        foreach (exante::checklist() as $n => $seccion) {
            foreach ($seccion['items'] as $id => $texto) {
                $item = $validacion['items'][$id] ?? null;
                if (!$item || ($item['estado'] ?? '') === exante::CUMPLE) {
                    continue;
                }
                $lineas[] = '- Sección ' . $n . ' (' . $seccion['titulo'] . '), ' . $id . ' «' . $texto . '»: '
                    . (($item['estado'] ?? '') === exante::NO_CUMPLE ? 'no cumple' : 'cumple parcialmente')
                    . (trim((string) ($item['observacion'] ?? '')) !== '' ? ' — ' . trim((string) $item['observacion']) : '');
            }
        }
        return $lineas
            ? "Registro de observaciones no resueltas de la Etapa 0 (ítems ⚠ o ❌ de la validación vigente de la ficha):\n"
                . implode("\n", $lineas)
            : 'La validación de la ficha (Etapa 0) no dejó observaciones no resueltas.';
    }

    /**
     * Segundos que puede tardar la generacion de una etapa antes de darla por caida.
     *
     * @param int $etapa
     * @return int
     */
    public static function max_seconds(int $etapa): int {
        return $etapa === self::CONSOLIDACION ? self::CONSOLIDACION_MAX : exante::PROCESO_MAX;
    }

    /**
     * Instrucciones de la etapa 7 con el asistente (Prompt Maestro del 30-10-2026): consolidar todos los entregables
     * «en extenso» en un documento unico, sin repetir los campos comunes. Funcion pura.
     *
     * @param string[] $etapas Numero de etapa (1 a 6) => producto aceptado (HTML).
     * @param bool $control Verificacion de consistencia entre etapas (interruptor «controlcambios»).
     * @param bool $consistencia Ampliacion de la verificacion de consistencia (bloque 5, interruptor «reglasconsistencia»).
     * @param bool $produccion Fila «Produccion de materiales» y catalogo de referencia (bloques 7 y 9, interruptor «reglasproduccion»).
     * @param bool $auditoria Si la verificacion de consistencia corre aparte en auditoria independiente (interruptor «auditoriam2»).
     * @return string
     */
    public static function build_consolidation_prompt(array $etapas, bool $control = false, bool $consistencia = false,
            bool $produccion = false, bool $auditoria = false): string {
        $l = [];
        $l[] = 'Eres el ' . exante::ROL . ': un asistente experto en diseño instruccional con enfoque de competencias, '
            . 'especializado en formación de personas adultas en contextos de capacitación laboral.';
        $l[] = '';
        $l[] = 'TAREA: ETAPA 7: CONSOLIDACIÓN. Consolida todos los entregables anteriores "en extenso" en un documento único '
            . 'listo para descargar en Word, sin repetir aquellos campos que puedan ser comunes. El documento tiene estas '
            . 'partes, en este orden: Análisis, Diseño, Secuencia, Materiales, Evaluación, Instrumentos.';
        $l[] = '';
        if ($control && !$auditoria) {
            // Bloque 3 del ajuste del Prompt Maestro del 10-11-2026: antes de «consolidar todos los entregables».
            foreach (self::BLOQUE_CONSISTENCIA as $linea) {
                // Bloque 9 del ajuste del Prompt Maestro del 21-11-2026: fila «Produccion de materiales» en la tabla.
                if ($produccion && strpos($linea, 'Para cada fila indica') === 0) {
                    $l[] = self::BLOQUE_CONSISTENCIA_PRODUCCION;
                }
                $l[] = $linea;
                // Bloque 5 del ajuste del Prompt Maestro del 19-11-2026: despues de «Evidencias e instrumentos».
                if ($consistencia && strpos($linea, '- Evidencias e instrumentos:') === 0) {
                    foreach (self::BLOQUE_CONSISTENCIA_EXTRA as $extra) {
                        $l[] = $extra;
                    }
                }
            }
            $l[] = self::CONSISTENCIA_PLATAFORMA;
            $l[] = 'Para la fila «Evidencias e instrumentos», compara criterio por criterio el instrumento que asigna la '
                . 'Etapa 5 con el que desarrolla la Etapa 6 (nombre y tipo: lista de cotejo, escala de apreciación, rúbrica, '
                . 'prueba): si algún criterio no coincide, la fila es «Inconsistente». Una fila no es «Consistente» si en el '
                . 'elemento hay una inconsistencia aceptada.';
            $aceptadas = self::accepted_inconsistencies($etapas);
            if ($produccion) {
                // Catalogo de capacidades del Bloque 7 como referencia para evaluar la fila «Produccion de materiales».
                $l[] = 'REFERENCIA PARA LA FILA «PRODUCCIÓN DE MATERIALES» (solo para comparar la Etapa 4 en la tabla '
                    . 'de consistencia; no modifiques los productos aceptados):';
                foreach (self::BLOQUE_CATALOGO_PRODUCCION as $linea) {
                    $l[] = $linea;
                }
            }
            if ($aceptadas) {
                $l[] = 'INCONSISTENCIAS ACEPTADAS REGISTRADAS EN LAS ETAPAS (la persona facilitadora decidió no actualizar '
                    . 'esas etapas): van en el «Registro de ajustes» del anexo con su estado «Inconsistencia aceptada», y la '
                    . 'fila del elemento que afectan es «Inconsistente», con la mención «inconsistencia aceptada»:';
                foreach ($aceptadas as $a) {
                    $l[] = '- ' . $a;
                }
            }
            $l[] = '';
        }
        $l[] = 'REGLAS:';
        if ($control && $auditoria) {
            $l[] = '- La verificación de consistencia entre etapas y el anexo «' . self::ANEXO_CONSISTENCIA
                . '» los ejecuta la plataforma en una auditoría independiente a continuación: NO los redactes aquí y '
                . 'concéntrate en consolidar fielmente y en extenso las seis etapas sin recortar secciones ni tablas.';
        }
        $l[] = '- "En extenso": conserva todo el contenido de cada etapa: actividades, tiempos, recursos, tablas, materiales, '
            . 'indicadores e instrumentos completos, con todos sus niveles, ítems y criterios. No resumas ni acortes.';
        $l[] = '- Sin repetir campos comunes: los datos que se repiten entre etapas (nombre de la experiencia, aprendizaje '
            . 'esperado, criterios de evaluación, contexto, modalidad, tiempos, participantes) van una sola vez, en una '
            . 'sección inicial de datos generales; en las partes siguientes no los vuelvas a transcribir.';
        $l[] = '- No agregues contenido nuevo ni cambies decisiones de diseño: solo reorganizas lo que la persona aceptó.';
        $l[] = '- Cada parte conserva todas las secciones de su etapa, con sus nombres, incluidas «' . self::SECCION_VACIOS
            . '» y «' . self::SECCION_VERIFICACION . '» de esa etapa, al final de la parte. Solo los datos generales '
            . 'comunes van una vez al comienzo.' . ($control ? ' El «' . self::SECCION_AJUSTES . '» de cada etapa no va en su '
            . 'parte: sus filas pasan al anexo.' : '');
        $l[] = '- No uses citas numéricas ni marcas entre corchetes dentro del texto (como [1] o [2]). Reúne las '
            . 'referencias de las etapas (incluidas las fuentes web) en una sola sección «Referencias consultadas» al '
            . 'final del documento, sin repetirlas.';
        $l[] = '- Diagramas: las tablas de cajas con flechas (→ ← ↓ ↑) de las etapas consérvalas como bloques de código '
            . 'con la etiqueta «diagrama» (una línea por caja, «Título / detalle»; «modo: ciclo» si se repiten en '
            . 'círculo; «titulo: ...» opcional), sin dibujarlas nunca con caracteres.';
        $l[] = self::REGLA_FORMULAS . ' Si una etapa trae una fórmula en LaTeX, pásala a texto.';
        $l[] = '- Transcribe el aprendizaje esperado y los criterios de evaluación tal como están.';
        $l[] = exante::NO_PEDAGOGIA;
        $l[] = '- Usa terminología de formación por competencias y lenguaje inclusivo con perspectiva de género.';
        $l[] = '- Escribe todo el producto en español de Chile, también las tablas, los vacíos y la verificación: nunca en '
            . 'inglés ni en otro idioma.';
        $l[] = '';
        $l[] = guardrails::injection_clause();
        $l[] = '';
        $l[] = 'FORMATO DE RESPUESTA: el documento completo en Markdown (títulos con #, listas y tablas Markdown), sin texto '
            . 'antes ni después del documento.';
        foreach ($etapas as $n => $html) {
            $l[] = '';
            $l[] = 'PRODUCTO ACEPTADO DE LA ETAPA ' . $n . ' (' . (self::etapas()[$n]['titulo'] ?? '') . '):';
            $l[] = guardrails::isolate_user_content((string) $html);
        }
        return implode("\n", $l);
    }

    /**
     * Instrucciones para la auditoria independiente de consistencia entre etapas (Etapa 7, interruptor «auditoriam2»):
     * una llamada dedicada y corta que recibe las 6 etapas aceptadas, los datos clave de la ficha y los hallazgos
     * objetivos ya calculados por el codigo PHP, y devuelve unicamente el anexo de verificacion de consistencia y
     * registro de ajustes. Funcion pura.
     *
     * @param string[] $etapas Numero de etapa (1 a 6) => producto aceptado (HTML o texto).
     * @param array $ficha Datos limpios de la ficha validada (participantes, tiempo_total, tiempo_experiencia).
     * @param bool $consistencia Incluye las 5 filas del bloque 5 (interruptor «reglasconsistencia»).
     * @param bool $produccion Incluye la fila «Produccion de materiales» y el catalogo (interruptor «reglasproduccion»).
     * @param string[] $hallazgosPhp Hallazgos objetivos verificados por el codigo PHP (design_audit_facts()).
     * @return string
     */
    public static function build_audit_prompt(array $etapas, array $ficha = [], bool $consistencia = true,
            bool $produccion = true, array $hallazgosPhp = []): string {
        $l = [];
        $l[] = 'Eres un revisor técnico independiente de diseño instruccional con enfoque de competencias (modo auditoría '
            . 'del ' . exante::ROL . '). No redactaste este diseño: tu única tarea es auditar las seis etapas aceptadas '
            . 'con rigor verificable y entregar únicamente el anexo «' . self::ANEXO_CONSISTENCIA . '».';
        $l[] = '';
        $datosFicha = [];
        if (!empty($ficha['participantes'])) {
            $datosFicha[] = 'Participantes declarados: ' . trim((string) $ficha['participantes']);
        }
        if (!empty($ficha['tiempo_experiencia'])) {
            $datosFicha[] = 'Tiempo asignado a la experiencia (2.3): ' . trim((string) $ficha['tiempo_experiencia']) . ' min';
        }
        if (!empty($ficha['tiempo_total'])) {
            $datosFicha[] = 'Tiempo total disponible (2.2): ' . trim((string) $ficha['tiempo_total']) . ' min';
        }
        if ($datosFicha) {
            $l[] = 'DATOS DE REFERENCIA DE LA FICHA VALIDADA (ETAPA 0): ' . implode(' | ', $datosFicha) . '.';
            $l[] = '';
        }
        foreach (self::BLOQUE_CONSISTENCIA as $linea) {
            if ($produccion && strpos($linea, 'Para cada fila indica') === 0) {
                $l[] = self::BLOQUE_CONSISTENCIA_PRODUCCION;
            }
            $l[] = $linea;
            if ($consistencia && strpos($linea, '- Evidencias e instrumentos:') === 0) {
                foreach (self::BLOQUE_CONSISTENCIA_EXTRA as $extra) {
                    $l[] = $extra;
                }
            }
        }
        $l[] = '';
        $l[] = 'REGLAS DE AUDITORÍA OBLIGATORIAS (NO CONFÍES EN LAS VERIFICACIONES PROPIAS DE CADA ETAPA):';
        $l[] = '- Cálculo ascendente (Regla 14): recalcula desde las tablas todas las sumas de tiempos por fase y total, '
            . 'puntajes máximos de instrumentos, ponderaciones (deben sumar 100 %) y equivalencia de umbrales. No copies '
            . 'sumas declaradas en el texto sin recalcularlas.';
        $l[] = '- Quién aplica cada instrumento (Regla 15): verifica que cada instrumento —y cada parte o estación '
            . 'evaluada— tenga una persona concreta que lo aplique en ese momento. «Acompañar» o «supervisar» no cuenta '
            . 'como aplicar un instrumento: si una estación evaluada solo tiene acompañamiento sin aplicador, marca '
            . '«Inconsistente» en «Agrupamientos y observación» y en «Registro de instrumentos».';
        $l[] = '- Actividades con varias tareas (Regla 16): verifica que las actividades compuestas (traslado, visita, '
            . 'regreso, prueba) desglosen el tiempo de cada tarea y no superen la duración declarada.';
        $l[] = '- Una fila por aplicación (Regla 17): si un instrumento se aplica en dos momentos distintos en la misma '
            . 'fila del registro de la Etapa 5, marca «Inconsistente» en «Registro de instrumentos».';
        $l[] = '- Fuente única de materiales e instrumentos (Regla 1 ampliada): si la Etapa 3 crea un catálogo propio de '
            . 'materiales o incluye pautas/listas/rúbricas/pruebas (formativas o sumativas) como materiales, o si la '
            . 'Etapa 4 mete instrumentos en la lista de producción o usa «Markdown» / «tabla Markdown» como formato de '
            . 'salida, marca «Inconsistente» en «Producción de materiales».';
        $l[] = '- Evidencias e instrumentos: compara criterio por criterio el instrumento asignado en la Etapa 5 con el '
            . 'desarrollado en la Etapa 6 (nombre, tipo, puntaje, umbral y formato).';
        $l[] = '- Una fila nunca puede ser «Consistente» si tiene un hallazgo verificado por el código o una '
            . 'inconsistencia aceptada.';
        $l[] = '';
        if ($hallazgosPhp) {
            $l[] = 'HALLAZGOS VERIFICADOS POR CÓDIGO DE LA PLATAFORMA (HECHOS OBJETIVOS — DEBES MARCAR «Inconsistente» '
                . 'LA FILA CORRESPONDIENTE Y DETALLAR LA CORRECCIÓN; NO PUEDES DECLARARLA «Consistente»):';
            foreach ($hallazgosPhp as $h) {
                $l[] = '- ' . $h;
            }
            $l[] = '';
        }
        $aceptadas = self::accepted_inconsistencies($etapas);
        if ($aceptadas) {
            $l[] = 'INCONSISTENCIAS ACEPTADAS REGISTRADAS EN LAS ETAPAS (van en «## Registro de ajustes» con estado '
                . '«Inconsistencia aceptada» y la fila afectada va «Inconsistente» con la mención «inconsistencia aceptada»):';
            foreach ($aceptadas as $a) {
                $l[] = '- ' . $a;
            }
            $l[] = '';
        }
        if ($produccion) {
            $l[] = 'REFERENCIA PARA LA FILA «PRODUCCIÓN DE MATERIALES»:';
            foreach (self::BLOQUE_CATALOGO_PRODUCCION as $linea) {
                $l[] = $linea;
            }
            $l[] = '';
        }
        $l[] = guardrails::injection_clause();
        $l[] = '';
        $l[] = 'FORMATO DE RESPUESTA: entrega ÚNICAMENTE el anexo en Markdown (sin consolidar ni reescribir el diseño, '
            . 'sin saludos ni explicaciones previas), empezando exactamente con:';
        $l[] = '# ' . self::ANEXO_CONSISTENCIA;
        $l[] = '## ' . self::SECCION_CONSISTENCIA;
        $l[] = '(tabla Markdown con las columnas «Elemento», «Resultado», «Etapas y diferencia» y «Corrección propuesta», '
            . 'con una fila por cada elemento de la lista; en «Resultado» escribe únicamente «Consistente» o «Inconsistente»)';
        $l[] = '## ' . self::SECCION_AJUSTES;
        $l[] = '(tabla con las filas reunidas de los registros de ajustes de las etapas; si ninguna etapa trae registro, '
            . 'indícalo en una línea).';
        $l[] = exante::NO_PEDAGOGIA;
        $l[] = 'Escribe todo en español de Chile.';
        foreach ($etapas as $n => $html) {
            $l[] = '';
            $l[] = 'PRODUCTO ACEPTADO DE LA ETAPA ' . $n . ' (' . (self::etapas()[$n]['titulo'] ?? '') . '):';
            $l[] = guardrails::isolate_user_content((string) $html);
        }
        return implode("\n", $l);
    }

    /**
     * Si el documento consolidado incluye la tabla del anexo «Verificación de consistencia entre etapas» con filas.
     * Funcion pura.
     *
     * @param string $html
     * @return bool
     */
    public static function has_consistency_annex(string $html): bool {
        return count(self::section_rows($html, '/verificacion de consistencia/')) > 0;
    }

    /**
     * Si se puede confirmar la consolidacion (Etapa 7) para habilitar la Etapa 8: con el requisito de anexo encendido,
     * exige que el documento consolidado incluya la tabla de verificacion de consistencia con filas. Funcion pura.
     *
     * @param string $html Documento consolidado mostrado (normalizado).
     * @param bool $exigeanexo True cuando estan encendidos controlcambios y auditoriam2.
     * @return bool
     */
    public static function can_confirm_consolidation(string $html, bool $exigeanexo): bool {
        return !$exigeanexo || self::has_consistency_annex($html);
    }

    /**
     * Reemplaza un anexo previo de verificacion de consistencia (o lo agrega al final si no existia). Funcion pura.
     *
     * @param string $html Documento consolidado (HTML).
     * @param string $anexoHtml Anexo generado por la auditoria independiente (HTML).
     * @return string
     */
    public static function replace_consistency_annex(string $html, string $anexoHtml): string {
        $base = $html;
        if (preg_match_all('#<h([1-3])\b[^>]*>(.*?)</h\1>#isu', $html, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($m as $h) {
                $titulo = self::plain_lower(html_entity_decode(strip_tags($h[2][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (preg_match('/verificacion de consistencia/', $titulo)) {
                    $base = rtrim(substr($html, 0, $h[0][1]));
                    break;
                }
            }
        }
        return trim($base) . "\n" . trim($anexoHtml);
    }

    /**
     * Si la consolidacion del asistente esta completa: no se corto por largo y conserva una parte razonable del texto de
     * las etapas (quitar lo repetido acorta, resumir acorta mucho mas). Funcion pura.
     *
     * @param string $consolidado Texto devuelto por el modelo.
     * @param string[] $etapas Productos aceptados (HTML).
     * @param string|null $fin finishReason del modelo.
     * @return bool
     */
    public static function consolidation_complete(string $consolidado, array $etapas, ?string $fin): bool {
        if ($fin !== null && strtoupper($fin) === 'MAX_TOKENS') {
            return false;
        }
        $largo = static function(string $t): int {
            return \core_text::strlen(trim(preg_replace('/\s+/u', ' ', strip_tags($t))));
        };
        $original = 0;
        foreach ($etapas as $html) {
            $original += $largo((string) $html);
        }
        return $original === 0 || $largo($consolidado) >= self::CONSOLIDACION_MIN * $original;
    }

    /**
     * Fuentes web que uso el modelo, como lista al final de la etapa (para que la persona las revise). Funcion pura.
     *
     * @param array $fuentes [['title' => string, 'uri' => string], ...] (gemini_client::extract_sources()).
     * @return string Markdown; '' sin fuentes.
     */
    public static function sources_markdown(array $fuentes): string {
        $lineas = [];
        foreach (array_slice($fuentes, 0, 8) as $f) {
            $uri = (string) ($f['uri'] ?? '');
            if (!preg_match('#^https?://#i', $uri)) {
                continue;
            }
            $titulo = trim(str_replace(['[', ']'], '', (string) ($f['title'] ?? ''))) ?: $uri;
            $lineas[] = '- [' . $titulo . '](' . $uri . ')';
        }
        return $lineas ? "\n\n**Fuentes web consultadas para la especialidad técnica:**\n\n" . implode("\n", $lineas) : '';
    }

    /**
     * Un puntaje 4 necesita la evidencia del diseno citada en la justificacion; sin ella baja a 3.
     *
     * @param int $puntaje
     * @param string $justificacion
     * @return int
     */
    public static function supported_score(int $puntaje, string $justificacion): int {
        return $puntaje === 4 && \core_text::strlen(trim($justificacion)) < self::EVIDENCIA_MIN ? 3 : $puntaje;
    }

    /**
     * La evaluacion como HTML (para la pagina y para el anexo del documento final).
     *
     * @param array $informe Resultado de parse_eval().
     * @return string
     */
    public static function evaluation_html(array $informe): string {
        $e = static function($t) {
            return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
        };
        // Limites por observaciones no resueltas (desde v1.20.14 con su origen; las evaluaciones anteriores no lo traen).
        $limites = (array) ($informe['limites'] ?? array_fill_keys((array) ($informe['limitados'] ?? []), []));
        $html = '<table class="table table-sm align-middle"><thead><tr><th>Criterio</th><th>Puntaje</th>'
            . '<th>Nivel</th><th>Justificación</th></tr></thead><tbody>';
        foreach (self::rubrica() as $id => $c) {
            $p = (int) ($informe['criterios'][$id]['puntaje'] ?? 0);
            $limite = isset($limites[$id]) ? '<br><em>Puntaje máximo 3 por observaciones no resueltas'
                . ($limites[$id] ? ': ' . $e(self::limit_origins((array) $limites[$id])) : '') . '.</em>' : '';
            $nivel = (string) ($c['niveles'][$p] ?? '');
            if (!isset($limites[$id])) {
                $nivel = (string) preg_replace('/\s*O bien: cumple el nivel 4.*$/su', '', $nivel);
            }
            $puntaje = isset($limites[$id]) ? ($p === 3 ? '3 (limitado)' : $p . ' / 4 (máx. 3)') : $p . ' / 4';
            $html .= '<tr><td>' . $e(substr($id, 1) . '. ' . $c['nombre']) . '</td><td>' . $puntaje
                . '</td><td>' . $e($nivel) . '</td><td>'
                . $e($informe['criterios'][$id]['justificacion'] ?? '') . $limite . '</td></tr>';
        }
        $html .= '<tr><th>Puntaje total</th><th>' . (int) $informe['total'] . ' / 36</th><th colspan="2">'
            . $e($informe['banda']['texto']) . '</th></tr>';
        if ($limites) {
            $html .= '<tr><th>Puntaje máximo alcanzable</th><th>' . (36 - count($limites)) . ' / 36</th><th colspan="2">'
                . count($limites) . ' criterio(s) limitado(s) por observaciones no resueltas del diagnóstico</th></tr>';
        }
        $html .= '</tbody></table>';
        foreach (['fortalezas' => 'Fortalezas', 'debilidades' => 'Debilidades', 'recomendaciones' => 'Recomendaciones']
                as $clave => $titulo) {
            if (!empty($informe[$clave])) {
                $html .= '<h3>' . $titulo . '</h3><ul>';
                foreach ($informe[$clave] as $t) {
                    $html .= '<li>' . $e($t) . '</li>';
                }
                $html .= '</ul>';
            }
        }
        return $html;
    }

    /**
     * Si la persona puede abrir una etapa: necesita la Fase 1 aprobada y todas las anteriores aceptadas.
     *
     * @param int $etapa
     * @param bool $fase1 Ficha validada con Aprobado o Aprobado con observaciones, vigente.
     * @param int[] $aceptadas Numeros de etapa aceptados.
     * @return bool
     */
    public static function can_open(int $etapa, bool $fase1, array $aceptadas): bool {
        if (!$fase1 || !isset(self::todas()[$etapa])) {
            return false;
        }
        for ($i = 1; $i < $etapa; $i++) {
            if (!in_array($i, $aceptadas, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Etapa en la que corresponde trabajar: la primera no aceptada (o la ultima si todas lo estan).
     *
     * @param int[] $aceptadas
     * @return int
     */
    public static function current_stage(array $aceptadas): int {
        foreach (array_keys(self::todas()) as $n) {
            if (!in_array($n, $aceptadas, true)) {
                return $n;
            }
        }
        return max(array_keys(self::todas()));
    }

    /**
     * Etapa que abre la pagina de la Fase 2 cuando no se pide una: la primera sin aceptar de la Fase 2, o la etapa 1
     * si las seis estan aceptadas, para recorrer el diseno. Antes caia en la etapa 8 y, con el modulo terminado, no
     * habia como volver a las etapas (Interfases, 26-10-2026). La Fase 3 se abre con su propio enlace (etapa 7).
     *
     * @param int[] $aceptadas
     * @return int
     */
    public static function entry_stage(array $aceptadas): int {
        foreach (array_keys(self::etapas()) as $n) {
            if (!in_array($n, $aceptadas, true)) {
                return $n;
            }
        }
        return 1;
    }

    /**
     * Instrucciones al modelo para producir (o ajustar) el producto de una etapa.
     *
     * @param int $etapa
     * @param string $ficha Ficha como texto (exante::to_text).
     * @param array $informe Informe de la validacion de la Fase 1 (debilidades y sugerencias).
     * @param string[] $previas Numero de etapa => texto del producto aceptado.
     * @param string[] $adjuntos Etiquetas de los documentos complementarios que se adjuntan.
     * @param string $actual Producto actual de la etapa, si se piden ajustes.
     * @param string $ajustes Ajustes que pide la persona.
     * @param bool $conlibreria
     * @param bool $conbusqueda
     * @param bool $control Control de cambios entre etapas (interruptor «controlcambios»).
     * @param string[] $posteriores Numero de etapa => texto de las etapas siguientes ya aceptadas (para el control).
     * @param string $hoy Fecha de hoy, para el registro de ajustes.
     * @param bool $consistencia Reglas de consistencia del diseno (bloques 4 y 6, interruptor «reglasconsistencia»).
    /**
     * Reglas de consistencia y auditoria aplicables a una etapa concreta (prompt desfragmentado, interruptor «auditoriam2»):
     * extrae textualmente de BLOQUE_REGLAS_CONSISTENCIA solo las reglas pertinentes para esa etapa e integra las reglas
     * 14 a 17, la ampliacion de la regla 1 y los formatos de salida cerrados. Funcion pura.
     *
     * @param int $etapa 1 a 6.
     * @param bool $consistencia Interruptor «reglasconsistencia».
     * @param bool $produccion Interruptor «reglasproduccion».
     * @return string[]
     */
    public static function rules_for_stage(int $etapa, bool $consistencia, bool $produccion): array {
        $porNumero = [];
        $actual = 0;
        foreach (self::BLOQUE_REGLAS_CONSISTENCIA as $linea) {
            if (preg_match('/^REGLA\s+(\d+)\./u', $linea, $m)) {
                $actual = (int) $m[1];
                $porNumero[$actual] = [$linea];
            } else if ($actual > 0) {
                $porNumero[$actual][] = $linea;
            }
        }
        $mapa = [
            1 => [8, 9],
            // La Etapa 2 define las fases y su tiempo (regla 1): tambien verifica y calcula esos tiempos.
            2 => [1, 4, 14, 6, 7, 8, 9],
            3 => [1, 4, 14, 16, 5, 6, 7, 8, 9],
            4 => [1, 'formatos', 8, 9],
            5 => [1, 2, 15, 17, 3, 4, 14, 5, 7, 8, 9],
            6 => [1, 2, 15, 3, 4, 14, 5, 7, 8, 9, 10],
        ];
        $salida = ['REGLAS DE CONSISTENCIA APLICABLES A LA ETAPA ' . $etapa . ':'];
        foreach ($mapa[$etapa] ?? [8, 9] as $clave) {
            if ($clave === 14) {
                foreach (self::REGLA_14_CALCULO_ASCENDENTE as $ln) {
                    $salida[] = $ln;
                }
            } else if ($clave === 15) {
                foreach (self::REGLA_15_QUIEN_APLICA as $ln) {
                    $salida[] = $ln;
                }
            } else if ($clave === 16) {
                foreach (self::REGLA_16_VARIAS_TAREAS as $ln) {
                    $salida[] = $ln;
                }
            } else if ($clave === 17) {
                foreach (self::REGLA_17_UNA_FILA_POR_APLICACION as $ln) {
                    $salida[] = $ln;
                }
            } else if ($clave === 'formatos') {
                if ($produccion) {
                    $salida[] = self::REGLA_FORMATOS_CERRADOS;
                }
            } else if ($consistencia && isset($porNumero[$clave])) {
                foreach ($porNumero[$clave] as $ln) {
                    $salida[] = $ln;
                }
                if ($clave === 1) {
                    $salida[] = self::REGLA_1_AMPLIACION_MATERIALES;
                }
            } else if ($clave === 1) {
                $salida[] = 'REGLA 1. FUENTE ÚNICA DE CADA ELEMENTO';
                $salida[] = self::REGLA_1_AMPLIACION_MATERIALES;
            }
        }
        return count($salida) > 1 ? $salida : [];
    }

    /**
     * @param bool $produccion Catalogo y reglas de materiales producibles con IA en la Etapa 4 (bloques 7 a 9, interruptor «reglasproduccion»).
     * @param bool $auditoria Reglas 14 a 17 y prompt desfragmentado por etapa (interruptor «auditoriam2»).
     * @return string
     */
    public static function build_prompt(int $etapa, string $ficha, array $informe, array $previas, array $adjuntos,
            string $actual = '', string $ajustes = '', bool $conlibreria = false, bool $conbusqueda = false,
            bool $control = false, array $posteriores = [], string $hoy = '', bool $consistencia = false,
            bool $produccion = false, bool $auditoria = false): string {
        $e = self::etapas()[$etapa];
        $l = [];
        $l[] = 'Eres el ' . exante::ROL . ': un asistente experto en diseño instruccional con enfoque de competencias, especializado en formación '
            . 'de personas adultas en contextos de capacitación laboral. Apoyas a personas facilitadoras en el diseño de '
            . 'experiencias de aprendizaje, utilizando como insumo principal y obligatorio una ficha de diagnóstico '
            . '(evaluación ex ante) para trabajo de campo, completada por la persona facilitadora y validada por este '
            . 'asistente en la Etapa 0 mediante el checklist de validación; el plan formativo, como documento de referencia '
            . 'obligatorio, y opcionalmente el perfil ocupacional. Tu comportamiento es estricto, estructurado y orientado a '
            . 'asegurar calidad.';
        $l[] = '';
        $l[] = 'TAREA: ETAPA ' . $etapa . ': ' . \core_text::strtoupper($e['titulo']) . '.';
        if ($produccion && $etapa === 4) {
            // Bloque 8 del ajuste del Prompt Maestro del 21-11-2026: al inicio de la Etapa 4.
            foreach (self::BLOQUE_REGLAS_PRODUCCION as $linea) {
                $l[] = $linea;
            }
            $l[] = '';
        }
        $l[] = 'Desarrolla solo esta etapa; no adelantes las siguientes. Debe incluir obligatoriamente:';
        foreach ($e['incluye'] as $item) {
            $l[] = '- ' . $item;
        }
        foreach ($e['indicaciones'] ?? [] as $indicacion) {
            $l[] = $indicacion;
        }
        if ($e['nota'] !== '') {
            $l[] = $e['nota'];
        }
        $l[] = '';
        $l[] = 'REGLAS DE GOBERNANZA:';
        $l[] = '- Usa como fuente principal la ficha de diagnóstico y el Plan Formativo. ' . exante::documents_rule($adjuntos);
        $l[] = '- No inventes aprendizajes esperados ni criterios de evaluación: transcríbelos tal como están en la ficha.';
        $l[] = '- Utiliza al menos 3 de los criterios de evaluación declarados en el plan formativo para el aprendizaje '
            . 'esperado (los que registra la ficha). Si el plan formativo declara menos de 3, utiliza todos los '
            . 'disponibles, infórmalo como vacío de información y no crees criterios nuevos.';
        $l[] = exante::NO_PEDAGOGIA;
        $l[] = '- Mantén la alineación constructiva con las etapas ya aceptadas y con los criterios de evaluación.';
        $l[] = '- Usa terminología de formación por competencias y lenguaje inclusivo con perspectiva de género.';
        $l[] = '- Escribe todo el producto en español de Chile, también las tablas, los vacíos y la verificación: nunca en '
            . 'inglés ni en otro idioma.';
        $l[] = '- Detecta vacíos de información y repórtalos. Propón soluciones viables sin alterar el plan formativo.';
        $l[] = '';
        if ($control) {
            // Bloque 1 del ajuste del Prompt Maestro del 10-11-2026, con su forma de cumplirlo en la plataforma.
            foreach (self::BLOQUE_CONTROL_CAMBIOS as $linea) {
                $l[] = $linea;
            }
            $l[] = self::CONTROL_CAMBIOS_PLATAFORMA . ($hoy !== '' ? ' Fecha de hoy: ' . $hoy . '.' : '');
            $l[] = '';
        }
        if ($auditoria) {
            // Prompt desfragmentado por etapa (23-11-2026): solo las reglas aplicables a esta etapa + Reglas 14 a 17.
            $reglasEtapa = self::rules_for_stage($etapa, $consistencia, $produccion);
            if ($reglasEtapa) {
                foreach ($reglasEtapa as $linea) {
                    $l[] = $linea;
                }
                $l[] = '';
            }
        } else if ($consistencia) {
            // Bloque 4 del ajuste del Prompt Maestro del 19-11-2026: reglas de consistencia del diseno (genericas).
            foreach (self::BLOQUE_REGLAS_CONSISTENCIA as $linea) {
                $l[] = $linea;
            }
            $l[] = '';
        }
        if ($produccion && in_array($etapa, [3, 4], true)) {
            // Bloque 7 del ajuste del Prompt Maestro del 21-11-2026: nueva seccion a continuacion de «REGLAS DE CONSISTENCIA DEL DISEÑO».
            // Tambien en la Etapa 3, que propone los recursos de cada actividad antes de la Etapa 4: asi no propone
            // materiales que luego no se pueden producir (en el Prompt Maestro, un solo texto, el catalogo lo ven todas).
            foreach (self::BLOQUE_CATALOGO_PRODUCCION as $linea) {
                $l[] = $linea;
            }
            $l[] = '';
        }
        if ($produccion && in_array($etapa, [3, 5, 6], true) && (isset($previas[4]) || isset($posteriores[4]))) {
            $l[] = self::CODIGOS_MATERIALES_PLATAFORMA;
            $l[] = '';
        }
        foreach (exante::knowledge_rules($conlibreria, $conbusqueda) as $regla) {
            $l[] = $regla;
        }
        $l[] = '- Considera el resultado de la validación de la ficha (Etapa 0) y su registro de observaciones no '
            . 'resueltas: incorpóralas como restricciones o prioridades de diseño.';
        $l[] = '- No uses citas numéricas ni marcas entre corchetes dentro del texto (como [1] o [2]). Al final del '
            . 'producto, después de la «Verificación de calidad de la etapa», agrega la sección «### Referencias '
            . 'consultadas» con una lista de viñetas de los documentos que usaste (de la librería de conocimientos, el '
            . 'Plan Formativo o el Perfil Ocupacional), por su nombre. Si no usaste ninguno aparte de la ficha, escribe '
            . '«Sin referencias adicionales a la ficha». Las fuentes web las agrega la plataforma.';
        $l[] = '- Diagramas: nunca dibujes cajas, líneas o flechas con caracteres (│ ─ ┌ ▼ →) ni texto alineado con '
            . 'espacios, porque se desarman al descargar el Word. Si un diagrama ayuda (secuencia de pasos, estaciones, '
            . 'rotación de subgrupos), entrégalo en un bloque de código con la etiqueta «diagrama»: una línea por caja, '
            . 'con «Título / detalle» (el detalle es opcional); puedes agregar una línea «titulo: ...» y la línea '
            . '«modo: ciclo» si los pasos se repiten en círculo (por ejemplo, la rotación de subgrupos); sin ella es una '
            . 'secuencia. Entre 2 y 12 cajas, sin líneas de flechas ni conectores entre ellas (│, ▼, ↓). La plataforma lo '
            . 'dibuja como tarjetas con flechas. Ejemplo:';
        $l[] = '```diagrama';
        $l[] = 'modo: ciclo';
        $l[] = 'titulo: Rotación de subgrupos por estaciones';
        $l[] = 'Estación A / Aseo personal (criterios 1.5 y 1.6)';
        $l[] = 'Estación B / Vestimenta y calzado (criterio 1.7)';
        $l[] = 'Estación C / Acondicionamiento de la cama (criterio 1.8)';
        $l[] = 'Estación D / Registro simplificado (criterio 1.9)';
        $l[] = '```';
        $l[] = self::REGLA_FORMULAS;
        $l[] = '';
        $l[] = 'CONTROL DE CALIDAD: antes de cerrar la etapa verifica la coherencia con la ficha, la alineación con el '
            . 'aprendizaje esperado y los criterios de evaluación, la aplicabilidad real y la consistencia con el tiempo '
            . 'asignado a la experiencia (2.3) y con el tiempo total disponible (2.2). Termina el documento con una '
            . 'sección breve «Verificación de calidad de la etapa» que diga qué verificaste y, si detectas una '
            . 'inconsistencia, qué ajuste se necesita.';
        if ($control) {
            // Bloque 2: despues de «detectar inconsistencias».
            foreach (self::BLOQUE_CALIDAD_DEPENDENCIA as $linea) {
                $l[] = $linea;
            }
        }
        if ($consistencia) {
            // Bloque 6: al final del control de calidad interno.
            foreach (self::BLOQUE_CALIDAD_CONSISTENCIA as $linea) {
                $l[] = $linea;
            }
        }
        if ($produccion && $etapa === 4) {
            // Bloque 9 (Etapa 4): verificaciones del control de calidad interno de la Etapa 4.
            foreach (self::BLOQUE_CALIDAD_PRODUCCION as $linea) {
                $l[] = $linea;
            }
            if ($auditoria) {
                $l[] = '- Verificar que ningún formato de salida sea «Markdown» o «tabla Markdown» y que la lista de '
                    . 'producción no contenga instrumentos evaluativos (pautas, listas de cotejo, rúbricas o pruebas).';
            }
        }
        if ($auditoria && in_array($etapa, [3, 5, 6], true)) {
            $l[] = '- Recalcular todas las sumas copiando número por número desde las tablas (Regla 14) sin arrastrar '
                . 'totales previos.';
            if ($etapa === 3) {
                $l[] = '- Verificar que no exista catálogo propio de materiales ni instrumentos evaluativos listados como '
                    . 'materiales (Regla 1 ampliada) y que toda actividad con varias tareas desglose sus tiempos (Regla 16).';
            }
            if ($etapa === 5) {
                $l[] = '- Verificar que el registro incluya la columna «Persona que aplica» con un aplicador concreto '
                    . '(acompañar o supervisar no equivale a aplicar; Regla 15) y una fila por cada aplicación (Regla 17).';
            }
        }
        $l[] = '';
        $l[] = 'FORMATO: entrega el producto en Markdown, estructurado, institucional y aplicable, listo para copiar en '
            . 'un documento Word: títulos con ## y ###, listas y tablas Markdown cuando ayuden. Empieza directamente '
            . 'con el título «## Etapa ' . $etapa . ': ' . $e['titulo'] . '». No agregues saludos, preguntas al final '
            . 'ni comentarios fuera del producto: la plataforma ya le pregunta a la persona si quiere ajustes.';
        $l[] = 'ESTRUCTURA OBLIGATORIA (la plataforma revisa que esté todo): cada elemento de la lista «Debe incluir '
            . 'obligatoriamente» aparece con su nombre, como sección «### N. Nombre» cuando es un apartado del producto, '
            . 'o como columna de tabla o rótulo en negrita («**Alcance:** ...») cuando es un dato de cada fase, actividad, '
            . 'material o instrumento. No omitas ninguno. El producto termina siempre con estas tres secciones, en este '
            . 'orden: «### ' . self::SECCION_VACIOS . '» (si no hay vacíos, dilo en una línea), «### '
            . self::SECCION_VERIFICACION . '» y «### ' . self::SECCION_REFERENCIAS . '».';
        $l[] = '';
        $l[] = guardrails::injection_clause();
        $l[] = '';
        $l[] = 'FICHA DE DIAGNÓSTICO VALIDADA:';
        $l[] = guardrails::isolate_user_content($ficha);
        if (!empty($informe['condicion'])) {
            $condiciones = [exante::APROBADO => '✔ Aprobado', exante::OBSERVACIONES => '⚠ Aprobado con observaciones',
                exante::NO_APROBADO => '❌ No aprobado'];
            $l[] = '';
            $l[] = 'RESULTADO DE LA VALIDACIÓN DE LA FICHA (ETAPA 0): ' . ($condiciones[$informe['condicion']] ?? '') . '.';
        }
        if (!empty($informe['items'])) {
            $l[] = self::pending_observations($informe);
        }
        $obs = array_merge($informe['debilidades'] ?? [], $informe['sugerencias'] ?? []);
        if ($obs) {
            $l[] = '';
            $l[] = 'OBSERVACIONES DE LA VALIDACIÓN DE LA FICHA:';
            foreach ($obs as $o) {
                $l[] = '- ' . $o;
            }
        }
        foreach ($previas as $n => $texto) {
            $l[] = '';
            $l[] = 'PRODUCTO ACEPTADO DE LA ETAPA ' . $n . ' (' . self::etapas()[$n]['titulo'] . '):';
            $l[] = guardrails::isolate_user_content(\core_text::substr($texto, 0, self::PREVIA_MAX));
        }
        foreach ($control ? $posteriores : [] as $n => $texto) {
            $l[] = '';
            $l[] = 'PRODUCTO ACEPTADO DE LA ETAPA ' . $n . ' (' . self::etapas()[$n]['titulo'] . '), POSTERIOR A ESTA (solo para '
                . 'el control de cambios: no la desarrolles):';
            $l[] = guardrails::isolate_user_content(\core_text::substr($texto, 0, self::PREVIA_MAX));
        }
        if (trim($ajustes) !== '') {
            $l[] = '';
            $l[] = 'PRODUCTO ACTUAL DE ESTA ETAPA (versión que la persona quiere ajustar):';
            $l[] = guardrails::isolate_user_content(\core_text::substr($actual, 0, self::PREVIA_MAX));
            $l[] = '';
            $l[] = 'AJUSTES QUE PIDE LA PERSONA: aplícalos y entrega el producto completo de la etapa, no solo los cambios. '
                . 'Si un ajuste contradice la ficha, el plan formativo o las reglas, no lo apliques y explícalo en la '
                . 'verificación de calidad.';
            $l[] = guardrails::isolate_user_content($ajustes);
        }
        return implode("\n", $l);
    }

    /**
     * Quita bloques de codigo que envuelvan toda la respuesta (```markdown ... ```).
     *
     * @param string|null $texto
     * @return string
     */
    public static function clean_markdown(?string $texto): string {
        $texto = trim((string) $texto);
        if (preg_match('/^```[a-zA-Z]*\s*\n(.*)\n```$/s', $texto, $m)) {
            $texto = trim($m[1]);
        }
        return self::plain_math(self::strip_citations($texto));
    }

    /**
     * Pasa a texto legible las formulas que el modelo escribe en LaTeX ($$\text{Porcentaje} = (\% \text{Inst. 1} \times
     * 0.20)$$ → «Porcentaje = (% Inst. 1 × 0.20)»): Moodle no las dibuja y quedaban los comandos a la vista. Solo toca
     * lo que va entre $$...$$, \[...\], \(...\) o $...$ con un comando dentro (un precio «$ 5.000» no cambia), y los
     * comandos sueltos conocidos. Funcion pura; sirve para Markdown y para HTML ya guardado.
     *
     * @param string $texto
     * @return string
     */
    public static function plain_math(string $texto): string {
        if (strpos($texto, '\\') === false) {
            return $texto;
        }
        $convertir = static function(string $f): string {
            $f = preg_replace('/\\\\(?:text|mathrm|textbf|mathbf|textit|mathit|operatorname|mbox)\s*\{([^{}]*)\}/u', '$1', $f);
            // \frac{a}{b} → (a ÷ b), de adentro hacia afuera.
            for ($i = 0; $i < 5 && strpos($f, 'frac') !== false; $i++) {
                $f = preg_replace('/\\\\[dt]?frac\s*\{([^{}]*)\}\s*\{([^{}]*)\}/u', '($1 ÷ $2)', $f);
            }
            $f = strtr($f, ['\\times' => '×', '\\cdot' => '·', '\\div' => '÷', '\\leq' => '≤', '\\le' => '≤', '\\geq' => '≥',
                '\\ge' => '≥', '\\neq' => '≠', '\\approx' => '≈', '\\pm' => '±', '\\sum' => 'Σ', '\\to' => '→',
                '\\rightarrow' => '→', '\\%' => '%', '\\left' => '', '\\right' => '', '\\,' => ' ', '\\;' => ' ', '\\:' => ' ',
                '\\!' => '', '\\quad' => ' ', '\\qquad' => ' ', '\\ ' => ' ', '\\{' => '{', '\\}' => '}', '\\_' => '_']);
            $f = preg_replace('/\\\\[a-zA-Z]+/', '', $f);
            $f = str_replace(['{', '}'], '', $f);
            return trim(preg_replace('/[ \t]{2,}/', ' ', $f));
        };
        $texto = preg_replace_callback('/\$\$(.+?)\$\$|\\\\\[(.+?)\\\\\]|\\\\\((.+?)\\\\\)/su', static function(array $m) use ($convertir): string {
            return $convertir($m[1] !== '' ? $m[1] : ($m[2] ?? '') . ($m[3] ?? ''));
        }, $texto);
        $texto = preg_replace_callback('/\$([^$\n]*\\\\[a-zA-Z%][^$\n]*)\$/u', static function(array $m) use ($convertir): string {
            return $convertir($m[1]);
        }, $texto);
        // Comandos sueltos fuera de delimitadores.
        if (preg_match('/\\\\(?:times|frac|text|div|cdot|leq|geq)\b/', $texto)) {
            $texto = preg_replace_callback('/[^\n<>]*\\\\(?:times|frac|text|div|cdot|leq|geq)\b[^\n<>]*/u', static function(array $m) use ($convertir): string {
                return $convertir($m[0]);
            }, $texto);
        }
        return $texto;
    }

    /**
     * Datos de la portada del documento final (documento_final::build()): titulo, ficha y resultado de la evaluacion.
     * Funcion pura.
     *
     * @param array $ficha Respuestas de la ficha (plan, modulo, aprendizaje, tiempo_experiencia).
     * @param string $titulo Nombre de la experiencia.
     * @param array $evaluacion Evaluacion vigente (total, banda).
     * @param string $fecha Fecha de la evaluacion, ya con formato.
     * @return array
     */
    public static function final_cover(array $ficha, string $titulo, array $evaluacion, string $fecha): array {
        $limpio = static function($v): string {
            return trim(rtrim(trim((string) $v), '.'));
        };
        $lineas = [];
        foreach ([['Plan formativo', $limpio($ficha['plan'] ?? '')], ['Módulo', $limpio($ficha['modulo'] ?? '')],
                ['Aprendizaje esperado', \core_text::substr($limpio($ficha['aprendizaje'] ?? ''), 0, 300)]] as $l) {
            if ($l[1] !== '') {
                $lineas[] = $l;
            }
        }
        $minutos = (int) preg_replace('/\D+/', '', (string) ($ficha['tiempo_experiencia'] ?? ''));
        if ($minutos > 0) {
            $lineas[] = ['Duración de la experiencia', $minutos . ' minutos'];
        }
        if (isset($evaluacion['total'])) {
            $lineas[] = ['Validación técnica', (int) $evaluacion['total'] . ' / 36 · ' . (string) ($evaluacion['banda']['texto'] ?? '')
                . ($fecha !== '' ? ' · ' . $fecha : '')];
        }
        return ['etiqueta' => 'Diseño final validado', 'titulo' => $titulo,
            'subtitulo' => 'Diseño de experiencia de aprendizaje por competencias', 'lineas' => $lineas,
            'encabezado' => 'Diseño de experiencia de aprendizaje · Diseño final validado'];
    }

    /**
     * HTML del producto a partir del Markdown del modelo: los diagramas («diagrama») quedan como tablas de cajas con
     * flechas y los dibujos de caracteres, como listas (no se desarman al descargar el Word).
     *
     * @param string $markdown
     * @return string
     */
    public static function product_html(string $markdown): string {
        [$sin, $tablas] = diagrama::extract($markdown);
        return clean_text(diagrama::list_flows(diagrama::ascii_lists(self::structure_pre(diagrama::insert(markdown_to_html($sin), $tablas)))), FORMAT_HTML);
    }

    /**
     * Numero de una version del prompt («M2-F2-etapas-v11» → 11; vacia o desconocida → 0). Funcion pura.
     *
     * @param string|null $version
     * @return int
     */
    public static function prompt_number(?string $version): int {
        return preg_match('/-v(\d+)$/', (string) $version, $m) ? (int) $m[1] : 0;
    }

    /**
     * Raices de las palabras significativas de un elemento («Resultado de la validación (Etapa 0) y observaciones…» →
     * result, valida, observ, resuel): lo anterior a una coma, punto y coma o dos puntos, sin lo que va entre parentesis ni
     * tildes, palabras de cuatro letras o mas, seis primeras letras. Funcion pura.
     *
     * @param string $item
     * @return string[]
     */
    public static function item_stems(string $item): array {
        $nucleo = preg_replace('/\([^)]*\)/u', ' ', preg_split('/[,;:]/u', $item)[0]);
        $vacias = ['para', 'como', 'cada', 'sobre', 'entre', 'segun', 'desde', 'hasta', 'esta', 'este', 'tabla'];
        $raices = [];
        foreach (preg_split('/[^a-z0-9]+/', self::plain_lower($nucleo)) as $w) {
            if (strlen($w) >= 4 && !in_array($w, $vacias, true)) {
                $raices[] = substr($w, 0, 6);
            }
        }
        return array_values(array_unique($raices));
    }

    /**
     * Texto en minusculas y sin tildes, para comparar. Funcion pura.
     *
     * @param string $t
     * @return string
     */
    /**
     * Filas de la primera tabla que sigue a un titulo, como [encabezado en minuscula sin tildes => texto]. Funcion pura.
     *
     * @param string $html
     * @param string $titulo Expresion regular sobre el titulo, en minuscula y sin tildes.
     * @return array[]
     */
    public static function section_rows(string $html, string $titulo): array {
        if (trim($html) === '') {
            return [];
        }
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        $filas = [];
        $dentro = false;
        foreach ((new \DOMXPath($dom))->query('//h1|//h2|//h3|//h4|//h5|//h6|//table') as $el) {
            if ($el->nodeName !== 'table') {
                $dentro = preg_match($titulo, self::plain_lower(trim($el->textContent))) === 1;
                continue;
            }
            if (!$dentro) {
                continue;
            }
            $cabecera = [];
            foreach ($el->getElementsByTagName('tr') as $tr) {
                $celdas = [];
                foreach ($tr->childNodes as $c) {
                    if ($c instanceof \DOMElement && ($c->nodeName === 'td' || $c->nodeName === 'th')) {
                        $celdas[] = trim(preg_replace('/\s+/u', ' ', $c->textContent));
                    }
                }
                if (!$cabecera) {
                    $cabecera = array_map([self::class, 'plain_lower'], $celdas);
                    continue;
                }
                $fila = [];
                foreach ($celdas as $k => $texto) {
                    $fila[$cabecera[$k] ?? (string) $k] = $texto;
                }
                $filas[] = $fila;
            }
            $dentro = false;
        }
        return $filas;
    }

    /**
     * @param array $fila Fila de section_rows().
     * @param string $columna Expresion regular sobre el encabezado.
     * @return string Texto de la primera columna que calza, o ''.
     */
    /**
     * Filas de la lista de produccion de la Etapa 4: la tabla cuyas columnas traen «Código» y «Modo de producción»
     * (bloque 8, registro obligatorio en 4.2), en cualquier parte del producto. Funcion pura.
     *
     * @param string $html
     * @return array[] Cada fila: encabezado normalizado => texto.
     */
    public static function production_rows(string $html): array {
        if (trim($html) === '' || stripos($html, '<table') === false) {
            return [];
        }
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        foreach ($dom->getElementsByTagName('table') as $tabla) {
            $cabecera = [];
            $filas = [];
            foreach ($tabla->getElementsByTagName('tr') as $tr) {
                $celdas = [];
                foreach ($tr->childNodes as $c) {
                    if ($c instanceof \DOMElement && ($c->nodeName === 'td' || $c->nodeName === 'th')) {
                        $celdas[] = trim(preg_replace('/\s+/u', ' ', $c->textContent));
                    }
                }
                if (!$cabecera) {
                    $cabecera = array_map([self::class, 'plain_lower'], $celdas);
                    if (!preg_grep('/^codigo/', $cabecera) || !preg_grep('/modo/', $cabecera)) {
                        continue 2;
                    }
                    continue;
                }
                $fila = [];
                foreach ($celdas as $k => $texto) {
                    $fila[$cabecera[$k] ?? (string) $k] = $texto;
                }
                $filas[] = $fila;
            }
            return $filas;
        }
        return [];
    }

    /**
     * Revision de la lista de produccion contra la regla 11 y el catalogo (bloques 7 y 8): (1) un material que combina
     * partes de distintos modos (varios modos en la celda, o una curaduria junto con otro material), que la regla 11 pide
     * dividir; (2) un material en Modo A con un archivo descargable (PDF, Word, Excel, presentacion), que en el catalogo
     * es Modo B. Visto al probar la Etapa 4 en el VPS del cliente el 21-11-2026: el asistente declaro cumplirlo y no lo
     * hizo. Funcion pura.
     *
     * @param string $html Producto de la Etapa 4.
     * @return string[] Un texto por problema, listo para mostrarlo y para pedirlo como ajuste.
     */
    public static function production_issues(string $html): array {
        $problemas = [];
        foreach (self::production_rows($html) as $fila) {
            $codigo = self::column($fila, '/^codigo/');
            $material = self::column($fila, '/^material/');
            $modo = self::column($fila, '/modo/');
            $formato = self::column($fila, '/formato/');
            if ($codigo === '' && $material === '') {
                continue;
            }
            $nombre = trim($codigo . ' · ' . $material, ' ·');
            // Letras de modo sueltas («Modo A», «A / C»), sin lo que va entre parentesis («(Producción completa con IA)»).
            preg_match_all('/(?<![a-z])[abc](?![a-z])/u', preg_replace('/\([^)]*\)/u', ' ', self::plain_lower($modo)), $m);
            $letras = array_values(array_unique($m[0]));
            $c = in_array('c', $letras, true) || preg_match('/curadur/u', self::plain_lower($modo));
            $nombrebajo = self::plain_lower($material);
            $otro = preg_match('/\b(tarjeta|guia|dossier|plantilla|pauta|manual|cuestionario|infografia|presentacion|planilla|'
                . 'caso|folleto|afiche|bitacora|tablero)/u', preg_replace('/ficha de (curaduria|recomendacion)/u', '', $nombrebajo));
            if (count($letras) > 1 || ($c && preg_match('/curadur|recomendaci|video/u', $nombrebajo) && $otro)) {
                $problemas[] = $nombre . ': combina partes de distintos modos de producción. La regla 11 pide dividirlo en '
                    . 'materiales separados, cada uno con un solo modo (por ejemplo, la curaduría en Modo C y el resto en '
                    . 'Modo A o B), con su propio código.';
                continue;
            }
            $formatobajo = self::plain_lower($formato);
            if (preg_match('/^ins[\s_-]?\d/i', trim($codigo)) || preg_match('/\b(rubrica|lista de cotejo|'
                    . 'escala de apreciacion|pauta de observacion|pauta de evaluacion|'
                    . 'prueba (?:escrita|practica|aplicada|teorica|sumativa|formativa|diagnostica))\b/u', $nombrebajo)) {
                $problemas[] = $nombre . ': es un instrumento de evaluación y no debe incluirse en la lista de '
                    . 'producción de la Etapa 4 (los instrumentos se definen en la Etapa 5 y se desarrollan en la Etapa 6; '
                    . 'Reglas 1 y 11).';
                continue;
            }
            if (empty($letras) && !$c && trim($modo) !== '') {
                $problemas[] = $nombre . ': no declara un modo válido del catálogo (A, B o C) en «' . $modo . '». Los '
                    . 'materiales instruccionales se resuelven siempre en Modo A, B o C; los recursos físicos van en la '
                    . 'lista del entorno formativo (Regla 11).';
                continue;
            }
            if (preg_match('/\bmarkdown\b/u', $formatobajo)) {
                $problemas[] = $nombre . ': el formato de salida («' . $formato . '») no pertenece al catálogo de '
                    . 'capacidades («Markdown» es una forma de escribir el texto, no un formato de uso). Especifica un '
                    . 'formato cerrado del catálogo (texto para copiar y pegar, código HTML, Word/PDF/Excel/presentación '
                    . 'descargable, GIFT/Google Apps Script o ficha de recomendación Modo C).';
            } else if (preg_match('/\b(genially|canva|kahoot|mentimeter|edpuzzle|articulate|storyline|h5p|padlet|prezi|'
                    . 'miro|scorm|nearpod|quizizz)\b/u', $formatobajo . ' ' . $nombrebajo)) {
                $problemas[] = $nombre . ': depende de una herramienta o plataforma externa fuera del catálogo de '
                    . 'capacidades (Regla 11). Propón la alternativa producible equivalente en Modo A, B o C.';
            }
            if ($letras === ['a'] && preg_match('/\b(pdf|word|docx|excel|xlsx|pptx|powerpoint|presentacion|descargable|'
                    . 'archivo)\b/u', $formatobajo)) {
                $problemas[] = $nombre . ': está en Modo A, pero su formato de salida («' . $formato . '») es un archivo '
                    . 'descargable. En el catálogo, el Modo A entrega texto para copiar y pegar o código HTML; un archivo '
                    . 'descargable (Word, PDF, Excel o presentación) corresponde al Modo B.';
            }
        }
        return $problemas;
    }

    /**
     * El producto sin las secciones que describen cambios o diferencias en vez del diseno vigente: «Registro de ajustes»
     * (por ejemplo, «de 4 subgrupos de 5 personas a 5 subgrupos de 4») y el anexo o la tabla de «Verificación de
     * consistencia». Cada seccion va desde su titulo hasta el siguiente titulo del mismo nivel o superior. La revision en
     * codigo de una etapa lee solo lo vigente: visto el 23-11-2026 en la Etapa 3 del cliente, donde el registro de
     * ajustes daba un falso «4 subgrupos de 5 personas». Funcion pura.
     *
     * @param string $html
     * @return string
     */
    public static function without_change_log(string $html): string {
        $partes = preg_split('/(<h[1-6]\b[^>]*>.*?<\/h[1-6]>)/isu', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $salida = '';
        $saltar = 0;
        foreach ($partes as $p) {
            if (preg_match('/^<h([1-6])\b[^>]*>(.*?)<\/h\1>$/isu', $p, $m)) {
                $nivel = (int) $m[1];
                if ($saltar > 0 && $nivel <= $saltar) {
                    $saltar = 0;
                }
                if ($saltar === 0 && preg_match('/registro de ajustes|verificacion de consistencia|anexo: verificacion/u',
                        self::plain_lower(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')))) {
                    $saltar = $nivel;
                }
            }
            if ($saltar === 0) {
                $salida .= $p;
            }
        }
        return $salida;
    }

    /**
     * Revision de tiempos de la Etapa 2, que define las fases y su tiempo (regla 1): suma explicita mal hecha, tablas de
     * fases cuya suma no da su total y fases que en conjunto superan el tiempo asignado a la experiencia (ficha 2.3, o el
     * total 2.2 si falta). Los tiempos se leen de los titulos de fase o momento («… (60 MINUTOS)», sin las subfases) y de
     * las tablas con columna de fase y de tiempo (Reglas 4 y 14). Funcion pura.
     *
     * @param string $html Producto de la Etapa 2.
     * @param int $tiempoExp
     * @param int $tiempoTotal
     * @return string[]
     */
    public static function phase_time_issues(string $html, int $tiempoExp = 0, int $tiempoTotal = 0): array {
        $problemas = self::arithmetic_expression_issues($html);
        $limite = $tiempoExp > 0 ? $tiempoExp : $tiempoTotal;
        $nombreLimite = $tiempoExp > 0 ? 'el tiempo asignado a la experiencia en la ficha (2.3)' : 'el tiempo total disponible en la ficha (2.2)';
        // Titulos de fase o momento con su tiempo.
        $fases = [];
        if (preg_match_all('#<h[1-6]\b[^>]*>(.*?)</h[1-6]>#isu', $html, $hm)) {
            foreach ($hm[1] as $ht) {
                $t = self::plain_lower(html_entity_decode(strip_tags($ht), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (preg_match('/\b(fase|momento)\b/u', $t) && !preg_match('/\bsub\s*-?\s*fase\b/u', $t)
                        && preg_match('/\(?\s*(\d{1,4})\s*min(?:utos?)?\b/u', $t, $mm)) {
                    $fases[] = (int) $mm[1];
                }
            }
        }
        if (count($fases) >= 2 && $limite > 0 && array_sum($fases) > $limite) {
            $problemas[] = 'Tiempos por fase (Reglas 4 y 14): las fases suman ' . array_sum($fases) . ' min ('
                . implode(' + ', $fases) . ') y superan ' . $nombreLimite . ': ' . $limite . ' min.';
        }
        // Tablas de fases con su tiempo.
        foreach (self::parse_tables($html) as $tabla) {
            $cab = $tabla['cabecera'];
            $colFase = null;
            $colTiempo = null;
            foreach ($cab as $i => $h) {
                if ($colFase === null && preg_match('/\b(fase|momento)\b/u', $h)) {
                    $colFase = $i;
                }
                if ($colTiempo === null && preg_match('/\b(tiempo|duracion|minutos?|min)\b/u', $h)) {
                    $colTiempo = $i;
                }
            }
            if ($colFase === null || $colTiempo === null) {
                continue;
            }
            $sumandos = [];
            $declarado = null;
            foreach ($tabla['filas'] as $f) {
                $fase = self::plain_lower($f['celdas'][$colFase] ?? '');
                $min = self::extract_minutes($f['celdas'][$colTiempo] ?? '', true);
                if ($min === null) {
                    continue;
                }
                if (preg_match('/^(sub\s*total|total|suma)\b/u', $fase)) {
                    $declarado = $min;
                } else if (!preg_match('/\bsub\s*-?\s*fase\b/u', $fase)) {
                    $sumandos[] = $min;
                }
            }
            if (count($sumandos) < 2) {
                continue;
            }
            $suma = array_sum($sumandos);
            $etiqueta = $tabla['titulo'] !== '' ? $tabla['titulo'] : 'la tabla de fases';
            if ($declarado !== null && $declarado !== $suma) {
                $problemas[] = 'Tiempos en «' . $etiqueta . '» (Reglas 4 y 14): las fases suman ' . $suma . ' min ('
                    . implode(' + ', $sumandos) . '), pero el total declara ' . $declarado . ' min.';
            }
            if ($limite > 0 && $suma > $limite) {
                $problemas[] = 'Tiempos en «' . $etiqueta . '» (Reglas 4 y 14): las fases suman ' . $suma . ' min y superan '
                    . $nombreLimite . ': ' . $limite . ' min.';
            }
        }
        return array_values(array_unique($problemas));
    }

    /**
     * Extrae todas las tablas HTML de un producto con el titulo de seccion mas cercano que las precede, sus encabezados
     * normalizados y sus filas. Funcion pura.
     *
     * @param string $html
     * @return array[] [['titulo' => string, 'cabecera' => string[], 'filas' => array[]], ...]
     */
    public static function parse_tables(string $html): array {
        if (trim($html) === '' || stripos($html, '<table') === false) {
            return [];
        }
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return [];
        }
        $tablas = [];
        $tituloActual = '';
        $recorrer = static function(\DOMNode $nodo) use (&$recorrer, &$tablas, &$tituloActual): void {
            foreach ($nodo->childNodes as $el) {
                if (!$el instanceof \DOMElement) {
                    continue;
                }
                $tag = strtolower($el->nodeName);
                if (preg_match('/^h[1-6]$/', $tag)) {
                    $tituloActual = trim(preg_replace('/\s+/u', ' ', $el->textContent));
                    continue;
                }
                if ($tag === 'table') {
                    $cabecera = [];
                    $filas = [];
                    foreach ($el->getElementsByTagName('tr') as $tr) {
                        $padre = $tr->parentNode;
                        if ($padre !== $el && ($padre === null || $padre->parentNode !== $el)) {
                            continue;
                        }
                        $celdas = [];
                        foreach ($tr->childNodes as $c) {
                            if ($c instanceof \DOMElement && ($c->nodeName === 'td' || $c->nodeName === 'th')) {
                                $celdas[] = trim(preg_replace('/\s+/u', ' ', $c->textContent));
                            }
                        }
                        if (!$celdas) {
                            continue;
                        }
                        if (!$cabecera) {
                            $cabecera = array_map([self::class, 'plain_lower'], $celdas);
                            continue;
                        }
                        $mapa = [];
                        foreach ($celdas as $k => $texto) {
                            $mapa[$cabecera[$k] ?? (string) $k] = $texto;
                        }
                        $filas[] = ['map' => $mapa, 'celdas' => $celdas];
                    }
                    if ($cabecera && $filas) {
                        $tablas[] = ['titulo' => $tituloActual, 'cabecera' => $cabecera, 'filas' => $filas];
                    }
                    continue;
                }
                if ($el->hasChildNodes()) {
                    $recorrer($el);
                }
            }
        };
        $recorrer($body);
        return $tablas;
    }

    /**
     * Convierte un numero escrito en formato chileno o internacional («1.200», «0,20», «330») a float. Funcion pura.
     *
     * @param string $raw
     * @return float|null
     */
    private static function parse_number(string $raw): ?float {
        $t = trim($raw);
        if ($t === '') {
            return null;
        }
        if (preg_match('/^\d{1,3}(?:\.\d{3})+(?:,\d+)?$/', $t)) {
            $t = str_replace('.', '', $t);
        }
        $t = str_replace(',', '.', $t);
        return is_numeric($t) ? (float) $t : null;
    }

    /**
     * Extrae la duracion en minutos de una celda («30 min», «150», «1 h 30 min», «2 horas»). Devuelve null si es un
     * rango ambiguo («20-30 min», «09:00-09:30») o no contiene una duracion clara. Funcion pura.
     *
     * @param string $texto
     * @param bool $permiteEnteroSuelto Si un numero sin unidad se interpreta como minutos (columna «Minutos»/«Tiempo»).
     * @return int|null
     */
    private static function extract_minutes(string $texto, bool $permiteEnteroSuelto = true): ?int {
        $t = self::plain_lower(trim(preg_replace('/\s+/u', ' ', $texto)));
        if ($t === '' || preg_match('/\d{1,2}\s*:\s*\d{2}/', $t)) {
            return null;
        }
        if (preg_match('/\d+\s*(?:-|–|—|a)\s*\d+\s*(?:min|h)/u', $t)) {
            return null;
        }
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(?:h|hr|hrs|hora|horas)\s*(?:y\s*)?(\d+)\s*(?:min|mins|minuto|minutos)\b/u', $t, $m)) {
            $h = self::parse_number($m[1]);
            return $h !== null ? (int) round($h * 60 + (int) $m[2]) : null;
        }
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(?:h|hr|hrs|hora|horas)\b/u', $t, $m)) {
            $h = self::parse_number($m[1]);
            return $h !== null ? (int) round($h * 60) : null;
        }
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(?:min|mins|minuto|minutos)\b/u', $t, $m)) {
            $min = self::parse_number($m[1]);
            return $min !== null ? (int) round($min) : null;
        }
        if ($permiteEnteroSuelto && preg_match('/^(\d{1,4})$/', $t, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Detecta sumas explicitas mal calculadas en el texto o tablas (por ejemplo «30 + 150 + 150 + 30 = 330», cuya suma
     * real es 360; Reglas 4 y 14). Ignora expresiones con multiplicacion/division o unidades mezcladas para evitar falsos
     * positivos. Funcion pura.
     *
     * @param string $html
     * @return string[]
     */
    public static function arithmetic_expression_issues(string $html): array {
        $texto = html_entity_decode(strip_tags(preg_replace('#</?(?:p|div|li|tr|td|th|br|h[1-6])\b[^>]*>#i', "\n", $html)),
            ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $problemas = [];
        $vistos = [];
        $patron = '/(?<![\d.,×*÷\/(-])(\d+(?:[.,]\d+)?(?:\s*(?:min(?:utos?)?|pts?|puntos?|%|h))?'
            . '(?:\s*\+\s*\d+(?:[.,]\d+)?(?:\s*(?:min(?:utos?)?|pts?|puntos?|%|h))?)+)\s*=\s*'
            . '(\d+(?:[.,]\d+)?)\s*(min(?:utos?)?|pts?|puntos?|%|h)?(?![\d×*÷\/])/iu';
        foreach (preg_split('/\R/u', $texto) as $linea) {
            if (strpos($linea, '+') === false || strpos($linea, '=') === false) {
                continue;
            }
            if (!preg_match_all($patron, $linea, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches as $m) {
                $offset = $m[0][1];
                $antes = substr($linea, 0, $offset);
                if (preg_match('/[×*÷\/(-]\s*$/u', $antes)) {
                    continue;
                }
                $izq = $m[1][0];
                $derRaw = $m[2][0];
                $unidDer = self::plain_lower(trim($m[3][0] ?? ''));
                $partes = preg_split('/\s*\+\s*/u', $izq);
                if (count($partes) < 2) {
                    continue;
                }
                $nums = [];
                $unidades = [];
                $valido = true;
                foreach ($partes as $p) {
                    if (!preg_match('/^(\d+(?:[.,]\d+)?)\s*(min(?:utos?)?|pts?|puntos?|%|h)?$/iu', trim($p), $pm)) {
                        $valido = false;
                        break;
                    }
                    $v = self::parse_number($pm[1]);
                    if ($v === null) {
                        $valido = false;
                        break;
                    }
                    $nums[] = $v;
                    $u = self::plain_lower($pm[2] ?? '');
                    if ($u !== '') {
                        $unidades[substr($u, 0, 1)] = true;
                    }
                }
                if (!$valido) {
                    continue;
                }
                if ($unidDer !== '') {
                    $unidades[substr($unidDer, 0, 1)] = true;
                }
                if (count($unidades) > 1) {
                    continue;
                }
                $declarado = self::parse_number($derRaw);
                if ($declarado === null) {
                    continue;
                }
                $real = array_sum($nums);
                if (abs($real - $declarado) > 0.01) {
                    $expr = trim(preg_replace('/\s+/u', ' ', $m[0][0]));
                    $clave = self::plain_lower($expr);
                    if (isset($vistos[$clave])) {
                        continue;
                    }
                    $vistos[$clave] = true;
                    $realFmt = (abs($real - round($real)) < 0.001) ? (string) (int) round($real)
                        : str_replace('.', ',', rtrim(rtrim(number_format($real, 2, '.', ''), '0'), '.'));
                    $sufijo = $unidDer !== '' ? ' ' . $unidDer : '';
                    $problemas[] = 'Verificación aritmética (Reglas 4 y 14): aparece el cálculo «' . $expr
                        . '», pero la suma real es ' . $realFmt . $sufijo . '. Corrige el cálculo desde la tabla.';
                }
            }
        }
        return $problemas;
    }

    /**
     * Detecta inconsistencias de agrupamientos (Regla 6) en las Etapas 2 y 3: (1) subgrupos de tamano impar >= 3
     * cuando se trabaja en duplas sin explicitar un trio o subgrupos de distinto tamano (p. ej. «9 duplas en 2 subgrupos
     * de 9 personas»); (2) producto de subgrupos x tamano distinto del total de participantes de la ficha. Funcion pura.
     *
     * @param string $html
     * @param int $participantes Total declarado en la ficha (0 si no se conoce).
     * @return string[]
     */
    public static function grouping_issues(string $html, int $participantes = 0): array {
        $txt = self::plain_lower(html_entity_decode(strip_tags(preg_replace('#</?(?:p|div|li|tr|td|th|br)\b[^>]*>#i', ' ', $html)),
            ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $txt = trim(preg_replace('/\s+/u', ' ', $txt));
        if ($txt === '') {
            return [];
        }
        $problemas = [];
        $usaDuplas = preg_match('/(?<!\bsin\s)(?<!\bno\s)\b(?:duplas?|parejas?)\b/u', $txt) === 1;
        $excepcion = preg_match('/\b(?:trios?|distinto tamano|tamanos? distintos?|tamanos? diferentes?|'
            . 'subgrupo impar|grupo impar|un grupo de \d+ y otro de \d+)\b/u', $txt) === 1;

        $tamVistos = [];
        if (preg_match_all('/\b(\d+)\s+(?:subgrupos?|grupos?)\s+de\s+(\d+)\s+(?:personas?|participantes?|estudiantes?|aprendices?)\b/u',
                $txt, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $nSub = (int) $hit[1];
                $tam = (int) $hit[2];
                if ($usaDuplas && !$excepcion && $tam >= 3 && $tam % 2 === 1 && !isset($tamVistos['impar_' . $tam])) {
                    $tamVistos['impar_' . $tam] = true;
                    $problemas[] = 'Agrupamientos (Regla 6): se declaran ' . $nSub . ' subgrupos de ' . $tam
                        . ' personas trabajando en duplas, lo que separa una dupla (' . $tam . ' es impar). Ajusta el '
                        . 'tamaño de los subgrupos a un número par o explicita la solución (un trío o subgrupos de '
                        . 'distinto tamaño).';
                }
                $esMixto = preg_match('/\b' . $nSub . '\s+(?:subgrupos?|grupos?)\s+de\s+' . $tam
                    . '\s+(?:personas?|participantes?|estudiantes?|aprendices?)[^.;]{0,35}\by\s+\d+\s+(?:subgrupos?|grupos?|trios?)/u',
                    $txt) === 1;
                if ($participantes > 0 && !$excepcion && !$esMixto && $nSub > 0 && $tam > 0
                        && ($nSub * $tam) !== $participantes && !isset($tamVistos['prod_' . $nSub . '_' . $tam])) {
                    $tamVistos['prod_' . $nSub . '_' . $tam] = true;
                    $problemas[] = 'Agrupamientos (Regla 6): ' . $nSub . ' subgrupos de ' . $tam . ' personas suman '
                        . ($nSub * $tam) . ', pero la ficha declara ' . $participantes . ' participantes. Ajusta los '
                        . 'agrupamientos o explicita la solución.';
                }
            }
        }
        if ($usaDuplas && !$excepcion && preg_match_all('/\b(?:subgrupos?|grupos?)\s+de\s+(\d+)\s+'
                . '(?:personas?|participantes?|estudiantes?|aprendices?)\b/u', $txt, $m2, PREG_SET_ORDER)) {
            foreach ($m2 as $hit) {
                $tam = (int) $hit[1];
                if ($tam >= 3 && $tam % 2 === 1 && !isset($tamVistos['impar_' . $tam])) {
                    $tamVistos['impar_' . $tam] = true;
                    $problemas[] = 'Agrupamientos (Regla 6): se declaran subgrupos de ' . $tam
                        . ' personas trabajando en duplas, lo que separa una dupla (' . $tam . ' es impar). Ajusta el '
                        . 'tamaño de los subgrupos a un número par o explicita la solución (un trío o subgrupos de '
                        . 'distinto tamaño).';
                }
            }
        }
        return $problemas;
    }

    /**
     * Revision deterministica de la Etapa 3 (Secuencia instruccional): sumas explicitas erroneas, suma real de minutos
     * en tablas de actividades vs subtotales/titulos/tiempo asignado (2.3) y deteccion de catalogos propios de materiales
     * o instrumentos evaluativos listados como materiales (Reglas 1 ampliada, 4 y 14). Funcion pura.
     *
     * @param string $html Producto de la Etapa 3.
     * @param int $tiempoExp Tiempo asignado a la experiencia en la ficha (2.3, en minutos; 0 si se desconoce).
     * @param int $tiempoTotal Tiempo total disponible en la ficha (2.2, en minutos; 0 si se desconoce).
     * @return string[]
     */
    public static function sequence_issues(string $html, int $tiempoExp = 0, int $tiempoTotal = 0): array {
        $problemas = self::arithmetic_expression_issues($html);

        if (preg_match_all('#<h[1-6]\b[^>]*>(.*?)</h[1-6]>#isu', $html, $hm)) {
            foreach ($hm[1] as $ht) {
                $h = self::plain_lower(html_entity_decode(strip_tags($ht), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (preg_match('/\b(catalogo de materiales|catalogo de recursos|lista de produccion)\b/u', $h)) {
                    $problemas[] = 'Fuente única de materiales (Regla 1 ampliada): la Etapa 3 incluye una sección «'
                        . trim(strip_tags($ht)) . '». Los materiales instruccionales se definen únicamente en la Etapa 4 '
                        . 'y los instrumentos de evaluación en la Etapa 5.';
                    break;
                }
            }
        }

        $tablas = self::parse_tables($html);
        $sumasActividades = [];
        foreach ($tablas as $tabla) {
            $cab = $tabla['cabecera'];
            if (preg_grep('/^codigo/', $cab) && preg_grep('/material|recurso|insumo/', $cab)
                    && !preg_grep('/actividad|tiempo|duracion|minutos/', $cab)) {
                $conInst = false;
                foreach ($tabla['filas'] as $f) {
                    $linea = self::plain_lower(implode(' ', $f['celdas']));
                    if (preg_match('/\bins[\s_-]?\d|\b(rubrica|lista de cotejo|escala de apreciacion|pauta de observacion)\b/u', $linea)) {
                        $conInst = true;
                        break;
                    }
                }
                $problemas[] = 'Fuente única (Regla 1 ampliada): la Etapa 3 incluye un catálogo propio de materiales'
                    . ($conInst ? ' (además mezcla instrumentos evaluativos como INS/pautas/listas de cotejo)' : '')
                    . '. Los materiales se definen solo en la Etapa 4 y los instrumentos en la Etapa 5.';
                continue;
            }

            $colTiempo = null;
            $esActividad = (bool) preg_grep('/actividad|fase|momento|secuencia|descripcion/', $cab);
            foreach ($cab as $idx => $h) {
                if (preg_match('/\b(tiempo|duracion|minutos?|min)\b/u', $h)) {
                    $colTiempo = $idx;
                    break;
                }
            }
            if ($colTiempo === null || !$esActividad) {
                continue;
            }

            $paralelas = 0;
            foreach ($tabla['filas'] as $f) {
                if (preg_match('/\b(en paralelo|simultane[ao]s?)\b/u', self::plain_lower(implode(' ', $f['celdas'])))) {
                    $paralelas++;
                }
            }
            if ($paralelas >= 2) {
                continue;
            }

            $sumandos = [];
            $noLeibles = 0;
            $declarado = null;
            foreach ($tabla['filas'] as $f) {
                $primera = self::plain_lower($f['celdas'][0] ?? '');
                $toda = self::plain_lower(implode(' ', $f['celdas']));
                $celdaTiempo = $f['celdas'][$colTiempo] ?? '';
                if (preg_match('/^(?:sub\s*total|subtotal|total|suma)\b/u', $primera)
                        || preg_match('/\b(?:subtotal fase|total fase|total experiencia|tiempo total)\b/u', $toda)) {
                    $d = self::extract_minutes($celdaTiempo, true);
                    if ($d !== null) {
                        $declarado = $d;
                    }
                    continue;
                }
                $min = self::extract_minutes($celdaTiempo, true);
                if ($min === null) {
                    $noLeibles++;
                } else {
                    $sumandos[] = $min;
                }
            }
            if ($noLeibles === 0 && count($sumandos) >= 2) {
                $suma = array_sum($sumandos);
                $etiqueta = $tabla['titulo'] !== '' ? $tabla['titulo'] : 'la tabla de secuencia';
                if (preg_grep('/actividad|descripcion/', $cab)) {
                    $sumasActividades[] = $suma;
                }
                if ($declarado !== null && $suma !== $declarado) {
                    $problemas[] = 'Tiempos en «' . $etiqueta . '» (Reglas 4 y 14): las actividades suman ' . $suma
                        . ' min (' . implode(' + ', $sumandos) . '), pero el total/subtotal declara ' . $declarado
                        . ' min. Corrige el tiempo sumando desde la tabla.';
                } else if ($declarado === null && preg_match('/\(?\s*(\d{2,4})\s*min(?:utos?)?\s*\)?/iu', $tabla['titulo'], $mh)) {
                    $titMin = (int) $mh[1];
                    if ($titMin !== $suma) {
                        $problemas[] = 'Tiempos en «' . $etiqueta . '» (Reglas 4 y 14): las actividades suman ' . $suma
                            . ' min (' . implode(' + ', $sumandos) . '), pero el título declara ' . $titMin . ' min.';
                    }
                }
            }
        }

        if ($sumasActividades) {
            $totalCalculado = array_sum($sumasActividades);
            if ($tiempoExp > 0 && $totalCalculado > $tiempoExp) {
                $problemas[] = 'Tiempo total de la secuencia (Reglas 4 y 14): las actividades suman ' . $totalCalculado
                    . ' min y superan el tiempo asignado a la experiencia en la ficha (' . $tiempoExp . ' min).';
            } else if ($tiempoExp <= 0 && $tiempoTotal > 0 && $totalCalculado > $tiempoTotal) {
                $problemas[] = 'Tiempo total de la secuencia (Reglas 4 y 14): las actividades suman ' . $totalCalculado
                    . ' min y superan el tiempo total disponible en la ficha (' . $tiempoTotal . ' min).';
            }
        }
        return array_values(array_unique($problemas));
    }

    /**
     * Revision deterministica de la Etapa 5 (Plan de evaluacion): sumas explicitas, suma de ponderaciones = 100 %,
     * unidad evaluada individual (Regla 5), columna «Persona que aplica» sin aceptar solo acompanamiento/supervision
     * (Regla 15), una fila por aplicacion (Regla 17) y coherencia del umbral puntos/%. Funcion pura.
     *
     * @param string $html Producto de la Etapa 5.
     * @param bool $auditoria Si exige las reglas 15 y 17 (interruptor «auditoriam2»).
     * @return string[]
     */
    public static function evaluation_plan_issues(string $html, bool $auditoria = true): array {
        $problemas = self::arithmetic_expression_issues($html);
        foreach (self::parse_tables($html) as $tabla) {
            $cab = $tabla['cabecera'];
            $esRegistro = (bool) preg_grep('/instrumento|nombre/', $cab)
                && (bool) preg_grep('/ponderacion|puntaje|umbral|tipo|momento/', $cab);
            if (!$esRegistro) {
                continue;
            }
            $tieneDetallesRegistro = (bool) preg_grep('/ponderacion|puntaje|umbral/', $cab);
            $tieneAplicador = (bool) preg_grep('/aplica|responsable|evaluador|quien/', $cab);
            if ($auditoria && $tieneDetallesRegistro && !$tieneAplicador) {
                $problemas[] = 'Registro de instrumentos (Regla 15): falta la columna «Persona que aplica» indicando '
                    . 'quién aplica cada instrumento o parte/estación evaluada («acompañar» no cuenta como aplicar).';
            }
            $ponderaciones = [];
            foreach ($tabla['filas'] as $f) {
                $row = $f['map'];
                $primera = self::plain_lower($f['celdas'][0] ?? '');
                if (preg_match('/^(?:total|suma)\b/u', $primera)) {
                    continue;
                }
                $inst = self::column($row, '/instrumento|nombre/') ?: ($f['celdas'][0] ?? 'Instrumento');

                $pond = self::column($row, '/ponderacion/');
                if ($pond !== '') {
                    $pb = self::plain_lower($pond);
                    if (!preg_match('/\b(formativ|diagnostic|no aplica|n\/a|sin ponder)\b/u', $pb)
                            && preg_match('/(\d+(?:[.,]\d+)?)\s*%/u', $pb, $mp)) {
                        $val = self::parse_number($mp[1]);
                        if ($val !== null && $val > 0) {
                            $ponderaciones[] = $val;
                        }
                    }
                }

                $unidad = self::column($row, '/unidad/');
                if ($unidad !== '') {
                    $ub = self::plain_lower($unidad);
                    if (preg_match('/\b(duplas?|parejas?|subgrupos?|grupal)\b/u', $ub)
                            && !preg_match('/\b(individual|persona|participante)\b/u', $ub)) {
                        $problemas[] = 'Evaluación individual (Regla 5): en «' . $inst . '» la unidad evaluada aparece '
                            . 'como «' . $unidad . '». Registra y califica las evidencias sumativas por persona y no '
                            . 'describas la unidad evaluada como «la dupla».';
                    }
                }

                if ($auditoria && $tieneAplicador) {
                    $apl = self::column($row, '/aplica|responsable|evaluador|quien/');
                    $ab = self::plain_lower($apl);
                    if ($ab === '' || preg_match('/^(?:—|-|n\/a|sin asignar|sin definir|nadie|por definir)$/u', $ab)) {
                        $problemas[] = 'Quién aplica el instrumento (Regla 15): «' . $inst . '» no tiene persona '
                            . 'asignada que lo aplique.';
                    } else if (preg_match('/\b(acompana|acompanamiento|supervisa|supervision|apoya|apoyo logistico)\b/u', $ab)
                            && !preg_match('/\b(aplica|evalua|califica|registra)\b/u', $ab)) {
                        $problemas[] = 'Quién aplica el instrumento (Regla 15): en «' . $inst . '» figura «' . $apl
                            . '». «Acompañar» o «supervisar» no cuenta como aplicar un instrumento: indica quién aplica '
                            . 'y registra efectivamente cada parte o estación.';
                    }
                }

                if ($auditoria) {
                    $momento = self::column($row, '/momento/');
                    if ($momento !== '') {
                        $mb = self::plain_lower($momento);
                        $dosMomentos = (preg_match('/\b(inicio|desarrollo|cierre)\b[^,;]{0,25}\b(?:y|\/|;)\s*(?:al\s+|en\s+|el\s+|la\s+)?\b(inicio|desarrollo|cierre)\b/u', $mb, $mm)
                                && $mm[1] !== $mm[2])
                            || (preg_match('/\bfase\s*(\d+)\b[^,;]{0,25}\b(?:y|\/|;)\s*\bfase\s*(\d+)\b/u', $mb, $mf)
                                && $mf[1] !== $mf[2])
                            || preg_match('/\b(diagnostica|formativa)\b[^,;]{0,20}\b(?:y|\/|;)\s*\bsumativa\b/u', $mb) === 1
                            || preg_match('/\b(dos\s+(?:momentos|aplicaciones)|2\s+(?:momentos|aplicaciones)|aplicacion\s*1\s*y\s*2)\b/u', $mb) === 1;
                        if ($dosMomentos) {
                            $problemas[] = 'Una fila por aplicación (Regla 17): «' . $inst . '» declara varios '
                                . 'momentos de aplicación en la misma fila («' . $momento . '»). Registra cada aplicación '
                                . 'en una fila separada.';
                        }
                    }
                }

                $maxStr = self::column($row, '/puntaje/');
                $umbStr = self::column($row, '/umbral/');
                if ($maxStr !== '' && $umbStr !== ''
                        && preg_match('/(\d+(?:[.,]\d+)?)/', $maxStr, $mMax)
                        && preg_match('/(\d+)\s*(?:pts?|puntos?)/iu', $umbStr, $mPts)
                        && preg_match('/(\d+(?:[.,]\d+)?)\s*%/u', $umbStr, $mPct)) {
                    $maxPts = self::parse_number($mMax[1]);
                    $umbPts = (int) $mPts[1];
                    $umbPct = self::parse_number($mPct[1]);
                    if ($maxPts !== null && $maxPts > 0 && $umbPct !== null && $umbPct > 0 && $umbPct <= 100) {
                        $esperado = (int) ceil(($maxPts * $umbPct / 100.0) - 0.0001);
                        if (abs($umbPts - $esperado) >= 2) {
                            $problemas[] = 'Umbral de aprobación (Regla 4): en «' . $inst . '», ' . $umbPct
                                . ' % de ' . (int) round($maxPts) . ' pts corresponde a ' . $esperado
                                . ' pts (redondeado hacia arriba), pero se declaran ' . $umbPts . ' pts.';
                        }
                    }
                }
            }
            if (count($ponderaciones) >= 2) {
                $sumaPond = array_sum($ponderaciones);
                if (abs($sumaPond - 100.0) > 0.5) {
                    $problemas[] = 'Ponderaciones en la Etapa 5 (Regla 4): las ponderaciones sumativas del registro '
                        . 'suman ' . rtrim(rtrim(number_format($sumaPond, 1, ',', ''), '0'), ',')
                        . ' % en lugar de 100 %.';
                }
            }
        }
        return array_values(array_unique($problemas));
    }

    /**
     * Revision deterministica de la Etapa 6 (Instrumentos evaluativos): sumas explicitas (Reglas 4 y 14), equivalencia
     * a los cuatro niveles de la ficha de implementacion del Modulo 3 (Regla 10) y unidad evaluada individual (Regla 5).
     * Funcion pura.
     *
     * @param string $html Producto de la Etapa 6.
     * @return string[]
     */
    public static function instruments_issues(string $html): array {
        $problemas = self::arithmetic_expression_issues($html);
        $plano = self::plain_lower(html_entity_decode(strip_tags(
            preg_replace('#</?(?:p|div|li|tr|td|th|br|h[1-6])\b[^>]*>#i', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (trim($plano) === '') {
            return $problemas;
        }
        $nivelesFaltantes = [];
        foreach (['insuficiente' => 'Insuficiente', 'basico' => 'Básico', 'adecuado' => 'Adecuado', 'avanzado' => 'Avanzado']
                as $clave => $etiqueta) {
            if (!preg_match('/\b' . $clave . '\b/u', $plano)) {
                $nivelesFaltantes[] = $etiqueta;
            }
        }
        if ($nivelesFaltantes && (stripos($html, '<table') !== false || stripos($html, '<h3') !== false)) {
            $problemas[] = 'Equivalencia de niveles (Regla 10): faltan niveles de la ficha del Módulo 3 ('
                . implode(', ', $nivelesFaltantes) . '). Incluye en cada instrumento la regla de equivalencia a '
                . 'Insuficiente, Básico, Adecuado y Avanzado.';
        }
        if (preg_match('/unidad evaluada\s*:\s*([^\n<]{1,60})/iu',
                self::plain_lower(html_entity_decode(strip_tags(preg_replace('#</?(?:p|li|br|tr|td|th)\b[^>]*>#i', "\n", $html)),
                    ENT_QUOTES | ENT_HTML5, 'UTF-8')), $mu)) {
            if (preg_match('/\b(la dupla|dupla|pareja|subgrupo)\b/u', $mu[1])
                    && !preg_match('/\b(individual|persona|participante)\b/u', $mu[1])) {
                $problemas[] = 'Evaluación individual (Regla 5): aparece «Unidad evaluada: ' . trim($mu[1])
                    . '». Registra y califica el desempeño por persona.';
            }
        }
        return array_values(array_unique($problemas));
    }

    /**
     * Revision deterministica unificada de una etapa (2 a 6). Funcion pura.
     *
     * @param int $etapa 1 a 6.
     * @param string $html Producto normalizado de la etapa.
     * @param array $ficha Datos limpios de la ficha (participantes, tiempo_experiencia, tiempo_total).
     * @param bool $auditoria Activa las reglas 14 a 17 y controles ampliados.
     * @return string[]
     */
    public static function stage_issues(int $etapa, string $html, array $ficha = [], bool $auditoria = true): array {
        // Solo el diseno vigente: sin el registro de ajustes ni el anexo de consistencia (describen cambios).
        $html = self::without_change_log($html);
        if (trim(strip_tags($html)) === '') {
            return [];
        }
        $participantes = (int) preg_replace('/\D+/', '', (string) ($ficha['participantes'] ?? ''));
        $tiempoExp = (int) preg_replace('/\D+/', '', (string) ($ficha['tiempo_experiencia'] ?? ''));
        $tiempoTotal = (int) preg_replace('/\D+/', '', (string) ($ficha['tiempo_total'] ?? ''));
        $lista = [];
        if ($etapa === 2) {
            $lista = array_merge(self::phase_time_issues($html, $tiempoExp, $tiempoTotal),
                self::grouping_issues($html, $participantes));
        } else if ($etapa === 3) {
            $lista = array_merge(self::sequence_issues($html, $tiempoExp, $tiempoTotal),
                self::grouping_issues($html, $participantes));
        } else if ($etapa === 4) {
            $lista = array_merge(self::production_issues($html), self::arithmetic_expression_issues($html));
        } else if ($etapa === 5) {
            $lista = self::evaluation_plan_issues($html, $auditoria);
        } else if ($etapa === 6) {
            $lista = self::instruments_issues($html);
        }
        return array_values(array_unique($lista));
    }

    /**
     * Hallazgos objetivos verificados por el codigo PHP a lo largo de las Etapas 2 a 6 (para la auditoria independiente
     * de la Etapa 7 y la Etapa 8). Incluye tambien cruces de codigos de materiales entre la Etapa 4 y las Etapas 3 y 5.
     * Funcion pura.
     *
     * @param string[] $contenidos Numero de etapa (1 a 6) => HTML aceptado.
     * @param array $ficha Datos limpios de la ficha.
     * @return string[]
     */
    public static function design_audit_facts(array $contenidos, array $ficha = []): array {
        $hallazgos = [];
        foreach ([2, 3, 4, 5, 6] as $n) {
            if (empty($contenidos[$n])) {
                continue;
            }
            $norm = self::normalize_product((string) $contenidos[$n]);
            foreach (self::stage_issues($n, $norm, $ficha, true) as $prob) {
                $hallazgos[] = 'Etapa ' . $n . ' — ' . $prob;
            }
        }
        if (!empty($contenidos[4])) {
            $definidos = [];
            foreach (self::production_rows(self::normalize_product((string) $contenidos[4])) as $fila) {
                $cod = strtoupper(trim(self::column($fila, '/^codigo/')));
                if (preg_match('/^M(\d{1,2})$/', $cod)) {
                    $definidos[$cod] = true;
                }
            }
            if ($definidos) {
                foreach ([3, 5] as $n) {
                    if (empty($contenidos[$n])) {
                        continue;
                    }
                    $plano = html_entity_decode(strip_tags(preg_replace('#</?(?:p|div|li|tr|td|th|br|h[1-6])\b[^>]*>#i', ' ',
                        (string) $contenidos[$n])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if (preg_match_all('/(?:(?:material|c[oó]digo)\s+)?\b(M(\d{1,2}))\b/u', $plano, $mc, PREG_SET_ORDER)) {
                        foreach ($mc as $hit) {
                            $cod = $hit[1];
                            $num = (int) $hit[2];
                            $conPrefijo = stripos($hit[0], 'material') !== false || stripos($hit[0], 'c') === 0;
                            if (!isset($definidos[$cod]) && ($conPrefijo || $num > 3)) {
                                $hallazgos[] = 'Etapa ' . $n . ' — Referencia a material inexistente (Regla 13): cita «'
                                    . $cod . '», que no figura en la lista de producción de la Etapa 4 ('
                                    . implode(', ', array_keys($definidos)) . ').';
                            }
                        }
                    }
                }
            }
        }
        return array_values(array_unique($hallazgos));
    }

    private static function column(array $fila, string $columna): string {
        foreach ($fila as $encabezado => $texto) {
            if (preg_match($columna, (string) $encabezado)) {
                return (string) $texto;
            }
        }
        return '';
    }

    /**
     * Correcciones pendientes del registro de ajustes de un producto (control de cambios entre etapas): las filas cuyo
     * estado es «Pendiente de actualizar». Funcion pura.
     *
     * @param string $html Producto de la etapa.
     * @return string[] «Etapas afectadas: corrección».
     */
    public static function pending_adjustments(string $html): array {
        $pendientes = [];
        foreach (self::section_rows($html, '/registro de ajustes/') as $fila) {
            if (!preg_match('/pendiente/', self::plain_lower(self::column($fila, '/^estado/')))) {
                continue;
            }
            $etapas = self::column($fila, '/^etapas? afectad/');
            $correccion = self::column($fila, '/^correccion/');
            $texto = trim($etapas . ($etapas !== '' && $correccion !== '' ? ': ' : '') . $correccion);
            if ($texto !== '') {
                $pendientes[] = $texto;
            }
        }
        return $pendientes;
    }

    /**
     * Etapas (1 a 6) que nombra la columna «Etapas afectadas» de un registro de ajustes: «Etapa 3 (Secuencia
     * instruccional)», «Etapas 3, 4 y 5», «Etapas 3 a 6». Funcion pura.
     *
     * @param string $texto
     * @return int[]
     */
    public static function stage_numbers(string $texto): array {
        $numeros = [];
        if (preg_match_all('/etapas?\s+((?:\d+(?:\s*(?:,|;|y|e|a|al|-|–|hasta)\s*)?)+)/u', self::plain_lower($texto), $m)) {
            foreach ($m[1] as $lista) {
                if (preg_match_all('/(\d+)\s*(?:a|al|-|–|hasta)\s*(\d+)/u', $lista, $rangos, PREG_SET_ORDER)) {
                    foreach ($rangos as $r) {
                        $numeros = array_merge($numeros, range((int) $r[1], max((int) $r[1], min((int) $r[2], 6))));
                    }
                }
                preg_match_all('/\d+/', $lista, $sueltos);
                $numeros = array_merge($numeros, array_map('intval', $sueltos[0]));
            }
        }
        $numeros = array_values(array_unique(array_filter($numeros, static fn($n) => $n >= 1 && $n <= 6)));
        sort($numeros);
        return $numeros;
    }

    /**
     * Correcciones pendientes del control de cambios entre etapas, con lo que dicen los registros de ajustes de todas
     * las etapas. Una fila «Pendiente de actualizar» de la etapa de origen O queda pendiente para cada etapa afectada N
     * que no se haya vuelto a aceptar despues del ajuste de O (aunque hoy este en borrador) (aceptacion de O, o su ultima modificacion si esta en
     * borrador). La etapa de origen es la de la columna «Etapa de origen» (el asistente copia en el registro de una etapa
     * las filas que recibio de otra); las filas repetidas entre registros cuentan una vez. Funcion pura.
     *
     * @param array $etapas n => ['contenido' => html, 'aceptada' => bool, 'timeaccepted' => int, 'timemodified' => int].
     * @return array[] ['origen' => O, 'etapas' => N pendientes, 'afectadas' => texto de la columna, 'correccion' => texto].
     */
    public static function pending_changes(array $etapas): array {
        $lista = [];
        $vistas = [];
        foreach ($etapas as $producto => $p) {
            foreach (self::section_rows((string) ($p['contenido'] ?? ''), '/registro de ajustes/') as $fila) {
                if (!preg_match('/pendiente/', self::plain_lower(self::column($fila, '/^estado/')))) {
                    continue;
                }
                $origen = self::stage_numbers(self::column($fila, '/^(etapa de )?origen/'))[0] ?? (int) $producto;
                $e = $etapas[$origen] ?? $p;
                $desde = !empty($e['aceptada']) ? (int) ($e['timeaccepted'] ?? 0) : (int) ($e['timemodified'] ?? 0);
                $clave = $origen . '|' . self::plain_lower(preg_replace('/\s+/u', ' ', self::column($fila, '/^etapas? afectad/') . '|'
                    . self::column($fila, '/^correccion/')));
                if (isset($vistas[$clave])) {
                    continue;
                }
                $vistas[$clave] = true;
                $afectadas = self::column($fila, '/^etapas? afectad/');
                $pendientes = [];
                foreach (self::stage_numbers($afectadas) as $n) {
                    $otra = $etapas[$n] ?? null;
                    // Resuelta si la etapa afectada se acepto despues del ajuste, aunque hoy este en borrador por otro ajuste
                    // (si no, la correccion ya aplicada reaparece mientras la persona trabaja en ella).
                    $resuelta = $otra && (int) ($otra['timeaccepted'] ?? 0) > $desde;
                    if ($n !== (int) $origen && !$resuelta) {
                        $pendientes[] = $n;
                    }
                }
                if ($pendientes) {
                    $lista[] = ['origen' => (int) $origen, 'etapas' => $pendientes, 'afectadas' => $afectadas,
                        'correccion' => self::column($fila, '/^correccion/')];
                }
            }
        }
        return $lista;
    }

    /**
     * Inconsistencias aceptadas que registraron las etapas (filas «Inconsistencia aceptada» de sus registros de ajustes),
     * sin repetir. Las detecta el codigo para que lleguen al anexo y a la etapa 8 aunque el asistente las pase por alto
     * al consolidar (visto al probar en el VPS del cliente el 10-11-2026). Funcion pura.
     *
     * @param string[] $productos Numero de etapa => producto (HTML).
     * @return string[] «Etapa 6 (Instrumentos evaluativos), desde la Etapa 5: corrección no aplicada. Efecto: ...».
     */
    public static function accepted_inconsistencies(array $productos): array {
        $lista = [];
        foreach ($productos as $n => $html) {
            foreach (self::section_rows((string) $html, '/registro de ajustes/') as $fila) {
                if (!preg_match('/inconsistencia aceptada/', self::plain_lower(self::column($fila, '/^estado/')))) {
                    continue;
                }
                $origen = self::stage_numbers(self::column($fila, '/^(etapa de )?origen/'))[0] ?? (int) $n;
                $texto = trim(self::column($fila, '/^etapas? afectad/') . ', desde la Etapa ' . $origen . ': '
                    . (self::column($fila, '/^correccion/') ?: self::column($fila, '/^cambio/')));
                $lista[self::plain_lower(preg_replace('/\s+/u', ' ', $texto))] = $texto;
            }
        }
        return array_values($lista);
    }

    /**
     * Si la consolidacion del asistente debe poder rehacerse aunque este al dia con las etapas: no trae la verificacion
     * de consistencia (es anterior al control de cambios), o hay inconsistencias aceptadas y su verificacion no marca
     * ninguna fila «Inconsistente». Funcion pura.
     *
     * @param string $documento Consolidacion (HTML).
     * @param string[] $aceptadas Inconsistencias aceptadas registradas en las etapas.
     * @return bool
     */
    public static function needs_reconsolidation(string $documento, array $aceptadas): bool {
        if (!self::section_rows($documento, '/verificacion de consistencia/')) {
            return true;
        }
        return $aceptadas && !self::inconsistencies($documento);
    }

    /**
     * Inconsistencias de la verificacion de consistencia entre etapas del diseno consolidado: las filas «Inconsistente».
     * Funcion pura.
     *
     * @param string $html Diseno consolidado.
     * @return string[] «Elemento: etapas y diferencia».
     */
    public static function inconsistencies(string $html): array {
        $lista = [];
        foreach (self::section_rows($html, '/verificacion de consistencia/') as $fila) {
            $resultado = self::column($fila, '/^resultado/');
            if (!preg_match('/\binconsistente\b/', self::plain_lower($resultado !== '' ? $resultado : implode(' ', $fila)))) {
                continue;
            }
            // El nombre corto del elemento: «Fases, actividades o estaciones: número y nombre en cada etapa.» -> «Fases, ...».
            $elemento = trim(preg_replace('/[\s.:]+$/u', '', preg_split('/:\s/u', (string) reset($fila), 2)[0]));
            $diferencia = self::column($fila, '/^etapas/');
            $lista[] = trim($elemento . ($diferencia !== '' ? ': ' . $diferencia : ''));
        }
        return $lista;
    }

    public static function plain_lower(string $t): string {
        return strtr(\core_text::strtolower($t), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            'ñ' => 'n']);
    }

    /**
     * Texto que da la estructura de un producto: titulos, encabezados de tabla y rotulos en negrita. Ahi se buscan los
     * elementos de la etapa (una palabra suelta en un parrafo no cuenta como sección). Funcion pura.
     *
     * @param string $html
     * @return string
     */
    public static function structure_text(string $html): string {
        preg_match_all('#<(h[1-6]|th|strong|b)\b[^>]*>(.*?)</\1>#isu', $html, $m);
        $partes = array_map(static function(string $t): string {
            return html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }, $m[2]);
        return ' ' . self::plain_lower(implode(' | ', $partes)) . ' ';
    }

    /**
     * Si un elemento esta en el texto de estructura: sus raices (todas si son una o dos; si son mas, al menos el 60 %).
     * Funcion pura.
     *
     * @param string $item
     * @param string $estructura De structure_text().
     * @return bool
     */
    public static function has_item(string $item, string $estructura): bool {
        $raices = self::item_stems($item);
        if (!$raices) {
            return true;
        }
        $hay = 0;
        foreach ($raices as $r) {
            $hay += preg_match('/(?<![a-z0-9])' . preg_quote($r, '/') . '/', $estructura) ? 1 : 0;
        }
        return $hay >= max(1, (int) ceil(count($raices) * 0.6));
    }

    /**
     * Revision automatica de una etapa: lo que pide la etapa y no aparece en el producto (como titulo, columna de tabla o
     * rotulo en negrita), mas el cierre comun (vacios, verificacion y referencias, en los productos hechos con el prompt
     * que ya lo pide; la verificacion siempre). Para la consolidacion (etapa 7): las seis etapas y la verificacion de cada
     * una. Funcion pura.
     *
     * @param int $etapa 1 a 7.
     * @param string $html Producto (ya normalizado).
     * @param bool $cierre El producto se pidio con el cierre comun (prompt_number() >= PROMPT_CIERRE).
     * @return string[] Nombres de lo que falta, para mostrarlos y pedirlos.
     */
    public static function missing_items(int $etapa, string $html, bool $cierre = true): array {
        if (trim(strip_tags($html)) === '') {
            return [];
        }
        if ($etapa === self::CONSOLIDACION) {
            return array_merge(self::missing_parts($html, $cierre), array_map(static function(string $frase): string {
                return 'Texto en español (hay un párrafo en inglés: «' . $frase . '…»)';
            }, self::english_paragraphs($html)));
        }
        $e = self::etapas()[$etapa] ?? null;
        if ($e === null) {
            return [];
        }
        $estructura = self::structure_text($html);
        $faltan = [];
        foreach ($e['comprobar'] ?? $e['incluye'] as $item) {
            if (!self::has_item($item, $estructura)) {
                $faltan[] = trim(preg_split('/[,;]/u', $item)[0]);
            }
        }
        foreach (self::english_paragraphs($html) as $frase) {
            $faltan[] = 'Texto en español (hay un párrafo en inglés: «' . $frase . '…»)';
        }
        // Las secciones de cierre se reconocen por su palabra clave, aunque el titulo no sea textual.
        $verif = '/verificacion de (?:la )?calidad/';
        $finales = $cierre ? [self::SECCION_VACIOS => '/vacios? (?:de informacion|y soluciones)|registro de vacios/', self::SECCION_VERIFICACION => $verif,
            self::SECCION_REFERENCIAS => '/referencias/'] : [self::SECCION_VERIFICACION => $verif];
        foreach ($finales as $s => $clave) {
            // La Etapa 4 ya pide «Vacíos y soluciones» en su lista: no se pide dos veces.
            if (!preg_match($clave, $estructura) && !($s === self::SECCION_VACIOS && in_array('Vacíos y soluciones', $faltan, true))) {
                $faltan[] = $s;
            }
        }
        return $faltan;
    }

    /**
     * Comienzo de los parrafos e items escritos en ingles (el modelo a veces redacta una seccion en ingles), hasta tres:
     * los que tienen muchas palabras funcionales del ingles y pocas del español. Funcion pura.
     *
     * @param string $html
     * @return string[]
     */
    public static function english_paragraphs(string $html): array {
        $en = array_flip(['the', 'and', 'of', 'to', 'in', 'for', 'with', 'were', 'was', 'is', 'are', 'this', 'that',
            'which', 'following', 'be', 'by', 'on', 'as', 'from', 'it', 'have', 'has', 'an', 'while']);
        $es = array_flip(['de', 'la', 'el', 'y', 'en', 'que', 'los', 'las', 'para', 'con', 'por', 'del', 'se', 'un',
            'una', 'al', 'su', 'es']);
        $salida = [];
        preg_match_all('#<(p|li|td)\b[^>]*>(.*?)</\1>#isu', $html, $m);
        foreach ($m[2] as $trozo) {
            $texto = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($trozo), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $palabras = preg_split('/[^\p{L}]+/u', \core_text::strtolower($texto), -1, PREG_SPLIT_NO_EMPTY);
            if (count($palabras) < 8) {
                continue;
            }
            $ningles = count(array_filter($palabras, static function(string $w) use ($en): bool {
                return isset($en[$w]);
            }));
            $nespanol = count(array_filter($palabras, static function(string $w) use ($es): bool {
                return isset($es[$w]);
            }));
            if ($ningles >= 0.2 * count($palabras) && $ningles > 2 * $nespanol) {
                $salida[] = \core_text::substr($texto, 0, 60);
                if (count($salida) === 3) {
                    break;
                }
            }
        }
        return $salida;
    }

    /**
     * Partes que faltan en la consolidacion: cada etapa 1 a 6 (titulo «Parte N» o «Etapa N») y, si se pide el cierre,
     * la verificacion de calidad dentro de cada una. Funcion pura.
     *
     * @param string $html
     * @param bool $cierre
     * @return string[]
     */
    private static function missing_parts(string $html, bool $cierre): array {
        preg_match_all('#<h[1-3]\b[^>]*>\s*(?:<[^>]+>\s*)*(?:parte|etapa)\s+(\d+)\b#iu', $html, $m, PREG_OFFSET_CAPTURE);
        $inicio = [];
        foreach ($m[1] as [$num, $pos]) {
            $n = (int) $num;
            if ($n >= 1 && $n <= 6 && !isset($inicio[$n])) {
                $inicio[$n] = $pos;
            }
        }
        $faltan = [];
        foreach (self::etapas() as $n => $e) {
            if (!isset($inicio[$n])) {
                $faltan[] = 'Etapa ' . $n . ': ' . $e['titulo'];
                continue;
            }
            if ($cierre) {
                $fin = strlen($html);
                foreach ($inicio as $otra => $pos) {
                    if ($pos > $inicio[$n] && $pos < $fin) {
                        $fin = $pos;
                    }
                }
                if (!preg_match('/verificacion de (?:la )?calidad/', self::structure_text(substr($html, $inicio[$n], $fin - $inicio[$n])))) {
                    $faltan[] = self::SECCION_VERIFICACION . ' (Etapa ' . $n . ')';
                }
            }
        }
        return $faltan;
    }

    /**
     * HTML del Word de una etapa con el diseño del documento final: el producto con su titulo de etapa al comienzo.
     * Funcion pura.
     *
     * @param string $titulo «Etapa 2: Diseño de la experiencia».
     * @param string $html Producto ya normalizado.
     * @return string
     */
    public static function stage_document_html(string $titulo, string $html): string {
        // El producto ya trae su titulo de etapa («Etapa 2: Diseño de la experiencia») casi siempre; si no, se agrega.
        if (preg_match('#<h[1-3][^>]*>\s*(?:<[^>]+>\s*)*(?:etapa|parte)\s+\d#iu', $html)) {
            return $html;
        }
        return '<h1>' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</h1>' . $html;
    }

    /**
     * Producto tal como se muestra, se edita y se descarga: formulas en texto (plain_math()), bloques preformateados
     * como HTML comun (structure_pre()) y tablas ASCII guardadas como viñetas reparadas (repair_art_lists()). Se aplica
     * tambien a lo ya guardado, asi la pagina y los Word coinciden. Funcion pura.
     *
     * @param string $html
     * @return string
     */
    public static function normalize_product(string $html): string {
        $html = diagrama::list_flows(self::repair_art_lists(diagrama::ascii_lists(self::structure_pre(self::plain_math($html)))));
        // Lo guardado antes de las reglas de hoy: sin marcas de cita «[1]» y con las casillas «[ ]» como ☐ (no en un enlace).
        return preg_replace('/\[\s{0,2}\](?!\()/u', '☐', self::strip_citations($html));
    }

    /**
     * Repara las tablas dibujadas en ASCII («| Plan Formativo / Código | Servicios ... |») que hasta la v1.21.4 se
     * guardaron como lista de pedazos («• | Plan Formativo / Código», «• | Servicios ... |»; revision del cliente del
     * 02-11): una lista con la mitad o mas de sus items empezados en «|» vuelve a ser una tabla de rotulo y valor. Un item
     * en mayusculas sin «|» es el titulo; uno que empieza en minuscula continua el valor anterior. Funcion pura.
     *
     * @param string $html
     * @return string
     */
    public static function repair_art_lists(string $html): string {
        if (strpos($html, '<li>|') === false && strpos($html, '<li>&#124;') === false) {
            return $html;
        }
        return preg_replace_callback('#<ul>((?:(?!</?ul>).)*)</ul>#is', static function(array $m): string {
            preg_match_all('#<li>(.*?)</li>#is', $m[1], $li);
            $items = array_map(static function(string $t): string {
                return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            }, $li[1]);
            $conbarra = count(array_filter($items, static function(string $t): bool {
                return $t !== '' && $t[0] === '|';
            }));
            if (count($items) < 4 || $conbarra * 2 < count($items)) {
                return $m[0];
            }
            $e = static function(string $t): string {
                return htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
            };
            $titulo = '';
            $filas = [];
            $pendiente = null;
            foreach ($items as $t) {
                $c = trim($t, " |\t");
                if ($c === '') {
                    continue;
                }
                if ($t[0] !== '|' && diagrama::is_upper($c) && !$filas && $pendiente === null) {
                    $titulo = $c;
                    continue;
                }
                if (strpos($c, '|') !== false) {
                    [$a, $b] = array_map('trim', explode('|', $c, 2));
                    if ($pendiente !== null) {
                        $filas[] = [$pendiente, ''];
                        $pendiente = null;
                    }
                    $filas[] = [$a, trim($b, ' |')];
                } else if ($filas && $pendiente === null && (preg_match('/^\p{Ll}/u', $c)
                        || ($c[0] === '(' && !preg_match('/[.;:!?]$/u', $filas[count($filas) - 1][1])))) {
                    // Continuacion del valor anterior: empieza en minuscula, o con un parentesis cuando el valor no termino.
                    $filas[count($filas) - 1][1] = trim($filas[count($filas) - 1][1] . ' ' . $c);
                } else if ($pendiente === null) {
                    $pendiente = $c;
                } else {
                    $filas[] = [$pendiente, $c];
                    $pendiente = null;
                }
            }
            if ($pendiente !== null) {
                $filas[] = [$pendiente, ''];
            }
            // La primera fila es el encabezado de las columnas si son dos rotulos cortos sin cifras («Criterio | Estado»).
            $cabecera = count($filas) >= 3 && \core_text::strlen($filas[0][0]) <= 40 && \core_text::strlen($filas[0][1]) <= 40
                && $filas[0][1] !== '' && !preg_match('/[\d:]/', $filas[0][0] . $filas[0][1]) ? array_shift($filas) : null;
            $x = '<table>' . ($titulo !== '' || $cabecera ? '<thead>' : '')
                . ($titulo !== '' ? '<tr><th colspan="2">' . $e($titulo) . '</th></tr>' : '')
                . ($cabecera ? '<tr><th>' . $e($cabecera[0]) . '</th><th>' . $e($cabecera[1]) . '</th></tr>' : '')
                . ($titulo !== '' || $cabecera ? '</thead>' : '') . '<tbody>';
            foreach ($filas as [$a, $b]) {
                $x .= '<tr><td><strong>' . $e($a) . '</strong></td><td>' . $e($b) . '</td></tr>';
            }
            return $x . '</tbody></table>';
        }, $html);
    }

    /**
     * Cambia cada bloque preformateado (<pre>) del HTML por su version en HTML comun (documento_final::pre_html()):
     * formularios con lineas de puntos y casillas, tablas y arboles dibujados con caracteres. En la pagina se veian en
     * letra de maquina y en el Word salian con otro formato (revision del cliente del 02-11). Funcion pura.
     *
     * @param string $html
     * @return string
     */
    public static function structure_pre(string $html): string {
        if (stripos($html, '<pre') === false) {
            return $html;
        }
        return preg_replace_callback('#<pre\b[^>]*>(.*?)</pre>#is', static function(array $m): string {
            $texto = html_entity_decode(strip_tags(preg_replace('#<br\s*/?>#i', "\n", $m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $nuevo = documento_final::pre_html($texto);
            return $nuevo !== '' ? $nuevo : $m[0];
        }, $html);
    }

    /**
     * Quita las marcas numericas de cita entre corchetes que el modelo deja dentro del texto ([1], [2, 3], [1][2],
     * [4-6]): distraen, y las referencias van como lista al final del producto (pedido del cliente del 02-11-2026).
     * No toca los enlaces Markdown ([texto](url)) ni los corchetes con letras ([CRÍTICO]). Funcion pura.
     *
     * @param string $texto
     * @return string
     */
    public static function strip_citations(string $texto): string {
        // La marca se lleva el espacio que la precede: «texto [1].» queda «texto.».
        return preg_replace('/[ \t]*\[\s*\d+(?:\s*[,;\x{2013}\x{2014}-]\s*\d+)*\s*\](?!\()/u', '', $texto);
    }

    // ------------------------------------------------------------------------------------------
    // Word (.docx) a partir del HTML del producto. Funcion pura (escribe en la ruta indicada).
    // ------------------------------------------------------------------------------------------

    /**
     * Arma un .docx con el titulo y el HTML del producto (titulos, parrafos, listas, tablas, negritas, cursivas).
     *
     * @param string $ruta Archivo de salida.
     * @param string $titulo
     * @param string $subtitulo
     * @param string $html
     * @return bool
     */
    public static function html_to_docx(string $ruta, string $titulo, string $subtitulo, string $html): bool {
        if (!class_exists('\ZipArchive')) {
            return false;
        }
        $cuerpo = self::w_parrafo([self::w_run($titulo)], 'Title')
            . ($subtitulo !== '' ? self::w_parrafo([self::w_run($subtitulo, ['i' => true])], 'Subtitle') : '');
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        $body = $dom->getElementsByTagName('body')->item(0);
        $cuerpo .= $body ? self::w_bloques($body, 0) : '';

        $zip = new \ZipArchive();
        if ($zip->open($ruta, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.'
            . 'wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.'
            . 'wordprocessingml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/'
            . 'officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" '
            . 'Target="styles.xml"/></Relationships>');
        $zip->addFromString('word/styles.xml', self::w_styles());
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . $cuerpo . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1418" w:right="1418" '
            . 'w:bottom="1418" w:left="1418" w:header="709" w:footer="709" w:gutter="0"/></w:sectPr></w:body></w:document>');
        return $zip->close();
    }

    /**
     * Estilos minimos: normal, titulo, subtitulo y titulos 1 a 3.
     *
     * @return string
     */
    private static function w_styles(): string {
        $estilo = static function(string $id, string $nombre, int $sz, bool $b, string $color, int $antes, int $despues) {
            return '<w:style w:type="paragraph" w:styleId="' . $id . '"><w:name w:val="' . $nombre . '"/>'
                . '<w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:keepNext/>'
                . '<w:spacing w:before="' . $antes . '" w:after="' . $despues . '"/></w:pPr><w:rPr>' . ($b ? '<w:b/>' : '')
                . '<w:color w:val="' . $color . '"/><w:sz w:val="' . $sz . '"/></w:rPr></w:style>';
        };
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>'
            . '<w:sz w:val="22"/><w:lang w:val="es-CL"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr>'
            . '<w:spacing w:after="120" w:line="264" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
            . $estilo('Title', 'Title', 36, true, '173F5F', 0, 120)
            . $estilo('Subtitle', 'Subtitle', 24, false, '54656F', 0, 240)
            . $estilo('Heading1', 'heading 1', 30, true, '173F5F', 360, 120)
            . $estilo('Heading2', 'heading 2', 26, true, '20639B', 280, 100)
            . $estilo('Heading3', 'heading 3', 23, true, '20639B', 200, 80)
            . '</w:styles>';
    }

    /**
     * @param string $texto
     * @param array $fmt b, i, u => bool.
     * @return string
     */
    private static function w_run(string $texto, array $fmt = []): string {
        $rpr = (!empty($fmt['b']) ? '<w:b/>' : '') . (!empty($fmt['i']) ? '<w:i/>' : '') . (!empty($fmt['u']) ? '<w:u w:val="single"/>' : '')
            . (!empty($fmt['color']) ? '<w:color w:val="' . $fmt['color'] . '"/>' : '')
            . (!empty($fmt['sz']) ? '<w:sz w:val="' . (int) $fmt['sz'] . '"/>' : '');
        return '<w:r>' . ($rpr ? '<w:rPr>' . $rpr . '</w:rPr>' : '') . '<w:t xml:space="preserve">'
            . htmlspecialchars($texto, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t></w:r>';
    }

    /**
     * @param string[] $runs
     * @param string $estilo
     * @param int $sangria Twips.
     * @return string
     */
    private static function w_parrafo(array $runs, string $estilo = '', int $sangria = 0, string $jc = ''): string {
        $ppr = ($estilo ? '<w:pStyle w:val="' . $estilo . '"/>' : '') . ($sangria ? '<w:ind w:left="' . $sangria
            . '" w:hanging="283"/>' : '') . ($jc !== '' ? '<w:jc w:val="' . $jc . '"/>' : '');
        return '<w:p>' . ($ppr ? '<w:pPr>' . $ppr . '</w:pPr>' : '') . implode('', $runs) . '</w:p>';
    }

    /**
     * Runs de un nodo en linea (texto con negritas, cursivas y saltos).
     *
     * @param \DOMNode $nodo
     * @param array $fmt
     * @return string[]
     */
    private static function w_runs(\DOMNode $nodo, array $fmt = []): array {
        $runs = [];
        foreach ($nodo->childNodes as $hijo) {
            if ($hijo instanceof \DOMText) {
                $t = preg_replace('/\s+/u', ' ', $hijo->nodeValue);
                if ($t !== '') {
                    $runs[] = self::w_run($t, $fmt);
                }
                continue;
            }
            if (!$hijo instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($hijo->nodeName);
            if ($tag === 'br') {
                $runs[] = '<w:r><w:br/></w:r>';
                continue;
            }
            if (in_array($tag, ['ul', 'ol', 'table'], true)) {
                continue;
            }
            $f = $fmt;
            if (in_array($tag, ['strong', 'b', 'th'], true)) {
                $f['b'] = true;
            } else if (in_array($tag, ['em', 'i'], true)) {
                $f['i'] = true;
            } else if ($tag === 'u') {
                $f['u'] = true;
            }
            $runs = array_merge($runs, self::w_runs($hijo, $f));
        }
        return $runs;
    }

    /**
     * Bloques (titulos, parrafos, listas, tablas) de un contenedor.
     *
     * @param \DOMNode $padre
     * @param int $nivel Nivel de lista, para la sangria.
     * @param array $fmt Formato heredado (negrita en las celdas de encabezado).
     * @return string
     */
    private static function w_bloques(\DOMNode $padre, int $nivel, array $fmt = []): string {
        $xml = '';
        $suelto = [];
        $vaciar = static function() use (&$suelto, &$xml, $fmt) {
            $runs = array_filter($suelto, static function($r) {
                return trim(strip_tags($r)) !== '' || strpos($r, '<w:br/>') !== false;
            });
            if ($runs) {
                $xml .= self::w_parrafo($suelto, '', 0, (string) ($fmt['jc'] ?? ''));
            }
            $suelto = [];
        };
        foreach ($padre->childNodes as $hijo) {
            $tag = $hijo instanceof \DOMElement ? strtolower($hijo->nodeName) : '';
            if ($hijo instanceof \DOMText || in_array($tag, ['strong', 'b', 'em', 'i', 'u', 'span', 'a', 'code', 'br'], true)) {
                $suelto = array_merge($suelto, self::w_runs(self::envolver($hijo), $fmt));
                continue;
            }
            $vaciar();
            if (preg_match('/^h([1-6])$/', $tag, $m)) {
                $xml .= self::w_parrafo(self::w_runs($hijo, $fmt), 'Heading' . min(3, (int) $m[1]));
            } else if ($tag === 'pre') {
                // Un dibujo de caracteres no sobrevive en Word (la letra no es de ancho fijo): se entrega lo que decia
                // cada caja como lista. Un texto preformateado normal conserva sus lineas.
                $texto = (string) $hijo->textContent;
                $lineas = diagrama::has_art($texto) ? array_map(static function($l) {
                    return '• ' . $l;
                }, diagrama::art_lines($texto)) : array_filter(array_map('trim', preg_split('/\R/u', $texto)), 'strlen');
                foreach ($lineas as $linea) {
                    $xml .= self::w_parrafo([self::w_run($linea, $fmt)], '', 0, (string) ($fmt['jc'] ?? ''));
                }
            } else if (in_array($tag, ['p', 'blockquote'], true)) {
                $runs = self::w_runs($hijo, $fmt);
                if ($runs) {
                    $xml .= self::w_parrafo($runs, '', 0, (string) ($fmt['jc'] ?? ''));
                }
            } else if ($tag === 'ul' || $tag === 'ol') {
                $i = 0;
                foreach ($hijo->childNodes as $li) {
                    if (!$li instanceof \DOMElement || strtolower($li->nodeName) !== 'li') {
                        continue;
                    }
                    $i++;
                    $marca = $tag === 'ol' ? $i . '. ' : '• ';
                    $xml .= self::w_parrafo(array_merge([self::w_run($marca, $fmt)], self::w_runs($li, $fmt)), '', 567 * ($nivel + 1));
                    foreach ($li->childNodes as $sub) {
                        if ($sub instanceof \DOMElement && in_array(strtolower($sub->nodeName), ['ul', 'ol'], true)) {
                            $xml .= self::w_bloques(self::envolver($sub), $nivel + 1, $fmt);
                        }
                    }
                }
            } else if ($tag === 'table') {
                $xml .= self::w_tabla($hijo);
            } else if ($tag === 'hr') {
                $xml .= '<w:p/>';
            } else if ($tag !== '') {
                $xml .= self::w_bloques($hijo, $nivel, $fmt);
            }
        }
        $vaciar();
        return $xml;
    }

    /**
     * Nodo envuelto en un contenedor propio, para recorrerlo como bloque.
     *
     * @param \DOMNode $nodo
     * @return \DOMNode
     */
    private static function envolver(\DOMNode $nodo): \DOMNode {
        $caja = $nodo->ownerDocument->createElement('div');
        $caja->appendChild($nodo->cloneNode(true));
        return $caja;
    }

    /**
     * Tabla con bordes simples; la fila de encabezado en negrita y sombreada.
     *
     * @param \DOMElement $tabla
     * @return string
     */
    private static function w_tabla(\DOMElement $tabla): string {
        $filas = [];
        $cols = 0;
        foreach ($tabla->getElementsByTagName('tr') as $tr) {
            $celdas = [];
            foreach ($tr->childNodes as $td) {
                if ($td instanceof \DOMElement && in_array(strtolower($td->nodeName), ['td', 'th'], true)) {
                    $celdas[] = $td;
                }
            }
            $cols = max($cols, count($celdas));
            $filas[] = $celdas;
        }
        if (!$filas || !$cols) {
            return '';
        }
        // Diagrama de cajas y flechas (diagrama::render_html): columnas de flecha angostas y bordes solo en las cajas.
        $diagrama = strpos(' ' . $tabla->getAttribute('class') . ' ', ' m2-diagrama ') !== false;
        $ancho = (int) floor(9360 / $cols);
        $anchos = array_fill(0, $cols, $ancho);
        if ($diagrama) {
            $flechas = intdiv($cols, 2);
            $cajas = $cols - $flechas;
            for ($c = 0; $c < $cols; $c++) {
                $anchos[$c] = $c % 2 === 1 ? 640 : (int) floor((9360 - 640 * $flechas) / max(1, $cajas));
            }
        }
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>';
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $b) {
            $xml .= '<w:' . $b . ' w:val="' . ($diagrama ? 'nil' : 'single') . '" w:sz="4" w:space="0" w:color="A6B4C0"/>';
        }
        $xml .= '</w:tblBorders><w:tblCellMar><w:left w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/>'
            . '</w:tblCellMar></w:tblPr><w:tblGrid>';
        foreach ($anchos as $a) {
            $xml .= '<w:gridCol w:w="' . $a . '"/>';
        }
        $xml .= '</w:tblGrid>';
        foreach ($filas as $celdas) {
            $xml .= '<w:tr>';
            for ($c = 0; $c < $cols; $c++) {
                $td = $celdas[$c] ?? null;
                $th = $td && strtolower($td->nodeName) === 'th';
                $estilo = $td ? self::w_celda($td) : ['fondo' => '', 'borde' => '', 'jc' => '', 'color' => ''];
                $flecha = $td && preg_match('/^\s*[→←↑↓]\s*$/u', $td->textContent);
                $fmt = ($th ? ['b' => true] : []) + ($estilo['jc'] !== '' ? ['jc' => $estilo['jc']] : [])
                    + ($flecha ? ['sz' => 36, 'b' => true, 'color' => $estilo['color'] ?: '20639B'] : []);
                $contenido = $td ? self::w_bloques($td, 0, $fmt) : '';
                $fondo = $estilo['fondo'] !== '' ? $estilo['fondo'] : ($th ? 'E8F0F7' : '');
                $bordes = '';
                if ($estilo['borde'] !== '') {
                    $bordes = '<w:tcBorders>';
                    foreach (['top', 'left', 'bottom', 'right'] as $b) {
                        $bordes .= '<w:' . $b . ' w:val="single" w:sz="8" w:space="0" w:color="' . $estilo['borde'] . '"/>';
                    }
                    $bordes .= '</w:tcBorders>';
                }
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $anchos[$c] . '" w:type="dxa"/>' . $bordes
                    . ($fondo !== '' ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $fondo . '"/>' : '')
                    . ($diagrama ? '<w:vAlign w:val="center"/>' : '') . '</w:tcPr>'
                    . ($contenido !== '' ? $contenido : '<w:p/>') . '</w:tc>';
            }
            $xml .= '</w:tr>';
        }
        return $xml . '</w:tbl><w:p/>';
    }

    /**
     * Estilo de una celda de tabla, de su atributo style y bgcolor: color de fondo, borde, alineacion y color del texto.
     * Funcion pura.
     *
     * @param \DOMElement $td
     * @return array fondo, borde, color (hex de seis cifras sin «#», o ''), jc (center|right|'').
     */
    public static function w_celda(\DOMElement $td): array {
        $style = strtolower($td->getAttribute('style'));
        $hex = static function(string $c): string {
            $c = ltrim(trim($c), '#');
            if (strlen($c) === 3) {
                $c = $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
            }
            return preg_match('/^[0-9a-f]{6}$/', $c) ? strtoupper($c) : '';
        };
        $fondo = preg_match('/background(?:-color)?\s*:\s*(#[0-9a-f]{3,6})\b/', $style, $m) ? $hex($m[1]) : '';
        $fondo = $fondo !== '' ? $fondo : $hex($td->getAttribute('bgcolor'));
        $borde = preg_match('/(?<![a-z-])border\s*:[^;]*?(#[0-9a-f]{3,6})\b/', $style, $m) ? $hex($m[1]) : '';
        $color = preg_match('/(?<![a-z-])color\s*:\s*(#[0-9a-f]{3,6})\b/', $style, $m) ? $hex($m[1]) : '';
        $jc = preg_match('/text-align\s*:\s*(center|right)/', $style, $m) ? $m[1] : '';
        return ['fondo' => $fondo, 'borde' => $borde, 'color' => $color, 'jc' => $jc];
    }

    // ------------------------------------------------------------------------------------------
    // Base de datos.
    // ------------------------------------------------------------------------------------------

    /**
     * Productos del diseno en uso de la persona en el curso.
     *
     * @param int $userid
     * @param int $courseid
     * @return \stdClass[] etapa => fila.
     */
    public static function get_all(int $userid, int $courseid): array {
        $ficha = exante::get_ficha($userid, $courseid);
        return $ficha ? self::get_by_ficha((int) $ficha->id) : [];
    }

    /**
     * Productos de un diseno (cada ficha tiene sus propias etapas).
     *
     * @param int $fichaid
     * @return \stdClass[] etapa => fila.
     */
    public static function get_by_ficha(int $fichaid): array {
        global $DB;
        $salida = [];
        foreach ($DB->get_records('local_chatbot_diseno', ['fichaid' => $fichaid]) as $fila) {
            if ($fila->generacion === exante::PROCESANDO && $fila->timemodified < time() - self::max_seconds((int) $fila->etapa)) {
                $fila->generacion = exante::ERROR;
                $fila->error = 'tiempo';
                $DB->update_record('local_chatbot_diseno', (object) ['id' => $fila->id, 'generacion' => $fila->generacion,
                    'error' => $fila->error, 'timemodified' => time()]);
            }
            $salida[(int) $fila->etapa] = $fila;
        }
        return $salida;
    }

    /**
     * Etapas aceptadas.
     *
     * @param \stdClass[] $filas
     * @return int[]
     */
    public static function accepted(array $filas): array {
        $salida = [];
        $huella = self::stages_fingerprint($filas);
        foreach ($filas as $n => $fila) {
            if ($fila->estado !== self::ACEPTADO) {
                continue;
            }
            // La consolidacion cuenta solo si las etapas no cambiaron despues de confirmarla.
            if ((int) $n === self::CONSOLIDACION && (string) $fila->huella !== $huella) {
                continue;
            }
            $salida[] = (int) $n;
        }
        sort($salida);
        return $salida;
    }

    /**
     * Fila de una etapa del diseno en uso (la crea vacia si no existe).
     *
     * @param int $userid
     * @param int $courseid
     * @param int $etapa
     * @return \stdClass
     */
    private static function row(int $userid, int $courseid, int $etapa): \stdClass {
        global $DB;
        $ficha = exante::get_ficha($userid, $courseid);
        if (!$ficha) {
            throw new \moodle_exception('invalidrecord', 'error', '', 'local_chatbot_exante');
        }
        $fila = $DB->get_record('local_chatbot_diseno', ['fichaid' => $ficha->id, 'etapa' => $etapa]);
        if ($fila) {
            return $fila;
        }
        $now = time();
        $fila = (object) ['fichaid' => (int) $ficha->id, 'userid' => $userid, 'courseid' => $courseid, 'etapa' => $etapa,
            'contenido' => null, 'estado' => self::BORRADOR, 'generacion' => '', 'timecreated' => $now, 'timemodified' => $now];
        $fila->id = $DB->insert_record('local_chatbot_diseno', $fila);
        return $fila;
    }

    /**
     * Pide al asistente la propuesta de la etapa (o sus ajustes). Queda pendiente; la pagina la ejecuta.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $etapa
     * @param int $validacionid Validacion de la Fase 1 en que se basa.
     * @param string $ajustes
     * @return int id de la fila.
     */
    public static function request(int $userid, int $courseid, int $etapa, int $validacionid, string $ajustes = ''): int {
        global $DB;
        $fila = self::row($userid, $courseid, $etapa);
        if (in_array($fila->generacion, [exante::PENDIENTE, exante::PROCESANDO], true)) {
            return (int) $fila->id;
        }
        // Cada pedido con ajustes es una ronda de ajuste de la etapa (indicador «Iteraciones por etapa» del tablero).
        $rondas = (int) ($fila->rondas ?? 0) + (trim($ajustes) !== '' ? 1 : 0);
        $DB->update_record('local_chatbot_diseno', (object) ['id' => $fila->id, 'generacion' => exante::PENDIENTE,
            'ajustes' => \core_text::substr(trim($ajustes), 0, 4000), 'validacionid' => $validacionid, 'error' => null,
            'rondas' => $rondas, 'timemodified' => time()]);
        return (int) $fila->id;
    }

    /**
     * Ejecuta una generacion pendiente. Corre una sola vez aunque la pagina la pida dos veces.
     *
     * @param int $id
     * @param int $userid
     * @return string Estado de la generacion.
     */
    public static function run(int $id, int $userid): string {
        global $DB;
        $candado = \core\lock\lock_config::get_lock_factory('local_chatbot_diseno')->get_lock('gen' . $id, 0);
        if (!$candado) {
            return exante::PROCESANDO;
        }
        try {
            $fila = $DB->get_record('local_chatbot_diseno', ['id' => $id, 'userid' => $userid]);
            if (!$fila || $fila->generacion !== exante::PENDIENTE) {
                return $fila ? (string) $fila->generacion : exante::ERROR;
            }
            $DB->update_record('local_chatbot_diseno', (object) ['id' => $id, 'generacion' => exante::PROCESANDO,
                'timemodified' => time()]);
            [$estado, $campos] = self::generate($fila);
            $DB->update_record('local_chatbot_diseno', (object) (['id' => $id, 'generacion' => $estado,
                'timemodified' => time()] + $campos));
            return $estado;
        } finally {
            $candado->release();
        }
    }

    /**
     * Confirma el diseno consolidado (etapa 7): guarda el documento y la huella de las etapas que lo forman.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $html
     * @param string $huella
     * @return void
     */
    public static function confirm_consolidation(int $userid, int $courseid, string $html, string $huella,
            ?string $promptversion = null): void {
        global $DB;
        $fila = self::row($userid, $courseid, self::CONSOLIDACION);
        // promptversion queda solo si lo confirmado es la consolidacion del asistente (para mostrarlo asi).
        $DB->update_record('local_chatbot_diseno', (object) ['id' => $fila->id, 'contenido' => $html, 'huella' => $huella,
            'estado' => self::ACEPTADO, 'promptversion' => $promptversion, 'timeaccepted' => time(), 'timemodified' => time()]);
    }

    /**
     * La consolidacion que se muestra y se confirma: la guardada (del asistente o ya confirmada) si corresponde a las
     * etapas de hoy; si no, la completa por codigo. Funcion pura.
     *
     * @param \stdClass|null $fila Fila de la etapa 7.
     * @param string[] $contenidos Productos de las etapas 1 a 6 (HTML).
     * @param string $huella Huella de las etapas de hoy (stages_fingerprint()).
     * @return array [html, si es del asistente (bool)].
     */
    public static function consolidation_shown(?\stdClass $fila, array $contenidos, string $huella): array {
        if ($fila && trim((string) $fila->contenido) !== '' && (string) $fila->huella === $huella) {
            // La del asistente se completa con las secciones de cierre que haya omitido (ver complete_consolidation()).
            return [self::complete_consolidation((string) $fila->contenido, $contenidos), !empty($fila->promptversion)];
        }
        return [self::consolidate($contenidos), false];
    }

    /**
     * Completa la consolidacion del asistente con lo que omitio de cada etapa: si una parte no trae el «Registro de vacíos
     * de información» o la «Verificación de calidad de la etapa» que si tiene el producto aceptado de esa etapa, se
     * copian textuales al final de la parte, con el nivel de titulo de sus secciones (revision del cliente del 02-11: el
     * documento final perdia la verificacion de las seis etapas, que el ejemplo del cliente trae). Funcion pura.
     *
     * @param string $html Consolidacion (HTML).
     * @param string[] $etapas Productos aceptados de las etapas 1 a 6 (HTML), por numero.
     * @param int $max Ultima parte que se completa (6 en el Modulo 2; el Modulo 3 usa 7).
     * @return string
     */
    public static function complete_consolidation(string $html, array $etapas, int $max = 6): string {
        preg_match_all('#<h([1-6])\b[^>]*>(.*?)</h\1>#isu', $html, $t, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $partes = [];
        foreach ($t as $i => $x) {
            $texto = trim(html_entity_decode(strip_tags($x[2][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ((int) $x[1][0] <= 3 && preg_match('/^(?:parte|etapa)\s+(\d+)\b/iu', $texto, $m) && (int) $m[1] >= 1
                    && (int) $m[1] <= $max && !isset($partes[(int) $m[1]])) {
                $partes[(int) $m[1]] = ['i' => $i, 'nivel' => (int) $x[1][0]];
            }
        }
        $cambios = [];
        foreach ($partes as $n => $p) {
            // La parte llega hasta la parte siguiente (o el final): una etapa puede traer su verificacion y sus referencias al
            // mismo nivel que su titulo. Lo que falte va antes de sus «Referencias consultadas» (las de la etapa o las
            // generales del final), o al final de la parte; con el nivel de titulo de sus secciones.
            $fin = strlen($html);
            foreach ($partes as $otra) {
                if ($otra['i'] > $p['i'] && $t[$otra['i']][0][1] < $fin) {
                    $fin = $t[$otra['i']][0][1];
                }
            }
            $sub = 0;
            $antes = $fin;
            for ($j = $p['i'] + 1; $j < count($t) && $t[$j][0][1] < $fin; $j++) {
                $nivel = (int) $t[$j][1][0];
                if ($nivel > $p['nivel']) {
                    $sub = $sub ? min($sub, $nivel) : $nivel;
                }
                if ($antes === $fin && preg_match('/referencias/', self::plain_lower(strip_tags($t[$j][2][0])))) {
                    $antes = $t[$j][0][1];
                }
            }
            $sub = $sub ?: $p['nivel'] + 1;
            $inicio = $t[$p['i']][0][1];
            $estructura = self::structure_text(substr($html, $inicio, $fin - $inicio));
            $agregar = '';
            foreach (['/vacios? (?:de informacion|y soluciones)|registro de vacios/', '/verificacion de (?:la )?calidad/'] as $clave) {
                if (preg_match($clave, $estructura)) {
                    continue;
                }
                $seccion = self::stage_section((string) ($etapas[$n] ?? ''), $clave);
                if ($seccion !== null) {
                    $agregar .= self::shift_headings($seccion[0], $sub - $seccion[1]);
                }
            }
            if ($agregar !== '') {
                $cambios[$antes] = ($cambios[$antes] ?? '') . $agregar;
            }
        }
        krsort($cambios);
        foreach ($cambios as $pos => $x) {
            $html = substr($html, 0, $pos) . $x . substr($html, $pos);
        }
        return $html;
    }

    /**
     * Seccion de un producto cuyo titulo coincide con el patron (en minusculas y sin tildes): el titulo y su contenido
     * hasta el siguiente titulo de su nivel o mayor. Funcion pura.
     *
     * @param string $html
     * @param string $patron
     * @return array|null [html, nivel del titulo]
     */
    private static function stage_section(string $html, string $patron): ?array {
        preg_match_all('#<h([1-6])\b[^>]*>(.*?)</h\1>#isu', $html, $t, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($t as $i => $x) {
            if (!preg_match($patron, self::plain_lower(html_entity_decode(strip_tags($x[2][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8')))) {
                continue;
            }
            $nivel = (int) $x[1][0];
            $fin = strlen($html);
            for ($j = $i + 1; $j < count($t); $j++) {
                if ((int) $t[$j][1][0] <= $nivel) {
                    $fin = $t[$j][0][1];
                    break;
                }
            }
            return [substr($html, $x[0][1], $fin - $x[0][1]), $nivel];
        }
        return null;
    }

    /**
     * Sube o baja los niveles de titulo de un trozo de HTML (entre h1 y h6). Funcion pura.
     *
     * @param string $html
     * @param int $delta
     * @return string
     */
    private static function shift_headings(string $html, int $delta): string {
        if ($delta === 0) {
            return $html;
        }
        return preg_replace_callback('#<(/?)h([1-6])\b#i', static function(array $m) use ($delta): string {
            return '<' . $m[1] . 'h' . max(1, min(6, (int) $m[2] + $delta));
        }, $html);
    }

    /**
     * Evaluacion vigente (etapa 8): lista y hecha sobre la consolidacion confirmada que sigue al dia.
     *
     * @param \stdClass[] $filas
     * @return array|null Resultado de parse_eval(), con 'fecha'.
     */
    public static function current_evaluation(array $filas): ?array {
        $c = $filas[self::CONSOLIDACION] ?? null;
        $e = $filas[self::EVALUACION] ?? null;
        if (!$c || !$e || !in_array(self::CONSOLIDACION, self::accepted($filas), true) || !$e->informe
                || (string) $e->huella !== (string) $c->huella) {
            return null;
        }
        $informe = json_decode((string) $e->informe, true);
        if (!is_array($informe)) {
            return null;
        }
        $informe['fecha'] = (int) $e->timemodified;
        return $informe;
    }

    /**
     * Llama al modelo con la ficha, la validacion, los adjuntos y las etapas aceptadas.
     *
     * @param \stdClass $fila
     * @return array [estado de la generacion, campos a guardar].
     */
    private static function generate(\stdClass $fila): array {
        global $DB;
        if (!ai\provider_factory::is_configured()) {
            return [exante::ERROR, ['error' => 'sinclave']];
        }
        $val = $DB->get_record('local_chatbot_exante_val', ['id' => $fila->validacionid, 'userid' => $fila->userid]);
        if (!$val) {
            return [exante::ERROR, ['error' => 'sinficha']];
        }
        $ficha = exante::clean((array) json_decode((string) $val->snapshot, true));
        $informe = (array) json_decode((string) $val->informe, true);
        // Resultado de la Etapa 0 para la Etapa 1 (Prompt Maestro del 30-10-2026).
        $informe['condicion'] = (string) $val->condicion;

        if ((int) $fila->etapa === self::EVALUACION) {
            return self::evaluate($fila, $ficha, (int) $val->fichaid, $informe);
        }
        if ((int) $fila->etapa === self::CONSOLIDACION) {
            return self::consolidate_ai($fila, $ficha);
        }

        $previas = [];
        $posteriores = [];
        // Las etapas del mismo diseno, aunque la persona haya cambiado de diseno mientras esto corria. Las siguientes ya
        // aceptadas van solo para el control de cambios entre etapas.
        foreach (self::get_by_ficha((int) $fila->fichaid) as $n => $otra) {
            if ($otra->estado === self::ACEPTADO && $otra->contenido && isset(self::etapas()[$n])) {
                if ($n < (int) $fila->etapa) {
                    $previas[$n] = html_to_text((string) $otra->contenido, 0, false);
                } else if ($n > (int) $fila->etapa) {
                    $posteriores[$n] = html_to_text((string) $otra->contenido, 0, false);
                }
            }
        }
        ksort($previas);
        ksort($posteriores);
        $control = ux::on('controlcambios');

        [$partes, $etiquetas] = exante::attachment_parts((int) $val->fichaid);
        $actual = $fila->contenido ? html_to_text((string) $fila->contenido, 0, false) : '';
        // Libreria del rol para el enfoque de competencias y busqueda web para la especialidad tecnica (30-10-2026).
        $libreria = exante::role_library('e' . (int) $fila->etapa, (string) ($ficha['nivel'] ?? ''));
        $buscar = exante::web_search_available();
        $prompt = self::build_prompt((int) $fila->etapa, pseudonymizer::scrub(exante::to_text($ficha)), $informe,
            $previas, $etiquetas, $actual, (string) $fila->ajustes, $libreria !== '', $buscar, $control, $posteriores,
            $control ? userdate(time(), '%d-%m-%Y') : '', ux::on('reglasconsistencia'), ux::on('reglasproduccion'),
            ux::on('auditoriam2'));
        $contenido = [['role' => 'user', 'parts' => exante::request_parts($prompt, $partes, $libreria,
            ux::on('libreriaruta'))]];

        $modelo = ai\provider_factory::get_configured_model();
        $resultado = exante::generate_steady($contenido, 240, $modelo, $buscar);
        if (isset($resultado['error'])) {
            return [exante::ERROR, ['error' => 'servicio']];
        }
        $markdown = self::clean_markdown(gemini_client::extract_text($resultado));
        if ($markdown === '') {
            return [exante::ERROR, ['error' => 'ilegible']];
        }
        $markdown .= self::sources_markdown(gemini_client::extract_sources($resultado));
        // Una propuesta nueva o ajustada vuelve a borrador: la persona la revisa y la acepta de nuevo.
        return [exante::LISTO, ['contenido' => self::product_html($markdown),
            'estado' => self::BORRADOR, 'modelo' => (string) $modelo, 'promptversion' => self::PROMPT_VERSION,
            'error' => null]];
    }

    /**
     * Etapa 7 con el asistente: consolida las seis etapas aceptadas en extenso, sin repetir campos comunes. Si la
     * respuesta llega cortada o mucho mas corta que las etapas, no se usa: queda la consolidacion completa por codigo.
     *
     * @param \stdClass $fila Fila de la etapa 7.
     * @return array [estado de la generacion, campos a guardar].
     */
    private static function consolidate_ai(\stdClass $fila, array $ficha = []): array {
        $filas = self::get_by_ficha((int) $fila->fichaid);
        $etapas = [];
        foreach (array_keys(self::etapas()) as $n) {
            if (empty($filas[$n]) || $filas[$n]->estado !== self::ACEPTADO || trim((string) $filas[$n]->contenido) === '') {
                return [exante::ERROR, ['error' => 'sinetapas']];
            }
            $etapas[$n] = (string) $filas[$n]->contenido;
        }
        $huella = self::stages_fingerprint($filas);
        $control = ux::on('controlcambios');
        $consistencia = ux::on('reglasconsistencia');
        $produccion = ux::on('reglasproduccion');
        $auditoria = ux::on('auditoriam2');

        $contenido = [['role' => 'user', 'parts' => [['text' => self::build_consolidation_prompt($etapas,
            $control, $consistencia, $produccion, $auditoria)]]]];
        $modelo = ai\provider_factory::get_configured_model();
        $resultado = exante::generate_steady($contenido, $auditoria ? 480 : 600, $modelo);
        if (isset($resultado['error'])) {
            return [exante::ERROR, ['error' => 'servicio']];
        }
        $markdown = self::clean_markdown(gemini_client::extract_text($resultado));
        $fin = $resultado['candidates'][0]['finishReason'] ?? null;
        $completa = $markdown !== '' && self::consolidation_complete($markdown, $etapas, $fin);
        if (!$completa && !($control && $auditoria)) {
            return [exante::ERROR, ['error' => 'incompleta']];
        }
        // Si la consolidacion larga se corto pero corre la auditoria independiente, conservamos las 6 etapas completas
        // por codigo y adjuntamos el anexo de auditoria verificada para no bloquear a la persona facilitadora.
        $docHtml = $completa ? self::product_html($markdown) : self::consolidate($etapas);

        if ($control && $auditoria) {
            $hallazgosPhp = self::design_audit_facts($etapas, $ficha);
            $auditPrompt = self::build_audit_prompt($etapas, $ficha, $consistencia, $produccion, $hallazgosPhp);
            $anexoHtml = '';
            for ($intento = 0; $intento < 2; $intento++) {
                $resAudit = exante::generate_steady([['role' => 'user', 'parts' => [['text' => $auditPrompt]]]],
                    240, $modelo);
                if (isset($resAudit['error'])) {
                    continue;
                }
                $mdAudit = self::clean_markdown(gemini_client::extract_text($resAudit));
                if ($mdAudit !== '') {
                    $candidato = self::product_html($mdAudit);
                    if (self::has_consistency_annex($candidato)) {
                        $anexoHtml = $candidato;
                        break;
                    }
                }
            }
            if ($anexoHtml === '') {
                return [exante::ERROR, ['error' => 'auditoria']];
            }
            $docHtml = self::replace_consistency_annex($docHtml, $anexoHtml);
        }

        // Queda como borrador: la persona la revisa y la confirma.
        return [exante::LISTO, ['contenido' => $docHtml,
            'estado' => self::BORRADOR, 'huella' => $huella, 'modelo' => (string) $modelo,
            'promptversion' => self::PROMPT_VERSION, 'error' => null]];
    }

    /**
     * Etapa 8: el modelo aplica la rubrica al diseno consolidado confirmado.
     *
     * @param \stdClass $fila
     * @param array $ficha
     * @param int $fichaid
     * @param array $validacion Informe de la validacion de la ficha.
     * @return array [estado de la generacion, campos a guardar].
     */
    private static function evaluate(\stdClass $fila, array $ficha, int $fichaid, array $validacion = []): array {
        global $DB;
        $c = $DB->get_record('local_chatbot_diseno', ['fichaid' => $fila->fichaid, 'etapa' => self::CONSOLIDACION]);
        if (!$c || $c->estado !== self::ACEPTADO || !$c->contenido) {
            return [exante::ERROR, ['error' => 'sinconsolidado']];
        }
        [$partes, $etiquetas] = exante::attachment_parts($fichaid);
        $libreria = exante::role_library('e8', (string) ($ficha['nivel'] ?? ''));
        $buscar = exante::web_search_available();
        $inconsistencias = [];
        if (ux::on('controlcambios')) {
            $etapasHtml = array_map(static fn($f) => (string) $f->contenido,
                array_intersect_key(self::get_by_ficha((int) $fila->fichaid), self::etapas()));
            $inconsistencias = self::accepted_inconsistencies($etapasHtml);
            if (ux::on('auditoriam2')) {
                $inconsistencias = array_values(array_unique(array_merge(
                    $inconsistencias,
                    self::inconsistencies(self::normalize_product((string) $c->contenido)),
                    self::design_audit_facts($etapasHtml, $ficha)
                )));
            }
        }
        $prompt = self::build_eval_prompt(html_to_text((string) $c->contenido, 0, false),
            pseudonymizer::scrub(exante::to_text($ficha)), $etiquetas, $libreria !== '', $buscar, $validacion,
            ux::on('controlcambios'), $inconsistencias);
        $contenido = [['role' => 'user', 'parts' => exante::request_parts($prompt, $partes, $libreria,
            ux::on('libreriaruta'))]];
        $modelo = ai\provider_factory::get_configured_model();
        $informe = null;
        $primero = null;
        // Un segundo intento solo si la respuesta no se pudo leer: la rubrica va entera o no va.
        for ($intento = 0; $intento < 2 && $informe === null; $intento++) {
            // El segundo intento va sin busqueda web, por si la respuesta con fuentes no vino como JSON limpio.
            $resultado = exante::generate_steady($contenido, 240, $modelo, $buscar && $intento === 0);
            if (isset($resultado['error'])) {
                if ($primero !== null) {
                    break;
                }
                return [exante::ERROR, ['error' => 'servicio']];
            }
            $informe = self::parse_eval(gemini_client::extract_text($resultado), ux::on('iaapoyo'),
                self::rubric_limits($validacion));
            // Con menos de 2 recomendaciones se pide una vez mas; si sigue igual, se conserva la lectura valida.
            if ($informe !== null && count($informe['recomendaciones']) < self::RECOMENDACIONES_MIN && $intento === 0) {
                $primero = $informe;
                $informe = null;
            }
        }
        $informe = $informe ?? $primero;
        if ($informe === null) {
            return [exante::ERROR, ['error' => 'ilegible']];
        }
        return [exante::LISTO, ['informe' => json_encode($informe, JSON_UNESCAPED_UNICODE), 'huella' => $c->huella,
            'modelo' => (string) $modelo, 'promptversion' => self::PROMPT_VERSION, 'error' => null]];
    }

    /**
     * Guarda lo que la persona edito; con $aceptar, ademas la etapa queda aceptada.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $etapa
     * @param string $html
     * @param bool $aceptar
     * @return void
     */
    public static function save(int $userid, int $courseid, int $etapa, string $html, bool $aceptar): void {
        global $DB;
        $fila = self::row($userid, $courseid, $etapa);
        $campos = ['id' => $fila->id, 'contenido' => clean_text($html, FORMAT_HTML), 'timemodified' => time()];
        if ($aceptar) {
            $campos['estado'] = self::ACEPTADO;
            $campos['timeaccepted'] = time();
        }
        $DB->update_record('local_chatbot_diseno', (object) $campos);
    }
}
