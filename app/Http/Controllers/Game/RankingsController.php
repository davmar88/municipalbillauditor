<?php

namespace App\Http\Controllers\Game;

use App\Game\Levels;
use App\Game\Skills;
use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\RosetteEntry;
use App\Models\SkillEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The leaderboards: the valley's best traders, best makers, and most
 * decorated animals, this season or of all time.
 */
class RankingsController extends Controller
{
    public const BOARDS = ['market', 'creativity', 'rosettes'];

    public const TOP = 50;

    public function index(Request $request): Response
    {
        /** @var Player $player */
        $player = $request->user()->player;
        $board = in_array($request->query('board'), self::BOARDS, true) ? $request->query('board') : 'market';
        $season = $player->valley->currentSeason();
        $scope = $request->query('scope') === 'all' || $season === null ? 'all' : 'season';

        $points = $this->points($player->valley_id, $board, $scope === 'season' ? $season->id : null);
        $ranked = $points->sortDesc()->keys()->values();
        $top = $ranked->take(self::TOP);
        $players = Player::query()
            ->with('faction:id,name,colour')
            ->whereKey($top->push($player->id)->unique())
            ->get()
            ->keyBy('id');

        $row = fn (int $id, int $rank) => [
            'rank' => $rank,
            'id' => $id,
            'name' => $players[$id]->name,
            'species' => $players[$id]->species->value,
            'faction' => $players[$id]->faction ? ['name' => $players[$id]->faction->name, 'colour' => $players[$id]->faction->colour] : null,
            'points' => (int) $points[$id],
            'title' => $this->title($board, $players[$id]),
            'me' => $id === $player->id,
        ];

        $myRank = $ranked->search($player->id);

        return Inertia::render('game/rankings', [
            'board' => $board,
            'scope' => $scope,
            'season' => $season?->number,
            'rows' => $ranked->take(self::TOP)->values()->map(fn (int $id, int $i) => $row($id, $i + 1)),
            'me' => $myRank !== false ? $row($player->id, $myRank + 1) : null,
            'skills' => array_map(fn (string $skill) => Skills::card($player, $skill), Skills::ALL),
        ]);
    }

    /**
     * Points per player on a board, only animals who have any.
     *
     * @return Collection<int, int>
     */
    private function points(int $valleyId, string $board, ?int $seasonId): Collection
    {
        $inValley = Player::query()->select('id')->where('valley_id', $valleyId);

        if ($seasonId === null) {
            $column = $board === 'rosettes' ? 'rosettes' : "{$board}_skill";

            return Player::query()
                ->where('valley_id', $valleyId)
                ->where($column, '>', 0)
                ->orderByDesc($column)
                ->orderBy('id')
                ->limit(1000)
                ->pluck($column, 'id')
                ->map(fn ($value) => (int) $value);
        }

        $query = $board === 'rosettes'
            ? RosetteEntry::query()
            : SkillEntry::query()->where('skill', $board);

        return $query
            ->where('season_id', $seasonId)
            ->whereIn('player_id', $inValley)
            ->groupBy('player_id')
            ->selectRaw('player_id, SUM(amount) as total')
            ->havingRaw('SUM(amount) > 0')
            ->orderByDesc('total')
            ->orderBy('player_id')
            ->limit(1000)
            ->pluck('total', 'player_id')
            ->map(fn ($value) => (int) $value);
    }

    private function title(string $board, Player $player): string
    {
        if ($board === 'rosettes') {
            return 'Level '.Levels::forRosettes($player->rosettes);
        }

        $card = Skills::card($player, $board);

        return "{$card['title']} · level {$card['level']}";
    }
}
