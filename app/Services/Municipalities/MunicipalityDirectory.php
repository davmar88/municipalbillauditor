<?php

namespace App\Services\Municipalities;

use App\Models\Municipality;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Finds the municipality a bill comes from by the name printed on it.
 *
 * Names are compared after normalising ("CITY OF TSHWANE" and "City of
 * Tshwane Metropolitan Municipality" are the same), against each
 * municipality's name, short name and aliases. Some names belong to two
 * municipalities (Emalahleni, Pixley ka Seme): the province or town on the
 * bill decides, and if it can't, nothing is matched and the customer picks.
 */
class MunicipalityDirectory
{
    /** Words around a name that don't tell municipalities apart. */
    private const PREFIXES = ['the ', 'city of ', 'municipality of '];

    private const SUFFIXES = [
        ' metropolitan municipality', ' local municipality', ' district municipality',
        ' metropolitan', ' municipality', ' metro', ' municipal council', ' council',
    ];

    /** @var Collection<int, Municipality>|null */
    private ?Collection $all = null;

    /**
     * @param  string  $context  Other text from the bill, such as addresses, used to choose between namesakes.
     */
    public function match(?string $name, string $context = ''): ?Municipality
    {
        $candidates = $this->candidates($name);

        if ($candidates->count() <= 1) {
            return $candidates->first();
        }

        $context = self::plain($context);

        if ($context !== '') {
            $narrowed = $candidates->filter(fn (Municipality $m) => collect([$m->province, $m->seat, $m->area_name])
                ->filter()
                ->contains(fn (string $place) => self::containsWords($context, self::plain($place))));

            if ($narrowed->count() === 1) {
                return $narrowed->first();
            }
        }

        return null;
    }

    /**
     * Every municipality the name could mean.
     *
     * @return Collection<int, Municipality>
     */
    public function candidates(?string $name): Collection
    {
        $wanted = self::normalise((string) $name);

        if ($wanted === '') {
            return collect();
        }

        $exact = $this->all()->filter(fn (Municipality $m) => in_array($wanted, self::names($m), true));

        if ($exact->isNotEmpty()) {
            return $exact->values();
        }

        // "City of Tshwane, Pretoria Region": the longest name found inside
        // what the bill printed.
        $found = $this->all()
            ->map(fn (Municipality $m) => [$m, collect(self::names($m))
                ->filter(fn (string $n) => mb_strlen($n) >= 4 && self::containsWords($wanted, $n))
                ->max(fn (string $n) => mb_strlen($n)) ?? 0])
            ->filter(fn (array $pair) => $pair[1] > 0);

        if ($found->isEmpty()) {
            return collect();
        }

        $longest = $found->max(fn (array $pair) => $pair[1]);

        return $found->filter(fn (array $pair) => $pair[1] === $longest)->map(fn (array $pair) => $pair[0])->values();
    }

    public static function normalise(string $name): string
    {
        $name = self::plain($name);

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $name = substr($name, strlen($prefix));
            }
        }

        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                $name = substr($name, 0, -strlen($suffix));
            }
        }

        return trim($name);
    }

    /**
     * @return list<string>
     */
    private static function names(Municipality $municipality): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (?string $n) => self::normalise((string) $n),
            [$municipality->name, $municipality->short_name, $municipality->code, ...($municipality->aliases ?? [])],
        ))));
    }

    /** Lower case, no accents or punctuation, single spaces. */
    private static function plain(string $text): string
    {
        $text = Str::lower(Str::ascii($text));
        $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);

        return trim($text);
    }

    private static function containsWords(string $haystack, string $needle): bool
    {
        return $needle !== '' && str_contains(" {$haystack} ", " {$needle} ");
    }

    /**
     * @return Collection<int, Municipality>
     */
    private function all(): Collection
    {
        return $this->all ??= Municipality::query()->get();
    }
}
