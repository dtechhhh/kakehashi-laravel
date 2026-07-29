<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->string('nomor_induk', 13)->nullable();
            $table->text('nama_alphabet');
            $table->text('nama_katakana')->nullable();
            $table->date('tanggal_lahir');
            $table->foreignId('tempat_lahir_kota_id')->nullable()->constrained('kota_kabupaten')->restrictOnUpdate()->restrictOnDelete();
            $table->text('alamat_detail')->nullable();
            $table->text('email')->nullable();
            $table->text('phone')->nullable();
            $table->text('line_id')->nullable();
            $table->foreignId('kewarganegaraan_id')->constrained('negara')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('asal_rekrutmen_id')->nullable()->constrained('asal_rekrutmen')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('agama_id')->nullable()->constrained('agama')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('alamat_provinsi_id')->nullable()->constrained('provinsi')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('alamat_kota_kabupaten_id')->nullable()->constrained('kota_kabupaten')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('alamat_kecamatan_id')->nullable()->constrained('kecamatan')->restrictOnUpdate()->restrictOnDelete();
            $table->text('jenis_kelamin');
            $table->text('status_pernikahan')->nullable();
            $table->text('status_ketersediaan')->default('TERSEDIA');
            $table->text('status_approval')->default('Draft');
            $table->unsignedBigInteger('parent_candidate_id')->nullable();
            $table->integer('version')->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->text('catatan_penolakan_terakhir')->nullable();
            $table->text('catatan_tambahan')->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampTz('pii_anonymized_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique('nomor_induk', 'candidate_nomor_induk_unique');
        });

        Schema::create('candidate_physical', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('candidate_id')->unique()->constrained('candidate')->restrictOnUpdate()->cascadeOnDelete();
            $table->decimal('tinggi_cm', 5, 2)->nullable();
            $table->decimal('berat_kg', 5, 2)->nullable();
            $table->decimal('lingkar_perut_cm', 5, 2)->nullable();
            $table->foreignId('golongan_darah_id')->nullable()->constrained('golongan_darah')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('ukuran_sepatu_id')->nullable()->constrained('ukuran_sepatu')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('penglihatan_kiri_id')->nullable()->constrained('tingkat_penglihatan')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('penglihatan_kanan_id')->nullable()->constrained('tingkat_penglihatan')->restrictOnUpdate()->restrictOnDelete();
            $table->text('dominan_tangan')->nullable();
            $table->text('buta_warna')->nullable();
            $table->text('merokok')->nullable();
            $table->text('minum_sake')->nullable();
            $table->text('pembatasan_makanan')->nullable();
            $table->text('riwayat_penyakit')->nullable();
            $table->text('riwayat_operasi')->nullable();
            $table->text('catatan_kesehatan')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('candidate_education', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('candidate_id')->constrained('candidate')->restrictOnUpdate()->cascadeOnDelete();
            $table->foreignId('tingkat_pendidikan_id')->constrained('tingkat_pendidikan')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('jurusan_id')->nullable()->constrained('jurusan')->restrictOnUpdate()->restrictOnDelete();
            $table->text('nama_institusi')->nullable();
            $table->date('tanggal_masuk')->nullable();
            $table->date('tanggal_keluar')->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('candidate_work', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('candidate_id')->constrained('candidate')->restrictOnUpdate()->cascadeOnDelete();
            $table->text('nama_perusahaan')->nullable();
            $table->text('perusahaan_penanggung')->nullable();
            $table->foreignId('bidang_pekerjaan_id')->nullable()->constrained('bidang_pekerjaan')->restrictOnUpdate()->restrictOnDelete();
            $table->date('tanggal_masuk')->nullable();
            $table->date('tanggal_keluar')->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('candidate_qual_english', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('candidate_id')->constrained('candidate')->restrictOnUpdate()->cascadeOnDelete();
            $table->foreignId('jenis_id')->constrained('jenis_kualifikasi_bahasa_inggris')->restrictOnUpdate()->restrictOnDelete();
            $table->date('tanggal_akuisisi')->nullable();
            $table->text('skor')->nullable();
            $table->text('url_file')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('candidate_qual_japanese', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('candidate_id')->constrained('candidate')->restrictOnUpdate()->cascadeOnDelete();
            $table->foreignId('jenis_id')->constrained('jenis_kualifikasi_bahasa_jepang')->restrictOnUpdate()->restrictOnDelete();
            $table->date('tanggal_akuisisi')->nullable();
            $table->text('skor')->nullable();
            $table->text('url_file')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('candidate_qual_ssw', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('candidate_id')->constrained('candidate')->restrictOnUpdate()->cascadeOnDelete();
            $table->foreignId('skill_ssw_id')->constrained('skill_ssw')->restrictOnUpdate()->restrictOnDelete();
            $table->date('tanggal_akuisisi')->nullable();
            $table->text('url_file')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('candidate_qual_driving', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('candidate_id')->constrained('candidate')->restrictOnUpdate()->cascadeOnDelete();
            $table->foreignId('kualifikasi_mengemudi_id')->constrained('kualifikasi_mengemudi')->restrictOnUpdate()->restrictOnDelete();
            $table->date('tanggal_akuisisi')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('candidate_qual_other', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('candidate_id')->constrained('candidate')->restrictOnUpdate()->cascadeOnDelete();
            $table->foreignId('kualifikasi_keahlian_lainnya_id')->constrained('kualifikasi_keahlian_lainnya')->restrictOnUpdate()->restrictOnDelete();
            $table->date('tanggal_akuisisi')->nullable();
            $table->text('url_file')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('candidate_self_promo', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('candidate_id')->unique()->constrained('candidate')->restrictOnUpdate()->cascadeOnDelete();
            $table->smallInteger('skor_iq')->nullable();
            $table->smallInteger('skor_matematika')->nullable();
            $table->foreignId('bidang_diminati_id')->nullable()->constrained('bidang_diminati')->restrictOnUpdate()->restrictOnDelete();
            $table->text('video_jikoshokai_url')->nullable();
            $table->text('video_keahlian_url')->nullable();
            $table->text('final_laporan_psikotes')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('candidate_family', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('candidate_id')->constrained('candidate')->restrictOnUpdate()->cascadeOnDelete();
            $table->foreignId('status_keluarga_id')->constrained('status_keluarga')->restrictOnUpdate()->restrictOnDelete();
            $table->text('nama')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('candidate_family_contact', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('candidate_id')->unique()->constrained('candidate')->restrictOnUpdate()->cascadeOnDelete();
            $table->foreignId('status_keluarga_id')->nullable()->constrained('status_keluarga')->restrictOnUpdate()->restrictOnDelete();
            $table->text('nama')->nullable();
            $table->text('phone')->nullable();
            $table->text('alamat')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('candidate_immigration', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('candidate_id')->unique()->constrained('candidate')->restrictOnUpdate()->cascadeOnDelete();
            $table->text('nomor_paspor')->nullable();
            $table->date('masa_berlaku_paspor')->nullable();
            $table->text('nomor_zairyu')->nullable();
            $table->text('alamat_zairyu')->nullable();
            $table->foreignId('jenis_visa_id')->nullable()->constrained('jenis_visa')->restrictOnUpdate()->restrictOnDelete();
            $table->text('pernah_ke_jepang')->nullable();
            $table->text('catatan')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('candidate_document', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('candidate_id')->constrained('candidate')->restrictOnUpdate()->cascadeOnDelete();
            $table->foreignId('jenis_dokumen_id')->constrained('jenis_dokumen')->restrictOnUpdate()->restrictOnDelete();
            $table->text('url_dokumen');
            $table->text('nama_file')->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->smallInteger('sort_order')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('candidate_id', 'idx_candidate_document_candidate');
        });

        Schema::create('candidate_photo', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->foreignId('candidate_id')->unique()->constrained('candidate')->restrictOnUpdate()->cascadeOnDelete();
            $table->text('object_key');
            $table->text('mime_type');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('nik_counter', function (Blueprint $table) {
            $table->smallInteger('year')->primary();
            $table->integer('last_value')->default(0);
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nik_counter');
        Schema::dropIfExists('candidate_photo');
        Schema::dropIfExists('candidate_document');
        Schema::dropIfExists('candidate_immigration');
        Schema::dropIfExists('candidate_family_contact');
        Schema::dropIfExists('candidate_family');
        Schema::dropIfExists('candidate_self_promo');
        Schema::dropIfExists('candidate_qual_other');
        Schema::dropIfExists('candidate_qual_driving');
        Schema::dropIfExists('candidate_qual_ssw');
        Schema::dropIfExists('candidate_qual_japanese');
        Schema::dropIfExists('candidate_qual_english');
        Schema::dropIfExists('candidate_work');
        Schema::dropIfExists('candidate_education');
        Schema::dropIfExists('candidate_physical');
        Schema::dropIfExists('candidate');
    }
};
