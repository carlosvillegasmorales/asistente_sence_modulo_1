# Asistente de IA — Módulo 1 · versión final

Estado del proyecto al **20-10-2026**: Moodle **5.2.2** con el plugin del asistente (`local_chatbot`) en
**M1-v1.16.11**, la misma versión que corre en los dos servidores (el propio y el del cliente).

Es el proyecto completo en un solo repositorio, sin historial previo. El plugin está **dentro**, en
`moodle/public/local/chatbot/`, como archivos normales: basta con clonar.

## Qué leer primero

1. **`README.md`**: instalación del entorno local, hook anti-secretos y reglas de credenciales.
2. **`CLAUDE.md`**: arquitectura del plugin, la regla que nunca se relaja (el asistente no califica ni certifica),
   trampas conocidas de Moodle y cómo se despliega.
3. **`docs/ESTADO_TRABAJO.md`**: qué se hizo, qué está pendiente y qué depende del cliente.
4. **`docs/Spec_Asistente_IA_para_IDE.md`**: los 111 requisitos con su estado. Se regenera desde
   `tools/matriz/datos_v5.py`; no se edita a mano.
5. **`moodle/public/local/chatbot/CHANGES.md`**: qué trae cada versión del plugin.

## Pruebas

No hay PHPUnit. La suite son los verificadores del plugin (748 casos, 44 celdas de la matriz de acceso y 917 claves
de idioma al 17-10-2026):

```bash
cd moodle/public/local/chatbot
for f in cli/verificar_*.php; do php $f; done
```

## Lo que este repositorio no trae, a propósito

- **Credenciales**: ni las de los servidores, ni la llave del proveedor de IA, ni las de la base de datos. Hay
  plantillas `*.example` (`.env.example`, `deploy/.env.example`, `deploy/config.php.example`,
  `moodle/config.php.example`).
- **Scripts de operación de los servidores** (`tools/operacion/`): llevan credenciales y se quedan con quien despliega.
- Para desarrollar, cada persona usa su propio Moodle local y su propia llave de IA.

## Cómo trabajar

- Una rama por cambio y pull request hacia `main`.
- Toda mejora visible nueva lleva su interruptor en `classes/ux.php`.
- Lógica nueva = función pura + caso en un `cli/verificar_*.php`.
- Todo en español: código de cara al cliente, commits y documentos.
