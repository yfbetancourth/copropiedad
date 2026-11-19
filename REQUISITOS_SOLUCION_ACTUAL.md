# Requisitos de la solución actual — Copropiedad

**Tipo de documento:** especificación funcional y técnica obtenida por ingeniería inversa  
**Fecha:** 18 de noviembre de 2026
**Base de análisis:** código PHP, rutas, vistas, servicios, migraciones SQL 001–019 y README del repositorio.

## 1. Propósito y cómo usar este documento

Este documento describe lo que la aplicación permite hoy y lo que debe definirse o implementarse para continuar su evolución. Los estados significan:

- **Implementado:** hay rutas y lógica de servidor que respaldan el requisito.
- **Parcial:** existe una parte del flujo o depende de configuración externa.
- **Pendiente / por validar:** el código no lo cubre o el negocio debe decidir su comportamiento.

Puede usarse como base para construir historias de usuario, priorizar trabajo o completar una especificación. No se inspeccionó una base de datos desplegada ni se ejecutó una revisión visual/manual del sitio; “implementado” describe el código disponible, no confirma que esté instalado o configurado en un ambiente.

## 2. Resumen ejecutivo

La solución es una aplicación web de administración de copropiedad, monolítica y modular, escrita en PHP 8.2+, con páginas renderizadas en servidor, PDO/MySQL y rutas propias. Incluye acceso administrativo y portal de usuario; administración de roles y permisos; parametrización de la copropiedad y conformación de inmuebles; asignación de inmuebles a cuentas; notas mensuales de cobro, registro de pagos, avisos por correo y pago en línea mediante Bold; y mensajería interna.

La operación de cartera es deliberadamente asistida: el personal revisa conceptos y emite notas. Intereses, multas, saldos iniciales, retroactivos y otros cargos no se calculan automáticamente. Las notas imprimibles no constituyen factura electrónica. No se encontró API REST pública, gestión de residentes/propietarios como perfiles diferenciados, contabilidad, conciliación bancaria, reservas, mantenimiento ni gestión de asambleas.

## 3. Actores

| Actor | Necesidad / alcance | Estado |
|---|---|---|
| Superadmin | Acceso de sistema y administración inicial de la solución | Implementado |
| Administrador con rol | Acceder a módulos según permisos otorgados; gestionar usuarios, roles u operación | Implementado |
| Usuario del portal | Consultar inmuebles asignados, cobros, avisos y mensajes; actualizar su cuenta y pagar en línea cuando esté habilitado | Implementado; depende de asignaciones/configuración |
| Servicio SMTP | Enviar recuperación de acceso, notas de cobro y mensajes de prueba | Parcial; requiere SMTP válido |
| Bold | Crear enlaces de pago y confirmar el resultado mediante consulta/webhook | Parcial; requiere credenciales y configuración externa |

## 4. Requisitos funcionales

### RF-01. Acceso y seguridad de cuentas

- El sistema debe permitir iniciar sesión con usuario o correo y contraseña. **Implementado.**
- Debe bloquear temporalmente una cuenta después de cinco intentos fallidos y permitir cerrar sesión. **Implementado según README y servicio de autenticación.**
- Debe permitir solicitar recuperación sin revelar si el correo está registrado; el enlace usa token aleatorio almacenado como hash, de un solo uso y con expiración de una hora. **Implementado.**
- Las contraseñas nuevas deben tener al menos 12 caracteres. **Implementado.**
- Las sesiones deben expirar tras 30 minutos de inactividad y revocarse cuando la versión de autenticación de la cuenta cambie. **Implementado.**
- Las operaciones de escritura de formularios deben validar token CSRF. **Implementado en rutas POST inspeccionadas.**

### RF-02. Administración de equipo, roles y permisos

- Un usuario autorizado debe crear y editar cuentas con nombre, correo, rol y contraseña inicial; nombre/correo son únicos. **Implementado.**
- Debe poder activar/desactivar cuentas sin borrar su historial; Superadmin está protegido. **Implementado.**
- Debe poder crear roles y conceder permisos por módulo en niveles `none`, `read`, `write` y `total`. **Implementado.**
- El servidor debe revisar los permisos en cada ruta y evitar que un administrador otorgue permisos superiores a los propios. **Implementado.**
- Los permisos existentes cubren panel, equipo, roles, parametrización, financiera, caracterización, conformación, asignaciones, portal, correo, cartera, mensajes y métodos de pago. **Implementado en servicios/migraciones.**

### RF-03. Parametrización de copropiedad y financiera

