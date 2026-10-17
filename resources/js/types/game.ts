export type Species =
    | 'pig'
    | 'dog'
    | 'horse'
    | 'sheep'
    | 'goat'
    | 'hen'
    | 'donkey'
    | 'raven';

export type FactionBadge = {
    id: number;
    name: string;
    colour: string;
    role?: string | null;
};

export type PlayerSummary = {
    id: number;
    name: string;
    species: Species;
    level: number;
    rosettes: number;
    nextLevelAt: number;
    horseshoes: number;
    maxHorseshoes: number;
    nextHorseshoeIn: number | null;
    corn: number;
    materials: Record<string, number>;
    language: string;
    faction: FactionBadge | null;
    inPoundUntil: string | null;
    homeClosedUntil: string | null;
    shieldUntil: string | null;
};

export type DesignCard = {
    id: number;
    template: 'hat' | 'scarf' | 'sign';
    templateLabel: string;
    name: string;
    colours: string[];
    pattern: string;
    edition: number | null;
    made: number;
    status: 'pending' | 'approved' | 'rejected';
};

export type FactionRef = { id: number; name: string; colour: string };

export type MapAnimal = {
    name: string;
    species: Species;
    colour: string | null;
    me: boolean;
};

export type MapRegion = {
    id: number;
    key: string;
    name: string;
    ring: 'seat' | 'inner' | 'middle' | 'outer' | 'safe';
    produces: string | null;
    col: number;
    row: number;
    faction: FactionRef | null;
    settledUntil: string | null;
    neighbours: number[];
    incoming: {
        id: number;
        faction: { name: string; colour: string };
        landsAt: string;
        from: number | null;
    }[];
    animals: { count: number; sample: MapAnimal[] };
};

export type MapBattle = {
    id: number;
    region: string;
    attacker: string;
    side: 'attack' | 'defence';
    landsAt: string;
    ourHorseshoes: number;
    joined: boolean;
};

export type MapLaw = { label: string; wording: string };
