import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Check, LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthSplitLayout from '@/layouts/auth/auth-split-layout';

// Must match OnboardingController::COLORS / EMOJIS (validated server-side against the same sets).
const COLORS = ['#2D5E3A', '#D4943A', '#C2453A', '#4A6B52', '#1B3A28', '#7A9A80'];
const EMOJIS = ['🌿', '🏡', '☀️', '🐻', '🌸', '⭐'];

export default function CreateFamily() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        description: '',
        color: COLORS[0],
        emoji: EMOJIS[0],
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('onboarding.store'));
    };

    return (
        <AuthSplitLayout
            headline="Name your family."
            sub="This is the shared home your family will gather around. You can change it anytime."
            image="/images/auth/register.svg"
        >
            <Head title="Create your family" />

            {/* Stepper — step 1 of 2. Step 2 (invite) arrives with the invite system; the bar reflects that. */}
            <div>
                <div className="flex gap-2">
                    <div className="bg-accent h-1 flex-1 rounded-full" />
                    <div className="bg-border h-1 flex-1 rounded-full" />
                </div>
                <div className="mt-2 flex justify-between text-sm">
                    <span className="text-primary font-medium">Step 1 of 2</span>
                    <span className="text-muted-foreground">Family details</span>
                </div>
            </div>

            {/* Header below the stepper, above the form (design order). */}
            <div className="flex flex-col gap-2">
                <h1 className="font-heading text-primary text-[32px] leading-tight">Create your family</h1>
                <p className="text-muted-foreground text-[15px]">Give it a name and a look everyone will recognize.</p>
            </div>

            <form className="flex flex-col gap-6" onSubmit={submit}>
                <div className="grid gap-2">
                    <Label htmlFor="name">Family name</Label>
                    <Input
                        id="name"
                        autoFocus
                        required
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="The Rivera Family"
                    />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="description">Short description (optional)</Label>
                    <Input
                        id="description"
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        placeholder="e.g. Home base for the Riveras"
                    />
                    <InputError message={errors.description} />
                </div>

                <div className="grid gap-3">
                    <Label>Choose a color &amp; emoji</Label>
                    <div className="flex items-center gap-4">
                        {/* Live preview of the chosen avatar. */}
                        <div
                            className="flex size-14 shrink-0 items-center justify-center rounded-md text-2xl"
                            style={{ backgroundColor: data.color }}
                        >
                            {data.emoji}
                        </div>
                        <div className="flex flex-col gap-2">
                            <div className="flex gap-2">
                                {COLORS.map((c) => (
                                    <button
                                        key={c}
                                        type="button"
                                        aria-label={`Color ${c}`}
                                        onClick={() => setData('color', c)}
                                        className="flex size-7 items-center justify-center rounded-md"
                                        style={{ backgroundColor: c }}
                                    >
                                        {data.color === c && <Check className="text-text-inverse size-4" />}
                                    </button>
                                ))}
                            </div>
                            <div className="flex gap-2">
                                {EMOJIS.map((em) => (
                                    <button
                                        key={em}
                                        type="button"
                                        aria-label={`Emoji ${em}`}
                                        onClick={() => setData('emoji', em)}
                                        className={`flex size-7 items-center justify-center rounded-md border text-base ${
                                            data.emoji === em ? 'border-accent bg-accent-subtle' : 'border-border bg-surface-card'
                                        }`}
                                    >
                                        {em}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                    <InputError message={errors.color || errors.emoji} />
                </div>

                <Button type="submit" className="w-full" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Create family
                </Button>
            </form>

            <div className="text-center">
                <Link href={route('onboarding.index')} className="text-muted-foreground inline-flex items-center gap-2 text-sm">
                    <ArrowLeft className="size-4" />
                    Back
                </Link>
            </div>
        </AuthSplitLayout>
    );
}
