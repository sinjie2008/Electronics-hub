# Catalog

Laravel 13 / nWidart Modules v13 的原生 Catalog 模块，安装到宿主 Modules/Catalog/。保留 Catalog、Spec Search、CSV、Typst、LaTeX 的六个页面和原有 API。Filament 页面改造留待后续；模块可与宿主 Filament 5 / coolsam/modules 5.x 同时运行。

## 安装

需要 PHP 8.3+、mysqli、pdo_mysql、mbstring、fileinfo 和宿主 MySQL/MariaDB。PDF 功能需要 Typst / pdflatex；模块不附带编译器。Node.js 仅用于构建前端。

按 [nWidart v13 安装说明](https://laravelmodules.com/docs/13/getting-started/installation-and-setup) 设置宿主，在宿主 composer.json 合并以下设置：

~~~json
{
    "require": {
        "nwidart/laravel-modules": "^13.0",
        "wikimedia/composer-merge-plugin": "^2.1"
    },
    "config": {
        "allow-plugins": {"wikimedia/composer-merge-plugin": true}
    },
    "extra": {
        "merge-plugin": {"include": ["Modules/*/composer.json"]}
    }
}
~~~

将本目录放入 Modules/Catalog/，在宿主根目录执行：

~~~shell
composer update nwidart/laravel-modules wikimedia/composer-merge-plugin --with-dependencies
composer dump-autoload
php artisan module:list
php artisan module:enable Catalog
php artisan vendor:publish --tag=catalog-config
~~~

module.json 是 providers 的唯一入口。不要将 CatalogServiceProvider 再加入宿主 providers 或 Composer package discovery；Composer 能加载类不等于模块已启用。

## 配置

宿主 config/database.php 配置一个无 table prefix 的 mysql/mariadb connection，例如 catalog；凭据由宿主提供。在宿主 config/catalog.php 设置：

~~~php
<?php
return [
    'prefix' => 'catalog',
    'legacy_urls' => true,
    'project_root_urls' => false,
    'root_typst_api' => 'public',
    'connection' => 'catalog',
    'storage_root' => storage_path('app/catalog'),
    'middleware' => [],
    'settings' => [
        'typst' => ['binary' => env('CATALOG_TYPST_BIN', 'typst')],
        'latex' => ['default_binary' => env('CATALOG_PDFLATEX_BIN', 'pdflatex')],
    ],
];
~~~

宿主设置递归覆盖模块 defaults；config:cache 后使用缓存值。默认模块地址 /catalog。子目录部署的 APP_URL 包含宿主路径，例如 https://example.com/app，页面 base、资源和下载 URL 会包含 /app/catalog。base_url 可显式指定外部模块地址。

legacy_urls 保留 root /catalog.php、/api/*、六个 .html 页面及 /storage/*。原来以 public/ 为 document root 的安装使用 root_typst_api=public。原来暴露项目根目录的安装使用 root_typst_api=root，使 root /api/typst/templates.php 和 variables.php 保留原 adapter 行为；project_root_urls 同时提供 /public/*。/catalog/api/typst/* 始终是原 public file API，/legacy/api/typst/* 明确提供原 root adapter。

middleware 默认为空，保留现有公开 API、方法和无 CSRF token 的调用。宿主全局 middleware 仍生效；认证/CORS 由宿主按客户端需求配置。TrimStrings / ConvertEmptyStringsToNull 仅对精确匹配的 Catalog API 地址跳过，避免修改原输入和宿主其他页面。

## 数据安装和升级

先确认同名表属于 Catalog；不兼容同名表在 DDL 前拒绝迁移。迁移不会创建数据库。新安装及旧 standalone 数据使用同一命令：

~~~shell
php artisan module:migrate Catalog --database=catalog --force
php artisan db:seed --class='Modules\Catalog\Database\Seeders\CatalogDatabaseSeeder' --database=catalog --force
~~~

catalog 必须与 config('catalog.connection') 一致，使 migration 记录与业务数据使用同一 connection。示例数据仅由显式 Seeder 初始化一次。HTTP 不执行 DDL 或 seed，truncate 后访问页面也不会恢复示例数据。

新建 series、将 category 转为 series 和 CSV 新建 series 时，由业务创建流程写入原有 series_voltage / series_notes 默认 Metadata；显式 Seeder 可补齐已有 series 的缺项。该步骤只执行 DML。

迁移保留行、外键、默认值和两种 field scope，补齐 metadata 默认字段、隐藏标记、Typst 标记，修复字段唯一索引；写入前检查同 scope 重复 key。legacy LaTeX 的 latex_template 与 file API 的 latex_templates / latex_variables 分开保留。旧 latex_content 回填到 latex_code；旧 global_variables 复制到新表，同时保留旧表/列。缺少 file API 表由受控迁移补齐。

down() 是保留数据的空操作，rollback 不删除表。升级前备份数据库和文件；移除数据需另外制定维护步骤。

## 连接和事务

业务 repositories 保留 MySQLi SQL、结果类型和事务边界。每个 Laravel request scope 共用 catalog.connection；migrations 使用宿主同名 connection 的 PDO。两者是独立会话，不共享宿主 PDO transaction，宿主事务不能回滚已经提交的 Catalog 变更。后续 Filament 页面可通过容器复用服务；联合事务需单独设计。

业务连接读取宿主 database、host、port、username、password、unix_socket、charset，使用原数据库的服务器 SQL modes。无前缀表名和单一 MySQL/MariaDB endpoint 已作为目标实现；read/write 分离、SSL 和其他 PDO session options 尚未验证。

## 文件、页面和资源

文件使用宿主 storage_root。已有文件可通过 settings.storage 的 csv、media、latex_build、latex_pdfs、api_latex_build、api_latex_pdfs、typst_build、typst_pdfs、typst_assets 分别配置原目录；配置不会搬移或删除原文件。路由只提供声明的文件类别，不公开整个 storage。

页面为 /catalog/catalog_ui.html、spec-search.html、catalog-csv.html、global_typst_template.html、series_typst_template.html?series_id=ID、latex-templating.html，保留原界面和流程。

~~~text
app/Http/Controllers, Middleware   Laravel HTTP
app/Providers                    nWidart providers
app/Services, Repositories        workflows and SQL
app/Support                      host config, connection, logging, controlled seed
routes, config, database          routes, defaults, migrations/seeders
resources/views                  display-only Blade
resources/assets/js, scss         browser sources
public/assets                    generated assets and hash manifest
scripts                          asset build
~~~

在 Modules/Catalog 执行 npm ci 和 npm run build:assets。生成资源随源码交付，通过模块资产路由加载，不要求宿主 Vite 构建。vendor:publish --tag=catalog-assets 可额外发布到宿主 public/modules/catalog/assets。HTML 由 Laravel / Blade 返回，不复制到 public。

## 启用状态和缓存

~~~shell
php artisan module:disable Catalog
php artisan optimize:clear
php artisan module:enable Catalog
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
~~~

状态变化后刷新宿主缓存。旧缓存中的 Catalog routes 仍通过 ModuleEnabled 检查状态；禁用返回 404，模块 providers / connection 绑定不加载。长驻宿主进程按其正常流程重启。

## 兼容范围

[API.md](API.md) 记录 methods、字段、独立 envelopes 和 adapter 差异。用户要求保留原有缺陷，包括 root Typst template DELETE 的 Missing ID 500、textarea 保存为 text，以及 legacy LaTeX 空 description 的 TypeError 500。file LaTeX variables 的 PUT 继续返回原有 405。报告须将缺陷单列，不能把兼容一致视为功能通过。

临时宿主验证不同于真实部署。未实际运行 WordPress 或 Octane/Swoole 时，不得宣称其通过。验证脚本、fixtures 和报告全部放在 repository 外；不保留永久 tests 或 runner。
