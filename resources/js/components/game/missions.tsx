import { Check, Lock, Sparkles } from 'lucide-react';
import type { ReactNode } from 'react';
import { Horseshoe } from '@/components/game/game-bar';
import { Button } from '@/components/ui/button';
import { act } from '@/lib/game';
import { cn } from '@/lib/utils';
import { claim } from '@/routes/play/mission';
import type { MissionCard, MissionReward, SkillCard } from '@/types/game';

const GRADIENT = 'linear-gradient(110deg, #41d1ff, #b39aff 50%, #d18cff)';

/** A thin bar for how far along something is. */
export function ProgressBar({
    value,
    max,
    label,
}: {
    value: number;
    max: number;
    label: string;
}) {
    const percent = Math.min(100, Math.round((value / Math.max(1, max)) * 100));

    return (
        <div
            role="progressbar"
            aria-label={label}
            aria-valuemin={0}
            aria-valuemax={max}
            aria-valuenow={value}
            className="h-2 overflow-hidden rounded-full bg-muted"
        >
            <div
                className="h-full rounded-full transition-[width] duration-500"
                style={{ width: `${percent}%`, backgroundImage: GRADIENT }}
            />
        </div>
    );
}

/** What a mission pays, as small chips. */
export function RewardChips({ reward }: { reward: MissionReward }) {
    const chips: { key: string; content: ReactNode }[] = [];

    if (reward.corn) {
        chips.push({ key: 'corn', content: `${reward.corn} Corn` });
    }

    if (reward.rosettes) {
        chips.push({ key: 'rosettes', content: `${reward.rosettes} Rosettes` });
    }

    if (reward.horseshoes) {
        chips.push({
            key: 'horseshoes',
            content: (
                <>
                    <Horseshoe className="size-3.5" />
                    {reward.horseshoes}
                </>
            ),
        });
    }

    Object.entries(reward.materials ?? {}).forEach(([material, count]) =>
        chips.push({ key: material, content: `${count} ${material}` }),
    );

    return (
        <ul className="flex flex-wrap gap-1.5" aria-label="Reward">
            {chips.map((chip) => (
                <li
                    key={chip.key}
                    className="flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs text-muted-foreground"
                >
                    {chip.content}
                </li>
            ))}
        </ul>
    );
}

/** The button that collects a finished mission, or says why it can't yet. */
function MissionAction({ mission }: { mission: MissionCard }) {
    if (mission.claimed) {
        return (
            <p className="flex items-center gap-1.5 text-sm font-semibold text-primary">
                <Check className="size-4" aria-hidden /> Collected
            </p>
        );
    }

    if (mission.locked) {
        return (
            <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                <Lock className="size-4" aria-hidden /> {mission.locked}
            </p>
        );
    }

    return (
        <Button
            size="sm"
            disabled={!mission.done}
            onClick={() => act(claim.url(mission.id))}
            className={cn(mission.done && 'animate-pulse')}
        >
            {mission.done
                ? 'Collect reward'
                : `${mission.progress} of ${mission.target}`}
        </Button>
    );
}

