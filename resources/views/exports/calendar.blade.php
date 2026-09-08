<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Content calendar — {{ $cursor->format('F Y') }}</title>
    <style>
        /* DomPDF has its own layout engine and no access to the app stylesheet,
           so this is deliberately self-contained and print-first. */
        @page { margin: 12mm 10mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 8pt;
            color: #333b47;
            margin: 0;
        }

        h1 { font-size: 16pt; color: #12161c; margin: 0 0 2mm; }
        .meta { font-size: 8pt; color: #6b7686; margin: 0 0 5mm; }

        table { width: 100%; border-collapse: collapse; table-layout: fixed; }

        th {
            font-size: 7pt;
            text-transform: uppercase;
            letter-spacing: 0.4pt;
            color: #6b7686;
            text-align: left;
            padding: 2mm 1.5mm;
            background: #f5f7fa;
            border: 0.4pt solid #dde2e9;
        }

        td {
            vertical-align: top;
            height: 26mm;
            padding: 1.5mm;
            border: 0.4pt solid #dde2e9;
            width: 14.28%;
        }

        td.outside { background: #fafbfc; }

        .daynum { font-size: 9pt; font-weight: bold; color: #12161c; }
        .daynum.outside { color: #b9c1cc; font-weight: normal; }

        .event {
            float: right;
            font-size: 6.5pt;
            color: #6b7686;
        }

        .chip {
            margin-top: 1.2mm;
            padding: 1mm 1.2mm;
            border: 0.4pt solid #eaeef3;
            border-left-width: 1.2pt;
            font-size: 6.5pt;
            line-height: 1.25;
        }

        .chip .when { color: #6b7686; }
        .chip .title { color: #12161c; }

        .legend { margin-top: 4mm; font-size: 7pt; color: #6b7686; }
        .legend span { margin-right: 5mm; }
    </style>
</head>
<body>

<h1>{{ $workspace?->name ?? config('app.name') }} — content calendar</h1>
<p class="meta">
    {{ $cursor->format('F Y') }} · all times {{ $tz }} · generated {{ now()->setTimezone($tz)->format('j M Y, H:i') }}
</p>

<table>
    <thead>
        <tr>
            @foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $weekday)
                <th>{{ $weekday }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach (collect($days)->chunk(7) as $week)
            <tr>
                @foreach ($week as $day)
                    @php
                        $inMonth = $day->month === $cursor->month;
                        $dayPosts = $posts->get($day->toDateString(), collect());
                        $dayEvents = $events->filter(fn ($e) => $e->coversDate($day));
                    @endphp

                    <td class="{{ $inMonth ? '' : 'outside' }}">
                        <span class="daynum {{ $inMonth ? '' : 'outside' }}">{{ $day->day }}</span>

                        @if ($dayEvents->isNotEmpty())
                            <span class="event">{{ $dayEvents->first()->name }}</span>
                        @endif

                        @foreach ($dayPosts as $post)
                            @php
                                $colour = match ($post->status->token()) {
                                    'published' => '#17795e',
                                    'partial' => '#b45309',
                                    'failed' => '#c0392f',
                                    'scheduled' => '#0e7c86',
                                    'approved' => '#4f46e5',
                                    'pending' => '#c2820a',
                                    'publishing' => '#7a3ec4',
                                    'cancelled' => '#6b7686',
                                    default => '#8a94a6',
                                };
                            @endphp

                            <div class="chip" style="border-left-color: {{ $colour }};">
                                <div class="when">
                                    {{ $post->scheduled_at->copy()->setTimezone($tz)->format('H:i') }}
                                    · {{ $post->status->label() }}
                                </div>
                                <div class="title">
                                    {{ Str::limit($post->title ?: strip_tags((string) $post->caption), 52) }}
                                </div>
                            </div>
                        @endforeach
                    </td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>

<p class="legend">
    @foreach (\App\Enums\PostStatus::cases() as $status)
        <span>{{ $status->glyph() }} {{ $status->label() }}</span>
    @endforeach
</p>

</body>
</html>
