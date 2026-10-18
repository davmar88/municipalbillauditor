# RevenueGuard

An online strategy game for the phone's browser, set on a farm the humans left behind and inspired by a classic fable. Players pick an animal, join factions and fight for the Farmhouse. Whoever holds it writes the laws on the Barn Wall, and once a week the rulers may secretly change one. Players who catch it can bring them down.

The design, and the decisions behind it, are in the game design document: https://claude.ai/code/artifact/84371daa-165d-4285-a438-94da064ddd77

## What is in the test season

- **The valley:** Hollowmere, 16 regions on a 5 by 3 grid with the Paddock for newcomers (`app/Game/ValleyBuilder.php`), drawn as a street map you drag and zoom, with fields, woods, Hollow Brook, buildings and named lanes (`resources/js/components/game/world-map.tsx`, shapes in `resources/js/lib/hollowmere.ts`).
- **Farms beyond the fences:** Thornbury, Brookside and Willowby Farms border Hollowmere. A faction's leader or council buys an unclaimed farm for 300 Corn from the treasury, or it is raided away like any land; each pays its holder 5 Corn every half hour and grows what Hollowmere doesn't: honey at Thornbury, herbs at Brookside, iron at Willowby. Anyone gathers it while a farm is unclaimed, only its holders after. The faction holding a farm can grow it three times; each growth clears a patch of the wild land round it into a new region of the farm (Thornbury Heath, Hives and Hollow; Brookside Reeds, Marsh and Meadow; Willowby Copse, Forge and Pits), raided only from the farm. Six recipes need these: Honey Cake, Iron Pickaxe, Iron Lock, Healer's Kit, Iron Gate and Iron Plough (`app/Game/Farms.php`).
- **Walking:** every animal stands in one region and walks to a neighbour for free, one region at a time; gathering and raids cost horseshoes. You gather where you stand, and raids start from a region next to the target (`app/Game/Movement.php`).
- **Animals:** pig, dog, horse, sheep and goat, each with a talent and a weak spot (`app/Enums/Species.php`).
- **Horseshoes:** up to 10, one back every 45 minutes; every action costs some.
- **Factions:** founded by a player, with their own joining rules (open or by approval, minimum level, species, entry fee, criminals or not).
- **The Barn Wall:** the House's five laws from a fixed menu, the unrest each adds, festivals, sheep chants, and the weekly secret edit players expose with their saved copies (`app/Game/Wall.php`).
- **Raids, uprisings and Gate Night:** attacks march for an hour and settle at the tick; at unrest 60 anyone can call an uprising; every Sunday at 19:00 the humans raid.
- **Lone wolves and the law:** lone wolves loot faction treasuries, burn homes and plunder the farm sections they stand on (crop and Corn), and risk the pound. The House's informants name recent criminals; its leader or council puts out warrants with a bounty of Apples from the House's store, anyone can add Apples to a bounty, and any animal standing with an outlaw can try an arrest that sends them to the pound and pays the bounty, less the game's 20% (`app/Game/Warrants.php`, the Wanted page).
- **The market:** design items in the Workshop, make numbered copies from gathered materials, sell them from your shop for Corn or Apples. Corn sales pay the House's market levy; on an Apple sale the seller gets 85% and the game keeps 15%, and a maker's 5% royalty on a resale comes out of the seller's share (`app/Game/Market.php`).
- **Apples:** bought with real money in packs of 50 to 1,000 on the Apples page, with Paystack in rand or PayPal in dollars; bigger packs save up to 20%. Apples buy shop items priced in Apples and Orchard membership, pay bounties, fill a faction's store, and swap for Corn at 10 each, one way only. The game never hands Apples out free, so every Apple was bought by someone. Prices are in `config/game.php`; the payment code is in `app/Payments`.
- **Orchard membership:** 60 Apples for 31 days, renewed by hand, with one free horseshoe refill a day, a 🌳 badge by the member's name and a seventh shop shelf (`app/Game/Orchard.php`, on the Apples page).
- **Cashing out:** once the owner opens it, an animal cashes Apples out at R0.60 each by bank transfer (Paystack), or $0.036 each by PayPal: less than the cheapest Apple costs, so every cash-out leaves the game ahead. Payout details need the animal's own name and ID or passport number (a South African ID must pass its check digit and show 18 or older), Paystack checks a bank account belongs to that name and ID, and everything is stored encrypted. Cash-outs run 500 to 5,000 Apples a day, one at a time, and Apples an animal bought wait 14 days in case the card payment is reversed. The Apples leave the balance when the animal asks and come back if the owner turns it down or the payout fails (`app/Payments/Cashouts.php`).
- **The admin pages:** at `/admin`, the owner pastes the Paystack, PayPal and Claude keys (stored encrypted), tests them, opens Apple sales and cash-outs, and sees what sales brought in. At `/admin/cashouts`, every cash-out waits for the owner: Pay sends it through Paystack or PayPal, and Turn down gives the Apples back. A cash-out's reference goes with it, and both providers refuse a second payout with the same reference, so a lost answer can be checked or sent again without paying twice.
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

The game moves on every half hour with `php artisan game:tick`, which the scheduler runs (`routes/console.php`). Staff check new makers' designs at `/staff/designs`; make a staff account with `php artisan app:make-staff you@example.com`. The owner's admin page is at `/admin`; open it to an account with `php artisan app:make-admin you@example.com`.

## Tests

`php artisan test` runs against the database named in `phpunit.xml`. The game rules are covered in `tests/Feature/Game`.

Deploying is described in `DEPLOY.md`.
