import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Cog, Fuel, Trash2, User } from 'lucide-react';
import { type ReactNode } from 'react';

import { FormActions } from '@/components/form-actions';
import InputError from '@/components/input-error';
import { Picker, type PickerIcon, type PickerOption } from '@/components/picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useT } from '@/lib/i18n';
import { fuelLabel, transmissionLabel, vehicleTitle, type MemberOption, type Vehicle } from '@/lib/vehicles';
import type { BreadcrumbItem } from '@/types';

type Props = {
    vehicle: Vehicle | null; // null => Add mode
    members: MemberOption[];
    fuelTypes: string[];
    transmissions: string[];
};

// The request body (snake_case, matching VehiclesController::validateVehicle). Numbers/dates are held as
// strings in the form and coerced by the server's validation; empty strings become null there too.
type Fields = {
    make: string;
    model: string;
    year: string;
    version: string;
    fuel_type: string;
    transmission: string;
    color: string;
    vin: string;
    registration_number: string;
    license_plate: string;
    owner_id: string;
    primary_driver_id: string;
    purchase_date: string;
    purchase_price: string;
    current_mileage: string;
    insurance_company: string;
    road_tax_status: string;
    inspection_status: string;
    notes: string;
};

function initialFields(v: Vehicle | null): Fields {
    return {
        make: v?.make ?? '',
        model: v?.model ?? '',
        year: v?.year != null ? String(v.year) : '',
        version: v?.version ?? '',
        fuel_type: v?.fuelType ?? '',
        transmission: v?.transmission ?? '',
        color: v?.color ?? '',
        vin: v?.vin ?? '',
        registration_number: v?.registrationNumber ?? '',
        license_plate: v?.licensePlate ?? '',
        owner_id: v?.ownerId != null ? String(v.ownerId) : '',
        primary_driver_id: v?.primaryDriverId != null ? String(v.primaryDriverId) : '',
        purchase_date: v?.purchaseDate ?? '',
        purchase_price: v?.purchasePrice != null ? String(v.purchasePrice) : '',
        current_mileage: v?.currentMileage != null ? String(v.currentMileage) : '',
        insurance_company: v?.insuranceCompany ?? '',
        road_tax_status: v?.roadTaxStatus ?? '',
        inspection_status: v?.inspectionStatus ?? '',
        notes: v?.notes ?? '',
    };
}

