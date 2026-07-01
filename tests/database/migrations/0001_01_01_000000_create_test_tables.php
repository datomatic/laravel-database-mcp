<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
    }
};
