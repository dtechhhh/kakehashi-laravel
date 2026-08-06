<?php

namespace Tests\Feature\UI;

use Tests\TestCase;

class NotificationKeysTest extends TestCase
{
    public function test_wave4_notification_keys_translate_in_id_and_ja(): void
    {
        $keys = [
            'IC_SUBMITTED',
            'IC_APPROVED',
            'IC_REJECTED',
            'IC_CLOSE_REQUESTED',
            'IC_CLOSED',
            'EXPEL_REQUESTED',
            'EXPEL_APPROVED',
            'EXPEL_REJECTED',
            'GUEST_LINK_REQUESTED',
            'GUEST_LINK_APPROVED',
            'GUEST_LINK_REJECTED',
        ];

        foreach (['id', 'ja'] as $locale) {
            app()->setLocale($locale);

            foreach ($keys as $key) {
                $translated = trans('ui.notifications.'.$key);

                $this->assertIsString($translated);
                $this->assertNotSame('ui.notifications.'.$key, $translated);
                $this->assertNotSame('', trim($translated));
            }
        }

        app()->setLocale('id');
    }
}
