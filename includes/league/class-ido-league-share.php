<?php
if (!defined('ABSPATH')) exit;

/**
 * Splitting a whole number across empires in proportion to their share.
 *
 * Used twice, in opposite directions, and it has to be exact in both:
 *
 * - A defending site pays plunder from the site's totals, apportioned by each
 *   empire's share of the resource being taken.
 * - An attacking site hands out survivors and spoils, apportioned by what each
 *   contributor risked.
 *
 * Proportions are fractional and troops are not. Splitting 97 surviving
 * legionnaires between three empires by rounding each share independently either
 * invents a soldier or loses one, and doing that on every unit type and every
 * resource, on every march, drifts into real money.
 *
 * So: largest remainder. Everybody gets their whole part, and the leftover units
 * go one each to whoever was rounded down hardest. The total handed out is
 * always exactly the total put in, which is asserted rather than hoped for.
 */
class IDO_League_Share {

    /**
     * Splits $total across $weights, keyed however the caller likes.
     *
     * A weight of zero gets nothing. If every weight is zero there is nothing to
     * divide by and nobody has any claim, so nothing is distributed: returning
     * an even split there would hand resources to empires that hold none of the
     * thing being taken.
     *
     * @param array<int|string,float|int> $weights
     * @return array<int|string,int> summing exactly to $total
     */
    public static function split(int $total, array $weights): array {
        $out = [];
        foreach ($weights as $key => $_) $out[$key] = 0;

        if ($total <= 0 || !$weights) return $out;

        $sum = 0.0;
        foreach ($weights as $weight) $sum += max(0.0, (float) $weight);
        if ($sum <= 0.0) return $out;

        $remainders = [];
        $given = 0;
        foreach ($weights as $key => $weight) {
            $exact = $total * (max(0.0, (float) $weight) / $sum);
            $whole = (int) floor($exact);
            $out[$key] = $whole;
            $given += $whole;
            $remainders[$key] = $exact - $whole;
        }

        // The leftover goes to the largest remainders, and ties break on the key
        // so the same inputs always produce the same answer. A split that
        // depended on the order rows came back from the database would be a
        // split two sites could disagree about.
        $left = $total - $given;
        if ($left > 0) {
            $keys = array_keys($remainders);
            usort($keys, static function ($a, $b) use ($remainders, $weights) {
                if ($remainders[$a] !== $remainders[$b]) return $remainders[$b] <=> $remainders[$a];
                if ($weights[$a] !== $weights[$b]) return $weights[$b] <=> $weights[$a];
                return strcmp((string) $a, (string) $b);
            });
            foreach ($keys as $key) {
                if ($left <= 0) break;
                // Never hand a share to somebody with no claim at all, even when
                // there are units spare: a zero weight means they held none of it.
                if ((float) $weights[$key] <= 0.0) continue;
                $out[$key]++;
                $left--;
            }
        }

        return $out;
    }

    /**
     * Splits a whole map of totals by one set of weights.
     *
     * @param array<string,int> $totals
     * @param array<int|string,float|int> $weights
     * @return array<int|string,array<string,int>> keyed by weight key, then by total key
     */
    public static function split_map(array $totals, array $weights): array {
        $out = [];
        foreach ($weights as $key => $_) $out[$key] = [];

        foreach ($totals as $what => $total) {
            foreach (self::split((int) $total, $weights) as $key => $share) {
                $out[$key][$what] = $share;
            }
        }
        return $out;
    }

    /**
     * Whether a split adds up. Cheap, and worth calling before anything is written.
     *
     * A march that cannot reconcile is held for an administrator rather than
     * applied approximately, because "approximately" in a game about counting
     * things is another word for wrong.
     */
    public static function reconciles(int $total, array $shares): bool {
        return array_sum(array_map('intval', $shares)) === $total;
    }
}
