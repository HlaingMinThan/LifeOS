/** 500000 → "5 သိန်း" for clean multiples, otherwise "500,000 Ks". */
export function formatMmk(amount: number | null | undefined): string {
    if (!amount) return '';
    if (amount >= 100_000 && amount % 100_000 === 0) {
        return `${amount / 100_000} သိန်း`;
    }
    return `${amount.toLocaleString()} Ks`;
}

export function formatDate(date: string | null | undefined): string {
    if (!date) return '';
    return new Date(date).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
}
