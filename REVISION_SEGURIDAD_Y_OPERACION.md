# Revisión de seguridad, calidad y operación

**Aplicación:** Copropiedad  
**Fecha:** 17 de noviembre de 2026  
**Alcance:** revisión estática del repositorio, flujos web, configuración y esquema SQL. No se accedió a datos reales ni a un servidor productivo.

## Resumen de la revisión

Se aplicaron dos controles compatibles con el comportamiento actual: PHP ahora rechaza identificadores de sesión no emitidos por el servidor y el punto de entrada publica una política CSP y una política de capacidades del navegador. Se mantienen los formularios, scripts en línea y estilos actuales; por eso la CSP permite temporalmente `'unsafe-inline'` y no es todavía una política fuerte contra XSS.

Las dos plantillas ahora ofrecen un enlace de teclado “Saltar al contenido principal”, que enfoca una sección identificada como contenido principal sin alterar la navegación visual habitual.

La seguridad depende de desplegar Apache con `DocumentRoot` en `public/`, HTTPS, secretos correctos y migraciones aplicadas. Los escenarios usan los puntos de entrada `public/index.php`, `public/admin/index.php` y `public/usuario/index.php`. La auditoría del código no prueba la configuración del servidor ni la base de datos productiva.

## Cambios aplicados

| Cambio | Ubicación | Motivo | Efecto esperado |
|---|---|---|---|
| `session.use_strict_mode=1` | `app/Core/Session.php`, `Session::start()` | No aceptar IDs de sesión no inicializados por PHP; reduce fijación de sesión. | No cambia el flujo normal de inicio/cierre de sesión. |
| `session.use_only_cookies=1` | `app/Core/Session.php`, `Session::start()` | Impide transportar el ID de sesión en URL/parámetros. | La sesión sigue usando cookie, como ya estaba previsto. |
| CSP y Permissions Policy | `app/Core/WebBootstrap.php` | Aplica los mismos controles a los tres escenarios web. | CDN jsDelivr y scripts/estilos actuales siguen habilitados. La política de scripts en línea es transitoria. |
| Caché de autorización por solicitud | `app/Core/Authorization.php` | Reutiliza el usuario/permisos cargados en la solicitud actual; evita repetir dos consultas SQL en layout/controladores. | Se valida la sesión y su tiempo de expiración en cada invocación; cambios externos se ven en la siguiente solicitud HTTP. |
| Accesibilidad de teclado | `app/Scenarios/Admin/layout.php`, `app/Scenarios/User/layout.php` | Enlace “Saltar al contenido principal” en ambos layouts. | No cambia el aspecto normal; aparece al navegar con teclado. |

La cookie ya estaba configurada `HttpOnly`, `SameSite=Lax` y `Secure` cuando la solicitud llega por HTTPS. La expiración por inactividad (30 minutos), regeneración tras autenticar, CSRF, autorización por servidor, consultas preparadas, hash de contraseñas y cifrado autenticado de llaves/contraseñas guardadas ya estaban implementados.

## Hallazgos y trabajo pendiente

| Prioridad | Hallazgo | Estado / siguiente acción |
|---|---|---|
| Alta | `DocumentRoot` incorrecto podría exponer archivos que no son públicos, incluidos `.env`, `app/` o `vendor/`. | El README exige `public/` como raíz. Confirmar la configuración real de Apache y negar acceso a archivos privados en el servidor. No mover el `DocumentRoot` sin validar rutas de la instalación. |
| Alta | El retorno/webhook de Bold confía en verificación posterior consultando la API del proveedor. Requiere llave y ambiente correctos, TLS y que el endpoint sea accesible públicamente. | Lógica en `BoldPaymentService`; realizar prueba integral en sandbox y producción controlada, validar reintentos del proveedor, pagos tardíos y conciliación. No confiar solo en el cuerpo recibido. |
| Media | El bloqueo por intentos fallidos se aplica por cuenta (cinco intentos, 15 minutos); sin límite por IP/red puede facilitar denegación de acceso dirigida. Recuperación limita por cuenta a un envío por minuto, sin rate limit de red global. | Evaluar limitación adicional por IP/proxy y observabilidad con administración. No activar bloqueo permanente ni bloquear redes compartidas. |
| Media | CSP admite scripts y estilos en línea para conservar vistas existentes. | Migrar scripts a archivos estáticos o nonces por respuesta, probar cada vista, y después quitar `'unsafe-inline'` de `script-src` (y `style-src` cuando no haya estilos en atributos). |
| Media | Recursos CSS/JS se descargan desde jsDelivr. Aunque están versionados, el sitio depende de terceros en tiempo de carga y de la cadena de suministro del CDN. | Considerar alojarlos localmente o añadir SRI verificable y política de actualización. Probar disponibilidad y licencias antes de moverlos. |
| Media | El portal carga documentos de cobro y ejecuta consultas asociadas por cada documento (`BoldPaymentService::payableStatements` llama a `BillingService::statement`). | Medir con datos de volumen representativo; optimizar lectura por lotes/paginación preservando saldo, orden y acceso por usuario. No limitar resultados hasta definir paginación funcional. |
| Media | No se encontró política operativa versionada para backups, restauración, retención, rotación de secretos o respuesta a incidentes. | Adoptar el procedimiento de abajo y guardar responsables/horarios en la operación del negocio. Probar restauraciones en ambiente aislado. |
| Baja | La estructura de inmuebles se guarda como JSON, mientras asignaciones y cartera referencian IDs de texto. | Mantener las transacciones y la estabilidad de IDs; antes de normalizar, diseñar migración que preserve asignaciones, notas e historial emitido. |
| Baja | No se encontró suite automatizada de pruebas ni contrato API en el repositorio inspeccionado. | Definir cobertura funcional antes de nuevas integraciones. La aplicación actual es web renderizada por servidor; no se detectó API REST pública. |

