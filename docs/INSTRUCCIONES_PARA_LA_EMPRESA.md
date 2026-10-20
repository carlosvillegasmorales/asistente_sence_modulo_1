# Guía de instalación — Asistente IA (Módulo 1)

**Release:** M1-v1.1.0
**Componente:** plugin local de Moodle (`local_chatbot`)
**Compatibilidad:** Moodle 4.3 o superior
**Requisitos de PHP:** extensiones `curl` y `fileinfo`

Asistente IA es el asistente y el conjunto de instrumentos del **Módulo 1**: perfilamiento, diagnóstico de brechas, Plan Personal de Desarrollo, proyecto integrador con rúbrica y cierre. El asistente funciona en tres modos —Tutor, Coach y Evaluador— y cada modo accede solo a los datos que su función necesita.

> **Sobre el nombre de la carpeta:** el producto se llama Asistente IA, pero el componente interno es `local_chatbot`. La carpeta que se instala tiene que llamarse `chatbot`, no `asistente` ni ninguna otra cosa: Moodle exige que el nombre de la carpeta coincida con el del componente, y si no coincide el plugin no carga.

---

## 1. Qué se entrega

Un archivo comprimido: `asistente_ia_M1-v1.1.0.zip`. Dentro trae la carpeta `chatbot/` con la estructura estándar de un plugin local de Moodle.

El nombre del archivo lleva el módulo y la versión. Si en el futuro reportan un problema, ese dato es lo primero que se pregunta.

## 2. Instalación

### Opción A — Interfaz web (recomendada)

1. Ingrese a Moodle con una cuenta de **administrador**.
2. Vaya a **Administración del sitio → Extensiones → Instalar complementos**.
3. Arrastre `asistente_ia_M1-v1.1.0.zip` a la caja de subida.
4. Verifique que el tipo detectado sea **Plugin local (local)**.
5. Presione **Instalar complemento desde el archivo ZIP** y siga los pasos en pantalla.

Moodle crea las tablas del plugin durante este paso. No hay que ejecutar ningún script SQL a mano.

### Opción B — Servidor (SSH / FTP)

1. Suba el archivo al servidor.
2. Descomprímalo dentro de la carpeta `local/` de su instalación de Moodle.
   La ruta final debe quedar en `moodle/local/chatbot`.
3. Ajuste permisos:
   ```bash
   sudo chown -R www-data:www-data /var/www/moodle/local/chatbot
   sudo chmod -R 0755 /var/www/moodle/local/chatbot
   ```
4. Ejecute la actualización:
   ```bash
   php /var/www/moodle/admin/cli/upgrade.php
   ```

---

## 3. Configuración

Al terminar la instalación, Moodle lleva a la página de ajustes. También se llega por **Administración del sitio → Extensiones → Extensiones locales → Asistente IA**.

### 3.1 Proveedor de IA (obligatorio)

El plugin no responde sin esto. Elija un proveedor y cargue su clave:

| Proveedor | Qué se necesita |
|---|---|
| Google Gemini | API Key. Es el único probado de punta a punta. |
| Anthropic Claude | API Key |
| OpenAI | API Key |
| Azure AI Foundry | Endpoint, deployment, versión de API y API Key |

El campo **Modelo** es opcional: si se deja vacío, se usa el nivel económico del proveedor.

Las claves se guardan como contraseñas enmascaradas: no se muestran en pantalla después de guardarlas.

### 3.2 Contacto de soporte

**Contacto de soporte** y **URL de soporte** son los datos que el asistente entrega cuando una consulta excede su alcance. Conviene completarlos antes de abrir el módulo a participantes.

### 3.3 Instrumento del Módulo 1

En **Administración del sitio → Extensiones → Extensiones locales → Instrumento del Módulo 1** se configuran el diagnóstico, la rúbrica del proyecto integrador y los prompts. Vienen precargados con la versión del documento de arquitectura, así que el módulo funciona sin tocar nada.

### 3.4 Recursos internos del módulo

En **Recursos internos del módulo** se suben los PDF que el asistente usa como fuente en modo Tutor. **Se suben acá, no vienen dentro del plugin.**

### 3.5 Certificado CA para las llamadas de IA

Si las respuestas fallan con un error de conexión, la causa más probable es el certificado CA de cURL/OpenSSL. Verifique `curl.cainfo` y `openssl.cafile` en `php.ini`. En Linux normalmente ya está resuelto; en Windows no siempre se detecta solo.

---

## 4. Validación posterior al despliegue

1. Purgue las cachés: **Administración del sitio → Desarrollo → Purgar todas las cachés**, o por consola:
   ```bash
   php /var/www/moodle/admin/cli/purge_caches.php
   ```
2. Recargue Moodle. Debe aparecer el ícono flotante en la esquina inferior.
3. Abra el asistente y haga una consulta de prueba en modo **Tutor**. Si responde, el proveedor de IA quedó bien configurado.
4. Cambie a modo **Coach** y verifique que el selector de modo abre una conversación distinta.
5. Entre a **Administración del sitio → Extensiones → Extensiones locales → Indicadores del Módulo 1**. Debe cargar aunque esté vacío.

Si el paso 3 falla, revise en este orden: la API Key, luego el certificado CA (3.5), luego la conectividad de salida del servidor hacia el proveedor.

---

## 5. Permisos

El plugin define cuatro capacidades, todas a nivel de sistema. Vienen con estos valores por defecto y se revisan en **Administración del sitio → Usuarios → Permisos → Definir roles**:

| Capacidad | Para qué | Roles que la tienen por defecto |
|---|---|---|
| `local/chatbot:use` | Usar el asistente y trabajar el propio recorrido | Usuario autenticado, Estudiante, Profesor, Profesor editor, Gestor |
| `local/chatbot:managekb` | Subir y eliminar los PDF del módulo | Gestor |
| `local/chatbot:manageinstrument` | Configurar diagnóstico, rúbrica y prompts | Gestor |
| `local/chatbot:viewanalytics` | Ver los indicadores agregados | Gestor |

`manageinstrument` está marcada con riesgo de configuración a propósito: define cómo la IA evalúa y recomienda a las personas. No la asigne a roles de curso.

Los indicadores son **agregados**: no exponen respuestas individuales de participantes.

---

## 6. Actualizaciones

Cada entrega llega como un zip nuevo con un release mayor. Se instala igual que la primera vez —Moodle detecta que el plugin ya existe y ejecuta solo los pasos de actualización pendientes— y **los datos de los participantes se conservan**.

Antes de actualizar en producción, respalde la base de datos. Es la recomendación estándar de Moodle para cualquier plugin, no una advertencia particular de este.
