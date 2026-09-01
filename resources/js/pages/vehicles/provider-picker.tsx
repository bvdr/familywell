import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { TFn } from '@/lib/i18n';
import type { ProviderOption } from '@/lib/vehicles';

// The provider picker used by the maintenance-completion and repair forms (V1.1). It replaces the old
// free-text garage field with a select of the family's service providers, keeping a free-text fallback for
// quick entry: choosing "Other" reveals a text input whose value is sent as `provider` (and clears the id).
//
// The two values are mutually exclusive by construction: a real provider selection sets `serviceProviderId`
// and blanks `provider`; "Other" sets `provider` free text and nulls the id.
export type ProviderValue = { serviceProviderId: number | null; provider: string };

const OTHER = '__other__';

export default function ProviderPicker({
    providers,
    value,
    onChange,
    label,
    t,
    error,
}: {
    providers: ProviderOption[];
    value: ProviderValue;
    onChange: (v: ProviderValue) => void;
    label: string;
    t: TFn;
    error?: string;
}) {
    // Which <select> option is active: a provider id, "Other" (free text present / chosen), or "" (none).
    const selectValue = value.serviceProviderId != null ? String(value.serviceProviderId) : value.provider !== '' ? OTHER : '';

    const onSelect = (v: string) => {
        if (v === '') onChange({ serviceProviderId: null, provider: '' });
        else if (v === OTHER) onChange({ serviceProviderId: null, provider: value.provider || '' });
        else onChange({ serviceProviderId: parseInt(v, 10), provider: '' });
    };

    return (
        <div className="grid gap-1.5">
            <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{label}</Label>
            <select
                value={selectValue}
                onChange={(e) => onSelect(e.target.value)}
                className="border-input bg-surface-card text-primary w-full rounded-lg border px-3 py-2 text-base focus-visible:ring-ring focus-visible:ring-2 focus-visible:outline-none md:text-sm"
            >
                <option value="">{t('vehicles.provider_none')}</option>
                {providers.map((p) => (
                    <option key={p.id} value={p.id}>
                        {p.name}
                    </option>
                ))}
                <option value={OTHER}>{t('vehicles.provider_other')}</option>
            </select>
            {selectValue === OTHER && (
                <Input
                    value={value.provider}
                    onChange={(e) => onChange({ serviceProviderId: null, provider: e.target.value })}
                    placeholder={t('vehicles.provider_placeholder')}
                    className="mt-1 text-base"
                    aria-label={t('vehicles.provider_other')}
                />
            )}
            {error && <p className="text-destructive text-xs">{error}</p>}
        </div>
    );
}
