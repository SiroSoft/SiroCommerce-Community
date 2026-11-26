<?php

declare(strict_types=1);

namespace App\Modules\Smart\Application;

/**
 * Lexicon review scorer (spec SMART_SKILLS_SPEC §3): no models, versioned
 * word lists, negation window, verbatim excerpts only. The upgrade path to a
 * PhoBERT microservice keeps this interface — see ReviewScorer.
 *
 * VI coverage: ~100 accented entries (single words + phrases) across
 * material/design/size/price/service; unaccented input is folded
 * automatically, so "vai dep" scores like "vải đẹp".
 */
final class SmartLexiconScorer implements ReviewScorer
{
    /** @var list<string> */
    private const POSITIVE_EN = ['good', 'great', 'excellent', 'amazing', 'love', 'perfect', 'beautiful', 'comfortable', 'soft', 'durable', 'quality', 'worth', 'happy', 'satisfied', 'recommend', 'best', 'nice', 'smooth', 'light', 'fresh', 'effective', 'gentle', 'fast', 'sturdy', 'elegant', 'gorgeous', 'wonderful', 'fantastic', 'awesome', 'pleased', 'authentic', 'real'];
    /** @var list<string> */
    private const NEGATIVE_EN = ['bad', 'poor', 'terrible', 'awful', 'hate', 'broken', 'cheap', 'rough', 'hard', 'uncomfortable', 'disappointed', 'waste', 'worst', 'ugly', 'tight', 'loose', 'small', 'large', 'smell', 'itchy', 'scratchy', 'slow', 'damaged', 'defective', 'wrong', 'missing', 'late', 'rude', 'horrible', 'disgusting', 'fake'];
    /** @var list<string> */
    private const POSITIVE_VI = [
        'tốt', 'tuyệt', 'xinh', 'thích', 'mê', 'yêu', 'đẹp', 'sang', 'bền', 'chắc', 'xịn', 'mềm', 'mịn', 'êm', 'mát', 'nhẹ', 'thơm', 'tươi', 'sạch', 'vừa',
        'rẻ', 'đáng', 'nhanh', 'gọn', 'tiện', 'chu đáo', 'tận tâm', 'dễ', 'oke', 'ok', 'ổn', 'ngon', 'bổ', 'chuẩn', 'đúng', 'giống', 'cẩn thận', 'kỹ', 'bóng', 'sáng', 'mới',
        'mượt', 'thoáng', 'ấm', 'dịu', 'lành', 'yên tâm', 'uy tín', 'hài lòng', 'ưng', 'nhiệt tình', 'dày dặn', 'mềm mại', 'co giãn', 'co dãn', 'ôm dáng', 'thoáng mát', 'mát mẻ',
        'tuyệt vời', 'tuyệt hảo', 'hoàn hảo', 'yêu thích', 'ưng ý', 'chắc chắn', 'xịn xò', 'sang trọng', 'sạch sẽ', 'vừa vặn', 'vừa ý', 'rẻ đẹp', 'xứng đáng', 'đáng tiền', 'đáng mua',
        'hiệu quả', 'gọn nhẹ', 'tiện lợi', 'nhiệt tình', 'dễ dùng', 'dễ thương', 'tạm ổn', 'ngon lành', 'bổ rẻ', 'ngon bổ rẻ', 'đúng mô tả', 'giống hình', 'giống mô tả', 'y hình',
        'đúng hẹn', 'giao nhanh', 'nhẹ nhàng', 'dịu nhẹ', 'lành tính', 'an toàn', 'tin tưởng', 'chính hãng', 'chuẩn auth', 'sẽ mua lại', 'ủng hộ', 'mua tiếp', 'rất ưng', 'quá ưng',
        'óng ả', 'mướt', 'thấm hút tốt', 'lên dáng đẹp', 'lên form đẹp', 'đóng gói cẩn thận', 'gói cẩn thận', 'giao đúng hẹn', 'shop tư vấn nhiệt tình',
        'chất lượng', 'giới thiệu', 'nhất', 'tốt nhất', 'đẹp nhất', 'rẻ nhất',
    ];
    /** @var list<string> */
    private const NEGATIVE_VI = [
        'tệ', 'tồi', 'dở', 'dỏm', 'đểu', 'lởm', 'xấu', 'kém', 'ghét', 'hôi', 'thối', 'ngứa', 'rát', 'hỏng', 'hư', 'bể', 'vỡ', 'rách', 'nứt', 'sờn', 'xù', 'phai', 'rụng', 'bong', 'tróc',
        'gãy', 'móp', 'méo', 'lệch', 'lỏng', 'chật', 'rộng', 'ngắn', 'nhỏ', 'bé', 'to', 'mỏng', 'nóng', 'bí', 'thô', 'ráp', 'cứng', 'cộm', 'thất vọng', 'phí', 'uổng', 'tiếc', 'hố', 'hớ',
        'đắt', 'mắc', 'chát', 'chém', 'lừa', 'nhái', 'giả', 'sai', 'lộn', 'nhầm', 'thiếu', 'thừa', 'trễ', 'muộn', 'chậm', 'lâu', 'hủy', 'bom', 'mùi', 'bẩn', 'dơ', 'ố', 'đau', 'đơ', 'lag', 'giật',
        'kém chất lượng', 'rẻ tiền', 'khó chịu', 'phí tiền', 'cắt cổ', 'lừa đảo', 'sai màu', 'sai size', 'gửi thiếu', 'giao thiếu', 'gửi sai', 'giao sai', 'gửi nhầm', 'thiếu hàng', 'thiếu đồ',
        'dị ứng', 'nổi mẩn', 'xù lông', 'phai màu', 'ra màu', 'đổ lông', 'bong tróc', 'lỏng lẻo', 'thùng thình', 'mùi hôi', 'mùi nhựa', 'mùi hóa chất', 'ố vàng', 'xỉn màu', 'hao pin',
        'nóng máy', 'chai pin', 'đứng máy', 'bom hàng', 'thái độ', 'cọc cằn', 'không nghe máy', 'khó liên lạc', 'treo đầu dê bán thịt chó', 'tiền mất tật mang', 'hàng mã', 'vải đểu',
    ];
    /** @var list<string> */
    private const NEGATIONS = ['not', 'no', 'never', "don't", "doesn't", 'không', 'chưa', 'chẳng', 'đừng', 'chả'];
    /** @var list<string> clause breaks stop a negation from leaking across */
    private const CLAUSE_BREAKS = [',', 'but', 'however', 'although', 'nhưng', 'tuy', 'mặc dù'];
    /** @var array<string, list<string>> aspect keyword buckets */
    private const ASPECTS = [
        'quality' => ['quality', 'durable', 'bền', 'chất lượng', 'dỏm', 'hỏng', 'vỡ', 'rách', 'defective', 'broken', 'sturdy', 'chắc chắn', 'kém chất lượng', 'xịn', 'dày dặn', 'sờn', 'bong tróc'],
        'comfort' => ['comfortable', 'comfort', 'soft', 'êm', 'mềm', 'khó chịu', 'uncomfortable', 'itchy', 'ngứa', 'smooth', 'mịn', 'mềm mại', 'thoáng mát', 'co giãn', 'ôm dáng', 'cộm', 'rát'],
        'size' => ['size', 'fit', 'tight', 'loose', 'small', 'large', 'chật', 'rộng', 'nhỏ', 'to', 'vừa', 'kích', 'vừa vặn', 'sai size', 'thùng thình', 'lên dáng đẹp', 'lên form đẹp'],
        'price' => ['price', 'worth', 'cheap', 'expensive', 'giá', 'rẻ', 'đắt', 'xứng đáng', 'phí', 'waste', 'value', 'đáng tiền', 'đáng mua', 'rẻ đẹp', 'chát', 'mắc', 'phí tiền'],
        'delivery' => ['delivery', 'shipping', 'fast', 'slow', 'late', 'giao', 'ship', 'nhanh', 'chậm', 'trễ', 'đóng gói', 'packaging', 'giao nhanh', 'giao đúng hẹn', 'giao thiếu', 'giao sai', 'đúng hẹn', 'muộn', 'đóng gói cẩn thận'],
        'scent' => ['smell', 'scent', 'fresh', 'mùi', 'hôi', 'thơm', 'fragrance', 'mùi hôi', 'mùi nhựa', 'thối'],
        'service' => ['service', 'support', 'staff', 'dịch vụ', 'nhân viên', 'tư vấn', 'rude', 'helpful', 'thái độ', 'shop tư vấn nhiệt tình', 'nhiệt tình', 'không nghe máy', 'khó liên lạc', 'uy tín'],
    ];

