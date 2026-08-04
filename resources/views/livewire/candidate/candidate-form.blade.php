@php
    $yesNo = ['YES' => __('ui.form.yes_no.YES'), 'NO' => __('ui.form.yes_no.NO')];
    $gender = ['M' => __('ui.form.gender.M'), 'F' => __('ui.form.gender.F')];
    $marital = ['MARRIED' => __('ui.form.marital.MARRIED'), 'SINGLE' => __('ui.form.marital.SINGLE')];
    $hand = ['RIGHT' => __('ui.form.hand.RIGHT'), 'LEFT' => __('ui.form.hand.LEFT')];
    $nav = [
        ['id' => 'section-personal', 'label' => __('ui.form.section.personal')],
        ['id' => 'section-physical', 'label' => __('ui.form.section.physical')],
        ['id' => 'section-education', 'label' => __('ui.form.section.education')],
        ['id' => 'section-work', 'label' => __('ui.form.section.work')],
        ['id' => 'section-quals', 'label' => __('ui.form.section.quals')],
        ['id' => 'section-selfpromo', 'label' => __('ui.form.section.selfpromo')],
        ['id' => 'section-family', 'label' => __('ui.form.section.family')],
        ['id' => 'section-immigration', 'label' => __('ui.form.section.immigration')],
        ['id' => 'section-documents', 'label' => __('ui.form.section.documents')],
        ['id' => 'section-photo', 'label' => __('ui.form.section.photo')],
    ];
@endphp

