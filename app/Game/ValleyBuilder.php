<?php

namespace App\Game;

use App\Models\Region;
use App\Models\Season;
use App\Models\Valley;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lays out a valley: the sixteen regions of Hollowmere on a five by three
 * grid with the Paddock along the bottom, the farms beyond its fences,
 * Irongate's three districts past the east fence,
 * which regions border which, and the first season. Running it again adds
 * only what is missing, so a new farm reaches a running valley.
 */
final class ValleyBuilder
{
    /**
     * key => [name, ring, produces, column, row]. Regions that touch on the
     * grid border each other; the Paddock borders the whole bottom row.
     */
    public const REGIONS = [
        'wildwood' => ['Wildwood', 'outer', 'wood', 0, 0],
        'orchard' => ['Orchard', 'middle', 'dye', 1, 0],
        'big_barn' => ['Big Barn', 'inner', 'straw', 2, 0],
        'hayfield' => ['Hayfield', 'middle', 'corn', 3, 0],
        'quarry' => ['Quarry', 'outer', 'stone', 4, 0],
        'sheepfold' => ['Sheepfold', 'outer', 'wool', 0, 1],
        'granary' => ['Granary', 'inner', 'corn', 1, 1],
        'farmhouse' => ['The Farmhouse', 'seat', null, 2, 1],
        'windmill_hill' => ['Windmill Hill', 'inner', 'corn', 3, 1],
        'ironfield_gate' => ['Ironfield Gate', 'outer', 'corn', 4, 1],
        'duck_pond' => ['Duck Pond', 'outer', 'water', 0, 2],
        'henhouse' => ['Henhouse', 'middle', 'eggs', 1, 2],
        'market_square' => ['Market Square', 'inner', 'corn', 2, 2],
        'stables' => ['Stables', 'middle', 'straw', 3, 2],
        'old_well' => ['Old Well', 'outer', 'water', 4, 2],
        'paddock' => ['The Paddock', 'safe', 'corn', 2, 3],
        // Farms beyond the fences: bought with Corn or taken in raids.
        // Each grows something found nowhere in Hollowmere.
        'thornbury' => ['Thornbury Farm', 'farm', 'honey', 0, 9],
        'brookside' => ['Brookside Farm', 'farm', 'herbs', 1, 9],
        'willowby' => ['Willowby Farm', 'farm', 'iron', 2, 9],
        // Wild land round each farm, cleared one patch at a time as its holder grows it.
        'thornbury_heath' => ['Thornbury Heath', 'farm', 'honey', 3, 9],
        'thornbury_hives' => ['Thornbury Hives', 'farm', 'honey', 4, 9],
        'thornbury_hollow' => ['Thornbury Hollow', 'farm', 'honey', 5, 9],
        'brookside_reeds' => ['Brookside Reeds', 'farm', 'herbs', 6, 9],
        'brookside_marsh' => ['Brookside Marsh', 'farm', 'herbs', 7, 9],
        'brookside_meadow' => ['Brookside Meadow', 'farm', 'herbs', 8, 9],
        'willowby_copse' => ['Willowby Copse', 'farm', 'iron', 9, 9],
        'willowby_forge' => ['Willowby Forge', 'farm', 'iron', 10, 9],
        'willowby_pits' => ['Willowby Pits', 'farm', 'iron', 11, 9],
        // The second ring of farms, out beyond the wild land round the first.
        'ashcombe' => ['Ashcombe Farm', 'farm', 'iron', 12, 9],
        'fennick' => ['Fennick Farm', 'farm', 'honey', 13, 9],
        'marlow' => ['Marlow Farm', 'farm', 'herbs', 14, 9],
        'ashcombe_ridge' => ['Ashcombe Ridge', 'farm', 'iron', 15, 9],
        'ashcombe_wood' => ['Ashcombe Wood', 'farm', 'iron', 16, 9],
        'ashcombe_spring' => ['Ashcombe Spring', 'farm', 'iron', 17, 9],
        'fennick_downs' => ['Fennick Downs', 'farm', 'honey', 18, 9],
        'fennick_moor' => ['Fennick Moor', 'farm', 'honey', 19, 9],
        'fennick_brake' => ['Fennick Brake', 'farm', 'honey', 20, 9],
        'marlow_glen' => ['Marlow Glen', 'farm', 'herbs', 21, 9],
        'marlow_leas' => ['Marlow Leas', 'farm', 'herbs', 22, 9],
        'marlow_mill' => ['Marlow Mill', 'farm', 'herbs', 23, 9],
        // The third ring, out at the edges of the valley, each with its wild land.
        'hartley' => ['Hartley Farm', 'farm', 'carrots', 24, 9],
        'northfold' => ['Northfold Farm', 'farm', 'pears', 25, 9],
        'coldharbour' => ['Coldharbour Farm', 'farm', 'gold', 26, 9],
        'rushmere' => ['Rushmere Farm', 'farm', 'berries', 27, 9],
        'southmere' => ['Southmere Farm', 'farm', 'pumpkins', 28, 9],
        'copperhill' => ['Copperhill Farm', 'farm', 'copper', 29, 9],
        'hartley_fold' => ['Hartley Fold', 'farm', 'carrots', 30, 9],
        'hartley_fen' => ['Hartley Fen', 'farm', 'carrots', 31, 9],
        'hartley_rise' => ['Hartley Rise', 'farm', 'carrots', 32, 9],
        'northfold_heath' => ['Northfold Heath', 'farm', 'pears', 33, 9],
        'northfold_hives' => ['Northfold Hives', 'farm', 'pears', 34, 9],
        'northfold_crag' => ['Northfold Crag', 'farm', 'pears', 35, 9],
        'coldharbour_scar' => ['Coldharbour Scar', 'farm', 'gold', 36, 9],
        'coldharbour_mine' => ['Coldharbour Mine', 'farm', 'gold', 37, 9],
        'coldharbour_moss' => ['Coldharbour Moss', 'farm', 'gold', 38, 9],
        'rushmere_bog' => ['Rushmere Bog', 'farm', 'berries', 39, 9],
        'rushmere_banks' => ['Rushmere Banks', 'farm', 'berries', 40, 9],
        'rushmere_carr' => ['Rushmere Carr', 'farm', 'berries', 41, 9],
        'southmere_dell' => ['Southmere Dell', 'farm', 'pumpkins', 42, 9],
        'southmere_gardens' => ['Southmere Gardens', 'farm', 'pumpkins', 43, 9],
        'southmere_weald' => ['Southmere Weald', 'farm', 'pumpkins', 44, 9],
        'copperhill_workings' => ['Copperhill Workings', 'farm', 'copper', 45, 9],
        'copperhill_tor' => ['Copperhill Tor', 'farm', 'copper', 46, 9],
        'copperhill_slag' => ['Copperhill Slag', 'farm', 'copper', 47, 9],
        // Irongate, the humans' town past Ironfield Gate: nobody holds it and nothing grows there.
        'irongate_rookery' => ['The Rookery', 'city', null, 0, 10],
        'irongate_yards' => ['The Iron Yards', 'city', null, 1, 10],
        'irongate_garrison' => ['The Garrison', 'city', null, 2, 10],
        'irongate_smithy' => ['Smithy Row', 'city', null, 3, 10],
        'irongate_tavern' => ['The Black Dog Tavern', 'city', null, 4, 10],
        'irongate_gallows' => ['Gallows Square', 'city', null, 5, 10],
        // The frontier: wild land filling the valley round the farms, open to all.
        'glitter_gully' => ['Glitter Gully', 'frontier', 'gold', 0, 11],
        'blackthorn_wood' => ['Blackthorn Wood', 'frontier', 'wood', 1, 11],
        'mirror_ponds' => ['Mirror Ponds', 'frontier', 'water', 2, 11],
        'bramble_heath' => ['Bramble Heath', 'frontier', 'dye', 3, 11],
        'grey_scree' => ['Grey Scree', 'frontier', 'stone', 4, 11],
        'long_meadow' => ['Long Meadow', 'frontier', 'wool', 5, 11],
        'rookhollow_wood' => ['Rookhollow Wood', 'frontier', 'wood', 6, 11],
        'heron_mere' => ['Heron Mere', 'frontier', 'water', 7, 11],
        'gorse_common' => ['Gorse Common', 'frontier', 'dye', 8, 11],
        'flint_downs' => ['Flint Downs', 'frontier', 'stone', 9, 11],
        'sheep_down' => ['Sheep Down', 'frontier', 'wool', 10, 11],
        'gallows_copse' => ['Gallows Copse', 'frontier', 'wood', 11, 11],
        'silt_pools' => ['Silt Pools', 'frontier', 'clay', 12, 11],
        'thistle_down' => ['Thistle Down', 'frontier', 'dye', 13, 11],
        'cairn_hill' => ['Cairn Hill', 'frontier', 'stone', 14, 11],
        'old_copper_seam' => ['Old Copper Seam', 'frontier', 'copper', 15, 11],
        'cowslip_lea' => ['Cowslip Lea', 'frontier', 'wool', 16, 11],
        'ashwood' => ['Ashwood', 'frontier', 'wood', 17, 11],
        'eelwater' => ['Eelwater', 'frontier', 'water', 18, 11],
        'wild_hives' => ['Wild Hives', 'frontier', 'honey', 19, 11],
        'briar_scrub' => ['Briar Scrub', 'frontier', 'dye', 20, 11],
        'rattle_rocks' => ['Rattle Rocks', 'frontier', 'stone', 21, 11],
        'windy_common' => ['Windy Common', 'frontier', 'wool', 22, 11],
        'hazel_hanger' => ['Hazel Hanger', 'frontier', 'wood', 23, 11],
        'berry_brake' => ['Berry Brake', 'frontier', 'berries', 24, 11],
        // Smaller patches filling the gaps.
        'ash_glade' => ['Ash Glade', 'frontier', 'wool', 25, 11],
        'bramble_thicket' => ['Bramble Thicket', 'frontier', 'dye', 26, 11],
        'crow_spring' => ['Crow Spring', 'frontier', 'water', 27, 11],
        'dun_spinney' => ['Dun Spinney', 'frontier', 'wood', 28, 11],
        'elder_camp_field' => ['Elder Camp Field', 'frontier', 'wool', 29, 11],
        'fern_knoll' => ['Fern Knoll', 'frontier', 'stone', 30, 11],
        'gorse_glade' => ['Gorse Glade', 'frontier', 'wool', 31, 11],
        'hare_thicket' => ['Hare Thicket', 'frontier', 'dye', 32, 11],
        'ivy_spring' => ['Ivy Spring', 'frontier', 'water', 33, 11],
        'juniper_spinney' => ['Juniper Spinney', 'frontier', 'wood', 34, 11],
        'kestrel_camp_field' => ['Kestrel Camp Field', 'frontier', 'wool', 35, 11],
        'lark_knoll' => ['Lark Knoll', 'frontier', 'stone', 36, 11],
        'moss_glade' => ['Moss Glade', 'frontier', 'wool', 37, 11],
        'nettle_thicket' => ['Nettle Thicket', 'frontier', 'dye', 38, 11],
        'oak_spring' => ['Oak Spring', 'frontier', 'water', 39, 11],
        'pike_spinney' => ['Pike Spinney', 'frontier', 'wood', 40, 11],
        'quill_camp_field' => ['Quill Camp Field', 'frontier', 'wool', 41, 11],
        'reed_knoll' => ['Reed Knoll', 'frontier', 'stone', 42, 11],
        'sedge_glade' => ['Sedge Glade', 'frontier', 'wool', 43, 11],
        'thorn_thicket' => ['Thorn Thicket', 'frontier', 'dye', 44, 11],
        'umber_spring' => ['Umber Spring', 'frontier', 'water', 45, 11],
        'vetch_spinney' => ['Vetch Spinney', 'frontier', 'wood', 46, 11],
        'willow_camp_field' => ['Willow Camp Field', 'frontier', 'wool', 47, 11],
        'yarrow_knoll' => ['Yarrow Knoll', 'frontier', 'stone', 48, 11],
        'alder_glade' => ['Alder Glade', 'frontier', 'wool', 49, 11],
        'birch_thicket' => ['Birch Thicket', 'frontier', 'dye', 50, 11],
        'clover_spring' => ['Clover Spring', 'frontier', 'water', 51, 11],
        'dock_spinney' => ['Dock Spinney', 'frontier', 'wood', 52, 11],
        'ember_camp_field' => ['Ember Camp Field', 'frontier', 'wool', 53, 11],
        'flax_knoll' => ['Flax Knoll', 'frontier', 'stone', 54, 11],
        'gale_glade' => ['Gale Glade', 'frontier', 'wool', 55, 11],
        'heather_thicket' => ['Heather Thicket', 'frontier', 'dye', 56, 11],
        'iris_spring' => ['Iris Spring', 'frontier', 'water', 57, 11],
        'kale_spinney' => ['Kale Spinney', 'frontier', 'wood', 58, 11],
        'linden_camp_field' => ['Linden Camp Field', 'frontier', 'wool', 59, 11],
        'mallow_knoll' => ['Mallow Knoll', 'frontier', 'stone', 60, 11],
        'nut_glade' => ['Nut Glade', 'frontier', 'wool', 61, 11],
        'otter_thicket' => ['Otter Thicket', 'frontier', 'dye', 62, 11],
        'poppy_spring' => ['Poppy Spring', 'frontier', 'water', 63, 11],
        'rush_spinney' => ['Rush Spinney', 'frontier', 'wood', 64, 11],
        'sorrel_camp_field' => ['Sorrel Camp Field', 'frontier', 'wool', 65, 11],
        'teasel_knoll' => ['Teasel Knoll', 'frontier', 'stone', 66, 11],
        'vole_glade' => ['Vole Glade', 'frontier', 'wool', 67, 11],
        'wren_thicket' => ['Wren Thicket', 'frontier', 'dye', 68, 11],
        'badger_spring' => ['Badger Spring', 'frontier', 'water', 69, 11],
        'cress_spinney' => ['Cress Spinney', 'frontier', 'wood', 70, 11],
        'daisy_camp_field' => ['Daisy Camp Field', 'frontier', 'wool', 71, 11],
        'finch_knoll' => ['Finch Knoll', 'frontier', 'stone', 72, 11],
        'grouse_glade' => ['Grouse Glade', 'frontier', 'wool', 73, 11],
        'holly_thicket' => ['Holly Thicket', 'frontier', 'dye', 74, 11],
        'lynx_spring' => ['Lynx Spring', 'frontier', 'water', 75, 11],
        'marten_spinney' => ['Marten Spinney', 'frontier', 'wood', 76, 11],
        'newt_camp_field' => ['Newt Camp Field', 'frontier', 'wool', 77, 11],
        'owl_knoll' => ['Owl Knoll', 'frontier', 'stone', 78, 11],
        'plover_glade' => ['Plover Glade', 'frontier', 'wool', 79, 11],
        'robin_thicket' => ['Robin Thicket', 'frontier', 'dye', 80, 11],
        'stoat_spring' => ['Stoat Spring', 'frontier', 'water', 81, 11],
        'tansy_spinney' => ['Tansy Spinney', 'frontier', 'wood', 82, 11],
        // Ironfield, the humans' mining country east of Irongate: nobody holds it, and the deeper in, the more men guard it.
        'ironfield_road' => ['Ironfield Road', 'ironfield', 'iron', 0, 13],
        'ironfield_carts' => ['Ore Cart Track', 'ironfield', 'iron', 1, 13],
        'ironfield_foundry' => ['Foundry Yard', 'ironfield', 'iron', 2, 13],
        'ironfield_kilns' => ['The Kilns', 'ironfield', 'stone', 3, 13],
        'ironfield_slag' => ['Slag Heaps', 'ironfield', 'copper', 4, 13],
        'ironfield_pithead' => ['The Pithead', 'ironfield', 'iron', 5, 13],
        'ironfield_lodge' => ["Overseer's Lodge", 'ironfield', 'copper', 6, 13],
        'ironfield_shaft' => ['Black Shaft', 'ironfield', 'gold', 7, 13],
        'ironfield_pit' => ['The Deep Pit', 'ironfield', 'gold', 8, 13],
        'ironfield_chains' => ['Chain Barracks', 'ironfield', 'iron', 9, 13],
        // The open scrub between all the rest, out to the valley's edge: anyone stands, gathers and camps there.
        'scrubland' => ['The Scrubland', 'scrub', 'wood', 0, 12],
    ];

