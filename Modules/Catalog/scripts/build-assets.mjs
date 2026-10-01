import { createHash } from 'node:crypto';
import { cp, mkdir, readdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import * as sass from 'sass';

const moduleRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const sourceRoot = path.join(moduleRoot, 'resources', 'assets');
const outputRoot = path.join(moduleRoot, 'public', 'assets');
const cssRoot = path.join(outputRoot, 'css');
await mkdir(cssRoot, { recursive: true });

for (const fileName of (await readdir(path.join(sourceRoot, 'scss'))).sort()) {
    if (!fileName.endsWith('.scss') || fileName.startsWith('_')) continue;
    const result = sass.compile(path.join(sourceRoot, 'scss', fileName), { style: 'expanded', sourceMap: false });
    await writeFile(path.join(cssRoot, fileName.replace(/\.scss$/, '.css')), result.css, 'utf8');
}
await cp(path.join(sourceRoot, 'js'), path.join(outputRoot, 'js'), { recursive: true, force: true });

const manifest = {};
for (const directory of ['css', 'js']) {
    for (const fileName of (await readdir(path.join(outputRoot, directory))).sort()) {
        const asset = directory + '/' + fileName;
        const assetPath = path.join(outputRoot, asset);
        let content = await readFile(assetPath);
        if (directory === 'js') {
            content = Buffer.from(content.toString('utf8').replace(/\r\n/g, '\n'));
            await writeFile(assetPath, content);
        }
        manifest[asset] = createHash('sha256').update(content).digest('hex').slice(0, 16);
    }
}
await writeFile(path.join(outputRoot, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n', 'utf8');
console.log('Built ' + Object.keys(manifest).length + ' Catalog assets and content hashes.');
