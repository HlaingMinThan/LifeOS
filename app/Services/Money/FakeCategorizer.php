<?php

namespace App\Services\Money;

use App\Models\LedgerEntry;

/**
 * Keyword categorizer for tests and for local dev without API credit.
 * Same contract as ClaudeCategorizer — deliberately dumb, never throws.
 */
class FakeCategorizer implements CategorizerContract
{
    /** First keyword that appears in the text wins. */
    private const KEYWORDS = [
        'Food & Drinks' => ['lunch', 'dinner', 'breakfast', 'food', 'snack', 'coffee', 'tea', 'ထမင်း', 'ကော်ဖီ'],
        'Transport' => ['taxi', 'grab', 'fuel', 'petrol', 'gas', 'bus', 'ဆီ', 'ကား'],
        'Utilities' => ['electric', 'water', 'internet', 'wifi', 'phone bill', 'bill', 'မီတာ'],
        'Rent' => ['rent', 'အိမ်လခ'],
        'Groceries' => ['grocery', 'groceries', 'market', 'ဈေး'],
        'Health' => ['doctor', 'medicine', 'hospital', 'ဆေး'],
        'Salary' => ['salary', 'wage', 'payroll', 'လစာ'],
    ];

    public function categorize(array $titles, array $existing): array
    {
        $out = [];

        foreach ($titles as $id => $text) {
            $out[$id] = $this->match(mb_strtolower($text));
        }

        return $out;
    }

    private function match(string $text): string
    {
        foreach (self::KEYWORDS as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $category;
                }
            }
        }

        return LedgerEntry::UNCATEGORIZED;
    }
}
