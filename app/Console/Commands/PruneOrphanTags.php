<?php

namespace App\Console\Commands;

use App\Models\Tag;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('prune:orphan-tags')]
#[Description('Delete tags that are not attached to any content')]
class PruneOrphanTags extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $pruned = Tag::whereDoesntHave('taggables')->delete();

        $this->info("Pruned {$pruned} orphan tag(s).");

        return self::SUCCESS;
    }
}
