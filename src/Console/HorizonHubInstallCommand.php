<?php

namespace HorizonHub\Agent\Console;

use Illuminate\Console\Command;

class HorizonHubInstallCommand extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'horizonhub:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish Horizon Hub Agent configuration file';

    /**
     * Execute the console command.
     */
    public function handle(): int {
        $this->info('Publishing Horizon Hub Agent configuration...');

        $this->call('vendor:publish', [
            '--tag' => 'horizon-hub-agent-config',
        ]);

        $this->info('Horizon Hub Agent configuration published successfully.');

        return self::SUCCESS;
    }
}
