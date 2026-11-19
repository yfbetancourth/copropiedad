# Inventario funcional para migración — Copropiedad

**Fecha de levantamiento:** 18 de noviembre de 2026  
**Propósito:** seleccionar qué capacidades, reglas y datos se conservarán al construir o migrar a otra aplicación.

## Cómo diligenciarlo

Marca `[x]` en **Migrar** para incluir el alcance. Marca `[x]` en **No migrar** para excluirlo. Deja ambas casillas vacías cuando la decisión esté pendiente. Las casillas son una herramienta de alcance, no evidencia de que una función esté activa en producción.

El análisis se hizo sobre el código, las vistas, las rutas y las migraciones SQL 001–019 presentes en el repositorio. No se inspeccionó una base desplegada ni se validó el uso real de cada función. Los elementos marcados como parciales necesitan confirmación/configuración externa.

## Resumen de la aplicación actual

Aplicación web de administración de copropiedad con tres experiencias: sitio público, panel administrativo y portal de usuarios. Está construida en PHP 8.2+, MVC modular propio, vistas renderizadas en servidor y MySQL mediante PDO. Incluye configuración del conjunto, estructura y asignación de inmuebles, usuarios y permisos, cartera con emisión asistida de notas, pagos, notificaciones, mensajería, clasificados y pagos Bold.

La operación de cartera no es contabilidad completa: el personal prepara y confirma cobros, y captura manualmente ajustes. Las notas son imprimibles, no factura electrónica. No se identificó un módulo de asambleas, reservas, mantenimiento, contabilidad, API pública ni conciliación bancaria.

## 1. Funcionalidades para decidir

En las dependencias, **bloqueante** significa que conviene incluir o reemplazar esa capacidad si se migra el elemento seleccionado; **recomendado** indica una dependencia operativa.

| Migrar | No migrar | Capacidad actual | Operación / alcance observable | Dependencias y notas |
|---|---|---|---|---|
| [ ] | [ ] | Sitio público | Portada con información y dirección de la copropiedad, mapa, galería de hasta 8 fotos, clasificados recientes, acceso al portal y metadatos SEO. | Recomendado: parametrización y galería. |
| [ ] | [ ] | Acceso y recuperación | Inicio de sesión por usuario o correo, cierre de sesión, bloqueo tras intentos fallidos y recuperación de contraseña por correo con enlace temporal. | Bloqueante: cuentas y proveedor de correo para recuperar acceso. |
| [ ] | [ ] | Panel administrativo | Entrada para personal con menú por permisos y tablero inicial. | Bloqueante: autenticación y roles/permisos. |
| [ ] | [ ] | Equipo de administración | Crear/editar cuentas, activar/desactivar y asignar roles; conserva cuenta desactivada. | Bloqueante: autenticación y roles. |
| [ ] | [ ] | Roles y permisos | Roles configurables con niveles de lectura, escritura y total por área; autorización validada por servidor. | Recomendado: definir matriz equivalente en destino. Superadmin está protegido. |
| [ ] | [ ] | Parametrización general | Nombre, dirección, NIT, datos de titular y cuenta bancaria, días de pronto pago/vencimiento. | Recomendado: cartera y sitio público. |
| [ ] | [ ] | Configuración financiera | Cuota base y vigencia, descuento por pronto pago, regla/espera de mora, valor base de multas y cuota extraordinaria con fechas. | Recomendado: cartera. Guardar estos valores no crea cobros ni calcula mora. |
| [ ] | [ ] | Caracterización | Tipo casa, torres de apartamentos o mixta; nomenclatura numérica/alfabética de torres y apartamentos 01–99. | Bloqueante: conformación de inmuebles. Cambios posteriores pueden reiniciar la estructura. |
| [ ] | [ ] | Conformación de inmuebles | Asistente con avance guardado, generación por categorías/cantidades, edición y coeficientes porcentuales; hasta 5.000 unidades. | Bloqueante: cartera y asignaciones. La cuota usa base × coeficiente / 100. |
| [ ] | [ ] | Asignación de usuarios a inmuebles | Una cuenta puede relacionarse con varias unidades; el usuario del portal solo accede a las asignadas. | Bloqueante: portal, destinatarios de cobro y mensajes individualizados. No distingue propietario/residente. |
| [ ] | [ ] | Portal de usuario | Consultar unidades asignadas, valores, cobros, avisos y mensajes; actualizar usuario/correo/contraseña. | Recomendado: asignaciones y autenticación. |
| [ ] | [ ] | Cartera: conceptos y emisión | Previsualizar período, añadir conceptos manuales por unidad y confirmar notas mensuales con consecutivo e instantánea de datos. | Bloqueante: unidades, coeficientes y configuración financiera. Emisión asistida, no automática. |
| [ ] | [ ] | Cartera: pagos y saldos | Registrar abonos con fecha, referencia y observaciones; saldo anterior y descuento pronto pago. | Recomendado: cartera/emisión. Los períodos se emiten en orden. |
| [ ] | [ ] | Notas imprimibles | Consultar/imprimir nota con datos congelados y líneas. | No equivale a factura electrónica; definir anulaciones/correcciones en destino. |
| [ ] | [ ] | Correo SMTP | Configurar servidor, remitente y secreto cifrado; enviar correo de prueba. Se usa para recuperación y notificaciones de cobro. | Bloqueante para envíos; requiere secretos/configuración nueva en destino. |
| [ ] | [ ] | Notificaciones de cobro | Envío por destinatario a cuentas activas asignadas; estado de entrega y reintento de fallidos/pendientes. | Recomendado: cartera, asignaciones y correo. Máximo 50 destinatarios por lote de reintento. |
| [ ] | [ ] | Mensajes internos | Administración envía asunto y texto a una cuenta o a todos los usuarios activos; portal muestra bandeja y lectura. | Recomendado: cuentas/portal. No hay adjuntos ni correo de mensajes. |
| [ ] | [ ] | Bold: botón/link de pago | Usuario solicita enlace de pago de una nota; el sistema verifica estado e importe con Bold antes de aplicar el abono. | Parcial: requiere cuenta, llaves, HTTPS y webhook; validar integración real. |
| [ ] | [ ] | Bold: pago por datáfono | Solicitud de pago a terminal Bold y registro tras confirmación firmada del proveedor. | Parcial: requiere API Integrations, terminal vinculada, webhook/llave secreta y pruebas con Bold. Es una capacidad separada del link de pago. |
| [ ] | [ ] | Clasificados | Usuario publica oferta de venta o búsqueda de compra con descripción, precio/presupuesto opcional, contacto autorizado e imágenes; catálogo público. | Recomendado: portal/cuentas y almacenamiento de archivos. Hasta 5 imágenes de 4 MB por aviso. |
| [ ] | [ ] | Galería pública | Administración añade/elimina fotos JPG/PNG/WebP para la portada. | Requiere respaldar archivos además de base de datos. Hasta 8 fotos. |
| [ ] | [ ] | SEO básico | `robots.txt`, `sitemap.xml`, URL canónica y metadatos de portada. | Requiere dominio/URL pública de destino. |

