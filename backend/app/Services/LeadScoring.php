<?php

namespace App\Services;

use App\Models\Lead;

/**
 * 0–100 lead score so agents work the hottest leads first. Transparent rules (shown in the UI tooltip):
 * source quality + contactability + clear brief + pipeline progress + consent, decaying while untouched.
 */
class LeadScoring
{
    public const SOURCE = ['referral' => 30, 'walk_in' => 24, 'website' => 20, 'portal' => 18, 'whatsapp' => 16];

    public const STATUS = ['new' => 0, 'contacted' => 10, 'qualified' => 25, 'converted' => 0, 'lost' => 0];

    public static function score(Lead $l): int
    {
        if ($l->status === 'converted') {
            return 100;
        }
        if ($l->status === 'lost') {
            return 0;
        }
        $s = self::SOURCE[$l->source] ?? 15;
        $s += $l->phone ? 15 : 0;
        $s += $l->email ? 10 : 0;
        $s += mb_strlen((string) $l->interest_description) >= 8 ? 10 : 0;
        $s += self::STATUS[$l->status] ?? 0;
        $s += $l->marketing_consent ? 5 : 0;
        $since = ($l->last_contacted_at ?? $l->created_at ?? now());
        $s -= min(25, intdiv((int) $since->diffInDays(now()), 3)); // cools off while nobody follows up

        return max(1, min(99, $s));
    }

    /** Recompute every lead's score (scores cool off with time, so the daily job refreshes them). Returns rows changed. */
    public static function rescoreAll(): int
    {
        $changed = 0;
        Lead::query()->chunkById(500, function ($leads) use (&$changed) {
            foreach ($leads as $l) {
                $score = self::score($l);
                if ($score !== (int) $l->score) {
                    Lead::whereKey($l->id)->update(['score' => $score]); // query update: no audit noise, no saving hook
                    $changed++;
                }
            }
        });

        return $changed;
    }

    public static function explain(): string
    {
        return 'Source (referral 30, walk-in 24, website 20, portal 18, WhatsApp 16) + phone 15 + email 10 + clear brief 10 '
            .'+ contacted 10 / qualified 25 + POPIA consent 5, minus 1 point per 3 days without contact (max −25).';
    }

    /** Normalise SA numbers for duplicate checks: "+27 82 441 0098" / "082 441 0098" → "824410098". */
    public static function phoneKey(?string $phone): ?string
    {
        $d = preg_replace('/\D/', '', (string) $phone);
        if ($d === '') {
            return null;
        }

        return substr($d, -9);
    }
}
