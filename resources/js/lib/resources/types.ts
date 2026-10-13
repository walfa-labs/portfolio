/*
 * The descriptor contract the admin UI is driven by (§5.10). One descriptor per
 * resource feeds both the schema-driven `ResourceForm.vue` and the list columns
 * and filters, so the form, the table and the create/edit prop shapes cannot
 * drift apart.
 */
export interface ResourceField {
    /*
     * The flat form field name — the name the admin FormRequest validates, not
     * the API's JSON:API path (§5.10). `tag_ids` is an `int[]` of tag ids.
     */
    name: string;
    label: string;
    type:
        | 'text'
        | 'textarea'
        | 'select'
        | 'checkbox'
        | 'number'
        | 'date'
        | 'datetime'
        | 'url'
        | 'tags';
    required?: boolean;
    options?: { value: string; label: string }[];
    /* Visible rule text, tied to the control with `aria-describedby` (§7.7). */
    help?: string;
    /* Render full-width in the two-column form grid. */
    wide?: boolean;
}

export interface ResourceDescriptor {
    /* Kebab route segment, e.g. `work-experiences` (§5.8). */
    segment: string;
    /* Singular label, e.g. `Work experience`. */
    label: string;
    /*
     * The sidebar label (§5.8 decision 3). `Work experience` and `Education` are
     * mass nouns, so their plural label is the singular one.
     */
    pluralLabel: string;
    /* FQCN of the Eloquent model the routes bind. */
    model: string;
    /* Only the six orderable types take the `reorder` route (§5.9). */
    orderable: boolean;
    /* Display order. `slug` is never a field: it is derived and stable (§2.4). */
    fields: ResourceField[];
    columns: { key: string; label: string; numeric?: boolean }[];
    filters: {
        name: string;
        label: string;
        type: 'select' | 'text';
        options?: { value: string; label: string }[];
    }[];
    /* The sort the list falls back to when the query string carries none. */
    defaultSort: { field: string; direction: 'asc' | 'desc' };
}

export type ResourceSegment =
    | 'work-experiences'
    | 'educations'
    | 'certifications'
    | 'publications'
    | 'projects'
    | 'awards'
    | 'writings'
    | 'tags';
