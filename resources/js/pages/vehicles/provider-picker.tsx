import { PenLine, Wrench } from 'lucide-react';
import { useId } from 'react';

import { Picker } from '@/components/picker';
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
    const id = useId();
    // Which Picker option is active: a provider id, "Other" (free text present / chosen), or "" (none).
    const selectValue = value.serviceProviderId != null ? String(value.serviceProviderId) : value.provider !== '' ? OTHER : '';

    const onSelect = (v: string) => {
        if (v === '') onChange({ serviceProviderId: null, provider: '' });
        else if (v === OTHER) onChange({ serviceProviderId: null, provider: value.provider || '' });
        else onChange({ serviceProviderId: parseInt(v, 10), provider: '' });
    };

    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id} className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">
                {label}
            </Label>
            <Picker
                id={id}
                options={[
                    { value: '', label: t('vehicles.provider_none') },
                    ...providers.map((p) => ({ value: String(p.id), label: p.name })),
                    { value: OTHER, label: t('vehicles.provider_other'), icon: PenLine },
                ]}
                selected={selectValue === '' ? [] : [selectValue]}
                onChange={(next) => onSelect(next[0] ?? '')}
                placeholder={t('vehicles.provider_none')}
                fallbackIcon={Wrench}
            />
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