## Controles ya presentes que conviene preservar

- Las contraseñas usan `password_hash`/`password_verify`; el login realiza verificación de hash para identidades inexistentes para reducir diferencias de tiempo.
- Los formularios de mutación inspeccionados verifican CSRF; la autorización se aplica en controladores y no depende solo de ocultar enlaces.
- PDO usa excepciones, consultas preparadas nativas y TLS con CA obligatoria para bases remotas.
- Los tokens de recuperación se guardan como hash, vencen y se invalidan tras uso; respuesta pública no revela si la cuenta existe.
- Las llaves de Bold y contraseñas SMTP se cifran con AES-256-GCM y `APP_KEY`. Perder o cambiar la clave impide descifrar esos secretos; custodiarla en un gestor seguro y respaldar con acceso restringido.
- Los pagos en línea se asocian a referencia única, transacción y monto esperado; la aprobación vuelve a consultar al proveedor.
- Las notas son instantáneas y el esquema impide repetir unidad/período. No eliminar movimientos ni editar manualmente la base de datos para “corregir” balances.

## Procedimiento de instalación y despliegue

### Instalación inicial

1. Instalar PHP 8.2+ con `pdo_mysql`, Composer y MySQL compatible con las restricciones usadas en las migraciones.
2. Ejecutar `composer install` desde la raíz del proyecto.
3. Copiar `.env.example` a `.env`; definir `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` HTTPS público, credenciales DB con mínimo privilegio, `APP_KEY` aleatoria base64 de 32 bytes y configuración de correo cuando se use.
4. Crear base de datos `utf8mb4`; usar cuenta MySQL de aplicación separada de `root`. Esta cuenta necesita lectura y escritura en las tablas de la aplicación. Las migraciones se ejecutan por separado con una cuenta administradora.
5. Ejecutar migraciones **una sola vez y en orden numérico** de `001` a `016`. Estas migraciones no constituyen un migrador automático y varias no son repetibles. Registrar cuáles se ejecutaron y cuándo.
6. Crear el primer Superadmin con `SUPERADMIN_PASSWORD` temporal en el entorno del proceso usando `php bin/create-superadmin.php`; no guardar la contraseña en archivos.
7. Configurar SMTP, enviar prueba, luego configurar Bold sandbox y validar link, webhook, retorno, monto, pago duplicado y estado en cartera.
8. Apuntar Apache exclusivamente a `public/`; comprobar que `.env`, `app/`, `database/`, `bin/` y `vendor/` no sean descargables por HTTP. Usar HTTPS y no confiar directamente en `X-Forwarded-Proto` salvo que el proxy esté controlado y su encabezado se limpie/reemplace.
9. Crear respaldo inicial y probar restauración en una base separada antes de habilitar operación.

### Actualización de versión

1. Registrar versión desplegada, checksum/commit y hora; pausar operaciones de emisión de cartera y cambios de estructura.
2. Tomar backup consistente de la base de datos y proteger `.env`/`APP_KEY` de forma separada.
3. Revisar cambios de esquema y ejecutar solo migraciones pendientes en orden. No reejecutar migraciones ALTER sin comprobar estado.
4. Desplegar código compatible; revisar logs PHP/Apache sin imprimir secretos; verificar login, permisos, portal, lectura de notas, SMTP y pagos sandbox.
5. Reanudar operaciones solo después de confirmar datos y endpoints. Si falla, restaurar código y esquema según plan preparado; no borrar tablas ni movimientos para volver atrás.