## 2. Procesos operativos actuales

Marca cada flujo completo o selecciona solo las etapas necesarias.

| Migrar | No migrar | Flujo | Pasos que realiza hoy |
|---|---|---|---|
| [ ] | [ ] | Preparación inicial | Crear base de datos → aplicar migraciones en orden → crear Superadmin → configurar dominio/secretos/correo. |
| [ ] | [ ] | Configuración de copropiedad | Datos generales/financieros → caracterización → conformación y coeficientes → asignación de usuarios a inmuebles. |
| [ ] | [ ] | Cobro mensual | Elegir período → revisar previsualización y pagos previos → ingresar ajustes manuales → emitir notas → notificar → registrar pagos/reintentar correo. |
| [ ] | [ ] | Pago en línea | Usuario elige nota → inicia enlace o terminal → proveedor confirma → servidor valida estado/referencia/monto → registra el pago. |
| [ ] | [ ] | Comunicación | Administración redacta mensaje → selecciona usuario o envío general → usuario consulta/marca leído en el portal. |
| [ ] | [ ] | Clasificado | Usuario publica/gestiona aviso → publicación activa aparece en catálogo público → usuario cierra, reactiva o elimina. |

## 3. Datos que podrían requerir migración

Selecciona cada conjunto según se migren datos históricos o se inicie vacío. Los nombres entre paréntesis corresponden a tablas actuales cuando existen.

