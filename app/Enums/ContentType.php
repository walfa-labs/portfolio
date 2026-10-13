<?php

namespace App\Enums;

use App\Models\Award;
use App\Models\Certification;
use App\Models\Education;
use App\Models\Project;
use App\Models\Publication;
use App\Models\WorkExperience;
use App\Models\Writing;
use Illuminate\Database\Eloquent\Model;

enum ContentType: string
{
    case WorkExperience = 'work_experience';
    case Education = 'education';
    case Certification = 'certification';
    case Publication = 'publication';
    case Project = 'project';
    case Award = 'award';
    case Writing = 'writing';

    /**
     * Get the plural, kebab-cased URL segment for the type.
     */
    public function routeSegment(): string
    {
        return match ($this) {
            self::WorkExperience => 'work-experiences',
            self::Education => 'educations',
            self::Certification => 'certifications',
            self::Publication => 'publications',
            self::Project => 'projects',
            self::Award => 'awards',
            self::Writing => 'writings',
        };
    }

    /**
     * Get the fully-qualified model class backing the type.
     *
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::WorkExperience => WorkExperience::class,
            self::Education => Education::class,
            self::Certification => Certification::class,
            self::Publication => Publication::class,
            self::Project => Project::class,
            self::Award => Award::class,
            self::Writing => Writing::class,
        };
    }

    /**
     * Get the human-readable label for the type.
     */
    public function label(): string
    {
        return match ($this) {
            self::WorkExperience => 'Work Experience',
            self::Education => 'Education',
            self::Certification => 'Certification',
            self::Publication => 'Publication',
            self::Project => 'Project',
            self::Award => 'Award',
            self::Writing => 'Writing',
        };
    }
}