- Debe configurarse nombre, dirección, NIT y opcionalmente titular, banco, tipo y número de cuenta. **Implementado.**
- Debe configurarse valor de administración y fecha de vigencia, descuento fijo por pronto pago, días de pronto pago y vencimiento, regla de mora porcentual o fija con días de espera, valor base de multas y cuota extraordinaria con fechas de vigencia. **Implementado como datos de configuración.**
- La aplicación debe aceptar copropiedad tipo casa, torres de apartamentos o mixta; nomenclatura de torres numérica/alfabética y apartamentos de dos dígitos 01–99. **Implementado en caracterización/conformación.**
- Cambiar caracterización/nomenclatura luego de iniciar la conformación debe advertir y reiniciar esa estructura. **Comportamiento descrito en README; verificar en ambiente.**

### RF-04. Conformación de inmuebles y asignaciones

- Un administrador debe completar un asistente que conserva respuestas parciales, generar unidades por cantidades, corregir/agregar categorías y finalizar la estructura. **Implementado.**
- Debe soportar hasta 5.000 inmuebles y mantener identificadores estables para vincular asignaciones y cobros. **Implementado según código/README.**
- Un usuario autorizado debe asignar una o más unidades a cuentas. Al retirar una unidad de la estructura se eliminan sus asignaciones. **Implementado.**
- Las cuentas del portal solo deben consultar unidades asignadas a ellas. **Implementado.**
- El modelo no distingue propietario de residente ni permite múltiples clases de titularidad. **Limitación actual.**

### RF-05. Portal de usuario

- El usuario debe consultar sus unidades y valores configurados, consultar notas disponibles y avisos de cobros/mensajes. **Implementado.**
- Debe marcar como leídos los avisos de cobro y mensajes; consultar “Mis mensajes”. **Implementado.**
- Debe actualizar su nombre de usuario, correo y contraseña validando la contraseña actual. **Implementado.**
- El acceso del portal debe priorizar el panel de usuario para cuentas sin acceso administrativo. **Implementado.**
- El usuario debe crear y cerrar/reabrir o eliminar sus clasificados de inmuebles desde el panel; las ofertas de venta y búsquedas de compra activas se consultan públicamente. **Implementado en el módulo de clasificados; requiere aplicar la migración 017.**
- Cada clasificado debe admitir de una a cinco imágenes JPEG, PNG o WebP de hasta 4 MB cada una, con presentación adaptable. **Implementado con validación en servidor y almacenamiento fuera del directorio público.**
- Los datos de contacto ingresados solo deben divulgarse con autorización explícita por publicación; el servidor no debe exponerlos en la consulta pública sin consentimiento. **Implementado.**

### RF-06. Cartera y notas de cobro

- El personal autorizado debe seleccionar período mensual, revisar previsualización y conceptos, registrar conceptos manuales por unidad y emitir notas. **Implementado.**
- Por unidad/período solo debe emitirse una nota; la emisión conserva una instantánea de valores, datos de copropiedad y fechas. Los períodos deben emitirse en orden para trasladar saldos. **Implementado.**
- Debe soportar saldo inicial/ajuste, intereses, gastos jurídicos, multas, retroactivos, extras y otros conceptos ingresados manualmente. **Implementado.**
- Debe registrar pagos asociados a una nota con fecha, referencia y observaciones; el descuento pronto pago se reconoce cuando los pagos alcanzan el total descontado dentro de la fecha límite. **Implementado según servicio/README.**
- Debe notificar por correo a cuentas activas asignadas al inmueble, mostrar estado por destinatario y permitir reintentar envíos pendientes/fallidos en lotes de hasta 50. **Implementado según servicio/README.**
- Debe mostrar/imprimir la nota desde el navegador. **Implementado.** No reemplaza factura electrónica ni revisión contable.
- No debe prorratear automáticamente una cuota con vigencia que inicia/termina a mitad del período; el flujo bloquea esos casos. **Implementado como restricción.**
- No se automatizan cálculo de mora, multas, retroactivos ni aprobación de soportes. **Fuera del alcance actual.**

### RF-07. Pagos en línea Bold

- La administración debe configurar ambiente sandbox/producción, llave de identidad cifrada y métodos de pago. **Implementado.**
- El usuario debe solicitar un enlace de pago para una nota habilitada. La cartera solo debe registrar el abono tras confirmar con Bold que el enlace fue pagado y que el valor coincide con el saldo esperado. **Implementado en servicio; requiere credenciales, HTTPS y webhook en ambiente real.**
- Deben procesarse webhook y retorno del proveedor, y evitar duplicar transacciones aprobadas. **Rutas y persistencia implementadas; validar integración real con Bold.**

### RF-08. Mensajes internos

- Un administrador con permiso debe enviar mensaje de texto con asunto a un usuario o a todos los usuarios activos. **Implementado.**
- El destinatario debe consultar mensajes y su estado de lectura. **Implementado.**
- No se encontró envío de estos mensajes por correo ni adjuntos. **No incluido actualmente.**

