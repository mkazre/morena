<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * One-shot deploy/update for hosts without SSH (cPanel): run it from a temporary cron job.
 * Safe to run repeatedly — every step is idempotent.
 *
 *   php artisan morena:deploy --admin-email=you@morenagroup.co.za --admin-name="Your Name"
 */
class Deploy extends Command
{
    protected $signature = 'morena:deploy
        {--admin-email= : Create this admin (only if no user has that email) and email them a set-password link}
        {--admin-name=Administrator : Name for the admin created with --admin-email}';

    protected $description = 'Migrate, seed reference data, link storage, cache config/routes, and create the first admin';

    public function handle(): int
    {
        $this->line('== Morena deploy '.now()->toDateTimeString().' ('.app()->environment().') ==');

        if (! $this->releaseComplete()) {
            return self::FAILURE; // never migrate a half-extracted release
        }

        try {
            $this->step('Database migrations', fn () => Artisan::call('migrate', ['--force' => true]));
            $this->step('Reference data (roles, pipeline, commission rules, categories)', fn () => Artisan::call('db:seed', [
                '--class' => ReferenceDataSeeder::class, '--force' => true,
            ]));
            $this->linkStorage();
            $this->step('Lead scores', fn () => \App\Services\LeadScoring::rescoreAll());
            $this->step('Clear old caches', fn () => Artisan::call('optimize:clear'));
            if (app()->isProduction()) {
                $this->step('Cache config, routes and views', fn () => Artisan::call('optimize'));
            }
            $this->createAdmin();
        } catch (Throwable $e) {
            $this->error('FAILED: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        $this->info('== Done ==');

        return self::SUCCESS;
    }

    /**
     * build-release.ps1 writes release-manifest.txt (every file in the zip). If File Manager's extract stopped early —
     * usually the hosting account's disk or file-count (inode) limit, or a timeout — files that are new in this
     * release are missing while overwritten ones look fine. Check before touching the database.
     */
    private function releaseComplete(): bool
    {
        $manifest = base_path('release-manifest.txt');
        if (! is_file($manifest)) {
            $this->warn('WARN No release-manifest.txt — skipping the file check (older release zip).');

            return true;
        }
        $lines = preg_split('/\r\n|\n/', trim(file_get_contents($manifest)));
        $release = str_starts_with($lines[0] ?? '', '# ') ? substr(array_shift($lines), 2) : 'unknown';
        $missing = array_values(array_filter($lines, fn ($f) => $f !== '' && ! is_file(base_path($f))));
        if ($missing) {
            $this->error('FAILED: release '.$release.' is incomplete — '.count($missing).' of '.count($lines).' files are missing on the server, e.g.:');
            foreach (array_slice($missing, 0, 12) as $f) {
                $this->line('    '.$f);
            }
            $this->line('    Nothing was changed in the database. Likely cause: the hosting account hit its disk-space or');
            $this->line('    file-count (inode) limit, or File Manager stopped extracting. Check cPanel → Disk Usage / the');
            $this->line('    "Inodes" figure in the sidebar, delete old zips, then extract the zip again (overwrite) and re-run.');

            return false;
        }
        $this->info('OK  Release '.$release.' — all '.count($lines).' files present');

        return true;
    }

    private function step(string $label, callable $run): void
    {
        $run();
        $this->info('OK  '.$label);
        $out = trim(Artisan::output());
        if ($out !== '') {
            $this->line(preg_replace('/^/m', '    ', $out));
        }
    }

    /** public/storage → storage/app/public (listing photos). Some shared hosts disable symlink(). */
    private function linkStorage(): void
    {
        $link = public_path('storage');
        if (is_link($link) || is_dir($link)) {
            $this->info('OK  Storage link already exists');

            return;
        }
        try {
            File::link(storage_path('app/public'), $link);
            $this->info('OK  Storage link created');
        } catch (Throwable $e) {
            $this->warn('WARN Could not create public/storage symlink ('.$e->getMessage().'). Listing photos will not display until it exists.');
        }
    }

    private function createAdmin(): void
    {
        $email = $this->option('admin-email');
        if (! $email) {
            return;
        }
        if (User::where('email', $email)->exists()) {
            $this->info("OK  Admin {$email} already exists — not changed");

            return;
        }

        $user = User::create(['name' => $this->option('admin-name'), 'email' => $email, 'status' => 'invited']);
        $user->assignRole('admin');
        $status = Password::broker()->sendResetLink(['email' => $email]);
        $this->info("OK  Admin {$email} created");
        $this->line($status === Password::RESET_LINK_SENT
            ? '    Set-password link emailed. Check the inbox (and spam).'
            : '    Could not email the set-password link ('.__($status).'). Fix MAIL_* in .env, then use "Forgot password?" on the login page.');
    }
}
