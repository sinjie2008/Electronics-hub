<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\CatalogTestDatabase;

beforeEach(function () {
    if (! filter_var(env('RUN_CATALOG_COMPILER_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('Set RUN_CATALOG_COMPILER_INTEGRATION=true with Typst and pdflatex installed.');
    }

    $this->catalogStorageRoot = CatalogTestDatabase::prepare();
});

afterEach(function () {
    if (isset($this->catalogStorageRoot)) {
        CatalogTestDatabase::cleanup($this->catalogStorageRoot);
    }
});

it('compiles a real Typst PDF and serves it through Catalog storage', function (bool $withSeries) {
    $version = new Process([(string) config('catalog.settings.typst.binary'), '--version']);
    $version->mustRun();
    expect($version->getOutput())->toContain('typst');

    $seriesId = null;
    if ($withSeries) {
        $root = $this->postJson('/catalog/catalog.php?action=v1.saveNode', [
            'name' => 'Compiler category', 'type' => 'category',
        ])->assertOk()->json('data');
        $series = $this->postJson('/catalog/catalog.php?action=v1.saveNode', [
            'name' => 'Compiler series', 'type' => 'series', 'parentId' => $root['id'],
        ])->assertOk()->json('data');
        $seriesId = $series['id'];
    }

    $this->postJson('/catalog/api/typst/variables.php', [
        'key' => 'company', 'type' => 'text', 'value' => 'Electronics Hub',
    ])->assertOk();
    $pdf = $this->postJson('/catalog/api/typst/compile.php', [
        'typst' => "#set page(width: 100mm, height: 100mm)\n= Catalog compilation\n#data.globals.company",
        'seriesId' => $seriesId,
    ])->assertOk()->assertJsonPath('success', true)->json('data');

    expect(File::get($pdf['path']))->toStartWith('%PDF-')
        ->and(realpath($pdf['path']))->toStartWith(realpath($this->catalogStorageRoot));
    $this->get($pdf['url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
})->with(['global' => false, 'series' => true]);

it('compiles real PDFs through both file and legacy LaTeX contracts', function () {
    $version = new Process([(string) config('catalog.settings.latex.default_binary'), '--version']);
    $version->mustRun();
    expect($version->getOutput())->toContain('pdfTeX');

    $latex = '\\documentclass{article}\\begin{document}Electronics Hub compilation\\end{document}';
    $root = $this->postJson('/catalog/catalog.php?action=v1.saveNode', [
        'name' => 'LaTeX category', 'type' => 'category',
    ])->assertOk()->json('data');
    $series = $this->postJson('/catalog/catalog.php?action=v1.saveNode', [
        'name' => 'LaTeX series', 'type' => 'series', 'parentId' => $root['id'],
    ])->assertOk()->json('data');

    $filePdf = $this->postJson('/catalog/api/latex/compile.php', [
        'series_id' => $series['id'], 'latex' => $latex,
    ])->assertOk()->assertJsonPath('success', true)->json('data');
    expect(File::get($filePdf['path']))->toStartWith('%PDF-');
    $this->get($filePdf['url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $template = $this->postJson('/catalog/catalog.php?action=v1.createLatexTemplate', [
        'title' => 'Compiler template', 'description' => 'Compiler regression', 'latex' => $latex,
    ])->assertOk()->json('data');
    $legacyPdf = $this->postJson('/catalog/catalog.php?action=v1.buildLatexTemplate&id='.$template['id'])
        ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.exitCode', 0)->json('data');
    expect(DB::connection('catalog')->table('latex_template')->where('id', $template['id'])->value('pdf_path'))
        ->toBe($legacyPdf['pdfPath']);
    $this->get($legacyPdf['downloadUrl'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
});
