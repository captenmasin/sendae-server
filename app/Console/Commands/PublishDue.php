<?php

namespace App\Console\Commands;

use App\Services\Publisher;
use Illuminate\Console\Command;

class PublishDue extends Command
{
    protected $signature = 'sendae:publish';

    protected $description = 'Publish due posts and mark expired posts missed (hosted mode only)';

    public function handle(Publisher $publisher): int
    {
        $publisher->tick();

        return 0;
    }
}
