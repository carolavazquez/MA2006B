# Changelog

Todos los cambios notables de este proyecto se documentan en este archivo.

## [2.0.0] — Etapa 2 — Junio 2026

### Agregado
- Módulo de gestión de documentos con carpetas por área y carpeta virtual "Sin clasificar"
- Firma digital de documentos mediante Web Crypto API (zero-knowledge)
- Verificación de integridad de adjuntos con hash SHA-256 y cron diario de validación
- Módulo de comunicación interna: anuncios institucionales (firma obligatoria) y mensajes directos (firma opcional)
- Middleware de reglas de mensajería por nivel y área
- Módulo de comunicación externa: canal de entrada para externos sin sesión (`externo.html`)
- Validación criptográfica de adjuntos en tres capas (extensión, MIME, magic bytes) más detección de macros en archivos Office
- Correos institucionales firmados con S/MIME, con soporte de adjuntos múltiples
- Verificador público de firmas S/MIME (`verificar.html`)
- Mecanismo de cuenta espejo para recuperación administrativa
- Reemisión de certificados vinculada a procedimientos de recuperación
- Matrículas institucionales automáticas por área (HUM, PSI, LEG, COM, ALM, TI)
- Página de términos y condiciones (`terminos.html`)
- Visualización de permisos RBAC con iconos en lugar de letras r/w/x/e
- Bitácora institucional consultable desde el frontend del administrador

### Corregido
- Botón "Nuevo documento" sin texto visible
- Imposibilidad de descargar adjuntos desde el modal de ver documento
- Sidebar mostraba la sección "Colaboradores" a nivel 2 sin permiso real
- Error de FK al crear documento con `carpeta_id` igual a `'sin-clasificar'`
- Mensajería bloqueada por requerir permiso de escritura en lugar de lectura
- `CorreosController.php` ausente en disco pese a estar documentado

### Cambiado
- Permiso requerido para mensajería ajustado de `w` a `r` en `iniciarHilo()` y `responder()`

## [1.0.0] — Etapa 1 

### Agregado
- Arquitectura inicial de tres capas (PHP 8.2 + Apache, MySQL 8, frontend vanilla JS)
- Módulo de gestión de identidades con cuatro niveles jerárquicos (Administrador, Coordinador, Operativo, Consulta/Externos)
- Control de acceso basado en roles (RBAC) con permisos por nivel y overrides individuales
- Infraestructura PKI: emisión, renovación, revocación y verificación de certificados X.509
- Autenticación con JWT (HS256) y contraseñas hasheadas con bcrypt
- Configuración de entorno Docker (PHP-Apache, MySQL, phpMyAdmin)
- Script de inicialización (`seed.php`) con creación del administrador y cuenta espejo