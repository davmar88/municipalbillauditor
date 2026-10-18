import type { GateNightInfo } from '@/types/game';

/** How strong the gate is so far tonight, against Ironfield's men. */
export function GateStrength({ gate }: { gate: GateNightInfo }) {
    if (gate.defenders === 0) {
        return (
            <span className="font-semibold">Nobody is at the gate yet.</span>
        );
    }

    return (
        <span className="font-semibold">
            {gate.defenders === 1
                ? '1 defender holds'
                : `${gate.defenders} defenders hold`}{' '}
            back {gate.strength} of them so far
            {gate.strength >= gate.humans ? ': enough to hold.' : '.'}
        </span>
    );
}
