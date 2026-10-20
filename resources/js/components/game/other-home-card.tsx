import { usePage } from '@inertiajs/react';
import { Flame, Home as HomeIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { WhyNot, needHorseshoes } from '@/components/game/why-not';
import { act, speciesEmoji } from '@/lib/game';
import type { Point } from '@/lib/hollowmere';
import { crime } from '@/routes/play';
import type { MapHome } from '@/types/game';

export type BurnRules = {
    /** The level crime opens at. */
    level: number;
    horseshoes: number;
    /** Whether you've already tried a loot or a burn today. */
    usedToday: boolean;
};

/**
 * Another animal's home, tapped on the map: whose it is, and setting it
 * alight, faction mates' homes included. Their faction's rules decide
 * what turning on your own costs you.
 */
export function OtherHomeCard({
    home,
    rules,
    onWalk,
}: {
    home: MapHome;
    rules: BurnRules;
    onWalk: (point: Point) => void;
}) {
    const { player } = usePage().props;
    const level = player?.level ?? 0;
    const shoes = player?.horseshoes ?? 0;
    const mate =
        player?.faction !== null &&
        player?.faction !== undefined &&
        home.factionName === player.faction.name;

    const reason =
        home.fire === 'burning'
            ? 'It is already on fire.'
            : home.fire === 'ash'
              ? 'It burned down already. A home can burn at most once a week.'
              : level < rules.level
                ? `Setting homes alight opens at level ${rules.level}. You are level ${level}.`
                : rules.usedToday
                  ? 'You have already tried a loot or a burn today. Lie low until tomorrow.'
                  : needHorseshoes(shoes, rules.horseshoes);

    return (
        <section className="flex flex-col gap-2 rounded-2xl border bg-card p-4 text-sm">
            <h2 className="flex items-center gap-2 pr-6 text-base font-bold">
                <HomeIcon className="size-4" aria-hidden />
                <span aria-hidden>{speciesEmoji(home.owner.species)}</span>
                {home.owner.name}'s {home.name}
            </h2>
            <p className="text-muted-foreground">
                {home.factionName
                    ? `${home.owner.name} is in ${home.factionName}${mate ? ', your own faction' : ''}.`
                    : `${home.owner.name} is a lone wolf.`}{' '}
                {home.skin ? `It wears ${home.skin.name}. ` : ''}A higher home
                and a better skin make a burner likelier to be caught.
            </p>
            {home.fire === 'burning' && (
                <p className="font-semibold text-orange-500">
                    🔥 It is on fire.
                </p>
            )}
            <div className="flex flex-wrap items-center gap-2">
                <Button
                    size="sm"
                    variant="destructive"
                    disabled={reason !== null}
                    onClick={() =>
                        act(crime.url(), {
                            kind: 'burn',
                            player_id: home.owner.id,
                        })
                    }
                >
                    <Flame className="size-4" aria-hidden /> Set it alight (
                    {rules.horseshoes} horseshoes)
                </Button>
                <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => onWalk([home.x, home.y])}
                >
                    Walk there
                </Button>
                <WhyNot reason={reason} />
            </div>
            <p className="text-xs text-muted-foreground">
                The fire takes half an hour. Anyone can run to the door and put
                it out, and if you're caught you go to the pound. Burning a
                faction mate's home can earn you a strike if your faction's
                rules forbid it, and the informants will name you to anyone
                putting up a bounty.
            </p>
        </section>
    );
}
