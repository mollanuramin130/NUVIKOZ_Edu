<?php

namespace Tests\Unit;

use App\Services\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    public function test_rfc6238_sha1_published_vectors(): void
    {
        $t = new Totp;
        foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'] as $time => $code) {
            $this->assertSame($code, $t->code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $time, 8));
        }
        $seed = $t->seed();
        $code = $t->code($seed, 120);
        $this->assertSame(4, $t->counter($seed, $code, 120, null));
        $this->assertNull($t->counter($seed, $code, 120, 4));
        $this->assertNull($t->counter($seed, $code, 210, null));
        $this->assertNull($t->counter($seed, ' 12345', 120, null));
        $this->assertNotSame($seed, $t->seed());
    }
}
