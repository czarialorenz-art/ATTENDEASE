<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-ANN-01..05: class announcements, including class cancellations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 15)->default('general'); // general / cancellation
            $table->string('title', 120);
            $table->text('body')->nullable();               // required for general; the reason for a cancellation
            $table->date('cancelled_date')->nullable();     // the class meeting that is cancelled
            $table->timestamps();
            $table->index(['class_id', 'created_at']);
            $table->index(['class_id', 'cancelled_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
