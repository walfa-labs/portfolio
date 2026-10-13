<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdministratorSeeder extends Seeder
{
    /**
     * Placeholder credentials for a local login (§5.2).
     *
     * The `.test` domain is reserved for exactly this purpose, and the password
     * is deliberately obvious: this seeder is never a provisioning path for a
     * real deployment, which is what the production guard below enforces. The
     * email is read from `config('portfolio.admin.email')`, whose default is the
     * placeholder address, so the seeded account and `User::isAdministrator()`
     * cannot disagree.
     */
    private const DEFAULT_PASSWORD = 'password';

    /**
     * The placeholder admin address `config('portfolio.admin.email')` falls back
     * to when `ADMIN_EMAIL` is unset or empty.
     */
    private const PLACEHOLDER_EMAIL = 'admin@example.test';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->isProduction() && ! config('portfolio.admin_force_seed')) {
            $this->command->warn(
                'AdministratorSeeder skipped: APP_ENV=production. Set ADMIN_FORCE_SEED=true to override.',
            );

            return;
        }

        $email = (string) config('portfolio.admin.email');

        if (app()->isProduction() && $email === self::PLACEHOLDER_EMAIL) {
            $this->command->warn(
                'AdministratorSeeder skipped: the placeholder admin email is still configured. Set ADMIN_EMAIL to a real address.',
            );

            return;
        }

        $name = (string) config('portfolio.admin.name');

        $administrator = User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => self::DEFAULT_PASSWORD],
        );

        // `email_verified_at` is not a fillable attribute on `User`, so it is
        // assigned directly — the admin has no inbox to verify from.
        $administrator->email_verified_at = now();
        $administrator->save();

        $this->seedProfile($name, $email);
    }

    /**
     * Create the single profile row if it does not exist yet (§2.3).
     *
     * First-or-create rather than update: the profile is the owner's own record
     * and re-running `db:seed` must never overwrite edits made in the admin UI.
     */
    private function seedProfile(string $name, string $email): void
    {
        Profile::query()->firstOrCreate([], [
            'name' => $name,
            'role_headline' => 'Software Engineer',
            'positioning_sentence' => 'Placeholder positioning sentence — replace it in the admin profile screen.',
            'location' => 'Remote',
            'availability' => 'Open to contract work',
            'email' => $email,
            'github_url' => null,
            'linkedin_url' => null,
            'orcid_url' => null,
            'years_experience_start' => now()->subYears(12)->startOfYear()->toDateString(),
        ]);
    }
}