| Migrar | No migrar | Conjunto de datos | Observaciones |
|---|---|---|---|
| [ ] | [ ] | Cuentas, roles y permisos (`admin_users`, `admin_roles`, `admin_role_permissions`) | No copiar hashes de contraseña sin comprobar compatibilidad; puede ser preferible forzar restablecimiento. |
| [ ] | [ ] | Solicitudes/tokens de recuperación | Normalmente no migrar tokens activos. |
| [ ] | [ ] | Datos de copropiedad y financiera (`copropiedad_settings`) | Incluye información identificable y bancaria. |
| [ ] | [ ] | Estructura, respuestas y coeficientes (`copropiedad_composition`) | La composición de unidades se almacena en JSON, no como catálogo relacional tradicional. |
| [ ] | [ ] | Asignaciones (`property_unit_assignments`) | Sus identificadores dependen de los IDs estables de unidad de la composición. |
| [ ] | [ ] | Configuración SMTP | Migrar configuración sin secreto o volver a cargar la contraseña en el destino. |
| [ ] | [ ] | Notas, líneas, destinatarios y pagos (`billing_*`) | Conservar instantáneas, consecutivos, pagos parciales y lecturas si se requiere historial/auditoría. |
| [ ] | [ ] | Configuración y transacciones Bold (`bold_payment_settings`, `billing_online_payments`, `billing_bold_terminal_payments`) | No copiar llaves secretas sin una decisión de seguridad; reconciliar operaciones pendientes/aprobadas con proveedor. |
| [ ] | [ ] | Mensajes y estado de lectura (`admin_messages`, `admin_message_recipients`) | Definir si se conserva historial y destinatarios desactivados. |
| [ ] | [ ] | Clasificados y metadatos (`property_classifieds`, `property_classified_images`) | Migrar también archivos de `storage/classifieds`; mantener vínculo/propietario e indicadores de contacto autorizado. |
| [ ] | [ ] | Fotos de galería | Archivos en `public/uploads/property-gallery`; respaldar/migrar por separado de MySQL. |

## 4. Reglas de negocio a preservar o reemplazar

| Migrar | No migrar | Regla vigente |
|---|---|---|
| [ ] | [ ] | Un inmueble solo puede tener una nota por período mensual. |
| [ ] | [ ] | La nota emitida congela datos/configuración y coeficiente aplicados en ese momento. |
| [ ] | [ ] | El orden de emisión importa para trasladar saldos; si no existe nota anterior se captura saldo inicial manualmente. |
| [ ] | [ ] | No hay prorrateo automático cuando la cuota cambia a mitad de período; emisión se bloquea en ese caso. |
| [ ] | [ ] | Mora, multas, intereses, retroactivos, extras y ajustes requieren ingreso/revisión manual; no se calculan automáticamente. |
| [ ] | [ ] | El descuento pronto pago se reconoce si los abonos alcanzan el total con descuento antes del límite. |
| [ ] | [ ] | Una cuenta recibe avisos por las unidades que tiene asignadas; no existe distinción de propietario frente a residente. |
| [ ] | [ ] | Pago Bold solo se registra después de confirmar la operación y validar el importe; los eventos repetidos no deben duplicar abonos. |
| [ ] | [ ] | El usuario de portal solo debe leer y modificar sus propios datos, clasificados, mensajes y unidades asignadas. |
| [ ] | [ ] | Las notas actuales no son facturas electrónicas. |

## 5. Capacidades no encontradas y decisiones para el nuevo sistema

Marca si la nueva aplicación debe incorporarlas. No se observaron implementadas en este repositorio.

| Incluir en nuevo alcance | No incluir | Capacidad por definir |
|---|---|---|
| [ ] | [ ] | Facturación electrónica / integración DIAN, notas crédito y documentos fiscales. |
| [ ] | [ ] | Contabilidad, catálogo contable, comprobantes e integración/exportación contable. |
| [ ] | [ ] | Conciliación bancaria, importación de extractos y reembolsos. |
| [ ] | [ ] | Cálculo automático y reglas de mora, multas, intereses y prorrateos. |
| [ ] | [ ] | Anulación, reversión y corrección auditada de notas y pagos emitidos. |
| [ ] | [ ] | Perfiles diferenciados de propietario, residente, arrendatario y responsable de pago; varios contactos por inmueble. |
| [ ] | [ ] | Reportes, exportaciones y consultas de cartera/recaudo. |
| [ ] | [ ] | Reservas de zonas comunes, mantenimiento, PQRS, asambleas/votaciones u otros módulos comunitarios. |
| [ ] | [ ] | API para integrar aplicaciones externas. |
| [ ] | [ ] | Auditoría de acciones, backups/restauración, retención y monitoreo. |

## 6. Prioridades elegidas

Completa tras revisar las casillas anteriores:

- **Imprescindible para salida inicial:** __________________________________________
- **Puede quedar para una fase posterior:** _______________________________________
- **Se excluye expresamente:** __________________________________________________
- **Fecha objetivo / fases:** ____________________________________________________
- **Responsable que valida reglas y datos:** ______________________________________

## Referencias del repositorio

- [README](README.md): instalación, configuración y reglas operativas.
- [Requisitos de la solución actual](REQUISITOS_SOLUCION_ACTUAL.md): especificación y limitaciones.
- [Mapa técnico y flujos](MAPA_TECNICO_Y_FLUJOS.md): módulos y responsabilidades.
- Esquema y evolución de datos: `database/migrations/001` a `database/migrations/019`.
