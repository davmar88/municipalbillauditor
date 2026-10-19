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
    /** The premium currency, bought with Pineapples in packs. */
    apples: number;
    /** What real money buys; they swap for packs of Apples. */
    pineapples: number;
    /** An Orchard member, with the badge and the extra shop shelf. */
    orchard: boolean;
    /** A member who hasn't taken today's free horseshoe refill. */
    freeRefill: boolean;
    materials: Record<string, number>;
    language: string;
    faction: FactionBadge | null;
    inPoundUntil: string | null;
    homeClosedUntil: string | null;
    shieldUntil: string | null;
    /** Powers from used items working right now. */
    powers: { kind: string; label: string; left: string }[];
};

export type ItemShape =
    | 'hat'
    | 'scarf'
    | 'sign'
    | 'gloves'
    | 'shoes'
    | 'cloak'
    | 'tonic'
    | 'ribbon'
    | 'banner'
    | 'hedge'
    | 'basket'
    | 'bell'
    | 'trowel'
    | 'sheaf'
    | 'cake'
    | 'pickaxe'
    | 'lock'
    | 'kit'
    | 'gate'
    | 'plough'
    | 'speedo'
    | 'skin'
    | 'mortar'
    | 'crown'
    | 'ring'
    | 'pie'
    | 'stew';

export type DesignCard = {
    id: number;
    template: ItemShape;
    templateLabel: string;
    /** What using a copy does, or null for looks. */
    power: string | null;
    for: 'you' | 'faction' | null;
    name: string;
    colours: string[];
    pattern: string;
    edition: number | null;
    made: number;
    status: 'pending' | 'approved' | 'rejected';
};

export type FactionRef = { id: number; name: string; colour: string };

/** What a shop prices an item in: Corn from play, or Apples. */
export type Currency = 'corn' | 'apples';

/** An animal you can write to, as Messages shows it. */
export type MessageAnimal = {
    id: number;
    name: string;
    species: Species;
    faction: string | null;
    colour: string | null;
    orchard: boolean;
};

/** A shop item an animal wears, as the server sends it (App\Game\Wardrobe). */
export type Worn = {
    spot:
        | 'hat'
        | 'scarf'
        | 'bell'
        | 'cloak'
        | 'ribbon'
        | 'gloves'
        | 'shoes'
        | 'carried';
    template: string;
    name: string;
    colours: string[];
    pattern: string;
};

export type MapAnimal = {
    id: number;
    name: string;
    species: Species;
    colour: string | null;
    me: boolean;
    /** The shop items it wears (App\Game\Wardrobe), for the 3D map. */
    wearing: Worn[];
};

export type BuildingKindValue =
    | 'den'
    | 'watchtower'
    | 'drill_yard'
    | 'storehouse'
    | 'workshop'
    | 'head_office';

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
    /** How many are building the level going up. */
    builders: number | null;
    /** Whether you lent a hand with the level going up. */
    helped: boolean;
};

/** Your faction's store and whether you may build from it. */
export type FactionStore = {
    canBuild: boolean;
    hasDen: boolean;
    treasury: number;
    materials: Record<string, number>;
};

export type StudyValue =
    | 'attack'
    | 'defence'
    | 'science'
    | 'farming'
    | 'marching';

/** A faction's studies, as the Faction page shows them. */
export type FactionStudies = {
    /** The faction's study levels, added up. */
    points: number;
    canStudy: boolean;
    studying: {
        value: StudyValue;
        label: string;
        level: number;
        until: string;
    } | null;
    studies: {
        value: StudyValue;
        label: string;
        level: number;
        max: number;
        /** What it does at the level reached, or null before level 1. */
        does: string | null;
        next: {
            level: number;
            does: string;
            corn: number;
            materials: Record<string, number>;
            minutes: number;
        } | null;
    }[];
};

/** Where you stand on the map, and the walk you're on. */
export type MapMe = {
    x: number;
    y: number;
    /** Paces a minute: a Speedo doubles it. */
    pace: number;
    walk: {
        from: [number, number];
        to: [number, number];
        startedAt: string;
        arrivesAt: string;
        /** The region at the end of the walk. */
        region: string | null;
    } | null;
};

/** Gate Night: Ironfield's Sunday raid at 19:00, and how it went. */
export type GateNightInfo = {
    /** Whether animals can still put horseshoes into holding the gate. */
    open: boolean;
    /** Tonight's raid time. */
    at: string;
    defended: boolean;
    /** Animals at the gate tonight, the men they hold back, and the men coming. */
    defenders: number;
    strength: number;
    humans: number;
    /** How tonight's raid went, once it has happened. */
    result: { held: boolean; defenders: number; burned: string[] } | null;
};

export type MapRegion = {
    id: number;
    key: string;
    name: string;
    ring: 'seat' | 'inner' | 'middle' | 'outer' | 'safe' | 'farm' | 'city';
    produces: string | null;
    /** What is left to gather, and how it grows back; null where nothing grows. */
    stock: {
        left: number;
        max: number;
        perHour: number;
        /** When more has grown, once it has run out. */
        nextAt: string | null;
        /** When it is full again, or null when it is. */
        fullAt: string | null;
    } | null;
    col: number;
    row: number;
    faction: FactionRef | null;
    settledUntil: string | null;
    /** When an Iron Gate keeping raiders out comes down. */
    gatedUntil: string | null;
    /** The farm a cleared patch of land belongs to. */
    partOf: string | null;
    /** How far a farm has grown into the wild land round it. */
    growth: {
        grown: number;
        max: number;
        next: {
            name: string;
            cost: { corn: number; materials: Record<string, number> };
        } | null;
    } | null;
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

/** One of Irongate's people: not a player, a fixed part of the town. */
export type Folk = {
    key: string;
    name: string;
    role: string;
    /** The district they stand in. */
    district: string;
    /** Where, as a nudge from the district's hub. */
    spot: [number, number];
    says: string;
};

/** Irongate's people and their prices. */
export type IrongateInfo = {
    folk: Folk[];
    /** Ironfield's men mustering for Sunday's Gate Night. */
    muster: number;
    fence: {
        price: number;
        rarePrice: number;
        rare: string[];
        /** Corn the fence will still pay you today. */
        left: number;
        daily: number;
    };
    /** Corn the informer wants for a tip. */
    tip: number;
};

/** An animal's own home on the map. */
export type MapHome = {
    id: number;
    owner: { id: number; name: string; species: Species };
    x: number;
    y: number;
    level: number;
    /** Hut, Cottage or Fort. */
    name: string;
    /** While a level goes up. */
    readyAt: string | null;
    skin: { name: string; colours: string[]; pattern: string } | null;
    mine: boolean;
    /** A faction mate's home going up that you could lend a hand on. */
    helpable: boolean;
};

/** What building your own home takes, and whether you're hiding in it. */
export type HomeInfo = {
    inside: boolean;
    levels: {
        level: number;
        name: string;
        corn: number;
        materials: Record<string, number>;
        minutes: number;
    }[];
    /** How close you must stand to go in or build on, in paces. */
    door: number;
    skins: { id: number; name: string; colours: string[]; pattern: string }[];
};
