#!/usr/bin/env node
/**
 * `npm run check:a11y` — §11.5 step 7.
 *
 * Reads CSS and source, never loads a page. Exits non-zero on:
 *   - a §6.2.3 contrast pair below its threshold;
 *   - a bare `outline-none` with no forced-colors fallback (§7.5, pinned 15);
 *   - a Tailwind default shadow step in source (§6.3, pinned 6);
 *   - an interactive element sized only by `size-7`/`size-8` (§7.2, pinned 9).
 *
 * Undocumented `text-*`/`bg-*` pairings are reported as warnings, not failures.
 * Pass `--strict` to promote warnings to failures.
 */

import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import process from 'node:process';

/* `CHECK_A11Y_ROOT` points the gate at a fixture tree so its own checks can be
   exercised; unset, it scans the repository. */
const ROOT = process.env.CHECK_A11Y_ROOT
    ? resolve(process.env.CHECK_A11Y_ROOT)
    : resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
const APP_CSS = join(ROOT, 'resources', 'css', 'app.css');
const STRICT = process.argv.slice(2).includes('--strict');

const rel = (absolute) => relative(ROOT, absolute).split(sep).join('/');

const failures = [];
const warnings = [];
const seenFindings = new Set();

const record = (list, rule, message, detail) => {
    const key = `${rule}|${message}|${detail}`;

    if (seenFindings.has(key)) {
        return;
    }

    seenFindings.add(key);
    list.push({ rule, message, detail });
};

const fail = (rule, message, detail) => record(failures, rule, message, detail);
const warn = (rule, message, detail) => record(warnings, rule, message, detail);

/* ------------------------------------------------------------------ colour */

const hexToRgb = (value) => {
    const match = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(value).trim());

    if (!match) {
        return null;
    }

    let hex = match[1];

    if (hex.length === 3) {
        hex = hex
            .split('')
            .map((character) => character + character)
            .join('');
    }

    return [
        parseInt(hex.slice(0, 2), 16),
        parseInt(hex.slice(2, 4), 16),
        parseInt(hex.slice(4, 6), 16),
    ];
};

const channel = (value) => {
    const scaled = value / 255;

    return scaled <= 0.04045
        ? scaled / 12.92
        : ((scaled + 0.055) / 1.055) ** 2.4;
};

const luminance = ([red, green, blue]) =>
    0.2126 * channel(red) + 0.7152 * channel(green) + 0.0722 * channel(blue);

const contrast = (foreground, background) => {
    const first = luminance(foreground);
    const second = luminance(background);
    const [high, low] = first >= second ? [first, second] : [second, first];

    return (high + 0.05) / (low + 0.05);
};

/* ------------------------------------------------------------ css plumbing */

const blankOut = (text) => text.replace(/[^\n]/g, ' ');

const stripComments = (source, { lineComments = false } = {}) => {
    let output = source
        .replace(
            /(^|[\s;,){}])\/\*[\s\S]*?\*\//gm,
            (match, prefix) => prefix + blankOut(match.slice(prefix.length)),
        )
        .replace(/<!--[\s\S]*?-->/g, blankOut);

    if (lineComments) {
        output = output.replace(
            /(^|\s)\/\/[^\n]*/gm,
            (match, prefix) => prefix + blankOut(match.slice(prefix.length)),
        );
    }

    return output;
};

const escapeRe = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const findBlock = (css, selector) => {
    const pattern = new RegExp(`${escapeRe(selector)}\\s*\\{`, 'g');
    const match = pattern.exec(css);

    if (!match) {
        return null;
    }

    const open = match.index + match[0].length - 1;
    let depth = 0;

    for (let index = open; index < css.length; index += 1) {
        if (css[index] === '{') {
            depth += 1;
        } else if (css[index] === '}') {
            depth -= 1;

            if (depth === 0) {
                return css.slice(open + 1, index);
            }
        }
    }

    return null;
};

const parseDeclarations = (block) => {
    const declarations = new Map();

    for (const match of block.matchAll(/(--[a-z0-9-]+)\s*:\s*([^;]+);/gi)) {
        declarations.set(match[1], match[2].trim());
    }

    return declarations;
};

/* ------------------------------------------------------------- pair table */

const AAA = 7;
const AA = 4.5;
const NON_TEXT = 3;

const TONES = [
    'tone-neutral',
    'tone-viridian',
    'tone-sky',
    'tone-marigold',
    'tone-violet',
    'tone-vermilion',
    'tone-rose',
    'tone-leaf',
];