    /** What each frontier region borders: its nearest land. */
    public const FRONTIER_LINKS = [
        'glitter_gully' => ['hartley_fen', 'blackthorn_wood'],
        'blackthorn_wood' => ['windy_common', 'hartley_fen'],
        'mirror_ponds' => ['long_meadow', 'northfold_crag'],
        'bramble_heath' => ['windy_common', 'hartley_rise'],
        'grey_scree' => ['northfold_crag', 'mirror_ponds'],
        'long_meadow' => ['mirror_ponds', 'coldharbour_mine'],
        'rookhollow_wood' => ['hartley_rise', 'bramble_heath'],
        'heron_mere' => ['rookhollow_wood', 'hartley'],
        'gorse_common' => ['hartley_fold', 'hartley'],
        'flint_downs' => ['fennick_moor', 'fennick_downs'],
        'sheep_down' => ['rushmere_bog', 'rushmere'],
        'gallows_copse' => ['hazel_hanger', 'brookside_meadow'],
        'silt_pools' => ['hazel_hanger', 'rushmere_carr'],
        'thistle_down' => ['marlow_mill', 'southmere_weald'],
        'cairn_hill' => ['copperhill_slag', 'eelwater'],
        'old_copper_seam' => ['rushmere_banks', 'wild_hives'],
        'cowslip_lea' => ['berry_brake', 'rushmere_banks'],
        'ashwood' => ['southmere_dell', 'silt_pools'],
        'eelwater' => ['southmere_weald', 'rattle_rocks'],
        'wild_hives' => ['old_copper_seam', 'berry_brake'],
        'briar_scrub' => ['northfold_heath', 'ashcombe_ridge'],
        'rattle_rocks' => ['eelwater', 'cairn_hill'],
        'windy_common' => ['bramble_heath', 'blackthorn_wood'],
        'hazel_hanger' => ['silt_pools', 'rushmere_carr'],
        'berry_brake' => ['cowslip_lea', 'wild_hives'],
        'ash_glade' => ['mirror_ponds', 'long_meadow'],
        'bramble_thicket' => ['rush_spinney', 'glitter_gully'],
        'crow_spring' => ['coldharbour_mine', 'thorn_thicket'],
        'dun_spinney' => ['ashcombe_wood', 'northfold'],
        'elder_camp_field' => ['long_meadow', 'coldharbour_mine'],
        'fern_knoll' => ['vole_glade', 'ashcombe_ridge'],
        'gorse_glade' => ['vetch_spinney', 'wren_thicket'],
        'hare_thicket' => ['flint_downs', 'gorse_common'],
        'ivy_spring' => ['badger_spring', 'willow_camp_field'],
        'juniper_spinney' => ['cress_spinney', 'kestrel_camp_field'],
        'kestrel_camp_field' => ['coldharbour_moss', 'daisy_camp_field'],
        'lark_knoll' => ['fennick_moor', 'moss_glade'],
        'moss_glade' => ['lark_knoll', 'fennick_moor'],
        'nettle_thicket' => ['grouse_glade', 'finch_knoll'],
        'oak_spring' => ['robin_thicket', 'copperhill_workings'],
        'pike_spinney' => ['owl_knoll', 'rushmere_bog'],
        'quill_camp_field' => ['ember_camp_field', 'silt_pools'],
        'reed_knoll' => ['robin_thicket', 'rattle_rocks'],
        'sedge_glade' => ['hartley_fen', 'poppy_spring'],
        'thorn_thicket' => ['sorrel_camp_field', 'grey_scree'],
        'umber_spring' => ['hartley_rise', 'heron_mere'],
        'vetch_spinney' => ['daisy_camp_field', 'gorse_glade'],
        'willow_camp_field' => ['ivy_spring', 'fennick_downs'],
        'yarrow_knoll' => ['birch_thicket', 'copperhill_tor'],
        'alder_glade' => ['clover_spring', 'willowby_copse'],
        'birch_thicket' => ['yarrow_knoll', 'willowby_pits'],
        'clover_spring' => ['alder_glade', 'hazel_hanger'],
        'dock_spinney' => ['marlow_leas', 'southmere'],
        'ember_camp_field' => ['southmere_dell', 'silt_pools'],
        'flax_knoll' => ['southmere_weald', 'thistle_down'],
        'gale_glade' => ['berry_brake', 'ashwood'],
        'heather_thicket' => ['southmere_dell', 'ashwood'],
        'iris_spring' => ['southmere_weald', 'eelwater'],
        'kale_spinney' => ['glitter_gully', 'blackthorn_wood'],
        'linden_camp_field' => ['windy_common', 'mallow_knoll'],
        'mallow_knoll' => ['northfold_heath', 'linden_camp_field'],
        'nut_glade' => ['northfold_crag', 'otter_thicket'],
        'otter_thicket' => ['northfold_crag', 'nut_glade'],
        'poppy_spring' => ['hartley_rise', 'blackthorn_wood'],
        'rush_spinney' => ['hartley_fold', 'bramble_thicket'],
        'sorrel_camp_field' => ['thorn_thicket', 'ashcombe_spring'],
        'teasel_knoll' => ['badger_spring', 'heron_mere'],
        'vole_glade' => ['thornbury_heath', 'fern_knoll'],
        'wren_thicket' => ['gorse_glade', 'thornbury_hollow'],
        'badger_spring' => ['teasel_knoll', 'ivy_spring'],
        'cress_spinney' => ['juniper_spinney', 'coldharbour_moss'],
        'daisy_camp_field' => ['vetch_spinney', 'coldharbour_moss'],
        'finch_knoll' => ['nettle_thicket', 'copperhill_tor'],
        'grouse_glade' => ['nettle_thicket', 'copperhill_workings'],
        'holly_thicket' => ['rushmere', 'fennick_brake'],
        'lynx_spring' => ['plover_glade', 'marlow_mill'],
        'marten_spinney' => ['newt_camp_field', 'rushmere_carr'],
        'newt_camp_field' => ['marten_spinney', 'rushmere_carr'],
        'owl_knoll' => ['rushmere_bog', 'rushmere_banks'],
        'plover_glade' => ['lynx_spring', 'marlow_mill'],
        'robin_thicket' => ['oak_spring', 'reed_knoll'],
        'stoat_spring' => ['ashwood', 'silt_pools'],
        'tansy_spinney' => ['southmere_dell', 'southmere_gardens'],
    ];

