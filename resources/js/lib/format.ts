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

/** "22:43" or "22:43:00" → "10:43pm" */
export function formatTime(time: string | null | undefined): string {
    if (!time) return '';
    const [h, m] = time.split(':').map(Number);
    const suffix = h >= 12 ? 'pm' : 'am';
    const hour12 = h % 12 || 12;
    return `${hour12}:${String(m).padStart(2, '0')}${suffix}`;
}