export default function VehicleForm({ vehicle, members, fuelTypes, transmissions }: Props) {
    const t = useT();
    const memberOptions = members.map((m) => ({ value: String(m.id), label: m.name }));
    const editing = vehicle !== null;
    const { data, setData, post, patch, processing, errors } = useForm<Fields>(initialFields(vehicle));

    const back = editing ? `/m/vehicles/${vehicle!.id}` : '/m/vehicles';
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('vehicles.title'), href: '/m/vehicles' },
        { title: editing ? t('vehicles.edit_vehicle') : t('vehicles.add_vehicle'), href: back },
    ];

    const submit = () => {
        if (editing) {
            patch(route('vehicles.update', vehicle!.id), { preserveScroll: true });
        } else {
            post(route('vehicles.store'));
        }
    };

    const remove = () => {
        if (window.confirm(t('vehicles.delete_confirm'))) {
            router.delete(route('vehicles.destroy', vehicle!.id));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={editing ? t('vehicles.edit_vehicle') : t('vehicles.add_vehicle')} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4">
                <header className="flex items-center gap-3">
                    <Link href={back} className="text-muted-foreground hover:text-primary" aria-label={t('vehicles.back')}>
                        <ArrowLeft className="size-5" />
                    </Link>
                    <h1 className="font-heading text-primary text-2xl">{editing ? vehicleTitle(vehicle!) : t('vehicles.add_vehicle')}</h1>
                </header>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                    className="flex flex-col gap-8"
                >
                    {/* Identity */}
                    <Section title={t('vehicles.section_identity')}>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label={t('vehicles.make_label')} error={errors.make}>
                                <Input
                                    value={data.make}
                                    onChange={(e) => setData('make', e.target.value)}
                                    placeholder="Dacia"
                                    className="text-base"
                                />
                            </Field>
                            <Field label={t('vehicles.model_label')} error={errors.model}>
                                <Input
                                    value={data.model}
                                    onChange={(e) => setData('model', e.target.value)}
                                    placeholder="Duster"
                                    className="text-base"
                                />
                            </Field>
                            <Field label={t('vehicles.year_label')} error={errors.year}>
                                <Input
                                    value={data.year}
                                    onChange={(e) => setData('year', e.target.value)}
                                    inputMode="numeric"
                                    placeholder="2019"
                                    className="text-base"
                                />
                            </Field>
                            <Field label={t('vehicles.version_label')} error={errors.version}>
                                <Input
                                    value={data.version}
                                    onChange={(e) => setData('version', e.target.value)}
                                    placeholder="1.5 dCi"
                                    className="text-base"
                                />
                            </Field>
                            <Field label={t('vehicles.fuel_label')} error={errors.fuel_type}>
                                <OptionPicker
                                    value={data.fuel_type}
                                    onChange={(v) => setData('fuel_type', v)}
                                    placeholder={t('vehicles.choose')}
                                    ariaLabel={t('vehicles.fuel_label')}
                                    icon={Fuel}
                                    options={fuelTypes.map((f) => ({ value: f, label: fuelLabel(t, f) }))}
                                />
                            </Field>
                            <Field label={t('vehicles.transmission_label')} error={errors.transmission}>
                                <OptionPicker
                                    value={data.transmission}
                                    onChange={(v) => setData('transmission', v)}
                                    placeholder={t('vehicles.choose')}
                                    ariaLabel={t('vehicles.transmission_label')}
                                    icon={Cog}
                                    options={transmissions.map((tr) => ({ value: tr, label: transmissionLabel(t, tr) }))}
                                />
                            </Field>
                            <Field label={t('vehicles.color_label')} error={errors.color}>
                                <Input value={data.color} onChange={(e) => setData('color', e.target.value)} className="text-base" />
                            </Field>
                            {!editing && (
                                <Field
                                    label={t('vehicles.current_mileage_label')}
                                    error={errors.current_mileage}
                                    hint={t('vehicles.opening_mileage_hint')}
                                >
                                    <Input
                                        value={data.current_mileage}
                                        onChange={(e) => setData('current_mileage', e.target.value)}
                                        inputMode="numeric"
                                        placeholder="0"
                                        className="text-base"
                                    />
                                </Field>
                            )}
                            <Field label={t('vehicles.vin_label')} error={errors.vin}>
                                <Input value={data.vin} onChange={(e) => setData('vin', e.target.value)} className="text-base" />
                            </Field>
                            <Field label={t('vehicles.registration_label')} error={errors.registration_number}>
                                <Input
                                    value={data.registration_number}
                                    onChange={(e) => setData('registration_number', e.target.value)}
                                    className="text-base"
                                />
                            </Field>
                            <Field label={t('vehicles.plate_label')} error={errors.license_plate}>
                                <Input value={data.license_plate} onChange={(e) => setData('license_plate', e.target.value)} className="text-base" />
                            </Field>
                        </div>
                    </Section>

                    {/* Ownership & purchase */}
                    <Section title={t('vehicles.section_ownership')}>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label={t('vehicles.owner_label')} error={errors.owner_id}>
                                <OptionPicker
                                    value={data.owner_id}
                                    onChange={(v) => setData('owner_id', v)}
                                    placeholder={t('common.none')}
                                    ariaLabel={t('vehicles.owner_label')}
                                    icon={User}
                                    options={memberOptions}
                                />
                            </Field>
                            <Field label={t('vehicles.driver_label')} error={errors.primary_driver_id}>
                                <OptionPicker
                                    value={data.primary_driver_id}
                                    onChange={(v) => setData('primary_driver_id', v)}
                                    placeholder={t('common.none')}
                                    ariaLabel={t('vehicles.driver_label')}
                                    icon={User}
                                    options={memberOptions}
                                />
                            </Field>
                            <Field label={t('vehicles.purchase_date_label')} error={errors.purchase_date}>
                                <Input
                                    type="date"
                                    value={data.purchase_date}
                                    onChange={(e) => setData('purchase_date', e.target.value)}
                                    className="text-base"
                                />
                            </Field>
                            <Field label={t('vehicles.purchase_price_label')} error={errors.purchase_price}>
                                <Input
                                    value={data.purchase_price}
                                    onChange={(e) => setData('purchase_price', e.target.value)}
                                    inputMode="decimal"
                                    className="text-base"
                                />
                            </Field>
                        </div>
                    </Section>

                    {/* Obligations (plain fields in P1; first-class obligations arrive later) */}
                    <Section title={t('vehicles.section_obligations')}>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label={t('vehicles.insurance_label')} error={errors.insurance_company}>
                                <Input
                                    value={data.insurance_company}
                                    onChange={(e) => setData('insurance_company', e.target.value)}
                                    className="text-base"
                                />
                            </Field>
                            <Field label={t('vehicles.road_tax_label')} error={errors.road_tax_status}>
                                <Input
                                    value={data.road_tax_status}
                                    onChange={(e) => setData('road_tax_status', e.target.value)}
                                    className="text-base"
                                />
                            </Field>
                            <Field label={t('vehicles.inspection_label')} error={errors.inspection_status}>
                                <Input
                                    value={data.inspection_status}
                                    onChange={(e) => setData('inspection_status', e.target.value)}
                                    className="text-base"
                                />
                            </Field>
                        </div>
                    </Section>

                    <Field label={t('vehicles.notes_label')} error={errors.notes}>
                        <textarea
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            rows={3}
                            className="border-input bg-surface-card focus-visible:ring-ring w-full rounded-lg border px-3 py-2 text-base focus-visible:ring-2 focus-visible:outline-none"
                        />
                    </Field>

                    <FormActions>
                        <Button type="submit" disabled={processing || !data.make.trim() || !data.model.trim()} className="flex-1">
                            {editing ? t('vehicles.save') : t('vehicles.add_vehicle')}
                        </Button>
                        {editing && (
                            <Button type="button" variant="outline" onClick={remove} className="text-destructive">
                                <Trash2 className="size-4" /> {t('vehicles.delete')}
                            </Button>
                        )}
                    </FormActions>
                </form>
            </div>
        </AppLayout>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="flex flex-col gap-4">
            <h2 className="font-heading text-primary border-b pb-2 text-lg">{title}</h2>
            {children}
        </section>
    );
}

function Field({ label, error, hint, children }: { label: string; error?: string; hint?: string; children: ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <Label className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">{label}</Label>
            {children}
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

// A Picker over a plain string field. `placeholder` is also the explicit empty-value option (owner "None",
// fuel "Choose…"), so a field can always be cleared back to null. The <Field> label isn't tied to a control,
// so the label text is passed as the accessible name.
function OptionPicker({
    value,
    onChange,
    placeholder,
    ariaLabel,
    icon,
    options,
}: {
    value: string;
    onChange: (v: string) => void;
    placeholder: string;
    ariaLabel: string;
    icon: PickerIcon;
    options: PickerOption[];
}) {
    return (
        <Picker
            options={[{ value: '', label: placeholder }, ...options]}
            selected={value === '' ? [] : [value]}
            onChange={(next) => onChange(next[0] ?? '')}
            placeholder={placeholder}
            ariaLabel={ariaLabel}
            fallbackIcon={icon}
        />
    );
}
