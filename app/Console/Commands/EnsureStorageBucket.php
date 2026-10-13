<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Create the two media buckets idempotently (§9.3).
 *
 * Garage offers no anonymous bucket creation through the app, so the buckets
 * are provisioned explicitly: `portfolio-media` on the `s3` disk and
 * `portfolio-private` on `s3-private`. The command is safe to re-run — it
 * checks before creating — which is what lets it sit in a bring-up script.
 */
#[Signature('storage:ensure-bucket')]
#[Description('Create the public and private media buckets idempotently')]
class EnsureStorageBucket extends Command
{
    /**
     * The disks whose buckets are provisioned.
     *
     * @var array<int, string>
     */
    protected array $disks = ['s3', 's3-private'];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $succeeded = true;

        foreach ($this->disks as $diskName) {
            $succeeded = $this->ensureBucket($diskName) && $succeeded;
        }

        return $succeeded ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Create one disk's bucket when it does not already exist.
     */
    protected function ensureBucket(string $diskName): bool
    {
        $bucket = (string) config("filesystems.disks.{$diskName}.bucket");

        if ($bucket === '') {
            $this->error("Disk [{$diskName}] has no bucket configured.");

            return false;
        }

        $disk = Storage::disk($diskName);

        if (! $disk instanceof AwsS3V3Adapter) {
            $this->error("Disk [{$diskName}] is not an S3 disk, so its bucket cannot be provisioned.");

            return false;
        }

        try {
            $client = $disk->getClient();

            if ($client->doesBucketExist($bucket)) {
                $this->info("Bucket [{$bucket}] on disk [{$diskName}] already exists.");

                return true;
            }

            $client->createBucket(['Bucket' => $bucket]);
        } catch (Throwable $exception) {
            $this->error("Could not provision bucket [{$bucket}] on disk [{$diskName}]: {$exception->getMessage()}");

            return false;
        }

        $this->info("Created bucket [{$bucket}] on disk [{$diskName}].");

        return true;
    }
}
