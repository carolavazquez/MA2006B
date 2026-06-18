# TODO — Roadmap de mejoras (versión 2)

## Seguridad

- [ ] Implementar Transparent Data Encryption (TDE) sobre las tablas con información sensible
- [ ] Activar TLS en la conexión entre PHP y MySQL
- [ ] Cifrar el contenido de documentos y adjuntos con AES-256-GCM antes de almacenarlos
- [ ] Agregar passphrase opcional al archivo `.key` descargado por el usuario
- [ ] Implementar autenticación de doble factor (TOTP) para niveles 1 y 2

## Protección contra malware

- [ ] Integrar ClamAV como servicio adicional para escaneo de adjuntos
- [ ] Evaluar detección de JavaScript embebido en PDFs (peepdf / pdfid)
- [ ] Implementar previsualización de adjuntos en sandbox antes de descarga directa

## Funcionalidad pendiente

- [ ] Carpetas personales por colaborador
- [ ] Módulo de reportes y estadísticas operativas (consumiendo la bitácora)
- [ ] Centro de notificaciones in-app, complementario al correo
- [ ] Preferencias de notificación configurables por usuario (instantánea / resumen diario / ninguna)
- [ ] Contadores de mensajes no leídos en el sidebar (aprovechando `leido_hasta` ya existente en `mensajes_participantes`)
- [ ] Búsqueda full-text en documentos, anuncios y mensajes

## Infraestructura

- [ ] Migrar almacenamiento de adjuntos a object storage (S3 / MinIO / Backblaze B2)
- [ ] Evaluar Redis para cache de consultas frecuentes
- [ ] Backups automáticos cifrados en ubicación distinta al servidor principal
- [ ] Bloqueo preventivo automático cuando el certificado del administrador entra en ventana crítica de expiración

## Cumplimiento legal

- [ ] Flujo formal de derechos ARCO conforme a la LFPDPPP
- [ ] Política de retención y eliminación de datos por tipo de información
- [ ] Aviso de privacidad formal vinculado desde el sistema, con histórico de versiones

## Operación y continuidad

- [ ] Documentar plan formal de respuesta ante incidentes
- [ ] Establecer protocolo de capacitación periódica del personal
- [ ] Programar auditoría externa de seguridad anual
- [ ] Exportación de bitácora a PDF/CSV firmado para auditorías externas

## Reorganización técnica pendiente

- [ ] Mover los archivos entregables (`reporte_tecnico.pdf`, `reporte_ejecutivo.pdf`, manual de usuario, presentación) de `src/` a `docs/`
- [ ] Renombrar archivos con espacios, acentos y paréntesis a nombres sin caracteres especiales
- [ ] Implementar pruebas automatizadas con PHPUnit