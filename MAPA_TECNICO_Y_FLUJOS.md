# Mapa técnico y flujos de Copropiedad

Guía de navegación del código para mantenimiento y ampliación. Está organizada por archivo/responsabilidad y por el recorrido de las solicitudes. Para requisitos funcionales y pendientes, consultar [REQUISITOS_SOLUCION_ACTUAL.md](REQUISITOS_SOLUCION_ACTUAL.md); para seguridad y procedimientos de operación, consultar [REVISION_SEGURIDAD_Y_OPERACION.md](REVISION_SEGURIDAD_Y_OPERACION.md).

## Cómo recorrer una solicitud

1. `public/index.php`, `public/admin/index.php` y `public/usuario/index.php` delegan carga compartida y cabeceras en `app/Core/WebBootstrap.php`.
2. Cada punto de entrada carga su agregador: `app/Scenarios/Public/routes.php`, `Admin/routes.php` o `User/routes.php`.
3. `app/Core/Url.php` quita el prefijo configurado en `APP_URL`; `app/Core/Router.php` decide método y ruta exacta.
4. El controlador del módulo valida sesión/permisos, token CSRF (en operaciones POST), entradas y errores.
5. El servicio del módulo aplica reglas de negocio y consulta MySQL a través de `app/Core/Database.php`.
6. `app/Core/View.php` resuelve vistas por escenario (`app/Scenarios/*/Views`) y usa el layout correspondiente.
7. Los servicios de correo y pagos llaman proveedores externos por TLS; los pagos se contabilizan tras verificación remota.
8. El módulo Classifieds registra clasificados e imágenes privadas al disco; las imágenes se sirven con MIME validado y solo son públicas si la publicación está activa.

## Infraestructura compartida (`app/Core`)

| Archivo | Responsabilidad / puntos de revisión |
|---|---|
| `Database.php` | Construye conexión PDO MySQL con `utf8mb4`, prepares nativos, excepciones y CA/verificación TLS en host remoto. Variables: `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSL_CA`. |
| `Router.php` | Registra métodos GET/POST, detecta duplicados y responde 404/405. El matching es exacto y no define rutas dinámicas. |
| `Url.php` | Obtiene prefijo de URL desde `APP_URL`, construye links internos y normaliza path para router. |
| `Session.php` | Inicia cookie de sesión, CSRF, detección HTTPS, regeneración y destrucción. Endurecimiento aplicado: modo estricto y solo cookies. |
| `Authorization.php` | Recupera usuario vigente, invalida sesión inactiva (30 min) y verifica nivel por módulo en servidor. Superadmin (`is_system`) tiene acceso de sistema. |
| `View.php` | Resuelve vistas de escenario o autenticación compartida y carga su layout. |
| `WebBootstrap.php` | Configura `.env`, cabeceras de seguridad y el enrutador para los tres puntos de entrada. |

## Entrada web

| Archivo | Responsabilidad |
|---|---|
| `public/index.php` | Punto de entrada de portada, login, clasificados públicos y callbacks de pago. |
| `public/admin/index.php` | Punto de entrada del panel administrativo; carga solo rutas administrativas y compartidas de autenticación. |
| `public/usuario/index.php` | Punto de entrada exclusivo del portal; carga rutas de perfil, notificaciones y clasificados del residente. |
| `app/Scenarios/Admin/routes.php` | Agrupa las rutas de administración de los módulos funcionales. |
| `app/Scenarios/User/routes.php` | Agrupa las rutas del panel de usuario. |
| `app/Scenarios/Public/routes.php` | Agrupa portada, login público, clasificados, callbacks y SEO. |
| `bin/check-database.php` | Verificación operativa de conexión configurada; ejecutar con `.env` preparado. |
| `bin/create-superadmin.php` | Alta inicial idempotente según su control de cuenta existente; contraseña solo desde variable de entorno temporal. |

## Módulo Auth (`app/Modules/Auth`)

