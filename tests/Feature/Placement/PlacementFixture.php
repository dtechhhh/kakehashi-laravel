<?php

namespace Tests\Feature\Placement;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Rbac;
use Modules\Candidates\Enums\CandidateAvailability;

trait PlacementFixture
{
    protected User $maker;

    protected User $checker;

    protected int $companyId;

    protected int $visaId;

    protected int $countryId;

    protected int $interviewContainerId;

    private int $candidateSequence = 0;

    private int $placementContainerSequence = 0;

    protected function setupPlacementUsers(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->maker = User::factory()->active()->create();
        $this->maker->assignRole(Rbac::ASSISTANT_MANAGER);
        $this->checker = User::factory()->active()->create();
        $this->checker->assignRole(Rbac::JOB_MANAGER);

        $this->actingAs($this->maker);
    }

    protected function seedPlacementReferences(): void
    {
        $this->companyId = (int) DB::table('perusahaan')->insertGetId([
            'nama_ja' => 'W5 配属会社',
            'nama_romaji' => 'W5 Haizoku Kaisha',
            'nama_id' => 'Perusahaan W5',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->visaId = (int) DB::table('jenis_visa')->insertGetId([
            'code' => 'W5_SSW',
            'label_id' => 'Visa W5',
            'label_ja' => 'W5ビザ',
            'kategori' => 'SSW',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->countryId = (int) DB::table('negara')->insertGetId([
            'code' => 'ID',
            'label_id' => 'Indonesia',
            'label_ja' => 'インドネシア',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->interviewContainerId = (int) DB::table('interview_container')->insertGetId([
            'judul' => 'W5 Interview Container',
            'perusahaan_id' => $this->companyId,
            'posisi_pekerjaan_id' => $this->positionId(),
            'jenis_wawancara' => 'ONLINE',
            'jenis_visa_id' => $this->visaId,
            'tanggal_wawancara' => '2026-09-01',
            'jumlah_peserta' => 0,
            'target_peserta_diterima' => 10,
            'deskripsi' => 'Synthetic fixture',
            'syarat' => 'N3',
            'status' => 'Aktif',
            'dibuat_oleh' => $this->maker->id,
            'disetujui_oleh' => $this->checker->id,
            'version' => 0,
            'created_at' => now(),
            'approved_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function activePlacementContainer(string $nama = 'W5 Kontainer Aktif'): object
    {
        $this->placementContainerSequence++;

        $id = DB::table('placement_container')->insertGetId([
            'kode_kontainer' => sprintf('P-2026-%05d', $this->placementContainerSequence),
            'nama' => $nama,
            'perusahaan_id' => $this->companyId,
            'status' => 'Aktif',
            'dibuat_oleh' => $this->maker->id,
            'disetujui_oleh' => $this->checker->id,
            'version' => 0,
            'created_at' => now(),
            'approved_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('placement_container')->where('id', $id)->first();
    }

    /**
     * Candidate `Sedang Dipakai` + Disetujui with a `Siap Dikirim` source
     * participation row in the active interview container.
     *
     * @param  array<string, mixed>  $candidateOverrides
     * @param  array<string, mixed>  $participationOverrides
     * @return array{candidate_id: int, participation_id: int}
     */
    protected function readyCandidate(
        array $candidateOverrides = [],
        array $participationOverrides = [],
    ): array {
        $this->candidateSequence++;
        $candidateId = (int) DB::table('candidate')->insertGetId(array_merge([
            'nomor_induk' => sprintf('K-2026-%05d', $this->candidateSequence),
            'nama_alphabet' => 'W5 Placement Candidate '.$this->candidateSequence,
            'tanggal_lahir' => '2000-01-01',
            'kewarganegaraan_id' => $this->countryId,
            'jenis_kelamin' => 'M',
            'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
            'status_approval' => 'Disetujui',
            'parent_candidate_id' => null,
            'version' => 0,
            'created_by' => $this->maker->id,
            'approved_by' => $this->checker->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $candidateOverrides));

        $participationId = (int) DB::table('participation')->insertGetId(array_merge([
            'interview_container_id' => $this->interviewContainerId,
            'candidate_id' => $candidateId,
            'status_wawancara' => 'Siap Dikirim',
            'frozen_at' => null,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $participationOverrides));

        return [
            'candidate_id' => $candidateId,
            'participation_id' => $participationId,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function batchRow(array $overrides = []): array
    {
        return array_merge([
            'candidate_id' => 1,
            'source_participation_id' => 1,
            'jenis_visa_id' => $this->visaId,
            'tanggal_mulai_kerja' => '2026-09-01',
            'durasi_kontrak_bulan' => 12,
            'tanggal_berakhir_kontrak' => null,
        ], $overrides);
    }

    private function positionId(): int
    {
        return (int) DB::table('posisi_pekerjaan')->insertGetId([
            'code' => 'W5_ENGINEER',
            'label_id' => 'Teknisi W5',
            'label_ja' => 'W5技術者',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
