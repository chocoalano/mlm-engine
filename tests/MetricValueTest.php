<?php

declare(strict_types=1);

namespace PandaBear\Mlm\Tests;

use PandaBear\Mlm\Exceptions\InvalidMetric;
use PandaBear\Mlm\Exceptions\InvalidVolumeEntry;
use PandaBear\Mlm\Metrics\MetricValue;
use PandaBear\Mlm\Volume\Quantity;

final class MetricValueTest extends TestCase
{
    public function test_a_value_is_exact_and_canonical(): void
    {
        $this->assertSame('35', MetricValue::of(35)->value());
        $this->assertSame('0.3', MetricValue::of('0.300')->value());
        $this->assertSame('-50', (string) MetricValue::of('-50'));
        $this->assertSame('25.5', MetricValue::fromQuantity(Quantity::of('25.5'))->value());
        $this->assertTrue(MetricValue::of('2.50')->equals(MetricValue::of('2.5')));
        $this->assertFalse(MetricValue::of('2.5')->equals(MetricValue::of('2.4')));
    }

    public function test_a_float_is_refused(): void
    {
        try {
            MetricValue::of(0.1);
            $this->fail('A float became a metric value.');
        } catch (InvalidMetric $exception) {
            $this->assertStringContainsString('float', $exception->getMessage());
            $this->assertInstanceOf(InvalidVolumeEntry::class, $exception->getPrevious());
        }
    }
}
