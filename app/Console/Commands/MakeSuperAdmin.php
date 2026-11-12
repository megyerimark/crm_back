<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('crm:super-admin {email} {--revoke}')]
#[Description('Grant (or revoke) VállalkozóOS Super Admin access for a user')]
class MakeSuperAdmin extends Command
{
    public function handle(): int
    {
        $user = User::where('email', strtolower($this->argument('email')))->first();

        if (! $user) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $user->forceFill(['is_super_admin' => ! $this->option('revoke')])->save();
        $this->info($user->email.($this->option('revoke') ? ' is no longer' : ' is now').' a super admin.');

        return self::SUCCESS;
    }
}
