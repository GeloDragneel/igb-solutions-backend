<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings → client profile updates
 *   t_client_document   scanned documents of a client:
 *       ownership  dti | sec | cda          (registration certificate → date registered)
 *       bir        bir_2303                 (date of registration / operation, tax types)
 *       withholding wh_notice               (notice / publication as withholding agent)
 *       permit     denr | boc | boi | other  (permits & registrations)
 *       id         valid_id                 (file of a Valid ID row: BIR, SSS, DENR, BOC, BOI …)
 *   m_client  + date_of_operation, is_withholding_agent, msme_class
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('t_client_document')) {
            Schema::create('t_client_document', function (Blueprint $t) {
                $t->id();
                $t->string('client_num', 30)->index();
                $t->string('doc_group', 20);                // ownership | bir | withholding | permit | id
                $t->unsignedBigInteger('valid_id_id')->nullable()->index();   // id: the Valid ID row the file belongs to
                $t->string('doc_type', 20);                 // dti | sec | cda | bir_2303 | wh_notice | denr | boc | boi | other
                $t->string('label', 150)->nullable();       // "Others" → what permit it is
                $t->string('doc_no', 100)->nullable();      // registration / certificate / permit no.
                $t->string('name_on_doc', 255)->nullable(); // business / registered name read from the document
                $t->date('date_registered')->nullable();
                $t->date('issued_at')->nullable();
                $t->date('expires_at')->nullable();
                $t->json('extracted')->nullable();          // what the scan read (TIN, RDO, tax types …)
                $t->string('file_path', 255);
                $t->string('file_name', 255);
                $t->string('mime', 100)->nullable();
                $t->unsignedInteger('size')->default(0);
                $t->unsignedBigInteger('uploaded_by')->nullable();
                $t->timestamps();
                $t->index(['client_num', 'doc_group']);
            });
        }

        if (!Schema::hasColumn('t_client_document', 'valid_id_id')) {
            Schema::table('t_client_document', fn (Blueprint $t) => $t->unsignedBigInteger('valid_id_id')->nullable()->index()->after('doc_group'));
        }

        Schema::table('m_client', function (Blueprint $t) {
            if (!Schema::hasColumn('m_client', 'date_of_operation')) $t->date('date_of_operation')->nullable();
            if (!Schema::hasColumn('m_client', 'is_withholding_agent')) $t->boolean('is_withholding_agent')->default(false);
            if (!Schema::hasColumn('m_client', 'msme_class')) $t->string('msme_class', 10)->nullable();   // nano | micro | small | medium | large
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_client_document');
        Schema::table('m_client', function (Blueprint $t) {
            foreach (['date_of_operation', 'is_withholding_agent', 'msme_class'] as $c) {
                if (Schema::hasColumn('m_client', $c)) $t->dropColumn($c);
            }
        });
    }
};
