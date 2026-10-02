<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Connection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Modules\Catalog\Database\Seeders\CatalogDatabaseSeeder;
use Modules\Catalog\Support\Db as CatalogDb;
use Modules\IAM\Models\Role;
use Symfony\Component\Process\ExecutableFinder;

beforeEach(function (): void {
    $storageRoot = storage_path('framework/testing/catalog-document-'.Str::uuid());
    config([
        'catalog.storage_root' => $storageRoot,
        'catalog.settings.logging.path' => $storageRoot.'/logs/catalog.log',
    ]);

    if (catalogDocumentConnection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Catalog document feature tests require MySQL.');
    }
});

afterEach(function (): void {
    $storageRoot = config('catalog.storage_root');
    if (is_string($storageRoot) && $storageRoot !== '') {
        File::deleteDirectory($storageRoot);
    }
});

it('supports LaTeX template CRUD, the series_id query alias, and exact validation responses', function (): void {
    $admin = catalogDocumentAdmin();
    $connection = catalogDocumentConnection();
    $seriesId = catalogDocumentSeries();

    $created = $this->actingAs($admin)->postJson('/catalog/api/latex/templates.php', [
        'templateTitle' => 'Global datasheet',
        'latex_code' => '\\documentclass{article}',
        'templateDescription' => 'Global template',
    ]);

    $created->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.title', 'Global datasheet')
        ->assertJsonPath('data.latex', '\\documentclass{article}');
    $globalTemplateId = (int) $created->json('data.id');

    $updated = $this->putJson('/catalog/api/latex/templates.php', [
        'id' => $globalTemplateId,
        'templateTitle' => 'Updated datasheet',
        'latex_content' => '\\documentclass{report}',
    ]);

    $updated->assertOk()
        ->assertJsonPath('data.title', 'Updated datasheet')
        ->assertJsonPath('data.latex', '\\documentclass{report}');

    $globalList = $this->getJson('/catalog/api/latex/templates.php')->assertOk();
    $this->assertContains($globalTemplateId, array_column($globalList->json('data'), 'id'));

    $seriesTemplate = $this->postJson('/catalog/api/latex/templates.php?series_id='.$seriesId, [
        'templateTitle' => 'Series datasheet',
        'latex_content' => 'Series source',
    ]);

    $seriesTemplate->assertCreated()->assertJsonPath('data.seriesId', $seriesId);
    $seriesTemplateId = (int) $seriesTemplate->json('data.id');
    $seriesList = $this->getJson('/catalog/api/latex/templates.php?series_id='.$seriesId)->assertOk();
    $this->assertContains($seriesTemplateId, array_column($seriesList->json('data'), 'id'));
    $this->assertContains($globalTemplateId, array_column($seriesList->json('data'), 'id'));

    $this->deleteJson('/catalog/api/latex/templates.php?id='.$globalTemplateId)
        ->assertOk()
        ->assertJsonPath('data.deleted', true);
    $this->assertFalse($connection->table('latex_templates')->where('id', $globalTemplateId)->exists());

    $this->postJson('/catalog/api/latex/templates.php', ['latex' => 'source'])
        ->assertBadRequest()
        ->assertJsonPath('error.code', 'validation_error')
        ->assertJsonPath('error.message', 'Title is required');

    $this->putJson('/catalog/api/latex/templates.php', ['templateTitle' => 'Missing ID'])
        ->assertBadRequest()
        ->assertJsonPath('error.message', 'ID and Title are required');

    $this->deleteJson('/catalog/api/latex/templates.php')
        ->assertBadRequest()
        ->assertJsonPath('error.message', 'ID is required');

    $this->postJson('/catalog/api/latex/compile.php', ['latex' => '', 'series_id' => $seriesId])
        ->assertBadRequest()
        ->assertJsonPath('error.message', 'LaTeX content and Series ID are required');
});