    /** Ironfield's regions and what each borders: the road runs out of Irongate's east districts. */
    public const IRONFIELD_LINKS = [
        'ironfield_road' => ['irongate_smithy', 'irongate_tavern', 'irongate_gallows', 'ironfield_carts', 'ironfield_foundry', 'ironfield_kilns'],
        'ironfield_carts' => [],
        'ironfield_foundry' => ['ironfield_carts'],
        'ironfield_kilns' => ['ironfield_foundry'],
        'ironfield_slag' => ['ironfield_carts'],
        'ironfield_pithead' => ['ironfield_slag', 'ironfield_foundry'],
        'ironfield_lodge' => ['ironfield_pithead', 'ironfield_kilns'],
        'ironfield_shaft' => ['ironfield_slag'],
        'ironfield_pit' => ['ironfield_shaft', 'ironfield_pithead'],
        'ironfield_chains' => ['ironfield_pit', 'ironfield_lodge'],
    ];

    /** Irongate's districts and what each borders: the Yards open onto Ironfield Gate and the other two. */
    public const CITY_LINKS = [
        'irongate_yards' => ['ironfield_gate', 'irongate_rookery', 'irongate_garrison', 'irongate_tavern'],
        'irongate_smithy' => ['irongate_rookery', 'irongate_tavern'],
        'irongate_tavern' => ['irongate_gallows'],
        'irongate_gallows' => ['irongate_garrison'],
    ];