/** The step of the story you're on. */
export function StoryMission({
    mission,
    chapters,
}: {
    mission: MissionCard | null;
    chapters: { name: string; done: number; total: number }[];
}) {
    const done = chapters.reduce((sum, chapter) => sum + chapter.done, 0);
    const total = chapters.reduce((sum, chapter) => sum + chapter.total, 0);

    return (
        <section
            aria-labelledby="story-heading"
            className="relative flex flex-col gap-3 overflow-hidden rounded-2xl border border-primary/30 bg-card p-5"
        >
            <div
                aria-hidden
                className="pointer-events-none absolute -top-16 -right-16 size-48 rounded-full opacity-20 blur-3xl"
                style={{ backgroundImage: GRADIENT }}
            />
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-mono text-xs tracking-[0.14em] text-primary">
                    ARRIVAL IN HOLLOWMERE
                    {mission?.chapter && ` · ${mission.chapter.toUpperCase()}`}
                </p>
                <p className="text-xs text-muted-foreground">
                    {done} of {total} steps
                </p>
            </div>
            <ol className="flex gap-1" aria-label="Chapters">
                {chapters.map((chapter) => (
                    <li
                        key={chapter.name}
                        className="flex flex-1 flex-col gap-1"
                        title={`${chapter.name}: ${chapter.done} of ${chapter.total}`}
                    >
                        <ProgressBar
                            value={chapter.done}
                            max={chapter.total}
                            label={chapter.name}
                        />
                        <span className="hidden truncate text-[11px] text-muted-foreground sm:block">
                            {chapter.name}
                        </span>
                    </li>
                ))}
            </ol>
            {mission ? (
                <>
                    <h2
                        id="story-heading"
                        className="text-xl font-extrabold tracking-tight"
                    >
                        {mission.title}
                    </h2>
                    {mission.story && (
                        <p className="text-sm text-muted-foreground italic">
                            {mission.story}
                        </p>
                    )}
                    <p className="font-semibold">{mission.goal}</p>
                    <ProgressBar
                        value={mission.progress}
                        max={mission.target}
                        label={mission.goal}
                    />
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <RewardChips reward={mission.reward} />
                        <MissionAction mission={mission} />
                    </div>
                </>
            ) : (
                <>
                    <h2
                        id="story-heading"
                        className="flex items-center gap-2 text-xl font-extrabold tracking-tight"
                    >
                        <Sparkles className="size-5 text-primary" /> The story
                        so far is yours
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        You've played every chapter there is. New ones open with
                        the seasons to come; until then, your daily jobs keep
                        coming.
                    </p>
                </>
            )}
        </section>
    );
}

/** One of today's jobs. */
export function DailyMission({
    mission,
    species,
}: {
    mission: MissionCard;
    species: string;
}) {
    return (
        <li
            className={cn(
                'flex flex-col gap-2 rounded-xl border bg-card p-4',
                mission.claimed && 'opacity-60',
            )}
        >
            <div className="flex items-start justify-between gap-2">
                <p className="font-bold">{mission.title}</p>
                {mission.favourite && (
                    <span className="shrink-0 rounded-full bg-primary/15 px-2 py-0.5 text-[11px] font-semibold text-primary">
                        Suits a {species.toLowerCase()}
                    </span>
                )}
            </div>
            <p className="text-sm">{mission.goal}</p>
            <ProgressBar
                value={mission.progress}
                max={mission.target}
                label={mission.goal}
            />
            <RewardChips reward={mission.reward} />
            <div className="mt-auto pt-1">
                <MissionAction mission={mission} />
            </div>
        </li>
    );
}

const SKILL_LABEL: Record<SkillCard['skill'], string> = {
    market: 'Market',
    creativity: 'Creativity',
};

const SKILL_HINT: Record<SkillCard['skill'], string> = {
    market: 'Grows with every sale and purchase in the shops.',
    creativity:
        'Grows when your designs pass their check and every time a copy sells.',
};

/** A skill with its level bar. */
export function SkillMeter({ skill }: { skill: SkillCard }) {
    return (
        <div className="flex flex-col gap-2 rounded-xl border bg-card p-4">
            <div className="flex items-baseline justify-between gap-2">
                <p className="font-bold">{SKILL_LABEL[skill.skill]}</p>
                <p className="text-sm text-muted-foreground">
                    Level {skill.level}
                </p>
            </div>
            <p className="text-sm font-semibold text-primary">{skill.title}</p>
            <ProgressBar
                value={skill.points - skill.from}
                max={skill.to - skill.from}
                label={`${SKILL_LABEL[skill.skill]} level ${skill.level}`}
            />
            <p className="text-xs text-muted-foreground">
                {skill.points} points · {skill.to - skill.points} to level{' '}
                {skill.level + 1}. {SKILL_HINT[skill.skill]}
            </p>
        </div>
    );
}
