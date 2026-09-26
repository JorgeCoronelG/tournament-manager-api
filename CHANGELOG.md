# Release Notes

Historial de este proyecto base. Al final se conserva, sin cambios, el historial del esqueleto oficial de Laravel 10 del que se partió.

## [Unreleased]

### Cambios que rompen compatibilidad

- **Roles:** la relación `User` ↔ `Role` pasó de uno a muchos a muchos a muchos. Se eliminó `users.role_id` y se agregó la tabla pivote `role_user`. `User::role()` (un solo rol) se reemplazó por `User::roles()`; `Role::users()` ahora es `belongsToMany`. El middleware `permission` autoriza si el usuario tiene **alguno** de los roles indicados.
- **Eliminación lógica:** `users` y `roles` usan `SoftDeletes` (columna `deleted_at`). `delete()`, `bulkDelete()` y las consultas de listado ya no eliminan la fila físicamente; queda oculta por el scope global y se puede consultar con `withTrashed()` o restaurar con `restore()`.

## [2.0.0] - 2026-09-18

Primera versión numerada. Actualiza el proyecto de Laravel 10 a Laravel 13 e incluye cambios que **rompen compatibilidad** con proyectos creados a partir de la versión anterior.

### Cambios que rompen compatibilidad

- **Framework y dependencias:** Laravel 10.31 → 13.32, PHP mínimo 8.3 (se desarrolla con 8.5), Sanctum 3 → 4, spatie/laravel-data 3 → 4, PHPUnit 10 → 11, tinker 2 → 3. Laravel 11 no se usó de paso porque Composer bloquea todas sus versiones por avisos de seguridad.
- **Filtros (`AdvancedFilter`):** ahora se combinan con **AND** y agrupados en un paréntesis; antes eran todos `OR` sueltos, lo que además rompía otros `where` y scopes globales. Para usar OR se indica `"boolean": "or"` en el filtro.
- **Filtros: whitelist obligatoria.** Cada modelo debe declarar `public array $allowedFilters`, igual que `$allowedSorts`. Un campo fuera de la lista responde `400`; si el modelo no la declara y se filtra, responde `500` con un mensaje que lo indica. Antes se podía filtrar por cualquier columna.
- **Filtros: el `value` debe ser escalar o `null`.** Un arreglo u objeto JSON responde `400`.
- **`BaseService::findAllPaginated`** recibe un `ListQuery` en lugar de `Request`. En el controller: `ListQuery::fromRequest($request)`.
- **Constructores:** `BaseRepository` y `BaseService` reciben sus dependencias por constructor. Las subclases deben llamar a `parent::__construct(...)` en lugar de asignar `$this->entity` o `$this->entityRepository`.
- **Ids `int|string`** (UUID/ULID) en repositorios y servicios.
- **`BaseRepositoryInterface`** se compone ahora de `ReadableRepositoryInterface`, `WritableRepositoryInterface`, `BulkRepositoryInterface` y `RelationSyncRepositoryInterface`. `bulkDelete` devuelve `int` (antes `bool`).
- **Genéricos:** `BaseRepository`, `BaseService` y sus interfaces usan `@template TModel`. Las clases hijas deben anotar `@extends BaseRepository<Modelo>` (o `BaseService<Modelo>`) para que Larastan acepte los tipos; los generadores ya lo hacen.
- **`findRandom`** lanza `ModelNotFoundException` (404) si no hay registros; antes daba un `TypeError`.
- **Paginación:** `showAll` con una colección paginada responde `data`, `links` y `meta` (formato de `PaginateCollection`); antes devolvía solo el arreglo de registros. Los enlaces de página conservan `per_page`, `sort` y `q`.
- **Enums:** se eliminó `App\Helpers\Enum`; se usa únicamente `App\Core\Enum`.
- **Docker / `.env`:** `DB_HOST=mysql` (nombre del servicio) y credenciales por defecto `laravel` / `secret` en lugar de `root` sin contraseña. Quien tenga un `.env` anterior debe actualizarlo.

### Nuevo

