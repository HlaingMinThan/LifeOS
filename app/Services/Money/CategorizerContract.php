<?php

namespace App\Services\Money;

interface CategorizerContract
{
    /**
     * Name a spending category for each title.
     *
     * @param  array<int, string>  $titles  keyed by ledger entry id
     * @param  array<int, string>  $existing  the user's categories so far
     * @return array<int, string> category keyed by the same ids
     */
    public function categorize(array $titles, array $existing): array;
}
