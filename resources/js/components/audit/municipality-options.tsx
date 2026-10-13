import type { MunicipalityOption } from '@/types';

/**
 * The options for a municipality picker, grouped by province so the full
 * list of 257 stays easy to scan.
 */
export function MunicipalityOptions({
    municipalities,
    placeholder,
}: {
    municipalities: MunicipalityOption[];
    placeholder: string;
}) {
    const provinces = new Map<string, MunicipalityOption[]>();

    for (const municipality of municipalities) {
        const province = municipality.province ?? 'Other';
        provinces.set(province, [
            ...(provinces.get(province) ?? []),
            municipality,
        ]);
    }

    return (
        <>
            <option value="">{placeholder}</option>
            {[...provinces].map(([province, options]) => (
                <optgroup key={province} label={province}>
                    {options.map((municipality) => (
                        <option key={municipality.id} value={municipality.id}>
                            {municipality.name}
                        </option>
                    ))}
                </optgroup>
            ))}
        </>
    );
}
