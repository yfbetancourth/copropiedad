# Copropiedad

Aplicacion PHP 8.2+ con MVC modular, AdminLTE 4 y MySQL mediante PDO.

## Preparacion local

1. Instala PHP 8.2 o superior con `pdo_mysql`, Composer y MySQL.
2. Desde la carpeta del proyecto instala las dependencias:

   ```powershell
   composer install
   ```

3. Crea la configuracion local a partir de la plantilla y define una contrasena fuerte:

   ```powershell
   Copy-Item .env.example .env
   ```

   Edita `.env`; el archivo esta excluido de Git.

4. Crea la base de datos y un usuario de aplicacion separado. Ejecuta como administrador de MySQL, cambiando la contrasena de ejemplo:

   ```sql
   CREATE DATABASE copropiedad CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'copropiedad_app'@'127.0.0.1' IDENTIFIED BY 'reemplazar-por-una-contrasena-larga';
   GRANT SELECT, INSERT, UPDATE, DELETE ON copropiedad.* TO 'copropiedad_app'@'127.0.0.1';
   ```

   Usa esas credenciales en `.env`. No uses el usuario `root` desde la aplicacion.

5. Comprueba la conexion:

   ```powershell
   php bin/check-database.php
   ```

6. Para una prueba local, inicia el servidor con `public` como raiz web:

   ```powershell
   php -S 127.0.0.1:8080 -t public
   ```

   Abre `http://127.0.0.1:8080/login`. En Apache, configura el `DocumentRoot` del sitio hacia la carpeta `public`, no hacia la raiz del proyecto. Si se usa XAMPP en `http://localhost/Copropiedad/public`, ajusta `APP_URL` a esa direccion en `.env`.

## Estructura modular

- `app/Core`: infraestructura compartida (enrutador, vistas y conexion).
- `app/Modules/<Modulo>`: rutas, controlador y vistas propias del modulo.
- `app/Views`: plantilla compartida de AdminLTE.
- `public`: unico directorio expuesto al servidor web.

Cada modulo debe registrar solo sus rutas y mantener su logica y vistas dentro de su propia carpeta. Los modulos acceden a MySQL a traves de `App\Core\Database`; usa consultas preparadas y nunca construyas SQL concatenando datos recibidos.

La conexion usa PDO con errores como excepciones, consultas preparadas nativas, `utf8mb4` y credenciales externas al codigo. Para un servidor MySQL remoto se requiere configurar `DB_SSL_CA` con el certificado CA legible para PHP; el certificado del servidor se valida.

## Acceso administrativo y recuperacion

Aplica una vez el esquema de autenticacion desde la raiz del proyecto:

```powershell
Get-Content .\database\migrations\001_create_admin_auth.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
```

Configura SMTP autenticado en `.env` (`MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION=tls`, `MAIL_FROM_ADDRESS`) y la URL publica en `APP_URL` para enviar enlaces de recuperacion. Para Gmail usa SMTP `smtp.gmail.com:587`, cifrado TLS y una contrasena de aplicacion de Google (no la contrasena de inicio de sesion). El enlace expira en una hora, solo se almacena el hash del token y el token se invalida al usarlo. La respuesta no revela si el correo esta registrado. En produccion `APP_URL` debe usar HTTPS.

Para crear el primer usuario administrativo, define `SUPERADMIN_PASSWORD` temporalmente en el entorno del proceso y ejecuta:

```powershell
php .\bin\create-superadmin.php
```

La cuenta se crea como `Superadmin` con el rol `Superadmin`; la contrasena se almacena con `password_hash` y el comando no reemplaza una cuenta existente. No guardes la contrasena en archivos del proyecto. El login bloquea temporalmente la cuenta despues de cinco intentos fallidos y las sesiones expiran por inactividad.

## Configuracion de equipo y permisos

Aplica una sola vez la migracion de roles y permisos con una cuenta MySQL administradora:

```powershell
Get-Content .\database\migrations\002_create_configuration_roles.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
```

En el panel, abre **Configuracion / Equipo** para crear, editar, activar y desactivar cuentas, y **Configuracion / Roles y permisos** para definir roles personalizados. La migracion conserva las cuentas existentes y convierte el rol actual en una relacion con `admin_roles`. Las cuentas desactivadas conservan su registro y sus datos; su acceso se revoca inmediatamente.

Los niveles son **Lectura** (consultar), **Escritura** (consultar, crear y editar) y **Total** (todas las acciones, incluida la desactivacion/eliminacion). La autorizacion se verifica del lado del servidor en cada ruta. Un administrador no puede conceder permisos por encima de los propios; el rol de sistema Superadmin no se puede editar, eliminar, asignar desde el formulario ni desactivar.

## Parametrizacion de copropiedad y financiera

Aplica la migracion una sola vez con una cuenta MySQL administradora:

```powershell
Get-Content .\database\migrations\003_create_property_settings.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
```

En **Configuracion / Parametrizacion** se guardan el nombre, direccion, NIT y, opcionalmente, los datos completos del titular, banco, tipo y numero de cuenta. En bases donde ya se aplico la migracion 003, aplica una sola vez la migracion adicional `004_add_bank_account_number.sql` para agregar el campo bancario sin afectar los demas datos. En **Configuracion / Financiera** se guardan el valor de administracion, su fecha de vigencia, el descuento fijo por pronto pago, la mora en porcentaje o valor fijo con sus dias de espera, y el valor base de las multas. En **Configuracion / Caracterizacion** se define si la copropiedad es una casa, un conjunto de torres de apartamentos o una copropiedad mixta.

Aplica tambien las migraciones `004_add_bank_account_number.sql` (si aun no se ha aplicado) y `005_add_housing_type.sql` una sola vez:

```powershell
Get-Content .\database\migrations\004_add_bank_account_number.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\005_add_housing_type.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\006_add_property_composition.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\007_add_tower_nomenclature.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\008_rename_tower_to_apartment_nomenclature.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
Get-Content .\database\migrations\009_split_tower_and_apartment_nomenclature.sql | & C:\xampp\mysql\bin\mysql.exe --protocol=tcp -h 127.0.0.1 -u root copropiedad
```

La migracion 005 agrega el tipo de vivienda como un dato opcional y un permiso independiente para Caracterizacion; la migracion 006 habilita el tipo Mixto y crea el almacenamiento de la estructura de bienes; las migraciones 007 a 009 agregan y separan la nomenclatura de torres y apartamentos. En **Configuracion / Caracterizacion**, para **Torres de apartamentos** y **Mixto** se puede elegir nomenclatura de torres numerica (Torre 1, Torre 2…) o alfabetica (Torre A, Torre B… Torre Z, Torre AA…). Los apartamentos usan siempre numeracion de dos digitos, del 01 al 99; 00 no es valido. En **Configuracion / Conformación de la copropiedad**, el administrador completa un asistente que guarda el avance, genera inmuebles a partir de las cantidades y permite corregir o agregar categorias (por ejemplo, locales u oficinas) antes de finalizar. La estructura admite hasta 5.000 inmuebles. Si se cambia la caracterizacion o la nomenclatura despues de comenzar, se solicita confirmacion y se reinician los datos de conformacion. El permiso de Conformacion se inicializa a partir del permiso de Caracterizacion; los permisos se pueden ajustar en **Configuracion / Roles y permisos**. Guardar parametros no emite facturas, crea cobros ni aplica mora o multas automaticamente.