### RF-09. Configuración de correo

- Debe configurarse SMTP (host, puerto, cifrado, usuario, remitente y contraseña). La contraseña guardada se cifra con AES-256-GCM mediante `APP_KEY`; al editar puede conservarse sin volver a mostrarla. **Implementado.**
- Debe permitirse enviar un correo de prueba a la cuenta administradora. **Implementado.**
- Deben usarse credenciales locales fuera del código y `APP_URL` HTTPS en producción. **Requisito de despliegue documentado.**

## 5. Requisitos no funcionales y arquitectura

- **Plataforma:** PHP >= 8.2, MySQL, Composer; extensiones PDO y PDO MySQL. **Implementado/documentado.**
- **Arquitectura:** MVC modular propio; `app/Core` contiene infraestructura; `app/Modules` contiene lógica por dominio; `app/Scenarios/{Public,Admin,User}` organiza rutas, layouts y vistas por escenario, con puntos de entrada propios en `public/`. **Implementado.**
- **Datos:** MySQL/InnoDB, `utf8mb4`; acceso PDO con consultas preparadas. Migraciones numeradas 001–019. **Implementado/documentado.**
- **Seguridad:** contraseñas con `password_hash`, token CSRF, control de acceso servidor, cabeceras de seguridad, secretos en `.env`, cifrado autenticado para secretos SMTP/Bold y validación TLS para MySQL remoto. **Implementado/documentado; requiere configuración correcta del despliegue.**
- **Disponibilidad/recuperación:** no se encontró en el repositorio política de copias, restauración, retención o monitoreo. **Pendiente de operación.**
- **Interfaz:** páginas PHP renderizadas en servidor y plantilla AdminLTE; portal con diseño separado. **Implementado.**
- **Idioma/moneda:** textos en español y montos colombianos representados en COP. **Implementado en vistas/servicios.**

## 6. Rutas principales identificadas

| Área | Rutas observadas |
|---|---|
| Acceso | Página pública en `/`; administración en `/admin` (login `/admin/login`); portal de usuario en `/usuario`; recuperación de contraseña en `/forgot-password` y `/reset-password`. |
| Portal | `/usuario`, `/usuario/mensajes`, `/usuario/perfil` (POST), `/usuario/pagar` (POST) |
| Clasificados | `/clasificados` (público), `/clasificados/imagen`; las acciones de `/usuario/clasificados/*` usan POST y requieren sesión, permiso de portal y CSRF |
| Página pública | `/` con fotos de galería, dirección/mapa, clasificados recientes, enlace de login, metadatos SEO, `/robots.txt` y `/sitemap.xml` |
| Configuración | `/configuracion/parametrizacion`, `/financiera`, `/caracterizacion`, `/conformacion`, `/equipo`, `/roles`, `/correo`, `/metodos-pago` y acciones POST asociadas |
| Inmuebles | `/inmuebles/asignaciones` y guardado POST |
| Cartera | `/cartera/cobros`, `/cartera/cobros/nota`, emisión, conceptos, pagos y reintento mediante POST; anulación de pagos `/cartera/cobros/pago/anular`; reportes `/cartera/reportes` y exportación CSV `/cartera/reportes/exportar` |
| Bold | `/pagos/bold/webhook` (POST), `/pagos/bold/retorno` |
| Mensajería | `/mensajes` y envío POST |

No se identificaron endpoints REST versionados (por ejemplo `/api/v1`) ni contrato OpenAPI.

## 7. Modelo de información observado

Las migraciones definen estos grupos principales:

- **Identidad y autorización:** `admin_users`, `admin_password_reset_tokens`, `admin_roles`, `admin_role_permissions`.
- **Configuración del inmueble:** `copropiedad_settings`, `copropiedad_composition`.
- **Asignaciones:** `property_unit_assignments` (identificadores de unidad en JSON de conformación; no existe tabla relacional de unidades en estas migraciones).
- **Correo:** `copropiedad_mail_settings`.
- **Cartera:** `billing_monthly_items`, `billing_statements`, `billing_statement_lines`, `billing_statement_recipients`, `billing_payments`, `billing_statement_user_reads`.
- **Pagos en línea:** `bold_payment_settings`, `billing_online_payments`.
- **Mensajería:** `admin_messages`, `admin_message_recipients`.
- **Clasificados:** `property_classifieds`, `property_classified_images`; metadatos en MySQL y archivos en `storage/classifieds`, fuera del directorio público.
- **Galería pública:** fotos JPG/PNG/WebP administradas desde el panel y guardadas en `public/uploads/property-gallery`; incluir estos archivos en los respaldos.

