<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Workspace extends Model
{
    /** @use HasFactory<\Database\Factories\WorkspaceFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'timezone',
        'logo_path',
        'settings',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    /**
     * A workspace preference, with a fallback.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function mergeSettings(array $values): void
    {
        $this->settings = array_replace_recursive($this->settings ?? [], $values);
        $this->save();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function credential(): HasOne
    {
        return $this->hasOne(AppCredential::class);
    }

    public function notificationsSetting(): HasOne
    {
        return $this->hasOne(NotificationsSetting::class);
    }

    public function captionTemplates(): HasMany
    {
        return $this->hasMany(CaptionTemplate::class);
    }

    public function hashtagSets(): HasMany
    {
        return $this->hasMany(HashtagSet::class);
    }

    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }

    /**
     * The brand footer appended at publish time.
     *
     * Stored as a template rather than pasted into every caption, so changing a
     * phone number changes it everywhere at once.
     */
    public function brandFooter(): ?CaptionTemplate
    {
        return $this->captionTemplates()->where('is_footer', true)->first();
    }
}
