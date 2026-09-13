<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per container start.
 *
 * The render tier is the expensive half of this project and the only record of
 * it was a log line. A log answers "what happened to this job"; it does not
 * answer "what does a render cost", "is the queue backed up or is the renderer
 * slow", or "why does identical work take three times as long sometimes".
 *
 * Those are the questions that decide what to buy and what to fix, so they are
 * kept as rows rather than left to be grepped for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('render_runs', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('scratch');

            // Nullable: the broker records every run, and not every kind of
            // work belongs to a file or to a person.
            $table->foreignId('product_file_id')->nullable()->nullOnDelete()->constrained();
            $table->foreignId('user_id')->nullable()->nullOnDelete()->constrained();
            $table->unsignedBigInteger('triangles')->nullable();
            $table->unsignedBigInteger('source_bytes')->nullable();

            $table->timestamp('asked_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Split on purpose: "renders got slower" and "the queue backed up"
            // have opposite fixes, and one number cannot tell them apart.
            $table->float('queued_seconds')->nullable();
            $table->float('run_seconds')->nullable();

            // What clouds bill in, so the invoice becomes a query.
            $table->float('vcpu_seconds')->nullable();
            $table->string('cpu_quota')->nullable();
            $table->string('memory')->nullable();

            $table->string('status');
            $table->integer('exit_code')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            // The two questions asked of this table: what did a day cost, and
            // what is slow about one kind of work.
            $table->index(['created_at', 'kind']);
            $table->index(['kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('render_runs');
    }
};
