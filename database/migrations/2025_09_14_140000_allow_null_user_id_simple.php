<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Versão simplificada sem Doctrine DBAL
        // Usar SQL direto para PostgreSQL

        // 1. Remover constraint de foreign key se existir
        DB::statement('
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.table_constraints
                    WHERE constraint_name LIKE \'%links_user_id_foreign%\'
                    AND table_name = \'links\'
                ) THEN
                    ALTER TABLE links DROP CONSTRAINT links_user_id_foreign;
                END IF;
            END $$;
        ');

        // 2. Alterar coluna para permitir NULL
        DB::statement('ALTER TABLE links ALTER COLUMN user_id DROP NOT NULL');

        // 3. Recriar foreign key constraint
        DB::statement('
            ALTER TABLE links
            ADD CONSTRAINT links_user_id_foreign
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remover constraint
        DB::statement('
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.table_constraints
                    WHERE constraint_name = \'links_user_id_foreign\'
                    AND table_name = \'links\'
                ) THEN
                    ALTER TABLE links DROP CONSTRAINT links_user_id_foreign;
                END IF;
            END $$;
        ');

        // Alterar coluna para NOT NULL (cuidado: pode falhar se houver dados NULL)
        DB::statement('ALTER TABLE links ALTER COLUMN user_id SET NOT NULL');

        // Recriar foreign key
        DB::statement('
            ALTER TABLE links
            ADD CONSTRAINT links_user_id_foreign
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ');
    }
};
