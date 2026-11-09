<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Planning;

use App\Domain\Planning\Exception\DuplicateStepLabel;
use App\Domain\Planning\Exception\EmptyStepLabel;
use App\Domain\Planning\Exception\NonPositiveStepDuration;
use App\Domain\Planning\ReleaseTemplate;
use App\Tests\Support\TestWorkspace;
use PHPUnit\Framework\TestCase;

final class ReleaseTemplateTest extends TestCase
{
    public function testATemplateStartsWithoutSteps(): void
    {
        $template = ReleaseTemplate::of(TestWorkspace::get());

        self::assertSame(TestWorkspace::get(), $template->workspace());
        self::assertSame([], $template->steps());
    }

    public function testDefiningTheStepsKeepsTheirOrderWithTrimmedLabels(): void
    {
        $template = ReleaseTemplate::of(TestWorkspace::get());

        $template->define([['label' => ' Dessin ', 'days' => 3], ['label' => 'Impression', 'days' => 2]]);

        self::assertSame([['label' => 'Dessin', 'days' => 3], ['label' => 'Impression', 'days' => 2]], $template->steps());
    }

    public function testATemplateStepNeedsALabel(): void
    {
        $this->expectException(EmptyStepLabel::class);

        ReleaseTemplate::of(TestWorkspace::get())->define([['label' => '', 'days' => 3]]);
    }

    public function testTemplateStepLabelsAreUnique(): void
    {
        $this->expectException(DuplicateStepLabel::class);

        ReleaseTemplate::of(TestWorkspace::get())->define([['label' => 'Dessin', 'days' => 3], ['label' => 'Dessin', 'days' => 1]]);
    }

    public function testATemplateStepLastsAtLeastOneDay(): void
    {
        $this->expectException(NonPositiveStepDuration::class);

        ReleaseTemplate::of(TestWorkspace::get())->define([['label' => 'Dessin', 'days' => -1]]);
    }
}
