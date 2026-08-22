/** JSON request with Laravel's XSRF cookie — for non-Inertia endpoints like the inbox and push. */
export async function apiSend<T = unknown>(
    method: string,
    url: string,
    data?: unknown,
): Promise<T> {
    const token = decodeURIComponent(
        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
    );

    const response = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': token,
            Accept: 'application/json',
        },
        body: data === undefined ? undefined : JSON.stringify(data),
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

export const apiPost = <T = unknown>(url: string, data: unknown): Promise<T> =>
    apiSend<T>('POST', url, data);
