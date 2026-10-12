/**
 * The shapes the Laravel presenters send to each page. Amounts and dates
 * arrive already formatted, so screens show exactly the engine's figures.
 */

export type OrganisationKind = 'household' | 'agent' | 'business';

export type Organisation = {
    kind: OrganisationKind;
    kindLabel: string;
    name: string;
    forwardingAddress: string;
};

export type Option = {
    value: string;
    label: string;
};

export type PropertyOption = {
    id: number;
    label: string;
    municipality: string | null;
};

export type MunicipalityOption = {
    id: number;
    name: string;
    province: string | null;
};

export type BillStatus =
    | 'received'
    | 'waiting_for_credit'
    | 'reading'
    | 'needs_entry'
    | 'needs_property'
    | 'checked';

export type Confidence = 'likely' | 'possible' | 'question';

export type FindingState = 'open' | 'confirmed' | 'dismissed';

export type BillSummary = {
    id: number;
    month: string;
    billDate: string | null;
    totalDue: string | null;
    status: BillStatus;
    statusLabel: string;
    source: string;
    needsReview: boolean;
    property: { id: number; label: string } | null;
    flags: number;
    questions: number;
    likelyTotal: string | null;
    upToTotal: string | null;
    dispute: {
        id: number;
        stage: string;
        stageLabel: string;
        submitted: boolean;
    } | null;
};

export type Finding = {
    id: number;
    number: number;
    checkType: string;
    checkLabel: string;
    service: string | null;
    confidence: Confidence;
    confidenceLabel: string;
    state: FindingState;
    title: string;
    explanation: string;
    amount: string | null;
    amountIsUpperBound: boolean;
    evidence: [string, string][];
    calculation: string;
    question: string;
    answerOptions: [string, string][];
    answer: string | null;
    action: string;
    awaitingAnswer: boolean;
    disputable: boolean;
};

export type Reading = {
    id: number;
    service: string;
    serviceLabel: string;
    meterNumber: string | null;
    previous: string | null;
    current: string | null;
    consumption: string | null;
    unit: string;
    readingType: 'actual' | 'estimated' | 'unknown';
    readingTypeShort: string;
    flags: number[];
};

export type ChargeLine = {
    id: number;
    service: string;
    description: string;
    amount: string;
    isCredit: boolean;
    flags: number[];
};

export type UsagePoint = {
    billId: number;
    month: string;
    label: string;
    value: number;
    estimated: boolean;
    flagged: boolean;
    current: boolean;
};

export type UsageSeries = {
    service: string;
    label: string;
    unit: string;
    average: number | null;
    points: UsagePoint[];
};

export type BillDetail = BillSummary & {
    accountNumber: string | null;
    tariffLabel: string | null;
    tariffCategory: string;
    billDateLabel: string | null;
    dueDate: string | null;
    period: string | null;
    municipality: { name: string; shortName: string } | null;
    readings: Reading[];
    charges: ChargeLine[];
    tariffFlags: number[];
    findings: Finding[];
    passed: string[];
    skipped: string[];
    reviewNote: string | null;
    readMethod: string | null;
    file: { name: string | null; url: string } | null;
    meterPhoto: string | null;
    deadline: { date: string; daysLeft: number; verified: boolean } | null;
    locked: boolean;
    canDispute: boolean;
    history: UsageSeries[];
};

export type DisputeMode = 'self' | 'managed';

export type DisputeSummary = {
    id: number;
    billId: number;
    mode: DisputeMode;
    property: string;
    billMonth: string;
    disputedTotal: string;
    submitted: boolean;
    awaitingLodging: boolean;
    stage: string;
    stageLabel: string;
    needsAction: boolean;
    isOpen: boolean;
    nextDate: string | null;
    daysLeft: number | null;
    lodgedOn: string | null;
    reference: string | null;
    issues: number;
};

export type TimelineState = 'done' | 'current' | 'upcoming' | 'if_needed';

export type TimelineEntry = {
    key: string;
    title: string;
    detail: string;
    on: string | null;
    state: TimelineState;
};

export type DisputeAction = {
    kind: string;
    label: string;
    fields: ('reference' | 'note' | 'amount')[];
};

export type TariffSnapshot = {
    month: string;
    label: string | null;
    total: string | null;
};

export type DisputeDetail = DisputeSummary & {
    letterSubject: string;
    letterBody: string;
    undisputedAmount: string | null;
    municipality: string | null;
    status: {
        headline: string;
        countdownLabel: string;
        daysLeft: number | null;
        progress: number | null;
        note: string;
    };
    timeline: TimelineEntry[];
    events: {
        id: number;
        kind: string;
        label: string;
        on: string;
        note: string | null;
    }[];
    findings: { number: number; title: string; amount: string | null }[];
    actions: DisputeAction[];
    creditAmount: string | null;
    sentTo: string | null;
    messages: {
        id: number;
        direction: 'out' | 'in';
        kind: string;
        subject: string | null;
        on: string;
        who: string;
        classification: string | null;
        summary: string | null;
    }[];
    tariff: {
        before: TariffSnapshot;
        after: TariffSnapshot | null;
        expected: string | null;
        monthlySaving: string | null;
    } | null;
};

export type PropertyDetail = {
    id: number;
    label: string;
    name: string;
    unit: string | null;
    address: string | null;
    suburb: string | null;
    municipalityId: number | null;
    municipality: string | null;
    propertyType: string;
    propertyTypeLabel: string;
    accountNumber: string | null;
    billCount: number | null;
};

export type PortfolioState =
    | 'escalated'
    | 'flags'
    | 'in_dispute'
    | 'missing'
    | 'pending'
    | 'clear';

export type PortfolioRow = {
    propertyId: number;
    label: string;
    municipality: string | null;
    billTotal: string | null;
    billMonth: string | null;
    state: PortfolioState;
    chip: string;
    flagCount: number;
    atStake: string | null;
    deadlineLabel: string | null;
    href: string;
};

export type JobQuote = {
    job: 'bill_check' | 'dispute';
    price: string;
    coveredByPlan: boolean;
    balance: string;
    affordable: boolean;
    shortfall: string | null;
};
