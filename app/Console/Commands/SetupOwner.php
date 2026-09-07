<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SetupOwner extends Command
{
    protected $signature = 'sendae:owner {--token : Issue a desktop/API token for the owner}';

    protected $description = 'Set up the single owner account or issue an access token';

    public function handle(): int
    {
        $user = User::first();
        if (! $user) {
            $email = $this->ask('Owner email');
            $password = $this->secret('Password (at least 12 characters)');
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password ?? '') < 12) {
                $this->error('Enter a valid email and a password of at least 12 characters.');

                return 1;
            }
            $user = User::create(['name' => 'Owner', 'email' => $email, 'password' => Hash::make($password)]);
        }
        if ($this->option('token')) {
            $this->line($user->createToken('Sendae desktop', ['mcp:use'])->accessToken);
        } else {
            $this->info('Owner account is ready. Sign in to the Sendae desktop with this email and password.');
        }

        return 0;
    }
}
