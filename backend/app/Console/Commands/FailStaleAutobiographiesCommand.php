<?php

namespace App\Console\Commands;

use App\Application\Services\AutobiographyGenerationState;
use App\Models\Autobiography;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FailStaleAutobiographiesCommand extends Command
{
    protected $signature = 'soulgraph:fail-stale-autobiographies
                            {--minutes=15 : Mark processing as failed if step unchanged this long}
                            {--id= : Only check one autobiography UUID}';

    protected $description = 'Fail autobiography generations stuck in processing';

    public function handle(): int
    {
        $minutes = max(5, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);

        $query = Autobiography::where('status', 'processing');
        if ($id = $this->option('id')) {
            $query->where('id', $id);
        }

        $count = 0;
        foreach ($query->get() as $autobiography) {
            $params = $autobiography->scope_params ?? [];
            $stepAt = $params['generation_step_at'] ?? $autobiography->updated_at?->toIso8601String();
            $step = $params['generation_step'] ?? '?';

            try {
                $lastActivity = $stepAt ? Carbon::parse($stepAt) : $autobiography->updated_at;
            } catch (\Throwable) {
                $lastActivity = $autobiography->updated_at;
            }

            if ($lastActivity && $lastActivity->greaterThan($cutoff)) {
                $this->line("Skip {$autobiography->id} (step: {$step}, active {$lastActivity->diffForHumans()})");

                continue;
            }

            AutobiographyGenerationState::fail(
                $autobiography,
                "Генерация зависла на шаге «{$step}» более {$minutes} мин. "
                .'Перезапустите queue:work и создайте автобиографию заново.',
            );
            $count++;
            $this->warn("Failed {$autobiography->id} (was on step: {$step})");
        }

        $this->info("Marked {$count} autobiograph".($count === 1 ? 'y' : 'ies').' as failed.');

        return self::SUCCESS;
    }
}
