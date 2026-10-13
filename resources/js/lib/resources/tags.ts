import { placeholderField } from './shared';
import type { ResourceDescriptor } from './types';

/*
 * A tag has no shared content block (§2.5): no `title`, no `status`, no
 * `meta_*`, no `sort_order`, and nothing to tag it with. It is ordered by the
 * ASCII-normalised key `LOWER(name), id` (§2.9 item 4), never by a raw `name`.
 */
export const tags: ResourceDescriptor = {
    segment: 'tags',
    label: 'Tag',
    pluralLabel: 'Tags',
    model: 'App\\Models\\Tag',
    orderable: false,
    fields: [
        {
            name: 'name',
            label: 'Name',
            type: 'text',
            required: true,
            help: 'Max 60 characters. The slug is derived from this on create and never changes afterwards.',
        },
        {
            name: 'description',
            label: 'Description',
            type: 'textarea',
        },
        placeholderField(),
    ],
    columns: [
        { key: 'name', label: 'Name' },
        { key: 'slug', label: 'Slug' },
        { key: 'is_placeholder', label: 'Placeholder' },
        { key: 'updated_at', label: 'Updated' },
    ],
    filters: [{ name: 'q', label: 'Search', type: 'text' }],
    defaultSort: { field: 'name', direction: 'asc' },
};