it('supports LaTeX global variable CRUD and validates the key field', function (): void {
    $admin = catalogDocumentAdmin();

    $created = $this->actingAs($admin)->postJson('/catalog/api/latex/variables.php', [
        'key' => 'manufacturer',
        'type' => 'text',
        'value' => 'Northstar Components',
    ]);

    $created->assertOk()
        ->assertJsonPath('data.key', 'manufacturer')
        ->assertJsonPath('data.value', 'Northstar Components');
    $variableId = (int) $created->json('data.id');

    $this->postJson('/catalog/api/latex/variables.php', [
        'id' => $variableId,
        'key' => 'manufacturer',
        'value' => 'Updated Components',
    ])->assertOk()->assertJsonPath('data.value', 'Updated Components');

    $variables = $this->getJson('/catalog/api/latex/variables.php')->assertOk();
    $this->assertContains($variableId, array_column($variables->json('data'), 'id'));

    $this->deleteJson('/catalog/api/latex/variables.php?id='.$variableId)
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    $this->postJson('/catalog/api/latex/variables.php', ['key' => '  '])
        ->assertBadRequest()
        ->assertJsonPath('error.code', 'validation_error')
        ->assertJsonPath('error.message', 'Key is required');
});

it('supports Typst global and series template CRUD with the seriesId query alias', function (): void {
    $admin = catalogDocumentAdmin();
    $connection = catalogDocumentConnection();
    $seriesId = catalogDocumentSeries();

    $created = $this->actingAs($admin)->postJson('/catalog/api/typst/templates.php', [
        'title' => 'Global Typst sheet',
        'description' => 'Global template',
        'typst' => '#let edition = 1',
    ]);

    $created->assertOk()
        ->assertJsonPath('data.title', 'Global Typst sheet')
        ->assertJsonPath('data.typst', '#let edition = 1')
        ->assertJsonPath('data.isGlobal', true);
    $globalTemplateId = (int) $created->json('data.id');

    $updated = $this->putJson('/catalog/api/typst/templates.php', [
        'id' => $globalTemplateId,
        'title' => 'Updated Typst sheet',
        'typst' => '#let edition = 2',
    ]);

    $updated->assertOk()
        ->assertJsonPath('data.title', 'Updated Typst sheet')
        ->assertJsonPath('data.typst', '#let edition = 2');

    $seriesTemplate = $this->postJson('/catalog/api/typst/templates.php', [
        'title' => 'Series Typst sheet',
        'typst' => '#let series = true',
        'seriesId' => $seriesId,
    ]);

    $seriesTemplate->assertOk()
        ->assertJsonPath('data.isGlobal', false)
        ->assertJsonPath('data.seriesId', $seriesId);
    $seriesTemplateId = (int) $seriesTemplate->json('data.id');

    $seriesList = $this->getJson('/catalog/api/typst/templates.php?seriesId='.$seriesId)->assertOk();
    $this->assertContains($seriesTemplateId, array_column($seriesList->json('data'), 'id'));
    $this->assertContains($globalTemplateId, array_column($seriesList->json('data'), 'id'));

    $this->deleteJson('/catalog/api/typst/templates.php?id='.$globalTemplateId)->assertOk();
    $this->assertFalse($connection->table('typst_templates')->where('id', $globalTemplateId)->exists());
});

it('supports Typst variable CRUD and returns the source validation messages', function (): void {
    $admin = catalogDocumentAdmin();
    $connection = catalogDocumentConnection();
    $seriesId = catalogDocumentSeries();

    $created = $this->actingAs($admin)->postJson('/catalog/api/typst/variables.php', [
        'key' => 'material',
        'type' => 'text',
        'value' => 'Aluminum',
    ]);

    $created->assertOk()
        ->assertJsonPath('data.key', 'material')
        ->assertJsonPath('data.value', 'Aluminum')
        ->assertJsonPath('data.isGlobal', true);
    $globalVariableId = (int) $created->json('data.id');

    $this->postJson('/catalog/api/typst/variables.php', [
        'id' => $globalVariableId,
        'key' => 'material',
        'value' => 'Stainless steel',
    ])->assertOk()->assertJsonPath('data.value', 'Stainless steel');

    $this->getJson('/catalog/api/typst/variables.php?id='.$globalVariableId)
        ->assertOk()
        ->assertJsonPath('data.value', 'Stainless steel');

    $scopedVariable = $this->postJson('/catalog/api/typst/variables.php', [
        'key' => 'finish',
        'value' => 'Brushed',
        'seriesId' => $seriesId,
    ]);

    $scopedVariable->assertOk()
        ->assertJsonPath('data.isGlobal', false)
        ->assertJsonPath('data.seriesId', $seriesId);
    $scopedVariableId = (int) $scopedVariable->json('data.id');
    $scopedList = $this->getJson('/catalog/api/typst/variables.php?seriesId='.$seriesId)->assertOk();
    $this->assertContains($scopedVariableId, array_column($scopedList->json('data'), 'id'));

    $this->deleteJson('/catalog/api/typst/variables.php?id='.$globalVariableId)
        ->assertOk();
    $this->assertFalse($connection->table('typst_variables')->where('id', $globalVariableId)->exists());

    $this->postJson('/catalog/api/typst/variables.php', ['key' => '  '])
        ->assertBadRequest()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath('error.message', 'Key is required');

    $this->postJson('/catalog/api/typst/variables.php', ['key' => 'scope', 'seriesId' => -1])
        ->assertBadRequest()
        ->assertJsonPath('error.message', 'seriesId must be positive when provided.');
});