    /** @var array<string, true>|null folded (unaccented) lookup sets, built once */
    private static ?array $foldedPos = null;
    /** @var array<string, true>|null */
    private static ?array $foldedNeg = null;

    /**
     * @param list<array{rating: int, body: string}> $reviews
     * @return array{rating_avg: float, pos_count: int, neg_count: int, aspects: array<string, array{pos: int, neg: int}>, excerpts: array{positive: ?string, negative: ?string}}
     */
    public function rollup(array $reviews): array
    {
        $ratings = [];
        $pos = 0;
        $neg = 0;
        $aspects = [];
        foreach (array_keys(self::ASPECTS) as $aspect) $aspects[$aspect] = ['pos' => 0, 'neg' => 0];
        $bestPos = null;
        $bestNeg = null;
        $bestPosScore = 0;
        $bestNegScore = 0;
        foreach ($reviews as $review) {
            $rating = (int) ($review['rating'] ?? 0);
            if ($rating >= 1 && $rating <= 5) $ratings[] = $rating;
            $body = (string) ($review['body'] ?? '');
            $score = $this->score($body);
            if ($score > 0) {
                $pos++;
                if ($score > $bestPosScore && ($excerpt = $this->excerpt($body)) !== null) {
                    $bestPosScore = $score;
                    $bestPos = $excerpt;
                }
            } elseif ($score < 0) {
                $neg++;
                if (-$score > $bestNegScore && ($excerpt = $this->excerpt($body)) !== null) {
                    $bestNegScore = -$score;
                    $bestNeg = $excerpt;
                }
            }
            $tokens = $this->tokens($body);
            // Folded aspect lookup covers unaccented input only; accented
            // tokens match raw keywords to avoid chất/chát-style merges.
            $plainSet = [];
            foreach ($tokens as $token) {
                if ($this->asciiOnly($token)) $plainSet[$token] = true;
            }
            foreach (self::ASPECTS as $aspect => $keywords) {
                $hit = false;
                foreach ($keywords as $keyword) {
                    if (str_contains($keyword, ' ')) {
                        if ($this->phraseHits($body, [$keyword]) > 0) {
                            $hit = true;
                            break;
                        }
                        continue;
                    }
                    if (in_array(mb_strtolower($keyword), $tokens, true) || isset($plainSet[$this->fold($keyword)])) {
                        $hit = true;
                        break;
                    }
                }
                if ($hit) {
                    if ($score > 0) $aspects[$aspect]['pos']++;
                    elseif ($score < 0) $aspects[$aspect]['neg']++;
                }
            }
        }
        $aspects = array_filter($aspects, static fn (array $counts): bool => $counts['pos'] > 0 || $counts['neg'] > 0);
        return [
            'rating_avg' => $ratings === [] ? 0.0 : round(array_sum($ratings) / count($ratings), 1),
            'pos_count' => $pos,
            'neg_count' => $neg,
            'aspects' => $aspects,
            'excerpts' => ['positive' => $bestPos, 'negative' => $bestNeg],
        ];
    }

