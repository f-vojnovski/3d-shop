<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_files', function (Blueprint $table) {
            $table->foreignId('source_file_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_files')
                ->cascadeOnDelete();

            $table->index(['source_file_id', 'kind']);
        });

        $this->moveAnglesOntoDeliverables();
        $this->attachStillsToTheirSource();

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('preview_angles');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('preview_angles')->nullable();
        });

        Schema::table('product_files', function (Blueprint $table) {
            $table->dropForeign(['source_file_id']);
            $table->dropIndex(['source_file_id', 'kind']);
            $table->dropColumn('source_file_id');
        });
    }

    private function moveAnglesOntoDeliverables(): void
    {
        // The product's single status described its single render, so it
        // becomes each deliverable's own status.
        $products = DB::table('products')
            ->whereNotNull('preview_angles')
            ->get(['id', 'preview_angles', 'preview_status', 'preview_error']);

        foreach ($products as $product) {
            $productId = $product->id;
            $angles = $product->preview_angles;
            $deliverables = DB::table('product_files')
                ->where('product_id', $productId)
                ->where('kind', 'deliverable')
                ->get(['id', 'meta']);

            foreach ($deliverables as $deliverable) {
                $meta = json_decode($deliverable->meta ?? '{}', true) ?: [];
                $meta['angles'] = json_decode($angles, true) ?: [];
                $meta['render'] = [
                    'status' => $product->preview_status ?? 'none',
                    'error' => $product->preview_error,
                ];

                DB::table('product_files')
                    ->where('id', $deliverable->id)
                    ->update(['meta' => json_encode($meta)]);
            }
        }
    }

    private function attachStillsToTheirSource(): void
    {
        $stills = DB::table('product_files')
            ->where('kind', 'preview_image')
            ->get(['id', 'product_id', 'meta']);

        foreach ($stills as $still) {
            $meta = json_decode($still->meta ?? '{}', true) ?: [];

            $source = DB::table('product_files')
                ->where('product_id', $still->product_id)
                ->where('kind', 'deliverable')
                ->when(
                    isset($meta['source_checksum']),
                    fn ($query) => $query->orderByRaw(
                        'case when checksum = ? then 0 else 1 end',
                        [$meta['source_checksum']]
                    )
                )
                ->orderBy('id')
                ->value('id');

            if ($source !== null) {
                DB::table('product_files')
                    ->where('id', $still->id)
                    ->update(['source_file_id' => $source]);
            }
        }
    }
};