it('stores a series preference for a global Typst template and clears it when that template is deleted', function (): void {
    $admin = catalogDocumentAdmin();
    $seriesId = catalogDocumentSeries();

    $template = $this->actingAs($admin)->postJson('/catalog/api/typst/templates.php', [
        'title' => 'Imported global template',
        'typst' => '#let shared = true',
    ])->assertOk();
    $templateId = (int) $template->json('data.id');

    $this->putJson('/catalog/api/typst/series-preferences.php', [
        'seriesId' => $seriesId,
        'lastGlobalTemplateId' => $templateId,
    ])->assertOk()->assertJsonPath('data.lastGlobalTemplateId', $templateId);

    $this->getJson('/catalog/api/typst/series-preferences.php?seriesId='.$seriesId)
        ->assertOk()
        ->assertJsonPath('data.lastGlobalTemplateId', $templateId);

    $this->deleteJson('/catalog/api/typst/templates.php?id='.$templateId)->assertOk();
    $this->getJson('/catalog/api/typst/series-preferences.php?seriesId='.$seriesId)
        ->assertOk()
        ->assertJsonPath('data.lastGlobalTemplateId', null);

    $missingTemplateId = (int) catalogDocumentConnection()->table('typst_templates')->max('id') + 5000;
    $this->putJson('/catalog/api/typst/series-preferences.php', [
        'seriesId' => $seriesId,
        'lastGlobalTemplateId' => $missingTemplateId,
    ])->assertInternalServerError()
        ->assertJsonPath('error.code', 'INTERNAL_ERROR')
        ->assertJsonPath('error.message', 'Global template not found.');

    $this->putJson('/catalog/api/typst/series-preferences.php', [
        'seriesId' => $seriesId,
        'lastGlobalTemplateId' => 'not-a-number',
    ])->assertBadRequest()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath('error.message', 'lastGlobalTemplateId must be numeric or null.');

    $this->getJson('/catalog/api/typst/series-preferences.php')
        ->assertBadRequest()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath('error.message', 'seriesId is required.');
});

it('replaces Typst text placeholders before compiling and leaves the failed source available for inspection', function (): void {
    $typstBinary = catalogDocumentTypstBinary();
    if ($typstBinary === null) {
        $this->markTestSkipped('The Linux Typst compiler is not installed.');
    }

    $admin = catalogDocumentAdmin();
    $connection = catalogDocumentConnection();
    $seriesId = catalogDocumentSeries();
    $fieldId = catalogDocumentField($seriesId, 'maker', 'text', 'series_metadata');
    $connection->table('series_custom_field_value')->insert([
        'series_id' => $seriesId,
        'series_custom_field_id' => $fieldId,
        'value' => 'Northstar Components',
    ]);
    config(['catalog.settings.typst.binary' => $typstBinary]);

    $response = $this->actingAs($admin)->postJson('/catalog/api/typst/compile.php', [
        'typst' => "{{maker}}\n#let broken = (\n",
        'seriesId' => $seriesId,
    ]);

    $response->assertInternalServerError()
        ->assertJsonPath('error.code', 'COMPILE_ERROR');
    $this->assertStringStartsWith('Typst Compilation failed:', (string) $response->json('error.message'));

    $sources = File::glob(config('catalog.storage_root').'/typst-build/*.typ');
    $this->assertCount(1, $sources);
    $renderedSource = file_get_contents($sources[0]);
    $this->assertIsString($renderedSource);
    $this->assertStringContainsString('Northstar Components', $renderedSource);
    $this->assertStringNotContainsString('{{maker}}', $renderedSource);
});

