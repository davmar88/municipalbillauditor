@extends('site.layout')

@php
    $shoes = config('game.horseshoes');
    $levels = config('game.levels');
    $faction = config('game.faction');
    $sections = [
        'getting-started' => ['Getting started', [
            'Make an account, pick an animal and give it a name. You start in the Paddock, the safe ground by the gate, with '.config('game.starting_corn').' Corn. Watch out: there is no newcomer protection, so other animals can rob you and burn your home from day one.',
            'Tap anywhere on the map to walk there. Press and hold (or right-click) a region to open its card and see what you can do there. The Today page shows your story missions and daily jobs.',
            'Everything you do costs horseshoes. Spend them well; they come back with time.',
        ]],
        'animals' => ['The animals', [
            'Pigs are planners: raids they lead hit harder.',
            'Dogs are guards: their defence counts 30% more, they make the best bounty hunters, and dogs in a faction make crime against it riskier.',
            'Horses are builders: they craft with half the materials.',
            'Sheep are the chorus: their chants calm or stir the valley.',
            'Goats are auditors: they spot secret changes to the laws sooner.',
            'Cows gather more, llamas carry more water, zebras walk faster, and tortoises are hard to beat but slow.',
            'Twelve cats, one for each coat, each with its own knack: the Ginger Cat fights harder, the Black Cat is harder to catch, the Masked Cat robs more, the Calico Cat gathers more, the Silver Tabby walks fastest, and more.',
        ]],
        'horseshoes' => ['Horseshoes', [
            'You hold up to '.$shoes['max'].' horseshoes, and one comes back every '.$shoes['refill_minutes'].' minutes.',
            'Every 24 hours your horseshoes are filled back up to '.$shoes['max'].'. The daily fill never takes you past '.$shoes['max'].'; only bought horseshoes go higher.',
            'Gathering, raiding, defending, crimes, fights and arrests all cost horseshoes.',
            'In a hurry? Buy horseshoes with Apples on the Apples page: tap your horseshoe count at the top of any page. Bought ones stack past '.$shoes['max'].', up to '.$shoes['saddlebag'].'.',
            'A hot meal at the Black Dog in Irongate, a Veggie Stew, and an Orchard membership\'s daily refill also top you up.',
        ]],
        'map' => ['The map and the valleys', [
            'Hollowmere is the first valley: the Farmhouse at its heart, fields and yards around it, the farms beyond the fences, the frontier, the open scrub between everything, the humans\' town of Irongate, and the mines of Ironfield.',
            'Each region holds only so much to gather, and grows back by the hour. The further from the farms, the less there is.',
            'Switch between the flat map and the 3D map with the button on the map. The map updates by itself when anything happens.',
            'A valley holds up to '.number_format(config('game.valleys.full_at')).' active animals. When it\'s full, newcomers start in the next valley, with its own map and season.',
        ]],
        'year' => ['The turning year', [
            'Every season turns through spring, summer, fall and winter, a quarter each. The bar above the map shows where we are.',
            'Spring is mild. Summer brings plenty: everything gathered comes in half as much again, but the heat makes animals thirstier.',
            'Fall dries the water up: ponds and springs give little, and the Paddock trough runs dry. Store water before it comes.',
            'Winter makes food scarce: Corn and crops come in at half, and the cold makes animals hungrier.',
        ]],
        'survival' => ['Staying alive', [
            'Every animal has health, food and water, out of 100, shown at the top of every page. Everyone can see the health bar over every animal and every home.',
            'You eat the Corn you carry when you are peckish, and drink the water you carry when you are dry; faction members drink from the faction\'s store too. Tap the Food or Water bar to eat or drink your fill now.',
            'Drink for free at the Paddock trough (except in fall) or at any spring or mere. Carry water in a Water skin (20), or buy a Water bucket (60), a Water barrel (150) or a Water butt (300) with Corn.',
            'With nothing to eat or drink you lose health; fed and watered you mend. A robbery roughs you up too. At no health you die: everything you carried is scattered over the valley in piles, and you wake up in the Paddock. Apples, Pineapples and items are never lost.',
            'Piles of loot (💰) lie on the map for two days. Walk over and pick one up for 1 horseshoe: finders keepers.',
        ]],
        'gathering' => ['Gathering, farms and water', [
            'Gather Corn and materials (wood, straw, stone, clay, wool, dye, eggs) where they grow. Farms beyond the fences grow rarer crops: honey, herbs, iron, gold, copper, fruit and vegetables.',
            'A faction can buy an unclaimed farm or raid one, clear its wild patches into new fields, and grow it.',
            'Farms need water. Fetch it from ponds and springs, build a Well, or dig a trench from water to the farm.',
        ]],
        'factions' => ['Factions', [
            'Found a faction from level '.$levels['found_faction'].' for '.$faction['founding_corn'].' Corn, or join one. A faction holds up to '.$faction['max_members'].' members, more with a bigger Den.',
            'Factions hold land, keep a treasury and a store of materials, and raise buildings: a Den, Watchtowers, a Drill Yard, a Storehouse, a Workshop, a Mill, a Well, a Coop, a Silo and a Hospital. Each building has its own job, and each stores Corn and materials.',
            'Factions study Attack, Defence, Science, Farming and Marching.',
            'Each faction can set its own raid rules (raid only from the front line, only next to its land, only the leader calls raids, spare named factions, never turn on our own). A member who breaks one gets a strike; three in a season flags them. The leader can excuse a member, or run the faction rogue.',
        ]],
        'raids' => ['Raids and the House', [
            'A faction with at least '.$faction['members_to_raid'].' members can raid any region from anywhere. Raids land after a march; both sides add horseshoes until then. Win, and the one who led the raid strips the field bare into their bag, as a plunder does.',
            'The faction that holds the Farmhouse and builds a Head Office there becomes the House. The House rules the valley and writes the laws on the Barn Wall.',
            'To raid the Farmhouse, a faction must hold 2 of the 4 regions around it.',
        ]],
        'laws' => ['Laws and the Barn Wall', [
            'The House writes the laws: taxes, levies, curfews and more. Once a week it may secretly change one.',
            'Save a copy of the Wall each day. Spot a secret change within 72 hours and you earn fame and Rosettes.',
            'Unfair laws raise the valley\'s unrest. At 60, anyone can call an uprising, and the Farmhouse can change hands.',
        ]],
        'crime' => ['Crime', [
            'From level '.$levels['crime'].', anyone can commit crimes, faction members too, against their own faction if they dare.',
            'Loot a faction\'s treasury, or set a home alight, once a day. Plunder a farm on its own clock. Rob an animal every two hours.',
            'Rob an animal or set a home alight from anywhere on the map: you make your way there, faster the faster you walk (a Speedo doubles it), and it lands when you arrive. A red arrow shows it on the way, to you and to the one it\'s aimed at, who is warned and can hide in their home.',
            'Crimes settle after a while: robbery after 10 minutes, the rest after half an hour. Nobody goes to the pound by chance: the victim is told who did it.',
            'The victim (or any member of the faction robbed) has a day to walk to where the criminal stands and catch them, for 2 horseshoes. It usually works, less often if they hide in a strong home. A catch sends them to the pound and they pay back what they took.',
            'Or the victim puts a bounty on them from their own Apples, and the bounty hunters do it.',
        ]],
        'fire' => ['Fire and ash', [
            'Tap any animal\'s home on the map to set it alight. It burns with real flames for half an hour.',
            'Anyone can run to the door and put the fire out for 1 horseshoe. That saves the home, and the burner was seen, so they\'re likelier to be caught.',
            'A home that burns down drops a level, and ash and charred beams stand where it was until its owner rebuilds.',
            'Every home shows its health: it falls while it burns and mends 10 an hour once the fire is out.',
            'Land plundered, raided or sacked by Irongate\'s riders burns for twenty minutes, then lies in ashes for an hour.',
        ]],
        'bounties' => ['Bounties and the pound', [
            'Any faction\'s leader and council can put a bounty in Apples on an animal the informants have seen breaking the law. Anyone can add Apples to a bounty.',
            'Walk to where the outlaw stands and try an arrest. A catch sends them to the pound and pays you the bounty, less the game\'s share. An outlaw hiding in a strong home is harder to catch.',
            'A caught outlaw pays back what their crimes took, as far as their Corn goes.',
        ]],
        'homes' => ['Homes and skins', [
            'Pitch a Tent or build a Hut almost anywhere: on a farm, in the scrub, on the frontier. Raise it to a Cottage, a Fort, a Keep and beyond.',
            'Hide inside to keep away from bounty hunters. A higher home is harder to burn and harder to arrest anyone in.',
            'Choose your home\'s look, from barns and windmills to town houses and flats.',
            'The skin shop sells eight skins: the first four for Corn, the finest for Apples. Each makes your home harder to burn and to arrest anyone hiding inside.',
        ]],
        'market' => ['The Market, items and weapons', [
            'Design hats, scarves, shop signs and dozens of other items on the Workshop\'s shapes. A design is checked before it can be made. Every copy is numbered.',
            'Sell copies from your own shop for Corn or Apples. Better shapes open up as your Creativity grows.',
            'Many items have a power that\'s used up when you use them: gloves for gathering, a Night Cloak for crime, a War Banner for raids, an Iron Lock for your home.',
            'Weapons: a Pitchfork makes your next 3 raids hit half as hard again; a Cudgel makes your next robbery take twice as much; a Sling doubles you against Irongate\'s plunderers; an Iron Sword doubles your next 3 raids, defences or plunderer fights.',
            'The Exchange page shows what sells most and least, how many of each item are about, and how prices moved this week.',
        ]],
        'money' => ['Pineapples, Apples and the Orchard', [
            'Pineapples are bought with real money through PayPal. Swap them for packs of Apples, or plant Apple trees with them.',
            'Apples are spent in shops that take them, on bounties, on horseshoes, on skins, on the Orchard, given to a faction, or swapped for Corn.',
            'You can also earn Apples: sell copies of your own designs for Apples, or catch outlaws and collect the bounties on them.',
            'Later in the game, Apples you earned can be paid out to your own PayPal account.',
            'The Orchard is a membership with extra shop shelves and a free daily horseshoe refill.',
            'Purchases are final. See the Terms and Conditions.',
        ]],
        'humans' => ['Irongate, Ironfield and the plunderers', [
            'Irongate is the humans\' town, where outlaws lie low and folk like Ma Vetch, Wick and Pell trade with animals.',
            'Ironfield is the humans\' mining country: missions there pay more the deeper you go, and the men guarding it get tougher.',
            'Every few hours in daylight, a band of plunderers rides out of Irongate for a farm. Every faction can fight them together: win and share their Corn; lose and they sack the farm, and the fighters are laid up a while (less with a Hospital).',
            'A ruling House can strike a corrupt deal with the humans: the Irongate Pact, painted on the Wall in the dark. Only the House and goats can see it there. While it stands, the House takes a share of whatever the plunderers carry off from the farms of other factions, and the valley grows angrier.',
            'On Gate Night, Sundays at 19:00, Ironfield\'s men raid the gate. If it holds, every defender is rewarded; if not, fields burn.',
        ]],
        'seasons' => ['Seasons, missions and rankings', [
            'A season lasts '.config('game.season_days').' days. Some things reset with a new season.',
            'Story missions walk you through the game, chapter by chapter; daily jobs change every day.',
            'Earn Rosettes to level up. The Rankings page shows the best traders, makers and most decorated animals.',
        ]],
        'help' => ['Help and fair play', [
            'See who\'s online on the Who\'s online page. Message any animal, or talk in chat.',
            'Something broken? Report a bug from the menu, with a screenshot or a short video.',
            'See a cheat? Report them for cheating from their profile or the map. The rules are on the No cheating page.',
        ]],
    ];
