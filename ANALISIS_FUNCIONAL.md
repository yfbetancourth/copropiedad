# Análisis funcional y propuestas — Copropiedad

**Fecha:** 18 de noviembre de 2026
**Base:** código de `develop` (módulos Auth, Configuration, PropertyAssignment, Billing, Messaging, Classifieds, Home), migraciones 001–020, documentación existente y pruebas contra una copia de la base local.

Complementa [REQUISITOS_SOLUCION_ACTUAL.md](REQUISITOS_SOLUCION_ACTUAL.md) (qué hace hoy la aplicación) con un diagnóstico: defectos encontrados, cambios aplicados y funciones que faltan, priorizadas.

## 1. Diagnóstico general

La aplicación cubre bien la **configuración de la copropiedad**, la **seguridad de acceso** (CSRF, bloqueo, permisos por módulo en servidor, cifrado de secretos) y un ciclo de **emisión de notas de cobro** cuidadoso (instantáneas, emisión única, envío con reintento, pagos Bold verificados con el proveedor).

Las debilidades estaban en lo que pasa **después de emitir**: corrección de errores, pagos que llegan tarde, visibilidad de la cartera y seguimiento de morosidad. El panel administrativo estaba vacío y no existía ningún reporte ni exportación.

| Área | Estado | Observación principal |
|---|---|---|
| Acceso y seguridad | Sólido | Sin auditoría general de acciones sensibles. |
| Roles y permisos | Sólido | Niveles read/write/total bien aplicados en servidor. |
| Parametrización / financiera | Parcial | Mora, multas y cuota extraordinaria se guardan pero **ningún proceso las usa**. |
| Conformación de inmuebles | Sólido | Sin carga masiva de coeficientes; en la base local 170 de 170 inmuebles no tienen coeficiente. |
| Asignaciones | Funcional | No distingue propietario / residente / arrendatario. |
| Cartera: emisión | Sólido | Corregido: saldo inicial duplicado. |
| Cartera: pagos | **Corregido** | Permitía pagar notas ya trasladadas (cobro doble) y no permitía corregir un pago erróneo. |
| Reportes / panel | **Agregado** | No existían. |
| Portal del residente | Funcional | Mostraba como pagables notas cuyo saldo ya estaba en la nota siguiente. |
| Mensajería | Básico | Sin correo, adjuntos ni plantillas. |
| Operación | Débil | Migraciones manuales sin registro; la base local va atrasada (faltan 018 y 019). |

## 2. Defectos encontrados y corregidos

### D1. El saldo inicial se contaba dos veces (alta)

En la primera nota de un inmueble (sin nota anterior), un concepto **Saldo inicial / ajuste** se sumaba al saldo anterior *y* otra vez como ajuste. Un saldo inicial de $50.000 terminaba en la nota como $100.000.

- Corrección: [BillingService.php](app/Modules/Billing/BillingService.php), método `preview()`: sin nota previa, el saldo de apertura es el ajuste.
- Verificado: con un saldo inicial de $50.000 la previsualización muestra $50.000.
- Acción recomendada: revisar si ya se emitieron notas de primer período con saldo inicial; en la base local no hay ninguna.

### D2. Se podía pagar una nota cuyo saldo ya pasó a la nota siguiente (alta)

Al emitir el período N, el saldo de la nota N‑1 queda **congelado** en la nota N. Pero el portal seguía ofreciendo pagar la nota N‑1 (Bold en línea y datáfono), y el registro manual también lo permitía. Resultado: el residente podía pagar dos veces la misma deuda, y un pago registrado en la nota vieja no se reflejaba en la nueva.

- Corrección: el concepto de **nota trasladada** (`superseded_by`) se calcula en `BillingService::statement()`; bloquean el pago `addPayment()`, `BoldPaymentService::createPaymentLink()` y `BoldTerminalService::startPayment()`.
- El portal muestra «Incluido en AAAA‑MM» y «Paga la nota más reciente» en lugar de botones de pago; el formulario administrativo solo lista notas vigentes.
- Al emitir, la previsualización advierte si hay pagos en línea o en datáfono pendientes sobre notas anteriores.
- Los pagos que aun así llegan tarde (confirmaciones tardías de Bold) se detectan y se muestran en el panel y en Reportes como «Pagos que requieren ajuste».

