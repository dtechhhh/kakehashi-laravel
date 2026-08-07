<?php

namespace Tests\Feature\UI;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * UI-W6-U3 — i18n parity (id/ja) and guest route smoke.
 */
class GuestI18nAndRouteSmokeTest extends TestCase
{
    public function test_guest_ui_keys_exist_and_translate_in_both_locales(): void
    {
        $keys = [
            'page_title',
            'brand',
            'surface_note',
            'code_title',
            'code_hint',
            'code_label',
            'open_link',
            'denied_title',
            'denied_message',
            'candidates_title',
            'container_label',
            'interview_date',
            'interview_type',
            'interview_type_OFFLINE',
            'interview_type_ONLINE',
            'col_code',
            'col_age',
            'col_gender',
            'col_nationality',
            'col_japanese',
            'col_ssw',
            'col_field',
            'empty_list',
            'detail_title',
            'back_to_list',
            'detail_profile',
            'detail_photo',
            'no_photo',
            'detail_qualifications',
            'detail_japanese',
            'detail_english',
            'detail_driving',
            'detail_ssw',
            'detail_work',
            'detail_education',
            'detail_documents',
        ];

        foreach (['id', 'ja'] as $locale) {
            app()->setLocale($locale);

            foreach ($keys as $key) {
                $translated = trans('ui.guest.'.$key);

                $this->assertIsString($translated);
                $this->assertNotSame('ui.guest.'.$key, $translated, "Missing ui.guest.{$key} in {$locale}.");
                $this->assertNotSame('', trim($translated));
            }
        }

        app()->setLocale('id');
    }

    public function test_guest_routes_are_registered_and_named(): void
    {
        foreach ([
            'guest.gate',
            'guest.code',
            'guest.candidates',
            'guest.detail',
            'guest.photo',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Missing guest route [{$name}].");
        }
    }

    public function test_guest_surface_requires_session_for_internal_pages(): void
    {
        $this->get(route('guest.candidates'))->assertNotFound();
        $this->get(route('guest.detail', 1))->assertNotFound();
        $this->get(route('guest.photo', 1))->assertNotFound();
    }
}
