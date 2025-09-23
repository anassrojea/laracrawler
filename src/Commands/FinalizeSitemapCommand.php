<?php

namespace Anassrojea\Laracrawler\Commands;

use Illuminate\Console\Command;
use Anassrojea\Laracrawler\Jobs\FinalizeSitemapJob;

class FinalizeSitemapCommand extends Command
{
    protected $signature = 'laracrawler:finalize 
                            {--output=public : Output directory for sitemap files}
                            {--sync : Run finalize job immediately instead of queueing it}';

    protected $description = 'Manually finalize sitemap from cached crawl results (normally auto-finalized when using --queue).';

    /**
     * Run the finalize sitemap job for the given output directory.
     *
     * This command will either run the job synchronously or dispatch it to the queue worker.
     * If the `--sync` option is provided, the job will be run synchronously.
     * Otherwise, the job will be dispatched to the queue worker.
     *
     * @return int
     */
    public function handle(): int
    {
        $output = $this->option('output');

        if ($this->option('sync')) {
            $this->info("⚡ Running FinalizeSitemapJob synchronously for: {$output}");

            (new FinalizeSitemapJob($output))->handle();

            $this->info("✅ Sitemap finalized immediately.");
        } else {
            $this->info("🚀 Dispatching FinalizeSitemapJob for output directory: {$output}");

            FinalizeSitemapJob::dispatch($output)
                ->onConnection(config('sitemap.queue.connection', 'database'));

            $this->info("✅ Finalize job dispatched. It will run on your queue worker.");
        }

        return self::SUCCESS;
    }
}
