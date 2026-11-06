<?php

declare(strict_types=1);

namespace Snt\Quotes\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Snt\Quotes\Domain\QuoteStatus;

final class QuoteStatusTest extends TestCase
{
    public function testNominalLifecycle(): void
    {
        $path = [QuoteStatus::Draft, QuoteStatus::Sent, QuoteStatus::Viewed, QuoteStatus::Accepted, QuoteStatus::Ordered, QuoteStatus::Paid];
        for ($i = 1, $n = count($path); $i < $n; ++$i) {
            self::assertTrue($path[$i - 1]->canTransitionTo($path[$i]), $path[$i - 1]->value . ' → ' . $path[$i]->value);
        }
    }

    public function testFinalStatusesAreLocked(): void
    {
        self::assertSame([], QuoteStatus::Paid->allowedTransitions());
        self::assertSame([], QuoteStatus::Cancelled->allowedTransitions());
        self::assertFalse(QuoteStatus::Ordered->canTransitionTo(QuoteStatus::Accepted));
    }

    public function testDraftCannotBeAcceptedDirectly(): void
    {
        self::assertFalse(QuoteStatus::Draft->canTransitionTo(QuoteStatus::Accepted));
    }

    public function testOnlyDraftLikeStatusesAreEditable(): void
    {
        $editable = array_filter(QuoteStatus::cases(), static fn (QuoteStatus $s): bool => $s->isEditable());
        self::assertEqualsCanonicalizing(
            [QuoteStatus::Draft, QuoteStatus::PendingApproval, QuoteStatus::RevisionRequested],
            array_values($editable)
        );
    }

    public function testEveryStatusHasLabelAndColor(): void
    {
        foreach (QuoteStatus::cases() as $status) {
            self::assertNotSame('', $status->label());
            self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $status->color());
        }
    }
}