## 8. Pendientes recomendados para convertir en solución completa

Estos puntos no se deben tratar como compromisos de producto hasta validarlos con administración:

1. **Definir alcance contable y fiscal:** confirmar si se requiere factura electrónica, integración DIAN, numeración fiscal, notas crédito/débito, libros o exportación contable. Hoy solo hay notas de cobro imprimibles.
2. **Definir ciclo de cartera:** reglas de reversión/anulación/corrección de una nota emitida, pagos parciales, aplicación de excedentes, mora automática, redondeo, cambio de cuota en mitad de mes y tratamiento de pagos tardíos. Actualmente la operación es manual y la nota emitida es una instantánea.
3. **Definir personas y titularidad:** propietario, residente, arrendatario, responsable de pago, varios contactos por inmueble, historial de ocupación y permisos sobre datos. Hoy una cuenta puede vincularse a unidades sin tipo de relación.
4. **Completar operación:** política de backups y restauración, auditoría de acciones sensibles, monitoreo/logs estructurados, gestión de errores y plan de actualización/rollback de migraciones. Hay `created_by` en movimientos, pero no un registro general de auditoría identificado.
5. **Validar pagos reales:** credenciales/ambientes, autenticidad y repetición de webhooks, conciliación contra reportes del comercio, reembolsos y tratamiento de pago aprobado luego de saldo modificado. El flujo existente valida estado y monto, pero requiere pruebas integrales del proveedor.
6. **Definir reportes y exportaciones:** cartera por período/unidad, antigüedad de saldos, recaudo, pagos, ingresos, comprobantes y formatos de descarga. Implementado parcialmente: `/cartera/reportes` ofrece cartera por inmueble, antigüedad estimada, recaudo mensual y CSV de cartera y pagos; faltan comprobantes, ingresos contables y estado de cuenta del residente.
7. **Definir alcance de notificaciones:** recordatorios automáticos, preferencias/canales, rebotes definitivos, mensajes masivos con plantillas, archivos y evidencia de entrega. Hoy hay avisos internos y correo de cobro/reintento.
8. **Confirmar necesidades de integración/API:** si otros sistemas deben consumir la solución, definir autenticación, recursos, permisos, límites y contrato API; actualmente la aplicación es web renderizada y no presenta API pública.
9. **Verificar despliegue y datos reales:** confirmar migraciones aplicadas en orden, usuario inicial, variables `.env`, HTTPS, SMTP/Bold, zona horaria, tamaño de copropiedad y permisos efectivos en cada rol.
10. **Crear pruebas automatizadas:** no se observaron suites de pruebas en los archivos del proyecto; cubrir autenticación, permisos, emisión idempotente, cálculo de saldos/descuento, asignaciones y webhooks.

## 9. Reglas de negocio que deben preservarse

- Un inmueble/período no se cobra más de una vez.
- La nota emitida conserva datos congelados aunque luego cambie la parametrización.
- El orden de emisión mensual importa para arrastrar el saldo anterior.
- Cada nota notifica a cuentas activas asignadas; no se distingue propietario de residente.
- El sistema no emite cobros automáticamente al guardar configuración.
- El pago Bold solo se contabiliza tras confirmación externa y validación del importe.
- La cuenta y el rol de sistema Superadmin están protegidos.
- El usuario del portal solo opera sobre su propia cuenta, mensajes y unidades asignadas.

## 10. Criterios de aceptación globales sugeridos

1. Cada página y acción de escritura exige sesión/permisos y CSRF según corresponda.
2. Un usuario de portal no puede consultar datos de unidades o documentos no asignados, incluso alterando identificadores de URL/formulario.
3. Emitir dos veces el mismo período no duplica notas ni envíos/documentos; la segunda acción informa claramente el resultado existente.
4. Los totales, saldo anterior y descuento se pueden reconstruir desde líneas y pagos registrados, y coinciden con la nota congelada.
5. Un webhook duplicado, tardío o con valor diferente no genera abonos duplicados ni altera incorrectamente el saldo.
6. Las credenciales y tokens no aparecen en páginas, registros o respuestas de error.
7. La recuperación de acceso mantiene respuestas indistinguibles para correos registrados/no registrados y los tokens vencidos/usados no funcionan.

## 11. Referencias del código

- Inicio y registro de rutas: `public/index.php` y `app/Modules/*/routes.php`.
- Requisitos de instalación y decisiones operativas: `README.md`.
- Esquema y evolución: `database/migrations/001` a `016`.
- Reglas de acceso: `app/Core/Authorization.php`.
- Flujos: controladores/servicios de `Auth`, `Configuration`, `PropertyAssignment`, `Billing`, `Messaging` y `Classifieds`.

