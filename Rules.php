<?php
final class SessionRules
{
    public static function defaults(): array {
        return ['bot_title'=>'Nashenas', 'bot_title_fa'=>'ناشناس', 'miss_title_fa'=>'میس', 'mister_title_fa'=>'مستر', 'miss_title'=>'Miss', 'mister_title'=>'Mister',
            'tendency_enabled'=>1, 'tendency_seconds'=>1800, 'tendency_title'=>'Preference test', 'tendency_title_fa'=>'تست گرایش',
            'age_required'=>1, 'reply_navigation'=>1, 'onboarding_required'=>1, 'ads_enabled'=>0, 'ads_every_events'=>8, 'ads_cooldown_seconds'=>900,
            'normal_enabled'=>1, 'normal_coins'=>2, 'normal_seconds'=>600, 'queue_seconds'=>600, 'support_username'=>'navid_dehnavi',
            'initial_coins'=>5, 'initial_minutes'=>10, 'interval_coins'=>1, 'interval_minutes'=>2,
            'provider_leave_minutes'=>5, 'early_refund_coins'=>3, 'info_exchange_coins'=>100, 'stars_pack'=>50, 'coins_pack'=>50,
            'response_seconds'=>120, 'silence_seconds'=>300, 'unanswered_limit'=>3, 'strike_limit'=>3, 'online_seconds'=>300,
            'preferences'=>'Friendly conversation,Roleplay,Shared interests',
            'preferences_fa'=>'گفت‌وگوی دوستانه,نقش‌آفرینی,علاقه‌مندی‌های مشترک',
            'welcome_fa'=>'خوشحالیم اینجایی 💛 از پایین انتخاب کن دلت چه گفت‌وگویی می‌خواد.', 'support_fa'=>'اگر دربارهٔ حسابت یا پرداخت سؤالی داری، به پشتیبانی پیام بده؛ با هم بررسیش می‌کنیم 💛',
            'welcome'=>'Glad you are here 💛 Choose a conversation that feels right.', 'support'=>'Need help with your account or a payment? Message support and we will take a look 💛',
            'moderation_block_phones'=>1, 'moderation_block_links'=>1, 'moderation_block_ids'=>1,
            'moderation_block_forwards'=>1, 'moderation_block_contacts'=>1,
            'paid_enabled'=>1, 'test_pack_enabled'=>1, 'test_stars'=>5, 'test_coins'=>100];
    }
    public static function validate(array $s): void {
        foreach (self::defaults() as $k=>$v) {
            if (is_int($v) && (!isset($s[$k]) || !preg_match('/^\d+$/', (string)$s[$k]) || (int)$s[$k] > 100000 || (int)$s[$k] < ((in_array($k,['normal_enabled','paid_enabled','test_pack_enabled','tendency_enabled','reply_navigation','onboarding_required','ads_enabled','age_required'],true) || str_starts_with($k,'moderation_block_'))?0:1))) throw new InvalidArgumentException("Invalid value for $k");
        }
        foreach(['normal_enabled','paid_enabled','test_pack_enabled','tendency_enabled','reply_navigation','onboarding_required','ads_enabled','age_required','moderation_block_phones','moderation_block_links','moderation_block_ids','moderation_block_forwards','moderation_block_contacts'] as $flag) {
            if ($s[$flag]>1) throw new InvalidArgumentException('paid_enabled must be 0 or 1');
        }
        if ($s['silence_seconds'] < $s['response_seconds']) throw new InvalidArgumentException('Silence timeout must be at least the response timeout');
        if ($s['provider_leave_minutes'] > $s['initial_minutes']) throw new InvalidArgumentException('Provider leave time must be within the initial period');
        if ($s['early_refund_coins'] > $s['initial_coins']) throw new InvalidArgumentException('Refund cannot exceed initial price');
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/',(string)($s['support_username']??''))) throw new InvalidArgumentException('Enter a valid Telegram support username');
        foreach (['bot_title','miss_title','mister_title','bot_title_fa','miss_title_fa','mister_title_fa','tendency_title','tendency_title_fa'] as $k) if (mb_strlen($s[$k])<1 || mb_strlen($s[$k])>60) throw new InvalidArgumentException('Titles must be 1–60 characters');
        foreach (['preferences','preferences_fa'] as $preferenceKey) {
            $options=array_map('trim',explode(',',$s[$preferenceKey]));
            foreach ($options as $label) {
                if (mb_strlen($label)<1 || mb_strlen($label)>60) throw new InvalidArgumentException('Preference labels must be 1–60 characters');
            }
            if (count(array_unique($options))!==count($options)) throw new InvalidArgumentException('Preference labels must be unique');
            if (mb_strlen($s[$preferenceKey])>1000 || count($options)>20) throw new InvalidArgumentException('Use at most 20 preferences, 1000 characters total');
        }
    }

    public static function closeRefund(array $s, int $elapsed, string $who): int {
        return $who==='provider' && $elapsed < $s['initial_minutes']*60 ? (int)$s['early_refund_coins'] : 0;
    }
    public static function unusedRefund(array $s, int $started, int $paidUntil, int $charged, int $now): int {
        if ($now < $started + $s['initial_minutes']*60) {
            return min($charged, (int)ceil($s['initial_coins'] * max(0,$started+$s['initial_minutes']*60-$now)/($s['initial_minutes']*60)));
        }
        return min($charged, (int)ceil($s['interval_coins'] * max(0,$paidUntil-$now)/($s['interval_minutes']*60)));
    }
}
