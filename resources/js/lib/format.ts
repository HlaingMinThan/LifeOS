/** Always plain digits — 500000 → "500,000 Ks" — so amounts scan and sum easily. */
export function formatMmk(amount: number | null | undefined): string {
    if (!amount) return '';
    return `${amount.toLocaleString()} Ks`;
}

export function formatDate(date: string | null | undefined): string {
    if (!date) return '';
    return new Date(date).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
}
