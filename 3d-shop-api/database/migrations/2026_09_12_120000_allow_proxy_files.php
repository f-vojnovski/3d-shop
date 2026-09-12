<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const KINDS = [
        'deliverable', 'preview_image', 'thumbnail', 'seller_image', 'wireframe', 'derived',
        'proxy',
    ];

    public function up(): void
    {
        $this->replaceKindCheck(self::KINDS);
    }

    public function down(): void
    {
        DB::table('product_files')->where('kind', 'proxy')->delete();

        $this->replaceKindCheck(array_slice(self::KINDS, 0, 6));
    }

    /** Laravel's enum() is a varchar plus a check constraint, so the list is here. */
    private function replaceKindCheck(array $kinds): void
    {
        $allowed = implode(', ', array_map(fn (string $kind) => "'{$kind}'", $kinds));

        DB::statement('alter table product_files drop constraint product_files_kind_check');
        DB::statement(
            "alter table product_files add constraint product_files_kind_check
             check (kind::text = any (array[{$allowed}]::text[]))"
        );
    }
};
