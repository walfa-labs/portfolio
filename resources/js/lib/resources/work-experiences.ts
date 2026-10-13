import {
    contentColumns,
    contentFields,
    contentFilters,
    tagsField,
} from './shared';
import type { ResourceDescriptor } from './types';

export const workExperiences: ResourceDescriptor = {
    segment: 'work-experiences',
    label: 'Work experience',
    pluralLabel: 'Work experience',
    model: 'App\\Models\\WorkExperience',
    orderable: true,
    fields: [
        ...contentFields(),
        tagsField(),
        {
            name: 'employer',
            label: 'Employer',
            type: 'text',
            required: true,
            help: 'Max 150 characters.',
        },
        {
            name: 'employer_url',
            label: 'Employer URL',
            type: 'url',
        },
        {
            name: 'location',
            label: 'Location',
            type: 'text',
            help: 'Max 100 characters.',
        },
        {
            name: 'start_date',
            label: 'Start date',
            type: 'date',
            required: true,
        },
        {
            name: 'end_date',
            label: 'End date',
            type: 'date',
            help: 'Leave empty for the current role; an empty end date renders as "Present".',
        },
        {
            name: 'highlights',
            label: 'Highlights',
            type: 'textarea',
            wide: true,
            help: 'One highlight per line, max 250 characters each.',
        },
    ],
    columns: contentColumns(),
    filters: contentFilters(),
    defaultSort: { field: 'sort_order', direction: 'asc' },
};