- **Docker:** `Dockerfile` (PHP 8.5), `docker-compose.yml` con la API, MySQL 8.4 y phpMyAdmin, y un `Makefile` (`make setup`, `up`, `test`, `stan`, `lint`, `format`, `artisan`, `composer`, `shell`). Solo requiere Docker instalado.
- **Generadores:** `make:repository` y `make:service` crean la interfaz en `Contracts`, la implementación y el binding en el provider. Aceptan subcarpetas, `--model`, `--repository` y `--force`. Plantillas en `stubs/`.
- **`ListQuery`:** filtros, orden y tamaño de página independientes de HTTP.
- **Roles:** tabla `roles` (`id` tinyint, `nombre`), columna `users.role_id` (nullable, con llave foránea), modelo `Role` con factory y relación `User` → `Role`.
- **Calidad:** Larastan en el nivel máximo (9), Pint (preset `laravel`, excluye `resources/lang`) y un workflow de GitHub Actions que corre Pint, Larastan y los tests con PHP 8.5.
- **Tests:** de los tests de ejemplo de Laravel a 119 (repositorio, servicio, filtros, orden, `ListQuery`, generadores, manejo de excepciones, paginación, roles, Sanctum con tokens reales y límite de intentos de login).
- **README:** guía de instalación, comandos, creación de repositorios y servicios, filtros, actualizaciones parciales, roles, un ejemplo completo con login por token y cómo limitar los intentos de login.

### Corregido

- **`Permission`** denegaba siempre el acceso porque los parámetros de middleware llegan como `string` y se comparaba con `===`. Ahora también responde `401` sin usuario autenticado y `403` si el usuario no tiene rol.
- **`Sortable`** exigía `allowedSorts` aunque no se pidiera orden (`500`).
- **Filtros:** `IS NULL` / `IS NOT NULL` ya no exigen `value`; un operador desconocido o un tipo incorrecto responde `400` en lugar de `500`.
- **Parámetros repetidos como arreglo** (`?q[]=x`, `?sort[]=x`, `?per_page[]=1`) respondían `500` por un `TypeError`; ahora `400`.
- **`validateDate`** usaba `&&` en lugar de `||` y dejaba pasar fechas inválidas como `2024/13/01`.
- **`ServiceLogicServiceProvider`** no estaba registrado en `config/app.php`, por lo que los bindings de servicios no se aplicaban. También se eliminó un `AppServiceProvider` duplicado.
- **Deprecaciones de PHP 8.4/8.5:** tipos nullable implícitos y `PDO::MYSQL_ATTR_SSL_CA`. En Laravel 13, `VerifyCsrfToken` pasó a extender `PreventRequestForgery`.
- **Docker:** la API no llegaba a MySQL porque `php artisan serve` ignora las variables de entorno del contenedor y lee el `.env`.

---

## Historial del esqueleto de Laravel 10 (heredado)

## [Unreleased](https://github.com/laravel/laravel/compare/v10.2.7...10.x)

## [v10.2.7](https://github.com/laravel/laravel/compare/v10.2.6...v10.2.7) - 2023-10-31

