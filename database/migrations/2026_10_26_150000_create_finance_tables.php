<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['team_id', 'type', 'name']);
        });

        Schema::create('finance_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finance_category_id')->constrained('finance_categories')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('transaction_date');
            $table->string('description');
            $table->string('type');
            $table->string('payment_method');
            $table->timestamps();
            $table->index(['team_id', 'transaction_date']);
        });

        $categories = [
            'income' => ['Sales', 'Other Income'],
            'expense' => ['Supplies', 'Rent', 'Transport', 'Utilities', 'Other Expense'],
        ];

        foreach (DB::table('teams')->pluck('id') as $teamId) {
            foreach ($categories as $type => $names) {
                foreach ($names as $name) {
                    DB::table('finance_categories')->insert([
                        'team_id' => $teamId,
                        'name' => $name,
                        'type' => $type,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_transactions');
        Schema::dropIfExists('finance_categories');
    }
};