### Respaldo y restauración (política a definir)

- Acordar RPO/RTO, retención, responsable y ubicación cifrada. El repositorio no fija esos valores.
- Respaldar MySQL con transacción/consistencia para InnoDB; respaldar `APP_KEY` y configuración cifrada de forma independiente, con acceso muy restringido.
- Mantener copias separadas del servidor de aplicación; cifrar en tránsito y reposo; registrar resultado y alertar fallos.
- Restaurar periódicamente en ambiente aislado; verificar usuarios, roles, estructura, notas, pagos y lectura de mensajes antes de declarar recuperación exitosa.
- Eliminar respaldos según la política de retención acordada y normativa aplicable; no usar dump de producción en desarrollo sin anonimización.

## Procedimientos de operación

### Alta y salida de usuarios

1. Confirmar identidad/correo y relación con la copropiedad fuera del sistema.
2. Crear cuenta con contraseña temporal robusta y rol mínimo; asignar inmuebles explícitamente.
3. Confirmar ingreso al portal y datos de contacto; no compartir credenciales por canales públicos.
4. Al retirar acceso, desactivar cuenta (revoca sesiones); no eliminarla si tiene pagos, notas o mensajes relacionados.
5. Revisar asignaciones y rol de reemplazo con segundo control administrativo cuando el proceso de la copropiedad lo requiera.

### Emisión mensual de cartera

1. Confirmar cuota, fechas límite, asignaciones activas y correo antes de empezar.
2. Registrar los pagos del período anterior antes de emitir el siguiente para que el saldo arrastrado sea correcto.
3. Revisar período y previsualización; ingresar manualmente ajustes respaldados por administración.
4. Revisar muestras de inmuebles/cálculos y aprobar internamente; emitir una sola vez.
5. Consultar estados de entrega y reintentar fallos/pedientes en lotes; documentar errores permanentes.
6. Conciliar pagos Bold contra confirmación del proveedor y el movimiento registrado. Para cambios de valor o reversos, no editar tablas: definir procedimiento contable y soporte antes de implementar una reversión en producto.

### Respuesta a incidente

1. Registrar hora, cuenta afectada, rutas y síntomas sin copiar contraseñas, tokens, llaves ni datos de pago a tickets.
2. Si hay compromiso de cuenta, desactivar usuario/rotar contraseña y revocar sesiones mediante cambio de cuenta; preservar logs y respaldos.
3. Si se expuso `APP_KEY`, preparar cambio coordinado: los secretos SMTP/Bold cifrados con la clave anterior deberán volver a configurarse. No sustituirla de forma improvisada.
4. Si se expuso credencial DB/Bold/SMTP, revocarla en el proveedor, actualizar `.env`/panel y validar conexión. Revisar pagos y correos durante el intervalo afectado.
5. Conservar evidencia y notificar a responsables según política organizacional y obligaciones aplicables.

## Verificación realizada

La revisión fue estática. `php -l` pasó en los 55 archivos PHP bajo `app/`, `public/` y `bin/`. `composer audit --locked` consultó los paquetes fijados y no encontró avisos de seguridad. PHP mostró advertencias locales de extensiones `intl` y `openssl` cargadas dos veces; se deben corregir en `php.ini` del equipo/servidor, no en el código de la aplicación.

No se hicieron pruebas contra base de datos, SMTP, Bold ni navegador productivo; tampoco pruebas funcionales automatizadas. Por ello la CSP debe validarse en staging con todas las pantallas antes de despliegue, especialmente formularios y recursos CDN.

## Referencias técnicas consultadas

- Manual PHP, [configuración de sesiones](https://www.php.net/manual/en/session.configuration.php): recomienda modo estricto y describe cookies/IDs de sesión.
- Manual PHP, [gestión segura de sesiones](https://www.php.net/session.security.management): regeneración y manejo de IDs de sesión.
- OWASP, [PHP Configuration Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/PHP_Configuration_Cheat_Sheet.html): configuración endurecida y recomendaciones de operación.
- Manual PHP, [PDO::prepare](https://www.php.net/manual/en/pdo.prepare.php): consultas preparadas y parámetros.
- Manual PHP, [Password Hashing](https://www.php.net/passwords): uso de la API de hash de contraseña.