it('compiles a Typst document and serves the generated PDF over HTTP', function (): void {
    $typstBinary = catalogDocumentTypstBinary();
    if ($typstBinary === null) {
        $this->markTestSkipped('The Linux Typst compiler is not installed.');
    }

    $admin = catalogDocumentAdmin();
    config(['catalog.settings.typst.binary' => $typstBinary]);

    $compiled = $this->actingAs($admin)->postJson('/catalog/api/typst/compile.php', [
        'typst' => "Catalog document integration test.\n",
    ]);

    $compiled->assertOk()->assertJsonPath('success', true);
    $pdfPath = (string) $compiled->json('data.path');
    $downloadUrl = (string) $compiled->json('data.url');
    $downloadPath = parse_url($downloadUrl, PHP_URL_PATH);
    $this->assertFileExists($pdfPath);
    $this->assertStringStartsWith('%PDF-', (string) file_get_contents($pdfPath));
    $this->assertIsString($downloadPath);

    $download = $this->get('/'.ltrim($downloadPath, '/'));
    $download->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->assertSame((string) filesize($pdfPath), $download->headers->get('Content-Length'));
});

it('stores Typst image variables from valid Laravel uploaded files', function (): void {
    $admin = catalogDocumentAdmin();
    $imageContents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGNgYGAAAAAEAAH2FzhVAAAAAElFTkSuQmCC', true);
    $this->assertIsString($imageContents);

    $created = $this->actingAs($admin)->post('/catalog/api/typst/variables.php', [
        'key' => 'brand_mark',
        'type' => 'image',
        'file' => UploadedFile::fake()->createWithContent('brand.png', $imageContents),
    ]);

    $created->assertOk()
        ->assertJsonPath('data.key', 'brand_mark')
        ->assertJsonPath('data.type', 'image');
    $assetPath = (string) $created->json('data.value');
    $variableId = (int) $created->json('data.id');
    $this->assertStringStartsWith('typst-assets/', $assetPath);
    $this->assertSame($assetPath, catalogDocumentConnection()->table('typst_variables')
        ->where('id', $variableId)
        ->value('field_value'));

    $storedAsset = config('catalog.storage_root').'/typst-assets/'.basename($assetPath);
    $this->assertFileExists($storedAsset);
    $this->assertSame($imageContents, file_get_contents($storedAsset));

    $previewUrl = (string) $created->json('data.previewUrl');
    $this->assertStringContainsString('/catalog/storage/typst-assets/', $previewUrl);
    $previewPath = parse_url($previewUrl, PHP_URL_PATH);
    $this->assertIsString($previewPath);
    $previewPath = '/'.ltrim($previewPath, '/');
    $this->assertStringStartsWith('/catalog/storage/typst-assets/', $previewPath);

    $download = $this->get($previewPath);
    $download->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->assertSame($imageContents, $download->streamedContent());
});

it('copies an uploaded Typst image into the build directory when compiling an image token', function (): void {
    $typstBinary = catalogDocumentTypstBinary();
    if ($typstBinary === null) {
        $this->markTestSkipped('The Linux Typst compiler is not installed.');
    }

    $admin = catalogDocumentAdmin();
    $imageContents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGNgYGAAAAAEAAH2FzhVAAAAAElFTkSuQmCC', true);
    $this->assertIsString($imageContents);
    config(['catalog.settings.typst.binary' => $typstBinary]);

    $created = $this->actingAs($admin)->post('/catalog/api/typst/variables.php', [
        'key' => 'brand_mark',
        'type' => 'image',
        'file' => UploadedFile::fake()->createWithContent('brand.png', $imageContents),
    ])->assertOk();
    $assetPath = (string) $created->json('data.value');
    $this->assertStringStartsWith('typst-assets/', $assetPath);

    $compiled = $this->postJson('/catalog/api/typst/compile.php', [
        'typst' => '#image("{{brand_mark}}", width: 1cm)',
    ])->assertOk()->assertJsonPath('success', true);

    $copiedAsset = config('catalog.storage_root').'/typst-build/'.$assetPath;
    $this->assertFileExists($copiedAsset);
    $this->assertSame($imageContents, file_get_contents($copiedAsset));
    $this->assertFileExists((string) $compiled->json('data.path'));
});

