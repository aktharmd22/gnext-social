<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CalendarEventKind;
use App\Enums\InsightWindow;
use App\Enums\Platform;
use App\Enums\PostSource;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Enums\Role;
use App\Enums\TargetStatus;
use App\Models\CalendarEvent;
use App\Models\CaptionTemplate;
use App\Models\HashtagSet;
use App\Models\NotificationsSetting;
use App\Models\Post;
use App\Models\PostInsight;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $workspace = Workspace::firstOrCreate(
            ['slug' => 'gnextsocial'],
            [
                'name' => 'GnextSocial',
                'timezone' => 'Asia/Dubai',
                'is_active' => true,
            ]
        );

        $this->users($workspace);
        $this->notifications($workspace);
        $this->templates($workspace);
        $this->hashtags($workspace);
        $this->calendar($workspace);

        $accounts = $this->accounts($workspace);
        $this->samplePosts($workspace, $accounts);

        $this->command?->info('Seeded workspace "'.$workspace->name.'".');
        $this->command?->line('  admin@gnextsocial.test / password');
        $this->command?->line('  writer@gnextsocial.test / password');
    }

    private function users(Workspace $workspace): void
    {
        User::firstOrCreate(
            ['email' => 'admin@gnextsocial.test'],
            [
                'workspace_id' => $workspace->id,
                'name' => 'Amal Rahman',
                'password' => 'password',
                'role' => Role::Admin,
                'timezone' => 'Asia/Dubai',
                'is_active' => true,
            ]
        );

        User::firstOrCreate(
            ['email' => 'writer@gnextsocial.test'],
            [
                'workspace_id' => $workspace->id,
                'name' => 'Priya Nair',
                'password' => 'password',
                'role' => Role::User,
                'timezone' => 'Asia/Dubai',
                'is_active' => true,
            ]
        );
    }

    private function notifications(Workspace $workspace): void
    {
        NotificationsSetting::firstOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'email_on_failure' => true,
                'email_on_publish' => false,
                'alert_recipients' => ['admin@gnextsocial.test'],
            ]
        );
    }

    private function templates(Workspace $workspace): void
    {
        $templates = [
            [
                'name' => 'Brand footer',
                'is_footer' => true,
                'body' => "📞 +971 4 555 0132 | 🌐 gnextsocial.ae | 📍 Dubai",
            ],
            [
                'name' => 'Product launch',
                'is_footer' => false,
                'body' => "Introducing {product}.\n\n{one_line_benefit}\n\nAvailable now, in store and online.",
            ],
            [
                'name' => 'Weekend offer',
                'is_footer' => false,
                'body' => "This weekend only: {offer}.\n\nValid {dates} across all branches. Terms apply.",
            ],
        ];

        foreach ($templates as $template) {
            CaptionTemplate::firstOrCreate(
                ['workspace_id' => $workspace->id, 'name' => $template['name']],
                [
                    'body' => $template['body'],
                    'is_footer' => $template['is_footer'],
                    'language' => 'en',
                ]
            );
        }
    }

    private function hashtags(Workspace $workspace): void
    {
        $sets = [
            'UAE general' => ['dubai', 'uae', 'mydubai', 'abudhabi', 'sharjah', 'dubailife', 'uaebusiness'],
            'Retail offers' => ['sale', 'offer', 'dubaideals', 'shopping', 'discount', 'uaeoffers', 'weekenddeals'],
        ];

        foreach ($sets as $name => $tags) {
            HashtagSet::firstOrCreate(
                ['workspace_id' => $workspace->id, 'name' => $name],
                ['tags' => $tags]
            );
        }
    }

    /**
     * The UAE overlay.
     *
     * Religious dates are marked approximate because they are confirmed by moon
     * sighting; showing a provisional Eid as though it were fixed would be worse
     * than showing it as provisional.
     */
    private function calendar(Workspace $workspace): void
    {
        $events = [
            ['Ramadan', 'رمضان', '2026-02-17', '2026-03-19', CalendarEventKind::Religious, true],
            ['Eid al-Fitr', 'عيد الفطر', '2026-03-20', '2026-03-22', CalendarEventKind::Religious, true],
            ['Eid al-Adha', 'عيد الأضحى', '2026-05-26', '2026-05-29', CalendarEventKind::Religious, true],
            ['Peak summer', null, '2026-06-15', '2026-08-31', CalendarEventKind::Season, false],
            ['Back to school', null, '2026-08-24', '2026-09-11', CalendarEventKind::Retail, false],
            ['UAE National Day', 'اليوم الوطني', '2026-12-02', '2026-12-03', CalendarEventKind::National, false],

            ['Ramadan', 'رمضان', '2027-02-07', '2027-03-08', CalendarEventKind::Religious, true],
            ['Eid al-Fitr', 'عيد الفطر', '2027-03-09', '2027-03-11', CalendarEventKind::Religious, true],
            ['Eid al-Adha', 'عيد الأضحى', '2027-05-16', '2027-05-19', CalendarEventKind::Religious, true],
        ];

        foreach ($events as [$name, $nameAr, $start, $end, $kind, $approximate]) {
            CalendarEvent::firstOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'name' => $name,
                    'starts_on' => $start,
                ],
                [
                    'name_ar' => $nameAr,
                    'ends_on' => $end,
                    'kind' => $kind,
                    'is_approximate' => $approximate,
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * Placeholder destinations so the calendar has platform glyphs on day one.
     *
     * These carry dummy tokens and are replaced the moment an admin runs the
     * real OAuth connect flow. Expiry is set far enough out that they do not
     * trigger the expiring-token banner on a fresh install.
     *
     * @return array{facebook: SocialAccount, instagram: SocialAccount}
     */
    private function accounts(Workspace $workspace): array
    {
        $facebook = SocialAccount::firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'platform' => Platform::Facebook,
                'page_id' => '000000000000001',
            ],
            [
                'ig_user_id' => null,
                'name' => 'Sample Page',
                'username' => 'samplepage',
                'access_token' => 'seed-placeholder-not-a-real-token',
                'token_type' => 'page',
                'token_expires_at' => now()->addDays(60),
                'scopes' => ['pages_show_list', 'pages_manage_posts', 'pages_read_engagement'],
                'is_active' => true,
            ]
        );

        $instagram = SocialAccount::firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'platform' => Platform::Instagram,
                'ig_user_id' => '000000000000002',
            ],
            [
                'page_id' => '000000000000001',
                'name' => 'Sample Page',
                'username' => 'samplepage',
                'access_token' => 'seed-placeholder-not-a-real-token',
                'token_type' => 'page',
                'token_expires_at' => now()->addDays(60),
                'scopes' => ['instagram_basic', 'instagram_content_publish'],
                'is_active' => true,
            ]
        );

        return ['facebook' => $facebook, 'instagram' => $instagram];
    }

    /**
     * Twelve posts across the current month in mixed statuses, so the calendar
     * shows what it looks like in use rather than an empty grid.
     *
     * @param  array{facebook: SocialAccount, instagram: SocialAccount}  $accounts
     */
    private function samplePosts(Workspace $workspace, array $accounts): void
    {
        if (Post::where('workspace_id', $workspace->id)->exists()) {
            return;
        }

        $author = User::where('email', 'writer@gnextsocial.test')->firstOrFail();
        $admin = User::where('email', 'admin@gnextsocial.test')->firstOrFail();

        $tz = $workspace->timezone;
        $month = Carbon::now($tz)->startOfMonth();

        // day-of-month, hour, type, status, title
        $plan = [
            [2, 9, PostType::Post, PostStatus::Published, 'Back-to-school range is in'],
            [4, 19, PostType::Reel, PostStatus::Published, 'Store walkthrough reel'],
            [6, 11, PostType::Carousel, PostStatus::Published, 'Five desk setups under AED 500'],
            [7, 20, PostType::Post, PostStatus::PartiallyPublished, 'Weekend offer announcement'],
            [9, 9, PostType::Post, PostStatus::Failed, 'Customer story: Al Quoz branch'],
            [11, 13, PostType::Story, PostStatus::Scheduled, 'Behind the counter'],
            [14, 9, PostType::Post, PostStatus::Scheduled, 'New arrivals, week 3'],
            [16, 18, PostType::Reel, PostStatus::Approved, 'How we pack an online order'],
            [18, 10, PostType::Post, PostStatus::PendingApproval, 'Team spotlight: Reem'],
            [21, 9, PostType::Carousel, PostStatus::PendingApproval, 'Desk accessories edit'],
            [24, 17, PostType::Post, PostStatus::Draft, 'Autumn campaign teaser'],
            [27, 9, PostType::Post, PostStatus::Draft, 'Month wrap-up'],
        ];

        foreach ($plan as [$day, $hour, $type, $status, $title]) {
            $scheduledLocal = $month->copy()->day(min($day, $month->daysInMonth))->setTime($hour, 0);

            $post = Post::create([
                'workspace_id' => $workspace->id,
                'title' => $title,
                'caption' => $this->sampleCaption($title),
                'type' => $type,
                'append_brand_footer' => true,
                // Stored UTC. The local time above is what a human chose.
                'scheduled_at' => $scheduledLocal->copy()->utc(),
                'published_at' => in_array($status, [
                    PostStatus::Published,
                    PostStatus::PartiallyPublished,
                ], true) ? $scheduledLocal->copy()->utc() : null,
                'status' => $status,
                'created_by' => $author->id,
                'approved_by' => in_array($status, [
                    PostStatus::Approved,
                    PostStatus::Scheduled,
                    PostStatus::Published,
                    PostStatus::PartiallyPublished,
                    PostStatus::Failed,
                ], true) ? $admin->id : null,
                'approved_at' => in_array($status, [
                    PostStatus::Approved,
                    PostStatus::Scheduled,
                    PostStatus::Published,
                    PostStatus::PartiallyPublished,
                    PostStatus::Failed,
                ], true) ? $scheduledLocal->copy()->subDays(2)->utc() : null,
                'source' => PostSource::Manual,
            ]);

            $this->sampleTargets($post, $accounts, $status);
        }
    }

    /**
     * @param  array{facebook: SocialAccount, instagram: SocialAccount}  $accounts
     */
    private function sampleTargets(Post $post, array $accounts, PostStatus $status): void
    {
        // partially_published is the case a single status column cannot express:
        // Facebook succeeded, Instagram did not.
        $pairs = match ($status) {
            PostStatus::Published => [
                ['facebook', TargetStatus::Published, null],
                ['instagram', TargetStatus::Published, null],
            ],
            PostStatus::PartiallyPublished => [
                ['facebook', TargetStatus::Published, null],
                ['instagram', TargetStatus::Failed, 'The image is 1:1. Instagram Reels need 9:16.'],
            ],
            PostStatus::Failed => [
                ['facebook', TargetStatus::Failed, 'The access token for Sample Page expired.'],
                ['instagram', TargetStatus::Failed, 'The access token for Sample Page expired.'],
            ],
            // A post submitted for approval has already had its destinations
            // chosen; only a draft legitimately has none yet.
            PostStatus::Scheduled, PostStatus::Approved, PostStatus::PendingApproval => [
                ['facebook', TargetStatus::Queued, null],
                ['instagram', TargetStatus::Queued, null],
            ],
            default => [],
        };

        foreach ($pairs as [$key, $targetStatus, $error]) {
            $target = PostTarget::create([
                'post_id' => $post->id,
                'social_account_id' => $accounts[$key]->id,
                'status' => $targetStatus,
                'attempts' => $targetStatus === TargetStatus::Failed ? 3 : ($targetStatus === TargetStatus::Published ? 1 : 0),
                'error_message' => $error,
                'error_code' => $error ? 'sample' : null,
                'published_at' => $targetStatus === TargetStatus::Published ? $post->scheduled_at : null,
                'last_attempt_at' => $targetStatus->isPending() ? null : $post->scheduled_at,
            ]);

            if ($targetStatus === TargetStatus::Published) {
                $this->sampleInsight($target);
            }
        }
    }

    /**
     * Plausible metrics for a published sample, so the insights dashboard and
     * the engagement heatmap show something on a fresh install rather than an
     * empty state nobody can evaluate.
     */
    private function sampleInsight(PostTarget $target): void
    {
        $reach = random_int(1_800, 6_400);
        $likes = (int) round($reach * (random_int(20, 70) / 1000));
        $comments = (int) round($likes * 0.08);
        $shares = (int) round($likes * 0.05);
        $saves = (int) round($likes * 0.12);

        $engagements = $likes + $comments + $shares + $saves;

        PostInsight::create([
            'post_target_id' => $target->id,
            'window' => InsightWindow::Day,
            'captured_at' => $target->published_at?->copy()->addDay() ?? now(),
            'impressions' => (int) round($reach * 1.35),
            'reach' => $reach,
            'likes' => $likes,
            'comments' => $comments,
            'shares' => $shares,
            'saves' => $saves,
            'engagement_rate' => round(($engagements / $reach) * 100, 4),
        ]);
    }

    private function sampleCaption(string $title): string
    {
        return $title."\n\n"
            ."Sample copy seeded so the calendar shows what it looks like in use. "
            ."Replace it, or delete these twelve posts once you have your own.";
    }
}
