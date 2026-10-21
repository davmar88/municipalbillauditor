import { Link } from '@inertiajs/react';
import { HeartHandshake } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { act, shortWhen } from '@/lib/game';
import { answer, breakMethod } from '@/routes/play/alliance';
import { show as factionProfile } from '@/routes/play/factions';

export type AllianceInfo = {
    ally: {
        id: number;
        name: string;
        colour: string;
        since: string | null;
    } | null;
    offers: {
        id: number;
        from: { id: number; name: string; colour: string };
    }[];
    canDecide: boolean;
};

/** Your faction's ally, offers waiting on an answer, and breaking it off. */
export function AllianceSection({ alliance }: { alliance: AllianceInfo }) {
    return (
        <section className="flex flex-col gap-2 rounded-xl border p-4">
            <h2 className="flex items-center gap-2 text-lg font-bold">
                <HeartHandshake className="size-5 text-primary" aria-hidden />
                Alliance
            </h2>
            {alliance.ally ? (
                <>
                    <p className="text-sm">
                        Allied with{' '}
                        <Link
                            href={factionProfile(alliance.ally.id)}
                            className="font-semibold"
                            style={{ color: alliance.ally.colour }}
                        >
                            {alliance.ally.name}
                        </Link>
                        {alliance.ally.since &&
                            ` since ${shortWhen(alliance.ally.since)}`}
                        . Neither of you can raid the other, you can defend each
                        other's land, and your homes are welcome on it.
                    </p>
                    {alliance.canDecide && (
                        <Button
                            size="sm"
                            variant="destructive"
                            className="self-start"
                            onClick={() =>
                                confirm(
                                    `Break the alliance with ${alliance.ally!.name}? The whole valley hears of it, and their land is fair game again.`,
                                ) && act(breakMethod.url(), {}, 'delete')
                            }
                        >
                            Break the alliance
                        </Button>
                    )}
                </>
            ) : (
                <p className="text-sm text-muted-foreground">
                    No ally. Your leader or council can offer one from another
                    faction's page. One ally at a time.
                </p>
            )}
            {alliance.offers.map((offer) => (
                <div
                    key={offer.id}
                    className="flex flex-wrap items-center gap-2 rounded-lg border px-3 py-2 text-sm"
                >
                    <span>
                        <Link
                            href={factionProfile(offer.from.id)}
                            className="font-semibold"
                            style={{ color: offer.from.colour }}
                        >
                            {offer.from.name}
                        </Link>{' '}
                        offers you an alliance.
                    </span>
                    {alliance.canDecide && (
                        <>
                            <Button
                                size="sm"
                                onClick={() =>
                                    act(answer.url(offer.id), { accept: true })
                                }
                            >
                                Accept
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    act(answer.url(offer.id), { accept: false })
                                }
                            >
                                Decline
                            </Button>
                        </>
                    )}
                </div>
            ))}
        </section>
    );
}