it('returns the expected legacy LaTeX build error when pdflatex is missing', function (): void {
    $admin = catalogDocumentAdmin();
    config(['catalog.settings.latex.default_binary' => '/catalog-test-bin/missing-pdflatex']);

    $created = $this->actingAs($admin)->postJson('/catalog/catalog.php?action=v1.createLatexTemplate', [
        'title' => 'Missing compiler fixture',
        'description' => 'Missing binary regression fixture',
        'latex' => "\\documentclass{article}\n\\begin{document}x\\end{document}",
    ])->assertOk();
    $templateId = (int) $created->json('data.id');

    $this->getJson('/catalog/catalog.php?action=v1.listLatexTemplates')
        ->assertOk();
    $this->getJson('/catalog/catalog.php?action=v1.getLatexTemplate&id='.$templateId)
        ->assertOk()
        ->assertJsonPath('data.latex', "\\documentclass{article}\n\\begin{document}x\\end{document}");

    $this->putJson('/catalog/catalog.php?action=v1.updateLatexTemplate&id='.$templateId, [
        'title' => 'Updated missing compiler fixture',
        'description' => 'Updated missing binary regression fixture',
        'latex' => "\\documentclass{article}\n\\begin{document}updated\\end{document}",
    ])->assertOk()->assertJsonPath('data.title', 'Updated missing compiler fixture');

    $this->postJson('/catalog/catalog.php?action=v1.buildLatexTemplate&id='.$templateId)
        ->assertInternalServerError()
        ->assertJsonPath('errorCode', 'LATEX_BUILD_ERROR')
        ->assertJsonPath('message', 'MiKTeX compilation failed.');

    $this->deleteJson('/catalog/catalog.php?action=v1.deleteLatexTemplate&id='.$templateId)->assertOk();
    $this->assertFalse(catalogDocumentConnection()->table('latex_template')->where('id', $templateId)->exists());
});

it('returns the legacy LaTeX build error for malformed source when pdflatex is installed', function (): void {
    $pdflatex = '/usr/bin/pdflatex';
    if (! is_file($pdflatex)) {
        $this->markTestSkipped('The Linux pdflatex compiler is not installed.');
    }

    $admin = catalogDocumentAdmin();
    config(['catalog.settings.latex.default_binary' => $pdflatex]);

    $this->actingAs($admin)->postJson('/catalog/catalog.php?action=v1.createLatexTemplate', [
        'latex' => 'source without title',
    ])->assertBadRequest()
        ->assertJsonPath('errorCode', 'LATEX_VALIDATION_ERROR')
        ->assertJsonPath('message', 'Template validation failed.')
        ->assertJsonPath('details.title', 'Title is required.');

    $created = $this->postJson('/catalog/catalog.php?action=v1.createLatexTemplate', [
        'title' => 'Malformed compiler fixture',
        'description' => 'Malformed source regression fixture',
        'latex' => "\\documentclass{article}\n\\begin{document}\n\\undefinedCatalogCommand\n\\end{document}",
    ])->assertOk();
    $templateId = (int) $created->json('data.id');

    $failedBuild = $this->postJson('/catalog/catalog.php?action=v1.buildLatexTemplate&id='.$templateId);
    $failedBuild
        ->assertInternalServerError()
        ->assertJsonPath('errorCode', 'LATEX_BUILD_ERROR')
        ->assertJsonPath('message', 'MiKTeX compilation failed.');
    $this->assertIsInt($failedBuild->json('details.exitCode'));
    $this->assertNotSame(0, $failedBuild->json('details.exitCode'));
});

it('compiles a LaTeX document and serves the generated PDF over HTTP', function (): void {
    $pdflatex = '/usr/bin/pdflatex';
    if (! is_file($pdflatex)) {
        $this->markTestSkipped('The Linux pdflatex compiler is not installed.');
    }

    $admin = catalogDocumentAdmin();
    config(['catalog.settings.latex.default_binary' => $pdflatex]);

    $created = $this->actingAs($admin)->postJson('/catalog/catalog.php?action=v1.createLatexTemplate', [
        'title' => 'LaTeX PDF download fixture',
        'description' => 'Successful compilation regression fixture',
        'latex' => "\\documentclass{article}\n\\begin{document}\nCatalog document integration test.\n\\end{document}",
    ])->assertOk();
    $templateId = (int) $created->json('data.id');

    $compiled = $this->postJson('/catalog/catalog.php?action=v1.buildLatexTemplate&id='.$templateId);
    $compiled->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.exitCode', 0);

    $downloadUrl = (string) $compiled->json('data.downloadUrl');
    $downloadPath = parse_url($downloadUrl, PHP_URL_PATH);
    $this->assertIsString($downloadPath);
    $pdfPath = (string) config('catalog.storage_root').'/latex-pdfs/'.basename($downloadPath);
    $this->assertFileExists($pdfPath);
    $this->assertStringStartsWith('%PDF-', (string) file_get_contents($pdfPath));

    $download = $this->get('/'.ltrim($downloadPath, '/'));
    $download->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->assertSame((string) filesize($pdfPath), $download->headers->get('Content-Length'));
});

