<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('firstname')->nullable();
            $table->string('phone')->nullable();
            $table->string('password');
            $table->string('remember_token')->nullable();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->decimal('total', 10, 2)->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('country_id')->nullable()->constrained('countries');
        });

        Schema::create('password_resets', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
        });

        Schema::create('telescope_entries', function (Blueprint $table): void {
            $table->id();
            $table->text('content');
        });

        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name');
        });

        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('project_id')->constrained('projects');
            $table->string('title');
        });

        Schema::create('task_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks');
            $table->string('body');
        });

        DB::statement('CREATE VIEW order_codes AS SELECT id, code, total FROM orders');
    }
};
