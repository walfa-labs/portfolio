#!/usr/bin/env node
/**
 * Wayfinder QUERY-method workaround.
 *
 * `laravel/wayfinder` cannot type a `Route::query()` route. The fix exists only
 * as two open, unmerged pull requests:
 *
 *   - https://github.com/laravel/wayfinder/pull/324 (targets `main`)
 *   - https://github.com/laravel/wayfinder/pull/325 (targets `next`)
 *
 * `laravel/wayfinder` v0.1.21 — the latest release — still ships the bug, and
 * there is no way to reach around it from this project: the plugin's options
 * (`patterns`, `actions`, `routes`, `formVariants`, `path`, `command`) cannot
 * extend the union or scope generation per route, the `wayfinder:generate`
 * flags (`--path`, `--skip-actions`, `--skip-routes`, `--with-form`) cannot
 * either, and a composer patch plugin would be a new dependency.
 *
 * PRD §4.1 makes `/api/v1/search` a QUERY endpoint, so `wayfinder:generate`
 * emits `method: "query"` and the generated tree breaks in two ways:
 *
 *   1. `resources/js/wayfinder/index.ts` declares
 *      `type Method = "get" | … | "options"` with no `"query"` member, so every
 *      `RouteDefinition<"query">` fails with `TS2344` (and the `methods: […]`
 *      member with `TS2561`).
 *   2. `GET /api/v1/search` and `QUERY /api/v1/search` resolve to the same
 *      invokable controller at the same URI, so Wayfinder's
 *      `resources/multi-method.blade.ts` — which keys its dictionary by URI and
 *      never de-duplicates — writes the same key twice in
 *      `resources/js/actions/App/Http/Controllers/Api/V1/SearchController.ts`,
 *      which is `TS1117`. Adding `"query"` to the union is not enough on its
 *      own; this is the same upstream gap showing up a second time.
 *
 * This script repairs both, in place, after generation:
 *
 *   - it adds `"query"` to the `Method` union in
 *     `resources/js/wayfinder/index.ts` when the member is missing;
 *   - it drops a repeated URI key from a Wayfinder multi-method dictionary
 *     under `resources/js/actions` and `resources/js/routes`.
 *
 * Behaviour:
 *
 *   - idempotent — a second run changes nothing;
 *   - self-retiring — a member upstream already types, and a dictionary with no
 *     repeated key, are left alone, so this script can stay wired in harmlessly
 *     while only one of the two pull requests has landed;
 *   - loud on shape change — a file that carries the marker this script keys
 *     off but no longer has the shape it expects exits non-zero with an
 *     explanation. It never fails silently, and it never "repairs" a file it
 *     does not recognise.
 *
 * WHEN UPSTREAM SHIPS THE FIX (wayfinder#324 or #325 merged, or any release
 * that types `"query"` and de-duplicates the multi-method dictionary):
 *
 *   1. delete this file;
 *   2. drop the `&& node scripts/patch-wayfinder.mjs` tail from the
 *      `wayfinder:generate`, `types:check` and `build` scripts in
 *      `package.json`;
 *   3. run `php artisan wayfinder:generate --with-form && npm run types:check`
 *      on a clean tree to confirm the generated output is green without it.
 *
 * Usage: `node scripts/patch-wayfinder.mjs` (exit 0 on success or no-op).
 */

import { existsSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));

const methodUnionFile = join(root, 'resources/js/wayfinder/index.ts');
const generatedTrees = ['resources/js/actions', 'resources/js/routes'];

/**
 * Wayfinder's own wording in `multi-method.blade.ts`. It is the anchor that
 * says "this file holds a URI-keyed dictionary" without hard-coding a path.
 */
const multiMethodMarker = 'dictionary keyed by URI rather than a callable';

const methodUnion = /^(\s*(?:export\s+)?type\s+Method\s*=\s*)(.+?)(\s*;\s*)$/;
const dictionaryOpen = /^\s*(?:export\s+)?const\s+[A-Za-z0-9_$]+\s*=\s*\{$/;
const dictionaryEntry = /^\s*('[^']*')\s*:\s*[A-Za-z0-9_$]+\s*,$/;
const dictionaryClose = /^\}$/;

const splitLines = (file) => readFileSync(file, 'utf8').split('\n');

const stripCarriageReturn = (line) =>
    line.endsWith('\r') ? line.slice(0, -1) : line;

const fail = (message) => {
    process.stderr.write(`patch-wayfinder: ${message}\n`);
    process.exit(1);
};

