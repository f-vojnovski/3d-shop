<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaced files are kept, not deleted: their previews claimed to come from
 * them, and the attestation records have to stay resolvable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_files', function (Blueprint $table) {
            $table->timestamp('superseded_at')->nullable()->after('meta');
            $table->foreignId('superseded_by_id')->nullable()->after('superseded_at')
                ->constrained('product_files')->nullOnDelete();
            $table->string('replacement_note', 200)->nullable()->after('superseded_by_id');

            // Every "what is on sale now" lookup filters on this.
            $table->index(['product_id', 'kind', 'superseded_at'], 'product_files_current_index');
        });
    }

    public function down(): void
    {
        Schema::table('product_files', function (Blueprint $table) {
            $table->dropIndex('product_files_current_index');
            $table->dropConstrainedForeignId('superseded_by_id');
            $table->dropColumn(['superseded_at', 'replacement_note']);
        });
    }
};