<div>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-sm text-zinc-500">
                    <a href="{{ route('candidate.index') }}" class="text-blue-600 hover:underline">{{ __('ui.candidate.list_title') }}</a>
                    <span class="mx-1" aria-hidden="true">/</span>
                    {{ $isEditing ? __('ui.form.edit_title') : __('ui.form.create_title') }}
                </p>
                <h1 class="mt-1 text-2xl font-semibold text-zinc-900">{{ $isEditing ? __('ui.form.edit_title') : __('ui.form.create_title') }}</h1>
                @if ($isEditing && ! $readonly)
                    <p class="mt-1 font-mono text-sm tabular-nums text-zinc-600">{{ __('ui.candidate.version', ['version' => $version]) }}</p>
                @endif
            </div>
            @if ($isEditing && $readonly)
                <x-badge type="info" icon="lock">{{ __('ui.form.readonly') }}</x-badge>
            @endif
        </div>

        @if ($conflict)
            <x-state type="conflict" />
        @endif

        @if ($actionError)
            <x-alert type="error" wire:key="error">{{ $actionError }}</x-alert>
        @endif

        @if ($similarityMatches !== null)
            <div class="rounded-lg border border-amber-300 bg-warning-bg p-4 shadow-sm" wire:key="similarity">
                <h2 class="text-base font-semibold text-warning-text">{{ __('ui.form.similarity_title') }}</h2>
                <p class="mt-1 text-sm text-warning-text">{{ __('ui.form.similarity_description') }}</p>
                <ul class="mt-3 flex flex-col gap-2">
                    @foreach ($similarityMatches as $match)
                        <li class="flex items-center justify-between rounded-md bg-white px-3 py-2 text-sm">
                            <span class="text-warning-text">{{ __('ui.form.similarity_match', ['nik' => $match['nomor_induk'] ?: '-']) }}</span>
                            <span class="font-mono tabular-nums text-warning-text">{{ number_format($match['score'] * 100, 1) }}%</span>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-3 flex gap-2">
                    <x-button wire:click="confirmSimilarityAndSubmit">{{ __('ui.form.similarity_continue') }}</x-button>
                    <x-button variant="secondary" wire:click="$set('similarityMatches', null)">{{ __('ui.common.cancel') }}</x-button>
                </div>
            </div>
        @endif

        <div class="flex gap-6">
            <aside class="hidden w-56 shrink-0 lg:block" aria-label="{{ __('ui.form.section_nav') }}">
                <nav class="sticky top-24 flex flex-col gap-0.5 rounded-lg border border-zinc-200 bg-white p-2 shadow-sm">
                    @foreach ($nav as $item)
                        <a href="#{{ $item['id'] }}" class="rounded-md px-2 py-1.5 text-sm text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 focus-visible:outline-2 focus-visible:outline-blue-600">
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </nav>
            </aside>

            <div class="min-w-0 flex-1 space-y-4" @if ($readonly) inert @endif>
                @if ($readonly)
                    <x-alert type="info">{{ __('ui.form.readonly_note') }}</x-alert>
                @endif

                <section id="section-personal" class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">{{ __('ui.form.section.personal') }}</h2>
                    <p class="mt-1 text-sm text-zinc-500">{{ __('ui.form.section.personal_desc') }}</p>
                    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <x-input wire:model="formNamaAlphabet" label="{{ __('ui.form.nama_alphabet') }}" required
                            :error="$serverErrors['nama_alphabet'] ?? null" />
                        <x-input wire:model="formNamaKatakana" label="{{ __('ui.form.nama_katakana') }}" />
                        <x-input wire:model="formTanggalLahir" type="date" label="{{ __('ui.form.tanggal_lahir') }}" required
                            :error="$serverErrors['tanggal_lahir'] ?? null" />
                        <x-select wire:model="formJenisKelamin" label="{{ __('ui.form.jenis_kelamin') }}" required
                            :options="$gender" :error="$serverErrors['jenis_kelamin'] ?? null" />
                        <x-select wire:model="formStatusPernikahan" label="{{ __('ui.form.status_pernikahan') }}"
                            :options="$marital" />
                        @include('livewire.candidate.partials.lookup-field', ['field' => 'formKewarganegaraanId', 'table' => 'negara', 'label' => __('ui.form.kewarganegaraan_id'), 'required' => true, 'error' => $serverErrors['kewarganegaraan_id'] ?? null])
                        @include('livewire.candidate.partials.lookup-field', ['field' => 'formTempatLahirKotaId', 'table' => 'kota_kabupaten', 'label' => __('ui.form.tempat_lahir_kota_id'), 'error' => $serverErrors['tempat_lahir_kota_id'] ?? null])
                        @include('livewire.candidate.partials.lookup-field', ['field' => 'formAsalRekrutmenId', 'table' => 'asal_rekrutmen', 'label' => __('ui.form.asal_rekrutmen_id'), 'error' => $serverErrors['asal_rekrutmen_id'] ?? null])
                        @include('livewire.candidate.partials.lookup-field', ['field' => 'formAgamaId', 'table' => 'agama', 'label' => __('ui.form.agama_id'), 'error' => $serverErrors['agama_id'] ?? null])
                        <x-input wire:model="formAlamatDetail" label="{{ __('ui.form.alamat_detail') }}" class="md:col-span-2" />
                        @include('livewire.candidate.partials.lookup-field', ['field' => 'formAlamatProvinsiId', 'table' => 'provinsi', 'label' => __('ui.form.alamat_provinsi_id'), 'error' => $serverErrors['alamat_provinsi_id'] ?? null])
                        @include('livewire.candidate.partials.lookup-field', ['field' => 'formAlamatKotaKabupatenId', 'table' => 'kota_kabupaten', 'label' => __('ui.form.alamat_kota_kabupaten_id'), 'error' => $serverErrors['alamat_kota_kabupaten_id'] ?? null])
                        @include('livewire.candidate.partials.lookup-field', ['field' => 'formAlamatKecamatanId', 'table' => 'kecamatan', 'label' => __('ui.form.alamat_kecamatan_id'), 'error' => $serverErrors['alamat_kecamatan_id'] ?? null])
                        <x-input wire:model="formEmail" type="email" label="{{ __('ui.common.email') }}" :error="$serverErrors['email'] ?? null" />
                        <x-input wire:model="formPhone" label="{{ __('ui.form.phone') }}" />
                        <x-input wire:model="formLineId" label="{{ __('ui.form.line_id') }}" />
                        <x-textarea wire:model="formCatatanTambahan" label="{{ __('ui.form.catatan_tambahan') }}" class="md:col-span-2" />
                    </div>
                </section>

                <section id="section-physical" class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">{{ __('ui.form.section.physical') }}</h2>
                    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <x-input wire:model="formTinggiCm" type="number" step="0.01" label="{{ __('ui.form.tinggi_cm') }}" />
                        <x-input wire:model="formBeratKg" type="number" step="0.01" label="{{ __('ui.form.berat_kg') }}" />
                        <x-input wire:model="formLingkarPerutCm" type="number" step="0.01" label="{{ __('ui.form.lingkar_perut_cm') }}" />
                        <x-select wire:model="formGolonganDarahId" label="{{ __('ui.form.golongan_darah_id') }}" :options="$options('golongan_darah')" />
                        <x-select wire:model="formUkuranSepatuId" label="{{ __('ui.form.ukuran_sepatu_id') }}" :options="$options('ukuran_sepatu')" />
                        <x-select wire:model="formPenglihatanKiriId" label="{{ __('ui.form.penglihatan_kiri_id') }}" :options="$options('tingkat_penglihatan')" />
                        <x-select wire:model="formPenglihatanKananId" label="{{ __('ui.form.penglihatan_kanan_id') }}" :options="$options('tingkat_penglihatan')" />
                        <x-select wire:model="formDominanTangan" label="{{ __('ui.form.dominan_tangan') }}" :options="$hand" />
                        <x-select wire:model="formButaWarna" label="{{ __('ui.form.buta_warna') }}" :options="$yesNo" />
                        <x-select wire:model="formMerokok" label="{{ __('ui.form.merokok') }}" :options="$yesNo" />
                        <x-select wire:model="formMinumSake" label="{{ __('ui.form.minum_sake') }}" :options="$yesNo" />
                        <x-select wire:model="formPembatasanMakanan" label="{{ __('ui.form.pembatasan_makanan') }}" :options="$yesNo" />
                        <x-select wire:model="formRiwayatPenyakit" label="{{ __('ui.form.riwayat_penyakit') }}" :options="$yesNo" />
                        <x-select wire:model="formRiwayatOperasi" label="{{ __('ui.form.riwayat_operasi') }}" :options="$yesNo" />
                        <x-textarea wire:model="formCatatanKesehatan" label="{{ __('ui.form.catatan_kesehatan') }}" class="md:col-span-2" />
                    </div>
                </section>

                <section id="section-education" class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">{{ __('ui.form.section.education') }}
                        <span class="text-xs font-normal text-zinc-500">{{ count($education) }} / 5</span>
                    </h2>
                    @foreach ($education as $index => $row)
                        <div class="mt-3 grid grid-cols-1 gap-3 rounded-md border border-zinc-200 p-3 md:grid-cols-4" wire:key="edu-{{ $index }}">
                            <x-select wire:model="education.{{ $index }}.tingkat_pendidikan_id" label="{{ __('ui.form.tingkat_pendidikan_id') }}" :options="$options('tingkat_pendidikan')" />
                            <x-select wire:model="education.{{ $index }}.jurusan_id" label="{{ __('ui.form.jurusan_id') }}" :options="$options('jurusan')" />
                            <x-input wire:model="education.{{ $index }}.nama_institusi" label="{{ __('ui.form.nama_institusi') }}" />
                            <div class="flex items-end gap-2">
                                <x-input wire:model="education.{{ $index }}.tanggal_masuk" type="date" label="{{ __('ui.form.tanggal_masuk') }}" />
                                <x-input wire:model="education.{{ $index }}.tanggal_keluar" type="date" label="{{ __('ui.form.tanggal_keluar') }}" />
                                <x-button size="sm" variant="destructive" wire:click="removeRow('education', {{ $index }})" aria-label="{{ __('ui.common.delete') }}">✕</x-button>
                            </div>
                        </div>
                    @endforeach
                    <x-button size="sm" variant="secondary" wire:click="addRow('education')" class="mt-3">{{ __('ui.form.add_education') }}</x-button>
                </section>

                <section id="section-work" class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">{{ __('ui.form.section.work') }}
                        <span class="text-xs font-normal text-zinc-500">{{ count($work) }} / 5</span>
                    </h2>
                    @foreach ($work as $index => $row)
                        <div class="mt-3 grid grid-cols-1 gap-3 rounded-md border border-zinc-200 p-3 md:grid-cols-4" wire:key="work-{{ $index }}">
                            <x-input wire:model="work.{{ $index }}.nama_perusahaan" label="{{ __('ui.form.nama_perusahaan') }}" />
                            <x-input wire:model="work.{{ $index }}.perusahaan_penanggung" label="{{ __('ui.form.perusahaan_penanggung') }}" />
                            <x-select wire:model="work.{{ $index }}.bidang_pekerjaan_id" label="{{ __('ui.form.bidang_pekerjaan_id') }}" :options="$options('bidang_pekerjaan')" />
                            <div class="flex items-end gap-2">
                                <x-input wire:model="work.{{ $index }}.tanggal_masuk" type="date" label="{{ __('ui.form.tanggal_masuk') }}" />
                                <x-input wire:model="work.{{ $index }}.tanggal_keluar" type="date" label="{{ __('ui.form.tanggal_keluar') }}" />
                                <x-button size="sm" variant="destructive" wire:click="removeRow('work', {{ $index }})" aria-label="{{ __('ui.common.delete') }}">✕</x-button>
                            </div>
                        </div>
                    @endforeach
                    <x-button size="sm" variant="secondary" wire:click="addRow('work')" class="mt-3">{{ __('ui.form.add_work') }}</x-button>
                </section>

                <section id="section-quals" class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">{{ __('ui.form.section.quals') }}</h2>
                    @foreach ([
                        ['key' => 'qualEnglish', 'prop' => 'qualEnglish', 'title' => 'ui.form.section.qual_english', 'lookup' => 'jenis_kualifikasi_bahasa_inggris', 'idfield' => 'jenis_id', 'ssw' => false],
                        ['key' => 'qualJapanese', 'prop' => 'qualJapanese', 'title' => 'ui.form.section.qual_japanese', 'lookup' => 'jenis_kualifikasi_bahasa_jepang', 'idfield' => 'jenis_id', 'ssw' => false],
                        ['key' => 'qualSsw', 'prop' => 'qualSsw', 'title' => 'ui.form.section.qual_ssw', 'lookup' => 'skill_ssw', 'idfield' => 'skill_ssw_id', 'ssw' => true],
                        ['key' => 'qualDriving', 'prop' => 'qualDriving', 'title' => 'ui.form.section.qual_driving', 'lookup' => 'kualifikasi_mengemudi', 'idfield' => 'kualifikasi_mengemudi_id', 'ssw' => false],
                        ['key' => 'qualOther', 'prop' => 'qualOther', 'title' => 'ui.form.section.qual_other', 'lookup' => 'kualifikasi_keahlian_lainnya', 'idfield' => 'kualifikasi_keahlian_lainnya_id', 'ssw' => false],
                    ] as $qual)
                        <div class="mt-4 rounded-md border border-zinc-100 p-3">
                            <h3 class="text-base font-semibold text-zinc-800">{{ __($qual['title']) }}</h3>
                            @foreach (${$qual['prop']} as $index => $row)
                                <div class="mt-2 grid grid-cols-1 gap-3 md:grid-cols-4" wire:key="{{ $qual['key'] }}-{{ $index }}">
                                    <x-select wire:model="{{ $qual['prop'] }}.{{ $index }}.{{ $qual['idfield'] }}" label="{{ __('ui.form.jenis_id') }}" :options="$options($qual['lookup'])" />
                                    <x-input wire:model="{{ $qual['prop'] }}.{{ $index }}.tanggal_akuisisi" type="date" label="{{ __('ui.form.tanggal_akuisisi') }}" />
                                    @if (! $qual['ssw'])
                                        <x-input wire:model="{{ $qual['prop'] }}.{{ $index }}.skor" label="{{ __('ui.form.skor') }}" />
                                    @endif
                                    <div class="flex items-end gap-2">
                                        <x-input wire:model="{{ $qual['prop'] }}.{{ $index }}.url_file" label="{{ __('ui.form.url_file') }}" :hint="__('ui.form.drive_hint')" />
                                        <x-button size="sm" variant="destructive" wire:click="removeRow('{{ $qual['key'] }}', {{ $index }})" aria-label="{{ __('ui.common.delete') }}">✕</x-button>
                                    </div>
                                </div>
                            @endforeach
                            <x-button size="sm" variant="secondary" wire:click="addRow('{{ $qual['key'] }}')" class="mt-2">{{ __('ui.form.add_qual') }}</x-button>
                        </div>
                    @endforeach
                </section>

                <section id="section-selfpromo" class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">{{ __('ui.form.section.selfpromo') }}</h2>
                    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <x-input wire:model="formSkorIq" label="{{ __('ui.form.skor_iq') }}" />
                        <x-input wire:model="formSkorMatematika" label="{{ __('ui.form.skor_matematika') }}" />
                        @include('livewire.candidate.partials.lookup-field', ['field' => 'formBidangDiminatiId', 'table' => 'bidang_diminati', 'label' => __('ui.form.bidang_diminati_id'), 'error' => $serverErrors['self_promo.bidang_diminati_id'] ?? null])
                        <x-input wire:model="formVideoJikoshokaiUrl" label="{{ __('ui.form.video_jikoshokai_url') }}" :hint="__('ui.form.video_hint')" />
                        <x-input wire:model="formVideoKeahlianUrl" label="{{ __('ui.form.video_keahlian_url') }}" :hint="__('ui.form.video_hint')" />
                        <x-input wire:model="formFinalLaporanPsikotes" label="{{ __('ui.form.final_laporan_psikotes') }}" :hint="__('ui.form.drive_hint')" class="md:col-span-2" />
                    </div>
                </section>

                <section id="section-family" class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">{{ __('ui.form.section.family') }}
                        <span class="text-xs font-normal text-zinc-500">{{ count($family) }} / 10</span>
                    </h2>
                    @foreach ($family as $index => $row)
                        <div class="mt-3 grid grid-cols-1 gap-3 rounded-md border border-zinc-200 p-3 md:grid-cols-4" wire:key="family-{{ $index }}">
                            <x-select wire:model="family.{{ $index }}.status_keluarga_id" label="{{ __('ui.form.status_keluarga_id') }}" :options="$options('status_keluarga')" />
                            <x-input wire:model="family.{{ $index }}.nama" label="{{ __('ui.form.nama') }}" />
                            <x-input wire:model="family.{{ $index }}.tanggal_lahir" type="date" label="{{ __('ui.form.tanggal_lahir') }}" />
                            <div class="flex items-end">
                                <x-button size="sm" variant="destructive" wire:click="removeRow('family', {{ $index }})" aria-label="{{ __('ui.common.delete') }}">✕</x-button>
                            </div>
                        </div>
                    @endforeach
                    <x-button size="sm" variant="secondary" wire:click="addRow('family')" class="mt-3">{{ __('ui.form.add_family') }}</x-button>

                    <h3 class="mt-6 text-base font-semibold text-zinc-800">{{ __('ui.form.section.family_contact') }}</h3>
                    <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <x-select wire:model="formKontakStatusKeluargaId" label="{{ __('ui.form.status_keluarga_id') }}" :options="$options('status_keluarga')" />
                        <x-input wire:model="formKontakNama" label="{{ __('ui.form.nama') }}" />
                        <x-input wire:model="formKontakPhone" label="{{ __('ui.form.phone') }}" />
                        <x-input wire:model="formKontakAlamat" label="{{ __('ui.form.alamat') }}" />
                    </div>
                </section>

                <section id="section-immigration" class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">{{ __('ui.form.section.immigration') }}</h2>
                    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <x-input wire:model="formNomorPaspor" label="{{ __('ui.form.nomor_paspor') }}" />
                        <x-input wire:model="formMasaBerlakuPaspor" type="date" label="{{ __('ui.form.masa_berlaku_paspor') }}" />
                        <x-input wire:model="formNomorZairyu" label="{{ __('ui.form.nomor_zairyu') }}" />
                        <x-input wire:model="formAlamatZairyu" label="{{ __('ui.form.alamat_zairyu') }}" />
                        <x-select wire:model="formJenisVisaId" label="{{ __('ui.form.jenis_visa_id') }}" :options="$options('jenis_visa')" />
                        <x-select wire:model="formPernahKeJepang" label="{{ __('ui.form.pernah_ke_jepang') }}" :options="$yesNo" />
                        <x-textarea wire:model="formCatatanImigrasi" label="{{ __('ui.form.catatan') }}" class="md:col-span-2" />
                    </div>
                </section>

                <section id="section-documents" class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">{{ __('ui.form.section.documents') }}</h2>
                    <p class="mt-1 text-sm text-zinc-500">{{ __('ui.form.documents_desc') }}</p>
                    @foreach ($documents as $index => $row)
                        <div class="mt-3 grid grid-cols-1 gap-3 rounded-md border border-zinc-200 p-3 md:grid-cols-4" wire:key="doc-{{ $index }}">
                            <x-select wire:model="documents.{{ $index }}.jenis_dokumen_id" label="{{ __('ui.form.jenis_dokumen_id') }}" :options="$options('jenis_dokumen')" />
                            <x-input wire:model="documents.{{ $index }}.url_dokumen" label="{{ __('ui.form.url_dokumen') }}" :hint="__('ui.form.drive_hint')" />
                            <x-input wire:model="documents.{{ $index }}.nama_file" label="{{ __('ui.form.nama_file') }}" />
                            <div class="flex items-end gap-2">
                                <x-input wire:model="documents.{{ $index }}.catatan" label="{{ __('ui.form.catatan') }}" />
                                <x-button size="sm" variant="destructive" wire:click="removeRow('documents', {{ $index }})" aria-label="{{ __('ui.common.delete') }}">✕</x-button>
                            </div>
                        </div>
                    @endforeach
                    <x-button size="sm" variant="secondary" wire:click="addRow('documents')" class="mt-3">{{ __('ui.form.add_document') }}</x-button>
                </section>

                <section id="section-photo" class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">{{ __('ui.form.section.photo') }}</h2>
                    <p class="mt-1 text-sm text-zinc-500">{{ __('ui.form.photo_desc') }}</p>
                    <div class="mt-3 flex flex-wrap items-start gap-4">
                        @if ($photoUrl)
                            <img src="{{ $photoUrl }}" alt="{{ __('ui.candidate.photo_alt', ['name' => $formNamaAlphabet ?: 'Kandidat']) }}"
                                class="h-40 w-32 rounded-md border border-zinc-200 object-cover" />
                        @endif
                        <div class="min-w-64">
                            <input type="file" wire:model="photoFile" accept="image/jpeg,image/png,image/webp"
                                class="block w-full text-sm text-zinc-600 file:mr-3 file:rounded-md file:border-0 file:bg-zinc-900 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-white hover:file:bg-zinc-800 focus-visible:outline-2 focus-visible:outline-blue-600"
                                aria-label="{{ __('ui.form.photo_upload') }}" @if (! $isEditing || $readonly) disabled @endif>
                            <div wire:loading wire:target="photoFile" class="mt-2 text-sm text-zinc-500">{{ __('ui.state.loading') }}</div>
                            @if ($photoError)
                                <p class="mt-2 text-sm text-red-600">{{ $photoError }}</p>
                            @endif
                            @if ($photoStatus)
                                <p class="mt-2 text-sm text-success-text">{{ $photoStatus }}</p>
                            @endif
                            @if (! $isEditing)
                                <p class="mt-2 text-xs text-zinc-500">{{ __('ui.form.photo_save_first') }}</p>
                            @endif
                        </div>
                    </div>
                </section>
            </div>
        </div>

        @if (! $readonly)
            <div class="sticky bottom-0 flex flex-wrap items-center justify-end gap-2 rounded-t-lg border-t border-zinc-200 bg-white/95 px-4 py-3 shadow-sm backdrop-blur">
                <p class="mr-auto text-sm text-zinc-500">{{ __('ui.form.action_hint') }}</p>
                <x-button variant="secondary" wire:click="saveDraft">{{ __('ui.form.save_draft') }}</x-button>
                <x-button wire:click="submitCandidate">{{ __('ui.form.submit') }}</x-button>
            </div>
        @endif
    </div>
</div>
