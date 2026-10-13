import {
    contentColumns,
    contentFields,
    contentFilters,
    tagsField,
} from './shared';
import type { ResourceDescriptor } from './types';

export const publications: ResourceDescriptor = {
    segment: 'publications',
    label: 'Publication',
    pluralLabel: 'Publications',
    model: 'App\\Models\\Publication',
    orderable: true,
    fields: [
        ...contentFields(),
        tagsField(),
        {
            name: 'venue',
            label: 'Venue',
            type: 'text',
            required: true,
            help: 'Max 255 characters.',
        },
        {
            name: 'authors',
            label: 'Authors',
            type: 'text',
            help: 'Comma-separated display names, max 255 characters.',
        },
        {
            name: 'doi',
            label: 'DOI',
            type: 'text',
            help: 'The identifier only, for example 10.1000/xyz123 — max 100 characters.',
        },
        {
            name: 'issued_on',
            label: 'Issued on',
            type: 'date',
            required: true,
        },
    ],
    columns: contentColumns(),
    filters: contentFilters(),
    defaultSort: { field: 'sort_order', direction: 'asc' },
};
