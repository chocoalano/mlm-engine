<?php

declare(strict_types=1);

namespace PandaPanel\Widgets\Enums;

/**
 * What a movement *means*, which is not what direction it went in.
 *
 * A stat's trend carries a direction — the number went up, or down, or stayed
 * where it was. That is arithmetic and the widget can work it out. Whether the
 * movement is welcome is not arithmetic: revenue rising is good news and cost
 * rising is not, and nothing about `+8%` distinguishes them.
 *
 * The widget used to answer that question anyway, by assuming up is good. For
 * a dashboard of revenue and signups that assumption is invisible; for one
 * showing error rate, churn, downtime or complaints it is exactly backwards,
 * and it is backwards in green.
 *
 * A closed set because the frontend maps each case to a semantic colour token,
 * and because a free-form string is how `trend()`'s direction argument came to
 * accept anything at all.
 */
enum StatSentiment: string
{
    /** The movement is welcome, whichever way it went. */
    case Positive = 'positive';

    /** The movement is unwelcome, whichever way it went. */
    case Negative = 'negative';

    /**
     * The movement has no inherent meaning, or none was declared.
     *
     * The default, and deliberately: a figure whose meaning nobody stated is
     * a figure whose meaning is not known, and colouring it green because the
     * number rose is a claim the widget is not entitled to make.
     */
    case Neutral = 'neutral';

    /**
     * What a movement means when the author said which way is better.
     *
     * `up`/`down` describe the number; `higherIsBetter` describes the metric.
     * Together they answer the question the colour is really asking.
     */
    public static function forDirection(
        string $direction,
        bool $higherIsBetter,
    ): self {
        // Anything that is not a movement has no meaning to report —
        // including a direction nobody recognises. `trend()` takes a plain
        // string, so "sideways" can reach here, and guessing at it would put
        // a colour on a figure whose direction is not even understood.
        if ($direction !== 'up' && $direction !== 'down') {
            return self::Neutral;
        }

        $roseAndShould = $direction === 'up' && $higherIsBetter;
        $fellAndShould = $direction === 'down' && ! $higherIsBetter;

        return $roseAndShould || $fellAndShould
            ? self::Positive
            : self::Negative;
    }
}
