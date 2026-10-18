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

export type BuildingKindValue =
    | 'den'
    | 'watchtower'
    | 'drill_yard'
    | 'storehouse'
    | 'workshop';

/** What a building costs and does at each level. */
export type BuildingKindCard = {
    value: BuildingKindValue;
    label: string;
    levels: {
        level: number;
        does: string;
        corn: number;
        materials: Record<string, number>;
        minutes: number;
    }[];
};

export type MapBuilding = {
    id: number;
    kind: BuildingKindValue;
    label: string;
    level: number;
    readyAt: string | null;
    does: string | null;
};

/** Your faction's store and whether you may build from it. */
export type FactionStore = {
    canBuild: boolean;
    hasDen: boolean;
    treasury: number;
    materials: Record<string, number>;
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
    building: MapBuilding | null;
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

/** What a mission pays when it's collected. */
export type MissionReward = {
    corn?: number;
    rosettes?: number;
    horseshoes?: number;
    materials?: Record<string, number>;
};

export type MissionCard = {
    id: number;
    key: string;
    chapter: string | null;
    title: string;
    story: string | null;
    goal: string;
    progress: number;
    target: number;
    done: boolean;
    claimed: boolean;
    locked: string | null;
    reward: MissionReward;
    favourite: boolean;
};

export type SkillName = 'market' | 'creativity';

/** A player's Market or Creativity skill: points, level and title. */
export type SkillCard = {
    skill: SkillName;
    points: number;
    level: number;
    title: string;
    from: number;
    to: number;
};
