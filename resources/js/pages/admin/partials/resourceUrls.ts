/*
 * The Wayfinder route functions the admin partials call.
 *
 * `ResourceTable.vue`, `ResourceForm.vue` and `ReorderList.vue` are handed a
 * `ResourceDescriptor` and never a URL, so each of them needs the routes behind
 * that descriptor's segment. This module is the one place that maps the kebab
 * segment to the generated Wayfinder module (§5.12) — the same reason
 * `lib/resources` holds one descriptor per type rather than eight copies.
 *
 * A `switch` rather than an indexed lookup: each module's `edit`, `destroy`,
 * `publish` and `unpublish` take the record's route key as their own named
 * parameter, and a union of eight of those functions is not callable as one.
 * An unknown segment throws, for the same reason `descriptorFor()` throws — it
 * is a programming error, not a runtime state.
 */

import awards from '@/routes/admin/awards';
import certifications from '@/routes/admin/certifications';
import educations from '@/routes/admin/educations';
import projects from '@/routes/admin/projects';
import publications from '@/routes/admin/publications';
import tags from '@/routes/admin/tags';
import workExperiences from '@/routes/admin/work-experiences';
import writings from '@/routes/admin/writings';

/** One row of an admin list: `AdminController::recordPayload()`'s flat shape. */
export type AdminRecord = {
    id: number;
    title?: string | null;
    name?: string | null;
    slug?: string | null;
    status?: string | null;
    published_at?: string | null;
    updated_at?: string | null;
    sort_order?: number | null;
    is_placeholder?: boolean;
    tag_ids?: number[];
    tags?: { id: number; name: string; slug: string }[];
    [key: string]: unknown;
};

/** Laravel's `LengthAwarePaginator` payload, as an admin index sends it. */
export type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    path: string;
    links: { url: string | null; label: string; active: boolean }[];
};

/** The routes every admin resource answers (§5.8). */
export type AdminUrls = {
    index: string;
    edit: (id: number) => string;
    destroy: (id: number) => string;
    publish: (id: number) => string;
    unpublish: (id: number) => string;
    /** `null` for `writings` and `tags`, which the §5.8 route set never gives a `reorder`. */
    reorder: (() => string) | null;
};

/**
 * The eight routes of one resource, resolved from its route segment.
 */
export function adminUrls(segment: string): AdminUrls {
    switch (segment) {
        case 'work-experiences':
            return {
                index: workExperiences.index.url(),
                edit: (id) => workExperiences.edit(id).url,
                destroy: (id) => workExperiences.destroy(id).url,
                publish: (id) => workExperiences.publish(id).url,
                unpublish: (id) => workExperiences.unpublish(id).url,
                reorder: () => workExperiences.reorder().url,
            };
        case 'educations':
            return {
                index: educations.index.url(),
                edit: (id) => educations.edit(id).url,
                destroy: (id) => educations.destroy(id).url,
                publish: (id) => educations.publish(id).url,
                unpublish: (id) => educations.unpublish(id).url,
                reorder: () => educations.reorder().url,
            };
        case 'certifications':
            return {
                index: certifications.index.url(),
                edit: (id) => certifications.edit(id).url,
                destroy: (id) => certifications.destroy(id).url,
                publish: (id) => certifications.publish(id).url,
                unpublish: (id) => certifications.unpublish(id).url,
                reorder: () => certifications.reorder().url,
            };
        case 'publications':
            return {
                index: publications.index.url(),
                edit: (id) => publications.edit(id).url,
                destroy: (id) => publications.destroy(id).url,
                publish: (id) => publications.publish(id).url,
                unpublish: (id) => publications.unpublish(id).url,
                reorder: () => publications.reorder().url,
            };
        case 'projects':
            return {
                index: projects.index.url(),
                edit: (id) => projects.edit(id).url,
                destroy: (id) => projects.destroy(id).url,
                publish: (id) => projects.publish(id).url,
                unpublish: (id) => projects.unpublish(id).url,
                reorder: () => projects.reorder().url,
            };
        case 'awards':
            return {
                index: awards.index.url(),
                edit: (id) => awards.edit(id).url,
                destroy: (id) => awards.destroy(id).url,
                publish: (id) => awards.publish(id).url,
                unpublish: (id) => awards.unpublish(id).url,
                reorder: () => awards.reorder().url,
            };
        case 'writings':
            return {
                index: writings.index.url(),
                edit: (id) => writings.edit(id).url,
                destroy: (id) => writings.destroy(id).url,
                publish: (id) => writings.publish(id).url,
                unpublish: (id) => writings.unpublish(id).url,
                reorder: null,
            };
        case 'tags':
            return {
                index: tags.index.url(),
                edit: (id) => tags.edit(id).url,
                destroy: (id) => tags.destroy(id).url,
                publish: (id) => tags.publish(id).url,
                unpublish: (id) => tags.unpublish(id).url,
                reorder: null,
            };
        default:
            throw new Error(`Unknown admin resource segment "${segment}".`);
    }
}

/** The rows of a paginator payload, or of a bare array of the same rows. */
export function rowsOf<T>(records: Paginator<T> | T[]): T[] {
    return Array.isArray(records) ? records : records.data;
}

/**
 * The record's display name: its own title or name, then its `slug`, then its
 * `id`. Used for the accessible names of row actions and for the delete
 * confirmation, which must name the record it deletes (§5.9).
 */
export function displayName(record: AdminRecord): string {
    const name = record.title ?? record.name ?? record.slug;

    return typeof name === 'string' && name.trim() !== ''
        ? name
        : `#${record.id}`;
}
