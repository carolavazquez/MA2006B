# MA2006B
Repositorio para reto de la materia Uso de álgebras modernas para ciberseguridad y criptografía GRUPO 604

Integrantes:

Carola Vázquez Arojna			A011754562
Iker Alvarez Bandrés			A01282986
Juan Pablo Valdes Cardenas 		A00839013
Andres Vivanco Treviño        A01723358
Juan Fernando González        A01571586


# SIGAM — Sistema Institucional de Gestión para Casa Monarca

Plataforma web institucional desarrollada para Casa Monarca, Ayuda Humanitaria al Migrante, A.B.P., que integra gestión de identidades, infraestructura PKI, gestión de documentos firmados y comunicación interna/externa criptográficamente verificable.

## Características principales

- Gestión de identidades con cuatro niveles jerárquicos (Administrador, Coordinador, Operativo, Consulta/Externos) y permisos individuales por override
- Matrículas institucionales asignadas por área (HUM, PSI, LEG, COM, ALM, TI)
- Infraestructura PKI completa: emisión, renovación, revocación y verificación de certificados X.509 firmados con SHA-256
- Firma digital RSA-2048 sobre documentos, anuncios, mensajes y comunicaciones externas mediante WebCrypto en el navegador
- Principio zero-knowledge: las llaves privadas de los usuarios nunca se almacenan en el servidor
- Comunicación interna con anuncios firmados obligatoriamente y mensajes directos con firma opcional
- Canal seguro de comunicación externa con verificación criptográfica de adjuntos en tres capas (extensión, MIME, magic bytes)
- Correos electrónicos salientes firmados con S/MIME (PKCS#7 detached) conforme al RFC 5751
- Verificador público de firmas para destinatarios externos sin necesidad de instalar la CA institucional
- Mecanismo de cuenta espejo para recuperación administrativa
- Bitácora institucional inmutable de todas las operaciones sensibles
- Validación periódica de integridad de adjuntos mediante script cron diario con alertas automáticas

## Requisitos de instalación

- Docker y Docker Compose instalados localmente
- Git para clonar el repositorio
- Navegador moderno con soporte para Web Crypto API (Chrome, Firefox, Safari, Edge en versiones recientes)
- Cuenta en Mailtrap u otro servidor SMTP para pruebas de envío de correos
- Mínimo 2 GB de RAM disponibles para los contenedores
- Puerto 8080 disponible en el equipo local

Para despliegue en producción se requiere hosting compartido con soporte para PHP 8.2, MySQL 8, extensiones OpenSSL y ZipArchive de PHP habilitadas.

## Instalación

1. Clonar el repositorio:

```bash
git clone https://github.com/carolavazquez/MA2006B.git
cd MA2006B
```

2. Crear el archivo `.env` en la raíz del proyecto a partir del ejemplo `.env.example`:

```bash
cp .env.example .env
```

3. Levantar los contenedores Docker:

```bash
docker compose up -d
```

4. Generar el certificado del sistema para firma S/MIME de correos:

```bash
docker compose exec php php /var/www/html/setup/generar_cert_sistema.php
```

5. Inicializar la base de datos y crear el administrador inicial:

```bash
curl http://localhost:8080/seed.php
```

El script devolverá las credenciales del administrador y el link de descarga de su certificado. Guardar ambos inmediatamente.

## Configuración

El archivo `.env` contiene las variables de configuración del sistema:

```env
APP_URL=http://localhost:8080

DB_HOST=mysql
DB_NAME=casa_monarca
DB_USER=root
DB_PASS=tu_contraseña

JWT_SECRET=cadena_secreta_para_firmar_tokens

MAIL_HOST=sandbox.smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=tu_usuario_mailtrap
MAIL_PASSWORD=tu_contraseña_mailtrap
MAIL_FROM_EMAIL=no-reply@casamonarca.org
MAIL_FROM_NAME=Casa Monarca
```

Para el despliegue en producción es necesario actualizar `APP_URL` con el dominio real, configurar las credenciales del servidor SMTP institucional y regenerar el `JWT_SECRET` con un valor aleatorio de al menos 64 caracteres.

## Uso básico

Una vez instalado, el sistema queda disponible en `http://localhost:8080`.

**Páginas principales:**

- `/frontend.html` — interfaz principal del sistema (requiere login)
- `/verificar.html` — verificador público de firmas S/MIME (sin login)
- `/externo.html?id={uuid}` — canal de comunicación para externos (sin login)
- `/reset-password.html?token={token}` — restablecimiento de contraseña
- `/terminos.html` — términos y condiciones

**Flujo típico:**

1. El administrador inicia sesión con las credenciales generadas en el seed
2. Genera su certificado personal desde la sección Certificados
3. Crea colaboradores adicionales desde Colaboradores
4. Activa cada colaborador para que reciba sus credenciales por correo
5. Emite certificados para los colaboradores que necesiten firmar
6. Los colaboradores acceden a la plataforma con sus credenciales y descargan su llave privada
7. Las operaciones que requieren firma solicitan el archivo `.key` del usuario y firman localmente

**Cron diario opcional para verificación de integridad de adjuntos:**

```
0 9 * * * docker compose exec -T php php /var/www/html/cron/verificar_hashes_adjuntos.php
```

## Estructura del proyecto

```
MA2006B/
├── docker-compose.yml
├── .env.example
├── LICENSE
├── README.md
├── docs/
│   ├── reportes/
│   └── manual_usuario.pdf
└── src/
    ├── api/
    │   └── index.php                  # Router principal de la API
    ├── config/
    │   ├── database.php
    │   ├── env.php
    │   ├── mail.php                   # Mailer con firma S/MIME
    │   └── templates.php              # Plantillas de correos
    ├── controllers/
    │   ├── AuthController.php
    │   ├── UsuariosController.php
    │   ├── CertificadosController.php
    │   ├── DocumentosController.php
    │   ├── CarpetasController.php
    │   ├── AnunciosController.php
    │   ├── MensajesController.php
    │   ├── ComunicacionesExternasController.php
    │   ├── CorreosController.php
    │   ├── VerificacionPublicaController.php
    │   ├── AuditController.php
    │   └── RecuperacionController.php
    ├── middleware/
    │   ├── rbac.php                   # Control de acceso por niveles
    │   ├── mensajeria.php             # Reglas de comunicación
    │   └── validacion_archivos.php    # Validación de adjuntos en tres capas
    ├── setup/
    │   └── generar_cert_sistema.php
    ├── cron/
    │   ├── avisos_expiracion.php
    │   └── verificar_hashes_adjuntos.php
    ├── frontend.html                  # SPA principal
    ├── verificar.html                 # Verificador público
    ├── externo.html                   # Canal de externos
    ├── reset-password.html
    ├── terminos.html
    └── seed.php                       # Inicialización del sistema
```


## Contribuciones

Antes de proponer modificaciones al proyecto, se recomienda:

1. Clonar el repositorio y levantar el sistema localmente siguiendo las instrucciones de instalación
2. Revisar los reportes técnicos en la carpeta `docs/reportes/` para entender las decisiones de diseño tomadas
3. Leer el código de los controladores en `src/controllers/` para familiarizarse con la arquitectura de capas
4. Revisar el middleware en `src/middleware/` para comprender el modelo de autorización y validación
5. Probar los flujos críticos desde la interfaz: creación de usuarios, emisión de certificados, firma de documentos, envío de comunicaciones

**Flujo de contribución sugerido:**

1. Crear una rama nueva a partir de `main` con un nombre descriptivo del cambio propuesto
2. Aplicar los cambios manteniendo el estilo de código existente
3. Verificar que las pruebas básicas siguen pasando
4. Documentar el cambio en un commit con mensaje descriptivo siguiendo el formato `tipo: descripción`
5. Abrir un pull request hacia `main` con explicación del cambio, justificación y pasos para reproducir las pruebas

Cualquier cambio que afecte la lógica criptográfica, el modelo de control de acceso o la persistencia de información sensible requiere revisión técnica adicional antes de integrarse.

## Pruebas básicas

**Verificación del estado del sistema:**

```bash
docker compose ps
```

Deben aparecer los tres contenedores (php, mysql, phpmyadmin) con estado `Up`.

**Prueba de conectividad a la API:**

```bash
curl http://localhost:8080/api/auth/ping
```

**Prueba del cron de verificación de integridad:**

```bash
docker compose exec php php /var/www/html/cron/verificar_hashes_adjuntos.php
```

**Prueba manual del flujo completo:**

1. Iniciar sesión como administrador
2. Crear un colaborador de nivel 2 en cualquier área
3. Activarlo y confirmar que recibe credenciales por correo en Mailtrap
4. Emitir certificado para ese colaborador
5. Iniciar sesión como el nuevo colaborador
6. Publicar un anuncio firmado con su `.key`
7. Verificar que el anuncio aparece con badge "✓ Firmado"
8. Confirmar que la bitácora registró las operaciones

## Licencia de uso

Este proyecto se distribuye bajo licencia MIT. Ver el archivo [LICENSE](LICENSE) para los términos completos.

## Contacto

Equipo desarrollador — Instituto Tecnológico y de Estudios Superiores de Monterrey
Grupo 604, Equipo 4

- Carola Vázquez Arjona — A01754562
- Iker Álvarez Bandrés — A01282986
- Juan Pablo Valdés Cárdenas — A00839013
- Andrés Vivanco Treviño — A01723358
- Juan Fernando González — A01571586
- Carlos Eduardo Ramos Martínez — A01286025

Profesores asesores: Raúl Gómez, Anas Wajid, Alberto F. Martínez

Socio formador: Casa Monarca, Ayuda Humanitaria al Migrante, A.B.P.