const PAIRS = [];
const pair = (foreground, background, minimum, theme, stated, label) =>
    PAIRS.push({ foreground, background, minimum, theme, stated, label });

pair('foreground', 'background', AAA, 'light', 16.72, 'ink on paper');
pair('foreground', 'card', AAA, 'light', 18.89, 'ink on card');
pair('foreground', 'muted', AAA, 'light', 14.73, 'ink on muted');
pair(
    'muted-foreground',
    'background',
    AAA,
    'light',
    8.32,
    'muted-foreground on paper',
);
pair(
    'muted-foreground',
    'card',
    AAA,
    'light',
    9.41,
    'muted-foreground on card',
);
pair(
    'muted-foreground',
    'secondary',
    AAA,
    'light',
    7.34,
    'muted-foreground on secondary',
);
pair('primary', 'background', AAA, 'light', 7.23, 'accent on paper');
pair('primary', 'card', AAA, 'light', 8.17, 'accent on card');
pair('primary', 'muted', AA, 'light', 6.38, 'accent on muted');
pair(
    'primary-foreground',
    'primary',
    AAA,
    'light',
    8.17,
    'primary-foreground on accent block',
);
pair('background', 'primary', AAA, 'light', 7.23, 'paper on accent block');
pair('primary-hover', 'card', AAA, 'light', 10.86, 'primary-hover on card');
pair(
    'destructive-foreground',
    'destructive',
    AA,
    'light',
    6.54,
    'destructive-foreground on destructive',
);
pair('destructive', 'background', AA, 'light', 5.78, 'destructive on paper');
pair('success', 'card', AA, 'light', 6.58, 'success on card');
pair('warning', 'card', AA, 'light', 6.85, 'warning on card');
pair('info', 'card', AA, 'light', 5.19, 'info on card');
pair('foreground', 'accent', AAA, 'light', 14.75, 'ink on accent tint');
pair(
    'border',
    'background',
    NON_TEXT,
    'light',
    16.72,
    'ink border on paper (1.4.11)',
);
pair(
    'ring',
    'background',
    NON_TEXT,
    'light',
    7.23,
    'focus ring on paper (1.4.11)',
);
pair('background', 'foreground', AAA, 'light', 16.72, 'paper on an ink block');
pair(
    'muted-foreground',
    'accent',
    AAA,
    'light',
    7.34,
    'muted-foreground on accent tint',
);
pair('destructive', 'card', AA, 'light', 6.54, 'destructive on card');
pair(
    'primary-foreground',
    'primary-hover',
    AAA,
    'light',
    10.86,
    'primary-foreground on primary-hover',
);
pair(
    'destructive-foreground',
    'destructive-hover',
    AAA,
    'light',
    8.98,
    'destructive-foreground on destructive-hover',
);

for (const tone of TONES) {
    pair('foreground', tone, AAA, 'light', [14.08, 15.19], `ink on ${tone}`);
}

pair('foreground', 'background', AAA, 'dark', 15.85, 'ink on paper (dark)');
pair('foreground', 'card', AAA, 'dark', 14.29, 'ink on card (dark)');
pair(
    'muted-foreground',
    'muted',
    AAA,
    'dark',
    7.83,
    'muted-foreground on muted (dark)',
);
pair('primary', 'background', AAA, 'dark', 10.13, 'accent on paper (dark)');
pair('primary', 'card', AAA, 'dark', 9.13, 'accent on card (dark)');
pair('primary', 'accent', AA, 'dark', 6.83, 'accent on accent tint (dark)');
pair(
    'primary-foreground',
    'primary',
    AAA,
    'dark',
    10.13,
    'primary-foreground on accent block (dark)',
);
pair(
    'destructive-foreground',
    'destructive',
    AAA,
    'dark',
    8.0,
    'destructive-foreground on destructive (dark)',
);
pair(
    'ring',
    'background',
    NON_TEXT,
    'dark',
    10.13,
    'focus ring on paper (dark)',
);
pair(
    'border',
    'background',
    NON_TEXT,
    'dark',
    15.85,
    'ink border on paper (dark, §7.2)',
);
pair(
    'muted-foreground',
    'card',
    AAA,
    'dark',
    8.78,
    'muted-foreground on card (dark)',
);
pair(
    'muted-foreground',
    'background',
    AAA,
    'dark',
    9.74,
    'muted-foreground on paper (dark)',
);
pair(
    'muted-foreground',
    'accent',
    AA,
    'dark',
    6.57,
    'muted-foreground on accent tint (dark)',
);
pair('destructive', 'card', AA, 'dark', 7.21, 'destructive on card (dark)');
pair('success', 'card', AA, 'dark', 10.7, 'success on card (dark)');
pair(
    'background',
    'foreground',
    AAA,
    'dark',
    15.85,
    'paper on an ink block (dark)',
);
pair(
    'primary-foreground',
    'primary-hover',
    AAA,
    'dark',
    11.81,
    'primary-foreground on primary-hover (dark)',
);
pair(
    'destructive-foreground',
    'destructive-hover',
    AAA,
    'dark',
    10.45,
    'destructive-foreground on destructive-hover (dark)',
);

