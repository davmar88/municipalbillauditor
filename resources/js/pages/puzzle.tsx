import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { home, play, register } from '@/routes';
import { solve } from '@/routes/puzzle';

type Props = {
    puzzle: {
        number: number;
        date: string;
        before: string[];
        after: string[];
        answer: number;
    };
    player: boolean;
    solvedToday: boolean;
};

const STUDY_SECONDS = 15;

/** Spot the Edit: study the Wall, then find the one number that changed. */
export default function Puzzle({ puzzle, player, solvedToday }: Props) {
    const [stage, setStage] = useState<'ready' | 'study' | 'spot' | 'done'>(
        'ready',
    );
    const [left, setLeft] = useState(STUDY_SECONDS);
    const [guess, setGuess] = useState<number | null>(null);
    const [seconds, setSeconds] = useState(0);
    const [copied, setCopied] = useState(false);
    const started = useRef(0);

    useEffect(() => {
        if (stage !== 'study') {
            return;
        }

        if (left === 0) {
            setStage('spot');
            started.current = Date.now();

            return;
        }

        const timer = window.setTimeout(() => setLeft(left - 1), 1000);

        return () => window.clearTimeout(timer);
    }, [stage, left]);

    const right = guess === puzzle.answer;
    const share = `Animal Farm Spot the Edit #${puzzle.number}: ${right ? `spotted in ${seconds}s` : 'missed it'} ${puzzle.after.map((_, i) => (i === puzzle.answer ? (right ? '🟪' : '🟧') : '⬛')).join('')}`;

    function pick(index: number) {
        const taken = Math.round((Date.now() - started.current) / 1000);
        setGuess(index);
        setSeconds(taken);
        setStage('done');

        if (player && !solvedToday && index === puzzle.answer) {
            router.post(
                solve.url(),
                { answer: index, seconds: taken },
                { preserveScroll: true, preserveState: true },
            );
        }
    }

    const lines = stage === 'study' ? puzzle.before : puzzle.after;

    return (
        <div className="dark min-h-screen bg-background text-foreground">
            <Head title={`Spot the Edit #${puzzle.number}`} />
            <div className="mx-auto flex max-w-2xl flex-col gap-6 px-4 py-10">
                <header className="flex flex-col gap-2">
                    {/* A plain link: the home page is Blade, not an
                        Inertia page, so it needs a full page load. */}
                    <a
                        href={home().url}
                        className="font-mono text-xs tracking-[0.14em] text-primary no-underline"
                    >
                        REVENUEGUARD
                    </a>
                    <h1 className="text-3xl font-extrabold tracking-tight">
                        Spot the Edit #{puzzle.number}
                    </h1>
                    <p className="text-muted-foreground">
                        Study the Barn Wall for {STUDY_SECONDS} seconds. Then
                        someone quietly changes one number. Tap the law that
                        changed.
                    </p>
                </header>

                <ol className="flex flex-col gap-2 rounded-xl border bg-card p-5">
                    {stage === 'ready' ? (
                        <li className="py-6 text-center text-muted-foreground">
                            The Wall is covered. Ready?
                        </li>
                    ) : (
                        lines.map((line, index) => (
                            <li key={index}>
                                <button
                                    type="button"
                                    disabled={stage !== 'spot'}
                                    onClick={() => pick(index)}
                                    className={cn(
                                        'flex w-full gap-3 rounded-md p-2 text-left',
                                        stage === 'spot' &&
                                            'hover:bg-primary/10',
                                        stage === 'done' &&
                                            index === puzzle.answer &&
                                            'bg-primary/20',
                                        stage === 'done' &&
                                            index === guess &&
                                            !right &&
                                            'bg-orange-500/20',
                                    )}
                                >
                                    <span className="font-mono text-primary">
                                        {index + 1}
                                    </span>
                                    <span>{line}</span>
                                </button>
                            </li>
                        ))
                    )}
                </ol>

                {stage === 'ready' && (
                    <Button
                        size="lg"
                        className="self-start"
                        onClick={() => setStage('study')}
                    >
                        Show me the Wall
                    </Button>
                )}
                {stage === 'study' && (
                    <p className="text-lg font-semibold">
                        Memorise it: {left}s
                    </p>
                )}
                {stage === 'spot' && (
                    <p className="text-lg font-semibold">Which law changed?</p>
                )}
                {stage === 'done' && (
                    <div className="flex flex-col gap-3">
                        <p className="text-lg font-semibold">
                            {right
                                ? `Spotted in ${seconds} seconds.`
                                : 'Not that one. The changed law is highlighted.'}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            It used to say: "{puzzle.before[puzzle.answer]}"
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="secondary"
                                onClick={() => {
                                    void navigator.clipboard?.writeText(share);
                                    setCopied(true);
                                }}
                            >
                                {copied ? 'Copied' : 'Copy my result'}
                            </Button>
                            <Button asChild>
                                <a
                                    href={`https://wa.me/?text=${encodeURIComponent(`${share} ${window.location.href}`)}`}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Share on WhatsApp
                                </a>
                            </Button>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            In the game, the rulers really do this once a week.{' '}
                            {player ? (
                                <Link href={play()}>Back to the valley</Link>
                            ) : (
                                <Link href={register()}>
                                    Play the test season, free
                                </Link>
                            )}
                        </p>
                    </div>
                )}
            </div>
        </div>
    );
}
