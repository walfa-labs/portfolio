import {
    contentColumns,
    contentFields,
    contentFilters,
    tagsField,
} from './shared';
import type { ResourceDescriptor } from './types';

export const certifications: ResourceDescriptor = {
    segment: 'certifications',
    label: 'Certification',
    pluralLabel: 'Certifications',
    model: 'App\\Models\\Certification',
    orderable: true,
    fields: [
        ...contentFields(),
        tagsField(),
        {
            name: 'issuer',
            label: 'Issuer',
            type: 'text',
            required: true,
            help: 'Max 150 characters.',
        },
        {
            name: 'credential_id',
            label: 'Credential ID',
            type: 'text',
            help: 'Letters, digits and hyphens only, max 100 characters.',
        },
        {
            name: 'credential_url',
            label: 'Credential URL',
            type: 'url',
        },
        {
            name: 'issued_on',
            label: 'Issued on',
            type: 'date',
            required: true,
        },
        {
            name: 'expires_on',
            label: 'Expires on',
            type: 'date',
            help: 'Leave empty if it never expires; an expiry within 90 days is badged.',
        },
    ],
    columns: contentColumns(),
    filters: contentFilters(),
    defaultSort: { field: 'sort_order', direction: 'asc' },
};
