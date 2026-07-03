/** JSON POST with Laravel's XSRF cookie — for non-Inertia endpoints like the inbox. */
export async function apiPost<T = unknown>(url: string, data: unknown): Promise<T> {
    const token = decodeURIComponent(
        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
    );

    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': token,
            Accept: 'application/json',
        },
        body: JSON.stringify(data),
    });

    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        const message =
            body.message ??
            Object.values(body.errors ?? {})[0]?.[0] ??
            'Request failed';
        throw new Error(message);
    }

    return body as T;
}