    /** Which farm each patch of wild land belongs to, in the order it is cleared. */
    public const FIELD_OF = [
        'thornbury_heath' => 'thornbury',
        'thornbury_hives' => 'thornbury',
        'thornbury_hollow' => 'thornbury',
        'brookside_reeds' => 'brookside',
        'brookside_marsh' => 'brookside',
        'brookside_meadow' => 'brookside',
        'willowby_copse' => 'willowby',
        'willowby_forge' => 'willowby',
        'willowby_pits' => 'willowby',
        'ashcombe_ridge' => 'ashcombe',
        'ashcombe_wood' => 'ashcombe',
        'ashcombe_spring' => 'ashcombe',
        'fennick_downs' => 'fennick',
        'fennick_moor' => 'fennick',
        'fennick_brake' => 'fennick',
        'marlow_glen' => 'marlow',
        'marlow_leas' => 'marlow',
        'marlow_mill' => 'marlow',
        'hartley_fold' => 'hartley',
        'hartley_fen' => 'hartley',
        'hartley_rise' => 'hartley',
        'northfold_heath' => 'northfold',
        'northfold_hives' => 'northfold',
        'northfold_crag' => 'northfold',
        'coldharbour_scar' => 'coldharbour',
        'coldharbour_mine' => 'coldharbour',
        'coldharbour_moss' => 'coldharbour',
        'rushmere_bog' => 'rushmere',
        'rushmere_banks' => 'rushmere',
        'rushmere_carr' => 'rushmere',
        'southmere_dell' => 'southmere',
        'southmere_gardens' => 'southmere',
        'southmere_weald' => 'southmere',
        'copperhill_workings' => 'copperhill',
        'copperhill_tor' => 'copperhill',
        'copperhill_slag' => 'copperhill',
    ];

