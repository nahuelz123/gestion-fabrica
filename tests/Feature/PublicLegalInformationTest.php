<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicLegalInformationTest extends TestCase
{
    public function test_privacy_policy_is_accessible_without_logging_in(): void
    {
        $this->get(route('legal.privacy'))
            ->assertOk()
            ->assertSee('Política de privacidad')
            ->assertSee('Datos tratados')
            ->assertSee('Consultas sobre datos');
    }

    public function test_cookie_policy_is_accessible_without_logging_in(): void
    {
        $this->get(route('legal.cookies'))
            ->assertOk()
            ->assertSee('Política de cookies');
    }

    public function test_public_vending_screen_displays_existing_legal_links(): void
    {
        $markup = file_get_contents(resource_path('views/vending/tablet.blade.php'));

        $this->assertStringContainsString("route('legal.privacy')", $markup);
        $this->assertStringContainsString("route('legal.cookies')", $markup);
        $this->assertStringContainsString('noopener noreferrer', $markup);
    }
}