it('rejects oversized media uploads and unsupported MIME types with the legacy messages', function (): void {
    $this->withHeader('Content-Type', 'multipart/form-data');
    $admin = catalogDocumentAdmin();
    $seriesId = catalogDocumentSeries();
    $fieldId = catalogDocumentField($seriesId, 'attachment', 'file', 'series_metadata');

    $tooLarge = $this->actingAs($admin)->post('/catalog/catalog.php?action=v1.saveSeriesAttributes', [
        'metadata' => json_encode(['seriesId' => $seriesId, 'values' => []], JSON_THROW_ON_ERROR),
        'files' => [
            'attachment' => UploadedFile::fake()->create('oversized.pdf', 10241, 'application/pdf'),
        ],
    ]);

    $tooLarge->assertBadRequest()
        ->assertJsonPath('errorCode', 'MEDIA_TOO_LARGE')
        ->assertJsonPath('message', 'Uploaded file exceeds size limit.');

    $unsupported = $this->post('/catalog/catalog.php?action=v1.saveSeriesAttributes', [
        'metadata' => json_encode(['seriesId' => $seriesId, 'values' => []], JSON_THROW_ON_ERROR),
        'files' => [
            'attachment' => UploadedFile::fake()->createWithContent('unsupported.txt', 'plain text payload'),
        ],
    ]);

    $unsupported->assertBadRequest()
        ->assertJsonPath('errorCode', 'MEDIA_TYPE_INVALID')
        ->assertJsonPath('message', 'Unsupported file type (images, PDF, GLB only).');
    $this->assertSame(0, catalogDocumentConnection()->table('series_custom_field_value')
        ->where('series_id', $seriesId)
        ->where('series_custom_field_id', $fieldId)
        ->count());
});

it('stores replacement media, removes the old file, and rolls back prior uploads after a later file fails', function (): void {
    $this->withHeader('Content-Type', 'multipart/form-data');
    $admin = catalogDocumentAdmin();
    $connection = catalogDocumentConnection();
    $token = (string) Str::uuid();
    $categoryName = 'Media Category '.$token;
    $seriesName = 'Media Series '.$token;
    $seriesId = catalogDocumentSeries($categoryName, $seriesName);
    $fieldId = catalogDocumentField($seriesId, 'manual', 'file', 'series_metadata');
    $storageRoot = (string) config('catalog.storage_root');
    $oldRelativePath = 'old/manual/previous.pdf';
    $oldAbsolutePath = $storageRoot.'/media/'.$oldRelativePath;
    File::ensureDirectoryExists(dirname($oldAbsolutePath));
    file_put_contents($oldAbsolutePath, "%PDF-1.4\nold\n%%EOF\n");
    $connection->table('series_custom_field_value')->insert([
        'series_id' => $seriesId,
        'series_custom_field_id' => $fieldId,
        'value' => $oldRelativePath,
    ]);

    $replacement = UploadedFile::fake()->createWithContent(
        'replacement.pdf',
        "%PDF-1.4\nreplacement\n%%EOF\n"
    );
    $this->actingAs($admin)->post('/catalog/catalog.php?action=v1.saveSeriesAttributes', [
        'metadata' => json_encode(['seriesId' => $seriesId, 'values' => []], JSON_THROW_ON_ERROR),
        'files' => ['manual' => $replacement],
    ])->assertOk()->assertJsonPath('success', true);

    $expectedRelativePath = Str::slug($categoryName).'/'.Str::slug($seriesName).'/manual/'.$seriesId.'/replacement.pdf';
    $newAbsolutePath = $storageRoot.'/media/'.$expectedRelativePath;
    $this->assertSame($expectedRelativePath, $connection->table('series_custom_field_value')
        ->where('series_id', $seriesId)
        ->where('series_custom_field_id', $fieldId)
        ->value('value'));
    $this->assertFileDoesNotExist($oldAbsolutePath);
    $this->assertFileExists($newAbsolutePath);

    $rollbackSeriesId = catalogDocumentSeries();
    $firstFieldId = catalogDocumentField($rollbackSeriesId, 'first_file', 'file', 'series_metadata', 1);
    $secondFieldId = catalogDocumentField($rollbackSeriesId, 'second_file', 'file', 'series_metadata', 2);
    $rollback = $this->post('/catalog/catalog.php?action=v1.saveSeriesAttributes', [
        'metadata' => json_encode(['seriesId' => $rollbackSeriesId, 'values' => []], JSON_THROW_ON_ERROR),
        'files' => [
            'first_file' => UploadedFile::fake()->createWithContent('first.pdf', "%PDF-1.4\nfirst\n%%EOF\n"),
            'second_file' => UploadedFile::fake()->createWithContent('second.txt', 'plain text payload'),
        ],
    ]);

    $rollback->assertBadRequest()
        ->assertJsonPath('errorCode', 'MEDIA_TYPE_INVALID')
        ->assertJsonPath('message', 'Unsupported file type (images, PDF, GLB only).');
    $this->assertSame(0, $connection->table('series_custom_field_value')
        ->where('series_id', $rollbackSeriesId)
        ->whereIn('series_custom_field_id', [$firstFieldId, $secondFieldId])
        ->count());
    $mediaFiles = File::isDirectory($storageRoot.'/media') ? File::allFiles($storageRoot.'/media') : [];
    $this->assertCount(1, $mediaFiles);
    $this->assertSame($newAbsolutePath, $mediaFiles[0]->getPathname());
});

