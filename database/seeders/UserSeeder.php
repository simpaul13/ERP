<?php

namespace Database\Seeders;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $user = User::query()->firstOrNew(['email' => 'e2e@example.test']);
        $user->forceFill([
            'name' => 'E2E Test User',
            'password' => 'password',
            'email_verified_at' => now(),
        ])->save();

        $team = Team::query()->firstOrCreate(
            ['slug' => 'e2e-test-user'],
            [
                'name' => 'E2E Test User Team',
                'is_personal' => true,
            ],
        );

        $team->members()->syncWithoutDetaching([
            $user->getKey() => ['role' => TeamRole::Owner->value],
        ]);

        $user->switchTeam($team);
    }
}
