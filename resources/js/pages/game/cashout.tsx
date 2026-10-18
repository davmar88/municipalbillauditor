import { Head } from '@inertiajs/react';
import { CashOut } from '@/components/game/cash-out';
import type { CashOutInfo } from '@/components/game/cash-out';
import { GameBar } from '@/components/game/game-bar';

type Props = {
    cashout: CashOutInfo;
};

/**
 * Cashing Apples out for money: an animal's payout details, such as their
 * PayPal email, asking for a cash-out, and the ones they asked for before.
 */
export default function CashoutPage({ cashout }: Props) {
    return (
        <>
            <Head title="Cash out" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
                <GameBar />
                <CashOut cashout={cashout} />
            </div>
        </>
    );
}