/**
 * Add `"query"` to the `Method` union.
 *
 * @return {boolean} whether the file was rewritten
 */
const patchMethodUnion = () => {
    if (!existsSync(methodUnionFile)) {
        return false;
    }

    const lines = splitLines(methodUnionFile);
    const index = lines.findIndex((line) =>
        methodUnion.test(stripCarriageReturn(line)),
    );

    if (index === -1) {
        fail(
            `${relative(root, methodUnionFile)} exists but declares no \`type Method = …\` union. ` +
                'Wayfinder changed the shape of its generated types, so this workaround needs ' +
                'revisiting — see the header of scripts/patch-wayfinder.mjs.',
        );
    }

    const [, prefix, members, suffix] = methodUnion.exec(
        stripCarriageReturn(lines[index]),
    );
    const declared = members
        .split('|')
        .map((member) => member.trim().replace(/^['"]|['"]$/g, ''));

    if (declared.includes('query')) {
        return false;
    }

    const carriageReturn = lines[index].endsWith('\r') ? '\r' : '';

    lines[index] = `${prefix}${members} | "query"${suffix}${carriageReturn}`;
    writeFileSync(methodUnionFile, lines.join('\n'));

    return true;
};

/**
 * Drop repeated keys from a Wayfinder multi-method dictionary.
 *
 * @return {boolean} whether the file was rewritten
 */
const patchMultiMethodDictionary = (file) => {
    const lines = splitLines(file);
    const marker = lines.findIndex((line) => line.includes(multiMethodMarker));

    if (marker === -1) {
        return false;
    }

    const open = lines.findIndex(
        (line, index) =>
            index > marker && dictionaryOpen.test(stripCarriageReturn(line)),
    );

    if (open === -1) {
        fail(
            `${relative(root, file)} carries Wayfinder's multi-method comment but no ` +
                '`const … = {` dictionary follows it. Wayfinder changed the shape of its generated ' +
                'dictionary, so this workaround needs revisiting — see the header of ' +
                'scripts/patch-wayfinder.mjs.',
        );
    }

    const entries = [];

    for (let index = open + 1; index < lines.length; index += 1) {
        if (!dictionaryEntry.test(stripCarriageReturn(lines[index]))) {
            break;
        }

        entries.push(index);
    }

    if (entries.length === 0) {
        fail(
            `${relative(root, file)} carries Wayfinder's multi-method comment but its dictionary ` +
                "has no `'<uri>': <identifier>,` entries. Wayfinder changed the shape of its " +
                'generated dictionary, so this workaround needs revisiting — see the header of ' +
                'scripts/patch-wayfinder.mjs.',
        );
    }

    if (
        !dictionaryClose.test(
            stripCarriageReturn(lines[entries.at(-1) + 1] ?? ''),
        )
    ) {
        fail(
            `${relative(root, file)} carries Wayfinder's multi-method dictionary but it is not ` +
                'closed by `}` on its own line. Wayfinder changed the shape of its generated ' +
                'dictionary, so this workaround needs revisiting — see the header of ' +
                'scripts/patch-wayfinder.mjs.',
        );
    }

    const seen = new Set();
    const duplicates = new Set();

    for (const index of entries) {
        const key = dictionaryEntry.exec(stripCarriageReturn(lines[index]))[1];

        if (seen.has(key)) {
            duplicates.add(index);
        } else {
            seen.add(key);
        }
    }

    if (duplicates.size === 0) {
        return false;
    }

    writeFileSync(
        file,
        lines.filter((line, index) => !duplicates.has(index)).join('\n'),
    );

    return true;
};

const walk = (directory) => {
    if (!existsSync(directory)) {
        return [];
    }

    return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const path = join(directory, entry.name);

        if (entry.isDirectory()) {
            return walk(path);
        }

        return entry.isFile() && entry.name.endsWith('.ts') ? [path] : [];
    });
};

const repairs = [];

if (patchMethodUnion()) {
    repairs.push(
        `${relative(root, methodUnionFile)}: added "query" to the Method union`,
    );
}

for (const file of generatedTrees.flatMap((tree) => walk(join(root, tree)))) {
    if (patchMultiMethodDictionary(file)) {
        repairs.push(
            `${relative(root, file)}: dropped a duplicate dictionary key`,
        );
    }
}

for (const repair of repairs) {
    process.stdout.write(`patch-wayfinder: ${repair}\n`);
}
