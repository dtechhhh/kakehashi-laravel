<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LookupSeeder extends Seeder
{
    public function run(): void
    {
        $this->seed('negara', [
            ['code' => 'ID', 'label_id' => 'Indonesia', 'label_ja' => 'インドネシア', 'region' => 'Asia Tenggara', 'dial_code' => '+62'],
            ['code' => 'JP', 'label_id' => 'Jepang', 'label_ja' => '日本', 'region' => 'Asia Timur', 'dial_code' => '+81'],
            ['code' => 'VN', 'label_id' => 'Vietnam', 'label_ja' => 'ベトナム', 'region' => 'Asia Tenggara', 'dial_code' => '+84'],
            ['code' => 'PH', 'label_id' => 'Filipina', 'label_ja' => 'フィリピン', 'region' => 'Asia Tenggara', 'dial_code' => '+63'],
            ['code' => 'CN', 'label_id' => 'Tiongkok', 'label_ja' => '中国', 'region' => 'Asia Timur', 'dial_code' => '+86'],
            ['code' => 'MM', 'label_id' => 'Myanmar', 'label_ja' => 'ミャンマー', 'region' => 'Asia Tenggara', 'dial_code' => '+95'],
            ['code' => 'NP', 'label_id' => 'Nepal', 'label_ja' => 'ネパール', 'region' => 'Asia Selatan', 'dial_code' => '+977'],
        ]);

        $this->seed('bahasa', [
            ['code' => 'id', 'label_id' => 'Bahasa Indonesia', 'label_ja' => 'インドネシア語'],
            ['code' => 'ja', 'label_id' => 'Bahasa Jepang', 'label_ja' => '日本語'],
            ['code' => 'en', 'label_id' => 'Bahasa Inggris', 'label_ja' => '英語'],
        ]);

        $this->seed('provinsi', [
            ['code' => 'ACEH', 'label_id' => 'Aceh', 'label_ja' => 'アチェ州'],
            ['code' => 'SUMUT', 'label_id' => 'Sumatera Utara', 'label_ja' => '北スマトラ州'],
            ['code' => 'SUMBAR', 'label_id' => 'Sumatera Barat', 'label_ja' => '西スマトラ州'],
            ['code' => 'RIAU', 'label_id' => 'Riau', 'label_ja' => 'リアウ州'],
            ['code' => 'KEPRI', 'label_id' => 'Kepulauan Riau', 'label_ja' => 'リアウ諸島州'],
            ['code' => 'JAMBI', 'label_id' => 'Jambi', 'label_ja' => 'ジャンビ州'],
            ['code' => 'SUMSEL', 'label_id' => 'Sumatera Selatan', 'label_ja' => '南スマトラ州'],
            ['code' => 'BABEL', 'label_id' => 'Kepulauan Bangka Belitung', 'label_ja' => 'バンカ・ブリトゥン州'],
            ['code' => 'BENGKULU', 'label_id' => 'Bengkulu', 'label_ja' => 'ブンクル州'],
            ['code' => 'LAMPUNG', 'label_id' => 'Lampung', 'label_ja' => 'ランプン州'],
            ['code' => 'DKI', 'label_id' => 'DKI Jakarta', 'label_ja' => 'ジャカルタ首都特別州'],
            ['code' => 'JABAR', 'label_id' => 'Jawa Barat', 'label_ja' => '西ジャワ州'],
            ['code' => 'BANTEN', 'label_id' => 'Banten', 'label_ja' => 'バンテン州'],
            ['code' => 'JATENG', 'label_id' => 'Jawa Tengah', 'label_ja' => '中部ジャワ州'],
            ['code' => 'DIY', 'label_id' => 'DI Yogyakarta', 'label_ja' => 'ジョグジャカルタ特別州'],
            ['code' => 'JATIM', 'label_id' => 'Jawa Timur', 'label_ja' => '東ジャワ州'],
            ['code' => 'BALI', 'label_id' => 'Bali', 'label_ja' => 'バリ州'],
            ['code' => 'NTB', 'label_id' => 'Nusa Tenggara Barat', 'label_ja' => '西ヌサ・トゥンガラ州'],
            ['code' => 'NTT', 'label_id' => 'Nusa Tenggara Timur', 'label_ja' => '東ヌサ・トゥンガラ州'],
            ['code' => 'KALBAR', 'label_id' => 'Kalimantan Barat', 'label_ja' => '西カリマンタン州'],
            ['code' => 'KALTENG', 'label_id' => 'Kalimantan Tengah', 'label_ja' => '中部カリマンタン州'],
            ['code' => 'KALSEL', 'label_id' => 'Kalimantan Selatan', 'label_ja' => '南カリマンタン州'],
            ['code' => 'KALTIM', 'label_id' => 'Kalimantan Timur', 'label_ja' => '東カリマンタン州'],
            ['code' => 'KALTARA', 'label_id' => 'Kalimantan Utara', 'label_ja' => '北カリマンタン州'],
            ['code' => 'SULUT', 'label_id' => 'Sulawesi Utara', 'label_ja' => '北スラウェシ州'],
            ['code' => 'GORONTALO', 'label_id' => 'Gorontalo', 'label_ja' => 'ゴロンタロ州'],
            ['code' => 'SULTENG', 'label_id' => 'Sulawesi Tengah', 'label_ja' => '中部スラウェシ州'],
            ['code' => 'SULBAR', 'label_id' => 'Sulawesi Barat', 'label_ja' => '西スラウェシ州'],
            ['code' => 'SULSEL', 'label_id' => 'Sulawesi Selatan', 'label_ja' => '南スラウェシ州'],
            ['code' => 'SULTRA', 'label_id' => 'Sulawesi Tenggara', 'label_ja' => '南東スラウェシ州'],
            ['code' => 'MALUKU', 'label_id' => 'Maluku', 'label_ja' => 'マルク州'],
            ['code' => 'MALUT', 'label_id' => 'Maluku Utara', 'label_ja' => '北マルク州'],
            ['code' => 'PAPUA', 'label_id' => 'Papua', 'label_ja' => 'パプア州'],
            ['code' => 'PAPUA_BARAT', 'label_id' => 'Papua Barat', 'label_ja' => '西パプア州'],
            ['code' => 'PAPUA_SELATAN', 'label_id' => 'Papua Selatan', 'label_ja' => '南パプア州'],
            ['code' => 'PAPUA_TENGAH', 'label_id' => 'Papua Tengah', 'label_ja' => '中部パプア州'],
            ['code' => 'PAPUA_PEGUNUNGAN', 'label_id' => 'Papua Pegunungan', 'label_ja' => '山岳パプア州'],
            ['code' => 'PAPUA_BARAT_DAYA', 'label_id' => 'Papua Barat Daya', 'label_ja' => '南西パプア州'],
        ], [
            'negara_id' => DB::table('negara')->where('code', 'ID')->value('id'),
        ]);

        $this->seed('agama', [
            ['code' => 'ISLAM', 'label_id' => 'Islam', 'label_ja' => 'イスラム教'],
            ['code' => 'KRISTEN', 'label_id' => 'Kristen Protestan', 'label_ja' => 'キリスト教（プロテスタント）'],
            ['code' => 'KATOLIK', 'label_id' => 'Katolik', 'label_ja' => 'カトリック'],
            ['code' => 'HINDU', 'label_id' => 'Hindu', 'label_ja' => 'ヒンドゥー教'],
            ['code' => 'BUDDHA', 'label_id' => 'Buddha', 'label_ja' => '仏教'],
            ['code' => 'KONGHUCU', 'label_id' => 'Konghucu', 'label_ja' => '儒教'],
        ]);

        $this->seed('golongan_darah', [
            ['code' => 'A', 'label_id' => 'A', 'label_ja' => 'A型'],
            ['code' => 'B', 'label_id' => 'B', 'label_ja' => 'B型'],
            ['code' => 'O', 'label_id' => 'O', 'label_ja' => 'O型'],
            ['code' => 'AB', 'label_id' => 'AB', 'label_ja' => 'AB型'],
        ]);

        $this->seed('tingkat_pendidikan', [
            ['code' => 'SD', 'label_id' => 'SD', 'label_ja' => '小学校'],
            ['code' => 'SMP', 'label_id' => 'SMP', 'label_ja' => '中学校'],
            ['code' => 'SMA', 'label_id' => 'SMA/SMK', 'label_ja' => '高等学校'],
            ['code' => 'D3', 'label_id' => 'Diploma (D3)', 'label_ja' => '短期大学・専門学校'],
            ['code' => 'S1', 'label_id' => 'Sarjana (S1)', 'label_ja' => '大学（学士）'],
            ['code' => 'S2', 'label_id' => 'Magister (S2)', 'label_ja' => '大学院（修士）'],
        ]);

        $this->seed('bidang_pekerjaan', [
            ['code' => 'KAIGO', 'label_id' => 'Perawatan (Kaigo)', 'label_ja' => '介護'],
            ['code' => 'KONSTRUKSI', 'label_id' => 'Konstruksi', 'label_ja' => '建設'],
            ['code' => 'PERTANIAN', 'label_id' => 'Pertanian', 'label_ja' => '農業'],
            ['code' => 'MANUFAKTUR', 'label_id' => 'Manufaktur', 'label_ja' => '製造業'],
            ['code' => 'PERIKANAN', 'label_id' => 'Perikanan', 'label_ja' => '漁業'],
            ['code' => 'FNB', 'label_id' => 'Makanan & Minuman', 'label_ja' => '外食業'],
        ]);

        $bidangIds = DB::table('bidang_pekerjaan')->pluck('id', 'code');

        $this->seed('skill_ssw', [
            ['code' => 'SSW_KAIGO', 'label_id' => 'SSW Kaigo', 'label_ja' => '特定技能・介護', 'bidang_id' => $bidangIds['KAIGO'], 'is_shareable' => true],
            ['code' => 'SSW_KONSTRUKSI', 'label_id' => 'SSW Konstruksi', 'label_ja' => '特定技能・建設', 'bidang_id' => $bidangIds['KONSTRUKSI'], 'is_shareable' => true],
            ['code' => 'SSW_PERTANIAN', 'label_id' => 'SSW Pertanian', 'label_ja' => '特定技能・農業', 'bidang_id' => $bidangIds['PERTANIAN'], 'is_shareable' => true],
            ['code' => 'SSW_FNB', 'label_id' => 'SSW Makanan & Minuman', 'label_ja' => '特定技能・外食業', 'bidang_id' => $bidangIds['FNB'], 'is_shareable' => true],
            ['code' => 'SSW_MANUFAKTUR', 'label_id' => 'SSW Manufaktur', 'label_ja' => '特定技能・製造業', 'bidang_id' => $bidangIds['MANUFAKTUR'], 'is_shareable' => true],
        ]);

        $this->seed('kualifikasi_mengemudi', [
            ['code' => 'SIM_A', 'label_id' => 'SIM A (Mobil)', 'label_ja' => '普通自動車免許'],
            ['code' => 'SIM_B1', 'label_id' => 'SIM B1', 'label_ja' => '中型自動車免許'],
            ['code' => 'SIM_B2', 'label_id' => 'SIM B2', 'label_ja' => '大型自動車免許'],
            ['code' => 'SIM_C', 'label_id' => 'SIM C (Motor)', 'label_ja' => '普通二輪免許'],
        ]);

        $this->seed('jenis_visa', [
            ['code' => 'SSW1', 'label_id' => 'SSW Tipe 1', 'label_ja' => '特定技能1号', 'kategori' => 'SSW'],
            ['code' => 'SSW2', 'label_id' => 'SSW Tipe 2', 'label_ja' => '特定技能2号', 'kategori' => 'SSW'],
            ['code' => 'GINOU', 'label_id' => 'Magang (Ginō Jisshū)', 'label_ja' => '技能実習', 'kategori' => 'MAGANG'],
            ['code' => 'RYUGAKU', 'label_id' => 'Pelajar', 'label_ja' => '留学', 'kategori' => 'STUDI'],
            ['code' => 'GIJINKOKU', 'label_id' => "Engineer/Humaniora/Int'l", 'label_ja' => '技術・人文知識・国際業務', 'kategori' => 'KERJA'],
        ]);

        $this->seed('jenis_dokumen', [
            ['code' => 'KTP', 'label_id' => 'KTP', 'label_ja' => '身分証明書（KTP）'],
            ['code' => 'KK', 'label_id' => 'Kartu Keluarga', 'label_ja' => '家族カード（KK）'],
            ['code' => 'IJAZAH', 'label_id' => 'Ijazah', 'label_ja' => '卒業証書'],
            ['code' => 'PASPOR', 'label_id' => 'Paspor', 'label_ja' => 'パスポート'],
            ['code' => 'ZAIRYU_CARD', 'label_id' => 'Kartu Zairyu (Foto Zairyu Card)', 'label_ja' => '在留カード'],
            ['code' => 'SKCK', 'label_id' => 'SKCK', 'label_ja' => '無犯罪証明（SKCK）'],
            ['code' => 'LAINNYA', 'label_id' => 'Dokumen Lainnya', 'label_ja' => 'その他書類'],
        ]);

        $this->seed('status_keluarga', [
            ['code' => 'AYAH', 'label_id' => 'Ayah', 'label_ja' => '父'],
            ['code' => 'IBU', 'label_id' => 'Ibu', 'label_ja' => '母'],
            ['code' => 'SAUDARA', 'label_id' => 'Saudara Kandung', 'label_ja' => '兄弟姉妹'],
            ['code' => 'PASANGAN', 'label_id' => 'Suami/Istri', 'label_ja' => '配偶者'],
            ['code' => 'ANAK', 'label_id' => 'Anak', 'label_ja' => '子'],
        ]);

        $this->seed('tingkat_penglihatan', [
            ['code' => 'NORMAL', 'label_id' => 'Normal', 'label_ja' => '正常'],
            ['code' => 'MINUS_RINGAN', 'label_id' => 'Minus Ringan', 'label_ja' => '軽度近視'],
            ['code' => 'MINUS_SEDANG', 'label_id' => 'Minus Sedang', 'label_ja' => '中等度近視'],
            ['code' => 'MINUS_BERAT', 'label_id' => 'Minus Berat', 'label_ja' => '強度近視'],
        ]);

        $this->seed('kategori_force_majeur', [
            ['code' => 'SAKIT_BERAT', 'label_id' => 'Sakit Berat / Alasan Kesehatan', 'label_ja' => '重病・健康上の理由'],
            ['code' => 'MENINGGAL', 'label_id' => 'Meninggal Dunia', 'label_ja' => '死亡'],
            ['code' => 'MASALAH_KELUARGA', 'label_id' => 'Keadaan Darurat Keluarga', 'label_ja' => '家族の緊急事情'],
            ['code' => 'BENCANA_ALAM', 'label_id' => 'Bencana Alam', 'label_ja' => '自然災害'],
            ['code' => 'MASALAH_HUKUM_IMIGRASI', 'label_id' => 'Masalah Hukum / Imigrasi', 'label_ja' => '法的・在留資格上の問題'],
            ['code' => 'LAINNYA', 'label_id' => 'Lainnya (wajib free-text)', 'label_ja' => 'その他（自由記述必須）'],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $defaults
     */
    private function seed(string $table, array $rows, array $defaults = []): void
    {
        $updatedAt = now();

        foreach ($rows as $sortOrder => $row) {
            $code = $row['code'];
            unset($row['code']);

            DB::table($table)->updateOrInsert(
                ['code' => $code],
                [...$defaults, ...$row, 'sort_order' => $sortOrder, 'updated_at' => $updatedAt],
            );
        }
    }
}