    /** Which Hollowmere regions each farm borders. */
    public const FARM_LINKS = [
        'thornbury' => ['orchard', 'big_barn', 'hayfield'],
        'brookside' => ['wildwood', 'sheepfold', 'duck_pond'],
        'willowby' => ['paddock'],
        'thornbury_heath' => ['thornbury'],
        'thornbury_hives' => ['thornbury'],
        'thornbury_hollow' => ['thornbury'],
        'brookside_reeds' => ['brookside'],
        'brookside_marsh' => ['brookside'],
        'brookside_meadow' => ['brookside'],
        'willowby_copse' => ['willowby'],
        'willowby_forge' => ['willowby'],
        'willowby_pits' => ['willowby'],
        // The second ring is reached from the first ring's farms.
        'ashcombe' => ['thornbury'],
        'fennick' => ['brookside'],
        'marlow' => ['willowby'],
        'ashcombe_ridge' => ['ashcombe'],
        'ashcombe_wood' => ['ashcombe'],
        'ashcombe_spring' => ['ashcombe'],
        'fennick_downs' => ['fennick'],
        'fennick_moor' => ['fennick'],
        'fennick_brake' => ['fennick'],
        'marlow_glen' => ['marlow'],
        'marlow_leas' => ['marlow'],
        'marlow_mill' => ['marlow'],
        // The third ring is reached from the second ring's farms.
        'hartley' => ['fennick'],
        'northfold' => ['ashcombe'],
        'coldharbour' => ['ashcombe'],
        'rushmere' => ['marlow'],
        'southmere' => ['marlow'],
        'copperhill' => ['willowby'],
        'hartley_fold' => ['hartley'],
        'hartley_fen' => ['hartley'],
        'hartley_rise' => ['hartley'],
        'northfold_heath' => ['northfold'],
        'northfold_hives' => ['northfold'],
        'northfold_crag' => ['northfold'],
        'coldharbour_scar' => ['coldharbour'],
        'coldharbour_mine' => ['coldharbour'],
        'coldharbour_moss' => ['coldharbour'],
        'rushmere_bog' => ['rushmere'],
        'rushmere_banks' => ['rushmere'],
        'rushmere_carr' => ['rushmere'],
        'southmere_dell' => ['southmere'],
        'southmere_gardens' => ['southmere'],
        'southmere_weald' => ['southmere'],
        'copperhill_workings' => ['copperhill'],
        'copperhill_tor' => ['copperhill'],
        'copperhill_slag' => ['copperhill'],
    ];

