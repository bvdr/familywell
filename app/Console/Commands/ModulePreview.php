<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Console\Command;

// Opt a person in to (or out of, --off) a module's preview (App\Support\Modules\Preview). Run from the production console.
class ModulePreview extends Command
{
    protected $signature = 'module:preview {module} {email} {--off}';

    protected $description = "Show a module's preview (next version) to one person, or stop with --off";

    public function handle(ModuleRegistry $registry): int
    {
        $module = (string) $this->argument('module');
        if (! $registry->manifest($module)) {
            $this->error("No module {$module}.");

            return self::FAILURE;
        }
        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim((string) $this->argument('email')))])->first();
        if (! $user) {
            $this->error("No user with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $current = $user->preview_modules ?? [];
        $next = $this->option('off') ? array_values(array_diff($current, [$module])) : array_values(array_unique([...$current, $module]));
        $user->forceFill(['preview_modules' => $next ?: null])->save();
        $this->info(($this->option('off') ? 'Off' : 'On')." {$module} preview for {$user->email}. Previews now: ".(implode(', ', $next) ?: 'none'));

        return self::SUCCESS;
    }
}