it('rejects media path traversal with the source error response', function (): void {
    $this->actingAs(catalogDocumentAdmin());

    $this->get('/catalog/catalog.php?action=v1.downloadMedia&id='.rawurlencode('../../outside.pdf'))
        ->assertNotFound()
        ->assertJsonPath('errorCode', 'MEDIA_NOT_FOUND')
        ->assertJsonPath('message', 'Media file not found.');
});

function catalogDocumentTypstBinary(): ?string
{
    $configuredBinary = (string) config('catalog.settings.typst.binary', 'typst');

    return is_executable($configuredBinary)
        ? $configuredBinary
        : (new ExecutableFinder)->find($configuredBinary);
}

function catalogDocumentConnection(): Connection
{
    $connectionName = (string) config('catalog.connection', 'catalog');
    if ($connectionName === 'default') {
        $connectionName = (string) config('database.default', 'mysql');
    }

    return DB::connection($connectionName);
}

function catalogDocumentAdmin(): User
{
    app(RolesAndPermissionsSeeder::class)->run();
    app(CatalogDatabaseSeeder::class)->run();

    $admin = User::factory()->create();
    $admin->assignRole(Role::SUPER_ADMIN);

    return $admin;
}

function catalogDocumentSeries(?string $categoryName = null, ?string $seriesName = null): int
{
    $connection = CatalogDb::connection();
    $token = (string) Str::uuid();
    $categoryId = (int) $connection->table('category')->insertGetId([
        'parent_id' => null,
        'name' => $categoryName ?? 'Catalog Category '.$token,
        'type' => 'category',
        'display_order' => 0,
    ]);

    return (int) $connection->table('category')->insertGetId([
        'parent_id' => $categoryId,
        'name' => $seriesName ?? 'Catalog Series '.$token,
        'type' => 'series',
        'typst_templating_enabled' => 1,
        'latex_templating_enabled' => 1,
        'display_order' => 0,
    ]);
}

function catalogDocumentField(
    int $seriesId,
    string $fieldKey,
    string $fieldType,
    string $fieldScope,
    int $sortOrder = 0
): int {
    return (int) CatalogDb::connection()->table('series_custom_field')->insertGetId([
        'series_id' => $seriesId,
        'field_key' => $fieldKey,
        'label' => Str::headline($fieldKey),
        'field_type' => $fieldType,
        'field_scope' => $fieldScope,
        'default_value' => null,
        'sort_order' => $sortOrder,
        'is_required' => 0,
        'is_public_portal_hidden' => 0,
        'is_backend_portal_hidden' => 0,
    ]);
}