    public function build(string $name = 'Hollowmere'): Valley
    {
        return DB::transaction(function () use ($name) {
            $valley = Valley::query()->firstOrCreate(['slug' => str($name)->slug()->toString()], ['name' => $name]);

            foreach (self::REGIONS as $key => [$regionName, $ring, $produces, $col, $row]) {
                // Kept in step with the list, so a region's crop can change; who holds it never does here.
                $region = Region::query()->updateOrCreate(
                    ['valley_id' => $valley->id, 'key' => $key],
                    ['name' => $regionName, 'ring' => $ring, 'produces' => $produces, 'col' => $col, 'row' => $row],
                );

                // A farm's patches start wild; once cleared they stay cleared.
                if ($region->wasRecentlyCreated && isset(self::FIELD_OF[$key])) {
                    $region->forceFill(['wild' => true])->save();
                } elseif ($region->wasRecentlyCreated && $ring === 'farm') {
                    // A new farm starts with a day's water.
                    $region->forceFill(['watered_until' => now()->addDay()])->save();
                }
            }

            $this->link($valley);

            if ($valley->currentSeason() === null) {
                $this->startSeason($valley, 1);
            }

            return $valley;
        });
    }

    public function startSeason(Valley $valley, int $number): Season
    {
        $now = CarbonImmutable::now();

        return Season::create([
            'valley_id' => $valley->id,
            'number' => $number,
            'starts_at' => $now,
            'ends_at' => $now->addDays((int) config('game.season_days')),
        ]);
    }

