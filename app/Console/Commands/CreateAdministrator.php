<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Provision the single administrator account (§5.2).
 *
 * This command is the provisioning path; `AdministratorSeeder` exists only so a
 * fresh checkout has a working local login. It is idempotent: re-running it
 * against the same address updates that account rather than creating a second
 * one, which is also how the address is changed when `ADMIN_EMAIL` moves (§13
 * R-ADMIN_EMAIL).
 *
 * `ADMIN_PASSWORD` is deliberately not read. Env-driven credentials leak through
 * `.env` backups, CI logs and process listings, so the password is asked for
 * interactively and only `--password` — an explicit, visible choice on the
 * command line — overrides that.
 */
#[Signature('admin:create {--email=} {--name=} {--password=}')]
#[Description('Create or update the single portfolio administrator account')]
class CreateAdministrator extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        /*
         * `ask()` returns its default without prompting under
         * `--no-interaction`, so a non-interactive run still resolves the
         * configured address and display name rather than failing.
         */
        $email = (string) ($this->option('email') ?: $this->ask(
            'Administrator email address',
            (string) config('portfolio.admin.email'),
        ));

        $name = (string) ($this->option('name') ?: $this->ask(
            'Administrator display name',
            (string) config('portfolio.admin.name'),
        ));

        $password = (string) ($this->option('password') ?: $this->secret('Password (leave blank to generate one)'));

        if ($password === '') {
            $password = Str::password();
            $this->line("Generated password: {$password}");
            $this->line('Change it after the first login — this is the only time it is shown.');
        }

        $administrator = User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => $password],
        );

        /*
         * `email_verified_at` is not fillable on `User`, and the `verified`
         * middleware guards every `/admin` route: without this stamp the first
         * login would land on `/admin` and be bounced straight back out (§5.2).
         */
        $administrator->email_verified_at = now();
        $administrator->save();

        $this->info("Administrator {$email} is ready.");

        /*
         * Authorisation compares the account's email against
         * `config('portfolio.admin.email')` (§5.7), so an `--email` that
         * disagrees with the configuration produces an account that can log in
         * and reach nothing. Say so rather than leaving a silent 403.
         */
        if (! $administrator->isAdministrator()) {
            $this->warn(
                "{$email} does not match config('portfolio.admin.email'). Set ADMIN_EMAIL to this address, or the account will be refused by the admin middleware.",
            );
        }

        return self::SUCCESS;
    }
}
