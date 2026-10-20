# Moodle Proyecto Completo

Entorno completo del **Asistente de IA del Módulo 1** (SENCE / Interfases Consultores): Moodle, el plugin
`local_chatbot`, la infraestructura de despliegue, los documentos del cliente y las herramientas que generan
la matriz de requisitos y el manual de facilitadores.

> **Antes de tocar nada, lee [CLAUDE.md](CLAUDE.md):** arquitectura del plugin, trampas conocidas, regla de
> alcance (el asistente no califica ni certifica) y cómo se despliega a los dos servidores.

## Un solo repositorio

Esta es la **versión final del Módulo 1**: el proyecto completo en un solo repositorio, sin
historial previo. El plugin `local_chatbot` está **dentro**, en `moodle/public/local/chatbot/`, como archivos
normales: basta con clonar. (En el desarrollo original el plugin tiene su propio repositorio, `moodle-chatbot-ia`;
aquí no hace falta.)

## Versiones

- **Moodle:** 5.2.2 · **PHP:** 8.3 · **MariaDB:** 10.11
- **Plugin:** ver `moodle/public/local/chatbot/version.php` (`release`).

## Estructura

```
.
├── CLAUDE.md                 # guía de arquitectura y gotchas (leer primero)
├── .github/workflows/        # CI: higiene del repo (sin secretos, scripts compilan)
├── deploy/                   # Docker, plantillas de config y .env.example (sin claves)
├── docs/                     # SPEC de requisitos, estado de trabajo, manuales, informes
├── Documentos Cliente/       # documentos de requerimientos de Interfases
├── moodle/                   # Moodle; el plugin va en public/local/chatbot
├── tools/
│   ├── hooks/                # pre-commit anti-secretos y escaneo del árbol
│   ├── matriz/ manual/       # generadores de la matriz y del manual (datos en datos_v5.py)
│   ├── plan_pruebas/         # pruebas del flujo completo contra un VPS
│   ├── desplegar/            # despliegue del plugin (plantilla servidores.example.json)
│   ├── presentacion/         # generador de la presentación de arquitectura
│   └── operacion/            # scripts de los VPS: NO se versionan (llevan credenciales)
├── iniciar.bat / detener.bat # arranque y apagado del entorno local (Windows)
└── env/                      # base de datos, PHP y moodledata locales (ignorado)
```

## Instalación

### 1. Clonar

```bash
git config --global core.longpaths true      # Windows: sin esto fallan las rutas largas
git clone <URL-de-este-repositorio>
cd <carpeta>
```

### 2. Instalar el hook anti-secretos (obligatorio)

```bash
sh tools/hooks/instalar.sh
```

Instala el hook `pre-commit`. Bloquea contraseñas y tokens literales, claves
privadas, cadenas de conexión con credencial, `.env`, `config.php`, volcados y archivos de más de 5 MB.

### 3. Entorno local

1. Pide al administrador el archivo **`env.zip`** (MariaDB, PHP y moodledata; no está en GitHub por tamaño
   y seguridad) y descomprímelo dentro de la carpeta del proyecto: debe quedar `env/`.
2. Copia la plantilla de configuración y pon la clave de tu base local:
   ```bash
   cp moodle/config.php.example moodle/config.php   # config.php está ignorado
   ```
3. Inicia con `./iniciar.bat` (MariaDB en el 3307, PHP en el 8000) → <http://localhost:8000>.
   Apaga siempre con `./detener.bat`, nunca cerrando las ventanas a la fuerza.

## Credenciales: reglas

- **Nunca** se versionan claves, `.env`, `config.php` reales ni los scripts de `tools/operacion/`.
- Las plantillas llevan el sufijo `.example` (`.env.example`, `deploy/.env.example`,
  `deploy/config.php.example`, `moodle/config.php.example`).
- Los datos de conexión de los servidores se piden al administrador por un canal privado.

## Pruebas

El plugin no usa PHPUnit; su suite son los verificadores `cli/verificar_*.php`:

```bash
cd moodle/public/local/chatbot
for f in cli/verificar_*.php; do php $f; done
```

`verificar_puente.php` y `verificar_versionado.php` necesitan base de datos y solo corren en un servidor.
El CI corre los demás en cada push.

## Flujo de trabajo en Git

1. Nunca hagas commits directamente en `main`.
2. Crea una rama desde `main`: `git checkout main && git pull && git checkout -b feature/mi-cambio`.
3. Commits en español, citando el requisito del SPEC cuando aplique (`RU-TUT-007`).
4. Sube la rama y abre un **Pull Request** hacia `main`.