| Archivo | Responsabilidad |
|---|---|
| `routes.php`, `user-routes.php`, `admin-routes.php` | Registro dividido entre acceso compartido, portal de usuario y panel de administración. |
| `AuthController.php` | Orquesta autenticación, recuperación y operaciones de panel. Los controladores siguen en el módulo Auth; sus vistas se distribuyen por escenario. |
| `AuthService.php` | Autenticación, bloqueo, hash/re-hash, recuperación y actualización de credenciales; operaciones sensibles de cuenta. |
| `PasswordResetMailer.php` | Construye y envía correo de recuperación con `APP_URL`. |
| `SmtpMailer.php` | Envío SMTP reutilizable con configuración del módulo o compatibilidad de `.env`. |
| `PortalNotificationService.php` | Consulta/lectura de notificaciones y mensajes para usuario; consultas deben filtrar siempre por `user_id`. |
| `login.php`, `forgot-password.php`, `reset-password.php` | Formularios compartidos de acceso/recuperación; contienen token CSRF. |
| `app/Scenarios/Admin/Views` | Dashboard y vistas administrativas de configuración, cartera, mensajería e inmuebles. |
| `app/Scenarios/User/Views` | Panel de usuario y bandeja de mensajes. |

### Flujo de acceso

`GET /admin/login` (o `/login` desde la página pública) → `POST /admin/login` valida CSRF y credenciales → `AuthService::authenticate()` aplica intentos/hash → `Session::regenerate()` → destino `/admin` o `/usuario` según permisos. Recuperación: token aleatorio en enlace, hash en DB, vencimiento una hora, cambio de contraseña y aumento de `auth_version` para revocar sesiones anteriores.

## Módulo Configuration (`app/Modules/Configuration`)

| Archivo | Responsabilidad |
|---|---|
| `routes.php` | Rutas de parametrización, financiera, correo, pagos, caracterización, conformación, equipo y roles. |
| `ConfigurationController.php` | Validación de formularios, autorización por clave de módulo, CSRF, transacciones y mensajes de interfaz. Controlador extenso; nuevas funciones se deben ubicar según dominio y delegarse a servicios. |
| `SettingsService.php` | Lectura/escritura de información general, financiera y caracterización de copropiedad. |
| `CompositionService.php` | Guarda respuestas y unidades JSON, genera/preserva IDs y sincroniza asignaciones al retirar unidades. Cambios de estructura deben preservar atomicidad. |
| `TeamService.php` | Alta/edición/activación de cuentas y validación de roles asignables. |
| `RoleService.php` | Catálogo de módulos y niveles; CRUD de roles y permisos con protección de rol de sistema. |
| `MailSettingsService.php` | Persiste SMTP y cifra/descifra contraseña usando `APP_KEY`. |
| `app/Scenarios/Admin/Views/Configuration` | Vistas/formularios por área. Preservar escape HTML, CSRF y `name` de campos esperado por el controlador. |

## Módulo PropertyAssignment (`app/Modules/PropertyAssignment`)

| Archivo | Responsabilidad |
|---|---|
| `routes.php` | Lectura y guardado de asignaciones. |
| `PropertyAssignmentController.php` | Control de permiso `inmuebles.asignaciones`, CSRF, validación y redirecciones. |
| `PropertyAssignmentService.php` | Datos de unidades/cuentas y reemplazo transaccional de asignaciones por usuario. |
| `app/Scenarios/Admin/Views/PropertyAssignment/index.php` | Interfaz de selección de cuenta y unidades. |

## Módulo Billing (`app/Modules/Billing`)

| Archivo | Responsabilidad |
|---|---|
| `routes.php` | Rutas administrativas de cartera/notas, conceptos, pagos, emisión y reintento. También anulación de pagos y reportes. |
| `BillingController.php` | Valida permiso `cartera.cobros`, CSRF y formularios; presenta vista/nota. |
| `BillingService.php` | Vista previa, conceptos, pagos, cálculo de movimientos, emisión de instantáneas, destinatarios, envíos y HTML imprimible. Revisar aquí invariantes de saldo y período. |
| `BoldPaymentService.php` | Guarda llave/métodos, consume API HTTPS, crea/reusa enlaces, consulta estado y registra pago aprobado idempotentemente. No aceptar el webhook como prueba suficiente sin consulta remota. |
| `PaymentWebhookController.php` | Limita cuerpo JSON, decodifica evento y delega conciliación; ruta pública necesaria para proveedor. |
| `ReportService.php`, `ReportController.php` | Consultas de solo lectura: cartera por inmueble (nota vigente), antigüedad, recaudo, indicadores del panel y exportación CSV. |
| `app/Scenarios/Admin/Views/Billing/index.php` | Vista de período, previsualización, conceptos, pagos, estados de envío y emisión. |

### Flujo mensual de cartera

Previsualizar período → confirmar asignaciones, concepto y pago anterior → agregar ajustes manuales → emitir transaccionalmente → congelar datos y notificar destinatarios → reintentar estados pendientes/fallidos. Las notas emitidas no deben mutarse para corregir errores: cualquier reverso requiere proceso de negocio y diseño de auditoría.