### D3. Un pago registrado por error no se podía corregir (media)

No existía ninguna forma de reversar un pago manual mal digitado.

- Nueva función: **anulación de pagos** con motivo obligatorio, solo con permiso **Total** de Cartera. El pago no se borra: queda con fecha, usuario y motivo, y deja de contar en los saldos.
- No se anulan pagos confirmados por Bold (deben reversarse con el proveedor) ni pagos de notas ya trasladadas (se corrigen con un concepto de ajuste en el período vigente).
- Requiere la migración `020_add_payment_voiding.sql`.

### D4. `php -S` no enrutaba las páginas internas de los paneles (media, entorno de desarrollo)

Con el comando del README (`php -S … public/router.php`), toda ruta bajo `/admin/…` o `/usuario/…` (por ejemplo `/admin/mensajes`) respondía 404: el servidor interno resolvía el script a `admin/index.php` y `router.php` tomaba `/admin` como prefijo de la aplicación. Corregido en [public/router.php](public/router.php). Apache no estaba afectado.

## 3. Funciones agregadas

| Función | Dónde | Permiso |
|---|---|---|
| **Panel administrativo con indicadores**: cartera por cobrar, recaudo del mes, inmuebles con nota vencida, notas emitidas del período, saldos a favor, 5 mayores saldos y lista de pendientes (correos fallidos o pendientes, pagos que requieren ajuste, inmuebles sin usuario o sin coeficiente, período sin emitir). | `/admin` | `panel` (los indicadores requieren `cartera.cobros` lectura) |
| **Reportes de cartera**: estado por inmueble con la nota vigente, antigüedad de saldos (al día, corriente, 1–30, 31–60, 61–90, +90 días) con filtro, recaudo de los últimos 12 meses y búsqueda. | `/cartera/reportes` | `cartera.cobros` lectura |
| **Exportación CSV** de cartera y de pagos recibidos en un mes (incluye anulados y su motivo). Separador `;`, UTF‑8 con BOM para Excel en español y protección contra inyección de fórmulas. | `/cartera/reportes/exportar?tipo=cartera` y `?tipo=pagos&mes=AAAA-MM` | `cartera.cobros` lectura |
| **Anulación de pagos** con motivo y trazabilidad. | Cartera / Cobros, tabla «Pagos registrados en notas del período» | `cartera.cobros` total |
| **Advertencia de pagos en línea pendientes** antes de emitir. | Previsualización de Cartera / Cobros | — |

La antigüedad es una **estimación**: períodos consecutivos con saldo arrastrado, más el período vigente si ya pasó su vencimiento. No reemplaza un cálculo contable por fecha de cada cargo.

## 4. Propuestas pendientes (no implementadas)

Ordenadas por impacto para la administración. Las marcadas **(decisión)** requieren definir una regla de negocio antes de construir.

### Prioridad alta

1. **Aplicar migraciones pendientes en la base local** (018, 019 y la nueva 020). Sin la 018, la emisión y la previsualización de cobros fallan con «Unknown column coefficient»; sin la 019, el portal falla al consultar pagos de datáfono. La 020 ya quedó aplicada en la base local durante esta revisión.
2. **Registro de migraciones** (`schema_migrations` + `bin/migrate.php` con opción de línea base). Hoy son 20 comandos manuales sin forma de saber cuáles se aplicaron, y el desfase anterior lo demuestra.
3. **Carga masiva de coeficientes y asignaciones (CSV)**. Con cientos de inmuebles, digitarlos uno por uno bloquea la emisión.
4. **Uso asistido de la configuración financiera (decisión)**. Mora, multa base y cuota extraordinaria con vigencia se guardan pero no se usan. Propuesta: en la previsualización, *sugerir* el interés de mora sobre saldos vencidos (según tipo, valor y días de gracia) y la línea de cuota extraordinaria vigente, para que el administrador confirme con un clic. Así se mantiene la regla de emisión asistida.
5. **Bitácora de auditoría** de acciones sensibles: emisión, pagos, anulaciones, cambios de permisos, configuración financiera y bancaria, asignaciones. Tabla `audit_log` (actor, acción, entidad, antes/después, IP, fecha) y consulta con filtro.
6. **Pruebas automatizadas** (PHPUnit) sobre `settle()`, emisión idempotente, traslado de saldos, anulación y permisos. El script de verificación usado en esta revisión cubre 26 casos y puede ser el punto de partida.

