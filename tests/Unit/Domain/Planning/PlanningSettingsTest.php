<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Planning;

use App\Domain\Event\Exception\NegativeReadyMargin;
use App\Domain\Planning\Exception\DuplicateStepLabel;
use App\Domain\Planning\Exception\EmptyStepLabel;
use App\Domain\Planning\Exception\NonPositiveStepDuration;
use App\Domain\Planning\PlanningSettings;
use App\Tests\Support\TestWorkspace;
use PHPUnit\Framework\TestCase;

final class PlanningSettingsTest extends TestCase
{
    public function testSettingsStartWithoutStepsAndThreeDaysOfMargin(): void
    {
        $settings = PlanningSettings::of(TestWorkspace::get());

        self::assertSame(TestWorkspace::get(), $settings->workspace());
        self::assertSame([], $settings->steps());
        self::assertSame(3, $settings->readyDaysBefore());
    }

    public function testDefiningKeepsTheStepOrderWithTrimmedLabelsAndTheMargin(): void
    {
        $settings = PlanningSettings::of(TestWorkspace::get());

        $settings->define([['label' => ' Dessin ', 'days' => 3], ['label' => 'Impression', 'days' => 2]], 5);

        self::assertSame([['label' => 'Dessin', 'days' => 3], ['label' => 'Impression', 'days' => 2]], $settings->steps());
        self::assertSame(5, $settings->readyDaysBefore());
    }

    public function testTheMarginCanBeZero(): void
    {
        $settings = PlanningSettings::of(TestWorkspace::get());

        $settings->define([], 0);

        self::assertSame(0, $settings->readyDaysBefore());
    }

    public function testANegativeMarginIsRefused(): void
    {
        $this->expectException(NegativeReadyMargin::class);

        PlanningSettings::of(TestWorkspace::get())->define([], -1);
    }

    public function testAStepNeedsALabel(): void
    {
        $this->expectException(EmptyStepLabel::class);

        PlanningSettings::of(TestWorkspace::get())->define([['label' => '', 'days' => 3]], 3);
    }

    public function testStepLabelsAreUnique(): void
    {
        $this->expectException(DuplicateStepLabel::class);

        PlanningSettings::of(TestWorkspace::get())->define([['label' => 'Dessin', 'days' => 3], ['label' => 'Dessin', 'days' => 1]], 3);
    }

    public function testAStepLastsAtLeastOneDay(): void
    {
        $this->expectException(NonPositiveStepDuration::class);

        PlanningSettings::of(TestWorkspace::get())->define([['label' => 'Dessin', 'days' => -1]], 3);
    }
}
