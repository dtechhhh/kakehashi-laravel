<?php

namespace Tests\Feature\Candidates;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Rbac;
use Modules\Candidates\Enums\CandidateApprovalStatus;
use Modules\Candidates\Enums\CandidateAvailability;
use Modules\Candidates\Services\CandidateDraftService;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class CandidateDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_input_can_create_draft_without_nik_or_pending(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();

        $row = app(CandidateDraftService::class)->createDraft($staff, [
            'nama_alphabet' => 'Budi Santoso',
            'tanggal_lahir' => '1998-05-10',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'M',
            'nomor_induk' => 'K-2026-99999',
            'status_approval' => 'Disetujui',
            'status_ketersediaan' => 'SEDANG_DIPAKAI',
            'approved_by' => $staff->getKey(),
        ]);

        $this->assertSame(CandidateApprovalStatus::Draft->value, $row->status_approval);
        $this->assertNull($row->nomor_induk);
        $this->assertSame(CandidateAvailability::Tersedia->value, $row->status_ketersediaan);
        $this->assertSame(0, (int) $row->version);
        $this->assertSame($staff->getKey(), (int) $row->created_by);
        $this->assertNull($row->approved_by);
        $this->assertNull($row->parent_candidate_id);
        $this->assertSame('Budi Santoso', $row->nama_alphabet);

        $this->assertDatabaseHas('candidate', [
            'id' => $row->id,
            'status_approval' => 'Draft',
            'nomor_induk' => null,
            'status_ketersediaan' => 'TERSEDIA',
            'version' => 0,
        ]);

        $this->assertSame(0, DB::table('pending_request')->where('target_id', $row->id)->count());
        $this->assertSame(0, DB::table('nik_counter')->count());

        $service = app(CandidateDraftService::class);
        $this->assertFalse($service->isOperational($row));
        $this->assertFalse($service->hasActivePending((int) $row->id));

        $audit = AuditLog::query()->where('action_type', ActionType::CANDIDATE_CREATED)->sole();
        $this->assertSame($staff->getKey(), $audit->actor_id);
        $this->assertSame('candidate', $audit->entity_type);
        $this->assertSame((int) $row->id, (int) $audit->entity_id);
        $this->assertEquals([
            'status_approval' => 'Draft',
            'has_nomor_induk' => false,
            'version' => 0,
        ], $audit->detail);
    }

    public function test_draft_can_store_optional_children_and_update_with_version(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $educationLevel = $this->seedLookup('tingkat_pendidikan', 'SMA');
        $docType = $this->seedLookup('jenis_dokumen', 'KTP');

        $service = app(CandidateDraftService::class);
        $created = $service->createDraft($staff, [
            'nama_alphabet' => 'Siti Aminah',
            'tanggal_lahir' => '1999-01-01',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'F',
            'education' => [
                ['tingkat_pendidikan_id' => $educationLevel, 'nama_institusi' => 'SMA Negeri 1'],
            ],
            'physical' => [
                'tinggi_cm' => 160.5,
                'dominan_tangan' => 'RIGHT',
                'buta_warna' => 'NO',
            ],
            'documents' => [
                [
                    'jenis_dokumen_id' => $docType,
                    'url_dokumen' => 'https://drive.google.com/file/d/abc/view',
                ],
            ],
        ]);

        $this->assertDatabaseHas('candidate_education', [
            'candidate_id' => $created->id,
            'nama_institusi' => 'SMA Negeri 1',
        ]);
        $this->assertDatabaseHas('candidate_physical', [
            'candidate_id' => $created->id,
            'dominan_tangan' => 'RIGHT',
            'buta_warna' => 'NO',
        ]);
        $this->assertDatabaseHas('candidate_document', [
            'candidate_id' => $created->id,
            'uploaded_by' => $staff->getKey(),
        ]);

        $updated = $service->updateDraft($staff, (int) $created->id, [
            'version' => 0,
            'nama_alphabet' => 'Siti Aminah Updated',
            'phone' => '08123456789',
            'education' => [
                ['tingkat_pendidikan_id' => $educationLevel, 'nama_institusi' => 'SMA Negeri 2'],
            ],
        ]);

        $this->assertSame('Siti Aminah Updated', $updated->nama_alphabet);
        $this->assertSame('08123456789', $updated->phone);
        $this->assertSame(1, (int) $updated->version);
        $this->assertNull($updated->nomor_induk);
        $this->assertSame('Draft', $updated->status_approval);
        $this->assertFalse($service->isOperational($updated));
        $this->assertSame(0, DB::table('pending_request')->count());
        $this->assertSame(0, DB::table('nik_counter')->count());
        $this->assertNull(DB::table('candidate')->where('id', $created->id)->value('nomor_induk'));

        $this->assertDatabaseHas('candidate_education', [
            'candidate_id' => $created->id,
            'nama_institusi' => 'SMA Negeri 2',
        ]);
        $this->assertSame(1, DB::table('candidate_education')->where('candidate_id', $created->id)->count());

        $audit = AuditLog::query()->where('action_type', ActionType::CANDIDATE_UPDATED)->sole();
        $this->assertSame((int) $created->id, (int) $audit->entity_id);
        $this->assertEquals([0, 1], $audit->detail['version']);
        $this->assertContains('nama_alphabet', $audit->detail['fields']);
    }

    public function test_stale_version_update_returns_conflict_without_partial_write(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $service = app(CandidateDraftService::class);

        $created = $service->createDraft($staff, $this->basePayload($country, 'Version Race'));
        $service->updateDraft($staff, (int) $created->id, [
            'version' => 0,
            'nama_alphabet' => 'First Writer',
        ]);

        try {
            $service->updateDraft($staff, (int) $created->id, [
                'version' => 0,
                'nama_alphabet' => 'Stale Writer',
            ]);
            $this->fail('Expected CONFLICT on stale version.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('CONFLICT', $exception->getMessage());
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $created->id,
            'nama_alphabet' => 'First Writer',
            'version' => 1,
            'nomor_induk' => null,
            'status_approval' => 'Draft',
        ]);
    }

    public function test_create_requires_staff_input_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $country = $this->seedCountry();
        $service = app(CandidateDraftService::class);

        $approver = User::factory()->active()->create();
        $approver->assignRole(Rbac::CANDIDATE_APPROVER);
        $this->actingAs($approver);

        $this->expectException(AuthorizationException::class);
        $service->createDraft($approver, $this->basePayload($country, 'Approver Cannot Create'));
    }

    public function test_unauthenticated_actor_mismatch_is_denied(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);
        $country = $this->seedCountry();

        $this->expectException(AuthorizationException::class);
        app(CandidateDraftService::class)->createDraft($staff, $this->basePayload($country, 'No Auth'));
    }

    public function test_missing_required_fields_fail_validation(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);

        $this->assertValidationCode(
            fn () => app(CandidateDraftService::class)->createDraft($staff, [
                'nama_alphabet' => '',
                'tanggal_lahir' => '2000-01-01',
                'kewarganegaraan_id' => $this->seedCountry(),
                'jenis_kelamin' => 'M',
            ]),
            'nama_alphabet',
        );
    }

    public function test_non_draft_status_cannot_be_updated_via_draft_service(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $service = app(CandidateDraftService::class);

        $created = $service->createDraft($staff, $this->basePayload($country, 'Locked Status'));
        DB::table('candidate')->where('id', $created->id)->update([
            'status_approval' => CandidateApprovalStatus::MenungguTinjauanBaru->value,
            'nomor_induk' => 'K-2026-00001',
        ]);

        $this->assertValidationCode(
            fn () => $service->updateDraft($staff, (int) $created->id, [
                'version' => 0,
                'nama_alphabet' => 'Should Fail',
            ]),
            'CANDIDATE_NOT_DRAFT_EDITABLE',
        );

        $this->assertDatabaseHas('candidate', [
            'id' => $created->id,
            'nama_alphabet' => 'Locked Status',
            'status_approval' => 'Menunggu Tinjauan-BARU',
        ]);
    }

    public function test_ditolak_candidate_remains_editable_and_non_operational(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $service = app(CandidateDraftService::class);

        $created = $service->createDraft($staff, $this->basePayload($country, 'Rejected Fix'));
        DB::table('candidate')->where('id', $created->id)->update([
            'status_approval' => CandidateApprovalStatus::Ditolak->value,
            'catatan_penolakan_terakhir' => 'Perbaiki alamat',
        ]);

        $updated = $service->updateDraft($staff, (int) $created->id, [
            'version' => 0,
            'alamat_detail' => 'Jl. Baru 1',
        ]);

        $this->assertSame('Jl. Baru 1', $updated->alamat_detail);
        $this->assertSame('Ditolak', $updated->status_approval);
        $this->assertNull($updated->nomor_induk);
        $this->assertFalse($service->isOperational($updated));
    }

    public function test_child_collection_limit_is_enforced(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $level = $this->seedLookup('tingkat_pendidikan', 'SMA');

        $education = [];
        for ($i = 0; $i < 6; $i++) {
            $education[] = [
                'tingkat_pendidikan_id' => $level,
                'nama_institusi' => "School {$i}",
            ];
        }

        $this->assertValidationCode(
            fn () => app(CandidateDraftService::class)->createDraft($staff, [
                ...$this->basePayload($country, 'Too Many Schools'),
                'education' => $education,
            ]),
            'CANDIDATE_CHILD_LIMIT',
        );
    }

    public function test_create_and_update_never_create_nik_pending_or_counter(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $service = app(CandidateDraftService::class);

        $created = $service->createDraft($staff, $this->basePayload($country, 'No Side Effects'));
        $updated = $service->updateDraft($staff, (int) $created->id, [
            'version' => 0,
            'nama_alphabet' => 'No Side Effects Updated',
            'nomor_induk' => 'K-2026-00099',
            'status_approval' => 'Menunggu Tinjauan-BARU',
        ]);

        $this->assertNull($updated->nomor_induk);
        $this->assertSame('Draft', $updated->status_approval);
        $this->assertFalse($service->isOperational($updated));
        $this->assertFalse($service->hasActivePending((int) $updated->id));
        $this->assertSame(0, DB::table('pending_request')->count());
        $this->assertSame(0, DB::table('nik_counter')->count());
    }

    public function test_document_and_video_urls_reject_invalid_schemes_and_hosts(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $docType = $this->seedLookup('jenis_dokumen', 'KTP');
        $service = app(CandidateDraftService::class);
        $base = $this->basePayload($country, 'URL Guard');

        foreach ([
            'not-a-url',
            'http://drive.google.com/file/d/x/view',
            'javascript:alert(1)',
            'https://evil.example/file',
            'https://dropbox.com/s/abc',
            'https://drive.usercontent.google.com/example',
        ] as $badUrl) {
            $this->assertValidationCode(
                fn () => $service->createDraft($staff, [
                    ...$base,
                    'nama_alphabet' => 'URL Guard '.$badUrl,
                    'documents' => [[
                        'jenis_dokumen_id' => $docType,
                        'url_dokumen' => $badUrl,
                    ]],
                ]),
                'CANDIDATE_URL_INVALID',
            );
        }

        foreach ([
            'ftp://youtube.com/v/1',
            'javascript:alert(1)',
            'https://tiktok.com/@x/video/1',
            'http://www.youtube.com/watch?v=abc',
            'https://drive.usercontent.google.com/example',
            'https://vimeo.com/123456',
            'https://player.vimeo.com/video/123456',
        ] as $badVideo) {
            $this->assertValidationCode(
                fn () => $service->createDraft($staff, [
                    ...$base,
                    'nama_alphabet' => 'Video Guard '.$badVideo,
                    'self_promo' => ['video_jikoshokai_url' => $badVideo],
                ]),
                'CANDIDATE_URL_INVALID',
            );
        }

        $ok = $service->createDraft($staff, [
            ...$base,
            'nama_alphabet' => 'URL Guard OK',
            'documents' => [[
                'jenis_dokumen_id' => $docType,
                'url_dokumen' => 'https://drive.google.com/file/d/ok/view',
            ]],
            'self_promo' => [
                'video_jikoshokai_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'video_keahlian_url' => 'https://drive.google.com/file/d/video/view',
            ],
        ]);

        $this->assertDatabaseHas('candidate_document', [
            'candidate_id' => $ok->id,
            'url_dokumen' => 'https://drive.google.com/file/d/ok/view',
        ]);
        $this->assertDatabaseHas('candidate_self_promo', [
            'candidate_id' => $ok->id,
            'video_jikoshokai_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);
        $this->assertNull($ok->nomor_induk);
        $this->assertSame(0, DB::table('pending_request')->count());
    }

    public function test_audit_failure_rolls_back_draft_create(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();

        AuditLog::creating(static function (): never {
            throw new \RuntimeException('audit failed');
        });

        try {
            app(CandidateDraftService::class)->createDraft($staff, $this->basePayload($country, 'Rollback'));
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit failed', $exception->getMessage());
        } finally {
            AuditLog::getEventDispatcher()?->forget('eloquent.creating: '.AuditLog::class);
        }

        $this->assertDatabaseMissing('candidate', ['nama_alphabet' => 'Rollback']);
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::CANDIDATE_CREATED)->count());
    }

    public function test_audit_failure_on_update_rolls_back_main_and_children(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $educationLevel = $this->seedLookup('tingkat_pendidikan', 'SMA');
        $service = app(CandidateDraftService::class);

        $created = $service->createDraft($staff, [
            ...$this->basePayload($country, 'Update Rollback'),
            'education' => [
                ['tingkat_pendidikan_id' => $educationLevel, 'nama_institusi' => 'Original School'],
            ],
            'physical' => [
                'tinggi_cm' => 170,
                'dominan_tangan' => 'LEFT',
            ],
        ]);

        AuditLog::creating(function ($model): void {
            if ($model->action_type === ActionType::CANDIDATE_UPDATED) {
                throw new \RuntimeException('audit update failed');
            }
        });

        try {
            $service->updateDraft($staff, (int) $created->id, [
                'version' => 0,
                'nama_alphabet' => 'Should Not Persist',
                'education' => [
                    ['tingkat_pendidikan_id' => $educationLevel, 'nama_institusi' => 'Replaced School'],
                ],
                'physical' => [
                    'tinggi_cm' => 180,
                    'dominan_tangan' => 'RIGHT',
                ],
            ]);
            $this->fail('Expected audit failure on update.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit update failed', $exception->getMessage());
        } finally {
            AuditLog::getEventDispatcher()?->forget('eloquent.creating: '.AuditLog::class);
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $created->id,
            'nama_alphabet' => 'Update Rollback',
            'version' => 0,
            'nomor_induk' => null,
            'status_approval' => 'Draft',
        ]);
        $this->assertDatabaseHas('candidate_education', [
            'candidate_id' => $created->id,
            'nama_institusi' => 'Original School',
        ]);
        $this->assertDatabaseMissing('candidate_education', [
            'candidate_id' => $created->id,
            'nama_institusi' => 'Replaced School',
        ]);
        $this->assertDatabaseHas('candidate_physical', [
            'candidate_id' => $created->id,
            'tinggi_cm' => 170,
            'dominan_tangan' => 'LEFT',
        ]);
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::CANDIDATE_UPDATED)->count());
        $this->assertSame(0, DB::table('pending_request')->count());
        $this->assertSame(0, DB::table('nik_counter')->count());
    }

    private function staffInput(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);

        return $staff;
    }

    private function seedCountry(): int
    {
        return DB::table('negara')->insertGetId([
            'code' => 'ID',
            'label_id' => 'Indonesia',
            'label_ja' => 'インドネシア',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedLookup(string $table, string $code): int
    {
        return DB::table($table)->insertGetId([
            'code' => $code,
            'label_id' => $code,
            'label_ja' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(int $country, string $name): array
    {
        return [
            'nama_alphabet' => $name,
            'tanggal_lahir' => '2000-02-02',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'M',
        ];
    }

    private function assertValidationCode(callable $callback, string $code): void
    {
        try {
            $callback();
            $this->fail("Expected validation failure containing [{$code}].");
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $blob = collect($errors)->map(
                static fn (array $messages, string $field): string => $field.' '.implode(' ', $messages)
            )->implode(' | ');
            $this->assertStringContainsString($code, $blob);
        }
    }
}