### Prioridad media

7. **Recordatorios automáticos** (tarea programada): aviso antes de la fecha de pronto pago y al vencer, solo a inmuebles con saldo.
8. **Estado de cuenta del residente**: historial de notas y pagos por inmueble, descargable, con saldo a favor visible.
9. **Conciliación bancaria básica**: importar el extracto (CSV), proponer coincidencias por valor y referencia y registrar pagos en lote.
10. **Notas de ajuste / crédito formales (decisión)**: hoy un error en una nota emitida se corrige con conceptos en el período siguiente; definir si se requiere un documento de corrección con su propio consecutivo.
11. **Titularidad del inmueble (decisión)**: tipo de relación (propietario, residente, arrendatario, responsable de pago) para decidir a quién se cobra, quién vota y quién recibe comunicados.
12. **Mensajería por correo** con plantillas, adjuntos y envío segmentado por torre o tipo de inmueble.

### Prioridad baja / nuevos módulos

13. Reservas de zonas comunes con reglas de horario y costo.
14. PQRS (peticiones, quejas, reclamos) con estados, responsables y tiempos de respuesta.
15. Asambleas: convocatoria, quórum y votación ponderada por coeficiente.
16. Repositorio de documentos (reglamento, actas, estados financieros) visible en el portal.
17. Facturación electrónica / integración DIAN, si la copropiedad está obligada **(decisión)**.

## 5. Archivos modificados

| Archivo | Cambio |
|---|---|
| `app/Modules/Billing/BillingService.php` | Corrección D1; regla única `settle()`; `newerStatement()`, `voidPayment()`, `periodPayments()`; exclusión de pagos anulados; bloqueo de pagos en notas trasladadas; advertencia de pagos en línea pendientes. |
| `app/Modules/Billing/BillingController.php`, `routes.php` | Acción y ruta `POST /cartera/cobros/pago/anular`; rutas de reportes. |
| `app/Modules/Billing/ReportService.php`, `ReportController.php` (nuevos) | Cartera por inmueble, antigüedad, recaudo, indicadores del panel, CSV. |
| `app/Modules/Billing/BoldPaymentService.php`, `BoldTerminalService.php` | Rechazan el pago de notas trasladadas. |
| `app/Modules/Auth/AuthController.php` | El panel carga los indicadores sin romperse si fallan. |
| `app/Scenarios/Admin/Views/Auth/admin.php` | Panel con indicadores y pendientes. |
| `app/Scenarios/Admin/Views/Billing/index.php`, `reports.php` (nuevo) | Pagos del período con anulación, advertencias, marca de nota trasladada; vista de reportes. |
| `app/Scenarios/Admin/layout.php` | Menú «Reportes de cartera». |
| `app/Scenarios/User/Views/Portal/index.php`, `payments.php` | Notas trasladadas sin botones de pago. |
| `public/router.php` | Corrección D4. |
| `database/migrations/020_add_payment_voiding.sql` (nueva) | Columnas de anulación en `billing_payments`. |

## 6. Despliegue

```powershell
Get-Content .\database\migrations\020_add_payment_voiding.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
```

Aplicar después de la 019. Para usar la anulación, un rol necesita el nivel **Total** en **Cartera / Cobros**.
