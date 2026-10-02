<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $user = User::firstOrNew(['email' => config('demo.email')]);
            if ($user->exists && ! $user->is_demo) {
                throw new LogicException('The demo email belongs to an existing non-demo account. Choose a dedicated demo email.');
            }
            $user->forceFill([
                'name' => 'Demo User', 'password' => config('demo.password'),
                'is_demo' => true, 'is_admin' => false,
            ])->save();
        });
    }
}
