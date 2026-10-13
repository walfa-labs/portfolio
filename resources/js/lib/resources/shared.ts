import type { ResourceField } from './types';

/*
 * The field blocks the seven content types share, built once rather than seven
 * times (§5.10). Order is pinned: the §2.2 shared columns, then the tag picker,
 * then the type's own columns in §2.3's order.
 *
 * Length rules live in `help` because it is the descriptor's only visible-text
 * channel and `ResourceForm.vue` has no `maxlength` source (§7.7 item 6). Only
 * limits under 500 characters are stated — an unbounded `text` column and a
 * 2048-character URL column are not reachable by typing.
 */
export function contentFields(
    options: { bodyHelp?: string } = {},
): ResourceField[] {
    return [
        {
            name: 'title',
            label: 'Title',
            type: 'text',
            required: true,
            help: 'Max 150 characters. The slug is derived from this on create and never changes afterwards.',
        },
        {
            name: 'summary',
            label: 'Summary',
            type: 'textarea',
            help: 'Card text in lists, and the fallback for the meta description.',
        },
        {
            name: 'body',
            label: 'Body',
            type: 'textarea',
            wide: true,
            help: options.bodyHelp ?? 'Plain text.',
        },
        {
            name: 'status',
            label: 'Status',
            type: 'select',
            options: [
                { value: 'draft', label: 'Draft' },
                { value: 'published', label: 'Published' },
            ],
            help: 'Drafts are never publicly reachable.',
        },
        {
            name: 'published_at',
            label: 'Published at',
            type: 'datetime',
            help: 'A published record is hidden until this moment, so a future date schedules it.',
        },
        placeholderField(),
        {
            name: 'meta_title',
            label: 'Meta title',
            type: 'text',
            help: 'Max 60 characters; falls back to the title.',
        },
        {
            name: 'meta_description',
            label: 'Meta description',
            type: 'textarea',
            help: 'Max 160 characters; falls back to the summary.',
        },
        {
            name: 'sort_order',
            label: 'Sort order',
            type: 'number',
            help: 'Lower numbers come first in the admin list.',
        },
    ];
}

/*
 * The SC-11 placeholder flag, shared with `tags` (§2.4 item 9).
 */
export function placeholderField(): ResourceField {
    return {
        name: 'is_placeholder',
        label: 'Placeholder',
        type: 'checkbox',
        help: 'Placeholder content must never read as a real claim.',
    };
}

/*
 * The tag picker. The admin payload is `tag_ids: int[]`, not the API's
 * JSON:API relationship shape (§5.9).
 */
export function tagsField(): ResourceField {
    return {
        name: 'tag_ids',
        label: 'Tags',
        type: 'tags',
        wide: true,
        help: 'Pick existing tags or type a new name to create one.',
    };
}

/*
 * §5.9's column set. `slug` is read-only here and nowhere else: it is derived
 * server-side and the form never submits it (§2.4 item 2, §5.9).
 */
export function contentColumns(): {
    key: string;
    label: string;
    numeric?: boolean;
}[] {
    return [
        { key: 'title', label: 'Title' },
        { key: 'slug', label: 'Slug' },
        { key: 'status', label: 'Status' },
        { key: 'tags', label: 'Tags' },
        { key: 'published_at', label: 'Published' },
        { key: 'sort_order', label: 'Order', numeric: true },
        { key: 'updated_at', label: 'Updated' },
    ];
}

/*
 * The filter grammar of §5.9: `?status=draft&q=laravel&tag=php`. `status` is the
 * only enumerable vocabulary; `tag` is a slug typed into a text filter because a
 * static descriptor cannot enumerate the tag table.
 */
export function contentFilters(): {
    name: string;
    label: string;
    type: 'select' | 'text';
    options?: { value: string; label: string }[];
}[] {
    return [
        { name: 'q', label: 'Search', type: 'text' },
        {
            name: 'status',
            label: 'Status',
            type: 'select',
            options: [
                { value: 'draft', label: 'Draft' },
                { value: 'published', label: 'Published' },
            ],
        },
        { name: 'tag', label: 'Tag slug', type: 'text' },
    ];
}
