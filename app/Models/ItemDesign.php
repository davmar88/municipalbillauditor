<?php

namespace App\Models;

use App\Enums\ItemTemplate;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An item a player designed in the Workshop: a shape, two colours and a
 * pattern. Copies are made from materials and numbered.
 *
 * @property int $id
 * @property int $player_id
 * @property ItemTemplate $template
 * @property string $name
 * @property array<int, string> $colours
 * @property string $pattern
 * @property int|null $edition
 * @property int $made
 * @property string $status pending, approved or rejected
 * @property Player $player
 */
#[Fillable(['player_id', 'template', 'name', 'colours', 'pattern', 'edition', 'status'])]
class ItemDesign extends Model
{
    /** The Workshop palette: night violet plus earth tones. */
    public const PALETTE = ['#b39aff', '#41d1ff', '#d18cff', '#ff8fb1', '#ffd33d', '#7ee787', '#f0883e', '#e6e1d3', '#8b6b4a', '#4a525c', '#16171d', '#ffffff'];

    public const PATTERNS = ['plain', 'stripes', 'dots', 'checks', 'stars'];

    public const EDITIONS = [10, 50, 250];

    protected function casts(): array
    {
        return [
            'template' => ItemTemplate::class,
            'colours' => 'array',
        ];
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @return HasMany<Item, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function soldOut(): bool
    {
        return $this->edition !== null && $this->made >= $this->edition;
    }

    /** @return array<string, mixed> */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'template' => $this->template->value,
            'templateLabel' => $this->template->label(),
            'name' => $this->name,
            'colours' => $this->colours,
            'pattern' => $this->pattern,
            'edition' => $this->edition,
            'made' => $this->made,
            'status' => $this->status,
        ];
    }
}