### Flujo Bold

Portal verifica que el usuario sea destinatario de la nota → calcula saldo pendiente → consulta métodos disponibles → crea enlace cerrado y persistencia local → usuario paga → retorno/webhook provoca consulta al enlace en API Bold → se comprueba estado, ambiente, monto e identificador → inserta pago y marca referencia aprobada en transacción.

## Módulo Messaging (`app/Modules/Messaging`)

| Archivo | Responsabilidad |
|---|---|
| `routes.php` | Lectura y envío de mensajes internos. |
| `MessagingController.php` | Permisos de lectura/escritura, CSRF y validación del formulario. |
| `MessagingService.php` | Selección de destinatarios activos, persistencia del mensaje y bandeja enviada. |
| `app/Scenarios/Admin/Views/Messaging/index.php` | Formulario de envío individual/masivo y lista de enviados. |

## Módulo Classifieds (`app/Modules/Classifieds`)

| Archivo | Responsabilidad |
|---|---|
| `routes.php`, `user-routes.php` | Separan catálogo e imágenes públicas de las mutaciones protegidas del panel de usuario. |
| `ClassifiedsController.php` | Autorización de usuario, CSRF, respuestas públicas de imágenes y operaciones de publicación/estado/eliminación. |
| `ClassifiedsService.php` | Validación de campos, consentimiento de contacto, carga segura de imágenes y consultas/operaciones asociadas al dueño. |
| `app/Scenarios/Public/Views/Classifieds/index.php` | Catálogo público adaptable para ofertas y búsquedas. |
| `database/migrations/017_create_classifieds.sql` | Almacenamiento de clasificados y metadatos de imágenes; los archivos se almacenan en `storage/classifieds`, fuera de `public`. |

## Módulo Home (`app/Modules/Home`)

| Archivo | Responsabilidad |
|---|---|
| `routes.php` | Registra página raíz. |
| `HomeController.php` | Carga parametrización, galería y tres clasificados recientes para portada. |
| `app/Scenarios/Public/Views/Home/index.php` | Portada: comunidad, fotos, ubicación, clasificados y acceso al portal. |
| `app/Modules/Configuration/PropertyGalleryService.php` | Valida y gestiona hasta ocho imágenes de la galería pública. |
| `public/assets/portada` | Estilos locales e ilustración de respaldo de la página pública. |

## Escenarios de presentación (`app/Scenarios`)

| Archivo | Responsabilidad |
|---|---|
| `Admin/layout.php` | Navegación AdminLTE, visibilidad de enlaces según permisos y contenedor administrativo. |
| `User/layout.php` | Cabecera, navegación, notificaciones y contenedor del portal. |
| `Public/layout.php` | Metadatos sociales/SEO y diseño liviano para portada/clasificados. |

## Base de datos

`database/migrations/001` a `019` crean autenticación, roles, configuración, composición, asignaciones, correo, cartera, notificaciones, pagos Bold y clasificados. Las migraciones 018 y 019 agregan instantánea de coeficiente en notas e integración Bold de datáfono, respectivamente. La 020 agrega la anulación trazable de pagos (`voided_at`, `voided_by`, `void_reason`); los saldos excluyen pagos anulados. Se ejecutan en orden una sola vez; no hay tabla de historial de migraciones ni comando automático en el código revisado. Antes de añadir una migración:

1. Identificar datos existentes y restricciones/FK afectadas.
2. Preparar migración de avance y procedimiento de recuperación compatible con los datos.
3. Aplicar primero en copia representativa y comprobar tiempos/locks.
4. Registrar nombre, fecha, operador y resultado.
5. No modificar una migración ya aplicada; crear una nueva numerada.

## Convenciones para cambios futuros

- Mantener validación y autorización en backend; el navegador solo mejora experiencia.
- Usar consultas preparadas y transacciones para operaciones que modifican varias tablas.
- Escapar salida HTML con `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
- Proteger POST con CSRF; definir explícitamente la excepción para proveedores externos y autenticar por verificación del proveedor.
- No loguear claves, contraseñas, tokens, datos bancarios ni cuerpos completos de pago.
- Agregar comentarios cuando expliquen una regla no obvia, invariante o decisión de seguridad; evitar comentarios que solo repitan cada línea de código.
- Actualizar requisitos, procedimientos y este mapa cuando se agregue una ruta, tabla, permiso o proceso.
