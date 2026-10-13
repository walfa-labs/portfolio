import {
    contentColumns,
    contentFields,
    contentFilters,
    tagsField,
} from './shared';
import type { ResourceDescriptor } from './types';

export const educations: ResourceDescriptor = {
    segment: 'educations',
    label: 'Education',
    pluralLabel: 'Education',
    model: 'App\\Models\\Education',
    orderable: true,
    fields: [
        ...contentFields(),
        tagsField(),
        {
            name: 'institution',
            label: 'Institution',
            type: 'text',
            required: true,
            help: 'Max 150 characters.',
        },
        {
            name: 'institution_url',
            label: 'Institution URL',
            type: 'url',
        },
        {
            name: 'field_of_study',
            label: 'Field of study',
            type: 'text',
            help: 'Max 150 characters.',
        },
        {
            name: 'start_date',
            label: 'Start date',
            type: 'date',
        },
        {
            name: 'end_date',
            label: 'End date',
            type: 'date',
            help: 'Leave empty if the studies are ongoing.',
        },
    ],
    columns: contentColumns(),
    filters: contentFilters(),
    defaultSort: { field: 'sort_order', direction: 'asc' },
};