for (const tone of TONES) {
    pair(
        'foreground',
        tone,
        AAA,
        'dark',
        [10.72, 12.86],
        `ink on ${tone} (dark)`,
    );
}

/* §6.2.3: "The one forbidden combination is ink-on-accent-block (2.31:1)." */
const FORBIDDEN = [
    {
        foreground: 'ink',
        background: 'primary',
        label: 'ink on an accent block',
    },
];

const BUILT_IN_COLOURS = { white: '#ffffff', black: '#000000' };

/* --------------------------------------------------------- source walking */

const SOURCE_ROOTS = ['resources/js', 'resources/css', 'resources/views'];
const SKIP_DIRS = new Set([
    'node_modules',
    'vendor',
    '.git',
    'public',
    'storage',
    'bootstrap',
]);
const SKIP_PATHS = [
    'resources/js/actions/',
    'resources/js/routes/',
    'resources/js/wayfinder/',
    'resources/js/scripts/',
];

const isSource = (name) =>
    name.endsWith('.blade.php') || /\.(vue|ts|js|mjs|css)$/.test(name);

const walk = (directory) => {
    const found = [];

    for (const entry of readdirSync(directory, { withFileTypes: true })) {
        const absolute = join(directory, entry.name);
        const path = rel(absolute);

        if (entry.isDirectory()) {
            if (SKIP_DIRS.has(entry.name)) {
                continue;
            }

            if (SKIP_PATHS.some((skip) => `${path}/`.startsWith(skip))) {
                continue;
            }

            found.push(...walk(absolute));
        } else if (entry.isFile() && isSource(entry.name)) {
            found.push(absolute);
        }
    }

    return found;
};

const files = SOURCE_ROOTS.flatMap((root) => {
    try {
        return walk(join(ROOT, root));
    } catch {
        return [];
    }
}).sort((first, second) => first.localeCompare(second));

const lineOf = (source, index) => source.slice(0, index).split('\n').length;

/* ------------------------------------------------------------- extraction */

const MARKUP = /\.(vue|blade\.php)$/;

const LITERALS = /'([^'\\\n]*)'|"([^"\\\n]*)"|`([^`\\]*)`/g;

const CLASS_ATTRIBUTE =
    /(?:\s|^)(?::|v-bind:)?class\s*=\s*"([^"]*)"|(?:\s|^)(?::|v-bind:)?class\s*=\s*'([^']*)'/g;