    private function link(Valley $valley): void
    {
        $regions = $valley->regions()->get()->keyBy('key');
        $links = [];

        foreach ($regions as $region) {
            foreach ($regions as $other) {
                if ($region->is($other)) {
                    continue;
                }

                if ($this->touches($region, $other)) {
                    $links[] = ['region_id' => $region->id, 'neighbour_id' => $other->id];
                }
            }
        }

        DB::table('region_links')->insertOrIgnore($links);
    }

    private function touches(Region $a, Region $b): bool
    {
        // The scrub lies round everything and borders nothing: nobody raids from it or into it.
        if ($a->isScrub() || $b->isScrub()) {
            return false;
        }

        if ($a->isIronfield() || $b->isIronfield()) {
            return in_array($b->key, self::IRONFIELD_LINKS[$a->key] ?? [], true)
                || in_array($a->key, self::IRONFIELD_LINKS[$b->key] ?? [], true);
        }

        if ($a->ring === 'frontier' || $b->ring === 'frontier') {
            return in_array($b->key, self::FRONTIER_LINKS[$a->key] ?? [], true)
                || in_array($a->key, self::FRONTIER_LINKS[$b->key] ?? [], true);
        }

        if ($a->isCity() || $b->isCity()) {
            return in_array($b->key, self::CITY_LINKS[$a->key] ?? [], true)
                || in_array($a->key, self::CITY_LINKS[$b->key] ?? [], true);
        }

        if ($a->isFarm() || $b->isFarm()) {
            return in_array($b->key, self::FARM_LINKS[$a->key] ?? [], true)
                || in_array($a->key, self::FARM_LINKS[$b->key] ?? [], true);
        }

        if ($a->isSafe() || $b->isSafe()) {
            $other = $a->isSafe() ? $b : $a;

            return $other->row === 2;
        }

        return abs($a->col - $b->col) + abs($a->row - $b->row) === 1;
    }
}
