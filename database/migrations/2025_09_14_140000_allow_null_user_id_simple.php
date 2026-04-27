<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                DO \$\$
                BEGIN
                    IF EXISTS (
                        SELECT 1 FROM information_schema.table_constraints
                        WHERE constraint_name LIKE '%links_user_id_foreign%'
                        AND table_name = 'links'
                    ) THEN
                        ALTER TABLE links DROP CONSTRAINT links_user_id_foreign;
                    END IF;
                END \$\$;
            ");

            DB::statement('ALTER TABLE links ALTER COLUMN user_id DROP NOT NULL');

            DB::statement('
                ALTER TABLE links
                ADD CONSTRAINT links_user_id_foreign
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ');
        } else {
            Schema::table('links', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                DO \$\$
                BEGIN
                    IF EXISTS (
                        SELECT 1 FROM information_schema.table_constraints
                        WHERE constraint_name = 'links_user_id_foreign'
                        AND table_name = 'links'
                    ) THEN
                        ALTER TABLE links DROP CONSTRAINT links_user_id_foreign;
                    END IF;
                END \$\$;
            ");

            DB::statement('ALTER TABLE links ALTER COLUMN user_id SET NOT NULL');

            DB::statement('
                ALTER TABLE links
                ADD CONSTRAINT links_user_id_foreign
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ');
        } else {
            Schema::table('links', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable(false)->change();
            });
        }
    }
};
