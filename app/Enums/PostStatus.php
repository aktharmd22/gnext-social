<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of a post, as seen by a human.
 *
 * This is a derived value. `post_targets.status` is the source of truth for what
 * actually happened at Meta; this enum summarises those rows into one word for
 * the calendar. When targets disagree -- Facebook published, Instagram failed --
 * the summary is PartiallyPublished, never Published.
 *
 * Colour alone never carries status in the UI. Every case supplies a glyph and a
 * label so a chip stays readable in greyscale and to colour-blind users.
 */
enum PostStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case PartiallyPublished = 'partially_published';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
            self::Scheduled => 'Scheduled',
            self::Publishing => 'Publishing',
            self::Published => 'Published',
            self::PartiallyPublished => 'Partly published',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The non-colour half of the status signal.
     */
    public function glyph(): string
    {
        return match ($this) {
            self::Draft => '○',
            self::PendingApproval => '◐',
            self::Approved => '✓',
            self::Scheduled => '◷',
            self::Publishing => '◍',
            self::Published => '●',
            self::PartiallyPublished => '◑',
            self::Failed => '✕',
            self::Cancelled => '⊘',
        };
    }

    /**
     * Design token stem: `draft` resolves to --color-draft / --color-draft-soft.
     */
    public function token(): string
    {
        return match ($this) {
            self::Draft => 'draft',
            self::PendingApproval => 'pending',
            self::Approved => 'approved',
            self::Scheduled => 'scheduled',
            self::Publishing => 'publishing',
            self::Published => 'published',
            self::PartiallyPublished => 'partial',
            self::Failed => 'failed',
            self::Cancelled => 'cancelled',
        };
    }

    /**
     * Nothing further will happen to this post without a human acting.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Published, self::Cancelled], true);
    }

    /**
     * Whether the composer may still change the caption or media.
     *
     * Publishing is excluded deliberately: a job is mid-flight at Meta and an
     * edit now would publish content nobody reviewed.
     */
    public function isEditable(): bool
    {
        return in_array($this, [
            self::Draft,
            self::PendingApproval,
            self::Approved,
            self::Scheduled,
            self::Failed,
        ], true);
    }

    /**
     * Whether the chip may be dragged to a new day on the calendar.
     */
    public function isReschedulable(): bool
    {
        return $this->isEditable();
    }

    /**
     * Statuses that mean "this is going out, or tried to".
     */
    public function hasLeftTheBuilding(): bool
    {
        return in_array($this, [
            self::Publishing,
            self::Published,
            self::PartiallyPublished,
            self::Failed,
        ], true);
    }

    /**
     * @return array<int, self>
     */
    public static function selectable(): array
    {
        return self::cases();
    }
}
