import {
    contentColumns,
    contentFields,
    contentFilters,
    tagsField,
} from './shared';
import type { ResourceDescriptor } from './types';

export const projects: ResourceDescriptor = {
    segment: 'projects',
    label: 'Project',
    pluralLabel: 'Projects',
    model: 'App\\Models\\Project',
    orderable: true,
    fields: [
        ...contentFields(),
        tagsField(),
        {
            name: 'repo_url',
            label: 'Repository URL',
            type: 'url',
        },
        {
            name: 'live_url',
            label: 'Live URL',
            type: 'url',
        },
        {
            name: 'started_on',
            label: 'Started on',
            type: 'date',
        },
        {
            name: 'ended_on',
            label: 'Ended on',
            type: 'date',
            help: 'Leave empty while the project is active.',
        },
        {
            name: 'is_featured',
            label: 'Featured',
            type: 'checkbox',
            help: 'Featured published projects are the candidates for the home page "Selected work".',
        },
    ],
    columns: contentColumns(),
    filters: contentFilters(),
    defaultSort: { field: 'sort_order', direction: 'asc' },
};
