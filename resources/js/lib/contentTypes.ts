import type { Tone } from '@/components/ui/badge';

export type ContentType =
    | 'work_experience'
    | 'education'
    | 'certification'
    | 'publication'
    | 'project'
    | 'award'
    | 'writing';

export type Tag = {
    slug: string;
    name: string;
};

export const contentTypeLabels: Record<ContentType, string> = {
    work_experience: 'Work experience',
    education: 'Education',
    certification: 'Certification',
    publication: 'Publication',
    project: 'Project',
    award: 'Award',
    writing: 'Writing',
};

export const contentTypeTones: Record<ContentType, Tone> = {
    work_experience: 'viridian',
    education: 'sky',
    certification: 'marigold',
    publication: 'violet',
    project: 'leaf',
    award: 'vermilion',
    writing: 'rose',
};
