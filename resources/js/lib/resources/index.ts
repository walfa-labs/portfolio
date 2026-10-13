import { awards } from './awards';
import { certifications } from './certifications';
import { educations } from './educations';
import { projects } from './projects';
import { publications } from './publications';
import { tags } from './tags';
import type { ResourceDescriptor } from './types';
import { workExperiences } from './work-experiences';
import { writings } from './writings';

export { awards, certifications, educations, projects, publications, tags };
export { workExperiences, writings };
export type {
    ResourceDescriptor,
    ResourceField,
    ResourceSegment,
} from './types';

/*
 * Keyed by the kebab route segment, in the order the sidebar lists them (§5.8).
 */
export const resources: Record<string, ResourceDescriptor> = {
    'work-experiences': workExperiences,
    educations,
    certifications,
    publications,
    projects,
    awards,
    writings,
    tags,
};

/*
 * The one lookup a page needs. An unknown segment is a programming error — the
 * admin routes are per-resource, so the segment is always a literal.
 */
export function descriptorFor(segment: string): ResourceDescriptor {
    const descriptor = resources[segment];

    if (!descriptor) {
        throw new Error(`Unknown admin resource segment "${segment}".`);
    }

    return descriptor;
}
