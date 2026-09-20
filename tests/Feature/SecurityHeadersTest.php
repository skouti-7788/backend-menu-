<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_security_headers_are_present(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        $response->assertHeader(
            'X-Content-Type-Options',
            'nosniff'
        );

        $response->assertHeader(
            'X-Frame-Options',
            'DENY'
        );

        $response->assertHeader(
            'Referrer-Policy',
            'strict-origin-when-cross-origin'
        );

        $response->assertHeader(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=()'
        );
    }
}
