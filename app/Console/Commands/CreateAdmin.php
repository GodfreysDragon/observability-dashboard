<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('obs:admin {email : Sign-in email for the dashboard} {--name= : Display name (defaults to the email\'s local part)}')]
#[Description('Create a dashboard user, or reset an existing user\'s password')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $email = mb_strtolower((string) $this->argument('email'));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email']])->fails()) {
            $this->components->error("[{$email}] is not a valid email address.");

            return self::FAILURE;
        }

        // Prompt when someone is at the terminal; otherwise generate a password and show it once.
        $password = $this->input->isInteractive() ? (string) $this->secret('Password (leave empty to generate one)') : '';
        $generated = $password === '';

        if ($generated) {
            $password = Str::password(20, symbols: false);
        } elseif (mb_strlen($password) < 12) {
            $this->components->error('Use at least 12 characters.');

            return self::FAILURE;
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => $this->option('name') ?: Str::before($email, '@'), 'password' => $password],
        );

        $this->components->info(($user->wasRecentlyCreated ? 'Created' : 'Updated')." dashboard user {$email}.");

        if ($generated) {
            $this->components->twoColumnDetail('Password', $password);
        }

        return self::SUCCESS;
    }
}