const OPENING_TAG = /<([A-Za-z][A-Za-z0-9._-]*)((?:"[^"]*"|'[^']*'|[^>"'])*)>/g;

const SCRIPT_BLOCK = /<script[^>]*>([\s\S]*?)<\/script>/g;

const APPLY_AT_RULE = /@apply([^;]*);/g;

const literalText = (match) => match[1] ?? match[2] ?? match[3] ?? '';

const classStringsOf = (source, path) => {
    const strings = [];

    if (MARKUP.test(path)) {
        for (const match of source.matchAll(CLASS_ATTRIBUTE)) {
            strings.push({
                text: match[1] ?? match[2] ?? '',
                index: match.index,
            });
        }

        for (const block of source.matchAll(SCRIPT_BLOCK)) {
            const offset = block.index + block[0].indexOf(block[1]);

            for (const literal of block[1].matchAll(LITERALS)) {
                strings.push({
                    text: literalText(literal),
                    index: offset + literal.index,
                });
            }
        }

        return strings;
    }

    if (path.endsWith('.css')) {
        for (const match of source.matchAll(APPLY_AT_RULE)) {
            strings.push({ text: match[1], index: match.index });
        }

        return strings;
    }

    for (const literal of source.matchAll(LITERALS)) {
        strings.push({ text: literalText(literal), index: literal.index });
    }

    return strings;
};

/* ------------------------------------------------------------- the checks */

const read = (absolute) => readFileSync(absolute, 'utf8');

let appCssRaw;

try {
    appCssRaw = read(APP_CSS);
} catch {
    console.error(`check:a11y — cannot read ${rel(APP_CSS)}`);
    process.exit(1);
}

const appCss = stripComments(appCssRaw);

const rootBlock = findBlock(appCss, ':root');
const darkBlock = findBlock(appCss, '.dark');
const themeBlock = findBlock(appCss, '@theme inline');

if (!rootBlock || !darkBlock || !themeBlock) {
    const missing = [
        rootBlock ? null : ':root',
        darkBlock ? null : '.dark',
        themeBlock ? null : '@theme inline',
    ].filter(Boolean);

    console.error(
        `check:a11y — could not parse ${rel(APP_CSS)}: missing ${missing.join(', ')}`,
    );

    process.exit(1);
}

const lightTokens = parseDeclarations(rootBlock);
const darkTokens = parseDeclarations(darkBlock);

const colourMap = new Map();

for (const match of themeBlock.matchAll(
    /--color-([a-z0-9-]+)\s*:\s*var\((--[a-z0-9-]+)\)/gi,
)) {
    colourMap.set(match[1], match[2]);
}

const resolveColour = (colourName, tokens) => {
    if (colourName in BUILT_IN_COLOURS) {
        return {
            hex: BUILT_IN_COLOURS[colourName],
            rgb: hexToRgb(BUILT_IN_COLOURS[colourName]),
        };
    }

    const variable = colourMap.get(colourName) ?? `--${colourName}`;

    if (!tokens.has(variable)) {
        return null;
    }

    const value = tokens.get(variable);
    const rgb = hexToRgb(value);

    if (!rgb) {
        return { error: `${variable}: ${value} is not a hex literal` };
    }

    return { hex: `#${value.replace('#', '').toLowerCase()}`, rgb, variable };
};

const themes = [
    { name: 'light', tokens: lightTokens },
    { name: 'dark', tokens: darkTokens },
];

/* 1. §6.2.3 contrast pairs ------------------------------------------------ */

let pairCount = 0;
const allowed = { light: new Set(), dark: new Set() };
const forbidden = { light: new Set(), dark: new Set() };

for (const spec of PAIRS) {
    for (const theme of themes) {
        if (spec.theme !== theme.name && spec.theme !== 'both') {
            continue;
        }

        const foreground = resolveColour(spec.foreground, theme.tokens);
        const background = resolveColour(spec.background, theme.tokens);

        if (
            !foreground ||
            foreground.error ||
            !background ||
            background.error
        ) {
            const broken =
                foreground?.error ?? background?.error ?? 'token not defined';
            fail(
                'contrast',
                `${spec.label} (${theme.name}) — ${broken}`,
                `${rel(APP_CSS)} · pair needs \`--${spec.foreground}\` and \`--${spec.background}\``,
            );
            continue;
        }

        pairCount += 1;
        allowed[theme.name].add(`${foreground.hex}|${background.hex}`);

        const ratio = contrast(foreground.rgb, background.rgb);

        if (ratio + 1e-9 < spec.minimum) {
            fail(
                'contrast',
                `${spec.label} (${theme.name}) — measured ${ratio.toFixed(2)}:1, required ${spec.minimum.toFixed(2)}:1`,
                `${rel(APP_CSS)} · ${foreground.hex} on ${background.hex} · §6.2.3 ${spec.label}`,
            );
        } else if (
            typeof spec.stated === 'number' &&
            Math.abs(ratio - spec.stated) > 0.05
        ) {
            warn(
                'contrast-drift',
                `${spec.label} (${theme.name}) — computed ${ratio.toFixed(2)}:1, §6.2.3 states ${spec.stated.toFixed(2)}:1`,
                `${rel(APP_CSS)} · ${foreground.hex} on ${background.hex}`,
            );
        } else if (
            Array.isArray(spec.stated) &&
            (ratio < spec.stated[0] - 0.05 || ratio > spec.stated[1] + 0.05)
        ) {
            warn(
                'contrast-drift',
                `${spec.label} (${theme.name}) — computed ${ratio.toFixed(2)}:1, §6.2.3 states ${spec.stated[0]}–${spec.stated[1]}:1`,
                `${rel(APP_CSS)} · ${foreground.hex} on ${background.hex}`,
            );
        }
    }
}

for (const theme of themes) {
    for (const spec of FORBIDDEN) {
        const foreground = resolveColour(spec.foreground, theme.tokens);
        const background = resolveColour(spec.background, theme.tokens);

        if (
            foreground &&
            background &&
            !foreground.error &&
            !background.error
        ) {
            forbidden[theme.name].add(`${foreground.hex}|${background.hex}`);
        }
    }
}

/* 2. source scans --------------------------------------------------------- */

const COLOUR_TOKEN = /((?:[^\s:'"]+:)*)!?(text|bg)-([a-z0-9-]+)(?:\/(\d+))?/g;

/* Variant segments that move the paint to a different box (`selection:`, `file:`,
   `[&>svg]:`). Those only ever pair with an identical prefix chain. */
const ELEMENT_PREFIX_SEGMENTS = new Set([
    'selection',
    'placeholder',
    'file',
    'marker',
    'before',
    'after',
    'first-letter',
    'first-line',
    'backdrop',
    'details-content',
]);

const colourNames = new Set([
    ...colourMap.keys(),
    ...Object.keys(BUILT_IN_COLOURS),
]);

const hasUtility = (text, utility) =>
    new RegExp(`(?:^|[\\s"'\`({,;:\\[])!?${escapeRe(utility)}(?![\\w-])`).test(
        text,
    );

const NATIVE_INTERACTIVE = new Set([
    'button',
    'a',
    'input',
    'select',
    'textarea',
    'summary',
    'option',
]);

const INTERACTIVE_ROLE =
    /role\s*=\s*["'](button|link|menuitem|menuitemcheckbox|menuitemradio|option|tab|checkbox|radio|switch|combobox|textbox|searchbox)["']/;

const INTERACTIVE_NAME =
    /(Button|Trigger|Link|Switch|Toggle|Tab|MenuItem|Option|Checkbox|Radio)$/;

const FORCED_COLORS = /forced-colors/;

const pairingFindings = new Map();
const sizeFindings = [];

const notePairing = (foregroundName, backgroundName, path, line) => {
    const key = `${foregroundName}|${backgroundName}`;
    const existing = pairingFindings.get(key) ?? {
        foregroundName,
        backgroundName,
        sites: [],
    };

    existing.sites.push(`${path}:${line}`);
    pairingFindings.set(key, existing);
};

const pairable = (first, second) => {
    if (first.chain === second.chain) {
        return true;
    }

    if (first.elementShift || second.elementShift) {
        return false;
    }

    return first.chain === '' || second.chain === '';
};

const checkPairing = (text, path, line) => {
    const foregroundTokens = [];
    const backgroundTokens = [];

    for (const match of text.matchAll(COLOUR_TOKEN)) {
        const [, chain, kind, name, opacity] = match;

        if (!colourNames.has(name) || opacity !== undefined) {
            continue;
        }

        const segments = chain ? chain.slice(0, -1).split(':') : [];
        const token = {
            name,
            chain,
            elementShift:
                /[&>+~]/.test(chain) ||
                segments.some((segment) =>
                    ELEMENT_PREFIX_SEGMENTS.has(segment),
                ),
        };

        (kind === 'text' ? foregroundTokens : backgroundTokens).push(token);
    }

    if (foregroundTokens.length === 0 || backgroundTokens.length === 0) {
        return;
    }

    const seen = new Set();

    for (const foregroundToken of foregroundTokens) {
        for (const backgroundToken of backgroundTokens) {
            const foregroundName = foregroundToken.name;
            const backgroundName = backgroundToken.name;
            const key = `${foregroundToken.chain}${foregroundName}|${backgroundToken.chain}${backgroundName}`;

            if (seen.has(key) || !pairable(foregroundToken, backgroundToken)) {
                continue;
            }

            seen.add(key);

            for (const theme of themes) {
                const foreground = resolveColour(foregroundName, theme.tokens);
                const background = resolveColour(backgroundName, theme.tokens);

                if (
                    !foreground ||
                    !background ||
                    foreground.error ||
                    background.error
                ) {
                    continue;
                }

                const fingerprint = `${foreground.hex}|${background.hex}`;

                if (forbidden[theme.name].has(fingerprint)) {
                    fail(
                        'class-pairing',
                        `forbidden pairing \`text-${foregroundName}\` on \`bg-${backgroundName}\` (${theme.name})`,
                        `${path}:${line} · ${foreground.hex} on ${background.hex} · §6.3: ink text on an accent block is banned (2.31:1 in light)`,
                    );
                    continue;
                }

                if (allowed[theme.name].has(fingerprint)) {
                    continue;
                }

                const ratio = contrast(foreground.rgb, background.rgb);

                if (ratio + 1e-9 < AA) {
                    const detail = `${path}:${line} · ${foreground.hex} on ${background.hex} · pair is not in §6.2.3`;

                    /* Both sides named by a `--color-*` token: a token-layer defect, so it fails.
                       A `white`/`black` literal can be painted over by an absolutely positioned
                       sibling, which a class-string scan cannot see — report, do not block. */
                    if (foreground.variable && background.variable) {
                        fail(
                            'class-pairing',
                            `\`text-${foregroundName}\` on \`bg-${backgroundName}\` (${theme.name}) — measured ${ratio.toFixed(2)}:1, required ${AA.toFixed(2)}:1`,
                            detail,
                        );
                    } else {
                        warn(
                            'class-pairing-low',
                            `\`text-${foregroundName}\` on \`bg-${backgroundName}\` (${theme.name}) — measured ${ratio.toFixed(2)}:1, required ${AA.toFixed(2)}:1`,
                            detail,
                        );
                    }

                    continue;
                }

                notePairing(foregroundName, backgroundName, path, line);
            }
        }
    }
};

for (const absolute of files) {
    const path = rel(absolute);
    const raw = read(absolute);
    const source = stripComments(raw, { lineComments: !path.endsWith('.css') });

    /* pinned 15 / §7.5 — `outline-none` is `outline-style: none` only. */
    const forcedColorsInFile = FORCED_COLORS.test(source);

    for (const match of source.matchAll(
        /(?:^|[\s"'`({,;:[])!?outline-none(?![-\w])/g,
    )) {
        const index = match.index + match[0].indexOf('outline-none');
        const line = lineOf(source, index);
        const lineText = source.split('\n')[line - 1] ?? '';

        if (
            FORCED_COLORS.test(lineText) ||
            (path.endsWith('.css') && forcedColorsInFile)
        ) {
            continue;
        }

        fail(
            'outline-none',
            'bare `outline-none` with no forced-colors fallback',
            `${path}:${line} · Tailwind v4 compiles it to \`outline-style: none\` only, so with a box-shadow replacement there is no focus indicator in forced-colors mode — use \`outline-hidden\` (§7.5, pinned 15)`,
        );
    }

    /* pinned 6 / §6.3 — the Tailwind default shadow steps are removed by retokenisation. */
    for (const match of source.matchAll(
        /\bshadow-(2xs|xs|sm|md|lg|xl|2xl|inner)\b/g,
    )) {
        const index = match.index;

        if (source.slice(Math.max(0, index - 2), index) === '--') {
            continue;
        }

        const line = lineOf(source, index);

        fail(
            'shadow-steps',
            `legacy shadow step \`${match[0]}\``,
            `${path}:${line} · §6.3 removes \`shadow-xs\`…\`shadow-2xl\` and \`shadow-inner\` by retokenisation — use \`shadow-hard-sm\`/\`shadow-hard\`/\`shadow-hard-lg\`/\`shadow-hard-xl\` (pinned 6)`,
        );
    }

    /* §7.2 / pinned 9 — icon buttons are `size="icon"` (size-9, 36px). */
    if (MARKUP.test(path)) {
        for (const match of source.matchAll(OPENING_TAG)) {
            const tagName = match[1];
            const attributes = match[2];
            const lower = tagName.toLowerCase();
            const interactive =
                NATIVE_INTERACTIVE.has(lower) ||
                INTERACTIVE_NAME.test(tagName) ||
                INTERACTIVE_ROLE.test(attributes) ||
                /\s(?:@click|v-on:click|href)\s*[.=]/.test(attributes);

            if (!interactive) {
                continue;
            }

            const offender = ['size-7', 'size-8'].find((utility) =>
                hasUtility(attributes, utility),
            );

            if (!offender) {
                continue;
            }

            const larger = [
                'size-9',
                'size-10',
                'size-11',
                'size-12',
                'size-14',
            ].some((utility) => hasUtility(attributes, utility));

            if (larger) {
                continue;
            }

            sizeFindings.push({
                path,
                line: lineOf(source, match.index),
                offender,
                tag: tagName,
            });
        }
    } else if (!path.endsWith('.css')) {
        /* cva definitions live in `.ts`; a class string there belongs to a control. */
        const interactiveMarker =
            /(?:^|[\s"'`({,;:[])(?:cursor-pointer|outline-hidden|hover:|focus-visible:|active:)/;

        for (const literal of source.matchAll(LITERALS)) {
            const text = literal[1] ?? literal[2] ?? literal[3] ?? '';

            if (!interactiveMarker.test(text)) {
                continue;
            }

            const offender = ['size-7', 'size-8'].find((utility) =>
                hasUtility(text, utility),
            );

            if (!offender) {
                continue;
            }

            sizeFindings.push({
                path,
                line: lineOf(source, literal.index),
                offender,
                tag: 'class string',
            });
        }
    }

    /* §6.2.3 — every shipped `text-*`/`bg-*` pairing appears in the table. */
    const pairingSource = stripComments(raw, {
        lineComments: !path.endsWith('.css'),
    });

    for (const { text, index } of classStringsOf(pairingSource, path)) {
        if (!text.includes('text-') && !text.includes('bg-')) {
            continue;
        }

        checkPairing(text, path, lineOf(pairingSource, index));
    }
}

for (const finding of sizeFindings) {
    fail(
        'target-size',
        `interactive <${finding.tag}> sized only by \`${finding.offender}\``,
        `${finding.path}:${finding.line} · §7.2 requires every icon button at \`size="icon"\` (\`size-9\`, 36px) — \`${finding.offender}\` is ${finding.offender === 'size-7' ? '28' : '32'}px (pinned 9)`,
    );
}

for (const finding of pairingFindings.values()) {
    const sites = finding.sites.slice(0, 3).join(', ');
    const more =
        finding.sites.length > 3 ? ` +${finding.sites.length - 3} more` : '';

    warn(
        'class-pairing',
        `\`text-${finding.foregroundName}\` on \`bg-${finding.backgroundName}\` is not in the §6.2.3 pair table`,
        `${sites}${more} · §6.2.3: "every shipped \`text-*\`/\`bg-*\` pairing appears there or is added with its measured ratio"`,
    );
}

/* --------------------------------------------------------------- reporting */

const ruleCounts = (list) =>
    list.reduce((counts, entry) => {
        counts[entry.rule] = (counts[entry.rule] ?? 0) + 1;

        return counts;
    }, {});

const printGroup = (title, entries, limit) => {
    if (entries.length === 0) {
        return;
    }

    console.log(`\n${title}`);

    entries.slice(0, limit).forEach((entry, index) => {
        console.log(
            `  ${String(index + 1).padStart(3)}. [${entry.rule}] ${entry.message}`,
        );
        console.log(`       ${entry.detail}`);
    });

    if (entries.length > limit) {
        console.log(`       … and ${entries.length - limit} more`);
    }
};

console.log(
    'check:a11y — §11.5 step 7 (contrast, retokenisation, target size)',
);
console.log('');
console.log(
    `  app.css        ${rel(APP_CSS)} — :root ${lightTokens.size} tokens · .dark ${darkTokens.size} tokens · ${colourMap.size} colour mappings`,
);
console.log(`  source files   ${files.length} scanned`);
console.log(
    `  contrast       ${pairCount} §6.2.3 pairs checked · ${allowed.light.size} light / ${allowed.dark.size} dark value-pairs allowed`,
);
console.log(
    `  warnings       ${warnings.length}${STRICT ? ' (promoted to failures by --strict)' : ''}`,
);

printGroup('FAILURES', failures, 40);
printGroup('WARNINGS', warnings, 20);

const effectiveFailures = STRICT ? [...failures, ...warnings] : failures;

if (effectiveFailures.length > 0) {
    const counts = ruleCounts(effectiveFailures);
    const summary = Object.entries(counts)
        .map(([rule, count]) => `${rule} ${count}`)
        .join(' · ');

    console.log(`\nFAIL — ${effectiveFailures.length} problem(s): ${summary}`);
    process.exit(1);
}

console.log('\nPASS — no contrast, retokenisation or target-size regressions.');

if (warnings.length > 0) {
    console.log(
        `(${warnings.length} warning(s) above are informational and do not fail the gate.)`,
    );
}
