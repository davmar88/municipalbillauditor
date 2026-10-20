import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { cancel, capture, order } from '@/routes/play/apples';

/**
 * PayPal's own buttons, right on the page: paying opens PayPal's window
 * over the game (or a card form in place), and the player never leaves.
 */

type PayPalButtonsApi = {
    render: (container: HTMLElement) => Promise<void>;
    close?: () => Promise<void>;
};

type PayPalSdk = {
    Buttons: (options: Record<string, unknown>) => PayPalButtonsApi;
};

declare global {
    interface Window {
        paypal?: PayPalSdk;
    }
}

let sdk: { clientId: string; ready: Promise<PayPalSdk> } | null = null;

/** Load PayPal's script once per page. */
function loadSdk(clientId: string, currency: string): Promise<PayPalSdk> {
    if (sdk?.clientId === clientId) {
        return sdk.ready;
    }

    const ready = new Promise<PayPalSdk>((resolve, reject) => {
        const script = document.createElement('script');
        script.src = `https://www.paypal.com/sdk/js?client-id=${encodeURIComponent(clientId)}&currency=${encodeURIComponent(currency)}&intent=capture&components=buttons`;
        script.async = true;
        script.onload = () =>
            window.paypal
                ? resolve(window.paypal)
                : reject(new Error('PayPal'));
        script.onerror = () => {
            sdk = null;
            reject(new Error('PayPal'));
        };
        document.head.appendChild(script);
    });
    sdk = { clientId, ready };

    return ready;
}

async function post(
    url: string,
    body: Record<string, unknown> = {},
): Promise<{ ok: boolean; data: Record<string, string> }> {
    const token = decodeURIComponent(
        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
    );
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });
    const data = (await response.json().catch(() => ({}))) as Record<
        string,
        string
    >;

    return { ok: response.ok, data };
}

export function PayPalButtons({
    pack,
    clientId,
    currency,
    onDone,
}: {
    pack: string;
    clientId: string;
    currency: string;
    onDone: () => void;
}) {
    const box = useRef<HTMLDivElement>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let gone = false;
        let buttons: PayPalButtonsApi | null = null;

        loadSdk(clientId, currency)
            .then((paypal) => {
                if (gone || !box.current) {
                    return;
                }
                buttons = paypal.Buttons({
                    style: {
                        layout: 'vertical',
                        shape: 'rect',
                        color: 'gold',
                        label: 'pay',
                    },
                    createOrder: async () => {
                        const answer = await post(order.url(), { pack });

                        if (!answer.ok || !answer.data.id) {
                            throw new Error(
                                answer.data.message ??
                                    "PayPal couldn't start the payment.",
                            );
                        }

                        return answer.data.id;
                    },
                    onApprove: async (data: { orderID: string }) => {
                        const answer = await post(capture.url(data.orderID));
                        const message =
                            answer.data.message ??
                            'PayPal is still confirming.';

                        if (
                            answer.ok &&
                            ['paid', 'pending'].includes(answer.data.status)
                        ) {
                            toast.success(message);
                        } else {
                            toast.error(message);
                        }
                        router.reload();
                        onDone();
                    },
                    onCancel: async (data: { orderID?: string }) => {
                        if (data.orderID) {
                            await post(cancel.url(data.orderID));
                        }
                        toast(
                            'You closed PayPal without paying. Nothing was charged.',
                        );
                    },
                    onError: (error: unknown) => {
                        setProblem(
                            error instanceof Error && error.message
                                ? error.message
                                : 'Something went wrong with PayPal. You were not charged; try again in a moment.',
                        );
                    },
                });
                void buttons.render(box.current).then(() => {
                    if (!gone) {
                        setLoading(false);
                    }
                });
            })
            .catch(() => {
                if (!gone) {
                    setLoading(false);
                    setProblem(
                        "PayPal didn't load. Check your connection, or turn off anything blocking paypal.com, and try again.",
                    );
                }
            });

        return () => {
            gone = true;
            void buttons?.close?.().catch(() => undefined);
        };
    }, [pack, clientId, currency, onDone]);

    return (
        <div className="flex flex-col gap-2">
            {loading && (
                <p className="text-xs text-muted-foreground">Loading PayPal…</p>
            )}
            {/* PayPal draws its buttons on white: give them a light card in dark mode. */}
            <div ref={box} className="rounded-lg bg-white p-2 empty:hidden" />
            {problem && (
                <p className="text-xs text-destructive" role="alert">
                    {problem}
                </p>
            )}
        </div>
    );
}
