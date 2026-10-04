<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class AdminDemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('admin_demo.email');
        $password = config('admin_demo.password');
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! is_string($password) || trim($password) === '') {
            throw new LogicException('Configure ADMIN_DEMO_EMAIL and ADMIN_DEMO_PASSWORD before seeding the recruiter demo.');
        }
        if (strcasecmp($email, (string) config('demo.email')) === 0) {
            throw new LogicException('The recruiter demo must use a separate email from the normal demo.');
        }

        DB::transaction(function () use ($email, $password): void {
            $user = User::whereRaw('LOWER(email) = ?', [strtolower($email)])->lockForUpdate()->first() ?? new User(['email' => $email]);
            if ($user->exists && (! $user->is_admin_demo || $user->is_admin || $user->is_demo)) {
                throw new LogicException('The recruiter demo email belongs to an unrelated account. Choose a dedicated email.');
            }
            $user->forceFill([
                'name' => 'Recruiter Demo', 'password' => $password,
                'is_admin_demo' => true, 'is_admin' => false, 'is_demo' => false,
            ])->save();
        });
    }
}
