# Catalog operations

Catalog is an optional Laravel module that is enabled in a fresh checkout. Its product data uses the named `catalog` MySQL connection, separate from the host application's database. Configure `CATALOG_DB_CONNECTION=catalog` and the `CATALOG_DB_*` values in `.env`; the Catalog schema must use unprefixed table names. The host's `DB_*` settings continue to point to the application database, where Catalog permission definitions are stored.

## Initial setup and upgrades

Create the Catalog database and grant the application account permission to create and alter its tables. After installing the root Composer dependencies and configuring both database connections, run:

```bash
php artisan migrate --seed
php artisan module:migrate Catalog --database=catalog --force
php artisan db:seed --class='Modules\Catalog\Database\Seeders\CatalogDatabaseSeeder' --database=catalog --force
```

The first command migrates and seeds the host database, including the Catalog permission definitions. Its migration records are stored in the host database. Catalog's explicit module migration stores its migration records in the Catalog database; the two histories are independent. Laravel Modules migration auto-discovery is disabled so Catalog migrations cannot run as part of host `migrate --seed`. The host registers the Catalog migration command once through `bootstrap/app.php` so its database/path guards also protect a fresh CLI process when Catalog is disabled. The command refuses a disabled Catalog, a Catalog connection mismatch, and migrations for other modules on the Catalog connection. Run the module migration explicitly after a Catalog code upgrade, and back up existing Catalog data before applying schema changes. Run the Catalog seeder only when installing its initial example data; it targets the Catalog database and is separate from the host seeder.

## Assets and document generation

Catalog assets have their own `package-lock.json`. Build them from the module directory after installing Node.js 24 and npm:

```bash
cd Modules/Catalog
npm ci
npm run build:assets
```

The module serves its generated assets independently of the host Vite build. PDF generation invokes external tools: install Typst for Typst documents and `pdflatex` for LaTeX documents. Set `CATALOG_TYPST_BIN` or `CATALOG_PDFLATEX_BIN` when the executable is not available under its default command name.

The host backup defaults still cover the application database and `storage/app/private`. To include Catalog in deployment backups, add its connection name to `backup.backup.source.databases` and its configured storage root to `backup.backup.source.files.include` in `config/backup.php`. Keep backup destinations private and verify the combined archive on the Linux backup worker after changing those sources.

## Access and compatibility

The host seeder creates `catalog.view`, `catalog.create`, `catalog.update`, and `catalog.delete` in the application database. It does not grant these permissions to the `Admin` role automatically. Assign only the permissions needed through `/admin/roles`; active Super Admins use the host application's existing bypass. Filament operations authorize writes through `CatalogAdminService` and `CatalogPolicy`, then invoke the imported domain services. After disabling Catalog or changing its module code, clear Laravel and Filament's component caches with `php artisan optimize:clear` and `php artisan filament:optimize-clear`; before serving a production release, rebuild the caches with `php artisan optimize` and `php artisan filament:optimize`.

The Catalog navigation contains five resources with List, View, Create, and Edit pages: Categories and series, Products, Series fields, Typst templates, and legacy LaTeX templates. Native tables provide search, sorting, pagination, and domain-appropriate filters. Delete actions use the same domain services. No additional panel, widgets, CSS, or JavaScript is required. File values are displayed read-only in Filament; original upload endpoints own files. Series requiring file attributes use the original interface when creating products. Typst global/series scope and field series/scope cannot change after creation. The LaTeX resource requires a description to avoid triggering the intentionally preserved empty-description defect in the legacy API.

The module retains its legacy API and page contracts. See the [API reference](../Modules/Catalog/API.md) for routes and known compatibility behaviors, and the [module README](../Modules/Catalog/README.md) for module structure and migration details. Legacy APIs remain public where existing integrations require that behavior; configure network access and CORS for the deployment.