- Postmark mailer configuration update by [@ninjaparade](https://github.com/ninjaparade) in https://github.com/laravel/laravel/pull/6228
- [10.x] Update sanctum config file by [@ahmed-aliraqi](https://github.com/ahmed-aliraqi) in https://github.com/laravel/laravel/pull/6234
- [10.x] Let database handle default collation by [@Jubeki](https://github.com/Jubeki) in https://github.com/laravel/laravel/pull/6241
- [10.x] Increase bcrypt rounds to 12 by [@valorin](https://github.com/valorin) in https://github.com/laravel/laravel/pull/6245
- [10.x] Use 12 bcrypt rounds for password in UserFactory by [@Jubeki](https://github.com/Jubeki) in https://github.com/laravel/laravel/pull/6247
- [10.x] Fix typo in the comment for token prefix (sanctum config) by [@yuters](https://github.com/yuters) in https://github.com/laravel/laravel/pull/6248
- [10.x] Update fixture hash to match testing cost by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/laravel/pull/6259
- [10.x] Update minimum `laravel/sanctum` by [@crynobone](https://github.com/crynobone) in https://github.com/laravel/laravel/pull/6261
- [10.x] Hash improvements by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/laravel/pull/6258
- Redis maintenance store config example contains an excess space by [@hedge-freek](https://github.com/hedge-freek) in https://github.com/laravel/laravel/pull/6264

## [v10.2.6](https://github.com/laravel/laravel/compare/v10.2.5...v10.2.6) - 2023-08-10

- Bump `laravel-vite-plugin` to latest version by [@adevade](https://github.com/adevade) in https://github.com/laravel/laravel/pull/6224

## [v10.2.5](https://github.com/laravel/laravel/compare/v10.2.4...v10.2.5) - 2023-06-30

- Allow accessing APP_NAME in Vite scope by [@domnantas](https://github.com/domnantas) in https://github.com/laravel/laravel/pull/6204
- Omit default values for suffix in phpunit.xml by [@spawnia](https://github.com/spawnia) in https://github.com/laravel/laravel/pull/6210

## [v10.2.4](https://github.com/laravel/laravel/compare/v10.2.3...v10.2.4) - 2023-06-07

- Add `precognitive` key to $middlewareAliases by @emargareten in https://github.com/laravel/laravel/pull/6193

## [v10.2.3](https://github.com/laravel/laravel/compare/v10.2.2...v10.2.3) - 2023-06-01

- Update description by @taylorotwell in https://github.com/laravel/laravel/commit/85203d687ebba72b2805b89bba7d18dfae8f95c8

## [v10.2.2](https://github.com/laravel/laravel/compare/v10.2.1...v10.2.2) - 2023-05-23

- Add lock path by @taylorotwell in https://github.com/laravel/laravel/commit/a6bfbc7f90e33fd6cae3cb23f106c9689858c3b5

## [v10.2.1](https://github.com/laravel/laravel/compare/v10.2.0...v10.2.1) - 2023-05-12

- Add hashed cast to user password by @emargareten in https://github.com/laravel/laravel/pull/6171
- Bring back pusher cluster config option by @jesseleite in https://github.com/laravel/laravel/pull/6174

## [v10.2.0](https://github.com/laravel/laravel/compare/v10.1.1...v10.2.0) - 2023-05-05

- Update welcome.blade.php by @aymanatmeh in https://github.com/laravel/laravel/pull/6163
- Sets package.json type to module by @timacdonald in https://github.com/laravel/laravel/pull/6090
- Add url support for mail config by @chu121su12 in https://github.com/laravel/laravel/pull/6170

## [v10.1.1](https://github.com/laravel/laravel/compare/v10.0.7...v10.1.1) - 2023-04-18

- Fix laravel/framework constraints for Default Service Providers by @Jubeki in https://github.com/laravel/laravel/pull/6160

## [v10.0.7](https://github.com/laravel/laravel/compare/v10.1.0...v10.0.7) - 2023-04-14

- Adds `phpunit/phpunit@10.1` support by @nunomaduro in https://github.com/laravel/laravel/pull/6155

## [v10.1.0](https://github.com/laravel/laravel/compare/v10.0.6...v10.1.0) - 2023-04-15

- Minor skeleton slimming by @taylorotwell in https://github.com/laravel/laravel/pull/6159

## [v10.0.6](https://github.com/laravel/laravel/compare/v10.0.5...v10.0.6) - 2023-04-05

- Add job batching options to Queue configuration file by @AnOlsen in https://github.com/laravel/laravel/pull/6149

## [v10.0.5](https://github.com/laravel/laravel/compare/v10.0.4...v10.0.5) - 2023-03-08

- Add replace_placeholders to log channels by @alanpoulain in https://github.com/laravel/laravel/pull/6139

## [v10.0.4](https://github.com/laravel/laravel/compare/v10.0.3...v10.0.4) - 2023-02-27

- Fix typo by @izzudin96 in https://github.com/laravel/laravel/pull/6128
- Specify facility in the syslog driver config by @nicolus in https://github.com/laravel/laravel/pull/6130

## [v10.0.3](https://github.com/laravel/laravel/compare/v10.0.2...v10.0.3) - 2023-02-21

- Remove redundant `@return` docblock in UserFactory by @datlechin in https://github.com/laravel/laravel/pull/6119
- Reverts change in asset helper by @timacdonald in https://github.com/laravel/laravel/pull/6122

## [v10.0.2](https://github.com/laravel/laravel/compare/v10.0.1...v10.0.2) - 2023-02-16

- Remove unneeded call by @taylorotwell in https://github.com/laravel/laravel/commit/3986d4c54041fd27af36f96cf11bd79ce7b1ee4e

## [v10.0.1](https://github.com/laravel/laravel/compare/v10.0.0...v10.0.1) - 2023-02-15

- Add PHPUnit result cache to gitignore by @itxshakil in https://github.com/laravel/laravel/pull/6105
- Allow php-http/discovery as a composer plugin by @nicolas-grekas in https://github.com/laravel/laravel/pull/6106

## [v10.0.0 (2022-02-14)](https://github.com/laravel/laravel/compare/v9.5.2...v10.0.0)

Laravel 10 includes a variety of changes to the application skeleton. Please consult the diff to see what's new.
