<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('linkedin_connections', function (Blueprint $table): void {
            // Where posts can go (the member and the LinkedIn Pages they administer) and which are selected.
            $table->json('pages')->nullable()->after('scopes');
            $table->json('selected_pages')->nullable()->after('pages');
        });

        Schema::table('linkedin_posts', function (Blueprint $table): void {
            // The page (author URN) the post went to.
            $table->string('author_urn')->nullable()->after('post_urn');
            $table->index(['shareable_type', 'shareable_id', 'author_urn']);
        });
    }

    public function down(): void
    {
        Schema::table('linkedin_posts', function (Blueprint $table): void {
            $table->dropIndex(['shareable_type', 'shareable_id', 'author_urn']);
            $table->dropColumn('author_urn');
        });

        Schema::table('linkedin_connections', function (Blueprint $table): void {
            $table->dropColumn(['pages', 'selected_pages']);
        });
    }
};
