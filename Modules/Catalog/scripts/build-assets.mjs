import { createHash } from 'node:crypto';
import { mkdir, readdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const moduleRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const sourceRoot = path.join(moduleRoot, 'resources', 'assets');
const publicRoot = path.join(moduleRoot, 'public', 'assets');
const versions = {};

async function copyAssets(type, extension) {
    const sourceDirectory = path.join(sourceRoot, type);
    const outputDirectory = path.join(publicRoot, type);
    await mkdir(outputDirectory, { recursive: true });

    const entries = await readdir(sourceDirectory, { withFileTypes: true });
    const files = entries
        .filter((entry) => entry.isFile() && entry.name.endsWith(extension))
        .map((entry) => entry.name)
        .sort();

    for (const fileName of files) {
        const contents = (await readFile(path.join(sourceDirectory, fileName), 'utf8'))
            .replace(/\r\n/g, '\n');
        await writeFile(path.join(outputDirectory, fileName), contents);
        versions[`${type}/${fileName}`] = createHash('sha256')
            .update(contents)
            .digest('hex')
            .slice(0, 16);
    }

    return files.length;
}

const cssCount = await copyAssets('css', '.css');
const javascriptCount = await copyAssets('js', '.js');
const manifest = Object.fromEntries(Object.entries(versions).sort(([left], [right]) => left.localeCompare(right)));

await writeFile(
    path.join(publicRoot, 'manifest.json'),
    `${JSON.stringify(manifest, null, 2)}\n`,
    'utf8',
);

console.log(`Copied ${cssCount} CSS files and ${javascriptCount} JavaScript files.`);
