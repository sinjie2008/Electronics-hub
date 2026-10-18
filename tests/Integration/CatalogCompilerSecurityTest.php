<?php

use Illuminate\Support\Facades\File;
use Modules\Catalog\Http\CatalogApiException;
use Modules\Catalog\Support\CompilerRunner;

beforeEach(function () {
    $this->compilerJob = CompilerRunner::createJobDirectory(storage_path('framework/testing/compiler-security'));
});

afterEach(function () {
    CompilerRunner::cleanup($this->compilerJob);
});

it('isolates document processes from application files, inherited secrets and host network', function () {
    $sentinel = storage_path('framework/testing/compiler-private-secret.txt');
    File::put($sentinel, 'private compiler sentinel');
    putenv('CATALOG_COMPILER_SENTINEL=private-value');
    try {
        $result = (new CompilerRunner)->run('/bin/bash', ['-c',
            'test ! -e '.escapeshellarg($sentinel).' && test ! -e /workspace/Electronics-hub/.env && test -z "$CATALOG_COMPILER_SENTINEL" && ! (exec 3<>/dev/tcp/127.0.0.1/3306) 2>/dev/null && printf isolated',
        ], $this->compilerJob);
        expect($result['exitCode'])->toBe(0)->and($result['stdout'])->toBe('isolated');
    } finally {
        putenv('CATALOG_COMPILER_SENTINEL');
        File::delete($sentinel);
    }
});

it('rejects oversized sources and bounds compiler output', function () {
    expect(fn () => CompilerRunner::assertSourceSize(str_repeat('x', CompilerRunner::MAX_SOURCE_BYTES + 1)))
        ->toThrow(CatalogApiException::class, 'Document source exceeds the compilation limit.');
    expect(fn () => (new CompilerRunner)->run('/bin/sh', ['-c', 'head -c 1048576 /dev/zero'], $this->compilerJob))
        ->toThrow(CatalogApiException::class, 'Compiler output exceeded the compilation limit.');
});

it('blocks TeX reads outside the job workspace while compiling a normal document', function () {
    $document = '\\documentclass{article}\\begin{document}Safe compiler fixture\\end{document}';
    File::put($this->compilerJob.'/safe.tex', $document);
    $runner = new CompilerRunner;
    $result = $runner->run((string) config('catalog.settings.latex.default_binary'), ['-no-shell-escape', '-interaction=nonstopmode', '-halt-on-error', 'safe.tex'], $this->compilerJob);
    expect($result['exitCode'])->toBe(0)->and(File::get($this->compilerJob.'/safe.pdf'))->toStartWith('%PDF-');
    File::put($this->compilerJob.'/unsafe.tex', '\\documentclass{article}\\begin{document}\\input{/workspace/Electronics-hub/.env}\\end{document}');
    $denied = $runner->run((string) config('catalog.settings.latex.default_binary'), ['-no-shell-escape', '-interaction=nonstopmode', '-halt-on-error', 'unsafe.tex'], $this->compilerJob);
    expect($denied['exitCode'])->not->toBe(0)->and(File::exists($this->compilerJob.'/unsafe.pdf'))->toBeFalse();
});

it('rejects Typst reads outside the private compiler root', function () {
    File::put($this->compilerJob.'/unsafe.typ', '#read("/workspace/Electronics-hub/.env")');
    $result = (new CompilerRunner)->run((string) config('catalog.settings.typst.binary'), ['compile', '--root', '/work', 'unsafe.typ', 'unsafe.pdf'], $this->compilerJob);

    expect($result['exitCode'])->not->toBe(0)->and(File::exists($this->compilerJob.'/unsafe.pdf'))->toBeFalse();
});