    public function score(string $text): int
    {
        $tokens = $this->tokens($text);
        $folded = $this->foldedTokens($tokens);
        $score = 0;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $polarity = $this->wordPolarity($token, $folded[$i] ?? '');
            if ($polarity !== 0) {
                // Negation reaches back at most 2 tokens and never crosses a
                // clause break, so "not good, very disappointed" scores -2.
                for ($j = $i - 1; $j >= max(0, $i - 2); $j--) {
                    if ($this->isBreak($tokens[$j], $folded[$j] ?? '')) break;
                    if ($this->isNegation($tokens[$j], $folded[$j] ?? '')) {
                        $polarity = -$polarity;
                        break;
                    }
                }
                $score += $polarity;
            }
        }
        // Multi-word entries (tuyệt vời, giao đúng hẹn, ...) never survive
        // tokenization, so match them as phrases with the same negation rule.
        $score += $this->phraseScore($text, true);
        $score += $this->phraseScore($text, false);
        return $score;
    }

    private function wordPolarity(string $token, string $plain): int
    {
        if (str_contains($token, ' ')) return 0;
        if (in_array($token, self::POSITIVE_EN, true) || in_array($token, self::POSITIVE_VI, true)) return 1;
        if (in_array($token, self::NEGATIVE_EN, true) || in_array($token, self::NEGATIVE_VI, true)) return -1;
        // Folded lookup is ASCII-only input: folding accented tokens can
        // merge distinct words (chất/quality vs chát/pricey), so accented
        // tokens match raw entries only.
        if (!$this->asciiOnly($token)) return 0;
        $sets = $this->foldedSets();
        if (isset($sets[0][$plain])) return 1;
        if (isset($sets[1][$plain])) return -1;
        return 0;
    }

    private function isNegation(string $token, string $plain): bool
    {
        if (in_array($token, self::NEGATIONS, true)) return true;
        if (!$this->asciiOnly($token)) return false;
        foreach (self::NEGATIONS as $entry) {
            if ($this->fold($entry) === $plain && $plain !== '') return true;
        }
        return false;
    }

    private function isBreak(string $token, string $plain): bool
    {
        if (in_array($token, self::CLAUSE_BREAKS, true)) return true;
        if (!$this->asciiOnly($token)) return false;
        foreach (self::CLAUSE_BREAKS as $entry) {
            if (!str_contains($entry, ' ') && $this->fold($entry) === $plain && $plain !== '') return true;
        }
        return false;
    }

    private function asciiOnly(string $token): bool
    {
        return preg_match('/^[\x00-\x7F]+$/', $token) === 1;
    }

    /** @param list<string> $phrases */
    private function phraseHits(string $text, array $phrases): int
    {
        $lower = mb_strtolower($text);
        $foldedText = $this->fold($lower);
        $hits = 0;
        foreach ($phrases as $phrase) {
            $needle = mb_strtolower($phrase);
            if ($this->hasPhrase($lower, $needle) || $this->hasPhrase($foldedText, $this->fold($needle))) $hits++;
        }
        return $hits;
    }

    private function hasPhrase(string $haystack, string $needle): bool
    {
        if ($needle === '') return false;
        $pos = mb_strpos($haystack, $needle);
        if ($pos === false) return false;
        $before = $pos > 0 ? mb_substr($haystack, $pos - 1, 1) : ' ';
        $after = mb_substr($haystack, $pos + mb_strlen($needle), 1);
        return !$this->isWordChar($before) && ($after === '' || !$this->isWordChar($after));
    }

    private function isWordChar(string $char): bool
    {
        return $char !== '' && preg_match('/[\p{L}\p{N}_]/u', $char) === 1;
    }

    private function phraseScore(string $text, bool $positive): int
    {
        $entries = $positive ? self::POSITIVE_VI : self::NEGATIVE_VI;
        $phrases = array_values(array_filter($entries, static fn (string $entry): bool => str_contains($entry, ' ')));
        if ($phrases === []) return 0;
        $lower = mb_strtolower($text);
        $foldedText = $this->fold($lower);
        $total = 0;
        foreach ($phrases as $phrase) {
            $needle = mb_strtolower($phrase);
            $matched = false;
            $probe = $lower;
            $want = $needle;
            for ($round = 0; $round < 2 && !$matched; $round++) {
                $start = 0;
                while (($pos = mb_strpos($probe, $want, $start)) !== false) {
                    $before = $pos > 0 ? mb_substr($probe, $pos - 1, 1) : ' ';
                    $after = mb_substr($probe, $pos + mb_strlen($want), 1);
                    if (!$this->isWordChar($before) && ($after === '' || !$this->isWordChar($after))) {
                        $matched = true;
                        $head = $pos > 0 ? mb_substr($probe, 0, $pos) : '';
                        $words = preg_split('/[^\p{L}\p{N}\',]+/u', $head) ?: [];
                        $words = array_values(array_filter($words, static fn (string $word): bool => $word !== ''));
                        $tail = array_slice($words, -2);
                        $negated = false;
                        foreach ($tail as $word) {
                            if ($this->isBreak($word, $this->fold($word))) break;
                            if ($this->isNegation($word, $this->fold($word))) {
                                $negated = true;
                                break;
                            }
                        }
                        $total += ($positive ? 1 : -1) * ($negated ? -1 : 1);
                        break;
                    }
                    $start = $pos + 1;
                }
                $probe = $foldedText;
                $want = $this->fold($needle);
            }
        }
        return $total;
    }

    /** @return array{0: array<string, true>, 1: array<string, true>} positive + negative folded sets */
    private function foldedSets(): array
    {
        if (self::$foldedPos === null || self::$foldedNeg === null) {
            $pos = [];
            foreach (array_merge(self::POSITIVE_EN, self::POSITIVE_VI) as $entry) {
                if (!str_contains($entry, ' ')) $pos[$this->fold($entry)] = true;
            }
            $neg = [];
            foreach (array_merge(self::NEGATIVE_EN, self::NEGATIVE_VI) as $entry) {
                if (!str_contains($entry, ' ')) $neg[$this->fold($entry)] = true;
            }
            self::$foldedPos = $pos;
            self::$foldedNeg = $neg;
        }
        return [self::$foldedPos, self::$foldedNeg];
    }

    /** @param list<string> $tokens @return list<string> */
    private function foldedTokens(array $tokens): array
    {
        return array_map(fn (string $token): string => $this->fold($token), $tokens);
    }

    /** Strip Vietnamese diacritics so unaccented input matches the lexicon. */
    private function fold(string $text): string
    {
        static $map = null;
        if ($map === null) {
            $from = 'àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđÀÁẠẢÃÂẦẤẬẨẪĂẰẮẶẲẴÈÉẸẺẼÊỀẾỆỂỄÌÍỊỈĨÒÓỌỎÕÔỒỐỘỔỖƠỜỚỢỞỠÙÚỤỦŨƯỪỨỰỬỮỲÝỴỶỸĐ';
            $to = 'aaaaaaaaaaaaaaaaaeeeeeeeeeeeiiiiiooooooooooooooooouuuuuuuuuuuyyyyydAAAAAAAAAAAAAAAAAEEEEEEEEEEEIIIIIOOOOOOOOOOOOOOOOUUUUUUUUUUUYYYYYD';
            $map = [];
            $fromChars = preg_split('//u', $from, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $toChars = str_split($to);
            foreach ($fromChars as $index => $char) $map[$char] = $toChars[$index] ?? '';
        }
        return mb_strtolower(strtr($text, $map));
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $clean = mb_strtolower($text);
        $clean = (string) preg_replace('/,/u', ' , ', $clean);
        $words = preg_split('/[^\p{L}\p{N}\',]+/u', $clean) ?: [];
        return array_values(array_filter($words, static fn (string $word): bool => $word !== ''));
    }

    private function excerpt(string $body): ?string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($body)) ?: [];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (mb_strlen($sentence) < 10 || mb_strlen($sentence) > 240) continue;
            if (preg_match('/\S+@\S+\.\S+|https?:\/\/|www\.|\d[\d\s().-]{6,}\d/u', $sentence) === 1) continue;
            return $sentence;
        }
        return null;
    }
}
