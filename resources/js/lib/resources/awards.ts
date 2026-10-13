import {
    contentColumns,
    contentFields,
    contentFilters,
    tagsField,
} from './shared';
import type { ResourceDescriptor } from './types';

export const awards: ResourceDescriptor = {
    segment: 'awards',
    label: 'Award',
    pluralLabel: 'Awards',
    model: 'App\\Models\\Award',
    orderable: true,
    fields: [
        ...contentFields(),
        tagsField(),
        {
            name: 'awarding_body',
            label: 'Awarding body',
            type: 'text',
            required: true,
            help: 'Max 150 characters.',
        },
        {
            name: 'awarded_at',
            label: 'Awarded at',
            type: 'date',
            required: true,
        },
    ],
    columns: contentColumns(),
    filters: contentFilters(),
    defaultSort: { field: 'sort_order', direction: 'asc' },
};
