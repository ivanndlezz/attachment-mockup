# Creador de adjuntos de Drive

Herramienta para crear la tarjeta HTML de un archivo o carpeta de Google Drive.

## Instalación en newfacecards.com

1. Copiar la carpeta `attachment-mockup` al sitio, incluyendo `api/drive-metadata.php`.
2. En Google Cloud, activar **Google Drive API** y crear una **cuenta de servicio**. Descargar su JSON de credenciales y guardarlo fuera del directorio público del sitio.
3. Definir la variable de entorno `GOOGLE_DRIVE_SERVICE_ACCOUNT_FILE` con la ruta absoluta a ese JSON en PHP-FPM/Apache. Nunca colocar el JSON ni su clave privada en el repositorio.
4. Compartir la carpeta principal de los documentos (por ejemplo, `Catálogo`) con el correo de la cuenta de servicio como lector. Así también quedarán disponibles sus archivos internos.
5. Confirmar que los documentos y carpetas de Drive se compartan como **Cualquier persona con el enlace puede ver**, para que los destinatarios de los correos puedan abrirlos.

La consulta usa `files.get` de Google Drive con el alcance mínimo `drive.metadata.readonly` para obtener nombre, MIME type, tamaño y enlace de visualización. El endpoint no guarda los enlaces ni descarga archivos.
