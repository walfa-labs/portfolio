import {
    contentColumns,
    contentFields,
    contentFilters,
    tagsField,
} from './shared';
import type { ResourceDescriptor } from './types';

/*
 * `body_html` and `reading_time_minutes` are derived from `body` when the record
 * is saved (§2.3), so they are never form fields.
 *
 * `series_id` is a `select` with no static options: the series table is data, so
 * the create/edit pages must supply `{ value: id, label: title }` for it at
 * runtime. Media (`media.alt` for the cover and inline images, §7.8, §9.2) is a
 * phase-8 concern and is not part of this form.
 */
export const writings: ResourceDescriptor = {
    segment: 'writings',
    label: 'Writing',
    pluralLabel: 'Writings',
    model: 'App\\Models\\Writing',
    orderable: false,
    fields: [
        ...contentFields({
            bodyHelp: 'Markdown; rendered to HTML when the record is saved.',
        }),
        tagsField(),
        {
            name: 'excerpt',
            label: 'Excerpt',
            type: 'textarea',
            help: 'Max 280 characters; the summary shown in lists and in the feed.',
        },
        {
            name: 'series_id',
            label: 'Series',
            type: 'select',
            help: 'Leave empty for a standalone post.',
        },
        {
            name: 'og_image_path',
            label: 'Open Graph image path',
            type: 'text',
            wide: true,
            help: 'Storage path of the 1200x630 image; empty falls back to the default card.',
        },
    ],
    columns: contentColumns(),
    filters: contentFilters(),
    defaultSort: { field: 'published_at', direction: 'desc' },
};
