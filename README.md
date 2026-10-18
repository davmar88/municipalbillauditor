# RevenueGuard

An online strategy game for the phone's browser, set on a farm the humans left behind and inspired by a classic fable. Players pick an animal, join factions and fight for the Farmhouse. Whoever holds it writes the laws on the Barn Wall, and once a week the rulers may secretly change one. Players who catch it can bring them down.

The design, and the decisions behind it, are in the game design document: https://claude.ai/code/artifact/84371daa-165d-4285-a438-94da064ddd77

## What is in the test season

- **The valley:** Hollowmere, 16 regions on a 5 by 3 grid with the Paddock for newcomers (`app/Game/ValleyBuilder.php`), drawn as a street map you drag and zoom, with fields, woods, Hollow Brook, buildings and named lanes (`resources/js/components/game/world-map.tsx`, shapes in `resources/js/lib/hollowmere.ts`).
- **Walking:** every animal stands in one region and walks to a neighbour for free, one region at a time; gathering and raids cost horseshoes. You gather where you stand, and raids start from a region next to the target (`app/Game/Movement.php`).
- **Animals:** pig, dog, horse, sheep and goat, each with a talent and a weak spot (`app/Enums/Species.php`).
- **Horseshoes:** up to 10, one back every 45 minutes; every action costs some.
- **Factions:** founded by a player, with their own joining rules (open or by approval, minimum level, species, entry fee, criminals or not).
- **The Barn Wall:** the House's five laws from a fixed menu, the unrest each adds, festivals, sheep chants, and the weekly secret edit players expose with their saved copies (`app/Game/Wall.php`).
- **Raids, uprisings and Gate Night:** attacks march for an hour and settle at the tick; at unrest 60 anyone can call an uprising; every Sunday at 19:00 the humans raid.
- **Lone wolves:** loot faction treasuries and burn homes, and risk the pound.
- **The market:** design items in the Workshop, make numbered copies from gathered materials, sell them from your shop for Corn. Real money comes after the test season.
- **Recipes with powers:** eleven Workshop shapes carry a power that works when the owner uses a copy up: Gathering Gloves, Iron Shoes, Night Cloak, Willow Tonic and Prize Ribbon for the animal; War Banner, Thorn Hedge, Feast Basket, Watch Bell, Mason's Trowel and Golden Sheaf for its faction or the House. Stronger recipes open at Creativity levels 2 and 3. None of them gives horseshoes (`app/Enums/ItemTemplate.php`, `app/Game/Powers.php`).
- **Messages, pop-ups and the bell:** tap any animal's name on the map to talk in a chat window over the screen; new messages and news pop up wherever you are, and the bell at the top keeps the news (raids on your land, battles, sales, requests to join, crimes against you, new laws and uprisings) (`app/Game/Notices.php`, `resources/js/components/game/player-pulse.tsx`).
- **Chat:** the valley square, your faction, and a channel with each other faction. The Translate button uses Claude when `ANTHROPIC_API_KEY` is set.
- **Rosettes and levels:** profile points from playing, selling and serving the valley.
- **Spot the Edit:** a free daily puzzle at `/spot-the-edit`.

## Running it

```bash
composer install
npm ci
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan game:setup        # lays out Hollowmere and starts season 1
npm run build
php artisan serve
```

The game moves on every half hour with `php artisan game:tick`, which the scheduler runs (`routes/console.php`). Staff check new makers' designs at `/staff/designs`; make a staff account with `php artisan app:make-staff you@example.com`.

## Tests

`php artisan test` runs against the database named in `phpunit.xml`. The game rules are covered in `tests/Feature/Game`.

Deploying is described in `DEPLOY.md`.
