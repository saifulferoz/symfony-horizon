<?php

namespace Saifulferoz\SymfonyHorizon\Tests\Supervisor;

use PHPUnit\Framework\TestCase;
use Saifulferoz\SymfonyHorizon\Supervisor\Autoscaler;

use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Autoscaler::class)]
class AutoscalerTest extends TestCase
{
    private Autoscaler $autoscaler;

    protected function setUp(): void
    {
        $this->autoscaler = new Autoscaler();
    }

    public function testScaleReturnsMinProcessesWhenNoPendingMessages(): void
    {
        $result = $this->autoscaler->scale(3, 0, 2, 8);
        $this->assertEquals(2, $result);

        $result = $this->autoscaler->scale(3, -5, 2, 8);
        $this->assertEquals(2, $result);
    }

    public function testScaleCalculatesCorrectProportionalScale(): void
    {
        // 1 additional worker per 50 pending messages
        // 49 messages -> +1 worker -> 2 + 1 = 3
        $result = $this->autoscaler->scale(2, 49, 2, 8);
        $this->assertEquals(3, $result);

        // 50 messages -> +1 worker -> 2 + 1 = 3
        $result = $this->autoscaler->scale(2, 50, 2, 8);
        $this->assertEquals(3, $result);

        // 51 messages -> +2 workers -> 2 + 2 = 4
        $result = $this->autoscaler->scale(2, 51, 2, 8);
        $this->assertEquals(4, $result);

        // 150 messages -> +3 workers -> 2 + 3 = 5
        $result = $this->autoscaler->scale(2, 150, 2, 8);
        $this->assertEquals(5, $result);
    }

    public function testScaleObeysMaxProcessesLimit(): void
    {
        // 1000 messages -> +20 workers -> 2 + 20 = 22, but capped at max 8
        $result = $this->autoscaler->scale(2, 1000, 2, 8);
        $this->assertEquals(8, $result);
    }
}
