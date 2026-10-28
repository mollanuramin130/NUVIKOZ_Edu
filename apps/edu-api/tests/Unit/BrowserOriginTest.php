<?php

namespace Tests\Unit;

use App\Support\BrowserOrigin;
use PHPUnit\Framework\TestCase;

class BrowserOriginTest extends TestCase
{
    public function test_only_exact_secure_origins_are_trusted(): void
    {
        $trusted = ['https://edu.example', 'http://localhost:3000'];
        $this->assertTrue(BrowserOrigin::allowed('https://edu.example', $trusted));
        $this->assertTrue(BrowserOrigin::allowed('http://localhost:3000', $trusted));
        foreach (['null', '*', 'https://nursery.example', 'https://edu.example.attacker.invalid', 'https://edu.example:444', 'https://edu.example/', 'https://name:secret@edu.example', 'http://edu.example'] as $origin) {
            $this->assertFalse(BrowserOrigin::allowed($origin, $trusted));
        }
    }
}