@endphp

@section('title', 'Wiki | '.config('app.name'))
@section('description', 'How Animal Farm works: animals, horseshoes, the map, factions, raids, laws, crime, homes, the Market, Apples and more.')
@section('canonical', route('wiki'))

@section('content')
    <article class="mx-auto flex max-w-[860px] flex-col gap-8 px-[clamp(16px,4vw,40px)] py-[clamp(40px,6vw,72px)]">
        <header class="flex flex-col gap-3">
            <h1 class="text-[clamp(32px,4.4vw,52px)] leading-[1.08] font-extrabold tracking-tight">The Animal Farm wiki</h1>
            <p class="text-lg text-ink-2">Everything about how the game works, in one place. The game changes often: see <a href="{{ route('updates') }}">Updates and fixes</a> for what's new.</p>
        </header>

        <nav aria-label="Contents" class="rounded-xl border border-rule-soft bg-paper px-5 py-4">
            <h2 class="mb-2 font-mono text-[12px] tracking-[0.12em] text-muted">CONTENTS</h2>
            <ol class="grid list-decimal gap-x-6 gap-y-1 pl-5 text-[15px] sm:grid-cols-2">
                @foreach ($sections as $id => [$heading])
                    <li><a href="#{{ $id }}">{{ $heading }}</a></li>
                @endforeach
            </ol>
        </nav>

        @foreach ($sections as $id => [$heading, $points])
            <section id="{{ $id }}" class="flex scroll-mt-6 flex-col gap-3">
                <h2 class="text-[24px] font-bold">{{ $heading }}</h2>
                <ul class="flex list-disc flex-col gap-2 pl-5 text-ink-2">
                    @foreach ($points as $point)
                        <li>{{ $point }}</li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </article>
@endsection
